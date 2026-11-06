<?php

namespace App\Modules\Audit\Models;

use App\Modules\Identity\Models\StaffUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded action (D-078). Append-only (database trigger).
 *
 * @property int $id
 * @property string $event
 * @property string $category
 * @property ?string $actor_id
 * @property ?string $candidate_id
 * @property array $context
 * @property ?string $ip
 */
class AuditEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['context' => 'array', 'created_at' => 'datetime'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'actor_id');
    }
}
