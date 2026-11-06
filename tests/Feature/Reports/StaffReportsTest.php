<?php

namespace Tests\Feature\Reports;

use App\Modules\Contracts\Actions\ManageContracts;
use App\Modules\Contracts\Models\ContractType;
use App\Modules\Identity\Database\Factories\StaffUserFactory;
use App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Interviews\Actions\ManageInterviews;
use App\Modules\Interviews\Actions\ManageSittings;
use App\Modules\Recruitment\Actions\ApplicationLifecycle;
use App\Modules\Recruitment\Enums\ApplicationStatus as S;
use App\Modules\Recruitment\Enums\DecisionType as D;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\NominatingEntity;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\Support\RecruitmentTestHelpers;
use Tests\Support\StaffTestHelpers;
use Tests\TestCase;

/**
 * The dashboard and the staff's reports (D-068) and the list for شعبة التجنيد (D-069), on one story:
 *  - A: nominated 1 March, failed the exam 10 March, admitted by an exception 12 March, passed the interview 5 April,
 *    first contract written 20 April;
 *  - B: nominated 2 March, absent from the exam 10 March, excuse accepted 15 March, passed the exam 3 May, absent
 *    from the interview 10 May, excuse accepted 12 May;
 *  - C: nominated 5 March, passed the exam 10 March, failed the interview held 31 March at 22:30 UTC, which is
 *    1 April in Damascus;
 *  - D: nominated 1 May, by another nominating entity, waiting for the exam.
 */
class StaffReportsTest extends TestCase
{
    use RecruitmentTestHelpers, RefreshDatabase, StaffTestHelpers;

    private StaffUser $staff;

