<?php

namespace Tests\Feature\Imports;

use App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportRow;
use App\Modules\Recruitment\Models\HealthCondition;
use App\Modules\Recruitment\Models\JobPosition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\LegacyWorkbook;
use Tests\Support\RecruitmentTestHelpers;
use Tests\Support\StaffTestHelpers;
use Tests\TestCase;

/** Reading cells into values, checking rows, and what staff do with rows before approval (§09 steps 3-5). */
class ImportRowsTest extends TestCase
{
    use RefreshDatabase, StaffTestHelpers, RecruitmentTestHelpers;

    private const URL = '/api/v1/staff/imports';

    private StaffUser $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
        $this->referenceIds();
        $this->staff = $this->hiringStaff();
    }

    /** Uploads the rows and returns the batch id. */
    private function uploadRows(array $rows): string
    {
        return $this->asStaff($this->staff)
            ->post(self::URL, ['file' => LegacyWorkbook::file($rows)], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('data.id');
    }

    private function onlyRow(array $overrides = []): array
    {
        $id = $this->uploadRows([LegacyWorkbook::row($overrides)]);

        return $this->asStaff($this->staff)->getJson(self::URL . "/{$id}/rows")->assertOk()->json('data.0') + ['batch_id' => $id];
    }

    private function codes(array $row): array
    {
        return array_column($row['issues'], 'code');
    }

    private function rowUrl(array $row, string $suffix = ''): string
    {
        return self::URL . "/{$row['batch_id']}/rows/{$row['id']}{$suffix}";
    }

    // ---- reading cells ----

    public function test_a_valid_row_shows_its_values_and_the_cells_as_read(): void
    {
        $row = $this->onlyRow();

        $this->assertSame('valid', $row['status']);
        $this->assertSame([], $row['issues']);
        $this->assertSame('مدرس', $row['values']['job_position_id']['name_ar']);
        $this->assertSame('01234567890', $row['values']['candidate.national_number']);
        $this->assertSame('1998-03-01', $row['values']['candidate.birth_date']);
        $this->assertFalse($row['values']['works_for_other_government_entity']);
        $this->assertTrue($row['values']['full_commitment']);
        $this->assertSame(12, $row['values']['studying_years']);

        $cells = collect($row['cells'])->keyBy('column');
        $this->assertSame('الرقم الوطني', $cells['J']['header']);
        $this->assertSame('01/03/1998', $cells['I']['value']);       // as Excel displayed it
        $this->assertSame('EX-17', $cells['Z']['value']);            // unused columns are kept too
        $this->assertSame('1', $cells['Y']['value']);                // a formula: its saved result
        $this->assertSame('لوحة التحكم', $cells['A']['value']);     // navigation labels are kept but never read
    }

    public static function dates(): array
    {
        return [
            'excel serial (AC-17)' => [new \DateTimeImmutable('1990-01-31'), '1990-01-31'],
            'day/month/year' => ['31/01/1990', '1990-01-31'],
            'arabic-indic digits' => ['٣١/٠١/١٩٩٠', '1990-01-31'],
            'year-month-day' => ['1990-01-31', '1990-01-31'],
            'month first when the day cannot be a month' => ['01/31/1990', '1990-01-31'],
            'with a time part' => ['1990-01-31 00:00:00', '1990-01-31'],
        ];
    }

    #[DataProvider('dates')]
    public function test_birth_dates_in_the_usual_forms(mixed $cell, string $expected): void
    {
        $row = $this->onlyRow(['I' => $cell]);

        $this->assertSame($expected, $row['values']['candidate.birth_date']);
        $this->assertSame('valid', $row['status']);
    }

    public function test_an_impossible_date_is_refused_with_what_the_cell_said(): void
    {
        $row = $this->onlyRow(['I' => '31/02/1990']);

        $this->assertSame('invalid', $row['status']);
        $this->assertSame(['invalid_date'], $this->codes($row));
        $this->assertStringContainsString('31/02/1990', $row['issues'][0]['message']);
    }

    public function test_a_1904_workbook_date_is_converted_with_its_own_calendar(): void
    {
        // The same serial means different days in the 1900 and 1904 date systems; the reader uses the file's.
        $file = LegacyWorkbook::file([LegacyWorkbook::row(['I' => 32000])]);
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::load($file->getRealPath());
        $reader->setExcelCalendar(\PhpOffice\PhpSpreadsheet\Shared\Date::CALENDAR_MAC_1904);
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($reader))->save($file->getRealPath());

        $id = $this->asStaff($this->staff)->post(self::URL, ['file' => $file], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        $values = $this->asStaff($this->staff)->getJson(self::URL . "/{$id}/rows")->json('data.0.values');

        $this->assertSame('1991-08-12', $values['candidate.birth_date']);   // 1987-08-11 in the 1900 system
    }

    /** AC-16: a national number or phone kept as text keeps its leading zero. */
    public function test_a_national_number_stored_as_a_number_lost_its_zero_and_is_not_guessed(): void
    {
        $row = $this->onlyRow(['J' => 1234567890, 'V' => 912345678]);

        $this->assertSame('invalid', $row['status']);
        $this->assertEqualsCanonicalizing(['national_number_leading_zero', 'phone_leading_zero'], $this->codes($row));
        $this->assertStringContainsString('01234567890', collect($row['issues'])->firstWhere('code', 'national_number_leading_zero')['message']);
        $this->assertStringContainsString('0912345678', collect($row['issues'])->firstWhere('code', 'phone_leading_zero')['message']);
        $this->assertNull($row['values']['candidate.national_number']);
    }

    public function test_an_eleven_digit_number_cell_is_fine(): void
    {
        $this->assertSame('valid', $this->onlyRow(['J' => 98765432101])['status']);
    }

    public function test_digits_and_separators_are_normalised(): void
    {
        $row = $this->onlyRow(['J' => '٠١٢٣٤-٥٦٧٨٩٠', 'V' => '٠٩١٢ ٣٤٥ ٦٧٨', 'Q' => '١٢']);

        $this->assertSame('valid', $row['status']);
        $this->assertSame('01234567890', $row['values']['candidate.national_number']);
        $this->assertSame('0912345678', $row['values']['phone_number']);
        $this->assertSame(12, $row['values']['studying_years']);
    }

    public function test_letters_in_a_national_number_are_refused_not_dropped(): void
    {
        $this->assertSame(['national_number_format'], $this->codes($this->onlyRow(['J' => '0123456789a'])));
    }

    public static function answers(): array
    {
        return [
            'نعم' => ['نعم', true], 'لا' => ['لا', false], 'yes' => ['Yes', true], 'no' => ['NO', false],
            '1' => [1, true], '0' => [0, false], 'ملتزم' => ['ملتزم', true], 'غير ملتزم' => ['غير ملتزم', false],
            'ticked' => ['✓', true],
        ];
    }

    #[DataProvider('answers')]
    public function test_yes_no_answers(mixed $cell, bool $expected): void
    {
        $this->assertSame($expected, $this->onlyRow(['T' => $cell])['values']['overnight_work']);
    }

    public static function numbersThatAreNotAnswers(): array
    {
        return ['minus one as number' => [-1], 'minus one as text' => ['-1'], 'two' => [2], 'decimal' => ['1.5']];
    }

    #[DataProvider('numbersThatAreNotAnswers')]
    public function test_only_one_and_zero_are_numeric_answers(mixed $cell): void
    {
        $this->assertSame(['invalid_boolean'], $this->codes($this->onlyRow(['T' => $cell])));
    }

    public function test_a_huge_number_is_not_a_count(): void
    {
        $this->assertSame(['invalid_number'], $this->codes($this->onlyRow(['Q' => 1.8446744073709552E19])));
    }

    public function test_an_unclear_answer_is_refused(): void
    {
        $this->assertSame(['invalid_boolean'], $this->codes($this->onlyRow(['T' => 'ربما'])));
    }

    public function test_list_values_match_in_any_spelling(): void
    {
        $row = $this->onlyRow(['C' => 'مدرّس', 'H' => 'دمشق ', 'O' => 'اجازة جامعية']);

        $this->assertSame('valid', $row['status']);
    }

    public function test_an_unknown_list_value_is_named_in_the_problem(): void
    {
        $row = $this->onlyRow(['C' => 'مهندس']);

        $this->assertSame('invalid', $row['status']);
        $this->assertSame(['unknown_value'], $this->codes($row));
        $this->assertSame('job_position_id', $row['issues'][0]['field']);
        $this->assertStringContainsString('مهندس', $row['issues'][0]['message']);
    }

    public function test_an_archived_list_value_says_so(): void
    {
        JobPosition::create(['name_ar' => 'مؤذن'])->delete();

        $this->assertSame(['archived_value'], $this->codes($this->onlyRow(['C' => 'مؤذن'])));
    }

    public function test_health_condition_and_details_share_one_cell(): void
    {
        HealthCondition::create(['name_ar' => 'مريض مزمن']);

        $row = $this->onlyRow(['L' => 'مريض مزمن - سكري من النوع الثاني']);

        $this->assertSame('valid', $row['status']);
        $this->assertSame('مريض مزمن', $row['values']['health_condition_id']['name_ar']);
        $this->assertSame('سكري من النوع الثاني', $row['values']['health_details']);
    }

    public function test_the_residence_cell_gives_the_area_and_the_details(): void
    {
        $row = $this->onlyRow(['N' => 'المزة، شارع الجلاء، بناء 4']);

        $this->assertSame('valid', $row['status']);
        $this->assertSame('المزة', $row['values']['residence_area_id']['name_ar']);
        $this->assertSame('شارع الجلاء، بناء 4', $row['values']['residence_details']);
    }

    public function test_entries_whose_names_contain_punctuation_beat_shorter_ones(): void
    {
        $district = \App\Modules\Recruitment\Models\District::where('name_ar', 'دمشق')->firstOrFail();
        $this->area($district, 'المزة-فيلات غربية');
        HealthCondition::create(['name_ar' => 'مريض']);
        HealthCondition::create(['name_ar' => 'مريض (مزمن)']);

        $row = $this->onlyRow(['N' => 'المزة-فيلات غربية - شارع المدارس', 'L' => 'مريض (مزمن) - ضغط']);

        $this->assertSame('المزة-فيلات غربية', $row['values']['residence_area_id']['name_ar']);
        $this->assertSame('شارع المدارس', $row['values']['residence_details']);
        $this->assertSame('مريض (مزمن)', $row['values']['health_condition_id']['name_ar']);
        $this->assertSame('ضغط', $row['values']['health_details']);
    }

    public function test_a_place_that_is_not_an_area_is_kept_as_residence_details(): void
    {
        // The legacy column is free text (often a town); the area is optional (D-043).
        $this->area($this->district('حلب'), 'الشهباء');

        $row = $this->onlyRow(['N' => 'الشهباء']);   // an area of another district: not used for دمشق

        $this->assertSame('valid', $row['status']);
        $this->assertNull($row['values']['residence_area_id']);
        $this->assertSame('الشهباء', $row['values']['residence_details']);
    }

    public function test_an_unknown_district_is_the_only_problem_of_the_residence(): void
    {
        $row = $this->onlyRow(['M' => 'باريس', 'N' => 'حي ما']);

        $this->assertSame(['unknown_value'], $this->codes($row));
        $this->assertSame('حي ما', $row['values']['residence_details']);
    }

    public static function studyYears(): array
    {
        return [
            'number' => [3, 3, false],
            'text' => ['5', 5, false],
            'discontinued' => ['3 ومنقطع', 3, true],
            'discontinued, arabic digit' => ['٢ ومنقطع', 2, true],
            'discontinued without و' => ['4 منقطع', 4, true],
        ];
    }

    #[DataProvider('studyYears')]
    public function test_study_years_from_the_legacy_list(mixed $cell, int $years, bool $discontinued): void
    {
        $row = $this->onlyRow(['Q' => $cell]);

        $this->assertSame('valid', $row['status']);
        $this->assertSame($years, $row['values']['studying_years']);
        $this->assertSame($discontinued, $row['values']['studies_discontinued']);
    }

    public function test_discontinued_without_years_needs_a_correction(): void
    {
        $row = $this->onlyRow(['Q' => 3]);

        $this->asStaff($this->staff)->patchJson($this->rowUrl($row), ['values' => ['studies_discontinued' => true, 'studying_years' => null]])
            ->assertOk()
            ->assertJsonPath('data.status', 'invalid')
            ->assertJsonPath('data.issues.0.field', 'studying_years');
    }

    public function test_missing_required_values_are_listed(): void
    {
        $row = $this->onlyRow(['G' => null, 'K' => null, 'U' => null]);

        $this->assertSame(['missing', 'missing', 'missing'], $this->codes($row));
        $this->assertEqualsCanonicalizing(['candidate.mother_name', 'marital_status_id', 'full_commitment'], array_column($row['issues'], 'field'));
    }

    public function test_optional_columns_may_be_empty(): void
    {
        $this->assertSame('valid', $this->onlyRow(['E' => null, 'Q' => null, 'R' => null, 'W' => null])['status']);
    }

    public static function badValues(): array
    {
        return [
            'name with digits' => [['F' => 'أحمد 2'], 'name_has_digits'],
            'short name' => [['F' => 'اب'], 'name_length'],
            'too many quran parts' => [['R' => 31], 'number_out_of_range'],
            'too many study years' => [['Q' => 16], 'number_out_of_range'],
            'phone with letters' => [['V' => '09ab345678'], 'phone_format'],
            'national number too short' => [['J' => '0123456789'], 'national_number_format'],
            'born in the future' => [['I' => '01/01/2999'], 'date_out_of_range'],
            'born before 1950' => [['I' => '01/01/1940'], 'date_out_of_range'],
        ];
    }

    #[DataProvider('badValues')]
    public function test_the_same_rules_as_the_api(array $overrides, string $code): void
    {
        $this->assertSame([$code], $this->codes($this->onlyRow($overrides)));
    }

    // ---- duplicates and people on file ----

    public function test_a_national_number_repeated_in_the_file_is_a_duplicate(): void
    {
        $id = $this->uploadRows([LegacyWorkbook::row(), LegacyWorkbook::row(['F' => 'نسخة ثانية'])]);

        $rows = $this->asStaff($this->staff)->getJson(self::URL . "/{$id}/rows")->json('data');

        $this->assertSame('valid', $rows[0]['status']);
        $this->assertSame(['duplicate_in_file'], $this->codes($rows[1]));
        $this->assertStringContainsString((string) $rows[0]['row_number'], $rows[1]['issues'][0]['message']);
    }

    public function test_a_person_with_a_live_application_cannot_be_imported(): void
    {
        $live = $this->openApplication();

        $row = $this->onlyRow();

        $this->assertSame(['live_application'], $this->codes($row));
        $this->assertStringContainsString($live->nomination_number, $row['issues'][0]['message']);
        $this->assertSame($live->candidate_id, $row['person_on_file']['id']);
    }

    public function test_a_known_person_with_the_same_data_is_simply_valid(): void
    {
        $this->closeApplication($this->openApplication());

        $row = $this->onlyRow(['F' => 'احمد محمد علي']);   // spelling variant of the stored name

        $this->assertSame('valid', $row['status']);
        $this->assertNotNull($row['person_on_file']);
    }

    public function test_a_known_person_with_different_data_needs_review(): void
    {
        $this->closeApplication($this->openApplication());

        $row = $this->onlyRow(['F' => 'محمود محمد علي', 'I' => '01/01/1999']);

        $this->assertSame('needs_review', $row['status']);
        $this->assertSame(['person_mismatch'], $this->codes($row));
        $this->assertSame('review', $row['issues'][0]['severity']);
        $this->assertStringContainsString('محمود محمد علي', $row['issues'][0]['message']);
        $this->assertStringContainsString('أحمد محمد علي', $row['issues'][0]['message']);
        $this->assertSame('أحمد محمد علي', $row['person_on_file']['full_name']);
    }

    public function test_confirming_the_person_makes_the_row_valid(): void
    {
        $this->closeApplication($this->openApplication());
        $row = $this->onlyRow(['F' => 'محمود محمد علي']);

        $this->asStaff($this->staff)->postJson($this->rowUrl($row, '/confirm-person'), ['note' => 'خطأ إملائي في كشف الجهة'])
            ->assertOk()
            ->assertJsonPath('data.status', 'valid')
            ->assertJsonPath('data.review.by.id', $this->staff->id)
            ->assertJsonPath('data.review.note', 'خطأ إملائي في كشف الجهة');
    }

    public function test_a_confirmation_does_not_carry_over_to_another_national_number(): void
    {
        $this->closeApplication($this->openApplication());
        $this->closeApplication($this->openApplication(null, ['candidate' => ['national_number' => '22222222222', 'full_name' => 'سارة خالد']]));
        $row = $this->onlyRow(['F' => 'محمود محمد علي']);
        $this->asStaff($this->staff)->postJson($this->rowUrl($row, '/confirm-person'))->assertOk();

        $this->asStaff($this->staff)->patchJson($this->rowUrl($row), ['values' => ['candidate.national_number' => '22222222222']])
            ->assertOk()
            ->assertJsonPath('data.status', 'needs_review');
    }

    public function test_only_a_row_with_a_person_to_confirm_can_be_confirmed(): void
    {
        $row = $this->onlyRow();

        $this->asStaff($this->staff)->postJson($this->rowUrl($row, '/confirm-person'))
            ->assertStatus(422)->assertJsonPath('errors.row.0', __('imports.nothing_to_confirm'));
    }

    // ---- corrections ----

    public function test_correcting_a_row_replaces_the_value_and_checks_again(): void
    {
        $row = $this->onlyRow(['C' => 'مهندس', 'J' => 1234567890]);
        $position = $this->referenceIds()['job_position_id'];

        Log::spy();

        $response = $this->asStaff($this->staff)->patchJson($this->rowUrl($row), ['values' => [
            'job_position_id' => $position,
            'candidate.national_number' => '01234567890',
        ]])
            ->assertOk()
            ->assertJsonPath('data.status', 'valid')
            ->assertJsonPath('data.values.job_position_id.name_ar', 'مدرس')
            ->assertJsonPath('data.cells.2.value', 'مهندس');   // the cell as read is kept (column C)

        $this->assertSame('01234567890', $response->json('data.values')['candidate.national_number']);

        Log::shouldHaveReceived('notice')->withArgs(fn(string $event, array $context) => $event === 'import.row_corrected'
            && $context['changes']['job_position_id']['to'] === $position)->once();
    }

    public function test_a_correction_is_checked_like_any_value(): void
    {
        $row = $this->onlyRow();

        $this->asStaff($this->staff)->patchJson($this->rowUrl($row), ['values' => ['quran_parts_memorized' => 40]])
            ->assertOk()
            ->assertJsonPath('data.status', 'invalid')
            ->assertJsonPath('data.issues.0.code', 'number_out_of_range');
    }

    public function test_a_correction_can_create_or_remove_a_duplicate_elsewhere_in_the_batch(): void
    {
        $id = $this->uploadRows([LegacyWorkbook::row(), LegacyWorkbook::row(['J' => '11111111111'])]);
        $rows = $this->asStaff($this->staff)->getJson(self::URL . "/{$id}/rows")->json('data');

        $this->asStaff($this->staff)->patchJson(self::URL . "/{$id}/rows/{$rows[0]['id']}", ['values' => ['candidate.national_number' => '11111111111']])
            ->assertOk()->assertJsonPath('data.status', 'valid');

        $this->assertSame('invalid', ImportRow::findOrFail($rows[1]['id'])->status->value, 'the other row is now the duplicate');
    }

    public static function badCorrections(): array
    {
        return [
            'unknown key' => [['salary' => 5], 'values.salary'],
            'id that is not a number' => [['job_position_id' => 'مدرس'], 'values.job_position_id'],
            'date in another format' => [['candidate.birth_date' => '01/02/1990'], 'values.candidate.birth_date'],
            'boolean as text' => [['full_commitment' => 'نعم'], 'values.full_commitment'],
            'letters in a national number' => [['candidate.national_number' => '0123456789x'], 'values.candidate.national_number'],
        ];
    }

    #[DataProvider('badCorrections')]
    public function test_malformed_corrections_are_refused(array $values, string $errorKey): void
    {
        $row = $this->onlyRow();

        $response = $this->asStaff($this->staff)->patchJson($this->rowUrl($row), ['values' => $values])->assertStatus(422);

        $this->assertArrayHasKey($errorKey, $response->json('errors'));
    }

    public function test_adding_a_missing_list_entry_then_revalidating_fixes_rows(): void
    {
        $row = $this->onlyRow(['C' => 'مهندس']);
        JobPosition::create(['name_ar' => 'مهندس']);

        // The cell was read as unknown; the entry now exists, but the row keeps its reading until corrected.
        $this->asStaff($this->staff)->postJson(self::URL . "/{$row['batch_id']}/revalidate")->assertOk()->assertJsonPath('data.counts.invalid', 1);

        $this->asStaff($this->staff)->patchJson($this->rowUrl($row), ['values' => ['job_position_id' => JobPosition::where('name_ar', 'مهندس')->value('id')]])
            ->assertJsonPath('data.status', 'valid');
    }

    public function test_a_list_entry_archived_after_upload_makes_the_row_invalid_on_revalidation(): void
    {
        $row = $this->onlyRow();
        JobPosition::findOrFail($this->referenceIds()['job_position_id'])->delete();

        $this->asStaff($this->staff)->postJson(self::URL . "/{$row['batch_id']}/revalidate")->assertOk()->assertJsonPath('data.counts.invalid', 1);
    }

    // ---- excluding ----

    public function test_excluding_and_including_a_row(): void
    {
        $id = $this->uploadRows([LegacyWorkbook::row(), LegacyWorkbook::row(['F' => 'نسخة ثانية'])]);
        $rows = $this->asStaff($this->staff)->getJson(self::URL . "/{$id}/rows")->json('data');

        // Excluding the first occurrence turns the duplicate into the valid one.
        $this->asStaff($this->staff)->postJson(self::URL . "/{$id}/rows/{$rows[0]['id']}/exclude", ['reason' => 'مكرر'])
            ->assertOk()
            ->assertJsonPath('data.status', 'excluded')
            ->assertJsonPath('data.exclusion.reason', 'مكرر');
        $this->assertSame('valid', ImportRow::findOrFail($rows[1]['id'])->status->value);

        $this->asStaff($this->staff)->postJson(self::URL . "/{$id}/rows/{$rows[0]['id']}/include")
            ->assertOk()->assertJsonPath('data.status', 'valid')->assertJsonPath('data.exclusion', null);
        $this->assertSame('invalid', ImportRow::findOrFail($rows[1]['id'])->status->value);
    }

    public function test_excluding_needs_a_reason(): void
    {
        $row = $this->onlyRow();

        $this->asStaff($this->staff)->postJson($this->rowUrl($row, '/exclude'), ['reason' => ''])->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    public function test_revalidating_a_closed_batch_is_refused(): void
    {
        $row = $this->onlyRow();
        $this->asStaff($this->staff)->postJson(self::URL . "/{$row['batch_id']}/cancel", ['reason' => 'ملف خاطئ'])->assertOk();

        $this->asStaff($this->staff)->postJson(self::URL . "/{$row['batch_id']}/revalidate")->assertStatus(422)->assertJsonValidationErrors('batch');
    }

    public function test_rows_can_be_filtered_by_status(): void
    {
        $id = $this->uploadRows([LegacyWorkbook::row(), LegacyWorkbook::row(['J' => 'abc']), LegacyWorkbook::row(['J' => '11111111111'])]);

        $this->asStaff($this->staff)->getJson(self::URL . "/{$id}/rows?status=invalid")->assertJsonCount(1, 'data');
        $this->asStaff($this->staff)->getJson(self::URL . "/{$id}/rows?status=valid")->assertJsonCount(2, 'data');
    }

    public function test_a_row_is_reachable_only_through_its_own_batch(): void
    {
        $first = $this->onlyRow();
        $other = $this->uploadRows([LegacyWorkbook::row(['J' => '33333333333'])]);

        $this->asStaff($this->staff)->getJson(self::URL . "/{$other}/rows/{$first['id']}")->assertNotFound();
        $this->asStaff($this->staff)->getJson($this->rowUrl($first))->assertOk();
    }

    public function test_nothing_in_a_cancelled_batch_can_change(): void
    {
        $row = $this->onlyRow();
        $this->asStaff($this->staff)->postJson(self::URL . "/{$row['batch_id']}/cancel", ['reason' => 'ملف خاطئ'])->assertOk();

        $this->asStaff($this->staff)->patchJson($this->rowUrl($row), ['values' => ['notes' => 'x']])->assertStatus(422)->assertJsonValidationErrors('batch');
        $this->asStaff($this->staff)->postJson($this->rowUrl($row, '/exclude'), ['reason' => 'لا'])->assertStatus(422);
        $this->assertSame('cancelled', ImportBatch::findOrFail($row['batch_id'])->status->value);
    }
}
