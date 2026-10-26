<?php

namespace Tests\Feature\Imports;

use App\Modules\Identity\Database\Factories\StaffUserFactory;
use App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Imports\Models\ImportBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\LegacyWorkbook;
use Tests\Support\RecruitmentTestHelpers;
use Tests\Support\StaffTestHelpers;
use Tests\TestCase;

/** Uploading a file: reading the legacy layout, refusing what cannot be imported (analysis doc §09, §23). */
class ImportUploadTest extends TestCase
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

    private function upload(UploadedFile $file, array $extra = [])
    {
        return $this->asStaff($this->staff)->post(self::URL, ['file' => $file] + $extra, ['Accept' => 'application/json']);
    }

    public function test_the_legacy_sheet_is_read_by_header_names(): void
    {
        $response = $this->upload(LegacyWorkbook::file([LegacyWorkbook::row(), LegacyWorkbook::row(['J' => '11111111111', 'F' => 'سارة خالد'])]))
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.sheet_name', 'بيانات المرشحين')
            ->assertJsonPath('data.header_row', 5)                  // three title rows and an empty row above, as in the real file
            ->assertJsonPath('data.counts.total', 2)
            ->assertJsonPath('data.counts.valid', 2)
            ->assertJsonPath('data.uploaded_by.id', $this->staff->id);

        $columns = collect($response->json('data.columns'))->pluck('column', 'field');
        $this->assertSame('B', $columns['nominating_entity']);
        $this->assertSame('J', $columns['national_number']);
        $this->assertSame('L', $columns['health']);                // "الحالة الصحية/بالتفصيل"
        $this->assertSame('N', $columns['residence_place']);
        $this->assertSame(['A', 'X', 'Y', 'Z'], collect($response->json('data.ignored_columns'))->pluck('column')->all());

        $batch = ImportBatch::firstOrFail();
        Storage::disk('local')->assertExists($batch->stored_path);
        $this->assertDatabaseCount('applications', 0);           // nothing is imported before approval
    }

    public function test_columns_are_found_wherever_they_are_and_whatever_the_spelling(): void
    {
        // Headers in spelling variants and with punctuation, no title row: still read correctly.
        $file = LegacyWorkbook::file([LegacyWorkbook::row()], ['headers' => ['G' => 'اسم الام', 'L' => 'الحالة الصحية بالتفصيل', 'V' => 'رقم الهاتف:'], 'title' => null]);

        $this->upload($file)->assertCreated()->assertJsonPath('data.header_row', 1)->assertJsonPath('data.counts.valid', 1);
    }

    public function test_the_candidates_sheet_is_found_among_other_sheets(): void
    {
        $file = LegacyWorkbook::file([LegacyWorkbook::row()], ['sheets_before' => ['لوحة التحكم', 'الاختبارات', 'المقابلات']]);

        $this->upload($file)->assertCreated()->assertJsonPath('data.sheet_name', 'بيانات المرشحين');
    }

    public function test_empty_rows_between_data_rows_are_skipped(): void
    {
        $file = LegacyWorkbook::file([LegacyWorkbook::row(), LegacyWorkbook::row(['J' => '11111111111'])], ['empty_rows_between' => true]);

        $this->upload($file)->assertCreated()->assertJsonPath('data.counts.total', 2);
    }

    public function test_a_missing_required_column_refuses_the_file(): void
    {
        $response = $this->upload(LegacyWorkbook::file([LegacyWorkbook::row()], ['headers' => ['K' => null]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertStringContainsString('الحالة الاجتماعية', $response->json('errors.file.0'));
        $this->assertDatabaseCount('import_batches', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());   // the stored copy is removed again
    }

    public function test_a_sheet_without_name_and_national_number_headers_is_refused(): void
    {
        $this->upload(LegacyWorkbook::file([LegacyWorkbook::row()], ['headers' => ['F' => null, 'J' => null]]))
            ->assertStatus(422)
            ->assertJsonPath('errors.file.0', __('imports.no_header_row'));
    }

    public function test_a_sheet_without_data_rows_is_refused(): void
    {
        $this->upload(LegacyWorkbook::file([]))->assertStatus(422)->assertJsonPath('errors.file.0', __('imports.no_rows'));
    }

    public function test_a_file_that_is_not_a_workbook_is_refused(): void
    {
        $file = UploadedFile::fake()->createWithContent('candidates.xlsx', 'this is not a spreadsheet');

        $this->upload($file)->assertStatus(422)->assertJsonPath('errors.file.0', __('imports.unreadable_file'));
    }

    public function test_only_excel_extensions_are_accepted(): void
    {
        $this->upload(UploadedFile::fake()->createWithContent('candidates.csv', "a,b\n1,2"))
            ->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_too_many_rows_are_refused(): void
    {
        config(['imports.max_rows' => 2]);
        $rows = [LegacyWorkbook::row(), LegacyWorkbook::row(['J' => '11111111111']), LegacyWorkbook::row(['J' => '22222222222'])];

        $this->upload(LegacyWorkbook::file($rows))->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_a_workbook_too_large_once_unpacked_is_refused(): void
    {
        config(['imports.max_unpacked_megabytes' => 0]);

        $this->upload(LegacyWorkbook::file([LegacyWorkbook::row()]))
            ->assertStatus(422)
            ->assertJsonPath('errors.file.0', __('imports.too_large_unpacked', ['max' => 0]));
    }

    // ---- the whole file is refused when a row has no national number (D-046) ----

    public function test_a_row_without_a_national_number_refuses_the_whole_file(): void
    {
        $rows = [LegacyWorkbook::row(), LegacyWorkbook::row(['J' => null, 'F' => 'بلا رقم']), LegacyWorkbook::row(['J' => '11111111111'])];

        $response = $this->upload(LegacyWorkbook::file($rows))->assertStatus(422)->assertJsonValidationErrors('file');

        $this->assertSame(__('imports.missing_national_numbers', ['count' => 1, 'rows' => 7]), $response->json('errors.file.0'));
        $this->assertDatabaseCount('import_batches', 0);
        $this->assertDatabaseCount('import_rows', 0);
    }

    public function test_a_malformed_national_number_is_a_row_problem_not_a_file_problem(): void
    {
        $this->upload(LegacyWorkbook::file([LegacyWorkbook::row(['J' => '123'])]))->assertCreated()->assertJsonPath('data.counts.invalid', 1);
    }

    // ---- security (D-045) ----

    /** Adds a part to a generated workbook. */
    private function withPart(UploadedFile $file, string $name, string $content): UploadedFile
    {
        $zip = new \ZipArchive();
        $zip->open($file->getRealPath());
        $zip->addFromString($name, $content);
        $zip->close();

        return new UploadedFile($file->getRealPath(), 'candidates.xlsx', null, null, true);
    }

    public static function unsafeParts(): array
    {
        return [
            'macros' => ['xl/vbaProject.bin'],
            'external workbook link' => ['xl/externalLinks/externalLink1.xml'],
            'embedded object' => ['xl/embeddings/oleObject1.bin'],
            'activeX control' => ['xl/activeX/activeX1.xml'],
            'external data connection' => ['xl/connections.xml'],
            'path traversal' => ['../evil.xml'],
        ];
    }

    #[DataProvider('unsafeParts')]
    public function test_workbooks_with_unsafe_parts_are_refused(string $part): void
    {
        $file = $this->withPart(LegacyWorkbook::file([LegacyWorkbook::row()]), $part, 'x');

        $this->upload($file)->assertStatus(422)->assertJsonPath('errors.file.0', __('imports.unsafe_content'));
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_zip_bomb_is_refused_before_anything_is_parsed(): void
    {
        $file = $this->withPart(LegacyWorkbook::file([LegacyWorkbook::row()]), 'xl/media/padding.bin', str_repeat("\0", 8 * 1024 * 1024));

        $this->upload($file)->assertStatus(422)->assertJsonPath('errors.file.0', __('imports.unsafe_content'));
    }

    public static function unsafeFormulas(): array
    {
        return [
            'DDE' => ["cmd|' /c calc'!A0"],
            'web service' => ['WEBSERVICE("http://example.invalid/x")'],
            'external workbook' => ["'C:\\data\\[other.xlsx]Sheet1'!A1"],
            'linked workbook' => ['[1]Sheet1!A1'],
            'hyperlink' => ['HYPERLINK("http://example.invalid","اضغط هنا")'],
        ];
    }

    /**
     * Writes the formula straight into the sheet XML, as a hostile file would (PhpSpreadsheet itself
     * refuses to write some of these as formulas and stores them as harmless text).
     */
    #[DataProvider('unsafeFormulas')]
    public function test_formulas_that_reach_outside_the_workbook_are_refused(string $formula): void
    {
        $file = LegacyWorkbook::file([LegacyWorkbook::row(['W' => 'PLACEHOLDER'])]);
        $zip = new \ZipArchive();
        $zip->open($file->getRealPath());
        $part = 'xl/worksheets/sheet2.xml';
        $xml = preg_replace('#<c r="W6"[^>]*>.*?</c>#s', '<c r="W6"><f>' . htmlspecialchars($formula, ENT_XML1) . '</f></c>', $zip->getFromName($part), 1);
        $zip->addFromString($part, $xml);
        $zip->close();

        $this->upload(new UploadedFile($file->getRealPath(), 'candidates.xlsx', null, null, true))
            ->assertStatus(422)
            ->assertJsonPath('errors.file.0', __('imports.unsafe_formula', ['cell' => 'W6']));
    }

    public function test_text_that_merely_starts_with_equals_is_not_a_formula(): void
    {
        $this->upload(LegacyWorkbook::file([LegacyWorkbook::row(['W' => ['text' => "=cmd|' /c calc'!A0"]])]))->assertCreated();
    }

    public function test_content_decides_the_format_not_the_extension(): void
    {
        $pdf = UploadedFile::fake()->createWithContent('candidates.xlsx', "%PDF-1.7\n...");
        $this->upload($pdf)->assertStatus(422)->assertJsonPath('errors.file.0', __('imports.unreadable_file'));

        $html = UploadedFile::fake()->createWithContent('candidates.xls', '<table><tr><td>الاسم الثلاثي</td><td>الرقم الوطني</td></tr></table>');
        $this->upload($html)->assertStatus(422)->assertJsonPath('errors.file.0', __('imports.unreadable_file'));
    }

    public function test_the_client_filename_is_cleaned(): void
    {
        $file = LegacyWorkbook::file([LegacyWorkbook::row()], [], "..\\..\\etc/كشف\x07 \"دمشق\".xlsx");

        $this->upload($file)->assertCreated()->assertJsonPath('data.original_filename', 'كشف دمشق.xlsx');
    }

    public function test_long_cell_text_is_bounded(): void
    {
        $id = $this->upload(LegacyWorkbook::file([LegacyWorkbook::row(['W' => str_repeat('ن', 10000)])]))->assertCreated()->json('data.id');

        $row = \App\Modules\Imports\Models\ImportRow::where('import_batch_id', $id)->sole();
        $this->assertSame(1000, mb_strlen($row->raw['W']));
        $this->assertSame('text_too_long', $row->issues[0]['code']);   // still refused, not silently cut
    }

    public function test_downloads_are_never_sniffed_by_the_browser(): void
    {
        $id = $this->upload(LegacyWorkbook::file([LegacyWorkbook::row()]))->json('data.id');

        $this->asStaff($this->staff)->get(self::URL . "/{$id}/original")->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->asStaff($this->staff)->get(self::URL . "/{$id}/report")->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_uploads_are_rate_limited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->upload(UploadedFile::fake()->createWithContent('x.xlsx', 'not a workbook'))->assertStatus(422);
        }

        $this->upload(UploadedFile::fake()->createWithContent('x.xlsx', 'not a workbook'))->assertStatus(429);
    }

    /** AC-10: uploading the same file twice does not create a second batch. */
    public function test_the_same_file_cannot_be_uploaded_twice_unless_its_batch_was_cancelled(): void
    {
        $file = LegacyWorkbook::file([LegacyWorkbook::row()]);
        $copy = new UploadedFile($file->getRealPath(), 'copy.xlsx', null, null, true);

        $id = $this->upload($file)->assertCreated()->json('data.id');
        $this->upload($copy)->assertStatus(422)->assertJsonValidationErrors('file');

        $this->asStaff($this->staff)->postJson(self::URL . "/{$id}/cancel", ['reason' => 'ملف خاطئ'])->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->upload(new UploadedFile($file->getRealPath(), 'again.xlsx', null, null, true))->assertCreated();
    }

    public function test_the_batch_records_where_the_file_comes_from(): void
    {
        $ids = $this->referenceIds();

        $this->upload(LegacyWorkbook::file([LegacyWorkbook::row()]), [
            'nominating_entity_id' => $ids['nominating_entity_id'],
            'source_district_id' => $ids['residence_district_id'],
            'notes' => 'دفعة تشرين',
        ])
            ->assertCreated()
            ->assertJsonPath('data.nominating_entity.name_ar', 'مديرية أوقاف دمشق')
            ->assertJsonPath('data.source_district.name_ar', 'دمشق')
            ->assertJsonPath('data.notes', 'دفعة تشرين');
    }

    public function test_the_batch_entity_fills_rows_whose_entity_cell_is_empty(): void
    {
        $ids = $this->referenceIds();

        $id = $this->upload(LegacyWorkbook::file([LegacyWorkbook::row(['B' => null])]), ['nominating_entity_id' => $ids['nominating_entity_id']])
            ->assertCreated()->assertJsonPath('data.counts.valid', 1)->json('data.id');

        $this->asStaff($this->staff)->getJson(self::URL . "/{$id}/rows")
            ->assertJsonPath('data.0.values.nominating_entity_id.id', $ids['nominating_entity_id']);
    }

    public function test_imports_need_the_imports_permission(): void
    {
        $manager = StaffUserFactory::new()->withRole('general_manager')->create();

        $this->asStaff($manager)->post(self::URL, ['file' => LegacyWorkbook::file([LegacyWorkbook::row()])], ['Accept' => 'application/json'])
            ->assertForbidden();
        $this->asStaff($manager)->getJson(self::URL)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withoutToken()->post(self::URL, [], ['Accept' => 'application/json'])->assertUnauthorized();
    }

    public function test_batches_are_listed_newest_first_with_counts(): void
    {
        $this->upload(LegacyWorkbook::file([LegacyWorkbook::row()], [], 'first.xlsx'))->assertCreated();
        $this->travel(1)->minutes();
        $this->upload(LegacyWorkbook::file([LegacyWorkbook::row(['J' => 'abc'])], [], 'second.xlsx'))->assertCreated();

        $this->asStaff($this->staff)->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.0.original_filename', 'second.xlsx')
            ->assertJsonPath('data.0.counts.invalid', 1)
            ->assertJsonPath('data.1.counts.valid', 1);
        $this->asStaff($this->staff)->getJson(self::URL . '?status=approved')->assertJsonCount(0, 'data');
    }

    public function test_the_original_file_can_be_downloaded_unchanged(): void
    {
        $file = LegacyWorkbook::file([LegacyWorkbook::row()], [], 'كشف دمشق.xlsx');
        $id = $this->upload($file)->json('data.id');

        $response = $this->asStaff($this->staff)->get(self::URL . "/{$id}/original")->assertOk();

        $this->assertSame(hash_file('sha256', $file->getRealPath()), hash('sha256', $response->streamedContent()));
    }
}
