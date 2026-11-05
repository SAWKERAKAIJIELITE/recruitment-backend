<?php

namespace App\Modules\Exams\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Applications to book into a session (take them from the exam queue). */
class BookExamSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the route middleware decides
    }

    public function rules(): array
    {
        return [
            'application_ids' => ['required', 'array', 'min:1', 'max:500'],
            'application_ids.*' => ['required', 'uuid', 'distinct', 'exists:applications,id'],
        ];
    }
}
