<?php

namespace App\Database;

use Illuminate\Database\PostgresConnection as BaseConnection;

/** The pgsql connection with the grammar that keeps a date's offset (D-081). */
class PostgresConnection extends BaseConnection
{
    protected function getDefaultQueryGrammar()
    {
        return new PostgresGrammar($this);
    }
}
