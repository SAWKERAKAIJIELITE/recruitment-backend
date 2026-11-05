<?php

namespace App\Modules\Reports\Exports;

use App\Modules\Reports\Support\ReportTable;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Report tables as an Excel workbook that looks like the legacy one ("نظام التوظيف والاختبارات والمقابلات"): one
 * sheet per entry, right to left, the green and gold header band (organization, department, title), a line with the
 * period, date, issuer and filters, then each table with its header row and its totals. A sheet with one table gets
 * frozen headers and filters; a workbook of several sheets gets links between them on the first one.
 *
 * Text is always written as text: no cell becomes a formula, and leading zeros (national numbers) survive.
 */
final class ReportWorkbook
{
    private const GREEN = '12402A';

    private const HEADER_GREEN = '1B5E3A';

    private const GOLD = 'C9A227';

    private const CREAM = 'F2E6C2';

    private const ROW = 'FBF9F3';

    private const LINE = 'D9CFB4';

    /**
     * @param  list<array{name: string, title: string, tables: list<ReportTable>}>  $sheets
     * @return string the path of a temporary file (deleted after sending)
     */
    public static function write(array $sheets, ReportHeader $header): string
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);
        $spreadsheet->getProperties()->setTitle($header->title)->setCreator($header->issuer->full_name);
        $spreadsheet->removeSheetByIndex(0);
        $names = [];

        foreach ($sheets as $sheet) {
            $worksheet = $spreadsheet->createSheet();
            $worksheet->setTitle($names[] = self::sheetName($sheet['name'], $names));
            self::fill($worksheet, $sheet['title'], $sheet['tables'], $header);
        }

        if (count($names) > 1) {
            self::links($spreadsheet->getSheet(0), $names);
        }

        $spreadsheet->setActiveSheetIndex(0);
        $path = tempnam(sys_get_temp_dir(), 'report-');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    /** @param list<ReportTable> $tables */
    private static function fill(Worksheet $sheet, string $title, array $tables, ReportHeader $header): void
    {
        $width = max(4, ...array_map(fn(ReportTable $table) => count($table->columns), $tables));
        $last = Coordinate::stringFromColumnIndex($width);
        $sheet->setRightToLeft(true);
        $sheet->setShowGridlines(false);

        foreach ([[1, $header->organization(), 16, self::GOLD, 26], [2, $header->department(), 12, self::CREAM, 20], [3, $title, 14, 'FFFFFF', 24]] as [$row, $text, $size, $color, $height]) {
            $sheet->mergeCells("A{$row}:{$last}{$row}");
            self::text($sheet, 1, $row, $text);
            $sheet->getRowDimension($row)->setRowHeight($height);
            $sheet->getStyle("A{$row}:{$last}{$row}")->applyFromArray([
                'font' => ['bold' => true, 'size' => $size, 'color' => ['rgb' => $color]],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::GREEN]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            ]);
        }

        $sheet->mergeCells("A4:{$last}4");
        self::text($sheet, 1, 4, $header->metaLine());
        $sheet->getRowDimension(4)->setRowHeight(30);
        $sheet->getStyle("A4:{$last}4")->applyFromArray([
            'font' => ['size' => 9, 'color' => ['rgb' => self::GREEN]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::CREAM]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);

        if (($logo = $header->logo()) !== null) {
            $drawing = new Drawing();
            $drawing->setPath($logo);
            $drawing->setHeight(64);
            $drawing->setCoordinates('A1');
            $drawing->setOffsetX(4);
            $drawing->setOffsetY(4);
            $drawing->setWorksheet($sheet);
        }

        $line = 5;
        $single = count($tables) === 1;

        foreach ($tables as $table) {
            if (! $single) {
                $line++;
                self::text($sheet, 1, $line, $table->title);
                $sheet->getStyle("A{$line}")->getFont()->setBold(true)->setSize(12)->getColor()->setRGB(self::GREEN);
                $line++;
            }

            $line = self::table($sheet, $table, $line, $single);
        }

        foreach ($tables[0]->columns ?? [] as $index => [, $characters]) {
            $sheet->getColumnDimensionByColumn($index + 1)->setWidth($characters);
        }

        if ($single) {
            $sheet->freezePane('A6');
            $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, 5);
        }

        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A4)->setFitToWidth(1)->setFitToHeight(0);
        $sheet->getHeaderFooter()->setOddFooter('&L&P / &N&R' . $title);
    }

    /** Writes a table from $line (its header row); returns the last line used. */
    private static function table(Worksheet $sheet, ReportTable $table, int $line, bool $filters): int
    {
        $last = Coordinate::stringFromColumnIndex(count($table->columns));
        $headerLine = $line;

        foreach ($table->columns as $index => [$label]) {
            self::text($sheet, $index + 1, $line, $label);
        }

        $sheet->getRowDimension($line)->setRowHeight(36);
        $sheet->getStyle("A{$line}:{$last}{$line}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::HEADER_GREEN]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::LINE]]],
        ]);

        foreach ($table->rows as $row) {
            $line++;

            foreach (array_values($row) as $index => $value) {
                self::value($sheet, $index + 1, $line, $value);
            }
        }

        if ($table->rows !== []) {
            $sheet->getStyle('A' . ($headerLine + 1) . ":{$last}{$line}")->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::ROW]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::LINE]]],
            ]);
        }

        if ($filters) {
            $sheet->setAutoFilter("A{$headerLine}:{$last}{$line}");
        }

        if ($table->totals !== []) {
            $line++;

            foreach ($table->totals as $label => $value) {
                $line++;
                self::text($sheet, 1, $line, $label);
                self::value($sheet, 2, $line, $value);
                $sheet->getStyle("A{$line}:B{$line}")->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => self::GREEN]],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::CREAM]],
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::LINE]]],
                ]);
            }
        }

        return $line + 1;
    }

    /** "التنقل بين الصفحات": a link to every sheet, at the right of the first one's tables. */
    private static function links(Worksheet $first, array $names): void
    {
        $column = Coordinate::columnIndexFromString($first->getHighestColumn()) + 2;
        $letter = Coordinate::stringFromColumnIndex($column);
        self::text($first, $column, 6, __('reports.navigation', [], 'ar'));
        $first->getStyle("{$letter}6")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '7B2D3B']],
        ]);
        $first->getColumnDimension($letter)->setWidth(30);

        foreach (array_slice($names, 1) as $index => $name) {
            $line = 7 + $index;
            self::text($first, $column, $line, $name);
            $first->getCell("{$letter}{$line}")->getHyperlink()->setUrl("sheet://'" . str_replace("'", "''", $name) . "'!A1");
            $first->getStyle("{$letter}{$line}")->applyFromArray([
                'font' => ['underline' => true, 'color' => ['rgb' => self::GREEN]],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FBF5E6']],
            ]);
        }
    }

    private static function value(Worksheet $sheet, int $column, int $line, string|int|float|null $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (is_string($value)) {
            self::text($sheet, $column, $line, $value);

            return;
        }

        $sheet->setCellValue([$column, $line], $value);

        if (is_float($value)) {
            $sheet->getStyle([$column, $line])->getNumberFormat()->setFormatCode('0.0');
        }
    }

    private static function text(Worksheet $sheet, int $column, int $line, string $value): void
    {
        $sheet->setCellValueExplicit([$column, $line], $value, DataType::TYPE_STRING);
    }

    /** Excel's rules for a sheet name: 31 characters, none of []:*?/\, unique in the workbook. */
    private static function sheetName(string $name, array $taken): string
    {
        $name = mb_substr(trim(str_replace(['[', ']', ':', '*', '?', '/', '\\'], ' ', $name)), 0, 31);
        $candidate = $name;

        for ($n = 2; in_array($candidate, $taken, true); $n++) {
            $candidate = mb_substr($name, 0, 28) . " {$n}";
        }

        return $candidate;
    }
}
