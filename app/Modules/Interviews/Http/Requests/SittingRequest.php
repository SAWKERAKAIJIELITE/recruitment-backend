<?php

namespace App\Modules\Interviews\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * A committee sitting: `starts_at`, `location`, `committee_name`, `members` (`name`, `capacity` chair|member|secretary,
 * optional `staff_user_id`; at most one chair), `notes`. On change, send only what changes (`members` replaces the
 * whole committee).
 */
class SittingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the route middleware decides
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'starts_at' => [$required, 'date'],
            'location' => [$required, 'string', 'min:2', 'max:300'],
            'committee_name' => [$required, 'string', 'min:2', 'max:150'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'members' => [$required, 'array', 'min:1', 'max:' . AppointmentRules::MAX_MEMBERS],
            ...AppointmentRules::memberRules(),
        ];
    }

    public function after(): array
    {
        return [fn(Validator $validator) => AppointmentRules::oneChair($validator, $this->input('members'))];
    }
}
