<?php

namespace App\Modules\Reports\Exports;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * The results list as an Excel workbook for analysis: the report header (title, date, issuer, filters), one line per
 * application, and the totals of the same lines. Right-to-left; every text cell is written as text (no formula, and
 * leading zeros of numbers survive).
 */
final class ResultsSpreadsheet
{
    private const COLUMNS = [
        'nomination_number', 'full_name', 'national_number', 'job_position', 'nominating_entity', 'exam_result',
        'admitted_by_exception', 'interview_result', 'interview_held_on', 'scientific_grade', 'general_grade', 'overall_grade', 'status',
    ];

    /**
     * @param  list<array>  $results  CandidateResults::of()
     * @return string the path of a temporary file (deleted after sending)
     */
    public static function write(array $results, array $totals, ReportHeader $header): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(mb_substr($header->title, 0, 31));
        $sheet->setRightToLeft(true);
        $text = fn(int $column, int $row, mixed $value) => $sheet->setCellValueExplicit([$column, $row], (string) $value, DataType::TYPE_STRING);

        $text(1, 1, $header->organization() . ' — ' . $header->title);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $text(1, 2, __('reports.generated', ['at' => $header->generatedAtText(), 'by' => $header->issuer->full_name], 'ar'));
        $text(1, 3, __('reports.filters', [], 'ar') . ': ' . self::filters($header));

        $line = 5;
        foreach (self::COLUMNS as $index => $column) {
            $text($index + 1, $line, __("reports.columns.{$column}", [], 'ar'));
        }
        $sheet->getStyle("A{$line}:M{$line}")->getFont()->setBold(true);

        foreach ($results as $result) {
            $line++;
            $application = $result['application'];
            $interview = $result['interview'];

            foreach ([
                $application->nomination_number,
                $application->candidate->full_name,
                $application->candidate->national_number,
                $application->jobPosition->name_ar,
                $application->nominatingEntity->name_ar,
                $result['exam']['label'] ?? '',
                ($result['exam']['admitted_by_exception'] ?? false) ? __('reports.yes', [], 'ar') : '',
                $interview['label'] ?? '',
                $interview['held_on'] ?? '',
                $interview['grades']['scientific']['label'] ?? '',
                $interview['grades']['general']['label'] ?? '',
                $interview['grades']['overall']['label'] ?? '',
                $application->current_status->labelAr(),
            ] as $index => $value) {
                $text($index + 1, $line, $value);
            }
        }

        $line += 2;
        $text(1, $line, __('reports.totals', [], 'ar'));
        $sheet->getStyle("A{$line}")->getFont()->setBold(true);

        foreach ($totals as $label => $count) {
            $line++;
            $text(1, $line, $label);
            $sheet->setCellValue([2, $line], $count);
        }

        foreach (range('A', 'M') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $path = tempnam(sys_get_temp_dir(), 'results-report-');
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }

    public static function filters(ReportHeader $header): string
    {
        if ($header->filters === []) {
            return __('reports.no_filters', [], 'ar');
        }

        return collect($header->filters)->map(fn(string $value, string $label) => "{$label}: {$value}")->implode(' — ');
    }
}
