<?php

namespace App\Modules\Recruitment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CloseApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the can:applications.close route middleware decides
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            // When the decision was taken, if earlier than now (for example a signed paper decision).
            'occurred_at' => ['nullable', 'date', 'before_or_equal:now'],
        ];
    }
}
