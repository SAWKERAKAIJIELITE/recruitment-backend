<?php

namespace App\Modules\Identity\Models;

use App\Modules\Identity\Enums\DeviceStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffDevice extends Model
{
    use HasUuids;

    /**
     * Mass assignment whitelist: only what a registering device supplies (or we derive from its key).
     * status, staff_user_id, approved_by/at and revoked_by/at/reason are decided by business rules
     * (the Approve/Revoke actions) and are set explicitly with forceFill(), never from request data.
     */
    protected $fillable = ['label', 'public_key', 'fingerprint', 'reported_mac', 'os_info'];

    /** New devices start pending (also correct on the in-memory model, not only in the database). */
    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return [
            'status' => DeviceStatus::class,
            'approved_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    // public function staffUsers(): BelongsToMany
    // {
    //     return $this->belongsToMany(StaffUser::class, 'device_assignments', 'staff_device_id', 'staff_user_id')
    //         ->withPivot('assigned_by')
    //         ->withTimestamps();
    // }

    /** The staff user who owns this device (null until approved). */
    public function staffUser(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'staff_user_id');
    }

    public function isApproved(): bool
    {
        return $this->status === DeviceStatus::Approved;
    }

    /** What humans compare: first 128 bits, uppercase, groups of four. */
    public function displayFingerprint(): string
    {
        return implode(' ', str_split(strtoupper(substr($this->fingerprint, 0, 32)), 4));
    }
}
