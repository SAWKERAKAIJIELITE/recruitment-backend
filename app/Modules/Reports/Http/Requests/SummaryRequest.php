<?php

namespace App\Modules\Reports\Http\Requests;

use Illuminate\Validation\Rule;

/** The dashboard: the filters of every report, and `group_by` for its trend series (D-099). */
class SummaryRequest extends ReportRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            /** The series' buckets: day, week (from Saturday) or month (default). */
            'group_by' => ['nullable', Rule::in(['day', 'week', 'month'])],
        ];
    }

    /** @return array<string, mixed> the filters given, without the grouping */
    public function filters(): array
    {
        return array_diff_key(parent::filters(), ['group_by' => true]);
    }

    public function groupBy(): string
    {
        return $this->validated('group_by') ?? 'month';
    }
}
