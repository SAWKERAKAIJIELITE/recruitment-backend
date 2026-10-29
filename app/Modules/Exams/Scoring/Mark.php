<?php

namespace App\Modules\Exams\Scoring;

use Brick\Math\BigNumber;
use Brick\Math\BigRational;

/**
 * Points achieved out of the points available, for a subject scored as a whole or for one part. Given as integers
 * or decimal strings (never floats: a float is not exact). Its score is achieved / max × 100, kept as an exact
 * fraction.
 */
final readonly class Mark
{
    public BigRational $achieved;

    public BigRational $max;

    public function __construct(int|string|BigNumber $achieved, int|string|BigNumber $max)
    {
        $this->achieved = BigRational::of($achieved);
        $this->max = BigRational::of($max);

        if (! $this->max->isGreaterThan(0) || $this->achieved->isNegative() || $this->achieved->isGreaterThan($this->max)) {
            throw new \InvalidArgumentException('A mark must be between 0 and a positive maximum.');
        }
    }

    public static function of(int|string|BigNumber $achieved, int|string|BigNumber $max = 100): self
    {
        return new self($achieved, $max);
    }

    /** Percentage, exact. */
    public function percent(): BigRational
    {
        return $this->achieved->dividedBy($this->max)->multipliedBy(100);
    }
}
