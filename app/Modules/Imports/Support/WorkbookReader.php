<?php

namespace App\Modules\Imports\Support;

use App\Modules\Imports\Enums\ImportField;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Reads the candidates sheet of an Excel file (.xlsx or .xls): picks the sheet, finds the header row,
 * maps columns to fields by header text, and returns the non-empty data rows with typed cell values.
 * Formulas are never evaluated: their value is the one Excel saved in the file. Only one sheet is loaded
 * at a time (a real legacy workbook holds 11 other sheets, 200 MB unpacked, that are never read).
 */
final class WorkbookReader
{
    /** Sheets tried when no sheet is named like the candidates sheet (each is loaded on its own). */
    private const MAX_SHEETS_TRIED = 15;

    /** Longest cell text kept (a longer value is still refused by the checker, which sees it is too long). */
    private const MAX_CELL_LENGTH = 4000;

    public static function read(string $path): SheetData
    {
        $file = FileInspector::inspect($path);
        $preferred = HeaderMatcher::normalize((string) config('imports.sheet_name'));

        try {
            // Excel formats only, chosen by content: never let sniffing read a text file as CSV or HTML.
            $reader = IOFactory::createReader($file->format === FileInspector::XLSX ? IOFactory::READER_XLSX : IOFactory::READER_XLS);
            $names = $reader->listWorksheetNames($path);
        } catch (Throwable) {
            ImportProblem::fail('file', 'unreadable_file');
        }

        // The sheet named like the candidates sheet alone if there is one; otherwise the first sheets in order.
        $candidates = array_values(array_filter($names, fn(string $name) => $preferred !== '' && str_contains(HeaderMatcher::normalize($name), $preferred)));
        $candidates = $candidates !== [] ? [$candidates[0]] : array_slice($names, 0, self::MAX_SHEETS_TRIED);

        $best = null;

        foreach ($candidates as $name) {
            $file->assertSheetIsReasonable($name);
            $sheet = self::loadSheet($reader, $path, $name);
            $found = self::findHeaderRow($sheet);

            if ($found !== null && ($best === null || count($found['fields']) > count($best['fields']))) {
                $best = $found + ['sheet' => $sheet];
            } else {
                $sheet->getParent()?->disconnectWorksheets();   // free a sheet that is not used
            }
        }

        if ($best === null) {
            ImportProblem::fail('file', 'no_header_row');
        }

        $missing = array_filter(
            ImportField::cases(),
            fn(ImportField $field) => $field->isRequiredColumn() && ! isset($best['fields'][$field->value]),
        );

        if ($missing !== []) {
            ImportProblem::fail('file', 'missing_columns', [
                'columns' => implode('، ', array_map(fn(ImportField $field) => '«' . ($field->headers()[0] ?? $field->value) . '»', $missing)),
            ]);
        }

        self::assertNoUnsafeFormulas($best['sheet']);

        return new SheetData(
            sheetName: $best['sheet']->getTitle(),
            headerRow: $best['row'],
            headers: $best['headers'],
            fields: $best['fields'],
            ignored: $best['ignored'],
            rows: self::dataRows($best['sheet'], $best['row'], $best['headers'], $best['fields']),
            calendar: (string) $best['sheet']->getParent()->getExcelCalendar(),
        );
    }

    private static function loadSheet(IReader $reader, string $path, string $name): Worksheet
    {
        try {
            $reader->setLoadSheetsOnly([$name]);
            $spreadsheet = $reader->load($path, IReader::IGNORE_EMPTY_CELLS);
        } catch (Throwable) {
            ImportProblem::fail('file', 'unreadable_file');
        }

        return $spreadsheet->getSheetByName($name) ?? $spreadsheet->getActiveSheet();
    }

