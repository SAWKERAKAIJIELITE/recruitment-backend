<?php

namespace App\Modules\Contracts\Http\Requests;

use App\Modules\Contracts\Enums\CertificateType;
use App\Support\BusinessDay;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A contract (the first, or a renewal): `contract_type_id`, `reference_number` (رقم الاستمارة on the form),
 * `written_on` (default today), `starts_on` (default the day it was written), `ends_on` (null: no end date),
 * `document_id` (an uploaded document of this application, optional), `notes`. Dates are YYYY-MM-DD.
 *
 * The contract form's details (D-098), each optional (blank on the form when missing; a renewal copies those of the
 * contract it renews unless sent): `unit_id` (الجهة الملتحق بها: the units list), `level_3` to `level_6` (السوية
 * التنظيمية الثالثة to السادسة), `workplace` (مكان العمل), `work_pattern` (نمط العمل), `job_specialty` (التخصص),
 * `work_schedule` (نظام الدوام), `salary_usd` (قيمة الراتب بالدولار), `certificate_type` (نوع الشهادة),
 * `university`, `gpa` (المعدل, 0 to 100), `certificate_date`, `wives_count` (0 to 4), `children_count`, `origin`
 * (البلد الأصلي; the place of birth when not given).
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
            ...self::formRules(),
        ];
    }

    /** @return array<string, list<mixed>> the contract form's details (D-098), each optional */
    public static function formRules(): array
    {
        $text = fn(int $max) => ['sometimes', 'nullable', 'string', 'max:' . $max];

        return [
            'unit_id' => ['sometimes', 'nullable', 'integer', Rule::exists('units', 'id')->whereNull('deleted_at')],
            'level_3' => $text(150),
            'level_4' => $text(150),
            'level_5' => $text(150),
            'level_6' => $text(150),
            'workplace' => $text(200),
            'work_pattern' => $text(100),
            'job_specialty' => $text(150),
            'work_schedule' => $text(100),
            'salary_usd' => ['sometimes', 'nullable', 'decimal:0,2', 'min:0', 'max:99999999'],
            'certificate_type' => ['sometimes', 'nullable', Rule::enum(CertificateType::class)],
            'university' => $text(200),
            'gpa' => ['sometimes', 'nullable', 'decimal:0,2', 'min:0', 'max:100'],
            'certificate_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:' . BusinessDay::today()],
            'wives_count' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:4'],
            'children_count' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:30'],
            'origin' => $text(150),
        ];
    }
}
