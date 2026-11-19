<?php

namespace App\Modules\Recruitment\Enums;

/** What kind of reason a failure by decision has (D-104). The reason itself is written out. */
enum DecisionReasonType: string
{
    case Cheating = 'cheating';
    case Other = 'other';

    public function labelAr(): string
    {
        return match ($this) {
            self::Cheating => 'غش',
            self::Other => 'سبب آخر',
        };
    }
}
