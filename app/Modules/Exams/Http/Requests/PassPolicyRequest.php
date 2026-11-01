<?php

namespace App\Modules\Exams\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A new version of the pass rules: `subject_pass_mark` (a subject passes at this score or more), `tolerance_floor`
 * (below the pass mark but at least this: tolerated), `max_tolerated_subjects`, `average_must_exceed` (strictly
 * greater), `description`. Scores are percentages with up to 2 decimals.
 */
class PassPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the route middleware decides
    }

    public function rules(): array
    {
        $mark = ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:100'];

        return [
            'subject_pass_mark' => $mark,
            'tolerance_floor' => [...$mark, 'lte:subject_pass_mark'],
            'max_tolerated_subjects' => ['required', 'integer', 'min:0', 'max:20'],
            'average_must_exceed' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'lt:100'],
            'description' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }
}
