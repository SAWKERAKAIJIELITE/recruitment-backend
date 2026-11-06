<?php

namespace App\Modules\Contracts\Http\Requests;

use App\Support\BusinessDay;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A contract (the first, or a renewal): `contract_type_id`, `reference_number`, `written_on` (default today),
 * `starts_on` (default the day it was written), `ends_on` (null: no end date), `document_id` (an uploaded document of
 * this application, optional), `notes`. Dates are YYYY-MM-DD.
 */
class ContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the route middleware decides
    }

    protected function prepareForValidation(): void
    {
        $writtenOn = $this->input('written_on') ?? BusinessDay::today();
        $this->merge(['written_on' => $writtenOn, 'starts_on' => $this->input('starts_on') ?? $writtenOn]);
    }

    public function rules(): array
    {
        return self::fieldRules();
    }

    /** @return array<string, list<mixed>> */
    public static function fieldRules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'contract_type_id' => [$required, 'integer', Rule::exists('contract_types', 'id')->whereNull('deleted_at')],
            'reference_number' => [$required, 'string', 'min:1', 'max:100'],
            'written_on' => [$required, 'date_format:Y-m-d', 'before_or_equal:'.BusinessDay::today()],
            'starts_on' => [$required, 'date_format:Y-m-d'],
            'ends_on' => ['sometimes', 'nullable', 'date_format:Y-m-d', ...($partial ? [] : ['after_or_equal:starts_on'])],
            'document_id' => ['sometimes', 'nullable', 'uuid'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
