<?php

namespace App\Modules\Interviews\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * A change to a sitting: only what changes. `members` replaces the whole committee (a one-off sitting keeps exactly
 * one). A sitting keeps its kind.
 */
class UpdateSittingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the route middleware decides
    }

    public function rules(): array
    {
        return [
            /**
             * Refused when sent (a sitting keeps its kind); not a parameter of this operation.
             *
             * @ignoreParam
             */
            'kind' => ['prohibited'],
            'starts_at' => ['sometimes', 'date'],
            'location' => ['sometimes', 'string', 'min:2', 'max:300'],
            /** A committee sitting keeps a name; a one-off sitting may drop it (null). */
            'committee_name' => ['sometimes', 'nullable', 'string', 'min:2', 'max:150'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'members' => ['sometimes', 'array', 'min:1', 'max:' . AppointmentRules::MAX_MEMBERS],
            ...AppointmentRules::memberRules(),
        ];
    }

    public function after(): array
    {
        return [fn(Validator $validator) => AppointmentRules::oneChair($validator, $this->input('members'))];
    }
}
