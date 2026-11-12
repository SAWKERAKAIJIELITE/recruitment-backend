<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Actions\IssueLoginChallenge;
use App\Modules\Identity\Actions\LoginStaff;
use App\Modules\Identity\Actions\StaffLoginData;
use App\Modules\Identity\Http\Requests\ChallengeRequest;
use App\Modules\Identity\Http\Requests\LoginRequest;
use App\Modules\Identity\Http\Resources\StaffUserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class StaffAuthController extends Controller
{
    /**
     * Request a login challenge
     *
     * Step 1 of login. The device signs the returned challenge together with the username.
     *
     * @unauthenticated
     */
    public function challenge(ChallengeRequest $request, IssueLoginChallenge $issue): JsonResponse
    {
        return response()->json($issue->execute($request->validated('device_id')));
    }

    /**
     * Log in
     *
     * Step 2 of login. Requires a signature made with the device's private key. The token lasts until the business
     * day ends (`expires_at`: the next 00:00 in Damascus, D-099); after that, log in again.
     *
     * @unauthenticated
     */
    public function login(LoginRequest $request, LoginStaff $loginStaff): JsonResponse
    {
        $result = $loginStaff->execute(new StaffLoginData(
            deviceId: $request->validated('device_id'),
            challengeId: $request->validated('challenge_id'),
            signature: $request->validated('signature'),
            username: $request->validated('username'),
            password: $request->validated('password'),
            tokenName: $request->validated('device_name') ?? 'api',
        ));

        return response()->json([
            'token' => $result['token'],
            'token_type' => 'Bearer',
            /** When the token stops working: the next 00:00 in Damascus. */
            'expires_at' => $result['expires_at']->toIso8601String(),
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
