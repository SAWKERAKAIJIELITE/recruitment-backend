<?php

namespace App\Modules\Exams\Enums;

/** Where a candidate's booking into an exam session stands (D-074). */
enum BookingStatus: string
{
    case Booked = 'booked';           // waiting for the session
    case Cancelled = 'cancelled';     // by staff, with a reason, before the candidate started
    case Absent = 'absent';           // not started when the late window closed
    case Attended = 'attended';       // started an attempt

    public function labelAr(): string
    {
        return match ($this) {
            self::Booked => 'محجوز',
            self::Cancelled => 'ملغى',
            self::Absent => 'غائب',
            self::Attended => 'حاضر',
        };
    }
}
