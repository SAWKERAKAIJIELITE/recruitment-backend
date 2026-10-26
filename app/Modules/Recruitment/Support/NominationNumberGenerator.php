<?php

namespace App\Modules\Recruitment\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Produces the readable, unique nomination number (analysis doc §10: atomic sequence, changeable format).
 *
 * One counter row per year, incremented with a single atomic statement. Call it inside the same
 * transaction that creates the application: if that transaction rolls back, the counter rolls back too,
 * so committed numbers have no gaps. The unique index on applications.nomination_number is the last guard.
 */
class NominationNumberGenerator
{
    public function next(?CarbonInterface $at = null): string
    {
        return $this->reserve(1, $at)[0];
    }

    /**
     * Takes $count consecutive numbers with one statement (an import approval numbers all its rows at
     * once: updating the counter row once per row would pile up row versions in one long transaction).
     *
     * @return list<string>
     */
    public function reserve(int $count, ?CarbonInterface $at = null): array
    {
        if ($count < 1) {
            return [];
        }

        $year = (int) ($at ?? now())->format('Y');

        $row = DB::selectOne(
            'INSERT INTO nomination_counters (year, last_value) VALUES (?, ?)
             ON CONFLICT (year) DO UPDATE SET last_value = nomination_counters.last_value + EXCLUDED.last_value
             RETURNING last_value',
            [$year, $count],
        );

        $numbers = [];

        for ($sequence = (int) $row->last_value - $count + 1; $sequence <= (int) $row->last_value; $sequence++) {
            $numbers[] = $this->format($year, $sequence);
        }

        return $numbers;
    }

    private function format(int $year, int $sequence): string
    {
        return strtr((string) config('recruitment.nomination_number.format'), [
            '{YYYY}' => (string) $year,
            '{YY}' => substr((string) $year, -2),
            '{SEQ}' => str_pad((string) $sequence, (int) config('recruitment.nomination_number.sequence_digits'), '0', STR_PAD_LEFT),
        ]);
    }
}
