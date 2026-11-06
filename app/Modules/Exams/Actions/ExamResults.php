<?php

namespace App\Modules\Exams\Actions;

use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Exams\Enums\AttemptStatus;
use App\Modules\Exams\Enums\ClosedBy;
use App\Modules\Exams\Models\ExamAttempt;
use App\Modules\Exams\Models\ExamBooking;
use App\Modules\Exams\Models\ExamManualMark;
use App\Modules\Exams\Models\ExamPlanSubject;
use App\Modules\Exams\Models\ExamPlanSubjectPart;
use App\Modules\Exams\Models\ExamPlanVersion;
use App\Modules\Exams\Models\ExamResult;
use App\Modules\Exams\Models\ExamSession;
use App\Modules\Exams\Scoring\ExamScorer;
use App\Modules\Exams\Scoring\Mark;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Recruitment\Actions\ApplicationLifecycle;
use App\Modules\Recruitment\Enums\ApplicationStatus;
use App\Modules\Recruitment\Enums\DecisionType;
use App\Modules\Recruitment\Exceptions\IllegalStatusTransition;
use App\Modules\Recruitment\Models\Application;
use Brick\Math\BigDecimal;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * From a closed attempt to a decision (analysis doc §04, §06; D-052, D-075):
 *
 *  - closing marks every answer right or wrong (an unanswered question is wrong), once;
 *  - an online subject or part scores its right answers out of its questions; a hand-marked part (التجويد) its
 *    entered mark out of its scale;
 *  - when the attempt is closed and every hand mark is in, the engine decides under the plan's pass policy and the
 *    application moves at once: passed → awaiting_interview, failed → exam_failed, dated the day of the exam, with
 *    the booking as the cause;
 *  - a hand mark is entered once; changing it is a correction with a reason, and after the decision it needs the
 *    individual permission exam_results.correct: the result gets a new revision, and when passed/failed changes the
 *    application gets a correction event (only while that result is still its latest decision).
 */
class ExamResults
{
    public function __construct(
        private readonly ApplicationLifecycle $lifecycle,
        private readonly ExamScorer $scorer = new ExamScorer,
    ) {}

    /** Closes a locked, open attempt and decides it when nothing is missing. */
    public function close(ExamAttempt $attempt, ClosedBy $by, CarbonInterface $at): ExamAttempt
    {
        $attempt->forceFill(['status' => AttemptStatus::Submitted, 'submitted_at' => $at, 'closed_by' => $by])->save();

        DB::update(
            'UPDATE exam_attempt_items i SET is_correct = (i.selected_option IS NOT NULL AND i.selected_option = v.correct_option)
             FROM exam_session_questions q JOIN exam_question_versions v ON v.id = q.question_version_id
             WHERE q.id = i.session_question_id AND i.attempt_id = ?',
            [$attempt->id],
        );

        AuditTrail::record('exam_attempt.closed', null, ['attempt_id' => $attempt->id, 'closed_by' => $by->value, 'at' => $at->toIso8601String()]);

        $this->decide($attempt, null);

        return $attempt;
    }

