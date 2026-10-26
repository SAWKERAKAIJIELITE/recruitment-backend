<?php

namespace App\Modules\Imports\Actions;

use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Imports\Enums\ImportRowStatus;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportRow;
use App\Modules\Imports\Support\ImportChecker;
use App\Modules\Imports\Support\ImportProblem;
use App\Modules\Recruitment\Support\NationalNumber;
use App\Modules\Recruitment\Support\PhoneNumber;
use App\Support\ArabicText;
use Illuminate\Support\Facades\DB;

/**
 * Analysis doc §09 step 4: what staff do with rows before approval. Correct values, confirm that a national
 * number belongs to the person on file, exclude a row or bring it back. Every change is audited and the
 * whole batch is checked again (a corrected national number can create or remove a duplicate elsewhere).
 */
class ReviewImportRows
{
    /** Correctable keys and the kind of value each accepts. */
    private const KINDS = [
        'nominating_entity_id' => 'id', 'job_position_id' => 'id', 'application_category_id' => 'id',
        'academic_qualification_id' => 'id', 'specialization_id' => 'id', 'marital_status_id' => 'id',
        'health_condition_id' => 'id', 'residence_district_id' => 'id', 'residence_area_id' => 'id',
        'candidate.birth_place_id' => 'id',
        'candidate.full_name' => 'text', 'candidate.mother_name' => 'text', 'existing_employee_number' => 'text',
        'health_details' => 'text', 'residence_details' => 'text', 'notes' => 'text',
        'candidate.national_number' => 'national_number', 'phone_number' => 'phone',
        'candidate.birth_date' => 'date',
        'studying_years' => 'integer', 'quran_parts_memorized' => 'integer', 'studies_discontinued' => 'boolean',
        'works_for_other_government_entity' => 'boolean', 'full_commitment' => 'boolean', 'overnight_work' => 'boolean',
    ];

    public function __construct(private readonly ImportChecker $checker)
    {
    }

    /** @return list<string> */
    public static function correctableKeys(): array
    {
        return array_keys(self::KINDS);
    }

    /** @param  array<string, mixed>  $values  dotted key => new value (null clears it) */
    public function correct(ImportRow $row, array $values, StaffUser $actor): ImportRow
    {
        return $this->inOpenBatch($row, function (ImportRow $row) use ($values, $actor) {
            $this->assertOpen($row);

            $data = $row->data;
            $parseIssues = $row->parse_issues ?? [];
            $changes = [];

            foreach ($values as $key => $value) {
                $new = $this->cast((string) $key, $value);
                $changes[$key] = ['from' => $data[$key] ?? null, 'to' => $new];
                $data[$key] = $new;
                unset($parseIssues[$key]);   // the staff value replaces whatever the cell said
            }

            $row->forceFill(['data' => $data, 'parse_issues' => (object) $parseIssues])->save();

            AuditTrail::record('import.row_corrected', $actor, $this->context($row) + ['changes' => $changes]);

            return $row;
        });
    }

    /** "Yes, this national number is the person on file": the stored person's data is kept on approval. */
    public function confirmPerson(ImportRow $row, StaffUser $actor, ?string $note): ImportRow
    {
        return $this->inOpenBatch($row, function (ImportRow $row) use ($actor, $note) {
            $this->assertOpen($row);

            if (! collect($row->issues)->contains('code', 'person_mismatch')) {
                ImportProblem::fail('row', 'nothing_to_confirm');
            }

            $row->forceFill([
                'confirmed_national_number' => $row->nationalNumber(),
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ])->save();

            AuditTrail::record('import.person_confirmed', $actor, $this->context($row) + ['candidate_id' => $row->candidate_id]);

            return $row;
        });
    }

