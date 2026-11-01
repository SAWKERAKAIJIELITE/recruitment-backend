<?php

namespace App\Modules\Interviews\Enums;

/** The legacy template's evaluation scale (lstEval), best first. Recorded as given; no average is derived (§08). */
enum InterviewGrade: string
{
    case Excellent = 'excellent';
    case VeryGood = 'very_good';
    case Good = 'good';
    case Acceptable = 'acceptable';
    case Average = 'average';
    case Weak = 'weak';

    public function labelAr(): string
    {
        return match ($this) {
            self::Excellent => 'ممتاز',
            self::VeryGood => 'جيد جداً',
            self::Good => 'جيد',
            self::Acceptable => 'مقبول',
            self::Average => 'وسط',
            self::Weak => 'ضعيف',
        };
    }
}
