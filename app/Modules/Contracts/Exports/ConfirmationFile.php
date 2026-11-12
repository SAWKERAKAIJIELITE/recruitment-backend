<?php

namespace App\Modules\Contracts\Exports;

use App\Modules\Contracts\Models\Contract;
use App\Modules\Identity\Models\StaffUser;
use App\Support\ArabicText;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * ملف التثبيت (D-098): the owner's «نموذج تثبيت متعاقد مدني», one contract per row from row 7, in its 33 columns, right
 * to left. The header block (rows 1-5) has the state, the ministry and the issuing body on the right, the emblem and the
 * title in the middle, and رقم الكتاب and the date (Hijri and Gregorian) left blank on the left, for the letter that
 * sends it. The program fills every column but the four of شعبة الخدمة (رقم الكتاب, تاريخ منح الرقم, تاريخ مباشرة
 * العمل, ملاحظات فرع الخدمة; red titles) and the template's column without a title (AD).
 *
 *  - رقم الاستمارة: the contract's reference number; الرقم الذاتي: the application's employee number;
 *  - التولد: the date of birth, month/day/year as the title asks; الحالة الاجتماعية: 1 married, 0 otherwise;
 *  - السوية التنظيمية الثانية: the unit joined; the third to the sixth levels, مكان العمل, نمط العمل, التخصص, اسم
 *    الجامعة, نوع الشهادة, المعدل, تاريخ الحصول على الشهادة and الراتب: as entered on the contract;
 *  - مسمى العمل and الرمز الوظيفي: the job position's; الاختصاص: the application's specialization;
 *  - السكن الحالي: the residence; السكن الأصلي: البلد الأصلي entered on the contract, else the place of birth.
 */
final class ConfirmationFile
{
    public const RELATIONS = [
        'unit', 'application.candidate.birthPlace', 'application.jobPosition', 'application.specialization',
        'application.maritalStatus', 'application.residenceDistrict', 'application.residenceArea',
    ];

    /** [title, width] of columns A to AG, as in the template. */
    public const COLUMNS = [
        ['م', 5.4], ['رقم الكتاب', 13.9], ['رقم الاستمارة', 18.9], ['الرقم الذاتي', 13.9], ['الاسم الثلاثي', 23],
        ['اسم الأم', 20], ['التولد ( الشهر /اليوم /السنة )', 24.4], ['الحالة الاجتماعية ( 1/0 )', 21.7], ['الرقم الوطني', 15],
        ['السوية_التنظيمية_الثانية', 21.6], ['السوية_التنظيمية_الثالثة', 21.4], ['السوية_التنظيمية_الرابعة', 24.4],
        ['السوية_التنظيمية_الخامسة', 21.1], ['السوية_التنظيمية_السادسة', 23.1], ['نمط العمل', 11.1], ['التخصص', 13],
        ['مسمى العمل', 22], ['الرمز الوظيفي', 22.4], ['اسم الجامعة', 20], ['نوع الشهادة', 20], ['الاختصاص', 18],
        ['المعدل بالنسبة المئوية', 14], ['تاريخ الحصول على الشهادة', 24.4], ['مكان العمل ', 22.4], ['السكن الحالي', 24],
        ['السكن الأصلي', 18], ['الراتب المستحق', 22.4], ['بدء العقد', 22.4], ['انتهاء العقد', 16], ['', 12],
        ['تاريخ منح الرقم', 16.6], ['تاريخ مباشرة العمل ', 16.6], ['ملاحظات فرع الخدمة', 29],
    ];

    /** Filled by شعبة الخدمة: red titles, left empty. */
    private const SERVICE_BRANCH = ['B', 'AE', 'AF', 'AG'];

    private const DATE_COLUMNS = ['W', 'AB', 'AC'];

    private const HEADER_ROW = 6;

    /**
     * @param  Collection<int, Contract>  $contracts  loaded with RELATIONS
     * @return string the path of a temporary file (deleted after sending)
     */
    public static function write(Collection $contracts, StaffUser $issuer): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getDefaultStyle()->getFont()->setName('Calibri')->setSize(11);
        $spreadsheet->getProperties()->setCreator($issuer->full_name)->setTitle(__('contracts.confirmation_title', [], 'ar'));
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('تثبيت تعاقد');
        $sheet->setRightToLeft(true);
        $last = 'AG';

