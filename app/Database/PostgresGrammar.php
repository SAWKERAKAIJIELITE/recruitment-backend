<?php

namespace App\Database;

use Illuminate\Database\Query\Grammars\PostgresGrammar as BaseGrammar;

/**
 * Dates written with their UTC offset (D-081). Laravel writes a date as "Y-m-d H:i:s" and drops the offset, so a time
 * sent as 09:00+03:00 would be stored as 09:00 UTC, three hours late, in every timestamptz column. With the offset,
 * PostgreSQL stores the instant that was meant, whatever zone the value came in.
 */
class PostgresGrammar extends BaseGrammar
{
    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:sP';
    }
}
