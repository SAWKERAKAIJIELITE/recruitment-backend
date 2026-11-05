<?php

namespace App\Modules\Exams\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** More time: how many minutes, and why (kept with the previous and new deadline). */
class ExtendExamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the route middleware decides
    }

    public function rules(): array
    {
        return [
            'minutes' => ['required', 'integer', 'min:1', 'max:' . (int) config('exams.max_extension_minutes')],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
