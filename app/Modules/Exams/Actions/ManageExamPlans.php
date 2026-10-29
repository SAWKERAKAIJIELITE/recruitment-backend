<?php

namespace App\Modules\Exams\Actions;

use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Exams\Enums\Assessment;
use App\Modules\Exams\Enums\Difficulty;
use App\Modules\Exams\Enums\ExamPlanStatus;
use App\Modules\Exams\Models\ExamPlanSubject;
use App\Modules\Exams\Models\ExamPlanSubjectPart;
use App\Modules\Exams\Models\ExamPlanVersion;
use App\Modules\Exams\Models\ExamQuestion;
use App\Modules\Exams\Models\ExamSubjectPart;
use App\Modules\Exams\Models\PassPolicy;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Recruitment\Models\JobPosition;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Exam plan versions of a job position (D-053, D-054): one draft at a time, edited freely; publishing freezes it
 * and retires the version it replaces, in one transaction. Every operation locks the job position row first, so
 * version numbers and the one-published / one-draft rules never race.
 *
 * Content shape (replaceDraft, and what a copy produces), subjects in exam order:
 *   ['subject_id' => 1, 'questions' => ['easy' => 8, 'medium' => 8, 'hard' => 4]]                  whole subject
 *   ['subject_id' => 6, 'parts' => [
 *       ['part_id' => 1, 'weight' => '60', 'assessment' => 'exam', 'questions' => [...]],           online part
 *       ['part_id' => 2, 'weight' => '40', 'assessment' => 'manual', 'max_score' => '40'],          marked by hand
 *   ]]
 * A part's weight and assessment default to the part's defaults; a manual part's scale defaults to 100.
 */
class ManageExamPlans
{
    public const DEFAULT_MANUAL_MAX_SCORE = '100';

    /** A new draft: a copy of $copyFrom, else of the published version, else empty. */
    public function createDraft(JobPosition $jobPosition, StaffUser $actor, ?ExamPlanVersion $copyFrom = null): ExamPlanVersion
    {
        return DB::transaction(function () use ($jobPosition, $actor, $copyFrom) {
            $this->lockJobPosition($jobPosition->id);

            if ($jobPosition->trashed()) {
                $this->fail('job_position_id', 'exams.job_position_archived');
            }

            $existing = ExamPlanVersion::query()->where('job_position_id', $jobPosition->id)->where('status', ExamPlanStatus::Draft)->first();

            if ($existing !== null) {
                $this->fail('job_position_id', 'exams.draft_exists', ['version' => $existing->version]);
            }

            if ($copyFrom !== null && $copyFrom->job_position_id !== $jobPosition->id) {
                $this->fail('copy_from', 'exams.copy_other_position');
            }

            $copyFrom ??= ExamPlanVersion::query()->where('job_position_id', $jobPosition->id)->where('status', ExamPlanStatus::Published)->first();

            $draft = new ExamPlanVersion($copyFrom === null ? [] : ['duration_minutes' => $copyFrom->duration_minutes, 'notes' => $copyFrom->notes]);
            $draft->forceFill([
                'job_position_id' => $jobPosition->id,
                'version' => (int) ExamPlanVersion::query()->where('job_position_id', $jobPosition->id)->max('version') + 1,
                'pass_policy_id' => PassPolicy::current()->id,
                'created_by' => $actor->id,
            ])->save();

            if ($copyFrom !== null) {
                $this->writeContent($draft, $this->contentOf($copyFrom));
            }

            AuditTrail::record('exam_plan.draft_created', $actor, [
                'plan_version_id' => $draft->id,
                'job_position_id' => $jobPosition->id,
                'version' => $draft->version,
                'copied_from' => $copyFrom?->id,
            ]);

            return $draft;
        });
    }

    /**
     * Replaces the draft's settings and its whole list of subjects. A draft may be incomplete; publish() checks.
     *
     * @param  array{duration_minutes?: ?int, notes?: ?string, subjects: list<array>}  $data
     */
    public function replaceDraft(ExamPlanVersion $draft, array $data, StaffUser $actor): ExamPlanVersion
    {
        return DB::transaction(function () use ($draft, $data, $actor) {
            $draft = $this->lockDraft($draft);

            $draft->fill(array_intersect_key($data, array_flip(['duration_minutes', 'notes'])))->save();
            $draft->subjects()->delete();   // their parts go with them (cascade)
            $this->writeContent($draft, $data['subjects']);

            AuditTrail::record('exam_plan.draft_updated', $actor, ['plan_version_id' => $draft->id, 'subjects' => count($data['subjects'])]);

            return $draft;
        });
    }

