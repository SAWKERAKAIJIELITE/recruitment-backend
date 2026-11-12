<?php

namespace App\Modules\Interviews\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Interviews\Actions\ManageInterviews;
use App\Modules\Interviews\Enums\InterviewResult;
use App\Modules\Interviews\Enums\InterviewStatus;
use App\Modules\Interviews\Http\Requests\CorrectResultRequest;
use App\Modules\Interviews\Http\Requests\InterviewExcuseRequest;
use App\Modules\Interviews\Http\Requests\ReasonRequest;
use App\Modules\Interviews\Http\Requests\RecordResultRequest;
use App\Modules\Interviews\Http\Requests\RescheduleInterviewRequest;
use App\Modules\Interviews\Http\Requests\ScheduleInterviewRequest;
use App\Modules\Interviews\Http\Resources\InterviewResource;
use App\Modules\Interviews\Models\Interview;
use App\Modules\Recruitment\Enums\DecisionType;
use App\Modules\Recruitment\Models\Application;
use App\Support\ArabicText;
use App\Support\BusinessDay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Interviews (analysis doc §07, §08; D-059). Reading: interviews.view.
 * Scheduling, rescheduling, cancelling and recording: interviews.manage. Correcting a recorded result:
 * interviews.correct. Accepting an absence excuse: interviews.excuse (D-097).
 */
class InterviewController extends Controller
{
    /**
     * List interviews
     *
     * Soonest first, 25 per page (`per_page` up to 100). Filters: `q` (the candidate's name in any spelling, or the
     * national or nomination number in any digits), `status` (scheduled, recorded, cancelled), `result` (passed,
     * failed, absent), `excused` (with result=absent: 1 excuse accepted, 0 not), `sitting_id`, `committee_name` and
     * `location` (part of), `job_position_id`, `nominating_entity_id`, `residence_district_id`, `from` / `to`
     * (appointment day in Damascus, included). `sort`: scheduled_at (default), held_at, nomination_number, full_name;
     * `direction`: asc (default) or desc.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', Rule::enum(InterviewStatus::class)],
            'result' => ['nullable', Rule::enum(InterviewResult::class)],
            'excused' => ['nullable', Rule::in(['0', '1', 'true', 'false'])],
            'sitting_id' => ['nullable', 'uuid'],
            'committee_name' => ['nullable', 'string', 'max:150'],
            'location' => ['nullable', 'string', 'max:150'],
            'job_position_id' => ['nullable', 'integer', Rule::exists('job_positions', 'id')],
            'nominating_entity_id' => ['nullable', 'integer', Rule::exists('nominating_entities', 'id')],
            'residence_district_id' => ['nullable', 'integer', Rule::exists('districts', 'id')],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'sort' => ['nullable', Rule::in(['scheduled_at', 'held_at', 'nomination_number', 'full_name'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $direction = $filters['direction'] ?? 'asc';
        $person = fn() => DB::table('applications')->whereColumn('applications.id', 'interviews.application_id');

        $excuse = fn($query) => $query->select(DB::raw(1))->from('application_status_events')
            ->whereColumn('application_status_events.interview_id', 'interviews.id')
            ->where('decision_type', DecisionType::ExcuseAccepted->value);

        $interviews = Interview::query()
            ->with(InterviewResource::RELATIONS)
            ->whenGiven($filters['q'] ?? null, function ($query, string $q) {
                $digits = ArabicText::digitsToAscii(trim($q));
                $like = '%' . addcslashes(ArabicText::searchKey($q), '\\%_') . '%';

                $query->whereHas('application', fn($application) => $application->where(fn($inner) => $inner
                    ->where('nomination_number', $digits)
                    ->orWhereHas('candidate', fn($candidate) => $candidate->where('national_number', $digits)->orWhere('name_search_key', 'like', $like))));
            })
            ->whenGiven($filters['status'] ?? null, fn($query, $value) => $query->where('status', $value))
            ->whenGiven($filters['result'] ?? null, fn($query, $value) => $query->where('result', $value))
            ->when(isset($filters['excused']), fn($query) => $request->boolean('excused') ? $query->whereExists($excuse) : $query->whereNotExists($excuse))
            ->whenGiven($filters['sitting_id'] ?? null, fn($query, $value) => $query->where('sitting_id', $value))
            ->whenGiven($filters['committee_name'] ?? null, fn($query, $value) => $query->whereHas('sitting', fn($sitting) => $sitting->where('committee_name', 'ilike', '%' . addcslashes($value, '\\%_') . '%')))
            ->whenGiven($filters['location'] ?? null, fn($query, $value) => $query->whereHas('sitting', fn($sitting) => $sitting->where('location', 'ilike', '%' . addcslashes($value, '\\%_') . '%')))
            ->whenGiven($filters['job_position_id'] ?? null, fn($query, $value) => $query->whereHas('application', fn($application) => $application->where('job_position_id', $value)))
            ->whenGiven($filters['nominating_entity_id'] ?? null, fn($query, $value) => $query->whereHas('application', fn($application) => $application->where('nominating_entity_id', $value)))
            ->whenGiven($filters['residence_district_id'] ?? null, fn($query, $value) => $query->whereHas('application', fn($application) => $application->where('residence_district_id', $value)))
            ->whenGiven($filters['from'] ?? null, fn($query, $date) => $query->where('scheduled_at', '>=', BusinessDay::startOf($date)))
            ->whenGiven($filters['to'] ?? null, fn($query, $date) => $query->where('scheduled_at', '<', BusinessDay::startOfNext($date)))
            ->when(
                $filters['sort'] ?? 'scheduled_at',
                fn($query, string $sort) => match ($sort) {
                    'nomination_number' => $query->orderBy($person()->select('applications.nomination_number'), $direction),
                    'full_name' => $query->orderBy($person()->join('candidates', 'candidates.id', '=', 'applications.candidate_id')->select('candidates.name_search_key'), $direction),
                    'held_at' => $query->orderByRaw('held_at ' . $direction . ' nulls last'),
                    default => $query->orderBy('scheduled_at', $direction),
                },
            )
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->withQueryString();

        return InterviewResource::collection($interviews);
    }

    /** Every interview round of one application, first round first. */
    public function forApplication(Application $application): AnonymousResourceCollection
    {
        return InterviewResource::collection(
            Interview::query()->with([...InterviewResource::RELATIONS, ...InterviewResource::DETAILS])->where('application_id', $application->id)->orderBy('round')->get(),
        );
    }

