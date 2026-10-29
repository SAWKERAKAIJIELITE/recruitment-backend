<?php

namespace App\Modules\Exams\Scoring;

use Brick\Math\BigDecimal;

/**
 * What the engine needs from a plan version: its subjects, and for a subject scored from parts, each part's
 * weight. Subjects weigh equally (analysis doc §04), so they carry no weight.
 */
final readonly class ScoringPlan
{
    /** @var array<int, array<int, BigDecimal>|null> subject id => (part id => weight) or null when scored as a whole */
    public array $subjects;

    /** @param array<int, array<int, string|int>|null> $subjects */
    public function __construct(array $subjects)
    {
        if ($subjects === []) {
            throw new \InvalidArgumentException('A plan needs at least one subject.');
        }

        $this->subjects = array_map(function (?array $parts) {
            if ($parts === null) {
                return null;
            }

            if ($parts === []) {
                throw new \InvalidArgumentException('A subject scored from parts needs at least one part.');
            }

            return array_map(function (string|int $weight) {
                $weight = BigDecimal::of($weight);

                if (! $weight->isGreaterThan(0)) {
                    throw new \InvalidArgumentException('A part weight must be positive.');
                }

                return $weight;
            }, $parts);
        }, $subjects);
    }
}
