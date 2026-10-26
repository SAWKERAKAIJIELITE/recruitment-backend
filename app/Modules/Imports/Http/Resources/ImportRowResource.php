<?php

namespace App\Modules\Imports\Http\Resources;

use App\Modules\Imports\Support\ReferenceLookup;
use App\Modules\Recruitment\Support\ApplicationRules;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of a batch: the cells as they were in Excel, the values understood (list values with their
 * names), what is wrong, and the decisions taken on it.
 *
 * @mixin \App\Modules\Imports\Models\ImportRow
 */
class ImportRowResource extends JsonResource
{
    public const RELATIONS = ['batch', 'candidate', 'application', 'reviewer', 'excluder'];

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'row_number' => $this->row_number,
            'status' => $this->status,
            'status_label' => __("imports.statuses.{$this->status->value}"),
            'issues' => $this->issues ?? [],
            'values' => $this->values($request),
            'cells' => $this->cells(),
            'person_on_file' => $this->candidate === null ? null : [
                'id' => $this->candidate->id,
                'national_number' => $this->candidate->national_number,
                'full_name' => $this->candidate->full_name,
                'mother_name' => $this->candidate->mother_name,
                'birth_date' => $this->candidate->birth_date?->toDateString(),
            ],
            'review' => $this->reviewed_at === null ? null : [
                'confirmed_national_number' => $this->confirmed_national_number,
                'by' => StaffSummary::of($this->reviewer),
                'at' => $this->reviewed_at,
                'note' => $this->review_note,
            ],
            'exclusion' => $this->excluded_at === null ? null : [
                'by' => StaffSummary::of($this->excluder),
                'at' => $this->excluded_at,
                'reason' => $this->exclude_reason,
            ],
            'application' => $this->application === null ? null : [
                'id' => $this->application->id,
                'nomination_number' => $this->application->nomination_number,
            ],
            'imported_at' => $this->imported_at,
        ];
    }

    /** Dotted key => value; list values as {id, name_ar} so the screen needs no extra lookups. */
    private function values(Request $request): array
    {
        $lookup = $request->attributes->get(ReferenceLookup::class);

        if (! $lookup instanceof ReferenceLookup) {
            $lookup = new ReferenceLookup();   // one per request, shared by every row of the response
            $request->attributes->set(ReferenceLookup::class, $lookup);
        }

        $values = [];

        foreach ($this->data ?? [] as $key => $value) {
            $table = ApplicationRules::LIST_FIELDS[$key] ?? null;

            $values[$key] = $table !== null && $value !== null
                ? ['id' => (int) $value, 'name_ar' => $lookup->name($table, (int) $value)]
                : $value;
        }

        return $values;
    }

    /** @return list<array{column: string, header: ?string, value: string}> */
    private function cells(): array
    {
        $headers = [];

        foreach (array_merge(array_values($this->batch->column_map ?? []), $this->batch->ignored_columns ?? []) as $column) {
            $headers[$column['column']] = $column['header'];
        }

        $cells = [];

        foreach ($this->raw ?? [] as $letter => $value) {
            $cells[] = ['column' => (string) $letter, 'header' => $headers[$letter] ?? null, 'value' => (string) $value];
        }

        return $cells;
    }
}
