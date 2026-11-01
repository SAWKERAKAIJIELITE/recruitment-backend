<?php

namespace App\Modules\Interviews\Actions;

use App\Modules\Interviews\Enums\MemberCapacity;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Writes a sitting's members, in the order given. */
final class Committee
{
    /** @param list<array{name: string, capacity: string|MemberCapacity, staff_user_id?: ?string}> $members */
    public static function write(HasMany $relation, array $members): void
    {
        foreach (array_values($members) as $position => $member) {
            $relation->create([
                'position' => $position + 1,
                'name' => trim($member['name']),
                'capacity' => $member['capacity'] instanceof MemberCapacity ? $member['capacity'] : MemberCapacity::from($member['capacity']),
                'staff_user_id' => $member['staff_user_id'] ?? null,
            ]);
        }
    }

    /** @return list<array{name: string, capacity: MemberCapacity, staff_user_id: ?string}> the members of a sitting, to copy them */
    public static function of(HasMany $relation): array
    {
        return $relation->get()->map(fn($member) => [
            'name' => $member->name,
            'capacity' => $member->capacity,
            'staff_user_id' => $member->staff_user_id,
        ])->all();
    }
}
