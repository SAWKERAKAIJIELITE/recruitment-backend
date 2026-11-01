<?php

namespace App\Modules\Interviews\Enums;

enum InterviewStatus: string
{
    case Scheduled = 'scheduled';
    case Recorded = 'recorded';
    case Cancelled = 'cancelled';

    public function labelAr(): string
    {
        return match ($this) {
            self::Scheduled => 'مجدولة',
            self::Recorded => 'سُجّلت النتيجة',
            self::Cancelled => 'ملغاة',
        };
    }
}
