<?php

namespace App\Modules\Reports\Http\Requests;

use App\Modules\Recruitment\Queries\ApplicationSearch;
use App\Modules\Reports\Support\ReportFilters;
use App\Modules\Reports\Support\ReportPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The filters every report takes (D-066, D-094): those of the applications list (which applications; no paging), and
 * the period (`year`, `month`, `period_from`, `period_to`: when; see ReportPeriod). A form request, so the API
 * documentation lists them.
 */
class ReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the route middleware and the controller decide
    }

    protected function prepareForValidation(): void
    {
        ApplicationSearch::prepare($this);
    }

    public function rules(): array
    {
        return ReportFilters::rules();
    }

    public function after(): array
    {
        return [fn(Validator $validator) => ReportPeriod::after($validator, $this->all())];
    }

    /** @return array<string, mixed> the filters given (empty ones left out) */
    public function filters(): array
    {
        return ReportFilters::given($this->validated());
    }
}
