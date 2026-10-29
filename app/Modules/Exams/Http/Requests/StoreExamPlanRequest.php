<?php

namespace App\Modules\Exams\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExamPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the can:exam_bank.manage route middleware decides
    }

    public function rules(): array
    {
        return [
            'job_position_id' => ['required', 'integer', Rule::exists('job_positions', 'id')],
            // The version to start from; by default the job position's published version, if any.
            'copy_from' => ['nullable', 'uuid', Rule::exists('exam_plan_versions', 'id')],
        ];
    }
}
