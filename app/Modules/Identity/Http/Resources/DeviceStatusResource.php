<?php

namespace App\Modules\Identity\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Modules\Identity\Models\StaffDevice */
class DeviceStatusResource extends JsonResource
{
    public static $wrap = null;   // {"status": "pending"} without a "data" envelope

    public function toArray(Request $request): array
    {
        return ['status' => $this->status];
    }
}
