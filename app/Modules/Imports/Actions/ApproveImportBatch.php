<?php

namespace App\Modules\Imports\Actions;

use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Imports\Enums\ImportBatchStatus;
use App\Modules\Imports\Enums\ImportRowStatus;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportRow;
use App\Modules\Imports\Support\ImportChecker;
use App\Modules\Imports\Support\ImportProblem;
use App\Modules\Recruitment\Actions\CreateApplication;
use App\Modules\Recruitment\Support\NominationNumberGenerator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Analysis doc §09 step 6, owner's rule (D-047): approving needs the individual permission imports.approve,
 * and never by the staff member who uploaded the batch.
 *
 * Approving a batch turns each valid row into a person (or the person on file),
 * an application and a nomination number, through the same CreateApplication as the API (D-041).
 *
 * - Everyone the batch names is locked first (the API takes the same lock), then the batch is checked
 *   again: lists, people and live applications may have changed since upload. Under those locks the
 *   check cannot be overtaken, so a row that passes it will be created.
 * - Rows that still need review block the approval: each must be confirmed or excluded first.
 * - A row that was valid but fails the final check (someone opened an application for that person
 *   meanwhile) blocks the approval too: staff see it before deciding, it is never left out silently.
 * - All valid rows are created in one transaction, without a savepoint per row, numbered in sheet order
 *   from one reservation. If anything still fails, nothing is imported and the batch stays pending.
 * - Invalid and excluded rows stay in the batch, with their reasons, and are not imported.
 */
class ApproveImportBatch
{
    /** Row data keys that go into CreateApplication (everything except helper keys). */
    private const INPUT_KEYS = [
        'nominating_entity_id', 'job_position_id', 'application_category_id', 'existing_employee_number',
        'academic_qualification_id', 'specialization_id', 'studying_years', 'studies_discontinued', 'quran_parts_memorized',
        'marital_status_id', 'health_condition_id', 'health_details',
        'residence_district_id', 'residence_area_id', 'residence_details', 'phone_number',
        'works_for_other_government_entity', 'full_commitment', 'overnight_work', 'notes',
        'candidate.national_number', 'candidate.full_name', 'candidate.mother_name',
        'candidate.birth_date', 'candidate.birth_place_id',
    ];

    public function __construct(
        private readonly ImportChecker $checker,
        private readonly CreateApplication $create,
        private readonly NominationNumberGenerator $numbers,
    ) {
    }

    public function execute(ImportBatch $batch, StaffUser $actor): ImportBatch
    {
        return DB::transaction(function () use ($batch, $actor) {
            $batch = ImportBatch::query()->whereKey($batch->getKey())->lockForUpdate()->firstOrFail();

            if (! $batch->isPending()) {
                ImportProblem::fail('batch', 'batch_not_pending', ['status' => __("imports.statuses.{$batch->status->value}")]);
            }

            // Four eyes (D-047): whoever uploaded the file cannot approve it.
            if ($batch->uploaded_by === $actor->id) {
                throw new AuthorizationException(__('imports.own_batch'));
            }

            CreateApplication::lockPeople(
                $batch->rows()->get()->map(fn(ImportRow $row) => $row->nationalNumber())->filter()->values()->all(),
            );

            // What staff last saw as valid must still be valid: a row must never be left out silently.
            $validBefore = $batch->rows()->where('status', ImportRowStatus::Valid)->pluck('row_number', 'id');

            $this->checker->check($batch);

            $changed = $batch->rows()->whereIn('id', $validBefore->keys())->where('status', '<>', ImportRowStatus::Valid)->pluck('row_number');

            if ($changed->isNotEmpty()) {
                // Rolled back with everything else; revalidate (or open the rows) to see the new problems.
                ImportProblem::fail('batch', 'rows_changed', ['rows' => $changed->sort()->implode('، ')]);
            }

            $reviews = $batch->rows()->where('status', ImportRowStatus::NeedsReview)->count();

            if ($reviews > 0) {
                ImportProblem::fail('batch', 'unresolved_reviews', ['count' => $reviews]);
            }

            $rows = $batch->rows()->where('status', ImportRowStatus::Valid)->get();
            $numbers = $this->numbers->reserve($rows->count());
            $now = now();

            foreach ($rows->values() as $index => $row) {
                $this->importRow($row, $actor, $numbers[$index], $now);
            }

            $batch->forceFill([
                'status' => ImportBatchStatus::Approved,
                'approved_by' => $actor->id,
                'approved_at' => $now,
            ])->save();

            AuditTrail::record('import.approved', $actor, ['batch_id' => $batch->id, 'imported' => $rows->count()]);

            return $batch;
        });
    }

    private function importRow(ImportRow $row, StaffUser $actor, string $nominationNumber, $now): void
    {
        try {
            $result = $this->create->createLocked(Arr::undot(Arr::only($row->data, self::INPUT_KEYS)), $actor, $nominationNumber);
        } catch (ValidationException $e) {
            // Should not happen under the locks; if it does, the whole approval is rolled back.
            ImportProblem::fail('batch', 'import_failed_row', [
                'row' => $row->row_number,
                'message' => collect($e->errors())->flatten()->first() ?? $e->getMessage(),
            ]);
        }

        $row->forceFill([
            'status' => ImportRowStatus::Imported,
            'application_id' => $result['application']->id,
            'candidate_id' => $result['application']->candidate_id,
            'imported_at' => $now,
        ])->save();
    }
}
