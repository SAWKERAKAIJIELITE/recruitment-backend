<?php

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Models\StaffDevice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What the public registration endpoint returns: only what the device itself needs to show.
 *
 * @mixin StaffDevice
 */
class DeviceRegistrationResource extends JsonResource
{
    public static $wrap = 'device';

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'fingerprint' => $this->displayFingerprint(),
        ];
    }
}
