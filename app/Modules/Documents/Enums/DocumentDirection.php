<?php

namespace App\Modules\Documents\Enums;

/** Who produces a document: the candidate brings it (required documents) or the organization issues it (output). */
enum DocumentDirection: string
{
    case Incoming = 'incoming';
    case Outgoing = 'outgoing';
}
