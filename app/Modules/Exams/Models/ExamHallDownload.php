<?php

namespace App\Modules\Exams\Models;

use App\Modules\Exams\Enums\HallDownloadStatus;
use App\Modules\Identity\Models\StaffDevice;
use App\Modules\Identity\Models\StaffUser;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One taking-out of an exam session to a hall with no internet (D-102): who took it, on which approved laptop, the
 * bookings and questions it carries, and how it ended. Never deleted.
 *
 * @property string $id
 * @property string $session_id
 * @property string $staff_device_id
 * @property CarbonInterface $downloaded_at
 * @property list<string> $booking_ids
 * @property list<int> $question_ids
 * @property HallDownloadStatus $status
 * @property ?string $upload_sha256
 * @property ?array $upload_summary
 */
class ExamHallDownload extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected $attributes = ['status' => 'out'];

    protected function casts(): array
    {
        return [
            'status' => HallDownloadStatus::class,
            'downloaded_at' => 'datetime',
            'returned_at' => 'datetime',
            'sealed_at' => 'datetime',
            'hall_started_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'replaced_at' => 'datetime',
            'booking_ids' => 'array',
            'question_ids' => 'array',
            'upload_summary' => 'array',
            'committee' => 'array',
            'attendance_count' => 'integer',
            'questions_exposed' => 'boolean',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ExamSession::class, 'session_id');
    }

    public function downloader(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'downloaded_by');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(StaffDevice::class, 'staff_device_id');
    }

    public function isOut(): bool
    {
        return $this->status === HallDownloadStatus::Out;
    }
}
