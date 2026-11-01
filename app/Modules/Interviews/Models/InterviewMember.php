<?php

namespace App\Modules\Interviews\Models;

use App\Modules\Identity\Models\StaffUser;
use App\Modules\Interviews\Enums\MemberCapacity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A committee member of a sitting: a name and a capacity (الصفة), linked to a staff account when the member is a
 * system user. Frozen once a result is recorded in the sitting (database trigger).
 *
 * @property MemberCapacity $capacity
 */
class InterviewMember extends Model
{
    public $timestamps = false;

    protected $fillable = ['position', 'name', 'capacity', 'staff_user_id'];

    protected function casts(): array
    {
        return ['capacity' => MemberCapacity::class];
    }

    public function staffUser(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class);
    }
}