        // The header block (rows 1-5).
        $sheet->mergeCells('A1:E5');
        $sheet->setCellValueExplicit('A1', implode("\n", ['الجمهورية العربية السورية', 'وزارة الدفاع', 'الجهة : ' . config('contracts.issuing_unit')]), DataType::TYPE_STRING);
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 14],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);
        $sheet->mergeCells('P2:T5');
        $sheet->setCellValueExplicit('P2', __('contracts.confirmation_title', [], 'ar'), DataType::TYPE_STRING);
        $sheet->getStyle('P2')->applyFromArray([
            'font' => ['bold' => true, 'size' => 36],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->mergeCells('AE1:AG5');
        $sheet->setCellValueExplicit('AE1', implode("\n", ['رقم الكتاب : (                    )', 'الـتـاريـخ:      /     /       هـ', 'الموافق لـ:     /     /         م']), DataType::TYPE_STRING);
        $sheet->getStyle('AE1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 12],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);

        $emblem = resource_path('images/emblem.png');

        if (is_file($emblem)) {
            $drawing = new Drawing;
            $drawing->setName('emblem');
            $drawing->setPath($emblem);
            $drawing->setHeight(95);
            $drawing->setCoordinates('M1');
            $drawing->setOffsetY(4);
            $drawing->setWorksheet($sheet);
        }

        foreach ([1 => 14.25, 2 => 13.5, 3 => 13.5, 4 => 13.5, 5 => 59.25, self::HEADER_ROW => 39.75] as $row => $height) {
            $sheet->getRowDimension($row)->setRowHeight($height);
        }

        // Titles (row 6).
        foreach (self::COLUMNS as $index => [$title, $width]) {
            $column = self::column($index);
            $sheet->setCellValueExplicit("{$column}" . self::HEADER_ROW, $title, DataType::TYPE_STRING);
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        $sheet->getStyle('A' . self::HEADER_ROW . ":{$last}" . self::HEADER_ROW)->applyFromArray([
            'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '006666']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'FFFFFF']]],
        ]);

        foreach (array_slice(self::SERVICE_BRANCH, 1) as $column) {
            $sheet->getStyle("{$column}" . self::HEADER_ROW)->getFill()->getStartColor()->setRGB('C00000');
        }

        // One contract per row.
        $line = self::HEADER_ROW;

        foreach ($contracts->values() as $index => $contract) {
            $line++;

            foreach (self::row($contract, $index + 1) as $column => $value) {
                $cell = "{$column}{$line}";

                match (true) {
                    $value === null => null,
                    $value instanceof CarbonInterface => $sheet->setCellValue($cell, ExcelDate::PHPToExcel($value->toDateString() . ' 00:00:00')),
                    is_int($value), is_float($value) => $sheet->setCellValue($cell, $value),
                    default => $sheet->setCellValueExplicit($cell, (string) $value, DataType::TYPE_STRING),
                };
            }
        }

        $lastLine = max($line, self::HEADER_ROW + 1);
        $sheet->getStyle('A' . (self::HEADER_ROW + 1) . ":{$last}{$lastLine}")->applyFromArray([
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR]],
        ]);

        foreach (self::DATE_COLUMNS as $column) {
            $sheet->getStyle("{$column}" . (self::HEADER_ROW + 1) . ":{$column}{$lastLine}")->getNumberFormat()->setFormatCode('yyyy/mm/dd');
        }

        $sheet->getStyle('AA' . (self::HEADER_ROW + 1) . ":AA{$lastLine}")->getNumberFormat()->setFormatCode('#,##0.##');
        $sheet->freezePane('A' . (self::HEADER_ROW + 1));
        $sheet->setAutoFilter('A' . self::HEADER_ROW . ":{$last}{$lastLine}");
        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A4)->setFitToWidth(1)->setFitToHeight(0);
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(self::HEADER_ROW, self::HEADER_ROW);
        $sheet->getPageSetup()->setHorizontalCentered(true);

        $path = tempnam(sys_get_temp_dir(), 'confirmation') . '.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    /** @return array<string, string|int|float|CarbonInterface|null> column => value */
    public static function row(Contract $contract, int $serial): array
    {
        $application = $contract->application;
        $candidate = $application->candidate;
        $married = $application->maritalStatus === null ? null : (int) ($application->maritalStatus->name_key === ArabicText::searchKey('متزوج'));
        $residence = array_filter([$application->residenceDistrict?->name_ar, $application->residenceArea?->name_ar, $application->residence_details], fn(?string $part) => $part !== null && trim($part) !== '');

        return [
            'A' => $serial,
            'C' => $contract->reference_number,
            'D' => $application->existing_employee_number,
            'E' => $candidate->full_name,
            'F' => $candidate->mother_name,
            'G' => $candidate->birth_date->format('m/d/Y'),
            'H' => $married,
            'I' => $candidate->national_number,
            'J' => $contract->unit?->name_ar,
            'K' => $contract->level_3,
            'L' => $contract->level_4,
            'M' => $contract->level_5,
            'N' => $contract->level_6,
            'O' => $contract->work_pattern,
            'P' => $contract->job_specialty,
            'Q' => $application->jobPosition?->name_ar,
            'R' => $application->jobPosition?->job_code,
            'S' => $contract->university,
            'T' => $contract->certificate_type?->labelAr(),
            'U' => $application->specialization?->name_ar,
            'V' => $contract->gpa === null ? null : (float) $contract->gpa,
            'W' => $contract->certificate_date,
            'X' => $contract->workplace,
            'Y' => $residence === [] ? null : implode(' - ', array_map('trim', $residence)),
            'Z' => $contract->origin ?? $candidate->birthPlace?->name_ar,
            'AA' => $contract->salary_usd === null ? null : (float) $contract->salary_usd,
            'AB' => $contract->starts_on,
            'AC' => $contract->ends_on,
        ];
    }

    /** A, B, ..., Z, AA, ... AG. */
    private static function column(int $index): string
    {
        return $index < 26 ? chr(65 + $index) : 'A' . chr(65 + $index - 26);
    }

    /** "ملف التثبيت 2026-11-11.xlsx" */
    public static function filename(): string
    {
        return __('contracts.confirmation_file', [], 'ar') . ' ' . Date::now(config('reports.timezone'))->toDateString() . '.xlsx';
    }
}
