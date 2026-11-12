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
use App\Support\BusinessDay;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\Rule;

/**
 * Sittings (D-059, D-061). Reading: interviews.view. Changing and booking:
 * interviews.manage.
 */
class InterviewSittingController extends Controller
{
    /**
     * List sittings
     *
     * Every sitting; `kind` committee or one_off narrows it, `member` (a staff user's id) keeps the sittings that
     * staff user sits on, `open` 1 keeps those not ended yet (to book into; 0: the ended ones). `from` / `to`
     * (YYYY-MM-DD, Damascus days, included) on the starting time. By the starting time, soonest first, or latest
     * first with `direction` desc; 25 per page.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'kind' => ['nullable', Rule::enum(SittingKind::class)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'member' => ['nullable', 'uuid'],
            'open' => ['nullable', 'boolean'],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);
        $direction = $filters['direction'] ?? 'asc';

        $sittings = InterviewSitting::query()
            ->with('members')
            ->withCount('interviews')
            ->whenGiven($filters['kind'] ?? null, fn($query, $kind) => $query->where('kind', $kind))
            ->whenGiven($filters['member'] ?? null, fn($query, $id) => $query->whereHas('members', fn($members) => $members->where('staff_user_id', $id)))
            ->whenGiven($filters['from'] ?? null, fn($query, $date) => $query->where('starts_at', '>=', BusinessDay::startOf($date)))
            ->whenGiven($filters['to'] ?? null, fn($query, $date) => $query->where('starts_at', '<', BusinessDay::startOfNext($date)))
            ->when(isset($filters['open']), fn($query) => $query->where('ends_at', $request->boolean('open') ? '>' : '<=', Date::now()))
            ->orderBy('starts_at', $direction)
            ->orderBy('id', $direction)
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
     * member; `committee_name` optional). `starts_at`, `ends_at` (after the start, within 24 hours), `location`,
     * `committee_name`, `members` (`name`, `capacity` chair|member|secretary; at most one chair; the signed-in user
     * by `staff_user_id` (their own id, from /auth/me; another account is 422 on `members.N.staff_user_id`), whose
     * name is then the account's, and the others by `name`), `notes`. 422 on `starts_at` when a sitting with the same
     * members overlaps it (wherever it is; the same sitting entered twice, even seconds apart, is one; spaces and the
     * letter forms ا/ي/ه are ignored), on `members` when the staff account sits in an overlapping sitting; both carry
     * `conflict` {reason: duplicate_sitting or staff_overlap, sitting_id, member} next to `errors`.
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
     * after any of them, nor `ends_at` to or before one. The kind never changes. 422 on overlaps as when creating.
     * New `members` may keep the staff accounts the sitting has, and add the signed-in user's.
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
     * In the order given (take it from the interview queue), from `first_at` (default: the sitting's start, or 30
     * minutes after its latest interview) every `minutes_between` minutes (10 to 480, default 30), each before the
     * sitting's end and 10 minutes at least from the sitting's other interviews (D-096). Each application must be
     * awaiting its interview with none booked. All or nothing: one refused application (422 on `application_ids.N`,
     * also when its time would reach the end or come too close to another interview) books none. 422 on `sitting`
     * once the sitting has ended.
     */
    public function book(BookSittingRequest $request, InterviewSitting $sitting, ManageInterviews $interviews): JsonResponse
    {
        $booked = $interviews->bookMany(
            $sitting,
            $request->validated('application_ids'),
            $request->validated('first_at') === null ? null : Date::parse($request->validated('first_at')),
            (int) ($request->validated('minutes_between') ?? Interview::DEFAULT_MINUTES_BETWEEN),
            $request->user(),
        );

        // Booked in order, every minutes_between: already by time. The relations load in one query each.
        return InterviewResource::collection((new EloquentCollection($booked))->loadMissing(InterviewResource::RELATIONS))
            ->response()->setStatusCode(201);
    }
}
