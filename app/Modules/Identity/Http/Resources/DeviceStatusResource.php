<?php

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Models\StaffDevice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StaffDevice */
class DeviceStatusResource extends JsonResource
{
    public static $wrap = null;   // {"status": "pending"} without a "data" envelope

    public function toArray(Request $request): array
    {
        return ['status' => $this->status];
    }
}
