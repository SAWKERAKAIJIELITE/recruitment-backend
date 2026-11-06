<?php

namespace App\Modules\Exams\Models;

use App\Modules\Exams\Enums\BookingStatus;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Recruitment\Contracts\StatusChangeCause;
use App\Modules\Recruitment\Models\Application;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * An application booked into an exam session: one round of its exam (D-074). It is the cause of the status change
 * it leads to (absent, passed, failed). Cancelled, never deleted.
 *
 * @property string $id
 * @property string $session_id
 * @property string $application_id
 * @property int $round
 * @property BookingStatus $status
 * @property ?CarbonInterface $decided_at
 */
class ExamBooking extends Model implements StatusChangeCause
{
    use HasUuids;

    protected $guarded = ['*'];

    protected $attributes = ['status' => 'booked'];

    protected function casts(): array
    {
        return ['status' => BookingStatus::class, 'decided_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public static function statusEventColumn(): string
    {
        return 'exam_booking_id';
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ExamSession::class, 'session_id');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function attempt(): HasOne
    {
        return $this->hasOne(ExamAttempt::class, 'booking_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'created_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'cancelled_by');
    }
}
