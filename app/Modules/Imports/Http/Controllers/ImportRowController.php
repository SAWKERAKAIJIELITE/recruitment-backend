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
use App\Support\ArabicText;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** The rows of an import batch: preview, corrections and review decisions before approval. */
class ImportRowController extends Controller
{
    /**
     * List a batch's rows
     *
     * In sheet order, 50 per page. `status` filters (for example `invalid`); `q` finds a row by the name read (any
     * spelling, a part of it), the national number (exact, any digits) or its row number in the sheet.
     */
    public function index(Request $request, ImportBatch $batch): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(ImportRowStatus::class)],
            'q' => ['nullable', 'string', 'max:150'],
        ]);

        $rows = $batch->rows()
            ->with(ImportRowResource::RELATIONS)
            ->whenGiven($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->whenGiven($filters['q'] ?? null, fn ($query, string $q) => $query->whereIn('id', $this->matching($batch, $q)))
            ->paginate(50);

        return ImportRowResource::collection($rows);
    }

    /**
     * The rows whose name, national number or row number match: names are compared as ArabicText normalizes them (in
     * PHP: a batch holds 2,000 rows at most).
     *
     * @return list<string>
     */
    private function matching(ImportBatch $batch, string $q): array
    {
        $key = ArabicText::searchKey(trim($q));
        $digits = ArabicText::digitsToAscii(trim($q));

        return $batch->rows()->toBase()
            ->get(['id', 'row_number', DB::raw("data->>'candidate.full_name' as full_name"), DB::raw("data->>'candidate.national_number' as national_number")])
            ->filter(fn (object $row) => ($row->national_number !== null && $row->national_number === $digits)
                || (string) $row->row_number === $digits
                || ($key !== '' && $row->full_name !== null && str_contains(ArabicText::searchKey($row->full_name), $key)))
            ->pluck('id')
            ->all();
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
