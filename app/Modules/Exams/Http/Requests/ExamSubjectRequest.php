<?php

namespace App\Modules\Exams\Http\Requests;

use App\Modules\Exams\Enums\Assessment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A new subject (optionally with its parts), or a new name for one. */
class ExamSubjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the can:exam_bank.manage route middleware decides
    }

    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'name_ar' => ['required', 'string', 'min:2', 'max:150'],
            'parts' => [$creating ? 'nullable' : 'prohibited', 'array', 'max:10'],
            'parts.*.name_ar' => ['required', 'string', 'min:2', 'max:150'],
            'parts.*.default_weight' => ExamSubjectPartRequest::WEIGHT_RULES,
            'parts.*.default_assessment' => ['sometimes', Rule::enum(Assessment::class)],
        ];
    }
}
