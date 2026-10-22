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
        $year = (int) ($at ?? now())->format('Y');

        $row = DB::selectOne(
            'INSERT INTO nomination_counters (year, last_value) VALUES (?, 1)
             ON CONFLICT (year) DO UPDATE SET last_value = nomination_counters.last_value + 1
             RETURNING last_value',
            [$year],
        );

        return strtr((string) config('recruitment.nomination_number.format'), [
            '{YYYY}' => (string) $year,
            '{YY}' => substr((string) $year, -2),
            '{SEQ}' => str_pad(
                (string) $row->last_value,
                (int) config('recruitment.nomination_number.sequence_digits'),
                '0',
                STR_PAD_LEFT,
            ),
        ]);
    }
}
