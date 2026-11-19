<?php

namespace Tests\Feature\Contracts;

use App\Modules\Contracts\Exports\ContractForm;
use App\Modules\Contracts\Models\ContractType;
use App\Modules\Contracts\Models\Unit;
use App\Modules\Identity\Database\Factories\StaffUserFactory;
use App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Recruitment\Actions\ApplicationLifecycle;
use App\Modules\Recruitment\Enums\ApplicationStatus as S;
use App\Modules\Recruitment\Enums\DecisionType as D;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\District;
use App\Modules\Recruitment\Models\JobPosition;
use App\Modules\Recruitment\Models\MaritalStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\RecruitmentTestHelpers;
use Tests\Support\StaffTestHelpers;
use Tests\TestCase;

/** The contract form's details, the filled form (تعاقد عمل), ملف التثبيت, the contracts report and list filters (D-098). */
class ContractFormTest extends TestCase
{
    use RecruitmentTestHelpers, RefreshDatabase, StaffTestHelpers;

    private StaffUser $staff;

    private ContractType $type;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->travelTo('2026-10-31 10:00:00');
        $this->staff = $this->contractsStaff();
        $this->type = ContractType::create(['name_ar' => 'عقد سنوي']);
        $this->unit = Unit::create(['name_ar' => 'هيئة التوجيه المعنوي']);
        $this->referenceIds();
        $this->openVacancies();
    }

    private function awaitingContract(string $number = '01234567890', array $overrides = []): Application
    {
        $this->travelTo('2026-10-01 09:00:00');
        $application = $this->openApplication(null, array_replace_recursive(['candidate' => ['national_number' => $number]], $overrides));
        app(ApplicationLifecycle::class)->move($application, S::ExamTaken, D::Outcome);
        app(ApplicationLifecycle::class)->move($application, S::AwaitingInterview, D::Outcome);
        app(ApplicationLifecycle::class)->move($application, S::AwaitingContract, D::Outcome);
        $this->travelTo('2026-10-31 10:00:00');

        return $application;
    }

    private function form(): array
    {
        return [
            'unit_id' => $this->unit->id,
            'level_3' => ' فرع الإعلام ',
            'level_4' => 'قسم الإنتاج',
            'workplace' => 'دمشق',
            'work_pattern' => 'مدني متعاقد',
            'job_specialty' => 'توجيه',
            'work_schedule' => 'دوام كامل',
            'salary_usd' => 350.5,
            'certificate_type' => 'bachelor_4',
            'university' => 'جامعة دمشق',
            'gpa' => 72.25,
            'certificate_date' => '2020-07-15',
            'wives_count' => 1,
            'children_count' => 2,
        ];
    }

    private function write(Application $application, array $overrides = []): TestResponse
    {
        return $this->asStaff($this->staff)->postJson("/api/v1/staff/applications/{$application->id}/contracts", $overrides + [
            'contract_type_id' => $this->type->id,
            'reference_number' => 'ع/2026/' . Str::random(4),
            'written_on' => '2026-10-20',
            'starts_on' => '2026-11-01',
            'ends_on' => '2027-10-31',
        ]);
    }

    public function test_the_form_details_are_written_shown_and_renewed(): void
    {
        $application = $this->awaitingContract();
        $contract = $this->write($application, $this->form())
            ->assertCreated()
            ->assertJsonPath('data.unit.name_ar', 'هيئة التوجيه المعنوي')
            ->assertJsonPath('data.level_3', 'فرع الإعلام')                     // trimmed
            ->assertJsonPath('data.level_5', null)
            ->assertJsonPath('data.salary_usd', 350.5)
            ->assertJsonPath('data.certificate_type', ['value' => 'bachelor_4', 'label' => 'إجازة جامعية 4 سنوات'])
            ->assertJsonPath('data.gpa', 72.25)
            ->assertJsonPath('data.certificate_date', '2020-07-15')
            ->assertJsonPath('data.wives_count', 1)
            ->assertJsonPath('data.origin', null)
            ->json('data.id');

        // A renewal starts with them; one sent replaces it, null clears it.
        $this->asStaff($this->staff)->postJson("/api/v1/staff/contracts/{$contract}/renew", ['contract_type_id' => $this->type->id, 'reference_number' => 'ت/1', 'ends_on' => '2028-10-31', 'salary_usd' => 400, 'level_4' => null])
            ->assertCreated()
            ->assertJsonPath('data.sequence', 2)
            ->assertJsonPath('data.unit.id', $this->unit->id)
            ->assertJsonPath('data.university', 'جامعة دمشق')
            ->assertJsonPath('data.salary_usd', 400)
            ->assertJsonPath('data.level_4', null)
            ->assertJsonPath('data.certificate_type.value', 'bachelor_4');

        // Corrected with a reason, audited before and after.
        $this->asStaff($this->staff)->patchJson("/api/v1/staff/contracts/{$contract}", ['children_count' => 3, 'certificate_type' => 'master', 'reason' => 'بيان عائلي جديد'])
            ->assertOk()->assertJsonPath('data.children_count', 3)->assertJsonPath('data.certificate_type.value', 'master');
        $changes = json_decode(DB::table('audit_events')->where('event', 'contract.corrected')->value('context'), true)['changes'];
        $this->assertEquals(['from' => 2, 'to' => 3], $changes['children_count']);
        $this->assertEquals(['from' => 'bachelor_4', 'to' => 'master'], $changes['certificate_type']);
    }

    public function test_the_form_details_are_checked_in_arabic(): void
    {
        $application = $this->awaitingContract();
        $archived = Unit::create(['name_ar' => 'الفرقة 50']);
        $archived->delete();

        $errors = $this->write($application, ['gpa' => 101, 'wives_count' => 5, 'certificate_type' => 'phd', 'unit_id' => $archived->id, 'salary_usd' => -1, 'certificate_date' => '2027-01-01'])
            ->assertStatus(422)->json('errors');

        $this->assertEqualsCanonicalizing(['gpa', 'wives_count', 'certificate_type', 'unit_id', 'salary_usd', 'certificate_date'], array_keys($errors));
        $this->assertStringContainsString('المعدل', $errors['gpa'][0]);
        $this->assertStringContainsString('عدد الزوجات', $errors['wives_count'][0]);
        $this->assertSame('القيمة المختارة في الجهة الملتحق بها غير موجودة أو مؤرشفة.', $errors['unit_id'][0]);
        $this->assertSame(0, DB::table('contracts')->count());
    }

    public function test_the_job_code_is_the_contracts_offices(): void
    {
        $position = JobPosition::query()->findOrFail($this->referenceIds()['job_position_id']);
        $url = "/api/v1/staff/job-positions/{$position->id}/job-code";

        $this->asStaff($this->hiringStaff())->putJson($url, ['job_code' => 'X'])->assertForbidden();
        $this->asStaff($this->staff)->putJson($url, [])->assertStatus(422)->assertJsonValidationErrors('job_code');
        $this->asStaff($this->staff)->putJson($url, ['job_code' => ' ٤٥١٢ '])->assertOk()->assertJsonPath('item.job_code', '4512');

        // Shown with the list and on the contracts of that position.
        $item = collect($this->asStaff($this->hiringStaff())->getJson('/api/v1/staff/reference/job-positions')->assertOk()->json('data'))->firstWhere('id', $position->id);
        $this->assertSame('4512', $item['job_code']);
        $this->write($this->awaitingContract())->assertCreated()->assertJsonPath('data.application.job_code', '4512');

        $this->asStaff($this->staff)->putJson($url, ['job_code' => null])->assertOk()->assertJsonPath('item.job_code', null);
        $this->assertSame(2, DB::table('audit_events')->where('event', 'contract.job_code_set')->count());
    }

    public function test_the_contract_form_is_the_template_filled(): void
    {
        DB::table('job_positions')->where('id', $this->referenceIds()['job_position_id'])->update(['job_code' => 'JC-7']);
        $application = $this->awaitingContract('01234567890', ['existing_employee_number' => '55123']);
        $contract = $this->write($application, ['reference_number' => 'ع/2026/77', 'written_on' => '2026-10-20'] + $this->form())->assertCreated()->json('data.id');

        $response = $this->asStaff($this->staff)->get("/api/v1/staff/contracts/{$contract}/form")->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString(rawurlencode('تعاقد عمل ع/2026/77.pdf'), $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertSame(1, DB::table('audit_events')->where('event', 'contract.form_printed')->count());

        // What it shows.
        $values = ContractForm::values(\App\Modules\Contracts\Models\Contract::query()->with(ContractForm::RELATIONS)->findOrFail($contract));
        $this->assertSame('55123', $values['employeeNumber']);
        $this->assertSame('ع/2026/77', $values['formNumber']);
        $this->assertSame((string) config('contracts.issuing_unit'), $values['issuer']);
        $rows = array_column([...$values['right'], ...$values['left']], 1, 0);
        $this->assertSame('هيئة التوجيه المعنوي', $rows['السوية التنظيمية الثانية']);
        $this->assertSame('JC-7', $rows['الرمز الوظيفي']);
        $this->assertSame('إجازة جامعية 4 سنوات', $rows['الشهادة العلمية']);
        $this->assertSame('إجازة جامعية', $rows['المرحلة الدراسية']);                 // the application's qualification
        $this->assertSame('2020', $rows['سنة التخرج']);
        $this->assertSame('350.50', $rows['قيمة الراتب بالدولار']);
        $this->assertSame('دمشق', $rows['البلد الأصلي']);                            // the place of birth: no origin entered
        $this->assertSame('دمشق - المزة - شارع الجلاء', $rows['السكن الحالي']);
        $this->assertSame('دمشق 1998/03/01', $rows['التولد']);
        $this->assertSame(['01/11/2026', '31/10/2027', 'سنة واحدة'], [$values['startsOn'], $values['endsOn'], $values['duration']]);
        $this->assertSame(['09/05/1448', '20/10/2026'], [$values['hijri'], $values['gregorian']]);

        // Only the contracts' readers print it.
        $this->asStaff($this->hiringStaff())->get("/api/v1/staff/contracts/{$contract}/form")->assertForbidden();
    }

    public function test_a_contracts_length_in_words(): void
    {
        $length = fn(string $from, ?string $to) => ContractForm::duration(Carbon::parse($from), $to === null ? null : Carbon::parse($to));

        $this->assertSame('سنة واحدة', $length('2026-01-01', '2026-12-31'));
        $this->assertSame('سنتان', $length('2026-01-01', '2027-12-31'));
        $this->assertSame('6 أشهر و15 يومًا', $length('2026-01-01', '2026-07-15'));
        $this->assertSame('شهران ويوم واحد', $length('2026-03-01', '2026-05-01'));
        $this->assertSame('يوم واحد', $length('2026-03-01', '2026-03-01'));
        $this->assertSame('غير محددة', $length('2026-03-01', null));
        $this->assertSame('01/06/1448', ContractForm::hijri(Carbon::parse('2026-11-11')));
    }

    public function test_the_confirmation_file_follows_the_owners_template(): void
    {
        DB::table('job_positions')->where('id', $this->referenceIds()['job_position_id'])->update(['job_code' => 'JC-7']);
        $married = MaritalStatus::create(['name_ar' => 'متزوج']);
        $first = $this->awaitingContract('01111111111', ['marital_status_id' => $married->id, 'existing_employee_number' => '9001']);
        $this->write($first, ['reference_number' => 'ع/1', 'written_on' => '2026-10-20'] + $this->form() + ['origin' => 'حمص'])->assertCreated();
        $second = $this->awaitingContract('02222222222', ['candidate' => ['full_name' => 'خالد سمير العلي', 'birth_date' => '1990-12-25']]);
        $this->write($second, ['reference_number' => 'ع/2', 'written_on' => '2026-10-25', 'ends_on' => null])->assertCreated();   // no form details

        $response = $this->asStaff($this->staff)->get('/api/v1/staff/contracts/confirmation-file')->assertOk();
        $this->assertStringContainsString(rawurlencode('ملف التثبيت'), $response->headers->get('Content-Disposition'));
        $sheet = IOFactory::load($response->getFile()->getPathname())->getActiveSheet();

        $this->assertSame('تثبيت تعاقد', $sheet->getTitle());
        $this->assertTrue($sheet->getRightToLeft());
        $this->assertSame('نموذج تثبيت متعاقد مدني', $sheet->getCell('P2')->getValue());
        $this->assertSame(['م', 'رقم الكتاب', 'رقم الاستمارة', 'الرقم الذاتي', 'الاسم الثلاثي'], array_slice($sheet->rangeToArray('A6:AG6')[0], 0, 5));
        $this->assertSame(['تاريخ منح الرقم', 'تاريخ مباشرة العمل ', 'ملاحظات فرع الخدمة'], array_slice($sheet->rangeToArray('A6:AG6')[0], 30, 3));
        $this->assertSame('C00000', $sheet->getStyle('AF6')->getFill()->getStartColor()->getRGB());

        $row = array_combine(range(0, 32), $sheet->rangeToArray('A7:AG7', null, false, false)[0]);
        $this->assertSame([1, null, 'ع/1', '9001', 'أحمد محمد علي', 'فاطمة حسن', '03/01/1998', 1, '01111111111'], array_slice($row, 0, 9));
        $this->assertSame(['هيئة التوجيه المعنوي', 'فرع الإعلام', 'قسم الإنتاج', null, null, 'مدني متعاقد', 'توجيه', 'مدرس', 'JC-7', 'جامعة دمشق', 'إجازة جامعية 4 سنوات', 'شريعة', 72.25], array_slice($row, 9, 13));
        $this->assertSame('2020-07-15', Carbon::instance(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($row[22]))->toDateString());
        $this->assertSame(['دمشق', 'دمشق - المزة - شارع الجلاء', 'حمص', 350.5], array_slice($row, 23, 4));
        $this->assertSame([null, null, null, null], array_slice($row, 29, 4));        // AD and شعبة الخدمة's three
        $this->assertSame('yyyy/mm/dd', $sheet->getStyle('AB7')->getNumberFormat()->getFormatCode());

        $other = $sheet->rangeToArray('A8:I8', null, false, false)[0];
        $this->assertSame([2, null, 'ع/2', null, 'خالد سمير العلي', 'فاطمة حسن', '12/25/1990', 0, '02222222222'], $other);
        $this->assertSame([null, null], $sheet->rangeToArray('J8:K8')[0]);
        $this->assertSame('دمشق', $sheet->getCell('Z8')->getValue());                // no origin: the place of birth
        $this->assertNull($sheet->getCell('AC8')->getValue());                       // no end date

        // The list's filters choose the rows; audited; the contracts' readers only.
        $sheet = IOFactory::load($this->asStaff($this->staff)->get('/api/v1/staff/contracts/confirmation-file?written_from=2026-10-21')->assertOk()->getFile()->getPathname())->getActiveSheet();
        $this->assertSame('ع/2', $sheet->getCell('C7')->getValue());
        $this->assertNull($sheet->getCell('C8')->getValue());
        $this->assertSame(2, DB::table('audit_events')->where('event', 'contract.confirmation_file')->count());
        $this->asStaff($this->hiringStaff())->get('/api/v1/staff/contracts/confirmation-file')->assertForbidden();
        $this->asStaff($this->staff)->getJson('/api/v1/staff/contracts/confirmation-file?written_to=nope')->assertStatus(422)->assertJsonValidationErrors('written_to');
    }

    public function test_contracts_are_filtered_like_the_other_lists(): void
    {
        $homs = District::create(['name_ar' => 'حمص']);
        $area = $this->area($homs, 'الوعر');
        $here = $this->write($this->awaitingContract('01111111111'), ['written_on' => '2026-10-10'] + $this->form())->assertCreated()->json('data.id');
        $there = $this->write($this->awaitingContract('02222222222', ['residence_district_id' => $homs->id, 'residence_area_id' => $area->id]), ['written_on' => '2026-10-25'])->assertCreated()->json('data.id');
        $ids = fn(string $query) => $this->asStaff($this->staff)->getJson("/api/v1/staff/contracts?{$query}")->assertOk()->json('data.*.id');

        $this->assertSame([$here], $ids('written_to=2026-10-10'));
        $this->assertSame([$there], $ids('written_from=2026-10-11&written_to=2026-10-31'));
        $this->assertSame([$there], $ids("residence_district_id={$homs->id}"));
        $this->assertSame([$there], $ids("residence_area_id={$area->id}"));
        $this->assertSame([$here], $ids("unit_id={$this->unit->id}"));
        $this->assertSame([], $ids('residence_district_id=0'));                      // "0" filters (D-091)
        $this->asStaff($this->staff)->getJson('/api/v1/staff/contracts?written_from=2026-10-20&written_to=2026-10-01')->assertStatus(422)->assertJsonValidationErrors('written_to');
    }

    public function test_the_contracts_report(): void
    {
        $first = $this->written('01111111111', '2026-10-03');
        $this->asStaff($this->staff)->postJson("/api/v1/staff/contracts/{$first}/renew", ['contract_type_id' => $this->type->id, 'reference_number' => 'ت/1', 'written_on' => '2026-10-15', 'ends_on' => '2028-10-31'])->assertCreated();
        $ended = $this->written('02222222222', '2026-10-12');
        $this->asStaff($this->staff)->postJson("/api/v1/staff/contracts/{$ended}/end", ['ended_on' => '2026-10-30', 'reason' => 'استقالة'])->assertOk();

        $sheet = IOFactory::load($this->asStaff($this->staff)->get('/api/v1/staff/reports/contracts?period_from=2026-10-10&period_to=2026-10-31')->assertOk()->getFile()->getPathname())->getActiveSheet();
        $rows = collect($sheet->toArray(null, false, false))->filter(fn(array $row) => in_array($row[7] ?? null, ['ع/2', 'ت/1'], true))->values();

        // Written in the period: the second's first contract and the first's renewal, in writing order.
        $this->assertSame(['ع/2', 'ت/1'], $rows->pluck(7)->all());
        $this->assertSame(['هيئة التوجيه المعنوي', 'عقد سنوي', 'ع/2', 'الأول', '2026-10-12', '2026-11-01', '2027-10-31', 'أُنهي دون تجديد', '2026-10-30', 'استقالة'], array_slice($rows[0], 5));
        $this->assertSame(['تجديد 1', '2026-10-15'], [$rows[1][8], $rows[1][9]]);

        // Its section's: the Contracts office and the general manager, not the others.
        $this->asStaff(StaffUserFactory::new()->withRole('general_manager')->create())->get('/api/v1/staff/reports/contracts?format=pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->asStaff($this->examsStaff())->get('/api/v1/staff/reports/contracts')->assertForbidden();
        $this->asStaff($this->hiringStaff())->get('/api/v1/staff/reports/contracts')->assertForbidden();
    }

    private function written(string $number, string $writtenOn): string
    {
        return $this->write($this->awaitingContract($number), ['reference_number' => $number === '01111111111' ? 'ع/1' : 'ع/2', 'written_on' => $writtenOn, 'starts_on' => '2026-11-01', 'ends_on' => '2027-10-31'] + $this->form())->assertCreated()->json('data.id');
    }
}
