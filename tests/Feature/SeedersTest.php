<?php

namespace Tests\Feature;

use App\Modules\Documents\Database\Seeders\DocumentsDemoSeeder;
use App\Modules\Documents\Database\Seeders\DocumentTypesSeeder;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentType;
use App\Modules\Exams\Database\Seeders\ExamsDemoSeeder;
use App\Modules\Exams\Database\Seeders\ExamSubjectsSeeder;
use App\Modules\Exams\Models\ExamPlanVersion;
use App\Modules\Exams\Models\ExamSubject;
use App\Modules\Identity\Database\Seeders\DevUsersSeeder;
use App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Imports\Database\Seeders\ImportsDemoSeeder;
use App\Modules\Imports\Enums\ImportRowStatus;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Recruitment\Database\Seeders\AreasSeeder;
use App\Modules\Recruitment\Database\Seeders\JobPositionsSeeder;
use App\Modules\Recruitment\Database\Seeders\RecruitmentDemoSeeder;
use App\Modules\Recruitment\Database\Seeders\ReferenceDataSeeder;
use App\Modules\Recruitment\Enums\ApplicationStatus;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\Area;
use App\Modules\Recruitment\Models\Candidate;
use App\Modules\Recruitment\Models\JobPosition;
use App\Modules\Recruitment\Models\NominatingEntity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SeedersTest extends TestCase
{
    use RefreshDatabase;

    private function seedDemo(): void
    {
        Storage::fake(config('imports.disk'));
        $this->seed([RolesAndPermissionsSeeder::class, ReferenceDataSeeder::class, DevUsersSeeder::class, RecruitmentDemoSeeder::class, ImportsDemoSeeder::class]);
    }

    // ---- reference data (every environment) ----

    public function test_reference_data_seeds_every_list(): void
    {
        $this->seed(ReferenceDataSeeder::class);

        foreach (['districts', 'areas', 'nominating_entities', 'marital_statuses', 'job_positions', 'application_categories', 'academic_qualifications', 'specializations', 'health_conditions'] as $table) {
            $this->assertGreaterThan(0, DB::table($table)->count(), $table);
        }

        $this->assertSame(5, NominatingEntity::query()->count());   // the template's (the owner's seeder, OD-13)
        $this->assertSame(['متعاقد جديد', 'رقم ذاتي'], DB::table('application_categories')->orderBy('id')->pluck('name_ar')->all());
    }

    public function test_reference_data_can_run_again_without_duplicating_or_restoring_what_staff_changed(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $counts = collect(['areas', 'job_positions', 'specializations'])->mapWithKeys(fn($table) => [$table => DB::table($table)->count()]);

        JobPosition::query()->where('name_ar', 'سائق')->sole()->delete();               // archived by staff
        JobPosition::query()->where('name_ar', 'مستخدم')->sole()->update(['name_ar' => 'مستخدَم']);   // respelled
        $this->seed(ReferenceDataSeeder::class);

        foreach ($counts as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), $table);
        }

        $this->assertTrue(JobPosition::withTrashed()->where('name_ar', 'سائق')->sole()->trashed());
        $this->assertSame(0, JobPosition::query()->where('name_ar', 'مستخدم')->count());
    }

    public function test_areas_go_to_their_governorate_and_a_missing_governorate_is_skipped(): void
    {
        $this->seed(ReferenceDataSeeder::class);

        $this->assertSame('حلب', Area::query()->where('name_ar', 'منبج')->sole()->district->name_ar);
        $this->assertSame(count(AreasSeeder::SUGGESTED['دمشق']), Area::query()->whereRelation('district', 'name_ar', 'دمشق')->count());

        DB::table('areas')->delete();
        DB::table('districts')->where('name_ar', 'حلب')->delete();
        $this->seed(AreasSeeder::class);

        $this->assertSame(0, Area::query()->where('name_ar', 'منبج')->count());
        $this->assertGreaterThan(0, Area::query()->count());
    }

    public function test_job_positions_keep_the_templates_list(): void
    {
        $this->seed(JobPositionsSeeder::class);

        foreach (JobPositionsSeeder::FROM_TEMPLATE as $name) {
            $this->assertDatabaseHas('job_positions', ['name_ar' => $name]);
        }
    }

    // ---- demo data (local only) ----

    public function test_the_demo_has_an_application_in_every_status_with_a_history_in_the_past(): void
    {
        $this->seedDemo();

        $statuses = Application::query()->distinct()->pluck('current_status')->map->value->sort()->values()->all();
        $all = collect(ApplicationStatus::cases())->map->value->sort()->values()->all();
        $this->assertSame($all, $statuses);

        $this->assertSame(0, DB::table('application_status_events')->where('occurred_at', '>', now())->count());
        $this->assertSame(0, DB::table('application_status_events')->whereColumn('occurred_at', '<>', 'created_at')->count());
        $this->assertSame(['closure', 'created', 'exception', 'excuse_accepted', 'outcome'], DB::table('application_status_events')->distinct()->orderBy('decision_type')->pluck('decision_type')->all());
        $this->assertSame(0, DB::table('application_status_events')->whereIn('decision_type', ['closure', 'exception', 'excuse_accepted'])->where(fn($q) => $q->whereNull('reason')->orWhereNull('actor_id'))->count());
    }

    public function test_the_demo_has_a_person_nominated_twice_and_a_deactivated_person(): void
    {
        $this->seedDemo();

        $twice = Candidate::query()->has('applications', '>=', 2)->get();
        $this->assertNotEmpty($twice);
        $this->assertNotNull($twice->first()->liveApplication);

        $inactive = Candidate::query()->where('is_active', false)->sole();
        $this->assertNotNull($inactive->deactivation_reason);
        $this->assertNull($inactive->liveApplication);
    }

    public function test_the_demo_import_batches_show_every_state_a_reviewer_meets(): void
    {
        $this->seedDemo();

        $batches = ImportBatch::query()->get()->keyBy(fn(ImportBatch $batch) => $batch->status->value);
        $this->assertEqualsCanonicalizing(['approved', 'cancelled', 'pending'], $batches->keys()->all());
        $this->assertNotSame($batches['approved']->uploaded_by, $batches['approved']->approved_by);
        $this->assertTrue($batches['approved']->rows()->get()->every(fn($row) => $row->status === ImportRowStatus::Imported));

        $pending = $batches['pending']->rows()->get();
        $this->assertEqualsCanonicalizing(['valid', 'invalid', 'needs_review'], $pending->map(fn($row) => $row->status->value)->unique()->values()->all());
        $codes = $pending->flatMap(fn($row) => array_column($row->issues ?? [], 'code'))->unique()->values()->all();
        $this->assertEqualsCanonicalizing(['unknown_value', 'phone_format', 'duplicate_in_file', 'person_mismatch', 'live_application'], $codes);

        foreach ($batches as $batch) {
            Storage::disk(config('imports.disk'))->assertExists($batch->stored_path);
        }
    }

    public function test_the_demo_runs_once(): void
    {
        $this->seedDemo();
        $counts = [Application::query()->count(), ImportBatch::query()->count(), DB::table('import_rows')->count()];

        $this->seed([RecruitmentDemoSeeder::class, ImportsDemoSeeder::class]);

        $this->assertSame($counts, [Application::query()->count(), ImportBatch::query()->count(), DB::table('import_rows')->count()]);
    }

    public function test_the_demo_adds_no_list_entries(): void
    {
        $this->seed([RolesAndPermissionsSeeder::class, ReferenceDataSeeder::class, DevUsersSeeder::class]);
        $entities = NominatingEntity::query()->count();
        Storage::fake(config('imports.disk'));
        $this->seed([RecruitmentDemoSeeder::class, ImportsDemoSeeder::class]);

        $this->assertSame($entities, NominatingEntity::query()->count());
    }

    // ---- exams ----

    public function test_exam_subjects_are_the_templates_with_the_quran_from_two_parts(): void
    {
        $this->seed(ExamSubjectsSeeder::class);
        ExamSubject::query()->where('name_ar', 'فقه الجهاد')->sole()->delete();
        $this->seed(ExamSubjectsSeeder::class);

        $this->assertSame(6, ExamSubject::withTrashed()->count());
        $this->assertTrue(ExamSubject::withTrashed()->where('name_ar', 'فقه الجهاد')->sole()->trashed());
        $quran = ExamSubject::query()->where('name_ar', 'القرآن الكريم')->sole();
        $this->assertSame(['التلاوة' => '60.00', 'التجويد' => '40.00'], $quran->parts->pluck('default_weight', 'name_ar')->map(fn($w) => (string) $w)->all());
        $this->assertSame(['التلاوة' => 'exam', 'التجويد' => 'manual'], $quran->parts->mapWithKeys(fn($part) => [$part->name_ar => $part->default_assessment->value])->all());
        $this->assertSame(2, DB::table('exam_subject_parts')->count());
    }

    public function test_the_exam_demo_has_a_plan_in_every_state(): void
    {
        $this->seed([RolesAndPermissionsSeeder::class, ReferenceDataSeeder::class, ExamSubjectsSeeder::class, DevUsersSeeder::class, ExamsDemoSeeder::class]);

        $this->assertEqualsCanonicalizing(['published', 'retired', 'published', 'draft'], ExamPlanVersion::query()->pluck('status')->map->value->all());
        $this->assertSame(120, ExamPlanVersion::query()->where('status', 'published')->where('version', 1)->sole()->questionCount());   // 6 online items × 20
        $this->assertSame(6 * 3 * \App\Modules\Exams\Database\Seeders\ExamsDemoSeeder::QUESTIONS_PER_DIFFICULTY, \App\Modules\Exams\Models\ExamQuestion::query()->count());

        $this->seed(ExamsDemoSeeder::class);
        $this->assertSame(4, ExamPlanVersion::query()->count());
    }

    // ---- documents ----

    public function test_document_types_are_seeded_once(): void
    {
        $this->seed(DocumentTypesSeeder::class);
        DocumentType::query()->where('name_ar', 'قرار الاستثناء')->sole()->delete();
        $this->seed(DocumentTypesSeeder::class);

        $this->assertSame(count(DocumentTypesSeeder::REQUESTED) + count(DocumentTypesSeeder::SUGGESTED), DocumentType::withTrashed()->count());
        $this->assertSame('contract_written', DocumentType::query()->where('name_ar', 'العقد')->sole()->required_from_status->value);
    }

    public function test_the_documents_demo_leaves_some_files_complete_and_some_missing(): void
    {
        $this->seedDemo();
        $this->seed([DocumentTypesSeeder::class, DocumentsDemoSeeder::class]);
        $count = Document::query()->count();

        $this->assertGreaterThan(0, $count);
        $this->assertGreaterThan(0, Document::query()->whereNotNull('application_id')->count());   // contracts
        $this->assertLessThan(Application::query()->count() * 2, $count);                           // not everyone

        $this->seed(DocumentsDemoSeeder::class);
        $this->assertSame($count, Document::query()->count());
    }
}