    /**
     * Formulas are never evaluated here, but the original is kept and staff may open it: refuse formulas
     * that reach outside the workbook or run something when opened (external references, DDE, web calls).
     */
    private static function assertNoUnsafeFormulas(Worksheet $sheet): void
    {
        $unsafe = '/\|'                                                    // DDE: =cmd|' /c ...'!A0
            . '|\[\d+\]|\[[^\]]*\.(xl|csv|ods)|[A-Za-z]:\\\\|\\\\\\\\'   // external workbooks, paths, UNC
            . '|\b(WEBSERVICE|FILTERXML|CALL|REGISTER(\.ID)?|RTD|EXEC|DDE|HYPERLINK|IMPORTDATA|IMPORTXML)\s*\(/i';

        foreach ($sheet->getCoordinates() as $coordinate) {
            $cell = $sheet->getCell($coordinate);

            if ($cell->isFormula() && preg_match($unsafe, (string) $cell->getValue()) === 1) {
                ImportProblem::fail('file', 'unsafe_formula', ['cell' => $coordinate]);
            }
        }
    }

    /** @return array{row: int, headers: array<string, string>, fields: array<string, string>, ignored: list<string>}|null */
    private static function findHeaderRow(Worksheet $sheet): ?array
    {
        $lastRow = min((int) config('imports.header_search_rows', 20), $sheet->getHighestDataRow());
        $lastColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        $best = null;

        for ($row = 1; $row <= $lastRow; $row++) {
            $headers = [];

            for ($column = 1; $column <= $lastColumn; $column++) {
                $text = trim(self::cell($sheet, $column, $row)->text());

                if ($text !== '') {
                    $headers[Coordinate::stringFromColumnIndex($column)] = $text;
                }
            }

            $match = HeaderMatcher::match($headers);

            if (! isset($match['fields'][ImportField::FullName->value], $match['fields'][ImportField::NationalNumber->value])) {
                continue;
            }

            if ($best === null || count($match['fields']) > count($best['fields'])) {
                $best = ['row' => $row, 'headers' => $headers] + $match;
            }
        }

        return $best;
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, string>  $fields
     * @return list<array{number: int, cells: array<string, CellValue>}>
     */
    private static function dataRows(Worksheet $sheet, int $headerRow, array $headers, array $fields): array
    {
        $rows = [];
        $max = (int) config('imports.max_rows', 5000);
        $lastRow = $sheet->getHighestDataRow();

        for ($row = $headerRow + 1; $row <= $lastRow; $row++) {
            $cells = [];

            foreach (array_keys($headers) as $letter) {
                $cells[$letter] = self::cell($sheet, Coordinate::columnIndexFromString($letter), $row);
            }

            // A row counts only if one of the fields the import reads has a value (helper columns with
            // formulas copied down an empty template do not make a row).
            $hasData = false;

            foreach ($fields as $letter) {
                if (! $cells[$letter]->isEmpty()) {
                    $hasData = true;
                    break;
                }
            }

            if (! $hasData) {
                continue;
            }

            if (count($rows) >= $max) {
                ImportProblem::fail('file', 'too_many_rows', ['count' => '+' . $max, 'max' => $max]);
            }

            $rows[] = ['number' => $row, 'cells' => $cells];
        }

        if ($rows === []) {
            ImportProblem::fail('file', 'no_rows');
        }

        return $rows;
    }

    private static function cell(Worksheet $sheet, int $column, int $row): CellValue
    {
        if (! $sheet->cellExists([$column, $row])) {
            return new CellValue(null, '');
        }

        $cell = $sheet->getCell([$column, $row]);
        $value = $cell->isFormula() ? $cell->getOldCalculatedValue() : $cell->getValue();

        if ($value instanceof RichText) {
            $value = $value->getPlainText();
        }

        if (is_string($value) && mb_strlen($value) > self::MAX_CELL_LENGTH) {
            $value = mb_substr($value, 0, self::MAX_CELL_LENGTH);
        }

        if ($value === null || $value === '') {
            return new CellValue(null, '');
        }

        $display = is_string($value)
            ? $value
            : NumberFormat::toFormattedString($value, $cell->getStyle()->getNumberFormat()->getFormatCode() ?? NumberFormat::FORMAT_GENERAL);

        return new CellValue($value, trim((string) $display));
    }
}