    public function deleteDraft(ExamPlanVersion $draft, StaffUser $actor): void
    {
        DB::transaction(function () use ($draft, $actor) {
            $draft = $this->lockDraft($draft);
            $draft->delete();

            AuditTrail::record('exam_plan.draft_deleted', $actor, ['plan_version_id' => $draft->id, 'version' => $draft->version]);
        });
    }

    /**
     * Freezes the draft under the current pass policy and retires the version it replaces. Refused (422, every
     * problem listed) when the draft is incomplete, uses archived subjects or parts, or asks more questions of a
     * difficulty than the bank holds.
     */
    public function publish(ExamPlanVersion $draft, StaffUser $actor): ExamPlanVersion
    {
        return DB::transaction(function () use ($draft, $actor) {
            $draft = $this->lockDraft($draft);
            $problems = $this->problems($draft);

            if ($problems !== []) {
                throw ValidationException::withMessages($problems);
            }

            $now = Date::now();
            $previous = ExamPlanVersion::query()
                ->where('job_position_id', $draft->job_position_id)
                ->where('status', ExamPlanStatus::Published)
                ->first();

            if ($previous !== null) {
                $previous->forceFill(['status' => ExamPlanStatus::Retired, 'retired_at' => $now])->save();
            }

            $draft->forceFill([
                'status' => ExamPlanStatus::Published,
                'pass_policy_id' => PassPolicy::current()->id,
                'published_by' => $actor->id,
                'published_at' => $now,
            ])->save();

            AuditTrail::record('exam_plan.published', $actor, [
                'plan_version_id' => $draft->id,
                'job_position_id' => $draft->job_position_id,
                'version' => $draft->version,
                'retired' => $previous?->id,
            ]);

            return $draft;
        });
    }

    /**
     * Everything that keeps a draft from being published, keyed like the request fields.
     *
     * @return array<string, list<string>>
     */
    public function problems(ExamPlanVersion $draft): array
    {
        $draft->loadMissing('subjects.subject', 'subjects.parts.part', 'jobPosition');
        $problems = [];

        if ($draft->jobPosition->trashed()) {
            $problems['job_position_id'][] = __('exams.job_position_archived');
        }

        if ($draft->duration_minutes === null) {
            $problems['duration_minutes'][] = __('exams.duration_required');
        }

        if ($draft->subjects->isEmpty()) {
            $problems['subjects'][] = __('exams.no_subjects');
        } elseif ($draft->questionCount() === 0 && ! $this->hasUncountedExamItem($draft)) {
            $problems['subjects'][] = __('exams.no_exam_questions');
        }

        foreach ($draft->subjects->values() as $index => $planSubject) {
            $key = "subjects.{$index}";
            $name = $planSubject->subject->name_ar;

            if ($planSubject->subject->trashed()) {
                $problems["{$key}.subject_id"][] = __('exams.subject_archived', ['subject' => $name]);
            }

            if (! $planSubject->scoredFromParts()) {
                $problems += $this->questionProblems($planSubject, $planSubject->subject_id, null, "{$key}.questions", $name);
                continue;
            }

            foreach ($planSubject->parts->values() as $partIndex => $planPart) {
                $partKey = "{$key}.parts.{$partIndex}";
                $partName = "{$name} / {$planPart->part->name_ar}";

                if ($planPart->part->trashed()) {
                    $problems["{$partKey}.part_id"][] = __('exams.part_archived', ['part' => $planPart->part->name_ar, 'subject' => $name]);
                }

                if (! $planPart->isManual()) {
                    $problems += $this->questionProblems($planPart, $planSubject->subject_id, $planPart->part_id, "{$partKey}.questions", $partName);
                }
            }
        }

        return $problems;
    }

