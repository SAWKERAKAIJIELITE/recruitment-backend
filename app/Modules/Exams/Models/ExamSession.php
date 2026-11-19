<?php

namespace App\Modules\Exams\Models;

use App\Modules\Exams\Enums\HallDownloadStatus;
use App\Modules\Identity\Models\StaffUser;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Date;

/**
 * A sitting of one published plan version (D-056, D-073): a start, a place, the plan's duration, a late window
 * and the password its candidates log in with. It ends at start + duration + the extensions given to everyone;
 * one attempt may be given more on its own. It can be taken out to a hall with no internet (D-102): it is then frozen
 * until brought back or cancelled. Its results are approved once after it (D-104): until then the applications whose
 * result is decided wait in exam_taken.
 *
 * @property string $id
 * @property CarbonInterface $starts_at
 * @property string $location
 * @property int $duration_minutes
 * @property int $late_minutes
 * @property int $extra_minutes
 * @property ?CarbonInterface $planned_starts_at
 * @property ?CarbonInterface $results_approved_at
 */
class ExamSession extends Model
{
    use HasUuids;

    protected $fillable = ['starts_at', 'location', 'late_minutes', 'notes'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'planned_starts_at' => 'datetime', 'results_approved_at' => 'datetime', 'password' => 'hashed', 'duration_minutes' => 'integer', 'late_minutes' => 'integer', 'extra_minutes' => 'integer'];
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

    /** The taking-out to a hall now running, if any. */
    public function hallDownload(): HasOne
    {
        return $this->hasOne(ExamHallDownload::class, 'session_id')->where('status', HallDownloadStatus::Out->value);
    }

    public function hallDownloads(): HasMany
    {
        return $this->hasMany(ExamHallDownload::class, 'session_id')->orderBy('downloaded_at');
    }

    /** Taken out to a hall: nothing about it changes on the server until it is brought back or cancelled. */
    public function isOut(): bool
    {
        return ExamHallDownload::query()->where('session_id', $this->id)->where('status', HallDownloadStatus::Out)->exists();
    }

    /** Who approved the session's results (D-104). */
    public function resultsApprover(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'results_approved_by');
    }

    /** Bookings whose application waits in exam_taken because of them: the approval moves them (D-104). */
    public function bookingsAwaitingApproval(): HasMany
    {
        return $this->bookings()
            ->where('exam_bookings.status', 'attended')
            ->whereNotNull('exam_bookings.decided_at')
            ->whereHas('application', fn($application) => $application->where('current_status', 'exam_taken'));
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
