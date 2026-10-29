<?php

namespace App\Modules\Exams\Http\Requests;

use App\Modules\Exams\Enums\Assessment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A new part of a subject, or changes to one (send only what changes). */
class ExamSubjectPartRequest extends FormRequest
{
    /** A weight is relative to the other parts of the subject: 60 and 40, or 3 and 2, mean the same. */
    public const WEIGHT_RULES = ['required', 'decimal:0,2', 'min:0.01', 'max:99999.99'];

    public function authorize(): bool
    {
        return true;   // the can:exam_bank.manage route middleware decides
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name_ar' => [$required, 'string', 'min:2', 'max:150'],
            'default_weight' => [$required, ...array_slice(self::WEIGHT_RULES, 1)],
            'default_assessment' => ['sometimes', Rule::enum(Assessment::class)],   // exam (default) or manual
        ];
    }
}