    /** Questions of every difficulty are set, and the bank holds enough active questions of each (§05). */
    private function questionProblems(ExamPlanSubject|ExamPlanSubjectPart $item, int $subjectId, ?int $partId, string $key, string $name): array
    {
        $counts = $item->difficultyCounts();

        if ($counts === null) {
            return [$key => [__('exams.questions_required', ['item' => $name])]];
        }

        $available = ExamQuestion::query()
            ->forItem($subjectId, $partId)
            ->selectRaw('difficulty, count(*) AS n')
            ->groupBy('difficulty')
            ->pluck('n', 'difficulty');

        $problems = [];

        foreach (Difficulty::cases() as $difficulty) {
            $have = (int) ($available[$difficulty->value] ?? 0);

            if ($have < $counts[$difficulty->value]) {
                $problems["{$key}.{$difficulty->value}"][] = __('exams.bank_short', [
                    'item' => $name,
                    'difficulty' => $difficulty->labelAr(),
                    'have' => $have,
                    'need' => $counts[$difficulty->value],
                ]);
            }
        }

        return $problems;
    }

    /** An online subject or part whose counts are still missing (reported on its own, not as "no questions"). */
    private function hasUncountedExamItem(ExamPlanVersion $draft): bool
    {
        return $draft->subjects->contains(fn(ExamPlanSubject $subject) => $subject->scoredFromParts()
            ? $subject->parts->contains(fn(ExamPlanSubjectPart $part) => ! $part->isManual() && $part->difficultyCounts() === null)
            : $subject->difficultyCounts() === null);
    }

    /** The content of a version in the shape replaceDraft() takes. */
    private function contentOf(ExamPlanVersion $version): array
    {
        return $version->subjects()->with('parts')->get()->map(fn(ExamPlanSubject $subject) => [
            'subject_id' => $subject->subject_id,
            'questions' => $subject->difficultyCounts(),
            'parts' => $subject->parts->map(fn(ExamPlanSubjectPart $part) => [
                'part_id' => $part->part_id,
                'weight' => (string) $part->weight,
                'assessment' => $part->assessment->value,
                'questions' => $part->difficultyCounts(),
                'max_score' => $part->max_score === null ? null : (string) $part->max_score,
            ])->all(),
        ])->all();
    }

    private function writeContent(ExamPlanVersion $draft, array $subjects): void
    {
        foreach (array_values($subjects) as $position => $subject) {
            $parts = $subject['parts'] ?? [];

            $planSubject = new ExamPlanSubject([
                'subject_id' => (int) $subject['subject_id'],
                'position' => $position + 1,
                // A subject scored from parts has its questions on the parts.
                ...self::counts($parts === [] ? ($subject['questions'] ?? null) : null),
            ]);
            $planSubject->plan_version_id = $draft->id;
            $planSubject->save();

            $defaults = ExamSubjectPart::withTrashed()->whereIn('id', array_column($parts, 'part_id'))->get()->keyBy('id');

            foreach (array_values($parts) as $partPosition => $part) {
                $default = $defaults[$part['part_id']];
                $assessment = Assessment::from($part['assessment'] ?? $default->default_assessment->value);
                $manual = $assessment === Assessment::Manual;

                $planSubject->parts()->create([
                    'plan_version_id' => $draft->id,
                    'subject_id' => $planSubject->subject_id,
                    'part_id' => (int) $part['part_id'],
                    'position' => $partPosition + 1,
                    'weight' => $part['weight'] ?? $default->default_weight,
                    'assessment' => $assessment,
                    'max_score' => $manual ? ($part['max_score'] ?? self::DEFAULT_MANUAL_MAX_SCORE) : null,
                    ...self::counts($manual ? null : ($part['questions'] ?? null)),
                ]);
            }
        }
    }

    /** @return array{easy_count: ?int, medium_count: ?int, hard_count: ?int} */
    private static function counts(?array $questions): array
    {
        $counts = [];

        foreach (Difficulty::cases() as $difficulty) {
            $counts[$difficulty->column()] = $questions === null ? null : (int) $questions[$difficulty->value];
        }

        return $counts;
    }

    private function lockJobPosition(int $jobPositionId): void
    {
        JobPosition::withTrashed()->whereKey($jobPositionId)->lockForUpdate()->firstOrFail();
    }

    /** Locks the job position, then re-reads the version: it must still be a draft. */
    private function lockDraft(ExamPlanVersion $version): ExamPlanVersion
    {
        $this->lockJobPosition($version->job_position_id);
        $fresh = ExamPlanVersion::query()->whereKey($version->id)->firstOrFail();

        if (! $fresh->isDraft()) {
            $this->fail('status', 'exams.not_a_draft', ['status' => __("exams.statuses.{$fresh->status->value}")]);
        }

        return $fresh;
    }

    private function fail(string $field, string $key, array $replace = []): never
    {
        throw ValidationException::withMessages([$field => [__($key, $replace)]]);
    }
}
