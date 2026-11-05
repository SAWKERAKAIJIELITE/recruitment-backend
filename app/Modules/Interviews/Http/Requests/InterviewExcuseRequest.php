<?php

namespace App\Modules\Interviews\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Accept an absence excuse (analysis doc §07: reason, decision and new appointment): `reason`, optional
 * `occurred_at` (the decision's real date, default now) and optional `appointment` (`sitting_id`, `scheduled_at`, as
 * when scheduling) to book the next round in the same step.
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
}
