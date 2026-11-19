<?php

namespace App\Modules\Recruitment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Recruitment\Actions\ApplicationLifecycle;
use App\Modules\Recruitment\Actions\CreateApplication;
use App\Modules\Recruitment\Actions\RecordAppointmentCalls;
use App\Modules\Recruitment\Actions\UpdateApplication;
use App\Modules\Recruitment\Enums\ApplicationStatus;
use App\Modules\Recruitment\Enums\DecisionType;
use App\Modules\Recruitment\Exceptions\IllegalStatusTransition;
use App\Modules\Recruitment\Http\Requests\CloseApplicationRequest;
use App\Modules\Recruitment\Http\Requests\RecordCallsRequest;
use App\Modules\Recruitment\Http\Requests\StartApplicationsRequest;
use App\Modules\Recruitment\Http\Requests\StoreApplicationRequest;
use App\Modules\Recruitment\Http\Requests\UpdateApplicationRequest;
use App\Modules\Recruitment\Http\Resources\ApplicationIdentityResource;
use App\Modules\Recruitment\Http\Resources\ApplicationResource;
use App\Modules\Recruitment\Http\Resources\ApplicationSummaryResource;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Queries\ApplicationSearch;
use App\Modules\Recruitment\Queries\Appointments;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;

class ApplicationController extends Controller
{
    /** Who may read the list and one application (the full file needs applications.view; D-086). */
    public const READERS = 'applications.view,exams.view,interviews.view,contracts.view,documents.view';

    /**
     * Search applications
     *
     * The full rows with `applications.view` (Recruitment, the general manager); the other offices get who the
     * candidate is and where the application stands (`id`, `nomination_number`, `status`, `job_position`, `candidate`
     * with `full_name` and `national_number`), and only the filters `q`, `national_number`, `status`, `live`,
     * `job_position_id`, `import_batch_id`, `from`, `to` (any other is refused, 422).
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
     *   `age_min`/`age_max` (whole years today), `from`/`to` (opening date, Damascus days). `studying_years`: exact.
     * - `created_by`: the staff user who opened it; `import_batch_id`: imported by that batch.
     * - `call_state` (D-105): `to_inform`, `not_reached` or `informed` about the appointment ahead (an exam booked, an
     *   interview scheduled), or `any` (every application with one). Each full row has `appointment`: its kind, time,
     *   place, call state and last call; null without one.
     * - `sort`: `created_at` (default, newest first), `updated_at` (the latest change first: a correction or a status
     *   move), `nomination_number`, `full_name`, `birth_date`, `status`;
     *   `direction`: `asc` or `desc`. `per_page`: 5 to 100 (25).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        ApplicationSearch::prepare($request);
        $full = $request->user()->hasPermission('applications.view');

        if (! $full) {
            ApplicationSearch::assertIdentityOnly($request);
        }

        $filters = $request->validate(ApplicationSearch::rules());

        $applications = ApplicationSearch::apply(Application::query()->with($full ? ApplicationSummaryResource::RELATIONS : ApplicationIdentityResource::RELATIONS), $filters)
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->withQueryString();

        if (! $full) {
            return ApplicationIdentityResource::collection($applications);
        }

        // Recruitment's calls about the appointments ahead (D-105), for the page in two queries.
        $appointments = Appointments::of($applications->getCollection(), Date::now());
        $applications->getCollection()->each(fn(Application $application) => $application->setAttribute('appointment', $appointments[$application->id] ?? null));

        return ApplicationSummaryResource::collection($applications);
    }

    /**
     * Count applications per status
     *
     * For the tiles above the list: how many applications the list's filters match in each status, every status
     * listed (0 included) in the lifecycle's order, with `total` and `live`. Same filters and meaning as the list
     * (`from` / `to`: the opening day in Damascus); `status`, `live`, sorting and paging are not used, so each tile
     * keeps its count whichever is chosen. The other offices may use the identity filters only, as in the list.
     *
     * @response array{data: array{total: int, live: int, statuses: list<array{status: string, label: string, live: bool, count: int}>}}
     */
    public function counts(Request $request): JsonResponse
    {
        ApplicationSearch::prepare($request);

        if (! $request->user()->hasPermission('applications.view')) {
            ApplicationSearch::assertIdentityOnly($request);
        }

        $filters = array_diff_key($request->validate(ApplicationSearch::rules()), array_flip(['status', 'live', 'sort', 'direction', 'per_page']));
        $counts = ApplicationSearch::filter(Application::query(), $filters)
            ->reorder()
            ->toBase()
            ->groupBy('applications.current_status')
            ->selectRaw('applications.current_status as status, count(*) as count')
            ->pluck('count', 'status');

        $statuses = array_map(fn(ApplicationStatus $status) => [
            'status' => $status->value,
            'label' => $status->labelAr(),
            'live' => $status->isLive(),
            'count' => (int) ($counts[$status->value] ?? 0),
        ], ApplicationStatus::cases());

        return response()->json(['data' => [
            'total' => array_sum(array_column($statuses, 'count')),
            'live' => array_sum(array_column(array_filter($statuses, fn(array $row) => $row['live']), 'count')),
            'statuses' => $statuses,
        ]]);
    }

