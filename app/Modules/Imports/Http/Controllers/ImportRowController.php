<?php

namespace App\Modules\Imports\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Imports\Actions\ReviewImportRows;
use App\Modules\Imports\Enums\ImportRowStatus;
use App\Modules\Imports\Http\Requests\ConfirmPersonRequest;
use App\Modules\Imports\Http\Requests\CorrectImportRowRequest;
use App\Modules\Imports\Http\Requests\ReasonRequest;
use App\Modules\Imports\Http\Resources\ImportRowResource;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportRow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** The rows of an import batch: preview, corrections and review decisions before approval. */
class ImportRowController extends Controller
{
    /** Rows of the batch in sheet order, 50 per page; `status` filters (for example `invalid`). */
    public function index(Request $request, ImportBatch $batch): AnonymousResourceCollection
    {
        $filters = $request->validate(['status' => ['nullable', Rule::enum(ImportRowStatus::class)]]);

        $rows = $batch->rows()
            ->with(ImportRowResource::RELATIONS)
            ->when($filters['status'] ?? null, fn($query, string $status) => $query->where('status', $status))
            ->paginate(50);

        return ImportRowResource::collection($rows);
    }

    public function show(ImportBatch $batch, ImportRow $row): ImportRowResource
    {
        return new ImportRowResource($row->load(ImportRowResource::RELATIONS));
    }

    /**
     * Correct a row
     *
     * `values` maps dotted keys to new values, for example `{"job_position_id": 4, "candidate.birth_date":
     * "1995-03-01", "phone_number": "0912345678"}`. List fields take the entry id; booleans true/false; dates
     * YYYY-MM-DD; null clears a value. The whole batch is checked again. The cells as read are kept.
     */
    public function update(CorrectImportRowRequest $request, ImportBatch $batch, ImportRow $row, ReviewImportRows $review): ImportRowResource
    {
        return new ImportRowResource($review->correct($row, $request->validated('values'), $request->user())->load(ImportRowResource::RELATIONS));
    }

    /** Confirm that the national number is the person already on file (their stored data is kept). */
    public function confirmPerson(ConfirmPersonRequest $request, ImportBatch $batch, ImportRow $row, ReviewImportRows $review): ImportRowResource
    {
        return new ImportRowResource($review->confirmPerson($row, $request->user(), $request->validated('note'))->load(ImportRowResource::RELATIONS));
    }

    /** Exclude the row from the import, with a reason. */
    public function exclude(ReasonRequest $request, ImportBatch $batch, ImportRow $row, ReviewImportRows $review): ImportRowResource
    {
        return new ImportRowResource($review->exclude($row, $request->user(), $request->validated('reason'))->load(ImportRowResource::RELATIONS));
    }

    /** Bring an excluded row back (it is checked again). */
    public function include(Request $request, ImportBatch $batch, ImportRow $row, ReviewImportRows $review): ImportRowResource
    {
        return new ImportRowResource($review->include($row, $request->user())->load(ImportRowResource::RELATIONS));
    }
}
