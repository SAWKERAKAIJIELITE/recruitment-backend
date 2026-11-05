<?php

namespace App\Modules\Exams\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** The chosen option (its index, from 0), or null to clear the answer. */
class SaveAnswerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['option' => ['present', 'nullable', 'integer', 'min:0', 'max:5']];
    }
}
