<?php

namespace App\Modules\Recruitment\Http\Requests;

use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Validator;

/**
 * Correcting a live application: send only the fields that change. Each field follows the same rule as when
 * the application is opened (StoreApplicationRequest::applicationRules). Combined rules are checked
 * against the result: the area must belong to the (possibly new) district, and discontinued studies need
 * the number of years.
 */
class UpdateApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the can:candidates.manage route middleware decides
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('phone_number'))) {
            $this->merge(['phone_number' => PhoneNumber::normalize($this->input('phone_number'))]);
        }
    }

    public function rules(): array
    {
        $rules = [];

        foreach (StoreApplicationRequest::applicationRules($this->district()) as $field => $fieldRules) {
            $rules[$field] = ['sometimes', ...$fieldRules];
        }

        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $application = $this->application();

            // A new district without a new area: the current area must still belong to it.
            $area = $this->exists('residence_area_id') ? $this->input('residence_area_id') : $application->residence_area_id;

            if ($this->exists('residence_district_id') && ! $this->exists('residence_area_id') && $area !== null) {
                $inDistrict = DB::table('areas')->where('id', $area)->where('district_id', $this->district())->exists();

                if (! $inDistrict) {
                    $validator->errors()->add('residence_area_id', __('recruitment.area_not_in_district'));
                }
            }

            $discontinued = $this->exists('studies_discontinued') ? $this->boolean('studies_discontinued') : $application->studies_discontinued;
            $years = $this->exists('studying_years') ? $this->input('studying_years') : $application->studying_years;

            if ($discontinued && $years === null) {
                $validator->errors()->add('studying_years', __('validation.required', ['attribute' => __('validation.attributes.studying_years')]));
            }
        }];
    }

    public function messages(): array
    {
        return ['residence_area_id.exists' => __('recruitment.area_not_in_district')];
    }

    /** The residence district after the change. */
    private function district(): int
    {
        return $this->exists('residence_district_id')
            ? (int) $this->input('residence_district_id')
            : (int) $this->application()?->residence_district_id;
    }

    /** Null only outside a request (when the API docs are generated). */
    private function application(): ?Application
    {
        return $this->route('application');
    }
}
