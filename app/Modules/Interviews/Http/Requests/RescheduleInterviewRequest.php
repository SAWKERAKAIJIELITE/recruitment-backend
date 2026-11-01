<?php

namespace App\Modules\Interviews\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * A new appointment and the `reason`. `sitting_id` (another committee sitting, optional `scheduled_at`), `scheduled_at`
 * alone (a new time in the same sitting; a one-off appointment gets a new sitting of one), or one-off fields
 * (`location`, `committee_name`, `members`: a new one-off appointment; what is not sent is copied from the current one).
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

    public function after(): array
    {
        return [fn(Validator $validator) => AppointmentRules::after($validator, $this->all())];
    }
}
