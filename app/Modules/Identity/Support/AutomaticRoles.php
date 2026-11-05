<?php

namespace App\Modules\Identity\Support;

use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\StaffUser;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Keeps a user's roles in line with their direct permissions (owner, Step 10; D-070):
 *
 *  - every permission of a role the user does not hold is granted directly: the role is added, marked automatic,
 *    with a note. The grants stay direct, and the role stays while all of them are granted;
 *  - every permission of a role the user holds is denied directly: the role is removed, and the denials get a
 *    note. The denials stay.
 *
 * Giving a role by hand is the other way round: its permissions then come from the role, so the direct grants and
 * denials of those permissions are cleared (ManageStaffAccess::assignRole). A role without permissions is never
 * added or removed here. Call inside the transaction of the change, before AccessInvariants.
 */
final class AutomaticRoles
{
    /** @return list<array{role: string, change: 'added'|'removed'|'dropped'}> what changed (also audited) */
    public static function sync(StaffUser $user, ?StaffUser $actor): array
    {
        $roles = Role::query()->with('permissions:id,slug')->get()->filter(fn(Role $role) => $role->permissions->isNotEmpty());
        $held = DB::table('role_staff_user')->where('staff_user_id', $user->id)->get()->keyBy('role_id');
        $overrides = DB::table('permission_staff_user')->join('permissions', 'permissions.id', '=', 'permission_staff_user.permission_id')
            ->where('staff_user_id', $user->id)->get(['permissions.slug', 'permission_staff_user.is_granted']);
        $granted = $overrides->where('is_granted', true)->pluck('slug');
        $denied = $overrides->where('is_granted', false)->pluck('slug');
        $changes = [];

        foreach ($roles as $role) {
            $slugs = $role->permissions->pluck('slug');
            $assignment = $held->get($role->id);
            $allGranted = self::covers($granted, $slugs);

            if ($assignment === null && $allGranted) {
                $user->roles()->attach($role->id, [
                    'automatic' => true,
                    'note' => __('access.notes.role_added', ['role' => $role->label_ar], 'ar'),
                    'assigned_by' => $actor?->id,
                    'created_at' => Date::now(),
                ]);
                $changes[] = ['role' => $role->slug, 'change' => 'added'];
            } elseif ($assignment !== null && $assignment->automatic && ! $allGranted) {
                $user->roles()->detach($role->id);
                $changes[] = ['role' => $role->slug, 'change' => 'dropped'];
            } elseif ($assignment !== null && ! $assignment->automatic && self::covers($denied, $slugs)) {
                $user->roles()->detach($role->id);
                self::noteDenials($user, $role);
                $changes[] = ['role' => $role->slug, 'change' => 'removed'];
            }
        }

        foreach ($changes as $change) {
            AccessLog::record("role_{$change['change']}_automatically", $actor ?? $user, ['user_id' => $user->id, 'role' => $change['role']]);
        }

        $user->forgetPermissionCache();

        return $changes;
    }

    /** After a role's permissions change: everyone may gain or lose it. */
    public static function syncEveryone(?StaffUser $actor): void
    {
        StaffUser::query()->each(fn(StaffUser $user) => self::sync($user, $actor));
    }

    private static function covers(Collection $have, Collection $needed): bool
    {
        return $needed->diff($have)->isEmpty();
    }

    /** The note on each denial of the role's permissions (added to an earlier note, if any). */
    private static function noteDenials(StaffUser $user, Role $role): void
    {
        $note = __('access.notes.role_removed', ['role' => $role->label_ar], 'ar');

        DB::table('permission_staff_user')
            ->where('staff_user_id', $user->id)
            ->whereIn('permission_id', $role->permissions->pluck('id'))
            ->update(['note' => DB::raw("concat_ws(' | ', note, " . DB::getPdo()->quote($note) . ')'), 'updated_at' => Date::now()]);
    }
}
