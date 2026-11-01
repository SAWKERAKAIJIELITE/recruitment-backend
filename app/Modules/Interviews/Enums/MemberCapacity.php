<?php

namespace App\Modules\Interviews\Enums;

/** A committee member's capacity (الصفة, owner's list). A committee has at most one chair. */
enum MemberCapacity: string
{
    case Chair = 'chair';
    case Member = 'member';
    case Secretary = 'secretary';

    public function labelAr(): string
    {
        return match ($this) {
            self::Chair => 'رئيس اللجنة',
            self::Member => 'عضو',
            self::Secretary => 'مقرر',
        };
    }
}
