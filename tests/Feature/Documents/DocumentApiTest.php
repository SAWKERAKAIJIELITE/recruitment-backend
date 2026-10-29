<?php

namespace Tests\Feature\Documents;

use App\Modules\Documents\Database\Seeders\DocumentTypesSeeder;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentType;
use App\Modules\Identity\Database\Factories\StaffUserFactory;
use App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Recruitment\Actions\ApplicationLifecycle;
use App\Modules\Recruitment\Enums\ApplicationStatus as S;
use App\Modules\Recruitment\Enums\DecisionType as D;
use App\Modules\Recruitment\Models\Application;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\Support\RecruitmentTestHelpers;
use Tests\Support\StaffTestHelpers;
use Tests\TestCase;

/** Documents of people and applications (D-057). */
class DocumentApiTest extends TestCase
{
    use RefreshDatabase, StaffTestHelpers, RecruitmentTestHelpers;

    private const PDF = "%PDF-1.4\n1 0 obj << >> endobj\n%%EOF\n";

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DocumentTypesSeeder::class]);
        Storage::fake(config('documents.disk'));
    }

    private function type(string $name): DocumentType
    {
        return DocumentType::withTrashed()->where('name_ar', $name)->sole();
    }

    private function pdf(string $name = 'id.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, self::PDF);
    }

    private function png(string $name = 'photo.png'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 20, 20);
    }

    private function upload(Application $application, string $type, ?UploadedFile $file = null, $user = null)
    {
        return $this->asStaff($user ?? $this->hiringStaff())->post("/api/v1/staff/applications/{$application->id}/documents", [
            'document_type_id' => $this->type($type)->id,
            'file' => $file ?? $this->pdf(),
        ], ['Accept' => 'application/json']);
    }

    /** Moves an application along the main path up to $status. */
    private function advance(Application $application, S $status): void
    {
        $lifecycle = app(ApplicationLifecycle::class);

        foreach ([S::AwaitingInterview, S::AwaitingContract, S::ContractWritten] as $next) {
            $lifecycle->move($application, $next, D::Outcome);

            if ($next === $status) {
                return;
            }
        }
    }

    public function test_a_person_document_uploaded_from_an_application_is_stored_on_the_person(): void
    {
        $application = $this->openApplication();
        Log::spy();

        $response = $this->upload($application, 'صورة البطاقة الشخصية', $this->pdf('هوية/../../أحمد.pdf'))
            ->assertCreated()
            ->assertJsonPath('data.type.name_ar', 'صورة البطاقة الشخصية')
            ->assertJsonPath('data.application', null)
            ->assertJsonPath('data.mime_type', 'application/pdf')
            ->assertJsonPath('data.original_filename', 'أحمد.pdf')
            ->assertJsonPath('data.sha256', hash('sha256', self::PDF))
            ->assertJsonPath('data.archived', null);

        $document = Document::query()->sole();
        $this->assertSame($application->candidate_id, $document->candidate_id);
        $this->assertNull($document->application_id);
        $this->assertStringStartsWith("documents/{$application->candidate_id}/{$document->id}", $document->stored_path);
        Storage::disk(config('documents.disk'))->assertExists($document->stored_path);
        Log::shouldHaveReceived('notice')->withArgs(fn(string $event) => $event === 'document.uploaded')->once();
        $this->assertNotNull($response->json('data.download_url'));
    }

    public function test_an_application_document_stays_with_its_application(): void
    {
        $application = $this->openApplication();

        $this->upload($application, 'العقد')->assertCreated()->assertJsonPath('data.application.nomination_number', $application->nomination_number);

        $this->asStaff($this->hiringStaff())->post("/api/v1/staff/candidates/{$application->candidate_id}/documents", [
            'document_type_id' => $this->type('العقد')->id, 'file' => $this->pdf(),
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('errors.document_type_id.0', __('documents.needs_application'));
    }

    public function test_the_person_keeps_their_documents_for_a_new_application(): void
    {
        $first = $this->openApplication();
        $this->upload($first, 'صورة البطاقة الشخصية')->assertCreated();
        $this->upload($first, 'العقد')->assertCreated();
        $this->closeApplication($first);
        $this->travel(1)->minutes();
        $second = $this->openApplication();

        $this->asStaff($this->hiringStaff())->getJson("/api/v1/staff/applications/{$second->id}/documents")
            ->assertOk()
            ->assertJsonCount(1, 'data.documents')                         // the ID card, not the first application's contract
            ->assertJsonPath('data.documents.0.type.name_ar', 'صورة البطاقة الشخصية');
        $this->asStaff($this->hiringStaff())->getJson("/api/v1/staff/candidates/{$second->candidate_id}/documents")
            ->assertJsonCount(2, 'data');
    }

    public function test_requirements_follow_the_application_status_and_show_what_is_missing(): void
    {
        $application = $this->openApplication();
        $requirements = fn() => collect($this->asStaff($this->hiringStaff())->getJson("/api/v1/staff/applications/{$application->id}/documents")->json('data.requirements'))
            ->keyBy('type.name_ar');

        $now = $requirements();
        $this->assertTrue($now['صورة البطاقة الشخصية']['missing']);
        $this->assertTrue($now['الشهادات العلمية']['required_now']);
        $this->assertFalse($now['العقد']['required_now']);                 // not before the contract is written
        $this->assertFalse($now['السيرة الذاتية']['required_now']);        // optional

        $this->upload($application, 'صورة البطاقة الشخصية')->assertCreated();
        $this->advance($application, S::ContractWritten);

        $now = $requirements();
        $this->assertFalse($now['صورة البطاقة الشخصية']['missing']);
        $this->assertSame(1, $now['صورة البطاقة الشخصية']['present']);
        $this->assertTrue($now['العقد']['missing']);
    }

    public function test_files_are_checked_by_content(): void
    {
        $application = $this->openApplication();

        $this->upload($application, 'صورة شخصية', $this->png())->assertCreated()->assertJsonPath('data.mime_type', 'image/png');
        $this->upload($application, 'السيرة الذاتية', UploadedFile::fake()->createWithContent('cv.pdf', '<?php echo 1;'))
            ->assertStatus(422)->assertJsonValidationErrors('file');
        $this->upload($application, 'السيرة الذاتية', UploadedFile::fake()->createWithContent('cv.png', self::PDF))         // a PDF named .png
            ->assertStatus(422)->assertJsonValidationErrors('file');
        $this->upload($application, 'السيرة الذاتية', UploadedFile::fake()->createWithContent('cv.docx', 'PK'))
            ->assertStatus(422)->assertJsonValidationErrors('file');
        $this->upload($application, 'السيرة الذاتية', UploadedFile::fake()->create('big.pdf', config('documents.max_file_kilobytes') + 1))
            ->assertStatus(422)->assertJsonValidationErrors('file');
        $this->assertSame(1, Document::query()->count());
    }

    public function test_download_returns_the_original_and_is_audited(): void
    {
        $application = $this->openApplication();
        $id = $this->upload($application, 'صورة البطاقة الشخصية', $this->pdf('بطاقة.pdf'))->json('data.id');
        Log::spy();

        $response = $this->asStaff(StaffUserFactory::new()->withRole('general_manager')->create())->get("/api/v1/staff/documents/{$id}/download");

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertSame(self::PDF, $response->streamedContent());
        Log::shouldHaveReceived('notice')->withArgs(fn(string $event, array $context) => $event === 'document.downloaded' && $context['document_id'] === $id)->once();
    }

    public function test_archiving_needs_a_reason_happens_once_and_keeps_the_file(): void
    {
        $application = $this->openApplication();
        $id = $this->upload($application, 'صورة البطاقة الشخصية')->json('data.id');
        $staff = $this->hiringStaff();

        $this->asStaff($staff)->postJson("/api/v1/staff/documents/{$id}/archive", [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->asStaff($staff)->postJson("/api/v1/staff/documents/{$id}/archive", ['reason' => 'صورة غير واضحة'])
            ->assertOk()->assertJsonPath('data.archived.reason', 'صورة غير واضحة')->assertJsonPath('data.archived.by.id', $staff->id);
        $this->asStaff($staff)->postJson("/api/v1/staff/documents/{$id}/archive", ['reason' => 'مرة أخرى'])->assertStatus(422);

        $this->asStaff($staff)->getJson("/api/v1/staff/applications/{$application->id}/documents")->assertJsonCount(0, 'data.documents');
        $this->asStaff($staff)->getJson("/api/v1/staff/applications/{$application->id}/documents?include_archived=1")->assertJsonCount(1, 'data.documents');
        Storage::disk(config('documents.disk'))->assertExists(Document::query()->sole()->stored_path);
    }

    public function test_an_archived_type_takes_no_new_uploads(): void
    {
        $type = $this->type('السيرة الذاتية');
        $type->delete();

        $this->upload($this->openApplication(), 'السيرة الذاتية')->assertStatus(422)->assertJsonPath('errors.document_type_id.0', __('documents.type_archived'));
    }

    public function test_permissions(): void
    {
        $application = $this->openApplication();
        $id = $this->upload($application, 'صورة البطاقة الشخصية')->json('data.id');
        $manager = StaffUserFactory::new()->withRole('general_manager')->create();   // reports.view: reads only
        $nobody = StaffUserFactory::new()->create();

        $this->asStaff($manager)->getJson("/api/v1/staff/applications/{$application->id}/documents")->assertOk();
        $this->upload($application, 'السيرة الذاتية', null, $manager)->assertForbidden();
        $this->asStaff($manager)->postJson("/api/v1/staff/documents/{$id}/archive", ['reason' => 'سبب'])->assertForbidden();
        $this->asStaff($nobody)->get("/api/v1/staff/documents/{$id}/download")->assertForbidden();
        $this->asStaff($nobody)->getJson("/api/v1/staff/candidates/{$application->candidate_id}/documents")->assertForbidden();
    }

    // ---- types ----

    public function test_document_types_are_managed(): void
    {
        $staff = $this->hiringStaff();

        $id = $this->asStaff($staff)->postJson('/api/v1/staff/document-types', ['name_ar' => 'شهادة خبرة', 'scope' => 'candidate', 'direction' => 'incoming', 'required_from_status' => 'awaiting_interview'])
            ->assertCreated()->assertJsonPath('data.required_from_status', 'awaiting_interview')->json('data.id');

        $this->asStaff($staff)->postJson('/api/v1/staff/document-types', ['name_ar' => 'شهاده خبره', 'scope' => 'candidate', 'direction' => 'incoming'])->assertStatus(422)->assertJsonValidationErrors('name_ar');
        $this->asStaff($staff)->postJson('/api/v1/staff/document-types', ['name_ar' => 'نوع', 'scope' => 'everyone', 'direction' => 'incoming'])->assertStatus(422)->assertJsonValidationErrors('scope');
        $this->asStaff($staff)->patchJson("/api/v1/staff/document-types/{$id}", ['required_from_status' => null])->assertOk()->assertJsonPath('data.required_from_status', null);
        $this->asStaff($staff)->patchJson("/api/v1/staff/document-types/{$id}", ['scope' => 'application'])->assertStatus(422)->assertJsonValidationErrors('scope');
        $this->asStaff($staff)->deleteJson("/api/v1/staff/document-types/{$id}")->assertOk()->assertJsonPath('data.archived', true);
        $this->asStaff(StaffUserFactory::new()->create())->getJson('/api/v1/staff/document-types')->assertOk()->assertJsonMissing(['name_ar' => 'شهادة خبرة']);
        $this->asStaff(StaffUserFactory::new()->withRole('general_manager')->create())->postJson('/api/v1/staff/document-types', ['name_ar' => 'x', 'scope' => 'candidate', 'direction' => 'incoming'])->assertForbidden();
    }

    // ---- database ----

    public function test_a_document_is_never_changed_or_deleted_only_archived_once(): void
    {
        $application = $this->openApplication();
        $id = $this->upload($application, 'صورة البطاقة الشخصية')->json('data.id');
        $refused = function (callable $write) {
            try {
                DB::transaction($write);
                $this->fail('The database accepted the write.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        };

        $refused(fn() => DB::table('documents')->where('id', $id)->update(['original_filename' => 'x.pdf']));
        $refused(fn() => DB::table('documents')->where('id', $id)->delete());
        $refused(fn() => DB::table('documents')->where('id', $id)->update(['archived_at' => now()]));   // no one, no reason
        DB::table('documents')->where('id', $id)->update(['archived_at' => now(), 'archived_by' => $this->hiringStaff()->id, 'archive_reason' => 'سبب']);
        $refused(fn() => DB::table('documents')->where('id', $id)->update(['archive_reason' => 'سبب آخر']));
    }

    public function test_the_database_keeps_documents_with_the_right_person_and_scope(): void
    {
        $application = $this->openApplication();
        $other = $this->openApplication(null, ['candidate' => ['national_number' => '22222222222']]);
        $row = [
            'id' => (string) \Illuminate\Support\Str::uuid7(), 'candidate_id' => $application->candidate_id, 'original_filename' => 'a.pdf',
            'stored_path' => 'documents/a.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 10, 'sha256' => str_repeat('a', 64),
            'uploaded_by' => $this->hiringStaff()->id, 'created_at' => now(),
        ];

        foreach ([
            ['document_type_id' => $this->type('العقد')->id, 'application_id' => $other->id],                 // another person's application
            ['document_type_id' => $this->type('العقد')->id, 'application_id' => null],                        // an application document without one
            ['document_type_id' => $this->type('صورة البطاقة الشخصية')->id, 'application_id' => $application->id],   // a person document on an application
        ] as $bad) {
            try {
                DB::transaction(fn() => DB::table('documents')->insert($bad + $row));
                $this->fail('The database accepted the write.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
