<?php

namespace App\Modules\Reports\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Contracts\Models\Contract;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Interviews\Models\Interview;
use App\Modules\Recruitment\Enums\ApplicationStatus;
use App\Modules\Recruitment\Enums\DecisionType;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Queries\ApplicationSearch;
use App\Modules\Reports\Exports\ConscriptionList;
use App\Modules\Reports\Exports\Pdf;
use App\Modules\Reports\Exports\ReportHeader;
use App\Modules\Reports\Exports\ReportWorkbook;
use App\Modules\Reports\Queries\Dashboard;
use App\Modules\Reports\Enums\ReportName;
use App\Modules\Reports\Http\Requests\ExportReportRequest;
use App\Modules\Reports\Http\Requests\ReportRequest;
use App\Modules\Reports\Http\Requests\SummaryRequest;
use App\Modules\Reports\Support\ReportFilters;
use App\Modules\Reports\Support\ReportPeriod;
use App\Modules\Reports\Support\ReportTable;
use App\Modules\Reports\Tables\RecruitmentTables;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The dashboard and the staff's reports (legacy workbook "نظام التوظيف والاختبارات والمقابلات"; analysis doc §16;
 * D-068). Every report takes the filters of the applications list (which applications) and a period (`year` with an
 * optional `month`, and/or `period_from` / `period_to`; each report says which date it compares). Exports carry the
 * title, period, date, issuer and filters, and their totals count the same rows; every export is audited. Each report
 * is its section's (D-086): the applications with applications.view, the exams with exams.view, the interviews with
 * interviews.view, the contracts with contracts.view, the dashboard and the workbook of all of them with reports.view.
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
        'contracts' => ['contracts', null],
        'dashboard' => ['dashboard', null],
    ];

    /** The applications' filters the contracts list has (D-098), which the Contracts office keeps in its report. */
    private const CONTRACTS_LIST_FILTERS = ['nominating_entity_id', 'residence_district_id', 'residence_area_id'];

    /** table builder => the permission that reads it (its section's, D-086) */
    private const READERS = [
        'applications' => 'applications.view',
        'exams' => 'exams.view',
        'interviews' => 'interviews.view',
        'contracts' => 'contracts.view',
        'dashboard' => 'reports.view',
    ];

    /**
     * Dashboard indicators
     *
     * As the legacy "لوحة التحكم": nominations; exam takers, passes (and passes by exception apart), failures (with
     * those later admitted by an exception, and those failed by decision), absences (with accepted excuses), results
     * waiting for the approval, and the pass rate; the same for interviews; first contracts, renewals and endings;
     * average days from nomination to each stage; the counts by month (the period's months, or from the first
     * nomination when no period is given; at most 60), by nominating entity and by job position. Each is counted on
     * its own date: a nomination by its date, an exam by the exam's day once its session's results are approved, an
     * interview by the day held, a contract by its writing date. `series` has the same counts by `group_by`: day, week
     * (from Saturday) or month (default), on the period's days, or from the first nomination up to today.
     *
     * Every staff user reads it, each office its own sections (D-107): `nominations` (applications.view: with the
     * applications started and closed in the period, and with imports.view `imports`, the batches approved and the
     * applications they brought), `exams` (exams.view), `interviews` (interviews.view), `contracts` (contracts.view:
     * with `contract_endings` by reason and `vacancies`), `documents` (documents.view: uploaded by type and direction,
     * and the live applications missing a required one). `sections` says which are filled; any other is null, in the
     * totals and in the columns of `monthly`, `series` and the breakdowns. The general manager (reports.view) reads
     * every one. Without applications.view the filters are the identity ones only (as on the applications list), with
     * the period and `group_by`; any other is 422. 403 for a user who reads none.
     */
    public function summary(SummaryRequest $request): JsonResponse
    {
        $user = $request->user();
        $sections = self::summarySections($user);
        abort_if($sections === [], 403);

        if (! $user->hasPermission('applications.view')) {
            ReportFilters::assertIdentityOnly($request);
        }

        $filters = $request->filters();

        return response()->json(['data' => Dashboard::build($filters, $request->groupBy(), $sections) + ['filters' => ReportFilters::describe($filters)]]);
    }

    /** @return list<string> the summary's sections a user reads (D-107) */
    private static function summarySections(StaffUser $user): array
    {
        if ($user->hasPermission('reports.view')) {
            return Dashboard::SECTIONS;
        }

        return array_values(array_filter(Dashboard::SECTIONS, fn (string $section) => $user->hasPermission(match ($section) {
            'nominations' => 'applications.view',
            'imports' => 'imports.view',
            'exams' => 'exams.view',
            'interviews' => 'interviews.view',
            'contracts' => 'contracts.view',
            'documents' => 'documents.view',
        })));
    }

    /**
     * Export a report (Excel or PDF)
     *
     * `report`: applications (بيانات المرشحين: one row per application, every field; with a period, those nominated
     * in it), exams (every exam decision in the period, with the exception or accepted excuse that followed),
     * exams-passed (regular passes and failures admitted by an exception), exams-failed, exams-absent, interviews
     * (every interview dated in the period: held, or else booked), interviews-passed, interviews-failed,
     * interviews-absent (recorded results by the day held), contracts (every contract written in the period, the
     * first and the renewals: type, reference, sequence, dates, state today, end reason; D-098), dashboard (the
     * indicators as tables). `format`: xlsx
     * (default) or pdf. Interviews also take `sitting_id`. Sorted by nomination number unless `sort` is sent. At most
     * 20,000 rows in Excel and 2,000 in PDF (422 on `format` beyond: narrow the filters).
     *
     * Without applications.view (an office that sees who the candidate is only, D-086), the exams and interviews
     * reports take the identity filters only (`q`, `national_number`, `status`, `live`, `job_position_id`,
     * `import_batch_id`, `from`, `to`) with the period, `format` and `sitting_id`, and the contracts report also the
     * contracts list's (`nominating_entity_id`, `residence_district_id`, `residence_area_id`): any other is 422. The
     * exams reports then leave out the qualification and the residence.
     */
    public function export(ExportReportRequest $request, ReportName $report): BinaryFileResponse|Response
    {
        $report = $report->value;
        $kind = self::REPORTS[$report][0];
        abort_unless($request->user()->hasPermission(self::READERS[$kind]), 403);
        // An office that sees who the candidate is only filters and reads that much here too (D-086).
        $identityOnly = in_array($kind, ['exams', 'interviews', 'contracts'], true) && ! $request->user()->hasPermission('applications.view');

        if ($identityOnly) {
            ReportFilters::assertIdentityOnly($request, $kind === 'contracts' ? self::CONTRACTS_LIST_FILTERS : []);
        }

        $filters = $request->filters();
        $format = $filters['format'] ?? 'xlsx';
        $this->assertCountFits($report, $filters, $format);
        $tables = $this->tables($report, $filters, $identityOnly);

        foreach ($tables as $table) {
            $this->assertFits($table, $format);
        }

        $title = $report === 'dashboard' ? __('reports.tables.dashboard', [], 'ar') : $tables[0]->title;
        $header = $this->header($title, $request, $filters);
        AuditTrail::record('report.exported', $request->user(), ['report' => $report, 'format' => $format, 'rows' => array_sum(array_map(fn (ReportTable $table) => $table->count(), $tables)), 'filters' => $filters]);

        return $this->send($tables, $header, $format, $title);
    }

    /**
     * The whole workbook (Excel)
     *
     * Every report above in one workbook, as the legacy file: the dashboard (with links to the other sheets),
     * بيانات المرشحين, the exams and their three views, the interviews and their three views, and the contracts. Same
     * filters and period.
     */
    public function workbook(ReportRequest $request): BinaryFileResponse
    {
        $filters = $request->filters();
        $sheets = [];

        foreach (['applications', 'exams', 'interviews', 'contracts'] as $report) {
            $this->assertCountFits($report, $filters, 'xlsx');
        }

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
    public function conscription(ReportRequest $request): BinaryFileResponse
    {
        $filters = $request->filters();
        $filters['status'] ??= [ApplicationStatus::AwaitingContract->value];
        $filters['sort'] ??= 'full_name';

        $this->assertFits(new ReportTable('', [], []), 'xlsx', ApplicationSearch::filter(Application::query(), ReportFilters::applicationFilters($filters))->count());
        $applications = ApplicationSearch::apply(Application::query()->with(ConscriptionList::RELATIONS), ReportFilters::applicationFilters($filters))->get();

        $title = __('reports.conscription_title', [], 'ar');
        $header = $this->header($title, $request, $filters);
        AuditTrail::record('report.conscription_list', $request->user(), ['rows' => $applications->count(), 'filters' => $filters]);

        return $this->download(ConscriptionList::write($applications, $header), "{$title} {$header->generatedAt->toDateString()}.xlsx");
    }

    /** @return list<ReportTable> */
    private function tables(string $report, array $filters, bool $identityOnly = false): array
    {
        [$kind, $view] = self::REPORTS[$report];
        $period = ReportPeriod::from($filters);
        $applicationFilters = ReportFilters::applicationFilters($filters) + ['sort' => 'nomination_number'];

        return match ($kind) {
            'applications' => [RecruitmentTables::applications($this->applications($applicationFilters), $period->isAllTime() ? null : $period)],
            'exams' => [RecruitmentTables::exams($this->applications($applicationFilters), $period, $view, $identityOnly)],
            'interviews' => [RecruitmentTables::interviews($this->interviews($applicationFilters, $filters['sitting_id'] ?? null), $period, $view)],
            'contracts' => [RecruitmentTables::contracts($this->contracts($applicationFilters, $period)->with(RecruitmentTables::CONTRACT_RELATIONS)->get())],
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
        $relations = array_map(fn (string $relation) => "application.{$relation}", RecruitmentTables::RELATIONS);

        return Interview::query()
            ->with(['sitting.members', ...$relations])
            ->whereIn('application_id', $applications)
            ->whenGiven($sitting, fn ($query, $id) => $query->where('sitting_id', $id))
            ->get();
    }

    /** The contracts of the applications the filters keep, written in the period (its days, D-098). */
    private function contracts(array $filters, ReportPeriod $period): Builder
    {
        $applications = ApplicationSearch::filter(Application::query(), array_diff_key($filters, ['sort' => true, 'direction' => true]))->select('applications.id');

        return Contract::query()
            ->whereIn('application_id', $applications)
            ->when($period->start, fn ($query, $start) => $query->where('written_on', '>=', $start->toDateString()))
            ->when($period->end, fn ($query, $end) => $query->where('written_on', '<', $end->toDateString()));
    }

    /**
     * Counted before anything is loaded: an export too large for its format is refused at once, not after the whole
     * set is in memory. The count is that of the report's own rows: applications nominated in the period, exam
     * decisions in the period, interviews dated in the period. The tables are checked again once built.
     */
    private function assertCountFits(string $report, array $filters, string $format): void
    {
        [$kind, $view] = self::REPORTS[$report];

        if ($kind === 'dashboard') {
            return;
        }

        // The outcomes a view's decisions have (passed: also failures later admitted by an exception); a decision that
        // was corrected is counted too, whatever it says (it may have become one of them).
        $examStatuses = match ($view) {
            'passed' => [ApplicationStatus::AwaitingInterview, ApplicationStatus::ExamFailed],
            'failed' => [ApplicationStatus::ExamFailed],
            'absent' => [ApplicationStatus::ExamAbsent],
            default => [ApplicationStatus::ExamTaken, ApplicationStatus::AwaitingInterview, ApplicationStatus::ExamFailed, ApplicationStatus::ExamAbsent],
        };
        // A round's result: since D-104 a round is taken (exam_taken) and its result is the approval that follows it.
        $result = "coalesce((select n.to_status from application_status_events n where n.previous_event_id = e.id and e.to_status = 'exam_taken' and n.decision_type in ('outcome', 'failed_by_decision')), e.to_status)";

        $period = ReportPeriod::from($filters);
        $applications = ApplicationSearch::filter(Application::query(), ReportFilters::applicationFilters($filters))->select('applications.id');
        $within = fn ($query, string|Expression $column) => $query
            ->when($period->start, fn ($q, $start) => $q->where($column, '>=', $start))
            ->when($period->end, fn ($q, $end) => $q->where($column, '<', $end));

        $count = match ($kind) {
            'applications' => $within(ApplicationSearch::filter(Application::query(), ReportFilters::applicationFilters($filters)), 'applications.created_at')->count(),
            // Each exam decision is an outcome from awaiting_exam (corrections replace one, they add none).
            'exams' => $within(DB::table('application_status_events as e')
                ->join('application_status_events as p', 'p.id', '=', 'e.previous_event_id')
                ->whereIn('e.application_id', $applications)
                ->where('p.to_status', ApplicationStatus::AwaitingExam->value)
                ->where('e.decision_type', DecisionType::Outcome->value)
                ->where(fn ($query) => $query
                    ->whereIn(DB::raw($result), array_map(fn (ApplicationStatus $status) => $status->value, $examStatuses))
                    // Corrected (of the round, or of its approval): counted, whatever it says now.
                    ->orWhereExists(fn ($correction) => $correction->from('application_status_events as c')->where('c.decision_type', DecisionType::Correction->value)
                        ->where(fn ($after) => $after->whereColumn('c.previous_event_id', 'e.id')
                            ->orWhereIn('c.previous_event_id', fn ($approval) => $approval->from('application_status_events as a')->select('a.id')->whereColumn('a.previous_event_id', 'e.id'))))), 'e.occurred_at')->count(),
            'interviews' => $within(Interview::query()
                ->whereIn('application_id', $applications)
                ->when($view !== 'all', fn ($query) => $query->where('result', $view))
                ->whenGiven($filters['sitting_id'] ?? null, fn ($query, $id) => $query->where('sitting_id', $id)), DB::raw('coalesce(held_at, scheduled_at)'))->count(),
            'contracts' => $this->contracts(ReportFilters::applicationFilters($filters), $period)->count(),
        };

        $this->assertFits(new ReportTable('', [], []), $format, $count);
    }

    private function assertFits(ReportTable $table, string $format, ?int $count = null): void
    {
        $max = (int) config("reports.max_rows.{$format}");
        $count ??= $table->count();

        if ($count > $max) {
            throw ValidationException::withMessages(['format' => [__('reports.too_many_rows', ['count' => $count, 'max' => $max])]]);
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
            $wide = collect($tables)->contains(fn (ReportTable $table) => $table->wide);
            $content = Pdf::render('reports.tables', ['tables' => $tables, 'fontSize' => $wide ? '7.5pt' : '8.5pt'], $header, landscape: true, wide: $wide);

            return response($content, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="report.pdf"; filename*=UTF-8\'\''.rawurlencode("{$name}.pdf"),
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
