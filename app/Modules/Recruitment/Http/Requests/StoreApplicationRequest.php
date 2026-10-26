<?php

namespace App\Modules\Recruitment\Http\Requests;

use App\Modules\Recruitment\Support\ApplicationRules as R;
use App\Modules\Recruitment\Support\NationalNumber;
use App\Modules\Recruitment\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class StoreApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the can:candidates.manage route middleware decides
    }

    /** Arabic-Indic digits and separators are normalized before validation (٠١٢٣ → 0123, spaces and dashes dropped). */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('phone_number'))) {
            $this->merge(['phone_number' => PhoneNumber::normalize($this->input('phone_number'))]);
        }

        $candidate = $this->input('candidate');

        if (is_array($candidate) && isset($candidate['national_number']) && is_string($candidate['national_number'])) {
            $candidate['national_number'] = NationalNumber::normalize($candidate['national_number']);
            $this->merge(['candidate' => $candidate]);
        }
    }

    public function rules(): array
    {
        $active = fn(string $table): Exists => Rule::exists($table, 'id')->whereNull('deleted_at');
        $lookup = fn(string $table): array => ['required', 'integer', $active($table)];
        // Limits shared with the Excel import (ApplicationRules).
        $name = ['string', 'min:' . R::NAME_MIN, 'max:' . R::NAME_MAX, 'not_regex:' . R::NAME_FORBIDDEN];

        return [
            'nominating_entity_id' => $lookup('nominating_entities'),
            'job_position_id' => $lookup('job_positions'),
            'application_category_id' => $lookup('application_categories'),
            'existing_employee_number' => ['nullable', 'string', 'max:' . R::EMPLOYEE_NUMBER_MAX],

            'academic_qualification_id' => $lookup('academic_qualifications'),
            'specialization_id' => $lookup('specializations'),
            'studying_years' => ['nullable', 'required_if_accepted:studies_discontinued', 'integer', 'min:0', 'max:' . R::STUDYING_YEARS_MAX],
            // "3 ومنقطع": studied studying_years years, then stopped (D-044). Defaults to false.
            'studies_discontinued' => ['nullable', 'boolean'],
            'quran_parts_memorized' => ['nullable', 'integer', 'min:0', 'max:' . R::QURAN_PARTS_MAX],
            'marital_status_id' => $lookup('marital_statuses'),
            'health_condition_id' => $lookup('health_conditions'),
            'health_details' => ['nullable', 'string', 'max:' . R::LONG_TEXT_MAX],

            'residence_district_id' => $lookup('districts'),
            // Optional (D-043). When given, it must be active and belong to the chosen district (the database
            // checks the pair too).
            'residence_area_id' => [
                'nullable',
                'integer',
                $active('areas')->where('district_id', (int) $this->input('residence_district_id')),
            ],
            'residence_details' => ['nullable', 'string', 'max:' . R::RESIDENCE_DETAILS_MAX],
            'phone_number' => ['required', 'string', 'regex:' . R::PHONE_PATTERN],

            'works_for_other_government_entity' => ['required', 'boolean'],
            'full_commitment' => ['required', 'boolean'],
            'overnight_work' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:' . R::LONG_TEXT_MAX],

            'candidate' => ['required', 'array'],
            'candidate.national_number' => ['required', 'string', 'digits:' . NationalNumber::LENGTH],
            'candidate.full_name' => ['required', ...$name],
            'candidate.mother_name' => ['required', ...$name],
            'candidate.birth_date' => ['required', 'date_format:Y-m-d', 'after:' . R::BIRTH_DATE_AFTER, 'before:today'],
            'candidate.birth_place_id' => $lookup('districts'),
            'candidate.notes' => ['nullable', 'string', 'max:' . R::LONG_TEXT_MAX],
        ];
    }

    public function messages(): array
    {
        return ['residence_area_id.exists' => __('recruitment.area_not_in_district')];
    }
}
