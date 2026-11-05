<?php

namespace App\Modules\Interviews\Models;

use App\Modules\Identity\Models\StaffUser;
use App\Modules\Interviews\Enums\InterviewStatus;
use App\Modules\Interviews\Enums\SittingKind;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Where, when and before whom interviews happen (D-059, D-061, D-067): a committee sitting, or a one-off sitting (a
 * single interviewer, exactly one member). Both hold many candidates, each with their own time from its start. It
 * always has at least one member, and no other sitting has the same start, place and members.
 *
 * @property string $id
 * @property SittingKind $kind
 * @property \Carbon\CarbonInterface $starts_at
 * @property string $location
 * @property ?string $committee_name
 */
class InterviewSitting extends Model
{
    use HasUuids;

    protected $fillable = ['starts_at', 'location', 'committee_name', 'notes'];

    protected $attributes = ['kind' => 'committee'];

    protected function casts(): array
    {
        return ['kind' => SittingKind::class, 'starts_at' => 'datetime'];
    }

    public function members(): HasMany
    {
        return $this->hasMany(InterviewMember::class, 'sitting_id')->orderBy('position');
    }

    public function interviews(): HasMany
    {
        return $this->hasMany(Interview::class, 'sitting_id')->orderBy('scheduled_at')->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'created_by');
    }

    public function isOneOff(): bool
    {
        return $this->kind === SittingKind::OneOff;
    }

    /** Its committee is frozen once a result was entered for any of its interviews. */
    public function hasResults(): bool
    {
        return $this->interviews()->where('status', InterviewStatus::Recorded)->exists();
    }
}
