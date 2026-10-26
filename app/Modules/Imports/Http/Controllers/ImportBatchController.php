<?php

namespace App\Modules\Imports\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Imports\Actions\ApproveImportBatch;
use App\Modules\Imports\Actions\BuildImportReport;
use App\Modules\Imports\Actions\CancelImportBatch;
use App\Modules\Imports\Actions\UploadImportBatch;
use App\Modules\Imports\Enums\ImportBatchStatus;
use App\Modules\Imports\Http\Requests\ReasonRequest;
use App\Modules\Imports\Http\Requests\UploadImportRequest;
use App\Modules\Imports\Http\Resources\ImportBatchResource;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Actions\ReviewImportRows;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Excel import batches (analysis doc §09). Everything here needs imports.manage. */
class ImportBatchController extends Controller
{
    /** List batches, newest first, with row counts per status. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate(['status' => ['nullable', Rule::enum(ImportBatchStatus::class)]]);

        $batches = ImportBatch::query()
            ->with(ImportBatchResource::RELATIONS)
            ->withRowCounts()
            ->when($filters['status'] ?? null, fn($query, string $status) => $query->where('status', $status))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25);

        return ImportBatchResource::collection($batches);
    }

    /**
     * Upload a file
     *
     * Multipart: `file` (.xlsx or .xls, the legacy template), optional `nominating_entity_id` (used for rows
     * whose entity cell is empty), `source_district_id`, `notes`. The original is kept, the candidates sheet
     * is read (columns found by header text), and every row is checked. Nothing is imported yet.
     * 422 when the file cannot be read, required columns are missing, it has no data rows, or the same file
     * was already uploaded in a batch that was not cancelled.
     */
    public function store(UploadImportRequest $request, UploadImportBatch $upload): JsonResponse
    {
        $batch = $upload->execute(
            $request->file('file'),
            $request->user(),
            $request->validated('nominating_entity_id') === null ? null : (int) $request->validated('nominating_entity_id'),
            $request->validated('source_district_id') === null ? null : (int) $request->validated('source_district_id'),
            $request->validated('notes'),
        );

        return (new ImportBatchResource($this->fresh($batch)))->response()->setStatusCode(201);
    }

    public function show(ImportBatch $batch): ImportBatchResource
    {
        return new ImportBatchResource($this->fresh($batch));
    }

    /** Check every open row again (for example after adding a missing entry to a list). */
    public function revalidate(ImportBatch $batch, ReviewImportRows $review): ImportBatchResource
    {
        $review->revalidate($batch);

        return new ImportBatchResource($this->fresh($batch));
    }

    /**
     * Approve the batch
     *
     * Needs the individual permission `imports.approve`; the staff member who uploaded the batch cannot
     * approve it (403).
     *
     * Checks the rows again, then turns every valid row into an application with a new nomination number.
     * 422 when rows still need review (confirm or exclude them first), or when a row that was valid is no
     * longer valid (for example, the person got an application meanwhile): look at those rows, then approve
     * again. Invalid and excluded rows are not imported and keep their reasons. All or nothing.
     */
    public function approve(Request $request, ImportBatch $batch, ApproveImportBatch $approve): ImportBatchResource
    {
        return new ImportBatchResource($this->fresh($approve->execute($batch, $request->user())));
    }

    /** Cancel the batch before approval. Nothing is imported; the same file can then be uploaded again. */
    public function cancel(ReasonRequest $request, ImportBatch $batch, CancelImportBatch $cancel): ImportBatchResource
    {
        return new ImportBatchResource($this->fresh($cancel->execute($batch, $request->user(), $request->validated('reason'))));
    }

    /** Download the original file, unchanged. */
    public function original(ImportBatch $batch): StreamedResponse
    {
        $type = str_ends_with(strtolower($batch->stored_path), '.xls')
            ? 'application/vnd.ms-excel'
            : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        // Always a download with a fixed type: never rendered or sniffed by the browser.
        return Storage::disk(config('imports.disk'))->download($batch->stored_path, $batch->original_filename, [
            'Content-Type' => $type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Download the accepted / rejected report of the batch (.xlsx). */
    public function report(ImportBatch $batch, BuildImportReport $report): BinaryFileResponse
    {
        $name = pathinfo($batch->original_filename, PATHINFO_FILENAME) . ' - ' . __('imports.report.title') . '.xlsx';

        return response()
            ->download($report->execute($batch), $name, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'X-Content-Type-Options' => 'nosniff',
            ])
            ->deleteFileAfterSend();
    }

    private function fresh(ImportBatch $batch): ImportBatch
    {
        return ImportBatch::query()->whereKey($batch->getKey())->with(ImportBatchResource::RELATIONS)->withRowCounts()->firstOrFail();
    }
}
