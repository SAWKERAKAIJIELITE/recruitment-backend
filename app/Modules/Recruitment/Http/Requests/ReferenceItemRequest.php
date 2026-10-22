<?php

namespace App\Modules\Recruitment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReferenceItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the can:candidates.manage route middleware decides
    }

    public function rules(): array
    {
        return ['name_ar' => ['required', 'string', 'min:2', 'max:150']];
    }
}
