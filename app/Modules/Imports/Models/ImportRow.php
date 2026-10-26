<?php

namespace App\Modules\Imports\Models;

use App\Modules\Identity\Models\StaffUser;
use App\Modules\Imports\Enums\ImportRowStatus;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\Candidate;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One data row of an uploaded sheet.
 *
 * - raw: the cells as Excel displayed them, by column letter (never changed)
 * - data: what the import understood, as dotted application input keys; corrections change it
 * - parse_issues: values that could not be read, by key; a correction of that key clears its issue
 * - issues: the result of the last check (errors and review points), recomputed by ImportChecker
 */
class ImportRow extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'status' => ImportRowStatus::class,
            'raw' => 'array',
            'data' => 'array',
            'parse_issues' => 'array',
            'issues' => 'array',
            'row_number' => 'integer',
            'reviewed_at' => 'datetime',
            'excluded_at' => 'datetime',
            'imported_at' => 'datetime',
        ];
    }

    public function value(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function nationalNumber(): ?string
    {
        $value = $this->value('candidate.national_number');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'reviewed_by');
    }

    public function excluder(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'excluded_by');
    }
}
