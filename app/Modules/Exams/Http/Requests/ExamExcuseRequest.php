<?php

namespace App\Modules\Exams\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** An accepted absence excuse: why, when it was decided (default now), and optionally the next session to book. */
class ExamExcuseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the route middleware decides
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'occurred_at' => ['nullable', 'date', 'before_or_equal:now'],
            'session_id' => ['nullable', 'uuid', Rule::exists('exam_sessions', 'id')],
        ];
    }
}
