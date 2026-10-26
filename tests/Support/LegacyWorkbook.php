<?php

namespace Tests\Support;

use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Builds synthetic workbooks in the layout of the legacy template (analysis doc §23, columns A to Z), for
 * tests only: no real person's data (§24 point 7).
 *
 * Cell values: strings are written as text (leading zeros kept), ints and floats as numbers,
 * DateTimeInterface as an Excel date serial, strings starting with "=" as formulas, ['text' => '...'] as
 * literal text even when it starts with "=".
 */
final class LegacyWorkbook
{
    /** The header row of the real template (row 5 of "بيانات المرشحين"). */
    public const HEADERS = [
        'A' => 'التنقل بين الصفحات',
        'B' => 'الجهة المرشحة',
        'C' => 'المسمى الوظيفي',
        'D' => 'الفئة',
        'E' => 'الرقم الذاتي',
        'F' => 'الاسم الثلاثي',
        'G' => 'اسم الأم',
        'H' => 'مكان الولادة',
        'I' => 'تاريخ الولادة',
        'J' => 'الرقم الوطني',
        'K' => 'الحالة الاجتماعية',
        'L' => 'الحالة الصحية/بالتفصيل',
        'M' => 'محافظة الإقامة',
        'N' => 'مكان الإقامة الحالية',
        'O' => 'المؤهل العلمي',
        'P' => 'الاختصاص',
        'Q' => 'عدد السنين الدراسية',
        'R' => 'عدد الأجزاء المحفوظة من القرآن',
        'S' => 'هل تعمل مع جهة حكومية أخرى',
        'T' => 'الالتزام بالمبيت والدوام بنسبة 50%',
        'U' => 'التفرغ الكامل للعمل',
        'V' => 'رقم الهاتف',
        'W' => 'ملاحظات',
        'X' => 'تاريخ الاستيراد',
        'Y' => 'المعرّف',
        'Z' => 'رقم الاختبار',
    ];

    /** Column A of the real template holds navigation labels, not data. */
    private const NAVIGATION = ['لوحة التحكم', '◄ بيانات المرشحين', 'ترقيم المرشحين', 'الاختبارات', 'المقابلات'];

    /** A valid row matching RecruitmentTestHelpers::referenceIds(); pass letter => value overrides. */
    public static function row(array $overrides = []): array
    {
        return array_replace([
            'B' => 'مديرية أوقاف دمشق',
            'C' => 'مدرس',
            'D' => 'الفئة الأولى',
            'E' => null,
            'F' => 'أحمد محمد علي',
            'G' => 'فاطمة حسن',
            'H' => 'دمشق',
            'I' => new \DateTimeImmutable('1998-03-01'),   // same person as RecruitmentTestHelpers::applicationPayload()
            'J' => '01234567890',
            'K' => 'أعزب',
            'L' => 'سليم',
            'M' => 'دمشق',
            'N' => 'المزة',
            'O' => 'إجازة جامعية',
            'P' => 'شريعة',
            'Q' => 12,
            'R' => 5,
            'S' => 'لا',
            'T' => 'نعم',
            'U' => 'نعم',
            'V' => '0912345678',
            'W' => null,
            'Y' => '=ROW()-5',                 // like the real template's derived id (its result is saved in the file)
            'Z' => 'EX-17',
        ], $overrides);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * Like the real template: three title rows, an empty row, the headers on row 5 (title => null puts the
     * headers on row 1).
     *
     * @param  array{headers?: array<string, ?string>, title?: ?string, sheets_before?: list<string>, sheet?: string, empty_rows_between?: bool, precalculate?: bool}  $options
     */
    public static function file(array $rows, array $options = [], string $filename = 'candidates.xlsx'): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);

        foreach ($options['sheets_before'] ?? ['لوحة التحكم'] as $name) {
            $other = $spreadsheet->createSheet();
            $other->setTitle($name);
            $other->setCellValue('A1', 'إحصاءات');
        }

        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($options['sheet'] ?? 'بيانات المرشحين');
        $sheet->setRightToLeft(true);

        $headerRow = 1;
        $title = array_key_exists('title', $options) ? $options['title'] : 'قاعدة بيانات المرشحين';

        if ($title !== null) {
            $sheet->setCellValue('B1', 'وزارة تجريبية');
            $sheet->setCellValue('B2', 'قسم التوظيف');
            $sheet->setCellValue('B3', $title);
            $headerRow = 5;
        }

        $headers = array_replace(self::HEADERS, $options['headers'] ?? []);

        foreach ($headers as $letter => $text) {
            if ($text !== null) {
                $sheet->setCellValue("{$letter}{$headerRow}", $text);
            }
        }

        $line = $headerRow + 1;

        foreach ($rows as $index => $row) {
            $row = ['A' => self::NAVIGATION[$index] ?? null, 'X' => new \DateTimeImmutable('2025-11-01')] + $row;

            foreach ($row as $letter => $value) {
                if ($value === null || ($headers[$letter] ?? null) === null) {
                    continue;
                }

                $coordinate = "{$letter}{$line}";

                match (true) {
                    is_array($value) => $sheet->setCellValueExplicit($coordinate, (string) $value['text'], DataType::TYPE_STRING),
                    $value instanceof DateTimeInterface => $sheet->setCellValue($coordinate, ExcelDate::PHPToExcel($value))
                        ->getStyle($coordinate)->getNumberFormat()->setFormatCode('dd/mm/yyyy'),
                    is_string($value) && str_starts_with($value, '=') => $sheet->setCellValue($coordinate, $value),
                    is_string($value) => $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_STRING),
                    default => $sheet->setCellValue($coordinate, $value),
                };
            }

            $line += ($options['empty_rows_between'] ?? false) ? 2 : 1;
        }

        $path = tempnam(sys_get_temp_dir(), 'legacy-') . '.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas($options['precalculate'] ?? true);   // false: formulas the engine must not run
        $writer->save($path);

        return new UploadedFile($path, $filename, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }
}
