<?php

namespace App\Modules\Recruitment\Http\Requests;

use App\Support\ArabicText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the can:candidates.manage route middleware decides
    }

    /** Arabic-Indic digits and separators are normalised before validation (٠١٢٣ → 0123, spaces and dashes dropped). */
    protected function prepareForValidation(): void
    {
        $candidate = $this->input('candidate');

        if (! is_array($candidate)) {
            return;
        }

        foreach (['national_id', 'phone'] as $field) {
            if (isset($candidate[$field]) && is_string($candidate[$field])) {
                $candidate[$field] = preg_replace('/[\s\-\.]/u', '', ArabicText::digitsToAscii($candidate[$field]));
            }
        }

        $this->merge(['candidate' => $candidate]);
    }

    public function rules(): array
    {
        $activeRow = fn(string $table) => Rule::exists($table, 'id')->whereNull('deleted_at');
        $name = ['string', 'min:3', 'max:150', 'not_regex:/[0-9\x{0660}-\x{0669}\x{06F0}-\x{06F9}]/u'];

        return [
            'job_title_id' => ['required', 'uuid', $activeRow('job_titles')],
            'source_office_id' => ['required', 'uuid', $activeRow('source_offices')],
            'category_id' => ['nullable', 'uuid', $activeRow('application_categories')],
            'existing_employee_number' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:2000'],

            'availability' => ['nullable', 'array'],
            'availability.works_for_other_government_entity' => ['nullable', 'boolean'],
            'availability.can_stay_overnight' => ['nullable', 'boolean'],
            'availability.full_time_available' => ['nullable', 'boolean'],

            'candidate' => ['required', 'array'],
            'candidate.full_name' => ['required', ...$name],
            'candidate.mother_name' => ['nullable', ...$name],
            'candidate.birth_date' => ['nullable', 'date', 'after:1900-01-01', 'before:today'],
            'candidate.birth_place' => ['nullable', 'string', 'max:100'],
            'candidate.national_id' => ['nullable', 'digits_between:5,20'],
            'candidate.phone' => ['nullable', 'regex:/^\+?[0-9]{6,15}$/'],
            'candidate.governorate_id' => ['nullable', 'uuid', $activeRow('governorates')],
            'candidate.residence_details' => ['nullable', 'string', 'max:500'],
            'candidate.education_level' => ['nullable', 'string', 'max:100'],
            'candidate.specialization' => ['nullable', 'string', 'max:100'],
            'candidate.study_years' => ['nullable', 'integer', 'min:0', 'max:40'],
            'candidate.quran_parts_memorized' => ['nullable', 'integer', 'min:0', 'max:30'],

            // Accepted only from users holding candidates.view_sensitive (checked in the controller).
            'sensitive' => ['nullable', 'array'],
            'sensitive.marital_status' => ['nullable', 'string', 'max:50'],
            'sensitive.health_status' => ['nullable', 'string', 'max:100'],
            'sensitive.health_details' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
