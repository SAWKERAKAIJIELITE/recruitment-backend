<?php

namespace App\Modules\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class StaffDevice extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REVOKED = 'revoked';

    protected $fillable = ['label', 'public_key', 'fingerprint', 'reported_mac', 'os_info', 'status'];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function staffUsers(): BelongsToMany
    {
        return $this->belongsToMany(StaffUser::class, 'device_assignments', 'staff_device_id', 'staff_user_id')
            ->withPivot('assigned_by')
            ->withTimestamps();
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    /** What humans compare: first 128 bits, uppercase, groups of four. */
    public function displayFingerprint(): string
    {
        return implode(' ', str_split(strtoupper(substr($this->fingerprint, 0, 32)), 4));
    }
}
