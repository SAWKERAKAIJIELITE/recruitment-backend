<?php

namespace App\Modules\Contracts\Support;

use App\Modules\Imports\Support\FileInspector;
use App\Modules\Imports\Support\HeaderMatcher;
use App\Modules\Recruitment\Models\JobPosition;
use App\Support\ArabicText;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Reads a vacancies file (D-088): the first sheet of an .xlsx or .xls, checked like an import (D-045: no macros,
 * external links or zip bombs), with a header row holding «المسمى الوظيفي» and «عدد الشواغر» (or their usual
 * variants) in the first 20 rows. Below it, one job position per row, named as in the job positions list (in any
 * spelling: أ/ا, ى/ي, ة/ه), and a whole number of vacancies (0 or more). Empty rows and a total row are skipped.
 * Every problem is reported with its row; one problem refuses the whole file.
 */
final class VacancyFile
{
    private const POSITION_HEADERS = ['المسمى الوظيفي', 'مسمى العمل', 'المسمى', 'الوظيفة'];

    private const COUNT_HEADERS = ['عدد الشواغر', 'الشواغر', 'عدد العقود', 'العدد'];

    /** Rows like these hold the sum of the others. */
    private const TOTAL_ROWS = ['المجموع', 'المجموع الكلي', 'الاجمالي', 'الإجمالي', 'المجموع العام'];

    private const MAX_ROWS = 1000;

    private const HEADER_SEARCH_ROWS = 20;

    /** @return list<array{row_number: int, job_position_id: int, vacancies: int}> */
    public static function read(string $path): array
    {
        $sheet = self::sheet($path);
        [$headerRow, $positionColumn, $countColumn] = self::header($sheet);
        $positions = self::positions();
        $totals = array_map(fn(string $text) => HeaderMatcher::normalize($text), self::TOTAL_ROWS);
        $items = [];
        $seen = [];
        $errors = [];
        $lastRow = $sheet->getHighestDataRow();

        if ($lastRow - $headerRow > self::MAX_ROWS) {
            self::fail([__('contracts.vacancy_file.too_many_rows', ['max' => self::MAX_ROWS])]);
        }

        for ($row = $headerRow + 1; $row <= $lastRow; $row++) {
            $title = trim((string) $sheet->getCell([$positionColumn, $row])->getFormattedValue());
            $countCell = $sheet->getCell([$countColumn, $row]);
            $countText = trim((string) $countCell->getFormattedValue());

            if (($title === '' && $countText === '') || in_array(HeaderMatcher::normalize($title), $totals, true)) {
                continue;
            }

            $position = $positions[ArabicText::searchKey($title)] ?? null;

            if ($title === '') {
                $errors[] = __('contracts.vacancy_file.no_position', ['row' => $row]);
            } elseif ($position === null) {
                $errors[] = __('contracts.vacancy_file.unknown_position', ['row' => $row, 'name' => $title]);
            } elseif ($position->trashed()) {
                $errors[] = __('contracts.vacancy_file.archived_position', ['row' => $row, 'name' => $title]);
            } elseif (isset($seen[$position->id])) {
                $errors[] = __('contracts.vacancy_file.duplicate_position', ['row' => $row, 'name' => $title, 'first' => $seen[$position->id]]);
            }

            $count = self::count($countCell->isFormula() ? null : $countCell->getValue());

            if ($countCell->isFormula()) {
                $errors[] = __('contracts.vacancy_file.formula', ['row' => $row]);
            } elseif ($count === null) {
                $errors[] = __('contracts.vacancy_file.bad_count', ['row' => $row, 'value' => $countText]);
            }

            if ($position !== null && ! isset($seen[$position->id])) {
                $seen[$position->id] = $row;
            }

            if ($position !== null && ! $position->trashed() && $count !== null) {
                $items[$position->id] ??= ['row_number' => $row, 'job_position_id' => (int) $position->id, 'vacancies' => $count];
            }
        }

        if ($errors !== []) {
            self::fail(array_slice($errors, 0, 50));
        }

        if ($items === []) {
            self::fail([__('contracts.vacancy_file.empty')]);
        }

        return array_values($items);
    }

    private static function sheet(string $path): Worksheet
    {
        $file = FileInspector::inspect($path);   // refuses what is not a safe Excel file (422 on `file`)

        try {
            $reader = IOFactory::createReader($file->format === FileInspector::XLSX ? IOFactory::READER_XLSX : IOFactory::READER_XLS);
            $names = $reader->listWorksheetNames($path);
            $file->assertSheetIsReasonable($names[0]);
            $reader->setLoadSheetsOnly([$names[0]]);
            $spreadsheet = $reader->load($path, IReader::IGNORE_EMPTY_CELLS);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable) {
            self::fail([__('imports.unreadable_file')]);
        }

        return $spreadsheet->getSheetByName($names[0]) ?? $spreadsheet->getActiveSheet();
    }

    /** @return array{0: int, 1: int, 2: int} the header row, the position column and the count column */
    private static function header(Worksheet $sheet): array
    {
        $lastRow = min(self::HEADER_SEARCH_ROWS, $sheet->getHighestDataRow());
        $lastColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        for ($row = 1; $row <= $lastRow; $row++) {
            $headers = [];

            for ($column = 1; $column <= $lastColumn; $column++) {
                $text = HeaderMatcher::normalize((string) $sheet->getCell([$column, $row])->getFormattedValue());

                if ($text !== '') {
                    $headers[$column] = $text;
                }
            }

            $position = self::column($headers, self::POSITION_HEADERS);
            $count = self::column(array_diff_key($headers, [$position => true]), self::COUNT_HEADERS);

            if ($position !== null && $count !== null) {
                return [$row, $position, $count];
            }
        }

        self::fail([__('contracts.vacancy_file.no_header')]);
    }

    /** The column whose header is one of the aliases (exactly first, then containing one, longest alias first). */
    private static function column(array $headers, array $aliases): ?int
    {
        $aliases = array_map(fn(string $alias) => HeaderMatcher::normalize($alias), $aliases);
        usort($aliases, fn(string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        foreach ([true, false] as $exact) {
            foreach ($aliases as $alias) {
                foreach ($headers as $column => $text) {
                    if ($exact ? $text === $alias : str_contains($text, $alias)) {
                        return $column;
                    }
                }
            }
        }

        return null;
    }

    /** @return array<string, JobPosition> every job position (archived too, for a precise message) by search key */
    private static function positions(): array
    {
        $positions = [];

        foreach (JobPosition::withTrashed()->orderByRaw('deleted_at is null')->get() as $position) {
            $positions[ArabicText::searchKey($position->name_ar)] = $position;   // an active one wins over an archived one
        }

        return $positions;
    }

    /** A whole number of 0 or more, typed as a number or as digits (Arabic-Indic too); null otherwise. */
    private static function count(mixed $value): ?int
    {
        if (is_int($value) || (is_float($value) && floor($value) === $value)) {
            return $value >= 0 && $value <= 100000 ? (int) $value : null;
        }

        $text = ArabicText::digitsToAscii(trim((string) $value));

        return preg_match('/^[0-9]{1,6}$/', $text) === 1 && (int) $text <= 100000 ? (int) $text : null;
    }

    /** @param list<string> $messages */
    private static function fail(array $messages): never
    {
        throw ValidationException::withMessages(['file' => $messages]);
    }
}
