<?php

namespace App\Modules\Interviews\Http\Requests;

use App\Modules\Interviews\Enums\SittingKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A new sitting: `kind` (committee, default; or one_off: a single interviewer who sees the candidates one by one,
 * so exactly one member), `starts_at`, `ends_at` (after the start, at most 24 hours later), `location`, `committee_name` (required for a committee), `members`, `notes`.
 * A member is a staff account (`staff_user_id`: the name is the account's) or a name (`name`), with a `capacity`
 * (chair, member or secretary; at most one chair).
 */
class StoreSittingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the route middleware decides
    }

    public function rules(): array
    {
        return [
            'kind' => ['nullable', Rule::enum(SittingKind::class)],
            'starts_at' => ['required', 'date'],
            /** Interviews are booked from the start until before the end; at most 24 hours after the start. */
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'location' => ['required', 'string', 'min:2', 'max:300'],
            'committee_name' => ['required_unless:kind,' . SittingKind::OneOff->value, 'nullable', 'string', 'min:2', 'max:150'],
            'notes' => ['nullable', 'string', 'max:2000'],
            /** A one-off sitting has exactly one. */
            'members' => ['required', 'array', 'min:1', 'max:' . AppointmentRules::MAX_MEMBERS],
            ...AppointmentRules::memberRules(),
        ];
    }

    public function after(): array
    {
        return [fn(Validator $validator) => AppointmentRules::oneChair($validator, $this->input('members'))];
    }
}
