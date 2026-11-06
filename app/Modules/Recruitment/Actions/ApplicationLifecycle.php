<?php

namespace App\Modules\Recruitment\Actions;

use App\Modules\Identity\Models\StaffUser;
use App\Modules\Recruitment\Contracts\StatusChangeCause;
use App\Modules\Recruitment\Enums\ApplicationStatus;
use App\Modules\Recruitment\Enums\DecisionType;
use App\Modules\Recruitment\Exceptions\IllegalStatusTransition;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\ApplicationStatusEvent;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * The only way an application's status changes (analysis doc §03, D-033).
 *
 * The legal moves are checked here, so an illegal jump is refused even when a request reaches the API
 * directly. The change itself is one new event appended to the history chain; the database then copies
 * its status into applications.current_status. Later modules (exams, interviews, contracts) call move().
 */
class ApplicationLifecycle
{
    /**
     * Writes the first event of a new application (status awaiting_exam). Runs inside the transaction that
     * creates the application, without a savepoint of its own (an import opens thousands in one transaction).
     */
    public function open(Application $application, ?StaffUser $actor): ApplicationStatusEvent
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('ApplicationLifecycle::open() must run in the transaction that creates the application.');
        }

        $event = $this->append($application, null, ApplicationStatus::AwaitingExam, DecisionType::Created, $actor, null, null, Date::now());

        $this->reload($application);

        return $event;
    }

    /**
     * @param  StatusChangeCause|null  $cause  the record behind the move (exam attempt, interview, contract, ...), D-038
     * @param  CarbonInterface|null  $occurredAt  when the decision happened in reality; now when null (D-039)
     *
     * @throws IllegalStatusTransition when the move, the decision type behind it, or its date is not allowed
     */
    public function move(
        Application $application,
        ApplicationStatus $to,
        DecisionType $type,
        ?StaffUser $actor = null,
        ?string $reason = null,
        ?StatusChangeCause $cause = null,
        ?CarbonInterface $occurredAt = null,
    ): ApplicationStatusEvent {
        return DB::transaction(function () use ($application, $to, $type, $actor, $reason, $cause, $occurredAt) {
            // Lock the row and read the status from the database: two simultaneous moves cannot both win.
            $locked = Application::query()->whereKey($application->getKey())->lockForUpdate()->firstOrFail();
            $from = $locked->current_status;

            $required = $from->decisionRequiredFor($to);

            if ($required === null) {
                throw new IllegalStatusTransition("An application cannot move from {$from->value} to {$to->value}.");
            }

            if ($required !== $type) {
                throw new IllegalStatusTransition("Moving from {$from->value} to {$to->value} requires a decision of type {$required->value}, not {$type->value}.");
            }

            $reason = $reason === null ? null : trim($reason);

            if ($type->needsReasonAndActor() && ($reason === null || $reason === '' || $actor === null)) {
                throw new IllegalStatusTransition("A {$type->value} decision needs a reason and the staff user who made it.");
            }

            $latest = $this->latestEvent($locked);
            $occurredAt = $this->checkedDate($occurredAt, $latest);

            $event = $this->append($locked, $latest->id, $to, $type, $actor, $reason, $cause, $occurredAt);

            $this->reload($application);

            return $event;
        });
    }

    /**
     * Replaces the latest outcome after the record that caused it was corrected (D-059): a new event of type
     * correction, from the wrong status to the right one. Allowed only while that outcome is still the latest
     * decision and was caused by $cause, and only towards another outcome the same step could have given (an
     * interview result: awaiting_contract, interview_failed or interview_absent). History is never rewritten.
     *
     * @throws IllegalStatusTransition
     */
    public function correct(
        Application $application,
        ApplicationStatus $to,
        StatusChangeCause $cause,
        StaffUser $actor,
        string $reason,
    ): ApplicationStatusEvent {
        return DB::transaction(function () use ($application, $to, $cause, $actor, $reason) {
            $locked = Application::query()->whereKey($application->getKey())->lockForUpdate()->firstOrFail();
            $latest = $this->latestEvent($locked);
            $column = $cause::statusEventColumn();

            if ($latest->getAttribute($column) !== $cause->getKey() || ! in_array($latest->decision_type, [DecisionType::Outcome, DecisionType::Correction], true)) {
                throw new IllegalStatusTransition('Only the latest decision can be corrected, and only from the record that caused it.');
            }

            $before = $this->statusBefore($latest);

            if ($to === $locked->current_status || $before?->decisionRequiredFor($to) !== DecisionType::Outcome) {
                throw new IllegalStatusTransition("A correction cannot move from {$locked->current_status->value} to {$to->value}.");
            }

            $reason = trim($reason);

            if ($reason === '') {
                throw new IllegalStatusTransition('A correction needs a reason.');
            }

            $event = $this->append($locked, $latest->id, $to, DecisionType::Correction, $actor, $reason, $cause, Date::now());

            $this->reload($application);

            return $event;
        });
    }

    /**
     * The status the decision started from. For a correction, that of the outcome it replaced: after
     * awaiting_interview -> awaiting_contract (outcome) -> interview_failed (correction), it is awaiting_interview.
     */
    private function statusBefore(ApplicationStatusEvent $event): ?ApplicationStatus
    {
        $previous = $event->previous_event_id === null ? null : ApplicationStatusEvent::query()->find($event->previous_event_id);

        if ($previous === null) {
            return null;
        }

        return $event->decision_type === DecisionType::Correction ? $this->statusBefore($previous) : $previous->to_status;
    }

    /** The event nothing follows yet. The database guarantees there is exactly one. */
    private function latestEvent(Application $application): ApplicationStatusEvent
    {
        return ApplicationStatusEvent::query()
            ->where('application_id', $application->getKey())
            ->whereNotExists(fn($next) => $next->from('application_status_events as next')
                ->whereColumn('next.previous_event_id', 'application_status_events.id'))
            ->firstOrFail();
    }

    /**
     * A decision cannot be dated in the future, nor before the decision it follows (the database refuses
     * both too; checking here gives a readable error). Stored to the second, like every timestamp.
     */
    private function checkedDate(?CarbonInterface $occurredAt, ApplicationStatusEvent $latest): CarbonInterface
    {
        $now = Date::now();

        if ($occurredAt === null) {
            return $now;
        }

        $occurredAt = Date::instance($occurredAt)->utc()->startOfSecond();

        if ($occurredAt->greaterThan($now)) {
            throw new IllegalStatusTransition('A decision cannot be dated in the future.');
        }

        if ($occurredAt->lessThan($latest->occurred_at)) {
            throw new IllegalStatusTransition("A decision cannot be dated before the previous one ({$latest->occurred_at->toIso8601String()}).");
        }

        return $occurredAt;
    }

    private function append(
        Application $application,
        ?string $previousEventId,
        ApplicationStatus $to,
        DecisionType $type,
        ?StaffUser $actor,
        ?string $reason,
        ?StatusChangeCause $cause,
        CarbonInterface $occurredAt,
    ): ApplicationStatusEvent {
        $attributes = [
            'application_id' => $application->getKey(),
            'previous_event_id' => $previousEventId,
            'to_status' => $to,
            'decision_type' => $type,
            'reason' => $reason,
            'actor_id' => $actor?->id,
            'occurred_at' => $occurredAt,
        ];

        if ($cause !== null) {
            $attributes[$cause::statusEventColumn()] = $cause->getKey();
        }

        $event = new ApplicationStatusEvent;
        $event->forceFill($attributes)->save();

        return $event;
    }

    /** The database changed current_status and updated_at: bring the caller's model up to date. */
    private function reload(Application $application): void
    {
        $fresh = Application::query()->whereKey($application->getKey())->firstOrFail();

        $application->setRawAttributes($fresh->getAttributes(), true);
        $application->unsetRelation('statusEvents');
    }
}
