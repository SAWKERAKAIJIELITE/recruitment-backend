<?php

namespace App\Modules\Documents\Enums;

/** Whom a document belongs to: the person (shared by every application, like the ID card) or one application. */
enum DocumentScope: string
{
    case Candidate = 'candidate';
    case Application = 'application';
}
