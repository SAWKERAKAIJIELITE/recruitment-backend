<?php

namespace App\Modules\Interviews\Http\Requests;

use App\Modules\Interviews\Enums\MemberCapacity;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The rules of an appointment, shared by scheduling, rescheduling and accepting an excuse (D-061, D-067): a sitting
 * (`sitting_id`: a committee, or a one-off sitting's single interviewer) and the candidate's time in it
 * (`scheduled_at`, default its start).
 */
final class AppointmentRules
{
    public const MAX_MEMBERS = 15;

    /**
     * @param  string  $prefix  for a nested appointment ("appointment."): its rules apply only when it is sent
     * @param  bool  $partial  rescheduling: what is not sent stays as it was (at least one of the two)
     * @return array<string, list<mixed>>
     */
    public static function rules(string $prefix = '', bool $partial = false): array
    {
        $rules = [
            "{$prefix}sitting_id" => [$partial ? "required_without:{$prefix}scheduled_at" : 'required', 'nullable', 'uuid', Rule::exists('interview_sittings', 'id')],
            "{$prefix}scheduled_at" => ['nullable', 'date'],
        ];

        if ($prefix === '') {
            return $rules;
        }

        $parent = rtrim($prefix, '.');

        return array_map(fn(array $fieldRules) => ["exclude_without:{$parent}", ...$fieldRules], $rules);
    }

    /** @return array<string, list<mixed>> */
    public static function memberRules(string $key = 'members'): array
    {
        return [
            /** Someone without a staff account. Not sent with `staff_user_id`: the name is then the account's. */
            "{$key}.*.name" => ["required_without:{$key}.*.staff_user_id", 'nullable', 'string', 'min:2', 'max:150'],
            "{$key}.*.capacity" => ['required', Rule::enum(MemberCapacity::class)],
            /** A staff account (active); its name is the member's name. */
            "{$key}.*.staff_user_id" => ['nullable', 'uuid', "prohibits:{$key}.*.name", Rule::exists('staff_users', 'id')->where('is_active', true)],
        ];
    }

    /** A committee has at most one chair. */
    public static function oneChair(Validator $validator, mixed $members, string $key = 'members'): void
    {
        if (is_array($members) && count(array_filter($members, fn($member) => is_array($member) && ($member['capacity'] ?? null) === MemberCapacity::Chair->value)) > 1) {
            $validator->errors()->add($key, __('interviews.one_chair'));
        }
    }
}
