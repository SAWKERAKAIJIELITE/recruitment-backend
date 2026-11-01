<?php

namespace App\Modules\Interviews\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Interviews\Actions\ManageInterviews;
use App\Modules\Interviews\Actions\ManageSittings;
use App\Modules\Interviews\Enums\SittingKind;
use App\Modules\Interviews\Http\Requests\BookSittingRequest;
use App\Modules\Interviews\Http\Requests\SittingRequest;
use App\Modules\Interviews\Http\Resources\InterviewResource;
use App\Modules\Interviews\Http\Resources\InterviewSittingResource;
use App\Modules\Interviews\Models\Interview;
use App\Modules\Interviews\Models\InterviewSitting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\Rule;

/**
 * Sittings (D-059, D-061). Reading: interviews.manage, candidates.manage or reports.view. Changing and booking:
 * interviews.manage.
 */
class InterviewSittingController extends Controller
{
    /**
     * List sittings
     *
     * Committee sittings by default (`kind=one_off` for one-off appointments). `from` / `to` (YYYY-MM-DD, UTC days,
     * included) on the starting time; soonest first; 25 per page.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'kind' => ['nullable', Rule::enum(SittingKind::class)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $sittings = InterviewSitting::query()
            ->with('members')
            ->withCount('interviews')
            ->where('kind', $filters['kind'] ?? SittingKind::Committee->value)
            ->when($filters['from'] ?? null, fn($query, $date) => $query->where('starts_at', '>=', $date))
            ->when($filters['to'] ?? null, fn($query, $date) => $query->where('starts_at', '<', Carbon::parse($date)->addDay()->toDateString()))
            ->orderBy('starts_at')
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString();

        return InterviewSittingResource::collection($sittings);
    }

    /** One sitting with its committee and every interview booked into it. */
    public function show(InterviewSitting $sitting): InterviewSittingResource
    {
        return new InterviewSittingResource($sitting->load(['members', ...array_map(fn($relation) => "interviews.{$relation}", InterviewResource::RELATIONS)]));
    }

    /**
     * Create a committee sitting
     *
     * `starts_at`, `location`, `committee_name`, `members` (`name`, `capacity` chair|member|secretary, optional
     * `staff_user_id`; at most one chair), `notes`.
     */
    public function store(SittingRequest $request, ManageSittings $sittings): JsonResponse
    {
        $sitting = $sittings->create($request->safe()->except('members'), $request->validated('members'), $request->user());

        return InterviewSittingResource::make($sitting->load('members'))->response()->setStatusCode(201);
    }

    /**
     * Change a committee sitting
     *
     * Send only what changes. `members` replaces the committee, refused once a result is recorded in the sitting.
     * Booked interviews keep their own times, so `starts_at` cannot move after any of them. A one-off appointment is
     * changed by rescheduling its interview (422 here).
     */
    public function update(SittingRequest $request, InterviewSitting $sitting, ManageSittings $sittings): InterviewSittingResource
    {
        $sitting = $sittings->update($sitting, $request->safe()->except('members'), $request->validated('members'), $request->user());

        return new InterviewSittingResource($sitting->load('members'));
    }

    /**
     * Book several applications into a sitting
     *
     * In the order given (take it from the interview queue), from `first_at` (default: the sitting's start) every
     * `minutes_between` minutes (default 30). Each application must be awaiting its interview with none booked. All
     * or nothing: one refused application (422 on `application_ids.N`) books none.
     */
    public function book(BookSittingRequest $request, InterviewSitting $sitting, ManageInterviews $interviews): JsonResponse
    {
        $booked = $interviews->bookMany(
            $sitting,
            $request->validated('application_ids'),
            $request->validated('first_at') === null ? null : Date::parse($request->validated('first_at')),
            (int) ($request->validated('minutes_between') ?? 30),
            $request->user(),
        );

        $ids = array_map(fn(Interview $interview) => $interview->id, $booked);

        return InterviewResource::collection(Interview::query()->with(InterviewResource::RELATIONS)->whereIn('id', $ids)->orderBy('scheduled_at')->get())
            ->response()->setStatusCode(201);
    }
}
