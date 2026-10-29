<?php

namespace App\Modules\Exams\Enums;

/**
 * How a part is assessed (analysis doc §05 "Subject Part: weight and assessment pattern"; D-054). Everything is
 * sat in one session, except parts marked by hand: التجويد is oral, the same day, and staff enter its mark.
 */
enum Assessment: string
{
    case Exam = 'exam';
    case Manual = 'manual';
}
