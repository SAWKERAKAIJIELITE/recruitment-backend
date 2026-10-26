<?php

namespace App\Modules\Recruitment\Support;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Adds and removes the cause columns of application_status_events (D-038). For use in migrations of the
 * modules that own the cause tables (Exams, Interviews, Contracts, ...):
 *
 *     StatusEventCauseColumns::add('exam_attempt_id', 'exam_attempts');      // in up()
 *     StatusEventCauseColumns::remove('exam_attempt_id');                    // in down()
 *
 * Each cause column is a nullable foreign key with ON DELETE RESTRICT, so a record that a history event
 * points to cannot be deleted. After every change the CHECK "at most one cause per event" is rebuilt over
 * all cause columns.
 */
final class StatusEventCauseColumns
{
    public const TABLE = 'application_status_events';

    public const CHECK = 'application_status_events_at_most_one_cause';

    /** Columns of the table that are not causes. Every other *_id column is a cause. */
    private const NOT_CAUSES = ['id', 'application_id', 'previous_event_id', 'actor_id'];

    public static function add(string $column, string $referencedTable, string $type = 'uuid'): void
    {
        self::assertIdentifier($column);
        self::assertIdentifier($referencedTable);

        if (! str_ends_with($column, '_id')) {
            throw new InvalidArgumentException("A cause column must end with _id: {$column}");
        }

        if (! in_array($type, ['uuid', 'bigint'], true)) {
            throw new InvalidArgumentException("Unsupported key type: {$type}");
        }

        $table = self::TABLE;

        DB::statement("ALTER TABLE {$table} ADD COLUMN {$column} {$type} NULL");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$column}_foreign FOREIGN KEY ({$column}) REFERENCES {$referencedTable} (id) ON DELETE RESTRICT");
        DB::statement("CREATE INDEX {$table}_{$column}_index ON {$table} ({$column})");

        self::rebuildCheck();
    }

    public static function remove(string $column): void
    {
        self::assertIdentifier($column);

        DB::statement('ALTER TABLE ' . self::TABLE . " DROP COLUMN IF EXISTS {$column}");

        self::rebuildCheck();
    }

    /** @return list<string> the cause columns currently on the table */
    public static function columns(): array
    {
        $rows = DB::select(
            "SELECT column_name FROM information_schema.columns
             WHERE table_schema = current_schema() AND table_name = ? AND column_name LIKE '%\\_id'
             ORDER BY ordinal_position",
            [self::TABLE],
        );

        return array_values(array_diff(array_map(fn($row) => $row->column_name, $rows), self::NOT_CAUSES));
    }

    private static function rebuildCheck(): void
    {
        $table = self::TABLE;
        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS " . self::CHECK);

        $columns = self::columns();

        if (count($columns) > 1) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT " . self::CHECK . ' CHECK (num_nonnulls(' . implode(', ', $columns) . ') <= 1)');
        }
    }

    private static function assertIdentifier(string $name): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $name) !== 1) {
            throw new InvalidArgumentException("Not a safe SQL identifier: {$name}");
        }
    }
}
