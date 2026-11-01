<?php

namespace App\Modules\Contracts\Enums;

/**
 * Where a contract stands today (computed, not stored; D-064). A contract that was renewed or ended stays so; for the
 * others the dates decide, with "ending soon" from the reminder window (config contracts.reminder_days).
 */
enum ContractState: string
{
    case Upcoming = 'upcoming';         // starts later
    case Active = 'active';
    case EndingSoon = 'ending_soon';    // ends within the reminder window, not renewed yet
    case Expired = 'expired';           // its end date passed, neither renewed nor ended by staff
    case Renewed = 'renewed';           // a later contract renews it
    case Ended = 'ended';               // staff recorded the end without renewal

    public function labelAr(): string
    {
        return match ($this) {
            self::Upcoming => 'لم يبدأ بعد',
            self::Active => 'ساري',
            self::EndingSoon => 'ينتهي قريباً',
            self::Expired => 'منتهٍ ولم يُجدَّد',
            self::Renewed => 'جُدِّد',
            self::Ended => 'أُنهي دون تجديد',
        };
    }
}
