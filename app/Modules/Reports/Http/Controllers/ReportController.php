<?php

namespace App\Modules\Reports\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Interviews\Models\Interview;
use App\Modules\Recruitment\Enums\ApplicationStatus;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Queries\ApplicationSearch;
use App\Modules\Reports\Exports\ConscriptionList;
use App\Modules\Reports\Exports\Pdf;
use App\Modules\Reports\Exports\ReportHeader;
use App\Modules\Reports\Exports\ReportWorkbook;
use App\Modules\Reports\Queries\Dashboard;
use App\Modules\Reports\Support\ReportFilters;
use App\Modules\Reports\Support\ReportPeriod;
use App\Modules\Reports\Support\ReportTable;
use App\Modules\Reports\Tables\RecruitmentTables;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The dashboard and the staff's reports (legacy workbook "نظام التوظيف والاختبارات والمقابلات"; analysis doc §16;
 * D-068). Every report takes the filters of the applications list (which applications) and a period (`year` with an
 * optional `month`, and/or `period_from` / `period_to`; each report says which date it compares). Exports carry the
 * title, period, date, issuer and filters, and their totals count the same rows; every export is audited. Reading:
 * reports.view, candidates.manage or interviews.manage.
 */
class ReportController extends Controller
{
    /** report => [table builder, view] */
    private const REPORTS = [
        'applications' => ['applications', null],
        'exams' => ['exams', 'all'],
        'exams-passed' => ['exams', 'passed'],
        'exams-failed' => ['exams', 'failed'],
        'exams-absent' => ['exams', 'absent'],
        'interviews' => ['interviews', 'all'],
        'interviews-passed' => ['interviews', 'passed'],
        'interviews-failed' => ['interviews', 'failed'],
        'interviews-absent' => ['interviews', 'absent'],
        'dashboard' => ['dashboard', null],
    ];

    /**
     * Dashboard indicators
     *
     * As the legacy "لوحة التحكم": nominations; exam takers, passes (and passes by exception apart), failures (with
     * those later admitted by an exception), absences (with accepted excuses) and the pass rate; the same for
     * interviews; first contracts and renewals; average days from nomination to each stage; the counts by month (the
     * period's months, or from the first nomination when no period is given; at most 60), by nominating entity and
     * by job position. Each is counted on its own date: a nomination by its date, an exam decision by its date, an
     * interview by the day held, a contract by its writing date.
     */
    public function summary(Request $request): JsonResponse
    {
        $filters = ReportFilters::validate($request);

        return response()->json(['data' => Dashboard::build($filters) + ['filters' => ReportFilters::describe($filters)]]);
    }

    /**
     * Export a report (Excel or PDF)
     *
     * `report`: applications (بيانات المرشحين: one row per application, every field; with a period, those nominated
     * in it), exams (every exam decision in the period, with the exception or accepted excuse that followed),
     * exams-passed (regular passes and failures admitted by an exception), exams-failed, exams-absent, interviews
     * (every interview dated in the period: held, or else booked), interviews-passed, interviews-failed,
     * interviews-absent (recorded results by the day held), dashboard (the indicators as tables). `format`: xlsx
     * (default) or pdf. Interviews also take `sitting_id`. Sorted by nomination number unless `sort` is sent. At most
     * 20,000 rows in Excel and 2,000 in PDF (422 on `format` beyond: narrow the filters).
     */
    public function export(Request $request, string $report): BinaryFileResponse|Response
    {
        abort_unless(isset(self::REPORTS[$report]), 404);
        $filters = ReportFilters::validate($request, ['format' => ['nullable', Rule::in(['xlsx', 'pdf'])], 'sitting_id' => ['nullable', 'uuid']]);
        $format = $filters['format'] ?? 'xlsx';
        $tables = $this->tables($report, $filters);

        foreach ($tables as $table) {
            $this->assertFits($table, $format);
        }

        $title = $report === 'dashboard' ? __('reports.tables.dashboard', [], 'ar') : $tables[0]->title;
        $header = $this->header($title, $request, $filters);
        AuditTrail::record('report.exported', $request->user(), ['report' => $report, 'format' => $format, 'rows' => array_sum(array_map(fn(ReportTable $table) => $table->count(), $tables)), 'filters' => $filters]);

        return $this->send($tables, $header, $format, $title);
    }

    /**
     * The whole workbook (Excel)
     *
     * Every report above in one workbook, as the legacy file: the dashboard (with links to the other sheets),
     * بيانات المرشحين, the exams and their three views, the interviews and their three views. Same filters and period.
     */
    public function workbook(Request $request): BinaryFileResponse
    {
        $filters = ReportFilters::validate($request);
        $sheets = [];

        foreach (['dashboard', ...array_diff(array_keys(self::REPORTS), ['dashboard'])] as $report) {
            $tables = $this->tables($report, $filters);

            foreach ($tables as $table) {
                $this->assertFits($table, 'xlsx');
            }

            $title = $report === 'dashboard' ? __('reports.tables.dashboard', [], 'ar') : $tables[0]->title;
            $sheets[] = ['name' => $title, 'title' => $title, 'tables' => $tables];
        }

        $title = __('reports.workbook_title', [], 'ar');
        $header = $this->header($title, $request, $filters);
        AuditTrail::record('report.workbook', $request->user(), ['filters' => $filters]);

        return $this->download(ReportWorkbook::write($sheets, $header), "{$title} {$header->generatedAt->toDateString()}.xlsx");
    }

