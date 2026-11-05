<?php

namespace App\Modules\Exams\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** An exception for a failed exam: why, and when it was decided (default now). */
class ExamExceptionRequest extends FormRequest
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
        ];
    }
}
