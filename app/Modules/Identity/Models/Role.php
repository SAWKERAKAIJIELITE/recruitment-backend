<?php

namespace App\Modules\Identity\Models;

use App\Modules\Identity\Enums\StaffRole;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/** A role: one of the offices, the general manager or the system administrator (D-085). */
class Role extends Model
{
    use HasUuids;

    protected $fillable = ['slug', 'label_ar'];

    /** The stored permissions (the system administrator has none stored: it holds every one, see grantedSlugs()). */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'permission_role');
    }

    public function staffUsers(): HasMany
    {
        return $this->hasMany(StaffUser::class, 'role_id');
    }

    public function kind(): StaffRole
    {
        return StaffRole::from($this->slug);
    }

    public function isAdministrator(): bool
    {
        return $this->slug === StaffRole::SystemAdmin->value;
    }

    /** The permissions the role gives: every one for the system administrator, the stored ones otherwise. */
    public function grantedSlugs(): Collection
    {
        if ($this->isAdministrator()) {
            return Permission::query()->orderBy('slug')->pluck('slug');
        }

        return $this->permissions->pluck('slug')->sort()->values();
    }
}
