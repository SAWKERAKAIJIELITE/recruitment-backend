<?php

namespace App\Modules\Recruitment\Http\Requests;

use App\Modules\Recruitment\Support\NationalNumber;
use Illuminate\Foundation\Http\FormRequest;

/** Correcting a person: send only the fields that change; each follows the rule used when the person is created. */
class UpdateCandidateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the can:candidates.manage route middleware decides
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('national_number'))) {
            $this->merge(['national_number' => NationalNumber::normalize($this->input('national_number'))]);
        }
    }

    public function rules(): array
    {
        $rules = [];

        foreach (StoreApplicationRequest::personRules() as $field => $fieldRules) {
            $rules[$field] = ['sometimes', ...$fieldRules];
        }

        return $rules;
    }
}
