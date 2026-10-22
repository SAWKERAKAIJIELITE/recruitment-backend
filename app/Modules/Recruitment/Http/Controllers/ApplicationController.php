<?php

namespace App\Modules\Recruitment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Recruitment\Actions\CreateApplication;
use App\Modules\Recruitment\Enums\ApplicationStatus;
use App\Modules\Recruitment\Http\Requests\StoreApplicationRequest;
use App\Modules\Recruitment\Http\Resources\ApplicationResource;
use App\Modules\Recruitment\Http\Resources\ApplicationSummaryResource;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Support\BlindIndex;
use App\Modules\Recruitment\Support\NationalId;
use App\Support\ArabicText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class ApplicationController extends Controller
{
    /**
     * Search applications
     *
     * `q` matches a nomination number exactly, or part of a name (any spelling of أ/ا, ى/ي, ة/ه).
     * `national_id` is an exact match. Newest first.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:150'],
            'national_id' => ['nullable', 'string', 'max:30'],
            'status' => ['nullable', Rule::enum(ApplicationStatus::class)],
            'job_title_id' => ['nullable', 'uuid'],
            'source_office_id' => ['nullable', 'uuid'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $applications = Application::query()
            ->with(['candidate', 'jobTitle', 'sourceOffice'])
            ->when($filters['q'] ?? null, function ($query, string $q) {
                $like = '%' . addcslashes(ArabicText::searchKey($q), '\\%_') . '%';

                $query->where(fn($inner) => $inner
                    ->where('nomination_number', trim($q))
                    ->orWhereHas('candidate', fn($candidate) => $candidate->where('name_search_key', 'like', $like)));
            })
            ->when($filters['national_id'] ?? null, fn($query, string $id) => $query->whereHas(
                'candidate',
                fn($candidate) => $candidate->where('national_id_hash', BlindIndex::for(NationalId::normalize($id))),
            ))
            ->when($filters['status'] ?? null, fn($query, string $status) => $query->where('current_status', $status))
            ->when($filters['job_title_id'] ?? null, fn($query, string $id) => $query->where('job_title_id', $id))
            ->when($filters['source_office_id'] ?? null, fn($query, string $id) => $query->where('source_office_id', $id))
            ->when($filters['from'] ?? null, fn($query, string $date) => $query->whereDate('created_at', '>=', $date))
            ->when($filters['to'] ?? null, fn($query, string $date) => $query->whereDate('created_at', '<=', $date))
            ->latest()
            ->orderByDesc('id')
            ->paginate(25);

        return ApplicationSummaryResource::collection($applications);
    }

    /**
     * Open an application
     *
     * Finds the person by national ID (or creates them), takes the next nomination number and starts the
     * history. `sensitive` fields need the candidates.view_sensitive permission (otherwise 403).
     * `candidate_matched` is true when the person was already on file: their stored data is not overwritten.
     */
    public function store(StoreApplicationRequest $request, CreateApplication $create): JsonResponse
    {
        $input = $request->validated();

        $sendsSensitive = array_filter($input['sensitive'] ?? [], fn($value) => $value !== null) !== [];

        if ($sendsSensitive && ! $request->user()->can('candidates.view_sensitive')) {
            abort(403, 'Marital and health details need the candidates.view_sensitive permission.');
        }

        $result = $create->execute($input, $request->user());

        $application = $result['application']->load(['candidate.governorate', 'candidate.sensitiveDetails', 'jobTitle', 'sourceOffice', 'category', 'statusEvents.actor']);

        return (new ApplicationResource($application))
            ->additional(['candidate_matched' => $result['candidate_matched']])
            ->response()
            ->setStatusCode(201);
    }

    /** One application file with the person and the full status history. */
    public function show(Request $request, Application $application): ApplicationResource
    {
        $application->load(['candidate.governorate', 'candidate.sensitiveDetails', 'jobTitle', 'sourceOffice', 'category', 'statusEvents.actor']);

        if ($request->user()->can('candidates.view_sensitive')) {
            AuditTrail::record('candidate.sensitive_viewed', $request->user(), [
                'application_id' => $application->id,
                'candidate_id' => $application->candidate_id,
            ]);
        }

        return new ApplicationResource($application);
    }
}
