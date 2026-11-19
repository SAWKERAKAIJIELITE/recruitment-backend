<?php

namespace Tests\Feature\Contracts;

use App\Modules\Contracts\Models\ContractType;
use App\Modules\Contracts\Models\VacancyPlan;
use App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Recruitment\Actions\ApplicationLifecycle;
use App\Modules\Recruitment\Enums\ApplicationStatus as S;
use App\Modules\Recruitment\Enums\DecisionType as D;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\JobPosition;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\RecruitmentTestHelpers;
use Tests\Support\StaffTestHelpers;
use Tests\TestCase;

/** The vacancies file and the first contracts it allows (D-088). */
class VacancyPlanTest extends TestCase
{
    use RecruitmentTestHelpers, RefreshDatabase, StaffTestHelpers;

    private const URL = '/api/v1/staff/vacancy-plans';

    private StaffUser $admin;

    private StaffUser $contracts;

    private ContractType $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
        $this->travelTo('2026-11-08 10:00:00');
        $this->admin = $this->staffOf('system_admin');
        $this->contracts = $this->contractsStaff();
        $this->type = ContractType::create(['name_ar' => 'عقد سنوي']);
        $this->referenceIds();   // the job position مدرس
        JobPosition::create(['name_ar' => 'سائق']);
    }

    /** @param list<array{0: mixed, 1: mixed}> $rows */
    private function file(array $rows, array $header = ['المسمى الوظيفي', 'عدد الشواغر'], string $name = 'الشواغر.xlsx'): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', 'وزارة الأوقاف');   // a title above the header row
        $sheet->fromArray($header, null, 'A3');

        foreach ($rows as $index => [$position, $count]) {
            $line = 4 + $index;
            $sheet->setCellValue("A{$line}", $position);
            is_string($count) ? $sheet->setCellValueExplicit("B{$line}", $count, str_starts_with($count, '=') ? DataType::TYPE_FORMULA : DataType::TYPE_STRING) : $sheet->setCellValue("B{$line}", $count);
        }

        $path = tempnam(sys_get_temp_dir(), 'vacancies-test-') . '.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function upload(array $rows, array $body = [], ?StaffUser $as = null): TestResponse
    {
        return $this->asStaff($as ?? $this->admin)->post(self::URL, ['file' => $this->file($rows)] + $body, ['Accept' => 'application/json']);
    }

    /** An application of $position whose interview passed. */
    private function awaitingContract(string $number, ?int $position = null): Application
    {
        $application = $this->openApplication(null, ['candidate' => ['national_number' => $number]] + ($position === null ? [] : ['job_position_id' => $position]));
        app(ApplicationLifecycle::class)->move($application, S::ExamTaken, D::Outcome);
        app(ApplicationLifecycle::class)->move($application, S::AwaitingInterview, D::Outcome);
        app(ApplicationLifecycle::class)->move($application, S::AwaitingContract, D::Outcome);

        return $application;
    }

    private function write(Application $application, string $writtenOn = '2026-11-08'): TestResponse
    {
        return $this->asStaff($this->contracts)->postJson("/api/v1/staff/applications/{$application->id}/contracts", [
            'contract_type_id' => $this->type->id, 'reference_number' => 'ع/' . Str::random(6), 'written_on' => $writtenOn, 'starts_on' => $writtenOn, 'ends_on' => '2027-11-07',
        ]);
    }

    private function teacher(): int
    {
        return $this->referenceIds()['job_position_id'];
    }

    // ---- the file ----

    public function test_the_administrator_uploads_three_months_of_vacancies(): void
    {
        $response = $this->upload([['مدرس', 2], ['سائق', '٠']], ['starts_on' => '2026-11-01', 'notes' => 'الربع الأخير'])
            ->assertCreated()
            ->assertJsonPath('data.starts_on', '2026-11-01')
            ->assertJsonPath('data.ends_on', '2027-01-31')
            ->assertJsonPath('data.state', 'current')
            ->assertJsonPath('data.original_filename', 'الشواغر.xlsx')
            ->assertJsonPath('data.uploaded_by.id', $this->admin->id)
            ->assertJsonPath('data.totals', ['vacancies' => 2, 'used' => 0, 'left' => 2]);

        $this->assertSame([['مدرس', 2, 0, 2], ['سائق', 0, 0, 0]], array_map(fn (array $item) => [$item['job_position']['name_ar'], $item['vacancies'], $item['used'], $item['left']], $response->json('data.items')));
        Storage::disk('local')->assertExists(VacancyPlan::query()->sole()->stored_path);
        $this->assertSame(1, DB::table('audit_events')->where('event', 'vacancy_plan.uploaded')->count());

        // Without starts_on: from today.
        $this->upload([['مدرس', 1]], ['starts_on' => '2027-02-01'])->assertCreated()->assertJsonPath('data.ends_on', '2027-04-30')->assertJsonPath('data.state', 'upcoming');
        $this->travelTo('2027-05-01 10:00:00');
        $this->upload([['مدرس', 1]])->assertCreated()->assertJsonPath('data.starts_on', '2027-05-01')->assertJsonPath('data.ends_on', '2027-07-31');
    }

    public function test_who_reads_and_who_uploads(): void
    {
        $plan = $this->upload([['مدرس', 2]])->assertCreated()->json('data.id');

        foreach (['contracts', 'general_manager'] as $role) {
            $user = $this->staffOf($role);
            $this->asStaff($user)->getJson(self::URL)->assertOk()->assertJsonCount(1, 'data');
            $this->asStaff($user)->getJson(self::URL . '/current')->assertOk()->assertJsonPath('data.id', $plan);
            $this->asStaff($user)->getJson(self::URL . "/{$plan}")->assertOk();
            $this->asStaff($user)->get(self::URL . "/{$plan}/original")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
            $this->upload([['مدرس', 2]], ['starts_on' => '2027-02-01'], $user)->assertForbidden();
        }

        foreach (['recruitment', 'exams_interviews', 'documenting'] as $office) {
            $this->asStaff($this->staffOf($office))->getJson(self::URL)->assertForbidden();
        }

        $this->travelTo('2027-03-01 10:00:00');
        $this->asStaff($this->contracts)->getJson(self::URL . '/current')->assertOk()->assertJsonPath('data', null);
    }

    public function test_every_problem_of_the_file_is_reported_with_its_row(): void
    {
        JobPosition::create(['name_ar' => 'محاسب'])->delete();

        $response = $this->upload([
            ['مدرّس', 1],            // another spelling of مدرس: fine
            ['طيار', 2],
            ['مدرس', 3],
            ['محاسب', 1],
            ['سائق', 'كثير'],
            ['سائق', -1],
            ['', 4],
            ['المجموع', '=SUM(B4:B9)'],   // a total row is skipped
        ])->assertStatus(422);

        $this->assertSame([
            __('contracts.vacancy_file.unknown_position', ['row' => 5, 'name' => 'طيار']),
            __('contracts.vacancy_file.duplicate_position', ['row' => 6, 'name' => 'مدرس', 'first' => 4]),
            __('contracts.vacancy_file.archived_position', ['row' => 7, 'name' => 'محاسب']),
            __('contracts.vacancy_file.bad_count', ['row' => 8, 'value' => 'كثير']),
            __('contracts.vacancy_file.duplicate_position', ['row' => 9, 'name' => 'سائق', 'first' => 8]),
            __('contracts.vacancy_file.bad_count', ['row' => 9, 'value' => '-1']),
            __('contracts.vacancy_file.no_position', ['row' => 10]),
        ], $response->json('errors.file'));
        $this->assertSame(0, VacancyPlan::query()->count());
        Storage::disk('local')->assertDirectoryEmpty('vacancies');

        $this->upload([['سائق', '=1+1']])->assertStatus(422)->assertJsonPath('errors.file.0', __('contracts.vacancy_file.formula', ['row' => 4]));
        $this->upload([])->assertStatus(422)->assertJsonPath('errors.file.0', __('contracts.vacancy_file.empty'));
        $this->asStaff($this->admin)->post(self::URL, ['file' => $this->file([['مدرس', 1]], ['الاسم', 'العدد'])], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('errors.file.0', __('contracts.vacancy_file.no_header'));
        $this->asStaff($this->admin)->post(self::URL, ['file' => UploadedFile::fake()->createWithContent('x.xlsx', 'not a workbook')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('file');

        // The usual variants of the headers.
        $this->asStaff($this->admin)->post(self::URL, ['file' => $this->file([['مدرس', 1]], ['مسمى العمل', 'الشواغر'])], ['Accept' => 'application/json'])->assertCreated();
    }

    public function test_periods_never_overlap_and_a_plan_is_corrected_by_replacing_it(): void
    {
        $first = $this->upload([['مدرس', 1]], ['starts_on' => '2026-11-01'])->assertCreated()->json('data.id');

        $this->upload([['مدرس', 1]], ['starts_on' => '2027-01-31'])->assertStatus(422)
            ->assertJsonPath('errors.starts_on.0', __('contracts.vacancy_plan_overlaps', ['from' => '2026-11-01', 'to' => '2027-01-31']));
        $this->upload([['مدرس', 1]], ['starts_on' => '2027-02-01'])->assertCreated();

        $replace = fn (string $plan, array $rows, array $body = []) => $this->asStaff($this->admin)->post(self::URL . "/{$plan}/replace", ['file' => $this->file($rows)] + $body, ['Accept' => 'application/json']);
        $this->assertSame(1, $this->write($this->awaitingContract('01111111111'))->assertCreated()->json('data.sequence'));

        // The corrected numbers apply at once; the contract written counts against them.
        $second = $replace($first, [['مدرس', 3], ['سائق', 1]])->assertCreated()
            ->assertJsonPath('data.starts_on', '2026-11-01')
            ->assertJsonPath('data.replaces_plan_id', $first)
            ->assertJsonPath('data.items.0.used', 1)
            ->assertJsonPath('data.items.0.left', 2)
            ->json('data.id');
        $this->asStaff($this->contracts)->getJson(self::URL . "/{$first}")->assertJsonPath('data.state', 'replaced')->assertJsonPath('data.replaced_by_plan_id', $second);
        $this->asStaff($this->contracts)->getJson(self::URL . '/current')->assertJsonPath('data.id', $second);

        $replace($first, [['مدرس', 5]])->assertStatus(422)->assertJsonValidationErrors('plan');                       // replaced already
        $replace($second, [['مدرس', 5]], ['starts_on' => '2026-12-01'])->assertStatus(422)->assertJsonValidationErrors('starts_on');   // the period stays
        $this->travelTo('2027-05-01 10:00:00');
        $replace($second, [['مدرس', 5]])->assertStatus(422)->assertJsonValidationErrors('plan');                      // its period ended
    }

    // ---- the first contracts it allows ----

    public function test_a_first_contract_needs_a_vacancy_of_its_job_position(): void
    {
        $this->write($this->awaitingContract('01111111111'))->assertStatus(422)
            ->assertJsonPath('errors.vacancies.0', __('contracts.no_vacancy_plan', ['date' => '2026-11-08']));

        $this->upload([['مدرس', 1]], ['starts_on' => '2026-11-01'])->assertCreated();
        $this->write($this->awaitingContract('02222222222'), '2026-10-31')->assertStatus(422)->assertJsonValidationErrors('vacancies');   // before the period

        $first = $this->awaitingContract('03333333333');
        $contract = $this->write($first)->assertCreated()->json('data.id');
        $this->write($this->awaitingContract('04444444444'))->assertStatus(422)
            ->assertJsonPath('errors.vacancies.0', __('contracts.no_vacancy', ['position' => 'مدرس', 'from' => '2026-11-01', 'to' => '2027-01-31', 'used' => 1, 'allowed' => 1]));

        // A job position missing from the file has no vacancy.
        $driver = $this->awaitingContract('05555555555', JobPosition::query()->where('name_ar', 'سائق')->value('id'));
        $this->write($driver)->assertStatus(422)->assertJsonPath('errors.vacancies.0', __('contracts.no_vacancy', ['position' => 'سائق', 'from' => '2026-11-01', 'to' => '2027-01-31', 'used' => 0, 'allowed' => 0]));

        // Renewals use none.
        $this->asStaff($this->contracts)->postJson("/api/v1/staff/contracts/{$contract}/renew", ['contract_type_id' => $this->type->id, 'reference_number' => 'ت/1', 'written_on' => '2026-11-08', 'starts_on' => '2027-11-07', 'ends_on' => '2028-11-06'])->assertCreated();
        $this->asStaff($this->contracts)->getJson(self::URL . '/current')->assertJsonPath('data.totals.used', 1);

        // Its job position no longer changes: the contract used its vacancy.
        $this->asStaff($this->hiringStaff())->patchJson("/api/v1/staff/applications/{$first->id}", ['job_position_id' => $driver->job_position_id])
            ->assertStatus(422)->assertJsonPath('errors.job_position_id.0', __('recruitment.position_after_contract'));
    }

    public function test_a_corrected_writing_day_needs_a_vacancy_on_that_day(): void
    {
        $this->upload([['مدرس', 1]], ['starts_on' => '2026-08-01'])->assertCreated();   // to 2026-10-31
        $this->upload([['مدرس', 1]], ['starts_on' => '2026-11-01'])->assertCreated();
        $this->travelTo('2026-10-20 10:00:00');
        $early = $this->write($this->awaitingContract('01111111111'), '2026-10-20')->assertCreated()->json('data.id');
        $this->travelTo('2026-11-08 10:00:00');
        $this->write($this->awaitingContract('02222222222'))->assertCreated();

        // Moving the October contract into November would take a second November vacancy.
        $this->asStaff($this->contracts)->patchJson("/api/v1/staff/contracts/{$early}", ['written_on' => '2026-11-02', 'reason' => 'خطأ في التاريخ'])
            ->assertStatus(422)->assertJsonValidationErrors('vacancies');
        $this->asStaff($this->contracts)->patchJson("/api/v1/staff/contracts/{$early}", ['written_on' => '2026-10-25', 'reason' => 'خطأ في التاريخ'])->assertOk();
    }

    public function test_the_database_keeps_the_vacancies(): void
    {
        $plan = $this->upload([['مدرس', 1]], ['starts_on' => '2026-11-01'])->assertCreated()->json('data.id');
        $this->write($this->awaitingContract('01111111111'))->assertCreated();
        $other = $this->awaitingContract('02222222222');
        $refused = function (callable $write, string $message) {
            try {
                DB::transaction($write);
                $this->fail('The database accepted the write.');
            } catch (QueryException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        };

        $refused(fn () => DB::table('contracts')->insert([
            'id' => (string) Str::uuid7(), 'application_id' => $other->id, 'sequence' => 1, 'contract_type_id' => $this->type->id, 'reference_number' => 'DB-1',
            'written_on' => '2026-11-08', 'starts_on' => '2026-11-08', 'created_by' => $this->contracts->id, 'created_at' => now(), 'updated_at' => now(),
        ]), 'contract_no_vacancy');
        $refused(fn () => DB::table('vacancy_plan_items')->where('plan_id', $plan)->update(['vacancies' => 5]), 'never change');
        $refused(fn () => DB::table('vacancy_plans')->where('id', $plan)->delete(), 'never deleted');
        $refused(fn () => DB::table('vacancy_plans')->where('id', $plan)->update(['ends_on' => '2027-12-31']), 'only gets replaced');
        $refused(fn () => DB::table('vacancy_plans')->insert([
            'id' => (string) Str::uuid7(), 'starts_on' => '2026-12-01', 'ends_on' => '2027-02-28', 'original_filename' => 'x.xlsx', 'stored_path' => 'x',
            'file_sha256' => str_repeat('0', 64), 'file_size' => 1, 'uploaded_by' => $this->admin->id, 'created_at' => now(),
        ]), 'vacancy_plans_no_overlap');
        $refused(fn () => DB::table('applications')->where('id', Application::query()->where('current_status', 'contract_written')->value('id'))->update(['job_position_id' => JobPosition::query()->where('name_ar', 'سائق')->value('id')]), 'application_position_after_contract');
    }
}
