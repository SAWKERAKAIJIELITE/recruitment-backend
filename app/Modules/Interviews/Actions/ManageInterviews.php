<?php

namespace App\Modules\Interviews\Actions;

use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Interviews\Enums\InterviewResult;
use App\Modules\Interviews\Enums\InterviewStatus;
use App\Modules\Interviews\Enums\SittingKind;
use App\Modules\Interviews\Models\Interview;
use App\Modules\Interviews\Models\InterviewSitting;
use App\Modules\Recruitment\Actions\ApplicationLifecycle;
use App\Modules\Recruitment\Enums\ApplicationStatus;
use App\Modules\Recruitment\Enums\DecisionType;
use App\Modules\Recruitment\Exceptions\IllegalStatusTransition;
use App\Modules\Recruitment\Models\Application;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The life of an interview (analysis doc §07, §08; D-059, D-061):
 *  - schedule: only for an application awaiting its interview (passed the exams, or by exception), one at a time;
 *    one by one, or several applications into one committee sitting at successive times;
 *  - reschedule or cancel a waiting interview, with a reason (kept);
 *  - record the committee's result, which moves the application (the interview is the cause of that event);
 *  - correct a recorded result (individual permission): a new revision, and a correction event when the result
 *    changes, allowed only while that result is still the application's latest decision;
 *  - accept an absence excuse (individual permission): back to awaiting_interview, optionally with the next
 *    appointment. The absent result stays: the new appointment is a new round.
 *
 * An appointment is either ['sitting_id' => ..., 'scheduled_at' => ?] (a committee sitting; the time defaults to its
 * start) or a one-off ['scheduled_at' => ..., 'location' => ..., 'committee_name' => ?, 'members' => [...]], which
 * becomes a sitting of one. When rescheduling, what is not sent stays as it was.
 */
class ManageInterviews
{
    private const RESULT_FIELDS = ['result', 'held_at', 'scientific_grade', 'general_grade', 'overall_grade', 'committee_summary', 'notes'];

    private const ONE_OFF_FIELDS = ['location', 'committee_name', 'members'];

    public function __construct(private readonly ApplicationLifecycle $lifecycle, private readonly ManageSittings $sittings)
    {
    }

    public function schedule(Application $application, array $appointment, StaffUser $actor): Interview
    {
        return DB::transaction(function () use ($application, $appointment, $actor) {
            $application = $this->lockBookable($application, 'application');
            [$sitting, $time] = $this->resolve($appointment, null, $actor);

            return $this->book($application, $sitting, $time, $actor);
        });
    }

    /**
     * Books several applications into one committee sitting, in the order given (the priority order the staff chose),
     * from `$firstAt` (default: the sitting's start) every `$minutesBetween` minutes. All or nothing.
     *
     * @param  list<string>  $applicationIds
     * @return list<Interview>
     */
    public function bookMany(InterviewSitting $sitting, array $applicationIds, ?CarbonInterface $firstAt, int $minutesBetween, StaffUser $actor): array
    {
        return DB::transaction(function () use ($sitting, $applicationIds, $firstAt, $minutesBetween, $actor) {
            $sitting = $this->committeeSitting($sitting->id, 'sitting');
            $time = Date::parse($firstAt ?? $sitting->starts_at);
            $this->assertWithinSitting($sitting, $time, 'first_at');
            $booked = [];

            foreach (array_values($applicationIds) as $index => $id) {
                $application = $this->lockBookable(Application::query()->findOrFail($id), "application_ids.{$index}");
                $booked[] = $this->book($application, $sitting, $time, $actor);
                $time = $time->copy()->addMinutes($minutesBetween);
            }

            return $booked;
        });
    }

