<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Actions\LoginStaff;
use App\Modules\Identity\Http\Requests\LoginRequest;
use App\Modules\Identity\Http\Resources\StaffUserResource;
// use App\Modules\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class StaffAuthController extends Controller
{
    public function login(LoginRequest $request, LoginStaff $loginStaff): JsonResponse
    {
        // $user = User::where('username', Str::lower(trim($request->validated('username'))))->first();

        // // One generic failure for unknown user, wrong password and inactive account,
        // // so the response never reveals which accounts exist.
        // if (!$user || !$user->is_active || !Hash::check($request->validated('password'), $user->password)) {
        //     throw ValidationException::withMessages([
        //         'username' => [trans('auth.failed')],
        //     ]);
        // }

        // $user->forceFill(['last_login_at' => now()])->save();

        // $token = $user->createToken($request->validated('device_name') ?? 'api')->plainTextToken;

        $result = $loginStaff->execute(
            $request->validated('username'),
            $request->validated('password'),
            $request->validated('device_name') ?? 'api',
        );

        return response()->json([
            'token' => $result['token'],
            'token_type' => 'Bearer',
            'user' => new StaffUserResource($result['user']),
        ]);
    }

    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => new StaffUserResource($request->user())]);
    }
}
