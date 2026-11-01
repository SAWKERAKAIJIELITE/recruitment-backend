<?php

namespace App\Modules\Reports\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Recruitment\Enums\ApplicationStatus;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Queries\ApplicationSearch;
use App\Modules\Recruitment\Support\ApplicationRules;
use App\Modules\Reports\Exports\Pdf;
use App\Modules\Reports\Exports\ReportHeader;
use App\Modules\Reports\Exports\ResultsSpreadsheet;
use App\Modules\Reports\Queries\CandidateResults;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Exam and interview results for candidates (D-066): a PDF statement of one application, and a list of many as Excel
 * or PDF. They show results, dates and the committee's grades; never the committee's notes or summary, nor any
 * reason. Reading: reports.view, candidates.manage or interviews.manage. Every export is audited.
 */
class ResultsReportController extends Controller
{
    /**
     * Results statement of one application (PDF)
     *
     * For the candidate: who they are, the exam outcome (and whether an exception admitted them to the interview),
     * and every recorded interview round with its grades.
     */
    public function statement(Request $request, Application $application): Response
    {
        $application->load(CandidateResults::RELATIONS);
        $result = CandidateResults::of(collect([$application]))[0];
        $header = new ReportHeader(__('reports.statement_title', [], 'ar'), $request->user());

        AuditTrail::record('report.results_statement', $request->user(), ['application_id' => $application->id]);

        return $this->pdf(Pdf::render('reports.results-statement', ['result' => $result], $header), "{$application->nomination_number}.pdf");
    }

    /**
     * Results list (Excel or PDF)
     *
     * `format`: xlsx (default) or pdf. Takes the filters and sorting of the applications list. The header carries the
     * title, the date, who exported it and the filters; the totals count the same rows. At most 20,000 applications
     * in Excel and 2,000 in PDF (422 beyond: narrow the filters).
     */
    public function list(Request $request): BinaryFileResponse|Response
    {
        ApplicationSearch::prepare($request);
        $filters = $request->validate(ApplicationSearch::rules() + ['format' => ['nullable', Rule::in(['xlsx', 'pdf'])]]);
        $format = $filters['format'] ?? 'xlsx';
        unset($filters['format'], $filters['per_page']);

        $query = ApplicationSearch::apply(Application::query()->with(CandidateResults::RELATIONS), $filters);
        $count = (clone $query)->toBase()->getCountForPagination();
        $max = (int) config("reports.max_rows.{$format}");

        if ($count > $max) {
            throw ValidationException::withMessages(['format' => [__('reports.too_many_rows', ['count' => $count, 'max' => $max])]]);
        }

        $results = CandidateResults::of($query->get());
        $totals = $this->totals($results);
        $header = new ReportHeader(__('reports.list_title', [], 'ar'), $request->user(), $this->describe($filters));

        AuditTrail::record('report.results_list', $request->user(), ['format' => $format, 'rows' => count($results), 'filters' => $filters]);

        $name = __('reports.list_title', [], 'ar') . ' ' . $header->generatedAt->toDateString();

        if ($format === 'pdf') {
            return $this->pdf(Pdf::render('reports.results-list', ['results' => $results, 'totals' => $totals, 'filters' => ResultsSpreadsheet::filters($header)], $header, landscape: true), "{$name}.pdf");
        }

        return response()
            ->download(ResultsSpreadsheet::write($results, $totals, $header), "{$name}.xlsx", [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'X-Content-Type-Options' => 'nosniff',
            ])
            ->deleteFileAfterSend();
    }

    /** @return array<string, int> label => count, from the rows themselves */
    private function totals(array $results): array
    {
        $totals = [__('reports.total_applications', [], 'ar') => count($results)];

        foreach (['exam' => 'exam', 'interview' => 'interview'] as $key => $part) {
            foreach (['passed', 'failed', 'absent'] as $value) {
                $label = __("reports.total_{$part}", ['result' => __("reports.results.{$value}", [], 'ar')], 'ar');
                $totals[$label] = count(array_filter($results, fn(array $result) => ($result[$key]['result'] ?? null) === $value));
            }
        }

        return $totals;
    }

    /** @return array<string, string> the applied filters, readable: list entries by name, statuses by label */
    private function describe(array $filters): array
    {
        $described = [];

        foreach ($filters as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $table = ApplicationRules::LIST_FIELDS[$key] ?? ($key === 'birth_place_id' ? 'districts' : null);
            $described[$key] = match (true) {
                $table !== null => (string) (DB::table($table)->where('id', (int) $value)->value('name_ar') ?? $value),
                $key === 'status' => collect((array) $value)->map(fn(string $status) => ApplicationStatus::from($status)->labelAr())->implode('، '),
                default => is_array($value) ? implode('، ', $value) : (string) $value,
            };
        }

        return $described;
    }

    private function pdf(string $content, string $filename): Response
    {
        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="report.pdf"; filename*=UTF-8\'\'' . rawurlencode($filename),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
