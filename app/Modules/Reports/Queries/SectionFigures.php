<?php

namespace App\Modules\Reports\Queries;

use App\Modules\Contracts\Actions\ManageVacancies;
use App\Modules\Contracts\Models\VacancyPlan;
use App\Modules\Contracts\Models\VacancyPlanItem;
use App\Modules\Documents\Models\DocumentType;
use App\Modules\Recruitment\Enums\ApplicationStatus;
use App\Modules\Reports\Support\ReportPeriod;
use App\Support\BusinessDay;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The summary's figures that are not facts of the applications' history (D-107), for the applications the filters keep
 * (`$applications`, a subquery of their ids) and the period:
 *
 *  - imports (imports.view): the batches approved in the period, and the applications they brought;
 *  - vacancies (contracts.view): the plans in force in the period, or today's without a period (D-088), with their
 *    vacancies, the first contracts that used them and what is left; the job position filter narrows them;
 *  - documents (documents.view): the documents uploaded in the period, by type and direction (the people's and the
 *    applications' of the applications kept), and the live applications missing a document required at a status they
 *    reached (D-057), by type.
 */
final class SectionFigures
{
    public function __construct(private readonly Builder $applications, private readonly ReportPeriod $period, private readonly array $filters) {}

    /** @return array{batches: int, applications: int} */
    public function imports(): array
    {
        $rows = DB::table('import_rows as r')
            ->join('import_batches as b', 'b.id', '=', 'r.import_batch_id')
            ->where('b.status', 'approved')
            ->whereIn('r.application_id', (clone $this->applications)->toBase())
            ->when($this->period->start, fn ($query, $start) => $query->where('b.approved_at', '>=', $start))
            ->when($this->period->end, fn ($query, $end) => $query->where('b.approved_at', '<', $end));

        return ['batches' => (clone $rows)->distinct()->count('b.id'), 'applications' => (clone $rows)->count()];
    }

    /** @return array{plans: list<array>, totals: array{vacancies: int, used: int, left: int}} */
    public function vacancies(): array
    {
        $position = $this->filters['job_position_id'] ?? null;
        $plans = VacancyPlan::query()
            ->inForce()
            ->with('items')
            ->when($this->period->isAllTime(), fn ($query) => $query->covering(BusinessDay::today()))
            ->when($this->period->start, fn ($query, $start) => $query->where('ends_on', '>=', $start->toDateString()))
            ->when($this->period->end, fn ($query, $end) => $query->where('starts_on', '<', $end->toDateString()))
            ->orderBy('starts_on')
            ->get();
        $vacancies = app(ManageVacancies::class);

        $shown = $plans->map(function (VacancyPlan $plan) use ($vacancies, $position) {
            $items = $plan->items->when($position !== null, fn ($items) => $items->where('job_position_id', (int) $position));
            $used = $vacancies->used($plan, $position === null ? null : [(int) $position]);
            $total = $items->sum('vacancies');
            $usedTotal = $used->sum();

            return [
                'id' => $plan->id,
                'starts_on' => $plan->starts_on->toDateString(),
                'ends_on' => $plan->ends_on->toDateString(),
                /** upcoming, current or past. */
                'state' => $plan->state(),
                'vacancies' => $total,
                'used' => $usedTotal,
                'left' => $items->sum(fn (VacancyPlanItem $item) => max(0, $item->vacancies - $used->get($item->job_position_id, 0))),
            ];
        })->values()->all();

        return ['plans' => $shown, 'totals' => [
            'vacancies' => array_sum(array_column($shown, 'vacancies')),
            'used' => array_sum(array_column($shown, 'used')),
            'left' => array_sum(array_column($shown, 'left')),
        ]];
    }

    /**
     * @return array{uploaded: array{total: int, by_type: list<array{type: array{id: int, name: string}, direction: string, count: int}>}, missing: array{applications: int, by_type: list<array{type: array{id: int, name: string}, required_from_status: string, applications: int}>}}
     */
    public function documents(): array
    {
        $types = DocumentType::withTrashed()->get()->keyBy('id');
        $candidates = (clone $this->applications)->toBase()->select('applications.candidate_id');

        $uploaded = DB::table('documents')
            ->whereIn('candidate_id', $candidates)
            ->when($this->period->start, fn ($query, $start) => $query->where('created_at', '>=', $start))
            ->when($this->period->end, fn ($query, $end) => $query->where('created_at', '<', $end))
            ->groupBy('document_type_id')
            ->selectRaw('document_type_id, count(*) AS n')
            ->pluck('n', 'document_type_id');

        $byType = $uploaded->map(fn ($count, $typeId) => [
            'type' => ['id' => (int) $typeId, 'name' => $types[$typeId]->name_ar],
            'direction' => $types[$typeId]->direction->value,
            'count' => (int) $count,
        ])->sortBy([['count', 'desc'], ['type.name', 'asc']])->values()->all();

        // Missing now, for the live applications kept: a type required from a status the application reached, with no
        // active document (the person's, or this application's for an application-level type).
        $missing = [];
        $missingApplications = DB::table('applications as a')->select('a.id')->whereRaw('false');

        foreach (DocumentType::query()->whereNotNull('required_from_status')->orderBy('id')->get() as $type) {
            $query = DB::table('applications as a')
                ->whereIn('a.id', (clone $this->applications)->toBase())
                ->whereNotIn('a.current_status', ApplicationStatus::terminalValues())
                ->whereExists(fn ($reached) => $reached->from('application_status_events as e')->whereColumn('e.application_id', 'a.id')->where('e.to_status', $type->required_from_status->value))
                ->whereNotExists(fn ($present) => $present->from('documents as d')
                    ->where('d.document_type_id', $type->id)
                    ->whereNull('d.archived_at')
                    ->whereColumn('d.candidate_id', 'a.candidate_id')
                    ->where(fn ($own) => $own->whereNull('d.application_id')->orWhereColumn('d.application_id', 'a.id')));
            $count = (clone $query)->count();

            if ($count > 0) {
                $missing[] = ['type' => ['id' => $type->id, 'name' => $type->name_ar], 'required_from_status' => $type->required_from_status->value, 'applications' => $count];
            }

            $missingApplications->union((clone $query)->select('a.id'));
        }

        usort($missing, fn (array $a, array $b) => [$b['applications'], $a['type']['name']] <=> [$a['applications'], $b['type']['name']]);

        return [
            'uploaded' => ['total' => (int) $uploaded->sum(), 'by_type' => $byType],
            /** `applications`: how many live applications miss at least one; `by_type`: how many miss each. */
            'missing' => ['applications' => DB::query()->fromSub($missingApplications, 'm')->count(), 'by_type' => $missing],
        ];
    }
}
