<?php

namespace App\Modules\Contracts\Http\Requests;

use App\Support\BusinessDay;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A vacancies file (multipart/form-data): `file` (.xlsx or .xls, checked by content), `starts_on` (YYYY-MM-DD, default
 * today; the period lasts three months from it; not when replacing), `notes`.
 */
class UploadVacancyPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the can:vacancies.manage route middleware decides
    }

    protected function prepareForValidation(): void
    {
        if ($this->route('plan') === null && $this->input('starts_on') === null) {
            $this->merge(['starts_on' => BusinessDay::today()]);
        }
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'extensions:xlsx,xls', 'max:' . (int) config('contracts.vacancies_max_file_kilobytes', 5120)],
            /** The first day of the three months (not when replacing: the replaced file's period is kept). */
            'starts_on' => $this->route('plan') === null ? ['required', 'date_format:Y-m-d'] : ['prohibited'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
