<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Actions\ManageStaffAccess;
use App\Modules\Identity\Actions\RegisterStaffUser;
use App\Modules\Identity\Actions\ResetStaffPassword;
use App\Modules\Identity\Http\Requests\AddUserRoleRequest;
use App\Modules\Identity\Http\Requests\RegisterUserRequest;
use App\Modules\Identity\Http\Requests\ResetUserPasswordRequest;
use App\Modules\Identity\Http\Requests\SetUserPermissionRequest;
use App\Modules\Identity\Http\Resources\StaffUserAccessResource;
use App\Modules\Identity\Http\Resources\StaffUserSummaryResource;
use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\StaffUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StaffUserController extends Controller
{
    /** List staff users. */
    public function index(): AnonymousResourceCollection
    {
        return StaffUserSummaryResource::collection(
            StaffUser::with('roles')->orderBy('username')->paginate(15)
        );
    }

    /** One staff user's roles, personal overrides and effective permissions. */
    public function show(StaffUser $user): StaffUserAccessResource
    {
        return new StaffUserAccessResource($user->load('roles', 'permissions'));
    }

    /**
     * Set a new password
     *
     * For a forgotten password: the administrator sets a new one and tells the user. Every session of the user ends
     * (their tokens are deleted); the device stays approved. Same strength rules as a new account
     * (`password_confirmation` too).
     */
    public function resetPassword(ResetUserPasswordRequest $request, StaffUser $user, ResetStaffPassword $reset): StaffUserAccessResource
    {
        return new StaffUserAccessResource($reset->execute($user, $request->validated('password'), $request->user()));
    }

    /**
     * Give a staff user a role
     *
     * Its permissions then come from the role: the user's own grants and denials of those permissions are cleared.
     * A role the system had added (all its permissions granted directly) becomes a role given by hand.
     */
    public function addRole(AddUserRoleRequest $request, StaffUser $user, ManageStaffAccess $manage): StaffUserAccessResource
    {
        $role = Role::where('slug', $request->validated('role'))->firstOrFail();

        return new StaffUserAccessResource($manage->assignRole($user, $role, $request->user()));
    }

    /**
     * Add a staff user
     *
     * Creates the account with its first role. Without users.manage, the role may only carry permissions the
     * caller holds (403 otherwise). The new user still needs an approved device to log in.
     */
    public function addStaff(RegisterUserRequest $request, RegisterStaffUser $register): JsonResponse
    {
        $data = $request->safe()->except(['role', 'password_confirmation']);
        $user = $register->execute($data, Role::where('slug', $request->validated('role'))->firstOrFail(), $request->user());

        return StaffUserAccessResource::make($user)->response()->setStatusCode(201);
    }

    /**
     * Take a role away from a staff user
     *
     * A role the user does not hold gives 404. Taking away a role the system added also takes away the direct
     * grants it stood for (otherwise it would come straight back).
     */
    public function removeRole(Request $request, StaffUser $user, Role $role, ManageStaffAccess $manage): StaffUserAccessResource
    {
        return new StaffUserAccessResource($manage->removeRole($user, $role, $request->user()));
    }

    /**
     * Grant or deny one permission to one staff user
     *
     * `grant` adds it whatever the roles say; `deny` removes it even if a role gives it. When the user's direct
     * grants now hold every permission of a role they do not have, that role is added (automatic, with a note); when
     * their denials cover every permission of a role they hold, that role is removed (the denials get a note).
     */
    public function setPermission(SetUserPermissionRequest $request, StaffUser $user, ManageStaffAccess $manage): StaffUserAccessResource
    {
        $permission = Permission::where('slug', $request->validated('permission'))->firstOrFail();

        return new StaffUserAccessResource(
            $manage->setPermission($user, $permission, $request->validated('effect') === 'grant', $request->user())
        );
    }

    /** Remove a personal grant or denial. A permission with no override gives 404. */
    public function clearPermission(Request $request, StaffUser $user, Permission $permission, ManageStaffAccess $manage): StaffUserAccessResource
    {
        return new StaffUserAccessResource($manage->clearPermission($user, $permission, $request->user()));
    }
}
