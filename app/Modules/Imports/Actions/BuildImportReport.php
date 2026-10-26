<?php

namespace App\Modules\Imports\Actions;

use App\Modules\Imports\Enums\ImportRowStatus;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportRow;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Analysis doc §09 step 7: the downloadable report of a batch, one line per row with its status, the
 * nomination number it received, and the reasons it was not imported. Right-to-left, Arabic headers.
 */
class BuildImportReport
{
    /** Writes the report to a temporary file and returns its path. */
    public function execute(ImportBatch $batch): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(mb_substr(__('imports.report.title'), 0, 31));
        $sheet->setRightToLeft(true);

        $headers = ['row', 'status', 'nomination_number', 'full_name', 'national_number', 'issues'];
        foreach ($headers as $index => $header) {
            $sheet->setCellValue([$index + 1, 1], __("imports.report.{$header}"));
        }
        $sheet->getStyle('A1:F1')->getFont()->setBold(true);

        $line = 2;

        foreach ($batch->rows()->with('application')->get() as $row) {
            /** @var ImportRow $row */
            $sheet->setCellValue([1, $line], $row->row_number);
            // Every text cell is written as text: a value starting with "=" must never become a formula.
            $sheet->setCellValueExplicit([2, $line], __("imports.statuses.{$row->status->value}"), DataType::TYPE_STRING);
            // Text, so leading zeros of numbers survive when the report is reopened (AC-16).
            $sheet->setCellValueExplicit([3, $line], (string) $row->application?->nomination_number, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([4, $line], (string) $row->value('candidate.full_name'), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([5, $line], (string) ($row->nationalNumber() ?? ($row->raw[$batch->column_map['national_number']['column'] ?? ''] ?? '')), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([6, $line], $this->reasons($row), DataType::TYPE_STRING);
            $line++;
        }

        foreach (range('A', 'F') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $path = tempnam(sys_get_temp_dir(), 'import-report-');   // deleted after sending
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }

    private function reasons(ImportRow $row): string
    {
        return match ($row->status) {
            ImportRowStatus::Excluded => (string) $row->exclude_reason,
            ImportRowStatus::Imported => (string) $row->review_note,
            default => implode(' | ', array_column($row->issues ?? [], 'message')),
        };
    }
}
