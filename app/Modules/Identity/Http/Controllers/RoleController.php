<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Actions\ManageRolePermissions;
use App\Modules\Identity\Http\Requests\AddRolePermissionsRequest;
use App\Modules\Identity\Http\Resources\RoleResource;
use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Models\Role;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RoleController extends Controller
{
    /** List roles with their permissions. */
    public function index(): AnonymousResourceCollection
    {
        return RoleResource::collection(Role::with('permissions')->orderBy('slug')->get());
    }

    /**
     * Add permissions to a role
     *
     * Everyone holding the role is affected. Permissions marked `individual_only` are refused.
     */
    public function addPermissions(AddRolePermissionsRequest $request, Role $role, ManageRolePermissions $manage): RoleResource
    {
        return new RoleResource($manage->add($role, $request->validated('permissions'), $request->user()));
    }

    /**
     * Remove a permission from a role
     *
     * Refused if it would leave nobody able to manage users. A permission the role does not have gives 404.
     */
    public function removePermission(Role $role, Permission $permission, ManageRolePermissions $manage): RoleResource
    {
        return new RoleResource($manage->remove($role, $permission, request()->user()));
    }
}
