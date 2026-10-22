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
 * The only way an application's status changes (analysis doc §03: illegal jumps are refused here,
 * even if a request reaches the API directly). Later modules (exams, interviews, contracts) call move().
 */
class ApplicationLifecycle
{
    /** Writes the first history row of a new application (status awaiting_exam). */
    public function open(Application $application, ?StaffUser $actor): ApplicationStatusEvent
    {
        return $this->write($application, null, ApplicationStatus::AwaitingExam, DecisionType::Created, $actor, null, null, null);
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

            if ($type->needsReasonAndActor() && (blank($reason) || $actor === null)) {
                throw new IllegalStatusTransition("A {$type->value} decision needs a reason and the staff user who made it.");
            }

            $event = $this->write($locked, $from, $to, $type, $actor, $reason, $relatedType, $relatedId);

            $application->setRawAttributes($locked->getAttributes(), true);

            return $event;
        });
    }

    private function write(
        Application $application,
        ?ApplicationStatus $from,
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
            'from_status' => $from,
            'to_status' => $to,
            'decision_type' => $type,
            'reason' => $reason,
            'actor_id' => $actor?->id,
            'related_type' => $relatedType,
            'related_id' => $relatedId,
            'occurred_at' => now(),
        ])->save();

        $application->forceFill(['current_status' => $to])->save();

        return $event;
    }
}
