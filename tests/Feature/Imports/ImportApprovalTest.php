<?php

namespace Tests\Feature\Imports;

use App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Imports\Models\ImportRow;
use App\Modules\Recruitment\Actions\CreateApplication;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\Candidate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\LegacyWorkbook;
use Tests\Support\RecruitmentTestHelpers;
use Tests\Support\StaffTestHelpers;
use Tests\TestCase;

/** Approving a batch: rows become people, applications and nomination numbers (§09 steps 6-7). */
class ImportApprovalTest extends TestCase
{
    use RefreshDatabase, StaffTestHelpers, RecruitmentTestHelpers;

    private const URL = '/api/v1/staff/imports';

    private StaffUser $staff;

    /** Holds imports.approve; never the uploader (D-047). */
    private StaffUser $approver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
        $this->referenceIds();
        $this->staff = $this->hiringStaff();
        $this->approver = $this->allow($this->hiringStaff(), 'imports.approve');
    }

    private function uploadRows(array $rows, string $filename = 'candidates.xlsx'): string
    {
        return $this->asStaff($this->staff)
            ->post(self::URL, ['file' => LegacyWorkbook::file($rows, [], $filename)], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('data.id');
    }

    private function rows(string $batch): array
    {
        return $this->asStaff($this->staff)->getJson(self::URL . "/{$batch}/rows")->json('data');
    }

    private function approve(string $batch)
    {
        return $this->asStaff($this->approver)->postJson(self::URL . "/{$batch}/approve");
    }

    /** Three valid people, one invalid row, one excluded row. */
    private function mixedBatch(): string
    {
        $batch = $this->uploadRows([
            LegacyWorkbook::row(['F' => 'أحمد محمد علي']),
            LegacyWorkbook::row(['J' => '11111111111', 'F' => 'سارة خالد عمر']),
            LegacyWorkbook::row(['J' => 'abc', 'F' => 'رقم خاطئ']),
            LegacyWorkbook::row(['J' => '22222222222', 'F' => 'محمود سعيد حسن']),
            LegacyWorkbook::row(['J' => '33333333333', 'F' => 'ليلى أحمد ناصر']),
        ]);

        $excluded = $this->rows($batch)[4]['id'];
        $this->asStaff($this->staff)->postJson(self::URL . "/{$batch}/rows/{$excluded}/exclude", ['reason' => 'انسحبت'])->assertOk();

        return $batch;
    }

    public function test_approving_imports_every_valid_row_and_only_those(): void
    {
        $batch = $this->mixedBatch();

        $this->approve($batch)
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.approved_by.id', $this->approver->id)
            ->assertJsonPath('data.counts.imported', 3)
            ->assertJsonPath('data.counts.invalid', 1)
            ->assertJsonPath('data.counts.excluded', 1);

        $this->assertDatabaseCount('applications', 3);
        $this->assertDatabaseCount('candidates', 3);

        $rows = $this->rows($batch);
        $this->assertSame(['imported', 'imported', 'invalid', 'imported', 'excluded'], array_column($rows, 'status'));

        $application = Application::with('candidate')->findOrFail($rows[1]['application']['id']);
        $this->assertSame($rows[1]['application']['nomination_number'], $application->nomination_number);
        $this->assertSame('سارة خالد عمر', $application->candidate->full_name);
        $this->assertSame('awaiting_exam', $application->current_status->value);
        $this->assertSame($this->approver->id, $application->created_by);
        $this->assertSame(1, $application->statusEvents()->count());
    }

    public function test_imported_rows_get_consecutive_numbers_in_sheet_order(): void
    {
        $batch = $this->mixedBatch();
        $this->approve($batch)->assertOk();

        $numbers = collect($this->rows($batch))->pluck('application.nomination_number')->filter()->map(fn($n) => (int) $n)->values()->all();

        $this->assertSame([$numbers[0], $numbers[0] + 1, $numbers[0] + 2], $numbers);
    }

    public function test_approving_needs_the_individual_approval_permission(): void
    {
        $batch = $this->uploadRows([LegacyWorkbook::row()]);

        $this->asStaff($this->hiringStaff())->postJson(self::URL . "/{$batch}/approve")->assertForbidden();
        $this->assertDatabaseCount('applications', 0);
    }

    public function test_the_uploader_cannot_approve_their_own_batch(): void
    {
        $this->allow($this->staff, 'imports.approve');
        $batch = $this->uploadRows([LegacyWorkbook::row()]);

        $this->asStaff($this->staff)->postJson(self::URL . "/{$batch}/approve")
            ->assertForbidden()
            ->assertJsonPath('message', __('imports.own_batch'));
        $this->assertDatabaseCount('applications', 0);

        $this->approve($batch)->assertOk();   // someone else may
    }

    public function test_an_approver_without_imports_manage_can_read_but_not_change_the_batch(): void
    {
        $approver = $this->allow(\App\Modules\Identity\Database\Factories\StaffUserFactory::new()->create(), 'imports.approve');
        $batch = $this->uploadRows([LegacyWorkbook::row()]);
        $row = $this->rows($batch)[0]['id'];

        $this->asStaff($approver)->getJson(self::URL . "/{$batch}")->assertOk();
        $this->asStaff($approver)->getJson(self::URL . "/{$batch}/rows")->assertOk();
        $this->asStaff($approver)->get(self::URL . "/{$batch}/report")->assertOk();
        $this->asStaff($approver)->patchJson(self::URL . "/{$batch}/rows/{$row}", ['values' => ['notes' => 'x']])->assertForbidden();
        $this->asStaff($approver)->postJson(self::URL . "/{$batch}/cancel", ['reason' => 'لا'])->assertForbidden();

        $this->asStaff($approver)->postJson(self::URL . "/{$batch}/approve")->assertOk();
    }

    public function test_the_application_carries_every_value_of_the_row(): void
    {
        $batch = $this->uploadRows([LegacyWorkbook::row(['N' => 'المزة، شارع الجلاء', 'W' => 'ملاحظة الجهة', 'E' => '٧٧١'])]);
        $this->approve($batch)->assertOk();

        $application = Application::with('candidate')->sole();
        $ids = $this->referenceIds();

        $this->assertSame($ids['job_position_id'], $application->job_position_id);
        $this->assertSame($ids['residence_area_id'], $application->residence_area_id);
        $this->assertSame('شارع الجلاء', $application->residence_details);
        $this->assertSame('ملاحظة الجهة', $application->notes);
        $this->assertSame('771', $application->existing_employee_number);
        $this->assertSame('0912345678', $application->phone_number);
        $this->assertSame(12, $application->studying_years);
        $this->assertFalse($application->works_for_other_government_entity);
        $this->assertTrue($application->overnight_work);
        $this->assertSame('01234567890', $application->candidate->national_number);
        $this->assertSame('1998-03-01', $application->candidate->birth_date->toDateString());
    }

    public function test_rows_needing_review_block_the_approval(): void
    {
        $this->closeApplication($this->openApplication());
        $batch = $this->uploadRows([LegacyWorkbook::row(['F' => 'محمود محمد علي']), LegacyWorkbook::row(['J' => '11111111111'])]);

        $this->approve($batch)->assertStatus(422)->assertJsonPath('errors.batch.0', __('imports.unresolved_reviews', ['count' => 1]));
        $this->assertDatabaseCount('applications', 1);   // only the closed one from before

        $review = $this->rows($batch)[0]['id'];
        $this->asStaff($this->staff)->postJson(self::URL . "/{$batch}/rows/{$review}/confirm-person")->assertOk();

        $this->approve($batch)->assertOk()->assertJsonPath('data.counts.imported', 2);
    }

    public function test_a_confirmed_person_keeps_the_data_on_file(): void
    {
        $this->closeApplication($this->openApplication());
        $batch = $this->uploadRows([LegacyWorkbook::row(['F' => 'محمود محمد علي'])]);
        $this->asStaff($this->staff)->postJson(self::URL . "/{$batch}/rows/{$this->rows($batch)[0]['id']}/confirm-person")->assertOk();

        $this->approve($batch)->assertOk();

        $this->assertDatabaseCount('candidates', 1);
        $this->assertSame('أحمد محمد علي', Candidate::sole()->full_name);
        $this->assertSame(2, Candidate::sole()->applications()->count());
    }

    /** AC-19: re-uploading the same people in another order creates no second application and changes no number. */
    public function test_re_uploading_the_rows_in_another_order_imports_nothing_twice(): void
    {
        $rows = [
            LegacyWorkbook::row(),
            LegacyWorkbook::row(['J' => '11111111111', 'F' => 'سارة خالد عمر']),
        ];
        $first = $this->uploadRows($rows, 'first.xlsx');
        $this->approve($first)->assertOk();
        $numbers = Application::query()->orderBy('nomination_number')->pluck('nomination_number', 'candidate_id')->all();

        $again = $this->uploadRows(array_reverse($rows), 'reordered.xlsx');

        $this->asStaff($this->staff)->getJson(self::URL . "/{$again}")->assertJsonPath('data.counts.invalid', 2);
        $this->assertSame(['live_application'], array_column($this->rows($again)[0]['issues'], 'code'));

        $this->approve($again)->assertOk()->assertJsonPath('data.counts.imported', 0);
        $this->assertSame($numbers, Application::query()->orderBy('nomination_number')->pluck('nomination_number', 'candidate_id')->all());
    }

    public function test_if_any_row_still_fails_nothing_is_imported(): void
    {
        $batch = $this->uploadRows([LegacyWorkbook::row(), LegacyWorkbook::row(['J' => '11111111111', 'F' => 'سارة خالد عمر'])]);

        // Under the person locks this cannot happen through the API; simulate it to prove approval is all or nothing.
        $real = app(CreateApplication::class);
        $calls = 0;
        $this->mock(CreateApplication::class, function ($mock) use ($real, &$calls) {
            $mock->shouldReceive('createLocked')->andReturnUsing(function (...$arguments) use ($real, &$calls) {
                if (++$calls === 2) {
                    throw ValidationException::withMessages(['candidate.national_number' => ['simulated refusal']]);
                }

                return $real->createLocked(...$arguments);
            });
        });

        $response = $this->approve($batch)->assertStatus(422);

        $this->assertStringContainsString('simulated refusal', $response->json('errors.batch.0'));
        $this->assertStringContainsString((string) $this->rows($batch)[1]['row_number'], $response->json('errors.batch.0'));
        $this->assertDatabaseCount('applications', 0);
        $this->assertDatabaseCount('nomination_counters', 0);   // the reserved numbers were given back
        $this->assertSame(['valid', 'valid'], array_column($this->rows($batch), 'status'));
        $this->asStaff($this->staff)->getJson(self::URL . "/{$batch}")->assertJsonPath('data.status', 'pending');
    }

    public function test_approval_reserves_the_numbers_in_one_step_without_gaps(): void
    {
        $this->openApplication(null, ['candidate' => ['national_number' => '99999999999']]);   // takes number 1
        $batch = $this->mixedBatch();

        $this->approve($batch)->assertOk();

        $sequences = Application::query()->orderBy('nomination_number')->pluck('nomination_number')
            ->map(fn(string $number) => (int) substr($number, -6))->all();
        $this->assertSame([1, 2, 3, 4], $sequences);
    }

    public function test_a_row_that_stopped_being_valid_blocks_the_approval(): void
    {
        $batch = $this->uploadRows([LegacyWorkbook::row(), LegacyWorkbook::row(['J' => '11111111111', 'F' => 'سارة خالد عمر'])]);

        // Meanwhile someone opens an application for the first person through the API.
        $this->openApplication();

        $response = $this->approve($batch)->assertStatus(422);
        $this->assertSame(__('imports.rows_changed', ['rows' => $this->rows($batch)[0]['row_number']]), $response->json('errors.batch.0'));
        $this->assertDatabaseCount('applications', 1);   // only the API one

        // Staff look again: the row now says why; approving imports the other row.
        $this->asStaff($this->staff)->postJson(self::URL . "/{$batch}/revalidate")->assertJsonPath('data.counts.invalid', 1);
        $this->approve($batch)->assertOk()->assertJsonPath('data.counts.imported', 1);
    }

    public function test_an_approved_batch_cannot_be_approved_cancelled_or_changed_again(): void
    {
        $batch = $this->uploadRows([LegacyWorkbook::row(), LegacyWorkbook::row(['J' => 'abc'])]);
        $this->approve($batch)->assertOk();
        $invalid = $this->rows($batch)[1]['id'];

        $this->approve($batch)->assertStatus(422)->assertJsonValidationErrors('batch');
        $this->asStaff($this->staff)->postJson(self::URL . "/{$batch}/cancel", ['reason' => 'متأخر'])->assertStatus(422);
        $this->asStaff($this->staff)->patchJson(self::URL . "/{$batch}/rows/{$invalid}", ['values' => ['candidate.national_number' => '44444444444']])
            ->assertStatus(422);
        $this->assertDatabaseCount('applications', 1);
    }

    public function test_a_cancelled_batch_cannot_be_approved(): void
    {
        $batch = $this->uploadRows([LegacyWorkbook::row()]);
        $this->asStaff($this->staff)->postJson(self::URL . "/{$batch}/cancel", ['reason' => 'ملف خاطئ'])
            ->assertOk()->assertJsonPath('data.cancel_reason', 'ملف خاطئ')->assertJsonPath('data.cancelled_by.id', $this->staff->id);

        $this->approve($batch)->assertStatus(422);
        $this->assertDatabaseCount('applications', 0);
    }

    public function test_report_cells_are_text_so_formulas_never_run(): void
    {
        $batch = $this->uploadRows([LegacyWorkbook::row(['F' => ['text' => '=HYPERLINK("http://example.invalid","احمد")']])]);
        $this->approve($batch)->assertOk();

        $response = $this->asStaff($this->staff)->get(self::URL . "/{$batch}/report")->assertOk();
        $sheet = IOFactory::load($response->baseResponse->getFile()->getPathname())->getActiveSheet();

        $this->assertSame(\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING, $sheet->getCell('D2')->getDataType());
        $this->assertSame('=HYPERLINK("http://example.invalid","احمد")', $sheet->getCell('D2')->getValue());
    }

    public function test_cancelling_needs_a_reason(): void
    {
        $batch = $this->uploadRows([LegacyWorkbook::row()]);

        $this->asStaff($this->staff)->postJson(self::URL . "/{$batch}/cancel", [])->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    public function test_approval_is_audited(): void
    {
        $batch = $this->mixedBatch();
        Log::spy();

        $this->approve($batch)->assertOk();

        Log::shouldHaveReceived('notice')->withArgs(fn(string $event, array $context) => $event === 'import.approved'
            && $context['batch_id'] === $batch && $context['imported'] === 3)->once();
    }

    public function test_the_report_lists_every_row_with_its_outcome(): void
    {
        $batch = $this->mixedBatch();
        $this->approve($batch)->assertOk();

        $response = $this->asStaff($this->staff)->get(self::URL . "/{$batch}/report")->assertOk();

        $path = $response->baseResponse->getFile()->getPathname();
        $sheet = IOFactory::load($path)->getActiveSheet();

        $this->assertTrue($sheet->getRightToLeft());
        $this->assertSame(__('imports.report.row'), $sheet->getCell('A1')->getValue());
        $this->assertSame(6, $sheet->getHighestDataRow());              // header + 5 rows
        $this->assertSame(__('imports.statuses.imported'), $sheet->getCell('B3')->getValue());
        $this->assertMatchesRegularExpression('/^\d{10}$/', (string) $sheet->getCell('C3')->getValue());
        $this->assertSame('11111111111', $sheet->getCell('E3')->getValue());   // text, not a number
        $this->assertSame(__('imports.statuses.invalid'), $sheet->getCell('B4')->getValue());
        $this->assertStringContainsString('11', (string) $sheet->getCell('F4')->getValue());   // "11 رقماً"
        $this->assertSame('انسحبت', $sheet->getCell('F6')->getValue());
    }
}
