<?php

namespace App\Modules\Recruitment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Recruitment\Actions\ApplicationLifecycle;
use App\Modules\Recruitment\Actions\CreateApplication;
use App\Modules\Recruitment\Actions\UpdateApplication;
use App\Modules\Recruitment\Enums\DecisionType;
use App\Modules\Recruitment\Exceptions\IllegalStatusTransition;
use App\Modules\Recruitment\Http\Requests\CloseApplicationRequest;
use App\Modules\Recruitment\Enums\ApplicationStatus;
use App\Modules\Recruitment\Http\Requests\StoreApplicationRequest;
use App\Modules\Recruitment\Http\Requests\UpdateApplicationRequest;
use App\Modules\Recruitment\Http\Resources\ApplicationResource;
use App\Modules\Recruitment\Http\Resources\ApplicationSummaryResource;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Queries\ApplicationSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;

class ApplicationController extends Controller
{
    /**
     * Search applications
     *
     * Every filter is optional; they combine with AND, and 0 or false filter too.
     *
     * - `q`: a nomination number exactly, or part of a name in any spelling (أ/ا, ى/ي, ة/ه).
     * - `national_number`, `phone_number`, `existing_employee_number`: exact (Arabic-Indic digits accepted);
     *   `has_employee_number`: 1 or 0.
     * - `status`: one status, or several (`status[]=exam_failed&status[]=exam_absent`); `live`: 1 (not ended) or 0.
     * - List entries by id: `job_position_id`, `nominating_entity_id`, `application_category_id`,
     *   `academic_qualification_id`, `specialization_id`, `marital_status_id`, `health_condition_id`,
     *   `residence_district_id`, `residence_area_id`, and the person's `birth_place_id`.
     * - Yes/no (1/0, true/false): `studies_discontinued`, `works_for_other_government_entity`, `full_commitment`,
     *   `overnight_work`, `candidate_active`.
     * - Ranges (included): `studying_years_min`/`_max`, `quran_parts_min`/`_max`, `birth_date_from`/`_to`,
     *   `age_min`/`age_max` (whole years today), `from`/`to` (opening date, UTC days). `studying_years`: exact.
     * - `created_by`: the staff user who opened it.
     * - `sort`: `created_at` (default, newest first), `nomination_number`, `full_name`, `birth_date`, `status`;
     *   `direction`: `asc` or `desc`. `per_page`: 5 to 100 (25).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        ApplicationSearch::prepare($request);
        $filters = $request->validate(ApplicationSearch::rules());

        $applications = ApplicationSearch::apply(Application::query()->with(ApplicationSummaryResource::RELATIONS), $filters)
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->withQueryString();

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

    /**
     * Correct an application
     *
     * Send only the fields that change (the same fields and rules as when opening one, without `candidate`).
     * Only a live application can be corrected; an ended one is history (422). The person is corrected with
     * PATCH /candidates/{id}; the status changes only through decisions. Audited.
     */
    public function update(UpdateApplicationRequest $request, Application $application, UpdateApplication $update): ApplicationResource
    {
        $update->execute($application, $request->validated(), $request->user());

        return new ApplicationResource($application->load(ApplicationResource::RELATIONS));
    }
}
