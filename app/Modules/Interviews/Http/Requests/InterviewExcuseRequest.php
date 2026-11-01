<?php

namespace App\Modules\Interviews\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Accept an absence excuse (analysis doc §07: reason, decision and new appointment): `reason`, optional
 * `occurred_at` (the decision's real date, default now) and optional `appointment` (as when scheduling) to book the
 * next round in the same step.
 */
class InterviewExcuseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the route middleware decides
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'occurred_at' => ['nullable', 'date', 'before_or_equal:now'],
            'appointment' => ['nullable', 'array'],
            ...AppointmentRules::rules('appointment.'),
        ];
    }

    public function after(): array
    {
        return [fn(Validator $validator) => AppointmentRules::after($validator, (array) $this->input('appointment', []), 'appointment.')];
    }
}
