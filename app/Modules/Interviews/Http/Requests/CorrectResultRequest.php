<?php

namespace App\Modules\Interviews\Http\Requests;

use App\Modules\Interviews\Enums\InterviewGrade;
use App\Modules\Interviews\Enums\InterviewResult;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** The fields that change (as when recording) and the `reason`. Grades are checked against the resulting result. */
class CorrectResultRequest extends FormRequest
{
    private const FIELDS = ['result', 'held_at', 'scientific_grade', 'general_grade', 'overall_grade', 'committee_summary', 'notes'];

    public function authorize(): bool
    {
        return true;   // the route middleware decides
    }

    public function rules(): array
    {
        $grade = ['sometimes', 'nullable', Rule::enum(InterviewGrade::class)];

        return [
            'result' => ['sometimes', Rule::enum(InterviewResult::class)],
            'held_at' => ['sometimes', 'date', 'before_or_equal:now'],
            'scientific_grade' => $grade,
            'general_grade' => $grade,
            'overall_grade' => $grade,
            'committee_summary' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isEmpty() && array_intersect(self::FIELDS, array_keys($this->all())) === []) {
                $validator->errors()->add('result', __('interviews.nothing_to_correct'));
            }
        }];
    }

    public function changes(): array
    {
        return array_intersect_key($this->validated(), array_flip(self::FIELDS));
    }
}
