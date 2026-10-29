<?php

namespace App\Modules\Imports\Actions;

use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Imports\Enums\ImportBatchStatus;
use App\Modules\Imports\Enums\ImportField;
use App\Modules\Imports\Enums\ImportRowStatus;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Support\ImportChecker;
use App\Modules\Imports\Support\ImportProblem;
use App\Modules\Imports\Support\ReferenceLookup;
use App\Modules\Imports\Support\RowParser;
use App\Modules\Imports\Support\SheetData;
use App\Modules\Imports\Support\WorkbookReader;
use App\Support\SafeFilename;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Analysis doc §09 steps 1 to 5: keeps the original file, reads the sheet, records every row with what the
 * cells said and what was understood, and checks the rows. Nothing becomes an application here.
 */
class UploadImportBatch
{
    public function __construct(private readonly ImportChecker $checker)
    {
    }

    public function execute(UploadedFile $file, StaffUser $actor, ?int $nominatingEntityId = null, ?int $sourceDistrictId = null, ?string $notes = null): ImportBatch
    {
        $hash = hash_file('sha256', $file->getRealPath());
        $this->assertNotUploadedBefore($hash);

        $sheet = WorkbookReader::read($file->getRealPath());
        $this->assertEveryRowHasANationalNumber($sheet);
        $parser = new RowParser(new ReferenceLookup(), $sheet->calendar, $nominatingEntityId);

        $id = (string) Str::uuid7();
        $extension = in_array(strtolower($file->getClientOriginalExtension()), ['xlsx', 'xls'], true) ? strtolower($file->getClientOriginalExtension()) : 'xlsx';
        $directory = trim((string) config('imports.directory', 'imports'), '/');
        $disk = Storage::disk(config('imports.disk'));
        $path = $disk->putFileAs($directory, $file, "{$id}.{$extension}");

        try {
            return DB::transaction(function () use ($id, $file, $path, $hash, $sheet, $parser, $actor, $nominatingEntityId, $sourceDistrictId, $notes) {
                $batch = new ImportBatch();
                $batch->forceFill([
                    'id' => $id,
                    'original_filename' => SafeFilename::of($file->getClientOriginalName(), 'import.xlsx'),
                    'stored_path' => $path,
                    'file_sha256' => $hash,
                    'file_size' => $file->getSize(),
                    'sheet_name' => mb_substr($sheet->sheetName, 0, 100),
                    'header_row' => $sheet->headerRow,
                    'column_map' => collect($sheet->fields)->map(fn(string $letter) => ['column' => $letter, 'header' => $sheet->headers[$letter]])->all(),
                    'ignored_columns' => array_map(fn(string $letter) => ['column' => $letter, 'header' => $sheet->headers[$letter]], $sheet->ignored),
                    'nominating_entity_id' => $nominatingEntityId,
                    'source_district_id' => $sourceDistrictId,
                    'notes' => $notes,
                    'status' => ImportBatchStatus::Pending,
                    'uploaded_by' => $actor->id,
                ])->save();

                $this->insertRows($batch, $sheet, $parser);
                $this->checker->check($batch);

                AuditTrail::record('import.uploaded', $actor, [
                    'batch_id' => $batch->id,
                    'filename' => $batch->original_filename,
                    'rows' => count($sheet->rows),
                ]);

                return $batch;
            });
        } catch (UniqueConstraintViolationException) {
            // Two uploads of the same file at the same moment: the database let only one through.
            $disk->delete($path);
            $this->assertNotUploadedBefore($hash);
            throw new \RuntimeException('Unexpected unique violation while saving an import batch.');
        } catch (Throwable $e) {
            $disk->delete($path);
            throw $e;
        }
    }

    /** AC-10: the same file is imported once; cancelling its batch frees it. */
    private function assertNotUploadedBefore(string $hash): void
    {
        $earlier = ImportBatch::query()
            ->where('file_sha256', $hash)
            ->where('status', '<>', ImportBatchStatus::Cancelled)
            ->first();

        if ($earlier !== null) {
            ImportProblem::fail('file', 'same_file', [
                'filename' => $earlier->original_filename,
                'date' => $earlier->created_at->toDateString(),
            ]);
        }
    }

    /**
     * The national number is the person's identity: a file with a row that has none is refused as a whole
     * (owner's rule, D-046), naming the rows. A malformed number stays a correctable row error.
     */
    private function assertEveryRowHasANationalNumber(SheetData $sheet): void
    {
        $column = $sheet->fields[ImportField::NationalNumber->value];
        $missing = [];

        foreach ($sheet->rows as $row) {
            if ($row['cells'][$column]->isEmpty()) {
                $missing[] = $row['number'];
            }
        }

        if ($missing !== []) {
            ImportProblem::fail('file', 'missing_national_numbers', [
                'rows' => implode('، ', array_slice($missing, 0, 20)) . (count($missing) > 20 ? '، …' : ''),
                'count' => count($missing),
            ]);
        }
    }


    private function insertRows(ImportBatch $batch, SheetData $sheet, RowParser $parser): void
    {
        $now = now();
        $records = [];

        foreach ($sheet->rows as $row) {
            $cells = [];

            foreach (ImportField::cases() as $field) {
                if (isset($sheet->fields[$field->value])) {
                    $cells[$field->value] = $row['cells'][$sheet->fields[$field->value]];
                }
            }

            $parsed = $parser->parse($cells);

            $records[] = [
                'id' => (string) Str::uuid7(),
                'import_batch_id' => $batch->id,
                'row_number' => $row['number'],
                // As displayed, shortened for the record (the full value went through the parser).
                'raw' => json_encode(array_map(fn($cell) => mb_substr($cell->display, 0, 1000), $row['cells']), JSON_UNESCAPED_UNICODE),
                'data' => json_encode($parsed['data'], JSON_UNESCAPED_UNICODE),
                'parse_issues' => json_encode((object) $parsed['parse_issues'], JSON_UNESCAPED_UNICODE),
                'issues' => '[]',
                'status' => ImportRowStatus::Invalid->value,   // until the checker runs, a few lines below
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($records, 500) as $chunk) {
            DB::table('import_rows')->insert($chunk);
        }
    }
}
