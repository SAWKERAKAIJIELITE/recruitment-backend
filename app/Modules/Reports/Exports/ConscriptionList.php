<?php

namespace App\Modules\Reports\Exports;

use App\Modules\Recruitment\Models\Application;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * The list sent to شعبة التجنيد, in the layout of its template (owner, D-069): the header block on rows 1-3, the
 * column titles on row 4, one person per row from row 5, right to left, the template's column widths.
 *
 *  - الاسم الثلاثي في الهوية: the candidate's full name;  اسم الام والنسبة: the mother's name;
 *  - تاريخ الميلاد: day/month/year, as the template asks;  الحالة الاجتماعية, التحصيل العلمي (the academic
 *    qualification), مسمى العمل (the job position): from the application;
 *  - شعبة التجنيد: the place of birth (owner: "شعبة التجنيد must be the birth_place");
 *  - الجهة العسكرية: the same for everyone (config reports.military_entity);  ملاحظات: left for the reader.
 */
final class ConscriptionList
{
    public const RELATIONS = ['candidate.birthPlace', 'maritalStatus', 'academicQualification', 'jobPosition'];

    /** [title, width] as in the template. */
    private const COLUMNS = [
        ['الاسم الثلاثي  في الهوية', 21.7], ['اسم الام والنسبة', 15.1], ['تاريخ الميلاد', 19.2], ['الحالة الاجتماعية', 20.5],
        ['التحصيل العلمي', 18.8], ['شعبة التجنيد', 17.4], ['مسمى العمل', 18.8], ['الجهة العسكرية', 18.6], ['ملاحظات', 28.9],
    ];

    /**
     * @param  Collection<int, Application>  $applications  loaded with RELATIONS
     * @return string the path of a temporary file (deleted after sending)
     */
    public static function write(Collection $applications, ReportHeader $header): string
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getDefaultStyle()->getFont()->setName('Arial')->setSize(11);
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('ورقة1');
        $sheet->setRightToLeft(true);
        $sheet->setShowGridlines(false);
        $text = fn(string $cell, ?string $value) => $value === null ? null : $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_STRING);

        $sheet->mergeCells('A1:I3');
        $text('A1', implode("\n", [$header->organization(), $header->department(), $header->title, $header->metaLine()]));
        $sheet->getStyle('A1:I3')->applyFromArray([
            'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => '002060']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DCE6F2']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => ['outline' => ['borderStyle' => Border::BORDER_THICK, 'color' => ['rgb' => '002060']]],
        ]);
        $sheet->getRowDimension(3)->setRowHeight(80);

        foreach (self::COLUMNS as $index => [$title, $width]) {
            $column = chr(ord('A') + $index);
            $text("{$column}4", $title);
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        $sheet->getRowDimension(4)->setRowHeight(32.4);
        $sheet->getStyle('A4:I4')->applyFromArray([
            'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F4E79']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);

        $line = 4;
        $entity = (string) config('reports.military_entity');

        foreach ($applications as $application) {
            $line++;
            $candidate = $application->candidate;

            foreach ([
                $candidate->full_name,
                $candidate->mother_name,
                $candidate->birth_date->format('d/m/Y'),
                $application->maritalStatus->name_ar,
                $application->academicQualification->name_ar,
                $candidate->birthPlace?->name_ar,
                $application->jobPosition->name_ar,
                $entity,
                null,
            ] as $index => $value) {
                $text(chr(ord('A') + $index) . $line, $value);
            }

            $sheet->getRowDimension($line)->setRowHeight(19.95);
        }

        $sheet->getStyle("A4:I{$line}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('9DC3E6');

        if ($line > 4) {
            $sheet->getStyle("A5:I{$line}")->applyFromArray([
                'font' => ['bold' => true, 'size' => 12],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            ]);
        }

        $sheet->setAutoFilter("A4:I{$line}");
        $sheet->freezePane('A5');
        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A4)->setFitToWidth(1)->setFitToHeight(0)->setRowsToRepeatAtTopByStartAndEnd(4, 4);
        $sheet->getHeaderFooter()->setOddFooter('&L&P / &N');

        $path = tempnam(sys_get_temp_dir(), 'conscription-');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }
}
