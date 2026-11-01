<?php

namespace App\Modules\Interviews\Http\Requests;

use App\Modules\Interviews\Enums\InterviewGrade;
use App\Modules\Interviews\Enums\InterviewResult;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The committee's result: `result` (passed, failed, absent); for an attended interview the three grades
 * (excellent, very_good, good, acceptable, average, weak); `held_at` (default: the appointment);
 * `committee_summary`; `notes` (for an absence, its reason).
 */
class RecordResultRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the route middleware decides
    }

    public function rules(): array
    {
        $grade = ['required_unless:result,absent', 'prohibited_if:result,absent', 'nullable', Rule::enum(InterviewGrade::class)];

        return [
            'result' => ['required', Rule::enum(InterviewResult::class)],
            'held_at' => ['nullable', 'date', 'before_or_equal:now'],
            'scientific_grade' => $grade,
            'general_grade' => $grade,
            'overall_grade' => $grade,
            'committee_summary' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** Only the keys that were sent (an absent `held_at` means: the appointment). */
    public function values(): array
    {
        return array_filter($this->validated(), fn($value, $key) => $key !== 'held_at' || $value !== null, ARRAY_FILTER_USE_BOTH);
    }
}
