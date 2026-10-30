<?php

namespace Tests\Feature\Recruitment;

use App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Recruitment\Actions\ApplicationLifecycle;
use App\Modules\Recruitment\Enums\ApplicationStatus;
use App\Modules\Recruitment\Enums\DecisionType;
use App\Modules\Recruitment\Models\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\RecruitmentTestHelpers;
use Tests\Support\StaffTestHelpers;
use Tests\TestCase;

/** The filters, sorting and page size of GET /applications (ApplicationSearch). */
class ApplicationSearchTest extends TestCase
{
    use RefreshDatabase, StaffTestHelpers, RecruitmentTestHelpers;

    private const URL = '/api/v1/staff/applications';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->travelTo('2026-10-30 10:00:00');
    }

    private function open(string $number, array $overrides = []): Application
    {
        return $this->openApplication(null, array_replace_recursive(['candidate' => ['national_number' => $number]], $overrides));
    }

    /** @return list<string> nomination numbers found, in the order returned */
    private function found(string $query): array
    {
        return array_column($this->asStaff($this->hiringStaff())->getJson(self::URL . '?' . $query)->assertOk()->json('data'), 'nomination_number');
    }

    private function total(string $query): int
    {
        return count($this->found($query));
    }

    public function test_yes_no_filters_also_filter_on_false(): void
    {
        $this->open('11111111111', ['full_commitment' => true, 'overnight_work' => false, 'works_for_other_government_entity' => true]);
        $this->open('22222222222', ['full_commitment' => false, 'overnight_work' => true, 'works_for_other_government_entity' => false, 'studies_discontinued' => true, 'studying_years' => 2]);

        $this->assertSame(1, $this->total('full_commitment=1'));
        $this->assertSame(1, $this->total('full_commitment=false'));
        $this->assertSame(1, $this->total('overnight_work=0'));
        $this->assertSame(1, $this->total('works_for_other_government_entity=true'));
        $this->assertSame(1, $this->total('studies_discontinued=1'));
        $this->assertSame(2, $this->total('full_commitment='));               // empty: no filter
        $this->asStaff($this->hiringStaff())->getJson(self::URL . '?full_commitment=maybe')->assertStatus(422);
    }

    public function test_ranges_include_their_bounds_and_zero_filters(): void
    {
        $this->open('11111111111', ['studying_years' => 0, 'quran_parts_memorized' => 0]);
        $this->open('22222222222', ['studying_years' => 4, 'quran_parts_memorized' => 15]);
        $this->open('33333333333', ['studying_years' => 6, 'quran_parts_memorized' => 30]);

        $this->assertSame(1, $this->total('studying_years=0'));
        $this->assertSame(2, $this->total('studying_years_min=4'));
        $this->assertSame(2, $this->total('studying_years_max=4'));
        $this->assertSame(1, $this->total('quran_parts_min=15&quran_parts_max=29'));
        $this->assertSame(1, $this->total('quran_parts_max=0'));
    }

    public function test_one_or_several_statuses_and_live_or_ended(): void
    {
        $failed = $this->open('11111111111');
        app(ApplicationLifecycle::class)->move($failed, ApplicationStatus::ExamFailed, DecisionType::Outcome);
        $this->open('22222222222');
        $closed = $this->open('33333333333');
        $this->closeApplication($closed);

        $this->assertSame(1, $this->total('status=exam_failed'));
        $this->assertSame(2, $this->total('status[]=exam_failed&status[]=awaiting_exam'));
        $this->assertSame(2, $this->total('live=1'));
        $this->assertSame([$closed->nomination_number], $this->found('live=0'));
        $this->asStaff($this->hiringStaff())->getJson(self::URL . '?status=finished')->assertStatus(422)->assertJsonValidationErrors('status.0');
    }

    public function test_by_list_entries_of_the_application_and_the_person(): void
    {
        $aleppo = $this->district('حلب');
        $other = $this->open('11111111111');
        $target = $this->open('22222222222', [
            'residence_district_id' => $aleppo->id,
            'residence_area_id' => $this->area($aleppo, 'الشهباء')->id,
            'job_position_id' => $this->jobPosition('سائق')->id,
            'candidate' => ['birth_place_id' => $aleppo->id],
        ]);

        $this->assertSame([$target->nomination_number], $this->found("residence_district_id={$aleppo->id}"));
        $this->assertSame([$target->nomination_number], $this->found("residence_area_id={$target->residence_area_id}"));
        $this->assertSame([$target->nomination_number], $this->found("job_position_id={$target->job_position_id}"));
        $this->assertSame([$target->nomination_number], $this->found("birth_place_id={$aleppo->id}"));
        $this->assertSame([$other->nomination_number], $this->found("marital_status_id={$other->marital_status_id}&residence_district_id={$other->residence_district_id}"));
    }

    public function test_ages_and_birth_dates(): void
    {
        $this->open('11111111111', ['candidate' => ['birth_date' => '2000-10-30']]);   // 26 today
        $this->open('22222222222', ['candidate' => ['birth_date' => '2000-10-31']]);   // 25 until tomorrow
        $this->open('33333333333', ['candidate' => ['birth_date' => '1980-01-01']]);   // 46

        $this->assertSame(2, $this->total('age_min=26'));
        $this->assertSame(2, $this->total('age_max=25') + $this->total('age_min=40&age_max=50'));
        $this->assertSame(1, $this->total('age_max=25'));
        $this->assertSame(2, $this->total('age_min=25&age_max=26'));
        $this->assertSame(1, $this->total('birth_date_from=1970-01-01&birth_date_to=1990-12-31'));
        $this->asStaff($this->hiringStaff())->getJson(self::URL . '?birth_date_from=2000-01-01&birth_date_to=1999-01-01')->assertStatus(422);
    }

    public function test_numbers_people_and_who_opened_it(): void
    {
        $staff = $this->hiringStaff();
        $mine = $this->openApplication($staff, ['candidate' => ['national_number' => '11111111111'], 'phone_number' => '0933111222', 'existing_employee_number' => '4455']);
        $this->open('22222222222');
        $inactive = $this->open('33333333333');
        $this->closeApplication($inactive);
        DB::table('candidates')->where('id', $inactive->candidate_id)->update(['is_active' => false, 'deactivated_at' => now(), 'deactivated_by' => $staff->id, 'deactivation_reason' => 'سبب']);

        $this->assertSame([$mine->nomination_number], $this->found('phone_number=' . urlencode('٠٩٣٣ ١١١ ٢٢٢')));
        $this->assertSame([$mine->nomination_number], $this->found('existing_employee_number=٤٤٥٥'));
        $this->assertSame([$mine->nomination_number], $this->found('has_employee_number=1'));
        $this->assertSame(2, $this->total('has_employee_number=0'));
        $this->assertSame([$mine->nomination_number], $this->found("created_by={$staff->id}"));
        $this->assertSame([$inactive->nomination_number], $this->found('candidate_active=0'));
    }

    public function test_sorting_and_page_size(): void
    {
        $this->open('11111111111', ['candidate' => ['full_name' => 'يوسف علي']]);
        $this->travel(1)->minutes();
        $this->open('22222222222', ['candidate' => ['full_name' => 'أحمد سالم']]);
        $this->travel(1)->minutes();
        $this->open('33333333333', ['candidate' => ['full_name' => 'سارة خالد']]);

        $names = fn(string $query) => array_column(array_column($this->asStaff($this->hiringStaff())->getJson(self::URL . '?' . $query)->json('data'), 'candidate'), 'full_name');

        $this->assertSame(['سارة خالد', 'أحمد سالم', 'يوسف علي'], $names(''));                                   // newest first
        $this->assertSame(['أحمد سالم', 'سارة خالد', 'يوسف علي'], $names('sort=full_name'));
        $this->assertSame(['يوسف علي', 'سارة خالد', 'أحمد سالم'], $names('sort=full_name&direction=desc'));
        $this->assertSame(['يوسف علي', 'أحمد سالم', 'سارة خالد'], $names('sort=nomination_number'));

        $page = $this->asStaff($this->hiringStaff())->getJson(self::URL . '?per_page=5&sort=full_name')->assertOk();
        $this->assertSame(5, $page->json('meta.per_page'));
        $this->assertStringContainsString('sort=full_name', $page->json('links.first'));   // the filters stay in the page links
        $this->asStaff($this->hiringStaff())->getJson(self::URL . '?per_page=500')->assertStatus(422);
        $this->asStaff($this->hiringStaff())->getJson(self::URL . '?sort=password')->assertStatus(422);
    }

    public function test_a_page_costs_the_same_number_of_queries_whatever_its_size(): void
    {
        foreach (range(1, 8) as $i) {
            $this->open(sprintf('%011d', $i));
        }

        $staff = $this->hiringStaff();
        $queries = function (int $perPage) use ($staff) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->asStaff($staff)->getJson(self::URL . "?per_page={$perPage}")->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $queries(5);   // warm up
        $this->assertSame($queries(5), $queries(8));
    }
}
