<?php

namespace App\Modules\Exams\Scoring;

use Brick\Math\BigDecimal;

/**
 * One version of the pass rules (analysis doc §04, D-052), as exact decimals. Comes from a PassPolicy row.
 */
final readonly class PassRules
{
    public BigDecimal $subjectPassMark;

    public BigDecimal $toleranceFloor;

    public BigDecimal $averageMustExceed;

    public function __construct(
        public int $version,
        string $subjectPassMark,
        string $toleranceFloor,
        public int $maxToleratedSubjects,
        string $averageMustExceed,
    ) {
        $this->subjectPassMark = BigDecimal::of($subjectPassMark);
        $this->toleranceFloor = BigDecimal::of($toleranceFloor);
        $this->averageMustExceed = BigDecimal::of($averageMustExceed);

        if ($this->toleranceFloor->isGreaterThan($this->subjectPassMark) || $maxToleratedSubjects < 0) {
            throw new \InvalidArgumentException('Inconsistent pass rules.');
        }
    }
}