    /**
     * Enters hand marks: [plan part id => mark]. A part already marked needs a reason (a correction), and after the
     * result is decided also the individual permission exam_results.correct.
     *
     * @param  array<int, string>  $marks
     */
    public function enterMarks(ExamAttempt $attempt, array $marks, ?string $reason, StaffUser $actor): ExamAttempt
    {
        return DB::transaction(function () use ($attempt, $marks, $reason, $actor) {
            $attempt = ExamAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            $session = ExamSession::query()->with('plan.subjects.parts')->findOrFail($attempt->session_id);
            $parts = $this->manualParts($session->plan)->keyBy('id');
            $current = $this->currentMarks($attempt);
            $decided = $attempt->result()->exists();
            $reason = $reason === null ? null : trim($reason);
            $corrected = [];

            foreach ($marks as $partId => $mark) {
                $part = $parts->get($partId) ?? $this->fail("marks.{$partId}", 'exams.marks.not_a_manual_part');

                if (BigDecimal::of((string) $mark)->isGreaterThan(BigDecimal::of((string) $part->max_score))) {
                    $this->fail("marks.{$partId}", 'exams.marks.above_max', ['max' => $part->max_score]);
                }

                $previous = $current[$partId] ?? null;

                if ($previous !== null && BigDecimal::of((string) $previous->mark)->isEqualTo(BigDecimal::of((string) $mark))) {
                    continue;   // the same mark again: nothing to record
                }

                if ($previous !== null && ($reason === null || $reason === '')) {
                    $this->fail("marks.{$partId}", 'exams.marks.reason_required');
                }

                if ($previous !== null && $decided && ! $actor->hasPermission('exam_results.correct')) {
                    throw new AuthorizationException(__('exams.marks.correct_permission'));
                }

                ExamManualMark::query()->forceCreate([
                    'attempt_id' => $attempt->id,
                    'plan_part_id' => $partId,
                    'revision' => ($previous?->revision ?? 0) + 1,
                    'mark' => (string) $mark,
                    'reason' => $previous === null ? null : $reason,
                    'entered_by' => $actor->id,
                    'created_at' => Date::now(),
                ]);

                if ($previous !== null) {
                    $corrected[$partId] = ['from' => (string) $previous->mark, 'to' => (string) $mark];
                }
            }

            AuditTrail::record($corrected === [] ? 'exam_marks.entered' : 'exam_marks.corrected', $actor, ['attempt_id' => $attempt->id, 'marks' => $marks, 'corrections' => $corrected, 'reason' => $reason]);

            if ($decided && $corrected !== []) {
                $this->revise($attempt, $reason, $actor);
            } elseif (! $decided) {
                $this->decide($attempt, $actor);
            }

            return $attempt;
        });
    }

    /**
     * The marks the engine takes, or null while a hand mark is missing or the attempt is open.
     *
     * @return array<int, Mark|array<int, Mark>>|null
     */
    public function marks(ExamAttempt $attempt, ExamPlanVersion $plan): ?array
    {
        if ($attempt->isOpen()) {
            return null;
        }

        $online = DB::table('exam_attempt_items as i')
            ->join('exam_session_questions as q', 'q.id', '=', 'i.session_question_id')
            ->where('i.attempt_id', $attempt->id)
            ->groupBy('q.plan_subject_id', 'q.plan_part_id')
            ->selectRaw('q.plan_subject_id, q.plan_part_id, count(*) AS asked, count(*) FILTER (WHERE i.is_correct) AS correct')
            ->get()
            ->keyBy(fn($row) => "{$row->plan_subject_id}:" . ($row->plan_part_id ?? ''));
        $manual = $this->currentMarks($attempt);
        $marks = [];

        foreach ($plan->subjects as $subject) {
            if (! $subject->scoredFromParts()) {
                $marks[$subject->subject_id] = $this->onlineMark($online->get("{$subject->id}:"));

                continue;
            }

            foreach ($subject->parts as $part) {
                if ($part->isManual()) {
                    if (! isset($manual[$part->id])) {
                        return null;
                    }

                    $marks[$subject->subject_id][$part->part_id] = Mark::of((string) $manual[$part->id]->mark, (string) $part->max_score);
                } else {
                    $marks[$subject->subject_id][$part->part_id] = $this->onlineMark($online->get("{$subject->id}:{$part->id}"));
                }
            }
        }

        return $marks;
    }

