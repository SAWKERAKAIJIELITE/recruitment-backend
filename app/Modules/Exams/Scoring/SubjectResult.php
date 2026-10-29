<?php

namespace App\Modules\Exams\Scoring;

use Brick\Math\BigRational;
use Brick\Math\RoundingMode;

/** A subject's score and where it falls against the pass rules. */
final readonly class SubjectResult
{
    public const PASSED = 'passed';            // at or above the pass mark

    public const IN_TOLERANCE = 'in_tolerance';   // from the floor up to (not including) the pass mark

    public const BELOW_FLOOR = 'below_floor';

    /** @param array<int, BigRational> $parts part id => percentage, for a subject scored from parts */
    public function __construct(
        public int $subjectId,
        public BigRational $score,
        public string $band,
        public array $parts = [],
    ) {
    }

    public function display(int $scale = 2): string
    {
        return (string) $this->score->toScale($scale, RoundingMode::HalfUp);
    }
}
