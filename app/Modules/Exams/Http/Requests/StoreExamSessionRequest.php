<?php

namespace App\Modules\Exams\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A new exam session: a published plan version (its job position and duration), the start, the place, the late
 * window (minutes after the start a candidate may still start; default config exams.late_minutes, 15) and the
 * password every candidate of the session logs in with.
 */
class StoreExamSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the route middleware decides
    }

    public function rules(): array
    {
        return [
            'plan_version_id' => ['required', 'uuid', Rule::exists('exam_plan_versions', 'id')],
            'starts_at' => ['required', 'date', 'after:now'],
            'location' => ['required', 'string', 'min:2', 'max:300'],
            'late_minutes' => ['nullable', 'integer', 'min:0', 'max:600'],
            /** Told to the candidates on the day (written on the board); stored hashed, never shown again. */
            'password' => ['required', 'string', 'min:6', 'max:64'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