    public function reschedule(Interview $interview, array $appointment, string $reason, StaffUser $actor): Interview
    {
        return DB::transaction(function () use ($interview, $appointment, $reason, $actor) {
            $interview = $this->lockScheduled($interview);
            $from = ['sitting_id' => $interview->sitting_id, 'scheduled_at' => $interview->scheduled_at];
            [$sitting, $time] = $this->resolve($appointment, $interview, $actor);

            if ($sitting->id === $from['sitting_id'] && $time->equalTo($from['scheduled_at'])) {
                $this->fail('scheduled_at', 'interviews.same_appointment');
            }

            $interview->forceFill(['sitting_id' => $sitting->id, 'scheduled_at' => $time])->save();
            $interview->setRelation('sitting', $sitting);

            $interview->reschedules()->create([
                'from_sitting_id' => $from['sitting_id'],
                'to_sitting_id' => $sitting->id,
                'from_scheduled_at' => $from['scheduled_at'],
                'to_scheduled_at' => $time,
                'reason' => trim($reason),
                'rescheduled_by' => $actor->id,
                'created_at' => Date::now(),
            ]);

            AuditTrail::record('interview.rescheduled', $actor, ['interview_id' => $interview->id, 'from_sitting_id' => $from['sitting_id'], 'to_sitting_id' => $sitting->id]);

            return $interview;
        });
    }

    public function cancel(Interview $interview, string $reason, StaffUser $actor): Interview
    {
        return DB::transaction(function () use ($interview, $reason, $actor) {
            $interview = $this->lockScheduled($interview);
            $interview->forceFill([
                'status' => InterviewStatus::Cancelled,
                'cancelled_at' => Date::now(),
                'cancelled_by' => $actor->id,
                'cancel_reason' => trim($reason),
            ])->save();

            AuditTrail::record('interview.cancelled', $actor, ['interview_id' => $interview->id]);

            return $interview;
        });
    }

    /** @param array{result: string, held_at?: ?string, scientific_grade?: ?string, general_grade?: ?string, overall_grade?: ?string, committee_summary?: ?string, notes?: ?string} $data */
    public function record(Interview $interview, array $data, StaffUser $actor): Interview
    {
        return DB::transaction(function () use ($interview, $data, $actor) {
            $application = $this->lockApplication($interview->application);
            $interview = $this->lockScheduled($interview);
            $this->assertStatus($application, ApplicationStatus::AwaitingInterview);

            $values = $this->resultValues($data + ['held_at' => $interview->scheduled_at], $interview);
            $now = Date::now();

            $interview->forceFill($values + ['status' => InterviewStatus::Recorded, 'recorded_by' => $actor->id, 'recorded_at' => $now])->save();
            $this->addRevision($interview, 1, null, $actor);

            try {
                $this->lifecycle->move($application, $interview->result->applicationStatus(), DecisionType::Outcome, $actor, null, $interview, $interview->held_at);
            } catch (IllegalStatusTransition $e) {
                throw ValidationException::withMessages(['held_at' => [__('interviews.held_before_previous_decision')]]);
            }

            AuditTrail::record('interview.recorded', $actor, ['interview_id' => $interview->id, 'result' => $interview->result->value]);

            return $interview;
        });
    }

    /** @param array<string, mixed> $changes some of the result fields */
    public function correct(Interview $interview, array $changes, string $reason, StaffUser $actor): Interview
    {
        return DB::transaction(function () use ($interview, $changes, $reason, $actor) {
            $application = $this->lockApplication($interview->application);
            $interview = Interview::query()->whereKey($interview->id)->lockForUpdate()->firstOrFail();

            if ($interview->status !== InterviewStatus::Recorded) {
                $this->fail('interview', 'interviews.not_recorded');
            }

            $current = collect(self::RESULT_FIELDS)->mapWithKeys(fn(string $field) => [$field => $interview->getAttribute($field)])->all();
            $current = array_map(fn($value) => $value instanceof \BackedEnum ? $value->value : $value, $current);
            $values = $this->resultValues(array_replace($current, $changes), $interview);
            $resultChanged = $values['result'] !== $current['result'];

            $interview->forceFill($values)->save();
            $revision = (int) $interview->revisions()->max('revision') + 1;
            $this->addRevision($interview, $revision, $reason, $actor);

            if ($resultChanged) {
                try {
                    $this->lifecycle->correct($application, $interview->result->applicationStatus(), $interview, $actor, $reason);
                } catch (IllegalStatusTransition) {
                    $this->fail('result', 'interviews.result_not_latest');
                }
            }

            AuditTrail::record('interview.corrected', $actor, ['interview_id' => $interview->id, 'revision' => $revision, 'reason' => trim($reason), 'from' => $current, 'to' => $values]);

            return $interview;
        });
    }

