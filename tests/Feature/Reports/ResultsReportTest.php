<?php

namespace Tests\Feature\Reports;

use App\Modules\Identity\Database\Factories\StaffUserFactory;
use App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Interviews\Actions\ManageInterviews;
use App\Modules\Interviews\Actions\ManageSittings;
use App\Modules\Recruitment\Actions\ApplicationLifecycle;
use App\Modules\Recruitment\Enums\ApplicationStatus as S;
use App\Modules\Recruitment\Enums\DecisionType as D;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Reports\Queries\CandidateResults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\Support\RecruitmentTestHelpers;
use Tests\Support\StaffTestHelpers;
use Tests\TestCase;

/** The results report for candidates: a PDF statement and an Excel / PDF list, without notes or reasons (D-066). */
class ResultsReportTest extends TestCase
{
    use RecruitmentTestHelpers, RefreshDatabase, StaffTestHelpers;

    private const NOTE = 'ملاحظة سرية للجنة';

    private const REASON = 'سبب الاستثناء الداخلي';

    private StaffUser $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->staff = $this->hiringStaff();
    }

    /** Passed by exception, then interviewed: passed with grades, a note and a summary the candidate must not see. */
    private function interviewed(string $number = '01234567890'): Application
    {
        $this->travelTo('2026-10-01 09:00:00');
        $application = $this->openApplication(null, ['candidate' => ['national_number' => $number, 'full_name' => 'سامر خالد']]);
        $lifecycle = app(ApplicationLifecycle::class);
        $lifecycle->move($application, S::ExamFailed, D::Outcome);
        $this->travelTo('2026-10-02 09:00:00');
        $lifecycle->move($application, S::AwaitingInterview, D::Exception, $this->staff, self::REASON);

        $this->travelTo('2026-10-10 09:00:00');
        $sitting = app(ManageSittings::class)->create(['starts_at' => '2026-10-10 08:00:00', 'location' => 'القاعة 2', 'committee_name' => 'لجنة'], [['name' => 'رئيس', 'capacity' => 'chair']], $this->staff);
        $interviews = app(ManageInterviews::class);
        $interview = $interviews->schedule($application, ['sitting_id' => $sitting->id], $this->staff);
        $interviews->record($interview, ['result' => 'passed', 'scientific_grade' => 'excellent', 'general_grade' => 'good', 'overall_grade' => 'very_good', 'notes' => self::NOTE, 'committee_summary' => self::NOTE], $this->staff);
        $this->travelTo('2026-10-31 10:00:00');

        return $application->fresh();
    }

    private function pdfText(TestResponse $response): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf-test-');
        file_put_contents($path, $response->getContent());
        $text = (string) shell_exec('pdftotext -enc UTF-8 '.escapeshellarg($path).' -');
        unlink($path);

        return $text;
    }

    /** The number next to a label in the totals. */
    private function total(Worksheet $sheet, string $label): mixed
    {
        foreach ($sheet->getRowIterator() as $row) {
            if ($sheet->getCell([1, $row->getRowIndex()])->getValue() === $label) {
                return $sheet->getCell([2, $row->getRowIndex()])->getValue();
            }
        }

        return null;
    }

    public function test_a_statement_shows_results_and_grades_but_never_notes_or_reasons(): void
    {
        $application = $this->interviewed();
        Log::spy();

        $response = $this->asStaff($this->staff)->get("/api/v1/staff/applications/{$application->id}/results-statement")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString(rawurlencode("{$application->nomination_number}.pdf"), $response->headers->get('Content-Disposition'));

        $text = $this->pdfText($response);
        if (getenv('KEEP_REPORT_PDF')) {
            file_put_contents(getenv('KEEP_REPORT_PDF'), $response->getContent());
        }
        $this->assertStringContainsString($application->nomination_number, $text);
        $this->assertStringContainsString('01234567890', $text);
        $this->assertStringContainsString('2026-10-10', $text);                                         // the interview date
        Log::shouldHaveReceived('notice')->withArgs(fn (string $event) => $event === 'report.results_statement')->once();

        // What the statement is built from (the PDF text of Arabic is shaped, so the content is checked here).
        $result = CandidateResults::of(collect([$application->load(CandidateResults::RELATIONS)]))[0];
        $this->assertSame(['result' => 'failed', 'label' => 'راسب', 'decided_on' => '2026-10-01', 'admitted_by_exception' => true], $result['exam']);
        $this->assertSame('ممتاز', $result['interview']['grades']['scientific']['label']);
        $shown = json_encode(array_diff_key($result, ['application' => true]), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString(self::NOTE, $shown);
        $this->assertStringNotContainsString(self::REASON, $shown);
    }

    public function test_the_list_in_excel_has_the_header_the_rows_and_the_totals(): void
    {
        $application = $this->interviewed('11111111111');
        $this->openApplication(null, ['candidate' => ['national_number' => '22222222222']]);   // no result yet

        $response = $this->asStaff($this->staff)->get('/api/v1/staff/reports/results?sort=nomination_number')->assertOk();
        $sheet = IOFactory::load($response->getFile()->getPathname())->getActiveSheet();
        $cells = collect($sheet->toArray())->flatten()->filter()->map(fn ($value) => (string) $value)->values()->all();

        $this->assertTrue($sheet->getRightToLeft());
        $this->assertStringContainsString('نتائج الاختبارات والمقابلات', (string) $sheet->getCell('A1')->getValue());
        $this->assertStringContainsString($this->staff->full_name, (string) $sheet->getCell('A2')->getValue());
        $this->assertSame('رقم الترشيح', $sheet->getCell('A5')->getValue());
        $this->assertSame($application->nomination_number, $sheet->getCell('A6')->getValue());
        $this->assertSame('11111111111', $sheet->getCell('C6')->getValue());                          // text: leading zeros survive
        $this->assertSame(['راسب', 'نعم', 'ناجح', '2026-10-10', 'ممتاز', 'جيد', 'جيد جداً'], [
            $sheet->getCell('F6')->getValue(), $sheet->getCell('G6')->getValue(), $sheet->getCell('H6')->getValue(), $sheet->getCell('I6')->getValue(),
            $sheet->getCell('J6')->getValue(), $sheet->getCell('K6')->getValue(), $sheet->getCell('L6')->getValue(),
        ]);
        $this->assertContains('عدد الطلبات', $cells);
        $this->assertSame(2, $this->total($sheet, 'عدد الطلبات'));                                     // the totals count the rows
        $this->assertSame(1, $this->total($sheet, 'المقابلة: ناجح'));
        $this->assertSame(1, $this->total($sheet, 'الاختبار: راسب'));
        $this->assertNotContains(self::NOTE, $cells);
        $this->assertNotContains(self::REASON, $cells);
    }

    public function test_the_list_takes_the_application_filters_and_prints_them(): void
    {
        $this->interviewed('11111111111');
        $this->openApplication(null, ['candidate' => ['national_number' => '22222222222']]);

        $response = $this->asStaff($this->staff)->get('/api/v1/staff/reports/results?status=awaiting_contract')->assertOk();
        $sheet = IOFactory::load($response->getFile()->getPathname())->getActiveSheet();

        $this->assertStringContainsString('ناجح بالمقابلة بانتظار كتابة العقد', (string) $sheet->getCell('A3')->getValue());   // the filter, readable
        $this->assertSame(1, $this->total($sheet, 'عدد الطلبات'));

        $pdf = $this->asStaff($this->staff)->get('/api/v1/staff/reports/results?format=pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->asStaff($this->staff)->getJson('/api/v1/staff/reports/results?format=docx')->assertStatus(422);
    }

    public function test_too_many_rows_for_a_format_are_refused(): void
    {
        config(['reports.max_rows.pdf' => 1]);
        $this->openApplication(null, ['candidate' => ['national_number' => '11111111111']]);
        $this->openApplication(null, ['candidate' => ['national_number' => '22222222222']]);

        $this->asStaff($this->staff)->getJson('/api/v1/staff/reports/results?format=pdf')->assertStatus(422)->assertJsonValidationErrors('format');
    }

    public function test_permissions(): void
    {
        $application = $this->interviewed();

        $this->asStaff(StaffUserFactory::new()->withRole('general_manager')->create())->get('/api/v1/staff/reports/results')->assertOk();
        $this->asStaff(StaffUserFactory::new()->create())->getJson("/api/v1/staff/applications/{$application->id}/results-statement")->assertForbidden();
    }
}
