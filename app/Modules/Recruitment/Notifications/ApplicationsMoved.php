<?php

namespace App\Modules\Recruitment\Notifications;

use App\Modules\Identity\Models\StaffUser;
use App\Modules\Recruitment\Enums\ApplicationStatus;
use App\Modules\Recruitment\Enums\DecisionType;
use App\Modules\Recruitment\Models\Application;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * In the system (database channel): one or many applications made the same move (D-089). The applications are
 * listed up to LISTED; `count` is all of them.
 */
class ApplicationsMoved extends Notification
{
    public const LISTED = 50;

    /** @param Collection<int, Application> $applications */
    public function __construct(
        private readonly Collection $applications,
        private readonly ?ApplicationStatus $from,
        private readonly ApplicationStatus $to,
        private readonly DecisionType $type,
        private readonly ?StaffUser $actor,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        $count = $this->applications->count();
        $first = $this->applications->first();
        $message = $count === 1
            ? __('recruitment.moved_one', ['name' => $first->candidate->full_name, 'nomination' => $first->nomination_number, 'to' => $this->to->labelAr()], 'ar')
            : __('recruitment.moved_many', ['count' => $count, 'to' => $this->to->labelAr()], 'ar');

        return [
            'kind' => 'applications_moved',
            'title' => $count === 1 ? __('recruitment.moved_title_one', [], 'ar') : __('recruitment.moved_title_many', ['count' => $count], 'ar'),
            'message' => $message . ($this->actor === null ? '' : __('recruitment.moved_by', ['actor' => $this->actor->full_name], 'ar')),
            'from_status' => $this->from?->value,
            'to_status' => $this->to->value,
            'to_status_label' => $this->to->labelAr(),
            'decision_type' => $this->type->value,
            'count' => $count,
            'applications' => $this->applications->take(self::LISTED)->map(fn(Application $application) => [
                'id' => $application->id,
                'nomination_number' => $application->nomination_number,
                'candidate_name' => $application->candidate->full_name,
            ])->values()->all(),
            'actor' => $this->actor === null ? null : ['id' => $this->actor->id, 'full_name' => $this->actor->full_name],
        ];
    }
}
