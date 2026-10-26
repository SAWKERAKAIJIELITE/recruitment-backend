<?php

namespace App\Modules\Imports\Support;

/** What WorkbookReader found in a file: the sheet, its header row, the column map and the data rows. */
final class SheetData
{
    /**
     * @param  array<string, string>  $headers  column letter => header text
     * @param  array<string, string>  $fields  ImportField value => column letter
     * @param  list<string>  $ignored  columns with a header the import does not use
     * @param  list<array{number: int, cells: array<string, CellValue>}>  $rows  non-empty data rows
     * @param  string  $calendar  Excel date system of the workbook (1900 or 1904)
     */
    public function __construct(
        public readonly string $sheetName,
        public readonly int $headerRow,
        public readonly array $headers,
        public readonly array $fields,
        public readonly array $ignored,
        public readonly array $rows,
        public readonly string $calendar,
    ) {
    }
}
