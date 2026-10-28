<?php

namespace App\Modules\Imports\Database\Seeders;

use App\Modules\Identity\Models\StaffUser;
use App\Modules\Imports\Actions\ApproveImportBatch;
use App\Modules\Imports\Actions\CancelImportBatch;
use App\Modules\Imports\Actions\UploadImportBatch;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Recruitment\Database\Seeders\Demo\DemoReferences;
use App\Modules\Recruitment\Enums\ApplicationStatus;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\Candidate;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Date;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * LOCAL DEVELOPMENT ONLY. Three import batches built from generated workbooks in the legacy template's
 * layout, each through the real actions (the files are kept on the imports disk like real uploads):
 *
 * - approved: six new people, uploaded by "staff" and approved by "manager" (four eyes, D-047);
 * - pending: one row of every kind the reviewer meets (valid, a known person nominated again, a person whose
 *   name differs from the file on record, a person with a live application, an unknown list value, a bad
 *   phone, a duplicate in the file);
 * - cancelled: uploaded by mistake and cancelled.
 *
 * Run after RecruitmentDemoSeeder (the pending batch points at its people). Runs once.
 */
class ImportsDemoSeeder extends Seeder
{
    public const NOTE = 'دفعة تجريبية';

    /** The real template's header row (row 5 of "بيانات المرشحين"), columns B to W. */
    private const HEADERS = [
        'B' => 'الجهة المرشحة', 'C' => 'المسمى الوظيفي', 'D' => 'الفئة', 'E' => 'الرقم الذاتي', 'F' => 'الاسم الثلاثي',
        'G' => 'اسم الأم', 'H' => 'مكان الولادة', 'I' => 'تاريخ الولادة', 'J' => 'الرقم الوطني', 'K' => 'الحالة الاجتماعية',
        'L' => 'الحالة الصحية/بالتفصيل', 'M' => 'محافظة الإقامة', 'N' => 'مكان الإقامة الحالية', 'O' => 'المؤهل العلمي',
        'P' => 'الاختصاص', 'Q' => 'عدد السنين الدراسية', 'R' => 'عدد الأجزاء المحفوظة من القرآن',
        'S' => 'هل تعمل مع جهة حكومية أخرى', 'T' => 'الالتزام بالمبيت والدوام بنسبة 50%', 'U' => 'التفرغ الكامل للعمل',
        'V' => 'رقم الهاتف', 'W' => 'ملاحظات',
    ];

    /** Demo people of the import files: indexes after those of RecruitmentDemoSeeder. */
    private const FIRST_INDEX = 101;

    /** @var list<string> generated files, deleted after seeding (the upload keeps its own copy) */
    private array $files = [];

    public function __construct(
        private readonly UploadImportBatch $upload,
        private readonly ApproveImportBatch $approve,
        private readonly CancelImportBatch $cancel,
    ) {
    }

    public function run(): void
    {
        if (ImportBatch::query()->where('notes', 'like', self::NOTE . '%')->exists()) {
            $this->command?->info('Import demo data is already there: skipped.');

            return;
        }

        $references = new DemoReferences();
        $staff = StaffUser::query()->where('username', 'staff')->firstOrFail();
        $manager = StaffUser::query()->where('username', 'manager')->firstOrFail();   // holds imports.approve
        $now = CarbonImmutable::now();

        try {
            // Approved twenty days ago.
            Date::setTestNow($now->subDays(21)->setTime(10, 15));
            $rows = array_map(fn(int $i) => $this->row($references, self::FIRST_INDEX + $i), range(0, 5));
            $approved = $this->upload->execute($this->file($rows, 'ترشيحات الإدارة العامة.xlsx'), $staff, null, null, self::NOTE . ': معتمدة');

            Date::setTestNow($now->subDays(20)->setTime(12, 40));
            $this->approve->execute($approved, $manager);

            // Uploaded by mistake (wrong file), cancelled the same day.
            Date::setTestNow($now->subDays(9)->setTime(9, 5));
            $wrong = $this->upload->execute($this->file([$this->row($references, self::FIRST_INDEX + 20)], 'نسخة قديمة.xlsx'), $staff, null, null, self::NOTE . ': ملغاة');

            Date::setTestNow($now->subDays(9)->setTime(9, 20));
            $this->cancel->execute($wrong, $staff, 'رُفع الملف الخطأ، والنسخة الصحيحة سترفع لاحقًا');

            // Waiting for review.
            Date::setTestNow($now->subDays(2)->setTime(11, 30));
            $this->upload->execute($this->file($this->pendingRows($references), 'ترشيحات مديرية الأوقاف.xlsx'), $staff, null, null, self::NOTE . ': بانتظار المراجعة');
        } finally {
            Date::setTestNow();

            foreach ($this->files as $path) {
                @unlink($path);
            }
        }

        $this->command?->info('Import demo: 3 batches (approved, cancelled, pending).');
    }

