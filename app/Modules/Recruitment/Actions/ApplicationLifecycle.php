<?php

namespace App\Modules\Recruitment\Actions;

use App\Modules\Identity\Models\StaffUser;
use App\Modules\Recruitment\Enums\ApplicationStatus;
use App\Modules\Recruitment\Enums\DecisionType;
use App\Modules\Recruitment\Exceptions\IllegalStatusTransition;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\ApplicationStatusEvent;
use Illuminate\Support\Facades\DB;

/**
 * The only way an application's status changes (analysis doc §03, D-027, D-033).
 *
 * The legal moves are checked here, so an illegal jump is refused even when a request reaches the API
 * directly. The change itself is one new event appended to the history chain; the database then copies
 * its status into applications.current_status. Later modules (exams, interviews, contracts) call move().
 */
class ApplicationLifecycle
{
    /** Writes the first event of a new application (status awaiting_exam). Call it in the creating transaction. */
    public function open(Application $application, ?StaffUser $actor): ApplicationStatusEvent
    {
        return DB::transaction(function () use ($application, $actor) {
            $event = $this->append($application, null, ApplicationStatus::AwaitingExam, DecisionType::Created, $actor, null, null, null);

            $this->reload($application);

            return $event;
        });
        // return $this->write($application, null, ApplicationStatus::AwaitingExam, DecisionType::Created, $actor, null, null, null);
    }

    /**
     * @throws IllegalStatusTransition when the move, or the decision type behind it, is not allowed
     */
    public function move(
        Application $application,
        ApplicationStatus $to,
        DecisionType $type,
        ?StaffUser $actor = null,
        ?string $reason = null,
        ?string $relatedType = null,
        ?string $relatedId = null,
    ): ApplicationStatusEvent {
        return DB::transaction(function () use ($application, $to, $type, $actor, $reason, $relatedType, $relatedId) {
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

            $event = $this->append($locked, $this->latestEventId($locked), $to, $type, $actor, $reason, $relatedType, $relatedId);
            // $event = $this->write($locked, $from, $to, $type, $actor, $reason, $relatedType, $relatedId);

            // $application->setRawAttributes($locked->getAttributes(), true);

            $this->reload($application);

            return $event;
        });
    }

    /** The event nothing follows yet. The database guarantees there is exactly one. */
    private function latestEventId(Application $application): ?string
    {
        return ApplicationStatusEvent::query()
            ->where('application_id', $application->getKey())
            ->whereNotExists(fn($next) => $next->from('application_status_events as next')
                ->whereColumn('next.previous_event_id', 'application_status_events.id'))
            ->value('id');
    }

    private function append(
        Application $application,
        ?string $previousEventId,
        // ?ApplicationStatus $from,
        ApplicationStatus $to,
        DecisionType $type,
        ?StaffUser $actor,
        ?string $reason,
        ?string $relatedType,
        ?string $relatedId,
    ): ApplicationStatusEvent {
        $event = new ApplicationStatusEvent();
        $event->forceFill([
            'application_id' => $application->getKey(),
            // 'from_status' => $from,
            'previous_event_id' => $previousEventId,
            'to_status' => $to,
            'decision_type' => $type,
            'reason' => $reason,
            'actor_id' => $actor?->id,
            'related_type' => $relatedType,
            'related_id' => $relatedId,
            'occurred_at' => now(),
        ])->save();

        // $application->forceFill(['current_status' => $to])->save();

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
