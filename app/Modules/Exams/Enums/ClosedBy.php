<?php

namespace App\Modules\Exams\Enums;

/** Who closed an attempt. */
enum ClosedBy: string
{
    case Candidate = 'candidate';     // submitted
    case Timeout = 'timeout';         // the server, at the deadline
}
