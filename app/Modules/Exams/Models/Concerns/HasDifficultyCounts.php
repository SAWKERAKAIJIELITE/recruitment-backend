<?php

namespace App\Modules\Exams\Models\Concerns;

use App\Modules\Exams\Enums\Difficulty;

/** A plan subject (scored as a whole) or an exam part: how many questions of each difficulty it asks. */
trait HasDifficultyCounts
{
    /** @return array{easy: int, medium: int, hard: int}|null null when no question is asked here */
    public function difficultyCounts(): ?array
    {
        if ($this->easy_count === null) {
            return null;
        }

        return array_combine(
            array_column(Difficulty::cases(), 'value'),
            array_map(fn(Difficulty $difficulty) => (int) $this->getAttribute($difficulty->column()), Difficulty::cases()),
        );
    }

    public function countedQuestions(): int
    {
        return array_sum($this->difficultyCounts() ?? []);
    }
}
