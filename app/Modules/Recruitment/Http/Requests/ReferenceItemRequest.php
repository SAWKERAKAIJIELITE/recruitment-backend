<?php

namespace App\Modules\Recruitment\Http\Requests;

use App\Modules\Recruitment\Enums\EditableReferenceType;
use App\Modules\Recruitment\Enums\ReferenceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReferenceItemRequest extends FormRequest
{
    /** The list's office: contract types contracts.manage, the others candidates.manage (D-085). */
    public function authorize(): bool
    {
        $type = $this->route('type');

        return ! $type instanceof EditableReferenceType || $this->user()->hasPermission($type->managedBy());
    }

    public function rules(): array
    {
        $type = $this->route('type');
        $type = ReferenceType::tryFrom($type instanceof \BackedEnum ? (string) $type->value : (string) $type);
        // A new area names the district it belongs to; renaming never moves an area to another district.
        $newArea = $type === ReferenceType::Areas && $this->isMethod('post');

        // Every key is listed (none added conditionally), so the API documentation shows all of them.
        return [
            'name_ar' => ['required', 'string', 'min:2', 'max:150'],
            'district_id' => [
                Rule::requiredIf($newArea),
                Rule::prohibitedIf(! $newArea),
                'integer',
                Rule::exists('districts', 'id')->whereNull('deleted_at'),
            ],
        ];
    }
}
