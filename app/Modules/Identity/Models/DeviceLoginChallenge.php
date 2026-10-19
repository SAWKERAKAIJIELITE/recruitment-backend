<?php

namespace App\Modules\Identity\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

class DeviceLoginChallenge extends Model
{
    use HasUuids, MassPrunable;

    const UPDATED_AT = null;   // created_at only

    protected $fillable = ['staff_device_id', 'challenge', 'expires_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime'];
    }

    /** Expired challenges are ephemeral: safe to delete (see D-007 exception rule). */
    public function prunable(): Builder
    {
        return static::where('expires_at', '<', now()->subDay());
    }
}
