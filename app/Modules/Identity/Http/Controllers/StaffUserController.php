<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Actions\ChangeStaffActivation;
use App\Modules\Identity\Actions\ManageStaffAccess;
use App\Modules\Identity\Actions\RegisterStaffUser;
use App\Modules\Identity\Actions\ResetStaffPassword;
use App\Modules\Identity\Http\Requests\RegisterUserRequest;
use App\Modules\Identity\Http\Requests\ResetUserPasswordRequest;
use App\Modules\Identity\Http\Requests\SetUserRoleRequest;
use App\Modules\Identity\Http\Resources\StaffUserAccessResource;
use App\Modules\Identity\Http\Resources\StaffUserSummaryResource;
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
            StaffUser::with('role')->orderBy('username')->paginate(15)
        );
    }

    /** One staff user's role and permissions. */
    public function show(StaffUser $user): StaffUserAccessResource
    {
        return new StaffUserAccessResource($user->load('role.permissions'));
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
     * Deactivate a staff user
     *
     * `reason` required. The user cannot log in, and every session ends at once; roles, device and history stay. Not
     * yourself, and not the last active user able to manage users (422).
     */
    public function deactivate(Request $request, StaffUser $user, ChangeStaffActivation $activation): StaffUserAccessResource
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']])['reason'];

        return new StaffUserAccessResource($activation->deactivate($user, $reason, $request->user()));
    }

    /** Reactivate a staff user: the account comes back as it was. */
    public function activate(Request $request, StaffUser $user, ChangeStaffActivation $activation): StaffUserAccessResource
    {
        return new StaffUserAccessResource($activation->activate($user, $request->user()));
    }

    /**
     * Change a staff user's role
     *
     * Every staff user holds one role (an office, the general manager or the system administrator): this one replaces
     * the role they hold, and their permissions become its permissions. 422 when nobody active would be left able to
     * manage users.
     */
    public function changeRole(SetUserRoleRequest $request, StaffUser $user, ManageStaffAccess $manage): StaffUserAccessResource
    {
        $role = Role::where('slug', $request->validated('role'))->firstOrFail();

        return new StaffUserAccessResource($manage->changeRole($user, $role, $request->user()));
    }

    /**
     * Add a staff user
     *
     * Creates the account with its role. Without users.manage, the role may only carry permissions the
     * caller holds (403 otherwise). The new user still needs an approved device to log in.
     */
    public function addStaff(RegisterUserRequest $request, RegisterStaffUser $register): JsonResponse
    {
        $data = $request->safe()->except(['role', 'password_confirmation']);
        $user = $register->execute($data, Role::where('slug', $request->validated('role'))->firstOrFail(), $request->user());

        return StaffUserAccessResource::make($user)->response()->setStatusCode(201);
    }
}
