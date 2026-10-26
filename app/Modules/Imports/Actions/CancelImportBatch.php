<?php

namespace App\Modules\Imports\Actions;

use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Imports\Enums\ImportBatchStatus;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Support\ImportProblem;
use Illuminate\Support\Facades\DB;

/** Abandons a batch before approval: nothing is imported, the file and rows stay as a record. */
class CancelImportBatch
{
    public function execute(ImportBatch $batch, StaffUser $actor, string $reason): ImportBatch
    {
        return DB::transaction(function () use ($batch, $actor, $reason) {
            $batch = ImportBatch::query()->whereKey($batch->getKey())->lockForUpdate()->firstOrFail();

            if (! $batch->isPending()) {
                ImportProblem::fail('batch', 'batch_not_pending', ['status' => __("imports.statuses.{$batch->status->value}")]);
            }

            $batch->forceFill([
                'status' => ImportBatchStatus::Cancelled,
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
                'cancel_reason' => trim($reason),
            ])->save();

            AuditTrail::record('import.cancelled', $actor, ['batch_id' => $batch->id, 'reason' => trim($reason)]);

            return $batch;
        });
    }
}
