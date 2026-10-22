<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Actions\ApproveDevice;
use App\Modules\Identity\Actions\RegisterDevice;
use App\Modules\Identity\Actions\RevokeDevice;
use App\Modules\Identity\Http\Requests\ApproveDeviceRequest;
use App\Modules\Identity\Http\Requests\RegisterDeviceRequest;
use App\Modules\Identity\Http\Requests\RevokeDeviceRequest;
use App\Modules\Identity\Http\Resources\DeviceRegistrationResource;
use App\Modules\Identity\Http\Resources\DeviceStatusResource;
use App\Modules\Identity\Http\Resources\StaffDeviceResource;
use App\Modules\Identity\Enums\DeviceStatus;
use App\Modules\Identity\Models\StaffUser;
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
     * Called by the EXE on first run. Creates a pending device (201); idempotent for the same
     * public key (200 with the current status).
     *
     * @unauthenticated
     */
    public function register(
        RegisterDeviceRequest $request,
        RegisterDevice $register
    ): DeviceRegistrationResource|JsonResponse {
        $device = $register->execute(
            $request->validated('public_key'),
            $request->validated('label'),
            $request->validated('mac_address'),
            $request->validated('os_info'),
        );

        // return response()->json([
        //     'device' => [
        //         'id' => $device->id,
        //         'status' => $device->status,
        //         'fingerprint' => $device->displayFingerprint(),
        //     ],
        // ], $device->wasRecentlyCreated ? 201 : 200);
        // Literal status codes (no ternary): the API documentation generator reads them from the code.
        if ($device->wasRecentlyCreated) {
            return (new DeviceRegistrationResource($device))->response()->setStatusCode(201);
        }

        return new DeviceRegistrationResource($device);
    }

    /**
     * Device status
     *
     * Lets the EXE show "waiting for approval". The device id is an unguessable UUID.
     *
     * @unauthenticated
     */
    public function status(StaffDevice $device): DeviceStatusResource
    {
        return new DeviceStatusResource($device);
    }

    /** List devices (optionally filtered by status). */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(DeviceStatus::class)],
        ]);

        $devices = StaffDevice::query()
            ->with('staffUser')
            ->when($filters['status'] ?? null, fn($query, $status) => $query->where('status', $status))
            ->latest()
            ->paginate(15);

        return StaffDeviceResource::collection($devices);
    }

    /** Approve a pending device and make it the property of one staff user. */
    public function approve(ApproveDeviceRequest $request, StaffDevice $device, ApproveDevice $approve): StaffDeviceResource
    {
        $device = $approve->execute(
            $device,
            $request->user(),
            $request->validated('fingerprint'),
            StaffUser::findOrFail($request->validated('staff_user_id')),
        );

        return new StaffDeviceResource($device->load('staffUser'));
    }

    /** Revoke a device and end every session that came from it. */
    public function revoke(RevokeDeviceRequest $request, StaffDevice $device, RevokeDevice $revoke): StaffDeviceResource
    {
        $device = $revoke->execute($device, $request->user(), $request->validated('reason'))->load('staffUser');

        return new StaffDeviceResource($device);
    }
}