    /** @var array<string, Application> */
    private array $people = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->staff = $this->hiringStaff();
        $this->story();
    }

    private function at(string $moment): CarbonInterface
    {
        $this->travelTo($moment);

        return Date::now();
    }

    private function story(): void
    {
        $lifecycle = app(ApplicationLifecycle::class);
        $interviews = app(ManageInterviews::class);
        $sittings = app(ManageSittings::class);
        $other = NominatingEntity::create(['name_ar' => 'مركز التحفيظ']);
        $open = function (string $key, string $moment, string $number, array $overrides = []) {
            $this->at($moment);
            $this->people[$key] = $this->openApplication($this->staff, array_replace_recursive(['candidate' => ['national_number' => $number, 'full_name' => "المرشح {$key} الأول"]], $overrides));
        };

        $open('A', '2026-03-01 09:00:00', '01111111111');
        $open('B', '2026-03-02 09:00:00', '02222222222', ['studies_discontinued' => true, 'studying_years' => 3]);
        $open('C', '2026-03-05 09:00:00', '03333333333');

        $lifecycle->move($this->people['A'], S::ExamFailed, D::Outcome, null, null, null, $this->at('2026-03-10 09:00:00'));
        $lifecycle->move($this->people['B'], S::ExamAbsent, D::Outcome, null, null, null, Date::now());
        $lifecycle->move($this->people['C'], S::AwaitingInterview, D::Outcome, null, null, null, Date::now());
        $lifecycle->move($this->people['A'], S::AwaitingInterview, D::Exception, $this->staff, 'قرار اللجنة العليا', null, $this->at('2026-03-12 09:00:00'));
        $lifecycle->move($this->people['B'], S::AwaitingExam, D::ExcuseAccepted, $this->staff, 'مرض موثق', null, $this->at('2026-03-15 09:00:00'));

        $this->at('2026-03-31 23:00:00');
        $late = $sittings->create(['starts_at' => '2026-03-31 22:00:00', 'location' => 'القاعة 1', 'committee_name' => 'لجنة آذار'], [['name' => 'رئيس', 'capacity' => 'chair']], $this->staff);
        $interview = $interviews->schedule($this->people['C'], ['sitting_id' => $late->id, 'scheduled_at' => '2026-03-31 22:30:00'], $this->staff);
        $interviews->record($interview, ['result' => 'failed', 'held_at' => '2026-03-31 22:30:00', 'scientific_grade' => 'weak', 'general_grade' => 'average', 'overall_grade' => 'weak', 'notes' => 'ضعف علمي'], $this->staff);

        $this->at('2026-04-05 12:00:00');
        $april = $sittings->create(['starts_at' => '2026-04-05 08:00:00', 'location' => 'القاعة 2', 'committee_name' => 'لجنة نيسان'], [['name' => 'رئيس', 'capacity' => 'chair']], $this->staff);
        $interview = $interviews->schedule($this->people['A'], ['sitting_id' => $april->id, 'scheduled_at' => '2026-04-05 09:00:00'], $this->staff);
        $interviews->record($interview, ['result' => 'passed', 'held_at' => '2026-04-05 09:00:00', 'scientific_grade' => 'good', 'general_grade' => 'good', 'overall_grade' => 'good'], $this->staff);

        $this->at('2026-04-20 10:00:00');
        app(ManageContracts::class)->write($this->people['A']->fresh(), [
            'contract_type_id' => ContractType::create(['name_ar' => 'عقد سنوي'])->id,
            'reference_number' => 'R-1', 'written_on' => '2026-04-20', 'starts_on' => '2026-04-21', 'ends_on' => '2027-04-20',
        ], $this->staff);

        $open('D', '2026-05-01 09:00:00', '04444444444', ['nominating_entity_id' => $other->id]);
        $lifecycle->move($this->people['B'], S::AwaitingInterview, D::Outcome, null, null, null, $this->at('2026-05-03 09:00:00'));

        $this->at('2026-05-10 12:00:00');
        $may = $sittings->create(['starts_at' => '2026-05-10 08:00:00', 'location' => 'القاعة 3', 'committee_name' => 'لجنة أيار'], [['name' => 'رئيس', 'capacity' => 'chair']], $this->staff);
        $interview = $interviews->schedule($this->people['B'], ['sitting_id' => $may->id], $this->staff);
        $interviews->record($interview, ['result' => 'absent', 'held_at' => '2026-05-10 08:00:00', 'notes' => 'لم يحضر'], $this->staff);
        $interviews->acceptExcuse($this->people['B'], 'حادث سير', $this->at('2026-05-12 09:00:00'), null, $this->staff);

        $this->at('2026-06-01 09:00:00');
    }

    private function summary(array $query = []): TestResponse
    {
        return $this->asStaff($this->staff)->getJson('/api/v1/staff/reports/summary?'.http_build_query($query))->assertOk();
    }

    private function sheet(TestResponse $response, int $index = 0): Worksheet
    {
        $response->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        return IOFactory::load($response->getFile()->getPathname())->getSheet($index);
    }

    /** @return list<list<?string>> the data rows (from line 6) of a one-table sheet, until the first empty line */
    private function rows(Worksheet $sheet, int $from = 6): array
    {
        $rows = [];

        for ($line = $from; $sheet->getCell([1, $line])->getValue() !== null; $line++) {
            $rows[] = array_map(fn ($value) => $value === null ? null : (string) $value, $sheet->rangeToArray("A{$line}:{$sheet->getHighestColumn()}{$line}", null, false, false)[0]);
        }

        return $rows;
    }

    private function total(Worksheet $sheet, string $label): mixed
    {
        foreach ($sheet->getRowIterator() as $row) {
            if ($sheet->getCell([1, $row->getRowIndex()])->getValue() === $label) {
                return $sheet->getCell([2, $row->getRowIndex()])->getValue();
            }
        }

        return null;
    }

    // ---- dashboard ----

    public function test_the_dashboard_counts_each_event_on_its_own_date(): void
    {
        $this->summary()
            ->assertJsonPath('data.period', ['from' => null, 'to' => null])
            ->assertJsonPath('data.totals.nominations', 4)
            ->assertJsonPath('data.totals.exams', ['takers' => 3, 'passed' => 2, 'passed_by_exception' => 1, 'failed' => 1, 'failed_then_excepted' => 1, 'absent' => 1, 'absent_excused' => 1, 'pass_rate_percent' => 66.7])
            ->assertJsonPath('data.totals.interviews', ['takers' => 2, 'passed' => 1, 'failed' => 1, 'absent' => 1, 'absent_excused' => 1, 'pass_rate_percent' => 50])
            ->assertJsonPath('data.totals.contracts', ['written' => 1, 'renewals' => 0])
            ->assertJsonPath('data.totals.average_days.to_exam', 7.3)              // 9, 8 and 5 days: the first decision of each
            ->assertJsonPath('data.totals.average_days.to_interview', 35)
            ->assertJsonPath('data.totals.average_days.to_contract', 49.6)        // 1 March 09:00 UTC to 20 April
            ->assertJsonCount(4, 'data.monthly')                                  // from the first nomination to this month
            ->assertJsonPath('data.monthly.0', ['month' => '2026-03', 'nominations' => 3, 'exam_takers' => 2, 'interview_takers' => 0, 'contracts_written' => 0])
            ->assertJsonPath('data.monthly.1', ['month' => '2026-04', 'nominations' => 0, 'exam_takers' => 0, 'interview_takers' => 2, 'contracts_written' => 1])
            ->assertJsonPath('data.by_nominating_entity.0', ['id' => $this->people['A']->nominating_entity_id, 'name' => 'مديرية أوقاف دمشق', 'nominations' => 3, 'exam_passed' => 2, 'exam_passed_by_exception' => 1, 'interview_passed' => 1, 'contracts_written' => 1])
            ->assertJsonPath('data.by_nominating_entity.1.nominations', 1)
            ->assertJsonPath('data.by_job_position.0.nominations', 4);
    }

    public function test_a_period_counts_what_happened_in_it_in_damascus_time(): void
    {
        // March: the late interview of 31 March 22:30 UTC was on 1 April in Damascus.
        $this->summary(['year' => 2026, 'month' => 3])
            ->assertJsonPath('data.period', ['from' => '2026-03-01', 'to' => '2026-03-31'])
            ->assertJsonPath('data.totals.nominations', 3)
            ->assertJsonPath('data.totals.exams.takers', 2)
            ->assertJsonPath('data.totals.exams.passed_by_exception', 1)
            ->assertJsonPath('data.totals.exams.absent_excused', 1)
            ->assertJsonPath('data.totals.interviews.takers', 0)
            ->assertJsonCount(1, 'data.monthly');

        // May: B passed the exam and was absent from the interview; D was nominated.
        $this->summary(['period_from' => '2026-05-01', 'period_to' => '2026-05-31'])
            ->assertJsonPath('data.totals.nominations', 1)
            ->assertJsonPath('data.totals.exams.passed', 1)
            ->assertJsonPath('data.totals.exams.absent', 0)
            ->assertJsonPath('data.totals.interviews.absent', 1)
            ->assertJsonPath('data.totals.interviews.absent_excused', 1);

        // A year gives its twelve months.
        $this->summary(['year' => 2026])->assertJsonCount(12, 'data.monthly')->assertJsonPath('data.monthly.4.month', '2026-05');
    }

    public function test_the_dashboard_takes_the_applications_filters(): void
    {
        $this->summary(['nominating_entity_id' => $this->people['D']->nominating_entity_id])
            ->assertJsonPath('data.totals.nominations', 1)
            ->assertJsonPath('data.totals.exams.takers', 0)
            ->assertJsonPath('data.totals.exams.pass_rate_percent', null)
            ->assertJsonPath('data.filters', ['الجهة المرشِّحة' => 'مركز التحفيظ']);
    }

    public function test_the_period_is_checked(): void
    {
        $url = '/api/v1/staff/reports/summary';

        $this->asStaff($this->staff)->getJson("{$url}?month=3")->assertStatus(422)->assertJsonValidationErrors('year');
        $this->asStaff($this->staff)->getJson("{$url}?period_from=2026-05-01&period_to=2026-04-01")->assertStatus(422)->assertJsonValidationErrors('period_to');
        $this->asStaff($this->staff)->getJson("{$url}?year=2025&period_from=2026-05-01")->assertStatus(422)->assertJsonPath('errors.period_from.0', __('reports.empty_period'));
    }

    // ---- exports ----

    public function test_the_exams_report_lists_each_decision_with_what_followed(): void
    {
        Log::spy();
        $sheet = $this->sheet($this->asStaff($this->staff)->get('/api/v1/staff/reports/exams'));

        $this->assertTrue($sheet->getRightToLeft());
        $this->assertSame('وزارة الدفاع السورية', $sheet->getCell('A1')->getValue());
        $this->assertSame('سجل الاختبارات ونتائجها', $sheet->getCell('A3')->getValue());
        $this->assertStringContainsString($this->staff->full_name, (string) $sheet->getCell('A4')->getValue());
        $this->assertSame('رقم الترشيح', $sheet->getCell('A5')->getValue());

        $rows = $this->rows($sheet);
        $this->assertCount(4, $rows);
        $this->assertSame([$this->people['A']->nomination_number, 'المرشح A الأول'], array_slice($rows[0], 0, 2));
        $this->assertSame(['1', 'حاضر', '2026-03-10', 'راسب', 'قُبل باستثناء في 2026-03-12', 'قرار اللجنة العليا'], array_slice($rows[0], 5));
        $this->assertSame(['1', 'غائب', '2026-03-10', 'غائب', 'قُبل العذر في 2026-03-15', 'مرض موثق'], array_slice($rows[1], 5));
        $this->assertSame(['2', 'حاضر', '2026-05-03', 'ناجح', null, null], array_slice($rows[3], 5));   // B's second time
        $this->assertSame(4, $this->total($sheet, 'عدد القرارات'));
        $this->assertSame(1, $this->total($sheet, 'قُبلوا باستثناء'));
        Log::shouldHaveReceived('notice')->withArgs(fn (string $event, array $context) => $event === 'report.exported' && $context['report'] === 'exams' && $context['rows'] === 4)->once();
    }

    public function test_the_exam_views(): void
    {
        $passed = $this->rows($this->sheet($this->asStaff($this->staff)->get('/api/v1/staff/reports/exams-passed')));
        $this->assertSame([$this->people['A']->nomination_number, $this->people['C']->nomination_number, $this->people['B']->nomination_number], array_column($passed, 0));
        $this->assertSame(['باستثناء (2026-03-12)', 'قرار اللجنة العليا'], array_slice($passed[0], 9));
        $this->assertSame('نظامي', $passed[1][9]);

        $this->assertCount(1, $this->rows($this->sheet($this->asStaff($this->staff)->get('/api/v1/staff/reports/exams-failed'))));
        $absent = $this->rows($this->sheet($this->asStaff($this->staff)->get('/api/v1/staff/reports/exams-absent?year=2026&month=3')));
        $this->assertSame(['قُبل العذر في 2026-03-15', 'مرض موثق'], array_slice($absent[0], 9));
        $this->assertCount(0, $this->rows($this->sheet($this->asStaff($this->staff)->get('/api/v1/staff/reports/exams-absent?year=2026&month=5'))));
    }

    public function test_the_interview_reports(): void
    {
        $all = $this->sheet($this->asStaff($this->staff)->get('/api/v1/staff/reports/interviews'));
        $rows = $this->rows($all);
        $this->assertSame([$this->people['C']->nomination_number, $this->people['A']->nomination_number, $this->people['B']->nomination_number], array_column($rows, 0));
        $this->assertSame(['لجنة آذار', 'رئيس (رئيس اللجنة)', 'القاعة 1', '2026-04-01 01:30', 'سُجّلت النتيجة', '2026-04-01', 'حاضر', 'ضعيف', 'وسط', 'راسب', 'ضعيف', 'ضعف علمي', null], array_slice($rows[0], 6));
        $this->assertSame('راسب (ثم استثناء)', $rows[1][4]);                      // A's exam
        $this->assertSame(1, $this->total($all, 'المقابلة: ناجح'));

        $absent = $this->rows($this->sheet($this->asStaff($this->staff)->get('/api/v1/staff/reports/interviews-absent')));
        $this->assertCount(1, $absent);
        $this->assertSame(['لم يحضر', 'قُبل العذر في 2026-05-12'], array_slice($absent[0], -2));

        $this->assertCount(0, $this->rows($this->sheet($this->asStaff($this->staff)->get('/api/v1/staff/reports/interviews-passed?year=2026&month=3'))));
        $this->assertCount(1, $this->rows($this->sheet($this->asStaff($this->staff)->get('/api/v1/staff/reports/interviews-failed?year=2026&month=4'))));
    }

    public function test_the_applications_report_has_every_field_of_the_template(): void
    {
        $sheet = $this->sheet($this->asStaff($this->staff)->get('/api/v1/staff/reports/applications'));
        $rows = $this->rows($sheet);

        $this->assertSame('الرقم الوطني', $sheet->getCell('J5')->getValue());
        $this->assertCount(4, $rows);
        $this->assertSame('02222222222', $rows[1][9]);                             // text: the leading zero stays
        $this->assertSame('3 ومنقطع', $rows[1][16]);
        $this->assertSame(['نعم', 'نعم'], [$rows[0][19], $rows[0][20]]);
        $this->assertSame(['2026-03-01', 'تمت كتابة العقد الأول'], array_slice($rows[0], -2));

        // With a period: the applications nominated in it.
        $this->assertCount(1, $this->rows($this->sheet($this->asStaff($this->staff)->get('/api/v1/staff/reports/applications?year=2026&month=5'))));
    }

    public function test_reports_as_pdf_and_the_dashboard_as_tables(): void
    {
        foreach (['dashboard', 'applications', 'interviews', 'exams-failed'] as $report) {
            $response = $this->asStaff($this->staff)->get("/api/v1/staff/reports/{$report}?format=pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $this->assertStringStartsWith('%PDF', $response->getContent());
        }

        $dashboard = $this->sheet($this->asStaff($this->staff)->get('/api/v1/staff/reports/dashboard?year=2026'));
        $this->assertSame('المؤشرات', $dashboard->getCell('A6')->getValue());
        $this->assertSame(['عدد المتقدمين', 4], [$dashboard->getCell('A8')->getValue(), $dashboard->getCell('B8')->getValue()]);
        $this->assertSame(66.7, $this->total($dashboard, 'معدل النجاح في الاختبارات (%)'));
    }

    public function test_the_whole_workbook_links_its_sheets(): void
    {
        $response = $this->asStaff($this->staff)->get('/api/v1/staff/reports/workbook')->assertOk();
        $book = IOFactory::load($response->getFile()->getPathname());

        $this->assertSame([
            'لوحة التحكم والتقارير', 'قاعدة بيانات المرشحين', 'سجل الاختبارات ونتائجها', 'الناجحون في الاختبارات', 'الراسبون في الاختبارات',
            'الغائبون عن الاختبارات', 'سجل المقابلات', 'الناجحون في المقابلات', 'الراسبون في المقابلات', 'الغائبون عن المقابلات',
        ], $book->getSheetNames());

        $first = $book->getSheet(0);
        $links = collect($first->getHyperlinkCollection())->map(fn ($link) => $link->getUrl())->values()->all();
        $this->assertCount(9, $links);
        $this->assertContains("sheet://'سجل المقابلات'!A1", $links);
    }

    public function test_too_many_rows_are_refused(): void
    {
        config(['reports.max_rows.pdf' => 2]);

        $this->asStaff($this->staff)->getJson('/api/v1/staff/reports/exams?format=pdf')->assertStatus(422)->assertJsonValidationErrors('format');
        $this->asStaff($this->staff)->getJson('/api/v1/staff/reports/exams-failed?format=pdf')->assertOk();
        $this->asStaff($this->staff)->getJson('/api/v1/staff/reports/unknown')->assertNotFound();
    }

    // ---- the list for شعبة التجنيد ----

    public function test_the_conscription_list_follows_the_template(): void
    {
        $this->at('2026-06-01 10:00:00');
        $waiting = $this->openApplication($this->staff, ['candidate' => ['national_number' => '05555555555', 'full_name' => 'سامر خالد الأحمد', 'mother_name' => 'مريم', 'birth_date' => '1995-10-13']]);
        app(ApplicationLifecycle::class)->move($waiting, S::AwaitingInterview, D::Outcome);
        app(ApplicationLifecycle::class)->move($waiting, S::AwaitingContract, D::Outcome);

        $sheet = $this->sheet($this->asStaff($this->staff)->get('/api/v1/staff/reports/conscription-list'));

        $this->assertTrue($sheet->getRightToLeft());
        $this->assertSame(['الاسم الثلاثي  في الهوية', 'اسم الام والنسبة', 'تاريخ الميلاد', 'الحالة الاجتماعية', 'التحصيل العلمي', 'شعبة التجنيد', 'مسمى العمل', 'الجهة العسكرية', 'ملاحظات'], $sheet->rangeToArray('A4:I4')[0]);
        $this->assertSame(['سامر خالد الأحمد', 'مريم', '13/10/1995', 'أعزب', 'إجازة جامعية', 'دمشق', 'مدرس', 'التوجيه المعنوي', null], $sheet->rangeToArray('A5:I5')[0]);
        $this->assertNull($sheet->getCell('A6')->getValue());                    // only who passed the interview
        $this->assertStringContainsString('قائمة شعبة التجنيد', (string) $sheet->getCell('A1')->getValue());

        $other = $this->sheet($this->asStaff($this->staff)->get('/api/v1/staff/reports/conscription-list?status=contract_written'));
        $this->assertSame('المرشح A الأول', $other->getCell('A5')->getValue());
    }

    public function test_permissions(): void
    {
        $manager = StaffUserFactory::new()->withRole('general_manager')->create();   // reports.view

        $this->asStaff($manager)->getJson('/api/v1/staff/reports/summary')->assertOk();
        $this->asStaff($manager)->get('/api/v1/staff/reports/exams')->assertOk();
        $this->asStaff($manager)->getJson('/api/v1/staff/reports/conscription-list')->assertForbidden();
        $this->asStaff(StaffUserFactory::new()->create())->getJson('/api/v1/staff/reports/summary')->assertForbidden();
    }
}
