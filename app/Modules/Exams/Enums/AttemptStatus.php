<?php

namespace App\Modules\Exams\Enums;

enum AttemptStatus: string
{
    case InProgress = 'in_progress';
    case Submitted = 'submitted';     // closed: by the candidate, or by the server at the deadline
}