    /**
     * The list for شعبة التجنيد (Excel)
     *
     * In the layout of its template: the full name, mother's name, birth date (day/month/year), marital status,
     * academic qualification, شعبة التجنيد (the place of birth), job position, الجهة العسكرية (the same for all,
     * set in the configuration) and an empty notes column. By default the applications that passed the interview and
     * wait for their contract (status awaiting_contract); any filter of the applications list changes that.
     * Ordered by full name unless `sort` is sent. Needs candidates.manage.
     */
    public function conscription(Request $request): BinaryFileResponse
    {
        $filters = ReportFilters::validate($request);
        $filters['status'] ??= [ApplicationStatus::AwaitingContract->value];
        $filters['sort'] ??= 'full_name';

        $applications = ApplicationSearch::apply(Application::query()->with(ConscriptionList::RELATIONS), ReportFilters::applicationFilters($filters))->get();
        $this->assertFits(new ReportTable('', [], array_fill(0, $applications->count(), [])), 'xlsx');

        $title = __('reports.conscription_title', [], 'ar');
        $header = $this->header($title, $request, $filters);
        AuditTrail::record('report.conscription_list', $request->user(), ['rows' => $applications->count(), 'filters' => $filters]);

        return $this->download(ConscriptionList::write($applications, $header), "{$title} {$header->generatedAt->toDateString()}.xlsx");
    }

    /** @return list<ReportTable> */
    private function tables(string $report, array $filters): array
    {
        [$kind, $view] = self::REPORTS[$report];
        $period = ReportPeriod::from($filters);
        $applicationFilters = ReportFilters::applicationFilters($filters) + ['sort' => 'nomination_number'];

        return match ($kind) {
            'applications' => [RecruitmentTables::applications($this->applications($applicationFilters), $period->isAllTime() ? null : $period)],
            'exams' => [RecruitmentTables::exams($this->applications($applicationFilters), $period, $view)],
            'interviews' => [RecruitmentTables::interviews($this->interviews($applicationFilters, $filters['sitting_id'] ?? null), $period, $view)],
            'dashboard' => RecruitmentTables::dashboard(Dashboard::build($filters)),
        };
    }

    /** @return Collection<int, Application> */
    private function applications(array $filters): Collection
    {
        return ApplicationSearch::apply(Application::query()->with(RecruitmentTables::RELATIONS), $filters)->get();
    }

    /** @return Collection<int, Interview> */
    private function interviews(array $filters, ?string $sitting): Collection
    {
        $applications = ApplicationSearch::filter(Application::query(), array_diff_key($filters, ['sort' => true, 'direction' => true]))->select('applications.id');
        $relations = array_map(fn(string $relation) => "application.{$relation}", RecruitmentTables::RELATIONS);

        return Interview::query()
            ->with(['sitting.members', ...$relations])
            ->whereIn('application_id', $applications)
            ->when($sitting, fn($query, $id) => $query->where('sitting_id', $id))
            ->get();
    }

    private function assertFits(ReportTable $table, string $format): void
    {
        $max = (int) config("reports.max_rows.{$format}");

        if ($table->count() > $max) {
            throw ValidationException::withMessages(['format' => [__('reports.too_many_rows', ['count' => $table->count(), 'max' => $max])]]);
        }
    }

    private function header(string $title, Request $request, array $filters): ReportHeader
    {
        return new ReportHeader($title, $request->user(), ReportFilters::describe($filters), ReportPeriod::from($filters)->describe());
    }

    /** @param list<ReportTable> $tables */
    private function send(array $tables, ReportHeader $header, string $format, string $title): BinaryFileResponse|Response
    {
        $name = "{$title} {$header->generatedAt->toDateString()}";

        if ($format === 'pdf') {
            $wide = collect($tables)->contains(fn(ReportTable $table) => $table->wide);
            $content = Pdf::render('reports.tables', ['tables' => $tables, 'fontSize' => $wide ? '7.5pt' : '8.5pt'], $header, landscape: true, wide: $wide);

            return response($content, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="report.pdf"; filename*=UTF-8\'\'' . rawurlencode("{$name}.pdf"),
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        return $this->download(ReportWorkbook::write([['name' => $title, 'title' => $title, 'tables' => $tables]], $header), "{$name}.xlsx");
    }

    private function download(string $path, string $filename): BinaryFileResponse
    {
        return response()
            ->download($path, $filename, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'X-Content-Type-Options' => 'nosniff',
            ])
            ->deleteFileAfterSend();
    }
}
