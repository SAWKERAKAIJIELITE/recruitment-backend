<?php

namespace App\Modules\Interviews\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Interviews\Actions\ManageInterviews;
use App\Modules\Interviews\Actions\ManageSittings;
use App\Modules\Interviews\Enums\SittingKind;
use App\Modules\Interviews\Http\Requests\BookSittingRequest;
use App\Modules\Interviews\Http\Requests\StoreSittingRequest;
use App\Modules\Interviews\Http\Requests\UpdateSittingRequest;
use App\Modules\Interviews\Http\Resources\InterviewResource;
use App\Modules\Interviews\Http\Resources\InterviewSittingResource;
use App\Modules\Interviews\Models\Interview;
use App\Modules\Interviews\Models\InterviewSitting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
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
     * Every sitting; `kind` committee or one_off narrows it, `member` (a staff user's id) keeps the sittings that
     * staff user sits on. `from` / `to` (YYYY-MM-DD, UTC days, included) on the starting time; soonest first; 25
     * per page.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'kind' => ['nullable', Rule::enum(SittingKind::class)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'member' => ['nullable', 'uuid'],
        ]);

        $sittings = InterviewSitting::query()
            ->with('members')
            ->withCount('interviews')
            ->when($filters['kind'] ?? null, fn($query, $kind) => $query->where('kind', $kind))
            ->when($filters['member'] ?? null, fn($query, $id) => $query->whereHas('members', fn($members) => $members->where('staff_user_id', $id)))
            ->when($filters['from'] ?? null, fn($query, $date) => $query->where('starts_at', '>=', $date))
            ->when($filters['to'] ?? null, fn($query, $date) => $query->where('starts_at', '<', Carbon::parse($date)->addDay()->toDateString()))
            ->orderBy('starts_at')
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString();

        return InterviewSittingResource::collection($sittings);
    }

    /**
     * One sitting with its committee and every interview booked into it. The interviews do not repeat the
     * sitting's place, committee and members: they are the sitting's.
     */
    public function show(InterviewSitting $sitting): InterviewSittingResource
    {
        return new InterviewSittingResource($sitting->load(['members', ...array_map(fn($relation) => "interviews.{$relation}", InterviewResource::IN_SITTING)]));
    }

    /**
     * Create a sitting
     *
     * `kind`: committee (default) or one_off, a single interviewer who sees the candidates one by one (exactly one
     * member; `committee_name` optional). `starts_at`, `location`, `committee_name`, `members` (`name`, `capacity`
     * chair|member|secretary; at most one chair; a staff account by `staff_user_id`, whose name is then the
     * account's, or someone else by `name`), `notes`. 422 `duplicate_sitting` when a
     * sitting with the same start, place and members exists (the same sitting entered twice; spaces and the letter
     * forms ا/ي/ه are ignored).
     */
    public function store(StoreSittingRequest $request, ManageSittings $sittings): JsonResponse
    {
        $kind = SittingKind::from($request->validated('kind') ?? SittingKind::Committee->value);
        $sitting = $sittings->create($request->safe()->except(['members', 'kind']), $request->validated('members'), $request->user(), $kind);

        return InterviewSittingResource::make($sitting->load('members'))->response()->setStatusCode(201);
    }

    /**
     * Change a sitting
     *
     * Send only what changes. `members` replaces the committee, refused once a result is recorded in the sitting.
     * A one-off sitting keeps exactly one member. Booked interviews keep their own times, so `starts_at` cannot move
     * after any of them. The kind never changes. 422 `duplicate_sitting` as when creating.
     */
    public function update(UpdateSittingRequest $request, InterviewSitting $sitting, ManageSittings $sittings): InterviewSittingResource
    {
        $sitting = $sittings->update($sitting, $request->safe()->except('members'), $request->validated('members'), $request->user());

        return new InterviewSittingResource($sitting->load('members'));
    }

    /**
     * Delete a sitting
     *
     * Only a sitting nothing was ever booked into: no interview in it, and none moved out of it (that history
     * points to it). 422 on `sitting` otherwise: cancel or move its interviews instead, the sitting then stays as
     * their record.
     */
    public function destroy(Request $request, InterviewSitting $sitting, ManageSittings $sittings): Response
    {
        $sittings->delete($sitting, $request->user());

        return response()->noContent();
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
