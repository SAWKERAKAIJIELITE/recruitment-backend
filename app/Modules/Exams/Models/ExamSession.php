<?php

namespace App\Modules\Exams\Models;

use App\Modules\Identity\Models\StaffUser;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Date;

/**
 * A sitting of one published plan version (D-056, D-073): a start, a place, the plan's duration, a late window
 * and the password its candidates log in with. It ends at start + duration + the extensions given to everyone;
 * one attempt may be given more on its own.
 *
 * @property string $id
 * @property CarbonInterface $starts_at
 * @property string $location
 * @property int $duration_minutes
 * @property int $late_minutes
 * @property int $extra_minutes
 */
class ExamSession extends Model
{
    use HasUuids;

    protected $fillable = ['starts_at', 'location', 'late_minutes', 'notes'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'password' => 'hashed', 'duration_minutes' => 'integer', 'late_minutes' => 'integer', 'extra_minutes' => 'integer'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ExamPlanVersion::class, 'plan_version_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(ExamSessionQuestion::class, 'session_id')->orderBy('position');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(ExamBooking::class, 'session_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(ExamAttempt::class, 'session_id');
    }

    public function extensions(): HasMany
    {
        return $this->hasMany(ExamSessionExtension::class, 'session_id')->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'created_by');
    }

    /** The last moment to start; after it, a candidate who has not started is absent. */
    public function lateUntil(): CarbonInterface
    {
        return $this->starts_at->copy()->addMinutes($this->late_minutes);
    }

    /** When every attempt ends, unless one was given more time on its own. */
    public function endsAt(): CarbonInterface
    {
        return $this->starts_at->copy()->addMinutes($this->duration_minutes + $this->extra_minutes);
    }

    public function hasStarted(): bool
    {
        return Date::now()->greaterThanOrEqualTo($this->starts_at);
    }

    /** Someone started: the plan, timing and questions are frozen. */
    public function hasAttempts(): bool
    {
        return $this->attempts()->exists();
    }
}
