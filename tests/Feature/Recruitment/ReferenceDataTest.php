<?php

namespace Tests\Feature\Recruitment;

use App\Modules\Identity\Database\Factories\StaffUserFactory;
use App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Recruitment\Database\Seeders\DistrictsSeeder;
use App\Modules\Recruitment\Database\Seeders\MaritalStatusesSeeder;
use App\Modules\Recruitment\Enums\ReferenceType;
use App\Modules\Recruitment\Models\District;
use App\Modules\Recruitment\Models\MaritalStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RecruitmentTestHelpers;
use Tests\Support\StaffTestHelpers;
use Tests\TestCase;

class ReferenceDataTest extends TestCase
{
    use RefreshDatabase, StaffTestHelpers, RecruitmentTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /** Every list except areas, which need a district (tested separately below). */
    public static function flatTypes(): array
    {
        return collect(ReferenceType::cases())
            ->reject(fn(ReferenceType $type) => $type->isScopedToDistrict())
            ->mapWithKeys(fn(ReferenceType $type) => [$type->value => [$type->value]])
            ->all();
    }

    private function url(string $type, string $suffix = ''): string
    {
        return "/api/v1/staff/reference/{$type}{$suffix}";
    }

    private function manager()
    {
        return StaffUserFactory::new()->withRole('recruitment_staff')->create();
    }

    // ---- every flat list ----

    #[DataProvider('flatTypes')]
    public function test_any_signed_in_staff_user_can_read_the_list(string $type): void
    {
        $this->getJson($this->url($type))->assertUnauthorized();

        $nobody = StaffUserFactory::new()->create();   // no roles at all
        $this->asStaff($nobody)->getJson($this->url($type))->assertOk();
    }

    #[DataProvider('flatTypes')]
    public function test_changing_a_list_needs_candidates_manage(string $type): void
    {
        $generalManager = StaffUserFactory::new()->withRole('general_manager')->create();

        $this->asStaff($generalManager)->postJson($this->url($type), ['name_ar' => 'اسم'])->assertForbidden();
    }

    #[DataProvider('flatTypes')]
    public function test_create_then_list(string $type): void
    {
        $response = $this->asStaff($this->manager())->postJson($this->url($type), ['name_ar' => ' مثال أول '])
            ->assertCreated()
            ->assertJsonPath('item.name_ar', 'مثال أول')
            ->assertJsonPath('item.archived_at', null);

        $this->assertIsInt($response->json('item.id'), 'list keys are integers');
        $this->getJson($this->url($type))->assertOk()->assertJsonCount(1, 'data');
    }

