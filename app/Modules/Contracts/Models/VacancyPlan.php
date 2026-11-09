<?php

namespace App\Modules\Contracts\Models;

use App\Modules\Identity\Models\StaffUser;
use App\Support\BusinessDay;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A vacancies file (D-088): how many first contracts each job position may get from starts_on to ends_on (included).
 * In force until another plan replaces it.
 *
 * @property string $id
 * @property CarbonInterface $starts_on
 * @property CarbonInterface $ends_on
 * @property ?CarbonInterface $replaced_at
 */
class VacancyPlan extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'replaced_at' => 'datetime',
        ];
    }

    /** How long a plan lasts (owner: three months). */
    public static function months(): int
    {
        return (int) config('contracts.vacancy_months', 3);
    }

    public function items(): HasMany
    {
        return $this->hasMany(VacancyPlanItem::class, 'plan_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'uploaded_by');
    }

    /** The plan this one corrected. */
    public function replaces(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_plan_id');
    }

    /** The plan that corrected this one. */
    public function replacement(): HasOne
    {
        return $this->hasOne(self::class, 'replaces_plan_id');
    }

    /** Not replaced. */
    public function scopeInForce(Builder $query): Builder
    {
        return $query->whereNull('replaced_at');
    }

    /** The plan in force on that day (a Damascus day, D-081), if any. */
    public function scopeCovering(Builder $query, string $day): Builder
    {
        return $query->inForce()->where('starts_on', '<=', $day)->where('ends_on', '>=', $day);
    }

    /** upcoming, current, past or replaced. */
    public function state(): string
    {
        $today = BusinessDay::today();

        return match (true) {
            $this->replaced_at !== null => 'replaced',
            $this->starts_on->toDateString() > $today => 'upcoming',
            $this->ends_on->toDateString() < $today => 'past',
            default => 'current',
        };
    }
}
