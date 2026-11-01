<?php

namespace App\Modules\Interviews\Enums;

use App\Modules\Recruitment\Enums\ApplicationStatus;

/** What the committee decided (analysis doc §08: ناجح / راسب / غائب). The committee enters it; nothing computes it. */
enum InterviewResult: string
{
    case Passed = 'passed';
    case Failed = 'failed';
    case Absent = 'absent';

    /** The application status this result leads to. */
    public function applicationStatus(): ApplicationStatus
    {
        return match ($this) {
            self::Passed => ApplicationStatus::AwaitingContract,
            self::Failed => ApplicationStatus::InterviewFailed,
            self::Absent => ApplicationStatus::InterviewAbsent,
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::Passed => 'ناجح',
            self::Failed => 'راسب',
            self::Absent => 'غائب',
        };
    }
}
