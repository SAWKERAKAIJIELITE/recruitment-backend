<?php

namespace App\Modules\Interviews\Http\Requests;

use App\Modules\Interviews\Enums\MemberCapacity;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The rules of an appointment, shared by scheduling, rescheduling and accepting an excuse (D-061): a committee
 * sitting (`sitting_id`, the candidate's time `scheduled_at` defaults to its start), or a one-off appointment
 * (`scheduled_at`, `location`, optional `committee_name`, `members`), which becomes a sitting of one.
 */
final class AppointmentRules
{
    public const MAX_MEMBERS = 15;

    private const ONE_OFF_FIELDS = ['location', 'committee_name', 'members'];

    /**
     * @param  string  $prefix  for a nested appointment ("appointment."): its rules apply only when it is sent
     * @param  bool  $partial  rescheduling: what is not sent stays as it was
     * @return array<string, list<mixed>>
     */
    public static function rules(string $prefix = '', bool $partial = false): array
    {
        $oneOff = $partial ? 'nullable' : "required_without:{$prefix}sitting_id";

        $rules = [
            "{$prefix}sitting_id" => ['nullable', 'uuid', Rule::exists('interview_sittings', 'id')],
            "{$prefix}scheduled_at" => [$oneOff, 'nullable', 'date'],
            "{$prefix}location" => [$oneOff, 'nullable', 'string', 'min:2', 'max:300'],
            "{$prefix}committee_name" => ['nullable', 'string', 'max:150'],
            "{$prefix}members" => [$oneOff, 'nullable', 'array', 'min:1', 'max:' . self::MAX_MEMBERS],
            ...self::memberRules("{$prefix}members"),
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
            "{$key}.*.name" => ['required', 'string', 'min:2', 'max:150'],
            "{$key}.*.capacity" => ['required', Rule::enum(MemberCapacity::class)],
            "{$key}.*.staff_user_id" => ['nullable', 'uuid', Rule::exists('staff_users', 'id')],
        ];
    }

    /** A committee sitting brings its place and committee: one-off fields are refused with it. */
    public static function after(Validator $validator, array $appointment, string $prefix = ''): void
    {
        if (($appointment['sitting_id'] ?? null) !== null) {
            foreach (self::ONE_OFF_FIELDS as $field) {
                if (array_key_exists($field, $appointment)) {
                    $validator->errors()->add("{$prefix}{$field}", __('interviews.one_off_fields_with_sitting'));
                }
            }
        }

        self::oneChair($validator, $appointment['members'] ?? null, "{$prefix}members");
    }

    /** A committee has at most one chair. */
    public static function oneChair(Validator $validator, mixed $members, string $key = 'members'): void
    {
        if (is_array($members) && count(array_filter($members, fn($member) => is_array($member) && ($member['capacity'] ?? null) === MemberCapacity::Chair->value)) > 1) {
            $validator->errors()->add($key, __('interviews.one_chair'));
        }
    }
}
