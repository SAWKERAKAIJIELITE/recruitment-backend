<?php

namespace App\Modules\Exams\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Why (cancelling a booking). */
class ExamReasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the route middleware decides
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:3', 'max:1000']];
    }
}