    /** Returns the new interview when an appointment was given, else null. */
    public function acceptExcuse(Application $application, string $reason, ?CarbonInterface $occurredAt, ?array $appointment, StaffUser $actor): ?Interview
    {
        return DB::transaction(function () use ($application, $reason, $occurredAt, $appointment, $actor) {
            $application = $this->lockApplication($application);
            $this->assertStatus($application, ApplicationStatus::InterviewAbsent);

            $absent = Interview::query()
                ->where('application_id', $application->id)
                ->where('result', InterviewResult::Absent)
                ->orderByDesc('round')
                ->firstOrFail();

            try {
                $this->lifecycle->move($application, ApplicationStatus::AwaitingInterview, DecisionType::ExcuseAccepted, $actor, $reason, $absent, $occurredAt);
            } catch (IllegalStatusTransition) {
                $this->fail('occurred_at', 'interviews.excuse_date');
            }

            AuditTrail::record('interview.excuse_accepted', $actor, ['application_id' => $application->id, 'interview_id' => $absent->id]);

            if ($appointment === null) {
                return null;
            }

            [$sitting, $time] = $this->resolve($appointment, null, $actor);

            return $this->book($application, $sitting, $time, $actor);
        });
    }

    private function book(Application $application, InterviewSitting $sitting, CarbonInterface $time, StaffUser $actor): Interview
    {
        $interview = new Interview();
        $interview->forceFill([
            'application_id' => $application->id,
            'round' => (int) Interview::query()->where('application_id', $application->id)->max('round') + 1,
            'sitting_id' => $sitting->id,
            'scheduled_at' => $time,
            'created_by' => $actor->id,
        ])->save();
        $interview->setRelation('sitting', $sitting);

        AuditTrail::record('interview.scheduled', $actor, ['interview_id' => $interview->id, 'application_id' => $application->id, 'round' => $interview->round, 'sitting_id' => $sitting->id]);

        return $interview;
    }

    /**
     * The sitting and the time an appointment leads to. A committee sitting is used as it is; one-off fields make a
     * new one-off sitting, taking what is not sent from the interview being rescheduled (its place, committee name and
     * members). A new time alone keeps a committee booking in its sitting; a one-off appointment gets a new sitting, so
     * the old one keeps where and when it was.
     *
     * @return array{InterviewSitting, CarbonInterface}
     */
    private function resolve(array $appointment, ?Interview $current, StaffUser $actor): array
    {
        $time = isset($appointment['scheduled_at']) ? Date::parse($appointment['scheduled_at']) : null;

        if (isset($appointment['sitting_id'])) {
            $sitting = $this->committeeSitting($appointment['sitting_id'], 'sitting_id');
            $time ??= $current?->sitting_id === $sitting->id ? $current->scheduled_at : $sitting->starts_at;
            $this->assertWithinSitting($sitting, $time, 'scheduled_at');

            return [$sitting, Date::parse($time)];
        }

        $currentSitting = $current?->sitting;
        $oneOff = array_intersect_key($appointment, array_flip(self::ONE_OFF_FIELDS)) !== [] || $currentSitting?->isOneOff() === true;

        if ($current !== null && ! $oneOff) {
            if ($time === null) {
                $this->fail('scheduled_at', 'interviews.same_appointment');
            }

            $this->assertWithinSitting($currentSitting, $time, 'scheduled_at');

            return [$currentSitting, $time];
        }

        $time ??= $current?->scheduled_at;
        $location = $appointment['location'] ?? $currentSitting?->location;
        $members = $appointment['members'] ?? ($currentSitting === null ? [] : Committee::of($currentSitting->members()));

        if ($time === null || $location === null || $members === []) {
            $this->fail('scheduled_at', 'interviews.appointment_incomplete');
        }

        $sitting = $this->sittings->create([
            'starts_at' => $time,
            'location' => $location,
            'committee_name' => array_key_exists('committee_name', $appointment) ? $appointment['committee_name'] : $currentSitting?->committee_name,
        ], $members, $actor, SittingKind::OneOff);

        return [$sitting, Date::parse($time)];
    }

