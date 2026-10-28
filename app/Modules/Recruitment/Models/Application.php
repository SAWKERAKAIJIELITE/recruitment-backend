<?php

namespace App\Modules\Recruitment\Models;

use App\Modules\Identity\Models\StaffUser;
use App\Modules\Recruitment\Enums\ApplicationStatus;
use App\Modules\Recruitment\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One nomination of one person for one job position: the main business entity (D-024). */
class Application extends Model
{
    use HasUuids;

    /**
     * Only what the caller supplies. nomination_number and created_by are set by CreateApplication with
     * forceFill(); current_status is written by the database alone, from the status history (D-033).
     */
    protected $fillable = [
        'candidate_id',
        'nominating_entity_id',
        'job_position_id',
        'application_category_id',
        'existing_employee_number',
        'academic_qualification_id',
        'specialization_id',
        'studying_years',
        'studies_discontinued',
        'quran_parts_memorized',
        'marital_status_id',
        'health_condition_id',
        'health_details',
        'residence_district_id',
        'residence_area_id',
        'residence_details',
        'phone_number',
        'works_for_other_government_entity',
        'full_commitment',
        'overnight_work',
        'notes',
    ];

    /** What the database gives a new row; set here so the model is consistent before it is reloaded. */
    protected $attributes = ['current_status' => 'awaiting_exam', 'studies_discontinued' => false];

    protected function casts(): array
    {
        return [
            'current_status' => ApplicationStatus::class,
            'studying_years' => 'integer',
            'studies_discontinued' => 'boolean',
            'quran_parts_memorized' => 'integer',
            'works_for_other_government_entity' => 'boolean',
            'full_commitment' => 'boolean',
            'overnight_work' => 'boolean',
        ];
    }

    protected function phoneNumber(): Attribute
    {
        return Attribute::make(set: fn(string $value) => PhoneNumber::normalize($value));
    }

    /** Applications that have not ended yet: a person can have only one (D-037). */
    public function scopeLive(Builder $query): void
    {
        $query->whereNotIn('current_status', ApplicationStatus::terminalValues());
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    public function nominatingEntity(): BelongsTo
    {
        return $this->belongsTo(NominatingEntity::class)->withTrashed();
    }

    public function jobPosition(): BelongsTo
    {
        return $this->belongsTo(JobPosition::class)->withTrashed();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ApplicationCategory::class, 'application_category_id')->withTrashed();
    }

    public function academicQualification(): BelongsTo
    {
        return $this->belongsTo(AcademicQualification::class)->withTrashed();
    }

    public function specialization(): BelongsTo
    {
        return $this->belongsTo(Specialization::class)->withTrashed();
    }

    public function maritalStatus(): BelongsTo
    {
        return $this->belongsTo(MaritalStatus::class)->withTrashed();
    }

    public function healthCondition(): BelongsTo
    {
        return $this->belongsTo(HealthCondition::class)->withTrashed();
    }

    public function residenceDistrict(): BelongsTo
    {
        return $this->belongsTo(District::class, 'residence_district_id')->withTrashed();
    }

    public function residenceArea(): BelongsTo
    {
        return $this->belongsTo(Area::class, 'residence_area_id')->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'created_by');
    }

    /** Unordered: use history() for the chain in order. */
    public function statusEvents(): HasMany
    {
        return $this->hasMany(ApplicationStatusEvent::class);
    }

    /**
     * The history in chain order, first event first, each with its `previousEvent` relation set (so the
     * status it came from is known without another query). Uses the loaded statusEvents when present.
     *
     * @return Collection<int, ApplicationStatusEvent>
     */
    public function history(): Collection
    {
        $events = $this->relationLoaded('statusEvents') ? $this->statusEvents : $this->statusEvents()->get();

        $byPrevious = $events->keyBy(fn(ApplicationStatusEvent $event) => $event->previous_event_id ?? '');
        $ordered = new Collection();
        $previous = null;

        // The database guarantees one first event and no forks, so this walks every event exactly once.
        while (($event = $byPrevious->get($previous?->id ?? '')) !== null) {
            $event->setRelation('previousEvent', $previous);
            $ordered->push($event);
            $previous = $event;
        }

        return $ordered;
    }
}
