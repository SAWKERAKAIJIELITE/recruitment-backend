<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Actions\ApproveDevice;
use App\Modules\Identity\Actions\RegisterDevice;
use App\Modules\Identity\Actions\RevokeDevice;
use App\Modules\Identity\Http\Requests\ApproveDeviceRequest;
use App\Modules\Identity\Http\Requests\RegisterDeviceRequest;
use App\Modules\Identity\Http\Requests\RevokeDeviceRequest;
use App\Modules\Identity\Http\Resources\StaffDeviceResource;
use App\Modules\Identity\Models\StaffDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class StaffDeviceController extends Controller
{
    /**
     * Register a device
     *
     * Called by the EXE on first run. Creates a pending device; idempotent for the same public key.
     *
     * @unauthenticated
     */
    public function register(RegisterDeviceRequest $request, RegisterDevice $register): JsonResponse
    {
        $device = $register->execute(
            $request->validated('public_key'),
            $request->validated('label'),
            $request->validated('mac_address'),
            $request->validated('os_info'),
        );

        // Public endpoint: expose only what the device itself needs to show.
        return response()->json([
            'device' => [
                'id' => $device->id,
                'status' => $device->status,
                'fingerprint' => $device->displayFingerprint(),
            ],
        ], $device->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Device status
     *
     * Lets the EXE show "waiting for approval". The device id is an unguessable UUID.
     *
     * @unauthenticated
     */
    public function status(StaffDevice $device): JsonResponse
    {
        return response()->json(['status' => $device->status]);
    }

    /** List devices (optionally filtered by status). */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => [
                'nullable',
                Rule::in([
                    StaffDevice::STATUS_PENDING,
                    StaffDevice::STATUS_APPROVED,
                    StaffDevice::STATUS_REVOKED,
                ]),
            ],
        ]);

        $devices = StaffDevice::query()
            ->with('staffUsers')
            ->when($filters['status'] ?? null, fn($query, $status) => $query->where('status', $status))
            ->latest()
            ->paginate(25);

        return StaffDeviceResource::collection($devices);
    }

    /** Approve a pending device and assign it to staff users. */
    public function approve(ApproveDeviceRequest $request, StaffDevice $device, ApproveDevice $approve): JsonResponse
    {
        $device = $approve->execute(
            $device,
            $request->user(),
            $request->validated('fingerprint'),
            $request->validated('staff_user_ids'),
        );

        return response()->json(['device' => new StaffDeviceResource($device->load('staffUsers'))]);
    }

    /** Revoke a device and end every session that came from it. */
    public function revoke(RevokeDeviceRequest $request, StaffDevice $device, RevokeDevice $revoke): JsonResponse
    {
        $device = $revoke->execute($device, $request->user(), $request->validated('reason'));

        return response()->json(['device' => new StaffDeviceResource($device)]);
    }
}
