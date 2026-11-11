<?php

namespace App\Modules\Reports\Http\Requests;

use Illuminate\Validation\Rule;

/** A report exported as a file: the filters of every report, `format`, and for the interviews `sitting_id`. */
class ExportReportRequest extends ReportRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            /** xlsx (default) or pdf. */
            'format' => ['nullable', Rule::in(['xlsx', 'pdf'])],
            /** The interviews reports: one sitting's. */
            'sitting_id' => ['nullable', 'uuid'],
        ];
    }
}
