<?php

namespace App\Modules\Exams\Enums;

/** Question difficulty. Every exam subject or part asks questions of all three (D-054). */
enum Difficulty: string
{
    case Easy = 'easy';
    case Medium = 'medium';
    case Hard = 'hard';

    public function column(): string
    {
        return "{$this->value}_count";
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::Easy => 'سهل',
            self::Medium => 'متوسط',
            self::Hard => 'صعب',
        };
    }
}
