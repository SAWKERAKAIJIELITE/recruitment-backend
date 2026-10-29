<?php

namespace App\Modules\Exams\Http\Requests;

use App\Modules\Exams\Enums\Difficulty;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A new question, or a revision (send only what changes). `options` are 2 to 6 texts in display order (they are not
 * shuffled); `correct_option` is the index of the right one, from 0. A revision never moves a question to another
 * subject or part.
 */
class ExamQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the can:exam_bank.manage route middleware decides
    }

    protected function prepareForValidation(): void
    {
        if (is_array($this->input('options'))) {
            $this->merge(['options' => array_map(fn($option) => is_string($option) ? trim($option) : $option, $this->input('options'))]);
        }

        if (is_string($this->input('prompt'))) {
            $this->merge(['prompt' => trim($this->input('prompt'))]);
        }
    }

    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $required = $creating ? 'required' : 'sometimes';

        return [
            'subject_id' => [$creating ? 'required' : 'prohibited', 'integer', Rule::exists('exam_subjects', 'id')->whereNull('deleted_at')],
            'part_id' => [$creating ? 'nullable' : 'prohibited', 'integer', Rule::exists('exam_subject_parts', 'id')->whereNull('deleted_at')],
            'difficulty' => [$required, Rule::enum(Difficulty::class)],
            'prompt' => [$required, 'string', 'min:2', 'max:5000'],
            'options' => [$required, 'array', 'list', 'min:2', 'max:6'],
            'options.*' => ['required', 'string', 'max:1000', 'distinct:ignore_case'],
            'correct_option' => [$creating ? 'required' : 'sometimes', 'integer', 'min:0', 'max:5'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->isMethod('post') && $this->input('part_id') !== null
                && ! DB::table('exam_subject_parts')->where('id', $this->input('part_id'))->where('subject_id', $this->input('subject_id'))->exists()) {
                $validator->errors()->add('part_id', __('exams.part_not_in_subject'));
            }

            if ($this->has('options') && $this->has('correct_option') && (int) $this->input('correct_option') >= count($this->input('options'))) {
                $validator->errors()->add('correct_option', __('exams.correct_option_out_of_range'));
            }
        }];
    }
}
