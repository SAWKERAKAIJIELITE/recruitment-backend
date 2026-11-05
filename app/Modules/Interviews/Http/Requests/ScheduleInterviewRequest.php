<?php

namespace App\Modules\Interviews\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Book an interview into a sitting: `sitting_id` (its place and committee, or its single interviewer for a one-off
 * sitting) and `scheduled_at`, the candidate's time (default: the sitting's start).
 */
class ScheduleInterviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the route middleware decides
    }

    public function rules(): array
    {
        return AppointmentRules::rules();
    }
}