    #[DataProvider('flatTypes')]
    public function test_the_same_name_in_another_spelling_is_refused(string $type): void
    {
        $manager = $this->manager();
        $this->asStaff($manager)->postJson($this->url($type), ['name_ar' => 'أحمد'])->assertCreated();

        $this->asStaff($manager)->postJson($this->url($type), ['name_ar' => 'احمد'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name_ar');
    }

    #[DataProvider('flatTypes')]
    public function test_rename(string $type): void
    {
        $manager = $this->manager();
        $id = $this->asStaff($manager)->postJson($this->url($type), ['name_ar' => 'قديم'])->json('item.id');

        $this->asStaff($manager)->putJson($this->url($type, "/{$id}"), ['name_ar' => 'جديد'])
            ->assertOk()
            ->assertJsonPath('item.name_ar', 'جديد');
    }

    #[DataProvider('flatTypes')]
    public function test_renaming_onto_an_existing_name_is_refused(string $type): void
    {
        $manager = $this->manager();
        $this->asStaff($manager)->postJson($this->url($type), ['name_ar' => 'الأول'])->assertCreated();
        $id = $this->asStaff($manager)->postJson($this->url($type), ['name_ar' => 'الثاني'])->json('item.id');

        $this->asStaff($manager)->putJson($this->url($type, "/{$id}"), ['name_ar' => 'الاول'])->assertStatus(422);
    }

    #[DataProvider('flatTypes')]
    public function test_archive_hides_restore_brings_back(string $type): void
    {
        $manager = $this->manager();
        $id = $this->asStaff($manager)->postJson($this->url($type), ['name_ar' => 'مؤقت'])->json('item.id');

        $this->asStaff($manager)->deleteJson($this->url($type, "/{$id}"))->assertOk();
        $this->asStaff($manager)->getJson($this->url($type))->assertJsonCount(0, 'data');
        $this->asStaff($manager)->getJson($this->url($type) . '?include_archived=1')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $id);

        $this->asStaff($manager)->postJson($this->url($type, "/{$id}/restore"))->assertOk();
        $this->asStaff($manager)->getJson($this->url($type))->assertJsonCount(1, 'data');
    }

    #[DataProvider('flatTypes')]
    public function test_an_archived_name_points_to_restore(string $type): void
    {
        $manager = $this->manager();
        $id = $this->asStaff($manager)->postJson($this->url($type), ['name_ar' => 'قديم'])->json('item.id');
        $this->asStaff($manager)->deleteJson($this->url($type, "/{$id}"))->assertOk();

        $this->asStaff($manager)->postJson($this->url($type), ['name_ar' => 'قديم'])
            ->assertStatus(422)
            ->assertJsonPath('errors.name_ar.0', __('recruitment.name_archived'));
    }

    public function test_unknown_type_and_bad_ids_are_404(): void
    {
        $manager = $this->manager();

        $this->asStaff($manager)->getJson($this->url('nonsense'))->assertNotFound();
        $this->asStaff($manager)->putJson($this->url('job-positions', '/not-a-number'), ['name_ar' => 'اسم'])->assertNotFound();
        $this->asStaff($manager)->putJson($this->url('job-positions', '/999999'), ['name_ar' => 'اسم'])->assertNotFound();
    }

    public function test_a_name_is_required(): void
    {
        $this->asStaff($this->manager())->postJson($this->url('job-positions'), ['name_ar' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name_ar');
    }

    // ---- areas live inside districts ----

    public function test_a_new_area_needs_an_active_district(): void
    {
        $manager = $this->manager();
        $archived = $this->district('القنيطرة');
        $archived->delete();

        $this->asStaff($manager)->postJson($this->url('areas'), ['name_ar' => 'المزة'])
            ->assertStatus(422)->assertJsonValidationErrors('district_id');
        $this->asStaff($manager)->postJson($this->url('areas'), ['name_ar' => 'المزة', 'district_id' => $archived->id])
            ->assertStatus(422)->assertJsonValidationErrors('district_id');
    }

    public function test_the_same_area_name_may_exist_in_two_districts_but_not_twice_in_one(): void
    {
        $manager = $this->manager();
        $damascus = $this->district('دمشق');
        $aleppo = $this->district('حلب');

        $this->asStaff($manager)->postJson($this->url('areas'), ['name_ar' => 'الجديدة', 'district_id' => $damascus->id])
            ->assertCreated()
            ->assertJsonPath('item.district_id', $damascus->id);
        $this->asStaff($manager)->postJson($this->url('areas'), ['name_ar' => 'الجديدة', 'district_id' => $aleppo->id])
            ->assertCreated();

        $this->asStaff($manager)->postJson($this->url('areas'), ['name_ar' => 'الجديده', 'district_id' => $damascus->id])
            ->assertStatus(422)->assertJsonValidationErrors('name_ar');
    }

    public function test_renaming_an_area_checks_only_its_own_district(): void
    {
        $manager = $this->manager();
        $damascus = $this->district('دمشق');
        $aleppo = $this->district('حلب');
        $this->area($aleppo, 'الشهباء');
        $mezzeh = $this->area($damascus, 'المزة');
        $this->area($damascus, 'كفرسوسة');

        $this->asStaff($manager)->putJson($this->url('areas', "/{$mezzeh->id}"), ['name_ar' => 'الشهباء'])->assertOk();
        $this->asStaff($manager)->putJson($this->url('areas', "/{$mezzeh->id}"), ['name_ar' => 'كفرسوسة'])->assertStatus(422);
        $this->assertSame($damascus->id, $mezzeh->fresh()->district_id, 'renaming never moves an area');
    }

    public function test_areas_can_be_listed_per_district(): void
    {
        $damascus = $this->district('دمشق');
        $aleppo = $this->district('حلب');
        $this->area($damascus, 'المزة');
        $this->area($damascus, 'كفرسوسة');
        $this->area($aleppo, 'الشهباء');

        $nobody = StaffUserFactory::new()->create();
        $this->asStaff($nobody)->getJson($this->url('areas'))->assertJsonCount(3, 'data');
        $this->asStaff($nobody)->getJson($this->url('areas') . "?district_id={$damascus->id}")
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.district_id', $damascus->id);
    }

    // ---- default lists ----

    public function test_default_districts_are_seeded_once(): void
    {
        $this->seed(DistrictsSeeder::class);
        $this->seed(DistrictsSeeder::class);

        $this->assertSame(14, District::count());
    }

    public function test_the_districts_seeder_does_not_bring_back_an_archived_entry(): void
    {
        $this->seed(DistrictsSeeder::class);
        District::where('name_ar', 'دمشق')->firstOrFail()->delete();

        $this->seed(DistrictsSeeder::class);

        $this->assertSame(13, District::count());
        $this->assertSame(14, District::withTrashed()->count());
    }

    public function test_default_marital_statuses_are_seeded_once(): void
    {
        $this->seed(MaritalStatusesSeeder::class);
        $this->seed(MaritalStatusesSeeder::class);

        $this->assertSame(4, MaritalStatus::count());
    }
}
