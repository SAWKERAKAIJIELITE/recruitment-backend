<?php

namespace App\Modules\Recruitment\Http\Requests;

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
        // No ASCII, Arabic-Indic or extended Arabic-Indic digits in a name.
        $name = ['string', 'min:3', 'max:150', 'not_regex:/[0-9\x{0660}-\x{0669}\x{06F0}-\x{06F9}]/u'];

        return [
            'nominating_entity_id' => $lookup('nominating_entities'),
            'job_position_id' => $lookup('job_positions'),
            'application_category_id' => $lookup('application_categories'),
            'existing_employee_number' => ['nullable', 'string', 'max:50'],

            'academic_qualification_id' => $lookup('academic_qualifications'),
            'specialization_id' => $lookup('specializations'),
            'studying_years' => ['nullable', 'integer', 'min:0', 'max:15'],
            'quran_parts_memorized' => ['nullable', 'integer', 'min:0', 'max:30'],
            'marital_status_id' => $lookup('marital_statuses'),
            'health_condition_id' => $lookup('health_conditions'),
            'health_details' => ['nullable', 'string', 'max:2000'],

            'residence_district_id' => $lookup('districts'),
            // The area must be active and belong to the chosen district (the database checks the pair too).
            'residence_area_id' => [
                'required',
                'integer',
                $active('areas')->where('district_id', (int) $this->input('residence_district_id')),
            ],
            'residence_details' => ['nullable', 'string', 'max:500'],
            'phone_number' => ['required', 'string', 'regex:/^\+?[0-9]{6,15}$/'],

            'works_for_other_government_entity' => ['required', 'boolean'],
            'full_commitment' => ['required', 'boolean'],
            'overnight_work' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],

            'candidate' => ['required', 'array'],
            'candidate.national_number' => ['required', 'string', 'digits:' . NationalNumber::LENGTH],
            'candidate.full_name' => ['required', ...$name],
            'candidate.mother_name' => ['required', ...$name],
            'candidate.birth_date' => ['required', 'date_format:Y-m-d', 'after:1950-01-01', 'before:today'],
            'candidate.birth_place_id' => $lookup('districts'),
            'candidate.notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return ['residence_area_id.exists' => __('recruitment.area_not_in_district')];
    }
}
