<?php

namespace App\Modules\Interviews\Enums;

/** A committee sitting (many candidates, each with a time) or a one-off appointment (a sitting of one; D-061). */
enum SittingKind: string
{
    case Committee = 'committee';
    case OneOff = 'one_off';
}
