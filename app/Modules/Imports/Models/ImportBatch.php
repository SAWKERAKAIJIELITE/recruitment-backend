<?php

namespace App\Modules\Imports\Models;

use App\Modules\Identity\Models\StaffUser;
use App\Modules\Imports\Enums\ImportBatchStatus;
use App\Modules\Imports\Enums\ImportRowStatus;
use App\Modules\Recruitment\Models\District;
use App\Modules\Recruitment\Models\NominatingEntity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One uploaded Excel file and its rows. Nothing is mass assignable: batches are written only by the
 * import actions (D-021).
 */
class ImportBatch extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'status' => ImportBatchStatus::class,
            'column_map' => 'array',
            'ignored_columns' => 'array',
            'file_size' => 'integer',
            'header_row' => 'integer',
            'approved_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** Adds rows_count and one count per row status (valid_count, needs_review_count, ...). */
    public function scopeWithRowCounts(Builder $query): void
    {
        $counts = ['rows'];

        foreach (ImportRowStatus::cases() as $status) {
            $counts["rows as {$status->value}_count"] = fn(Builder $rows) => $rows->where('status', $status->value);
        }

        $query->withCount($counts);
    }

    public function isPending(): bool
    {
        return $this->status === ImportBatchStatus::Pending;
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class)->orderBy('row_number');
    }

    public function nominatingEntity(): BelongsTo
    {
        return $this->belongsTo(NominatingEntity::class)->withTrashed();
    }

    public function sourceDistrict(): BelongsTo
    {
        return $this->belongsTo(District::class, 'source_district_id')->withTrashed();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'uploaded_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'approved_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'cancelled_by');
    }
}
