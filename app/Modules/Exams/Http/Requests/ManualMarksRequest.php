<?php

namespace App\Modules\Exams\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Hand marks (التجويد): for each hand-marked part of the plan (`part_id`, the plan part's id from the session's
 * plan), the mark out of the part's maximum, up to two decimals. Changing a mark already entered needs `reason`.
 */
class ManualMarksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the route middleware decides
    }

    public function rules(): array
    {
        return [
            'marks' => ['required', 'array', 'min:1', 'max:20'],
            'marks.*.part_id' => ['required', 'integer', 'distinct'],
            'marks.*.mark' => ['required', 'numeric', 'min:0', 'regex:/^\d{1,5}(\.\d{1,2})?$/'],
            'reason' => ['nullable', 'string', 'min:3', 'max:1000'],
        ];
    }

    /** @return array<int, string> plan part id => mark */
    public function marks(): array
    {
        return collect($this->validated('marks'))->mapWithKeys(fn(array $mark) => [(int) $mark['part_id'] => (string) $mark['mark']])->all();
    }
}
