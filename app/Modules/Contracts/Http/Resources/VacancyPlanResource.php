<?php

namespace App\Modules\Contracts\Http\Resources;

use App\Modules\Contracts\Models\VacancyPlan;
use App\Modules\Contracts\Models\VacancyPlanItem;
use App\Modules\Recruitment\Http\Resources\ReferenceSummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * A vacancies file (D-088). `items` (one plan only): each job position with its `vacancies`, the first contracts
 * already written in the period (`used`) and what is `left` (never below 0; a replaced file's numbers can be below
 * what was written).
 *
 * @mixin VacancyPlan
 *
 * @property ?Collection<int, int> $usage first contracts written in the period, by job position (set by the controller)
 */
class VacancyPlanResource extends JsonResource
{
    public const RELATIONS = ['uploader', 'replacement:id,replaces_plan_id'];

    public function toArray(Request $request): array
    {
        $usage = $this->usage ?? collect();

        return [
            'id' => $this->id,
            'starts_on' => $this->starts_on->toDateString(),
            'ends_on' => $this->ends_on->toDateString(),
            /** upcoming, current, past or replaced. */
            'state' => $this->state(),
            'original_filename' => $this->original_filename,
            'notes' => $this->notes,
            'replaces_plan_id' => $this->replaces_plan_id,
            'replaced_by_plan_id' => $this->replacement?->id,
            'replaced_at' => $this->replaced_at?->toIso8601String(),
            'uploaded_by' => $this->uploader === null ? null : ['id' => $this->uploader->id, 'full_name' => $this->uploader->full_name],
            'created_at' => $this->created_at?->toIso8601String(),
            'items' => $this->whenLoaded('items', fn() => $this->items->sortBy('row_number')->values()->map(fn(VacancyPlanItem $item) => [
                'job_position' => ReferenceSummary::of($item->jobPosition),
                'vacancies' => $item->vacancies,
                'used' => $usage->get($item->job_position_id, 0),
                'left' => max(0, $item->vacancies - $usage->get($item->job_position_id, 0)),
            ])),
            'totals' => $this->whenLoaded('items', fn() => [
                'vacancies' => $this->items->sum('vacancies'),
                'used' => $usage->sum(),
                'left' => $this->items->sum(fn(VacancyPlanItem $item) => max(0, $item->vacancies - $usage->get($item->job_position_id, 0))),
            ]),
        ];
    }
}
