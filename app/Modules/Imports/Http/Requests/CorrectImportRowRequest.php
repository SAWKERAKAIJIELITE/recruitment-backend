<?php

namespace App\Modules\Imports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CorrectImportRowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Keys and value types are checked by ReviewImportRows (they depend on the field).
        return ['values' => ['required', 'array', 'min:1']];
    }
}
