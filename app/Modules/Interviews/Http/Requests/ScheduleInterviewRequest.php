<?php

namespace App\Modules\Interviews\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Book an interview: `sitting_id` (a committee sitting: its place and committee; `scheduled_at` sets the candidate's
 * time, default its start), or a one-off appointment: `scheduled_at`, `location`, optional `committee_name`, and
 * `members` (`name`, `capacity` chair|member|secretary, optional `staff_user_id`).
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

    public function after(): array
    {
        return [fn(Validator $validator) => AppointmentRules::after($validator, $this->all())];
    }
}
