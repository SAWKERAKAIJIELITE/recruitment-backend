<?php

namespace App\Modules\Exams\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** A change to an exam session: only what changes. Its start, place and late window only before it starts. */
class UpdateExamSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the route middleware decides
    }

    public function rules(): array
    {
        return [
            /**
             * A session keeps its plan (delete it and create another); not a parameter of this operation.
             *
             * @ignoreParam
             */
            'plan_version_id' => ['prohibited'],
            'starts_at' => ['sometimes', 'date', 'after:now'],
            'location' => ['sometimes', 'string', 'min:2', 'max:300'],
            'late_minutes' => ['sometimes', 'integer', 'min:1', 'max:600'],
            /** A new password (for example when the old one leaked); at any time. */
            'password' => ['sometimes', 'string', 'min:6', 'max:64'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
