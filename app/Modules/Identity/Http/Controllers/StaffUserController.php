<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Actions\ManageStaffAccess;
use App\Modules\Identity\Http\Requests\AddUserRoleRequest;
use App\Modules\Identity\Http\Requests\SetUserPermissionRequest;
use App\Modules\Identity\Http\Resources\StaffUserAccessResource;
use App\Modules\Identity\Http\Resources\StaffUserSummaryResource;
use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\StaffUser;
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

    /** Give a staff user a role. */
    public function addRole(AddUserRoleRequest $request, StaffUser $user, ManageStaffAccess $manage): StaffUserAccessResource
    {
        $role = Role::where('slug', $request->validated('role'))->firstOrFail();

        return new StaffUserAccessResource($manage->assignRole($user, $role, $request->user()));
    }

    /** Take a role away from a staff user. A role the user does not hold gives 404. */
    public function removeRole(Request $request, StaffUser $user, Role $role, ManageStaffAccess $manage): StaffUserAccessResource
    {
        return new StaffUserAccessResource($manage->removeRole($user, $role, $request->user()));
    }

    /**
     * Grant or deny one permission to one staff user
     *
     * `grant` adds it whatever the roles say; `deny` removes it even if a role gives it.
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
