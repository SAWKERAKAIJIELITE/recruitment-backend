<?php

namespace App\Modules\Interviews\Enums;

/** A committee sitting, or a one-off sitting: a single interviewer who sees the candidates one by one (D-067). */
enum SittingKind: string
{
    case Committee = 'committee';
    case OneOff = 'one_off';

    public function labelAr(): string
    {
        return match ($this) {
            self::Committee => 'لجنة مقابلة',
            self::OneOff => 'مقابلة فردية',
        };
    }
}
