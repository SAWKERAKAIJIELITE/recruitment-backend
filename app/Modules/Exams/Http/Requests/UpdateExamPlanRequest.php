<?php

namespace App\Modules\Exams\Http\Requests;

use App\Modules\Exams\Enums\Assessment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The whole content of a draft: the session duration and every subject, in exam order (D-053, D-054). A subject
 * is scored as a whole (`questions`: easy, medium and hard, each at least 1) or from its parts. A part is in the
 * exam (`questions`) or marked by hand (`assessment: manual`, optional `max_score`, the scale staff mark it out of,
 * 100 by default); left out, `weight` and `assessment` take the part's defaults. Subjects and parts must be active.
 */
class UpdateExamPlanRequest extends FormRequest
{
    public const MAX_PER_DIFFICULTY = 200;

    public const MAX_MINUTES = 600;

    public function authorize(): bool
    {
        return true;   // the can:exam_bank.manage route middleware decides
    }

    public function rules(): array
    {
        $activeSubject = Rule::exists('exam_subjects', 'id')->whereNull('deleted_at');
        $activePart = Rule::exists('exam_subject_parts', 'id')->whereNull('deleted_at');
        $count = ['integer', 'min:1', 'max:' . self::MAX_PER_DIFFICULTY];   // the keys are required by required_array_keys
        $questions = ['nullable', 'array:easy,medium,hard', 'required_array_keys:easy,medium,hard'];

        return [
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:' . self::MAX_MINUTES],
            'notes' => ['nullable', 'string', 'max:2000'],
            'subjects' => ['present', 'array', 'max:30'],
            'subjects.*.subject_id' => ['required', 'integer', 'distinct', $activeSubject],
            'subjects.*.questions' => $questions,
            'subjects.*.questions.easy' => $count,
            'subjects.*.questions.medium' => $count,
            'subjects.*.questions.hard' => $count,
            'subjects.*.parts' => ['nullable', 'array', 'max:10'],
            'subjects.*.parts.*.part_id' => ['required', 'integer', 'distinct', $activePart],
            'subjects.*.parts.*.weight' => ['nullable', ...array_slice(ExamSubjectPartRequest::WEIGHT_RULES, 1)],
            'subjects.*.parts.*.assessment' => ['nullable', Rule::enum(Assessment::class)],
            'subjects.*.parts.*.questions' => $questions,
            'subjects.*.parts.*.questions.easy' => $count,
            'subjects.*.parts.*.questions.medium' => $count,
            'subjects.*.parts.*.questions.hard' => $count,
            'subjects.*.parts.*.max_score' => ['nullable', 'decimal:0,2', 'min:0.01', 'max:1000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            foreach ($this->input('subjects', []) as $index => $subject) {
                $parts = $subject['parts'] ?? [];

                if ($parts !== [] && ($subject['questions'] ?? null) !== null) {
                    $validator->errors()->add("subjects.{$index}.questions", __('exams.questions_on_parts'));
                }

                $known = DB::table('exam_subject_parts')
                    ->where('subject_id', $subject['subject_id'])
                    ->whereIn('id', array_column($parts, 'part_id'))
                    ->pluck('default_assessment', 'id');

                foreach ($parts as $partIndex => $part) {
                    $key = "subjects.{$index}.parts.{$partIndex}";

                    if (! $known->has($part['part_id'])) {
                        $validator->errors()->add("{$key}.part_id", __('exams.part_not_in_subject'));
                        continue;
                    }

                    $manual = ($part['assessment'] ?? $known[$part['part_id']]) === Assessment::Manual->value;

                    if ($manual && ($part['questions'] ?? null) !== null) {
                        $validator->errors()->add("{$key}.questions", __('exams.manual_has_no_questions'));
                    }

                    if (! $manual && ($part['max_score'] ?? null) !== null) {
                        $validator->errors()->add("{$key}.max_score", __('exams.max_score_manual_only'));
                    }
                }
            }
        }];
    }
}