    public function exclude(ImportRow $row, StaffUser $actor, string $reason): ImportRow
    {
        return $this->inOpenBatch($row, function (ImportRow $row) use ($actor, $reason) {
            $this->assertOpen($row);

            $row->forceFill([
                'status' => ImportRowStatus::Excluded,
                'excluded_by' => $actor->id,
                'excluded_at' => now(),
                'exclude_reason' => trim($reason),
            ])->save();

            AuditTrail::record('import.row_excluded', $actor, $this->context($row) + ['reason' => trim($reason)]);

            return $row;
        });
    }

    public function include(ImportRow $row, StaffUser $actor): ImportRow
    {
        return $this->inOpenBatch($row, function (ImportRow $row) use ($actor) {
            if ($row->status !== ImportRowStatus::Excluded) {
                ImportProblem::fail('row', 'row_final', ['status' => __("imports.statuses.{$row->status->value}")]);
            }

            // The checker decides the real status right after.
            $row->forceFill(['status' => ImportRowStatus::Invalid, 'excluded_by' => null, 'excluded_at' => null, 'exclude_reason' => null])->save();

            AuditTrail::record('import.row_included', $actor, $this->context($row));

            return $row;
        });
    }

    /** Checks every open row again (after list changes), under the same batch lock as every other change. */
    public function revalidate(ImportBatch $batch): void
    {
        DB::transaction(fn() => $this->checker->check($this->lockOpenBatch($batch->getKey())));
    }

    /** Locks the batch (approval locks it too), runs the change, then checks the whole batch again. */
    private function inOpenBatch(ImportRow $row, callable $change): ImportRow
    {
        return DB::transaction(function () use ($row, $change) {
            $batch = $this->lockOpenBatch($row->import_batch_id);

            $row->refresh();
            $change($row);
            $this->checker->check($batch);

            return $row->refresh();
        });
    }

    private function lockOpenBatch(string $batchId): ImportBatch
    {
        $batch = ImportBatch::query()->whereKey($batchId)->lockForUpdate()->firstOrFail();

        if (! $batch->isPending()) {
            ImportProblem::fail('batch', 'batch_not_pending', ['status' => __("imports.statuses.{$batch->status->value}")]);
        }

        return $batch;
    }

    private function assertOpen(ImportRow $row): void
    {
        if (! $row->status->isChecked()) {
            ImportProblem::fail('row', 'row_final', ['status' => __("imports.statuses.{$row->status->value}")]);
        }
    }

    private function cast(string $key, mixed $value): mixed
    {
        $kind = self::KINDS[$key] ?? null;

        if ($kind === null) {
            ImportProblem::fail("values.{$key}", 'unknown_field', ['field' => $key]);
        }

        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        $invalid = fn() => ImportProblem::fail("values.{$key}", 'invalid_correction', ['field' => __("imports.fields.{$key}")]);

        return match ($kind) {
            'id' => is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : $invalid(),
            'integer' => is_int($value) || (is_string($value) && preg_match('/^\d{1,4}$/', ArabicText::digitsToAscii($value)) === 1)
                ? (int) ArabicText::digitsToAscii((string) $value) : $invalid(),
            'boolean' => is_bool($value) || in_array($value, [0, 1, '0', '1'], true) ? (bool) $value : $invalid(),
            'text' => is_string($value) ? trim(preg_replace('/\s+/u', ' ', $value) ?? $value) : $invalid(),
            // Separators are dropped; any other character makes the value invalid (never silently removed).
            'national_number' => is_string($value) && preg_match('/^[\d\s\-.]+$/u', ArabicText::digitsToAscii($value)) === 1
                ? NationalNumber::normalize($value) : $invalid(),
            'phone' => is_string($value) ? PhoneNumber::normalize($value) : $invalid(),
            'date' => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', ArabicText::digitsToAscii($value)) === 1
                ? ArabicText::digitsToAscii($value) : $invalid(),
        };
    }

    private function context(ImportRow $row): array
    {
        return ['batch_id' => $row->import_batch_id, 'row_id' => $row->id, 'row_number' => $row->row_number];
    }
}
