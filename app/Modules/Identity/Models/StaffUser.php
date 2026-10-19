<?php

namespace App\Modules\Identity\Models;

use App\Modules\Identity\Database\Factories\StaffUserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;

class StaffUser extends Authenticatable
{
    use HasApiTokens, HasFactory, HasUuids;

    // public const TYPE_STAFF = 'staff';
    // public const TYPE_CANDIDATE = 'candidate';

    protected $fillable = [
        // 'type',
        'username',
        'first_name',
        'last_name',
        'personal_number',
        'password',
        'is_active',
        // 'must_change_password',
    ];

    protected $hidden = ['password'];

    /** Per-request cache of effective permission slugs. */
    private ?Collection $permissionSlugCache = null;

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            // 'must_change_password' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    /** Usernames are case-insensitive: always stored trimmed and lowercase. */
    protected function username(): Attribute
    {
        return Attribute::make(set: fn(string $value) => mb_strtolower(trim($value)));
    }

    protected function getFullNameAttribute(): string
    {
        return mb_ucfirst($this->first_name) . ' ' . mb_ucfirst($this->last_name);
    }

    // We use token auth only; there is no remember_token column.
    public function getRememberTokenName(): string
    {
        return '';
    }

    protected static function newFactory(): StaffUserFactory
    {
        return StaffUserFactory::new();
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_staff_user');
    }

    /** Direct per-user overrides (grant or deny). */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'permission_staff_user')
            ->withPivot(['is_granted', 'granted_by'])
            ->withTimestamps();
    }

    /**
     * Effective permissions = (permissions from roles + direct grants) - direct denials.
     */
    public function permissionSlugs(): Collection
    {
        return $this->permissionSlugCache ??= $this->resolvePermissionSlugs();
    }

    public function hasPermission(string $slug): bool
    {
        return $this->permissionSlugs()->contains($slug);
    }

    public function forgetPermissionCache(): void
    {
        $this->permissionSlugCache = null;
    }

    private function resolvePermissionSlugs(): Collection
    {
        $fromRoles = $this->roles()->with('permissions')->get()
            ->flatMap(fn(Role $role) => $role->permissions->pluck('slug')->all())
            ->all();

        $direct = $this->permissions()->get();
        $granted = $direct->filter(fn($p) => (bool) $p->pivot->is_granted)->pluck('slug')->all();
        $denied = $direct->reject(fn($p) => (bool) $p->pivot->is_granted)->pluck('slug')->all();

        return collect($fromRoles)->merge($granted)->unique()->diff($denied)->values();
    }

    public function devices(): BelongsToMany
    {
        return $this->belongsToMany(StaffDevice::class, 'device_assignments', 'staff_user_id', 'staff_device_id');
    }
}
