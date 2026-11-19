<?php

namespace App\Modules\Recruitment\Models;

use App\Modules\Identity\Models\StaffUser;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A call by Recruitment to tell a candidate of their appointment (D-105): an exam booking or an interview, the time and
 * place they were told, and whether they were reached. Append-only.
 *
 * @property int $id
 * @property string $application_id
 * @property ?string $exam_booking_id
 * @property ?string $interview_id
 * @property CarbonInterface $appointment_at
 * @property string $location
 * @property bool $reached
 * @property ?string $note
 * @property CarbonInterface $created_at
 */
class AppointmentCall extends Model
{
    const UPDATED_AT = null;   // a call is never changed

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['appointment_at' => 'datetime', 'reached' => 'boolean', 'created_at' => 'datetime'];
    }

    public function caller(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'called_by');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}
