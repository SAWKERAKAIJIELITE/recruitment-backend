<?php

namespace App\Modules\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    use HasUuids;

    protected $fillable = ['slug', 'label_ar', 'individual_only'];

    protected function casts(): array
    {
        return ['individual_only' => 'boolean'];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'permission_role');
    }
}
