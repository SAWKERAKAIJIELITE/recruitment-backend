<?php

namespace App\Modules\Contracts\Models;

use App\Modules\Contracts\Enums\ContractState;
use App\Modules\Documents\Models\Document;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Recruitment\Contracts\StatusChangeCause;
use App\Modules\Recruitment\Models\Application;
use App\Support\BusinessDay;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Date;

/**
 * One contract of an application: the first (sequence 1) or a renewal of the previous one (D-064). The first contract
 * causes contract_written; the end of the latest causes contract_ended.
 *
 * @property string $id
 * @property string $application_id
 * @property int $sequence
 * @property ?string $renews_contract_id
 * @property CarbonInterface $written_on
 * @property CarbonInterface $starts_on
 * @property ?CarbonInterface $ends_on
 * @property ?CarbonInterface $ended_on
 */
class Contract extends Model implements StatusChangeCause
{
    use HasUuids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'written_on' => 'date',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'ended_on' => 'date',
            'ended_at' => 'datetime',
            'expiry_reminded_at' => 'datetime',
        ];
    }

    public static function statusEventColumn(): string
    {
        return 'contract_id';
    }

    /** Days before the end date when a contract counts as ending soon and its reminder goes out. */
    public static function reminderDays(): int
    {
        return (int) config('contracts.reminder_days', 14);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(ContractType::class, 'contract_type_id')->withTrashed();
    }

    public function renews(): BelongsTo
    {
        return $this->belongsTo(self::class, 'renews_contract_id');
    }

    public function renewal(): HasOne
    {
        return $this->hasOne(self::class, 'renews_contract_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'created_by');
    }

    public function ender(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'ended_by');
    }

    /** Neither renewed nor ended: the one that decides the application's situation. */
    public function scopeLatestInChain(Builder $query): Builder
    {
        return $query->whereNull('contracts.ended_on')
            ->whereNotExists(fn($renewal) => $renewal->from('contracts as renewal')->whereColumn('renewal.renews_contract_id', 'contracts.id'));
    }

    /** Contracts in a state today (the same rules as state()). */
    public function scopeInState(Builder $query, ContractState $state): Builder
    {
        $today = BusinessDay::today();
        $soon = BusinessDay::todayDate()->addDays(self::reminderDays())->toDateString();
        $renewed = fn ($renewal) => $renewal->from('contracts as renewal')->whereColumn('renewal.renews_contract_id', 'contracts.id');

        return match ($state) {
            ContractState::Ended => $query->whereNotNull('contracts.ended_on'),
            ContractState::Renewed => $query->whereExists($renewed),
            ContractState::Upcoming => $query->latestInChain()->where('starts_on', '>', $today),
            ContractState::Expired => $query->latestInChain()->where('ends_on', '<', $today),
            ContractState::EndingSoon => $query->latestInChain()->where('starts_on', '<=', $today)->whereBetween('ends_on', [$today, $soon]),
            ContractState::Active => $query->latestInChain()->where('starts_on', '<=', $today)->where(fn($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>', $soon)),
        };
    }

    /** Needs the renewal relation (loaded, or loaded here). */
    public function state(): ContractState
    {
        $today = BusinessDay::todayDate();

        return match (true) {
            $this->ended_on !== null => ContractState::Ended,
            $this->renewal !== null => ContractState::Renewed,
            $this->starts_on->greaterThan($today) => ContractState::Upcoming,
            $this->ends_on === null => ContractState::Active,
            $this->ends_on->lessThan($today) => ContractState::Expired,
            $this->ends_on->lessThanOrEqualTo($today->copy()->addDays(self::reminderDays())) => ContractState::EndingSoon,
            default => ContractState::Active,
        };
    }

    /** Days left until the end date (0 on the last day, negative once passed); null without an end date. */
    public function daysLeft(): ?int
    {
        return $this->ends_on === null ? null : (int) BusinessDay::todayDate()->diffInDays($this->ends_on, false);
    }
}