    /**
     * Record calls about appointments
     *
     * Recruitment told the candidates (or tried to) of the appointment their application waits for (D-105): an exam
     * booked (until its session's late window closes) or an interview scheduled (until its time). `application_ids`
     * (1 to 500), `reached` (true: informed; false: not reached), optional `note`. For information only: nothing moves
     * and nothing is blocked. A call is about the time and place as they stand: when either changes, the application is
     * `to_inform` again. All or nothing: an application without an appointment ahead is 422 on `application_ids.N`.
     * Answers each application's `appointment` after the calls.
     *
     * @response array{data: list<array{application_id: string, appointment: array}>}
     */
    public function calls(RecordCallsRequest $request, RecordAppointmentCalls $calls): JsonResponse
    {
        $appointments = $calls->record($request->validated('application_ids'), $request->boolean('reached'), $request->validated('note'), $request->user());

        return response()->json(['data' => array_map(fn(string $id) => ['application_id' => $id, 'appointment' => $appointments[$id]], $request->validated('application_ids'))], 201);
    }

    /**
     * Open an application
     *
     * Finds the person by national number (or creates them), takes the next nomination number and starts
     * the history: the application is `received`. With `start` = true it is started at once (awaiting its exam;
     * needs `applications.start`, 403 otherwise). `candidate_matched` is true when the person was already on file:
     * the person's stored data is kept and the `candidate` fields sent are ignored. 422 when the person already has
     * a live application, or when the area does not belong to the district.
     */
    public function store(StoreApplicationRequest $request, CreateApplication $create): JsonResponse
    {
        $start = $request->boolean('start');
        abort_if($start && ! $request->user()->hasPermission('applications.start'), 403);

        $result = $create->execute($request->safe()->except('start'), $request->user(), $start);

        $application = $result['application']->load(ApplicationResource::RELATIONS);

        return (new ApplicationResource($application))
            ->additional(['candidate_matched' => $result['candidate_matched']])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * One application
     *
     * With `applications.view`, the full file with the person and the status history; the other offices get who
     * the candidate is and where the application stands, as in the list.
     */
    public function show(Request $request, Application $application): ApplicationResource|ApplicationIdentityResource
    {
        if (! $request->user()->hasPermission('applications.view')) {
            return new ApplicationIdentityResource($application->load(ApplicationIdentityResource::RELATIONS));
        }

        $application->load(ApplicationResource::RELATIONS);
        // The appointment ahead and every call about it (D-105).
        $application->setAttribute('appointment', Appointments::of(collect([$application]), Date::now(), withCalls: true)[$application->id] ?? null);

        return new ApplicationResource($application);
    }

    /**
     * Start a received application
     *
     * It now awaits its exam, and the Exams and Interviews office is notified. 422 when it is not `received`.
     */
    public function start(Request $request, Application $application, ApplicationLifecycle $lifecycle): ApplicationResource
    {
        try {
            $lifecycle->start($application, $request->user());
        } catch (IllegalStatusTransition) {
            throw ValidationException::withMessages(['status' => [__('recruitment.illegal_transition', ['status' => $application->fresh()->current_status->labelAr()])]]);
        }

        return new ApplicationResource($application->load(ApplicationResource::RELATIONS));
    }

    /**
     * Start many received applications
     *
     * Either `application_ids` (1 to 1,000; each must be `received`, otherwise nothing starts and 422 names the
     * others), or `filters`: the filters of the applications list (for example `{"import_batch_id": "…"}` for the
     * applications of an import, with `job_position_id`, `residence_district_id`, `age_max`, …), applied to the
     * received applications only (422 when none matches, or more than 5,000). All or none, in one transaction; the
     * offices get one notification for all of them. Returns how many started and their ids.
     */
    public function startMany(StartApplicationsRequest $request, ApplicationLifecycle $lifecycle): JsonResponse
    {
        $ids = $request->applicationIds();
        $refused = $lifecycle->startMany($ids, $request->user());

        if ($refused !== []) {
            $numbers = Application::query()->whereKey($refused)->orderBy('nomination_number')->limit(20)->pluck('nomination_number')->implode('، ');

            throw ValidationException::withMessages(['application_ids' => [__('recruitment.not_received', ['numbers' => $numbers])]]);
        }

        return response()->json(['data' => ['started' => count($ids), 'application_ids' => $ids]]);
    }

    /**
     * Close an application
     *
     * Ends the application (status `closed`) so the person can be nominated again. Needs the
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
    private function decisionDate(?string $value): ?CarbonInterface
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
