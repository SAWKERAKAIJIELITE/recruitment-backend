<?php

namespace App\Modules\Interviews\Actions;

use App\Modules\Identity\Models\StaffUser;
use App\Modules\Interviews\Enums\MemberCapacity;
use App\Modules\Interviews\Models\InterviewSitting;

/**
 * Writes a sitting's members, in the order given. A member who is a staff account carries the account's name (the
 * database sets it too, so it can never differ).
 */
final class Committee
{
    /** @param list<array{name?: ?string, capacity: string|MemberCapacity, staff_user_id?: ?string}> $members */
    public static function write(InterviewSitting $sitting, array $members): void
    {
        // Validated data can list the members out of order (keys follow the rules that filled them): the key is
        // the position sent.
        ksort($members);
        $accounts = StaffUser::query()->whereIn('id', array_filter(array_column($members, 'staff_user_id')))->get()->keyBy('id');

        foreach (array_values($members) as $position => $member) {
            $account = isset($member['staff_user_id']) ? $accounts->get($member['staff_user_id']) : null;

            $sitting->members()->forceCreate([
                'sitting_kind' => $sitting->kind,
                'position' => $position + 1,
                'name' => $account?->full_name ?? trim((string) $member['name']),
                'capacity' => $member['capacity'] instanceof MemberCapacity ? $member['capacity'] : MemberCapacity::from($member['capacity']),
                'staff_user_id' => $member['staff_user_id'] ?? null,
            ]);
        }
    }
}
