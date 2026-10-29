<?php

namespace App\Modules\Exams\Enums;

/**
 * How the exam is sat (analysis doc §05: "one session for all subjects or a separate session per subject; the
 * model supports both"). The plan's duration belongs to the single session; per subject, each subject has its own.
 */
enum SessionMode: string
{
    case Single = 'single';
    case PerSubject = 'per_subject';
}