    private function committeeSitting(string $id, string $field): InterviewSitting
    {
        $sitting = InterviewSitting::query()->whereKey($id)->sharedLock()->firstOrFail();

        if ($sitting->isOneOff()) {
            $this->fail($field, 'interviews.not_a_committee_sitting');
        }

        return $sitting;
    }

    private function assertWithinSitting(InterviewSitting $sitting, CarbonInterface $time, string $field): void
    {
        if ($time->lessThan($sitting->starts_at)) {
            $this->fail($field, 'interviews.before_sitting_start', ['start' => $sitting->starts_at->toIso8601String()]);
        }
    }

    /** An application awaiting its interview, with none waiting yet. */
    private function lockBookable(Application $application, string $field): Application
    {
        $application = $this->lockApplication($application);

        if ($application->current_status !== ApplicationStatus::AwaitingInterview) {
            $this->fail($field, 'interviews.wrong_application_status', ['status' => $application->current_status->labelAr()]);
        }

        if (Interview::query()->where('application_id', $application->id)->where('status', InterviewStatus::Scheduled)->exists()) {
            $this->fail($field, 'interviews.already_scheduled');
        }

        return $application;
    }

    /** The result fields, checked together: grades for an attended interview, none for an absence. */
    private function resultValues(array $data, Interview $interview): array
    {
        $values = array_intersect_key($data, array_flip(self::RESULT_FIELDS));
        $absent = $values['result'] === InterviewResult::Absent->value;

        if ($absent) {
            $values['scientific_grade'] = $values['general_grade'] = $values['overall_grade'] = null;
        } elseif (in_array(null, [$values['scientific_grade'] ?? null, $values['general_grade'] ?? null, $values['overall_grade'] ?? null], true)) {
            $this->fail('overall_grade', 'interviews.grades_required');
        }

        $values['held_at'] = Date::parse($values['held_at']);

        if ($values['held_at']->isFuture()) {
            $this->fail('held_at', 'interviews.held_in_future');
        }

        if ($values['held_at']->lessThan($interview->sitting->starts_at)) {
            $this->fail('held_at', 'interviews.held_before_sitting', ['start' => $interview->sitting->starts_at->toIso8601String()]);
        }

        return $values;
    }

    private function addRevision(Interview $interview, int $revision, ?string $reason, StaffUser $actor): void
    {
        $interview->revisions()->create([
            'revision' => $revision,
            'result' => $interview->result,
            'held_at' => $interview->held_at,
            'scientific_grade' => $interview->scientific_grade,
            'general_grade' => $interview->general_grade,
            'overall_grade' => $interview->overall_grade,
            'committee_summary' => $interview->committee_summary,
            'notes' => $interview->notes,
            'reason' => $reason === null ? null : trim($reason),
            'entered_by' => $actor->id,
            'created_at' => Date::now(),
        ]);
    }

    private function lockApplication(Application $application): Application
    {
        return Application::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();
    }

    private function lockScheduled(Interview $interview): Interview
    {
        $interview = Interview::query()->whereKey($interview->id)->lockForUpdate()->firstOrFail();

        if (! $interview->isScheduled()) {
            $this->fail('interview', 'interviews.not_scheduled', ['status' => $interview->status->labelAr()]);
        }

        return $interview;
    }

    private function assertStatus(Application $application, ApplicationStatus $expected): void
    {
        if ($application->current_status !== $expected) {
            $this->fail('application', 'interviews.wrong_application_status', ['status' => $application->current_status->labelAr()]);
        }
    }

    private function fail(string $field, string $key, array $replace = []): never
    {
        throw ValidationException::withMessages([$field => [__($key, $replace)]]);
    }
}
