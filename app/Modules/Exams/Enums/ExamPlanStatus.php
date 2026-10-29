<?php

namespace App\Modules\Exams\Enums;

/** Life of an exam plan version (D-053). Only a draft changes; publishing freezes it. */
enum ExamPlanStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Retired = 'retired';
}