    /** One row of every kind; the rows that need demo people from RecruitmentDemoSeeder are left out without them. */
    private function pendingRows(DemoReferences $references): array
    {
        $base = self::FIRST_INDEX + 10;
        $rows = [
            $this->row($references, $base),
            $this->row($references, $base + 1, ['Q' => '3 ومنقطع', 'W' => 'ترك الدراسة في السنة الرابعة']),
            $this->row($references, $base + 2, ['C' => 'مدرس رياضيات']),            // not in the list: invalid until added or corrected
            $this->row($references, $base + 3, ['V' => '09-ABC']),                   // phone_format
        ];
        // Another spelling of a listed entity (ة/ه, أ/ا): matched by the normalized name.
        $entity = $references->entry('nominating_entity_id', $base + 4)->name_ar;
        $rows[] = $this->row($references, $base + 4, ['B' => strtr($entity, ['ة' => 'ه', 'أ' => 'ا', 'إ' => 'ا', 'ى' => 'ي'])]);
        $rows[] = $this->row($references, $base, ['W' => 'مكرر في الملف']);       // duplicate_in_file

        $ended = Candidate::query()
            ->where('national_number', 'like', '09%')
            ->where('is_active', true)
            ->whereDoesntHave('applications', fn($query) => $query->live())
            ->orderBy('national_number')
            ->first();

        if ($ended !== null) {
            $index = (int) substr($ended->national_number, 2);
            $rows[] = $this->row($references, $index, ['W' => 'سبق ترشيحه وانتهى طلبه']);                     // the same person again: valid
        }

        $other = Candidate::query()
            ->where('national_number', 'like', '09%')
            ->where('is_active', true)
            ->whereDoesntHave('applications', fn($query) => $query->live())
            ->orderByDesc('national_number')
            ->first();

        if ($other !== null && ! $other->is($ended)) {
            $index = (int) substr($other->national_number, 2);
            $rows[] = $this->row($references, $index, ['F' => 'اسم مختلف عن الملف', 'G' => 'أم مختلفة']);   // person_mismatch: needs review
        }

        $live = Application::query()->where('current_status', ApplicationStatus::AwaitingExam)->with('candidate')->orderBy('nomination_number')->first();

        if ($live !== null && str_starts_with($live->candidate->national_number, '09')) {
            $rows[] = $this->row($references, (int) substr($live->candidate->national_number, 2));         // live_application: invalid
        }

        return $rows;
    }

    /** A row for demo person $index, written as staff type it in the legacy template; $overrides by column letter. */
    private function row(DemoReferences $references, int $index, array $overrides = []): array
    {
        $input = $references->applicationInput($index);
        $name = fn(string $field) => $references->entry($field, $index)->name_ar;
        $district = $references->entry('residence_district_id', $index);
        $area = $references->area($district, $index);
        $years = $input['studying_years'];
        $yesNo = fn(bool $value) => $value ? 'نعم' : 'لا';

        return array_replace([
            'B' => $name('nominating_entity_id'),
            'C' => $name('job_position_id'),
            'D' => $name('application_category_id'),
            'E' => $input['existing_employee_number'],
            'F' => $input['candidate']['full_name'],
            'G' => $input['candidate']['mother_name'],
            'H' => $references->entry('residence_district_id', $index + 2)->name_ar,
            'I' => new \DateTimeImmutable($input['candidate']['birth_date']),
            'J' => $input['candidate']['national_number'],
            'K' => $name('marital_status_id'),
            'L' => $name('health_condition_id'),
            'M' => $district->name_ar,
            'N' => $area?->name_ar,
            'O' => $name('academic_qualification_id'),
            'P' => $name('specialization_id'),
            'Q' => $years === null ? null : ($input['studies_discontinued'] ? "{$years} ومنقطع" : $years),
            'R' => $input['quran_parts_memorized'],
            'S' => $yesNo($input['works_for_other_government_entity']),
            'T' => $yesNo($input['overnight_work']),
            'U' => $yesNo($input['full_commitment']),
            'V' => $input['phone_number'],
            'W' => null,
        ], $overrides);
    }

    /** A workbook in the legacy layout: title rows, headers on row 5, data from row 6, text cells kept as text. */
    private function file(array $rows, string $filename): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('بيانات المرشحين');
        $sheet->setRightToLeft(true);
        $sheet->setCellValue('B1', 'قسم التوظيف');
        $sheet->setCellValue('B3', 'قاعدة بيانات المرشحين');

        foreach (self::HEADERS as $letter => $text) {
            $sheet->setCellValue("{$letter}5", $text);
        }

        foreach (array_values($rows) as $offset => $row) {
            $line = 6 + $offset;

            foreach ($row as $letter => $value) {
                $cell = "{$letter}{$line}";

                match (true) {
                    $value === null => null,
                    $value instanceof DateTimeInterface => $sheet->setCellValue($cell, ExcelDate::PHPToExcel($value))
                        ->getStyle($cell)->getNumberFormat()->setFormatCode('dd/mm/yyyy'),
                    is_string($value) => $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_STRING),
                    default => $sheet->setCellValue($cell, $value),
                };
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'demo-import-') . '.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $this->files[] = $path;

        return new UploadedFile($path, $filename, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }
}
