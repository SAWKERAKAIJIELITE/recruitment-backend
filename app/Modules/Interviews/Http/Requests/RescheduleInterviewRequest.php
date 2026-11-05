<?php

namespace App\Modules\Interviews\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A new appointment and the `reason`: `sitting_id` (another sitting; the time defaults to its start), `scheduled_at`
 * (a new time; alone, in the same sitting), or both.
 */
class RescheduleInterviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the route middleware decides
    }

    public function rules(): array
    {
        return AppointmentRules::rules('', partial: true) + ['reason' => ['required', 'string', 'min:3', 'max:2000']];
    }
}
