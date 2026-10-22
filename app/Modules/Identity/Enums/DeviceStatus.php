<?php

namespace App\Modules\Identity\Enums;

/** Lifecycle of a trusted staff device. The database enforces the same three values (CHECK). */
enum DeviceStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Revoked = 'revoked';
}
