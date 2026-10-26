<?php

namespace App\Modules\Imports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UploadImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the can:imports.manage route middleware decides
    }

    public function rules(): array
    {
        return [
            // The content is checked by reading it: a file that is not a workbook is refused (422).
            'file' => ['required', 'file', 'extensions:xlsx,xls', 'max:' . (int) config('imports.max_file_kilobytes', 10240)],
            'nominating_entity_id' => ['nullable', 'integer', Rule::exists('nominating_entities', 'id')->whereNull('deleted_at')],
            'source_district_id' => ['nullable', 'integer', Rule::exists('districts', 'id')->whereNull('deleted_at')],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
