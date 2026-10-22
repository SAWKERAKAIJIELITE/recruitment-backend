<?php

namespace App\Modules\Recruitment\Models;

use App\Modules\Identity\Models\StaffUser;
use App\Modules\Recruitment\Enums\ApplicationStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One nomination of one person for one job title (the main business entity). */
class Application extends Model
{
    use HasUuids;

    /**
     * Only what the caller supplies. nomination_number, current_status, created_by and
     * availability_declared_at are decided by the system and set with forceFill() in CreateApplication;
     * current_status then changes only through ApplicationLifecycle.
     */
    protected $fillable = [
        'candidate_id', 'job_title_id', 'source_office_id', 'category_id', 'existing_employee_number', 'notes',
        'works_for_other_government_entity', 'can_stay_overnight', 'full_time_available',
    ];

    protected $attributes = ['current_status' => 'awaiting_exam'];

    protected function casts(): array
    {
        return [
            'current_status' => ApplicationStatus::class,
            'works_for_other_government_entity' => 'boolean',
            'can_stay_overnight' => 'boolean',
            'full_time_available' => 'boolean',
            'availability_declared_at' => 'datetime',
        ];
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    public function jobTitle(): BelongsTo
    {
        return $this->belongsTo(JobTitle::class)->withTrashed();
    }

    public function sourceOffice(): BelongsTo
    {
        return $this->belongsTo(SourceOffice::class)->withTrashed();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ApplicationCategory::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'created_by');
    }

    public function statusEvents(): HasMany
    {
        return $this->hasMany(ApplicationStatusEvent::class)->orderBy('occurred_at')->orderBy('created_at');
    }
}