    /** One interview with its reschedules and every version of its result. */
    public function show(Interview $interview): InterviewResource
    {
        return $this->resource($interview);
    }

    /**
     * Schedule an interview
     *
     * Only for an application awaiting its interview (passed the exams, or by exception), and one waiting interview at
     * a time. Book it into a sitting (`sitting_id`: a committee, or a one-off sitting's single interviewer), at the
     * candidate's time `scheduled_at` (default: the sitting's start, or 30 minutes after its latest interview). 422 on
     * `scheduled_at` before the sitting starts or at or after its end, or less than 5 minutes from another interview
     * of the sitting (D-096); on `sitting_id` once the sitting has ended.
     */
    public function store(ScheduleInterviewRequest $request, Application $application, ManageInterviews $interviews): JsonResponse
    {
        $interview = $interviews->schedule($application, $request->validated(), $request->user());

        return $this->resource($interview)->response()->setStatusCode(201);
    }

    /**
     * Reschedule an interview
     *
     * With a `reason` (kept in its reschedules). Another sitting (`sitting_id`; the time defaults to its next free
     * time, as when scheduling), a new time (`scheduled_at`; alone, in the same sitting), or both, within the sitting's
     * time and 5 minutes at least from its other interviews. 422 `same_appointment` when nothing changes; 422 on
     * `interview` once its sitting has ended (record it, absent if so, or cancel it), on `sitting_id` when the new
     * sitting has ended.
     */
    public function reschedule(RescheduleInterviewRequest $request, Interview $interview, ManageInterviews $interviews): InterviewResource
    {
        return $this->resource($interviews->reschedule($interview, $request->safe()->except('reason'), $request->validated('reason'), $request->user()));
    }

    /** Cancel a waiting interview, with a `reason`. The application stays awaiting its interview. */
    public function cancel(ReasonRequest $request, Interview $interview, ManageInterviews $interviews): InterviewResource
    {
        return $this->resource($interviews->cancel($interview, $request->validated('reason'), $request->user()));
    }

    /**
     * Record the committee's result
     *
     * Moves the application: passed → awaiting_contract, failed → interview_failed, absent → interview_absent.
     * The interview is the cause of that history event, dated `held_at` (default: the appointment).
     */
    public function record(RecordResultRequest $request, Interview $interview, ManageInterviews $interviews): InterviewResource
    {
        return $this->resource($interviews->record($interview, $request->values(), $request->user()));
    }

    /**
     * Correct a recorded result
     *
     * Permission interviews.correct. Send the fields that change and a `reason`; a new revision keeps
     * every version. A changed result moves the application with a correction event, only while that result is
     * still the application's latest decision (422 otherwise).
     */
    public function correct(CorrectResultRequest $request, Interview $interview, ManageInterviews $interviews): InterviewResource
    {
        return $this->resource($interviews->correct($interview, $request->changes(), $request->validated('reason'), $request->user()));
    }

    /**
     * Accept an interview absence excuse
     *
     * Permission interviews.excuse (D-097). The application goes back to awaiting its interview; the absent
     * result stays. With `appointment`, the next round is booked in the same step and returned (otherwise 204).
     * `decision_number`: the decision's paper (رقم القرار, optional; D-100).
     */
    public function excuse(InterviewExcuseRequest $request, Application $application, ManageInterviews $interviews): JsonResponse|InterviewResource
    {
        // Booking the next round is scheduling: it needs interviews.manage as well.
        abort_if($request->validated('appointment') !== null && ! $request->user()->hasPermission('interviews.manage'), 403);

        $next = $interviews->acceptExcuse(
            $application,
            $request->validated('reason'),
            $request->validated('occurred_at') === null ? null : Date::parse($request->validated('occurred_at')),
            $request->validated('appointment'),
            $request->user(),
            $request->validated('decision_number'),
        );

        return $next === null ? response()->json(null, 204) : $this->resource($next);
    }

    private function resource(Interview $interview): InterviewResource
    {
        return new InterviewResource($interview->load([...InterviewResource::RELATIONS, ...InterviewResource::DETAILS]));
    }
}
