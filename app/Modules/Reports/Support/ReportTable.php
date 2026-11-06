<?php

namespace App\Modules\Reports\Support;

/**
 * One table of a report, ready for any format: a sheet in Excel, a page in PDF. Columns are [label, width in
 * characters]; a row holds one value per column (text, a whole number, or null for an empty cell); the totals count
 * the same rows (analysis doc §16: "إجماليات متطابقة مع الاستعلام").
 */
final readonly class ReportTable
{
    /**
     * @param  list<array{0: string, 1: int}>  $columns
     * @param  list<list<string|int|float|null>>  $rows
     * @param  array<string, int|float|string>  $totals  label => value
     */
    public function __construct(
        public string $title,
        public array $columns,
        public array $rows,
        public array $totals = [],
        public bool $wide = false,                 // many columns: A3 in PDF
    ) {}

    public function count(): int
    {
        return count($this->rows);
    }
}
