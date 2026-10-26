<?php

namespace App\Modules\Recruitment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Recruitment\Actions\ApplicationLifecycle;
use App\Modules\Recruitment\Actions\CreateApplication;
use App\Modules\Recruitment\Enums\DecisionType;
use App\Modules\Recruitment\Exceptions\IllegalStatusTransition;
use App\Modules\Recruitment\Http\Requests\CloseApplicationRequest;
use App\Modules\Recruitment\Enums\ApplicationStatus;
use App\Modules\Recruitment\Http\Requests\StoreApplicationRequest;
use App\Modules\Recruitment\Http\Resources\ApplicationResource;
use App\Modules\Recruitment\Http\Resources\ApplicationSummaryResource;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Support\NationalNumber;
use App\Support\ArabicText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ApplicationController extends Controller
{
    /**
     * Search applications
     *
     * `q` matches a nomination number exactly, or part of a name in any spelling (أ/ا, ى/ي, ة/ه).
     * `national_number` is an exact match (Arabic-Indic digits accepted). Newest first, 25 per page.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:150'],
            'national_number' => ['nullable', 'string', 'max:30'],
            'status' => ['nullable', Rule::enum(ApplicationStatus::class)],
            'job_position_id' => ['nullable', 'integer'],
            'nominating_entity_id' => ['nullable', 'integer'],
            'application_category_id' => ['nullable', 'integer'],
            'residence_district_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $applications = Application::query()
            ->with(['candidate', 'jobPosition', 'nominatingEntity'])
            ->when($filters['q'] ?? null, function ($query, string $q) {
                $like = '%' . addcslashes(ArabicText::searchKey($q), '\\%_') . '%';

                $query->where(fn($inner) => $inner
                    ->where('nomination_number', ArabicText::digitsToAscii(trim($q)))
                    ->orWhereHas('candidate', fn($candidate) => $candidate->where('name_search_key', 'like', $like)));
            })
            ->when($filters['national_number'] ?? null, fn($query, string $number) => $query->whereHas(
                'candidate',
                fn($candidate) => $candidate->where('national_number', NationalNumber::normalize($number)),
            ))
            ->when($filters['status'] ?? null, fn($query, string $status) => $query->where('current_status', $status))
            ->when($filters['job_position_id'] ?? null, fn($query, $id) => $query->where('job_position_id', $id))
            ->when($filters['nominating_entity_id'] ?? null, fn($query, $id) => $query->where('nominating_entity_id', $id))
            ->when($filters['application_category_id'] ?? null, fn($query, $id) => $query->where('application_category_id', $id))
            ->when($filters['residence_district_id'] ?? null, fn($query, $id) => $query->where('residence_district_id', $id))
            ->when($filters['from'] ?? null, fn($query, string $date) => $query->where('created_at', '>=', $date))
            ->when($filters['to'] ?? null, fn($query, string $date) => $query->where('created_at', '<', now()->parse($date)->addDay()->toDateString()))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25);

        return ApplicationSummaryResource::collection($applications);
    }

    /**
     * Open an application
     *
     * Finds the person by national number (or creates them), takes the next nomination number and starts
     * the history. `candidate_matched` is true when the person was already on file: the person's stored
     * data is kept and the `candidate` fields sent are ignored. 422 when the person already has a live
     * application for this job position, or when the area does not belong to the district.
     */
    public function store(StoreApplicationRequest $request, CreateApplication $create): JsonResponse
    {
        $result = $create->execute($request->validated(), $request->user());

        $application = $result['application']->load(ApplicationResource::RELATIONS);

        return (new ApplicationResource($application))
            ->additional(['candidate_matched' => $result['candidate_matched']])
            ->response()
            ->setStatusCode(201);
    }

    /** One application file with the person and the full status history. */
    public function show(Application $application): ApplicationResource
    {
        return new ApplicationResource($application->load(ApplicationResource::RELATIONS));
    }

    /**
     * Close an application
     *
     * Ends the application (status `closed`) so the person can be nominated again. Needs the individual
     * permission `applications.close` and a reason. `occurred_at` dates the decision if it was taken earlier;
     * it cannot be in the future or before the application's previous decision. 422 when the application
     * has already ended or has a written contract (a contract ends through the Contracts module).
     */
    public function close(CloseApplicationRequest $request, Application $application, ApplicationLifecycle $lifecycle): ApplicationResource
    {
        $occurredAt = $request->validated('occurred_at');

        try {
            $lifecycle->move(
                $application,
                ApplicationStatus::Closed,
                DecisionType::Closure,
                actor: $request->user(),
                reason: $request->validated('reason'),
                occurredAt: $this->decisionDate($occurredAt),
            );
        } catch (IllegalStatusTransition $e) {
            $current = $application->fresh()->current_status;

            // Either the status does not allow closing, or (the only other refusal) the date is wrong.
            throw ValidationException::withMessages($current->decisionRequiredFor(ApplicationStatus::Closed) === null
                ? ['status' => [__('recruitment.illegal_transition', ['status' => $current->labelAr()])]]
                : ['occurred_at' => [$e->getMessage()]]);
        }

        return new ApplicationResource($application->load(ApplicationResource::RELATIONS));
    }

    /**
     * A date without a time means "that day": its end, but never later than now (so closing "today" an
     * application opened today is accepted, and an earlier day still sorts after that day's events).
     */
    private function decisionDate(?string $value): ?\Carbon\CarbonInterface
    {
        if ($value === null) {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return Date::parse($value)->endOfDay()->min(Date::now());
        }

        return Date::parse($value);
    }
}
