<?php

namespace App\Modules\Recruitment\Http\Requests;

use App\Modules\Recruitment\Enums\ReferenceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReferenceItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the can:candidates.manage route middleware decides
    }

    public function rules(): array
    {
        $rules = ['name_ar' => ['required', 'string', 'min:2', 'max:150']];

        // A new area names the district it belongs to; renaming never moves an area to another district.
        if ($this->route('type') === ReferenceType::Areas->value && $this->isMethod('post')) {
            $rules['district_id'] = ['required', 'integer', Rule::exists('districts', 'id')->whereNull('deleted_at')];
        }

        return $rules;
    }
}
