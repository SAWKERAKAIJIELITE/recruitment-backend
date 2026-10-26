<?php

namespace App\Modules\Imports\Http\Resources;

use App\Modules\Recruitment\Http\Resources\ReferenceSummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One import batch: the file, what was read from it, the row counts per status, and who did what.
 *
 * @mixin \App\Modules\Imports\Models\ImportBatch
 */
class ImportBatchResource extends JsonResource
{
    public const RELATIONS = ['nominatingEntity', 'sourceDistrict', 'uploader', 'approver', 'canceller'];

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'original_filename' => $this->original_filename,
            'file_size' => $this->file_size,
            'sheet_name' => $this->sheet_name,
            'header_row' => $this->header_row,
            'status' => $this->status,
            'status_label' => __("imports.statuses.{$this->status->value}"),
            'nominating_entity' => ReferenceSummary::of($this->nominatingEntity),
            'source_district' => ReferenceSummary::of($this->sourceDistrict),
            'notes' => $this->notes,
            // Which column of the sheet became which field, and which columns were not used.
            'columns' => collect($this->column_map)->map(fn(array $column, string $field) => $column + ['field' => $field])->values(),
            'ignored_columns' => $this->ignored_columns,
            // Number of rows in each status (total = all rows of the file).
            'counts' => [
                'total' => (int) $this->rows_count,
                'valid' => (int) $this->valid_count,
                'needs_review' => (int) $this->needs_review_count,
                'invalid' => (int) $this->invalid_count,
                'excluded' => (int) $this->excluded_count,
                'imported' => (int) $this->imported_count,
            ],
            'uploaded_by' => StaffSummary::of($this->uploader),
            'uploaded_at' => $this->created_at,
            'approved_by' => StaffSummary::of($this->approver),
            'approved_at' => $this->approved_at,
            'cancelled_by' => StaffSummary::of($this->canceller),
            'cancelled_at' => $this->cancelled_at,
            'cancel_reason' => $this->cancel_reason,
        ];
    }
}