    /** Decides a closed attempt with every mark in, once. */
    private function decide(ExamAttempt $attempt, ?StaffUser $actor): ?ExamResult
    {
        if ($attempt->result()->exists()) {
            return null;
        }

        $plan = $this->plan($attempt);
        $marks = $this->marks($attempt, $plan);

        if ($marks === null) {
            return null;
        }

        $result = $this->store($attempt, $plan, $marks, 1, null, $actor);
        $booking = ExamBooking::query()->whereKey($attempt->booking_id)->lockForUpdate()->firstOrFail();
        $application = Application::query()->whereKey($booking->application_id)->lockForUpdate()->firstOrFail();

        if ($application->current_status !== ApplicationStatus::AwaitingExam) {
            // Closed by staff in the meantime: the result is kept, the application stays where it is.
            Log::notice('exam_result_not_applied', ['attempt_id' => $attempt->id, 'status' => $application->current_status->value]);

            return $result;
        }

        try {
            $this->lifecycle->move($application, $result->passed ? ApplicationStatus::AwaitingInterview : ApplicationStatus::ExamFailed, DecisionType::Outcome, $actor, null, $booking, $attempt->submitted_at);
        } catch (IllegalStatusTransition $e) {
            // Kept, not lost with the attempt's closing: staff see the result and decide (logged).
            Log::warning('exam_result_not_applied', ['attempt_id' => $attempt->id, 'error' => $e->getMessage()]);

            return $result;
        }

        $booking->forceFill(['decided_at' => Date::now()])->save();

        AuditTrail::record('exam_result.decided', $actor, ['attempt_id' => $attempt->id, 'passed' => $result->passed, 'average' => (string) $result->average]);

        return $result;
    }

    /** A hand mark was corrected after the decision: a new revision, and a correction event when the decision changes. */
    private function revise(ExamAttempt $attempt, string $reason, StaffUser $actor): void
    {
        $plan = $this->plan($attempt);
        $previous = $attempt->result()->firstOrFail();
        $result = $this->store($attempt, $plan, $this->marks($attempt, $plan), $previous->revision + 1, $reason, $actor);

        if ($result->passed !== $previous->passed) {
            $booking = ExamBooking::query()->findOrFail($attempt->booking_id);

            try {
                $this->lifecycle->correct(Application::query()->findOrFail($booking->application_id), $result->passed ? ApplicationStatus::AwaitingInterview : ApplicationStatus::ExamFailed, $booking, $actor, $reason);
            } catch (IllegalStatusTransition) {
                $this->fail('marks', 'exams.results.not_latest');
            }
        }

        AuditTrail::record('exam_result.revised', $actor, ['attempt_id' => $attempt->id, 'revision' => $result->revision, 'passed' => $result->passed, 'was_passed' => $previous->passed, 'reason' => $reason]);
    }

    private function store(ExamAttempt $attempt, ExamPlanVersion $plan, array $marks, int $revision, ?string $reason, ?StaffUser $actor): ExamResult
    {
        $outcome = $this->scorer->score($plan->scoringPlan(), $marks, $plan->passPolicy->rules());

        return ExamResult::query()->forceCreate([
            'attempt_id' => $attempt->id,
            'revision' => $revision,
            'pass_policy_id' => $plan->pass_policy_id,
            'passed' => $outcome->passed,
            'average' => $outcome->averageDisplay(),
            'outcome' => $outcome->toArray(),
            'reason' => $reason,
            'decided_by' => $actor?->id,
            'created_at' => Date::now(),
        ]);
    }

    private function plan(ExamAttempt $attempt): ExamPlanVersion
    {
        return ExamSession::query()->with('plan.subjects.parts', 'plan.passPolicy')->findOrFail($attempt->session_id)->plan;
    }

    /** @return Collection<int, ExamPlanSubjectPart> */
    private function manualParts(ExamPlanVersion $plan): Collection
    {
        return $plan->subjects->flatMap(fn(ExamPlanSubject $subject) => $subject->parts)->filter(fn(ExamPlanSubjectPart $part) => $part->isManual())->values();
    }

    /** @return array<int, ExamManualMark> the latest revision of each part's mark */
    private function currentMarks(ExamAttempt $attempt): array
    {
        return ExamManualMark::query()
            ->where('attempt_id', $attempt->id)
            ->orderBy('revision')
            ->get()
            ->keyBy('plan_part_id')
            ->all();
    }

    private function onlineMark(?object $row): Mark
    {
        // Every exam item asks at least three questions (D-054), so a closed attempt always has them.
        return Mark::of((int) $row->correct, (int) $row->asked);
    }

    private function fail(string $field, string $key, array $replace = []): never
    {
        throw ValidationException::withMessages([$field => [__($key, $replace)]]);
    }
}
