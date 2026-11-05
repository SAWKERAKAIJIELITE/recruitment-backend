<?php

namespace App\Modules\Exams\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Exams\Actions\ManageExamBookings;
use App\Modules\Exams\Actions\ManageExamSessions;
use App\Modules\Exams\Actions\SweepExamSessions;
use App\Modules\Exams\Http\Requests\BookExamSessionRequest;
use App\Modules\Exams\Http\Requests\ExtendExamRequest;
use App\Modules\Exams\Http\Requests\StoreExamSessionRequest;
use App\Modules\Exams\Http\Requests\UpdateExamSessionRequest;
use App\Modules\Exams\Http\Resources\ExamBookingResource;
use App\Modules\Exams\Http\Resources\ExamSessionResource;
use App\Modules\Exams\Http\Resources\SessionQuestionResource;
use App\Modules\Exams\Models\ExamBooking;
use App\Modules\Exams\Models\ExamSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * Exam sessions (D-056, D-073). Reading: exams.manage, candidates.manage, reports.view or exam_bank.manage.
 * Creating, changing and booking: exams.manage. The drawn questions (with their answers): exam_bank.manage.
 * More time for everyone: the individual permission attempts.extend.
 */
class ExamSessionController extends Controller
{
    /**
     * List exam sessions
     *
     * `from` / `to` (YYYY-MM-DD, UTC days, included) on the start; `job_position_id`; soonest first; 25 per page.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'job_position_id' => ['nullable', 'integer'],
        ]);

        $sessions = ExamSession::query()
            ->with(ExamSessionResource::RELATIONS)
            ->withCount(['questions', 'bookings'])
            ->when($filters['from'] ?? null, fn($query, $date) => $query->where('starts_at', '>=', $date))
            ->when($filters['to'] ?? null, fn($query, $date) => $query->where('starts_at', '<', Carbon::parse($date)->addDay()->toDateString()))
            ->when($filters['job_position_id'] ?? null, fn($query, $id) => $query->whereHas('plan', fn($plan) => $plan->where('job_position_id', $id)))
            ->orderBy('starts_at')
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString();

        return ExamSessionResource::collection($sessions);
    }

    /** One exam session, with its extensions. */
    public function show(ExamSession $session, SweepExamSessions $sweep): ExamSessionResource
    {
        $sweep->sweep($session->id);

        return new ExamSessionResource($session->load([...ExamSessionResource::RELATIONS, 'extensions.author'])->loadCount(['questions', 'bookings']));
    }

    /**
     * Create an exam session
     *
     * On a published plan version (its job position and duration). Its questions are drawn at once, the same for
     * every candidate (each sees them in their own order): 422 on `plan_version_id` when the bank is short. The
     * password is what its candidates log in with, with their national number.
     */
    public function store(StoreExamSessionRequest $request, ManageExamSessions $sessions): JsonResponse
    {
        $session = $sessions->create($request->validated(), $request->user());

        return ExamSessionResource::make($session->load(ExamSessionResource::RELATIONS)->loadCount(['questions', 'bookings']))->response()->setStatusCode(201);
    }

    /**
     * Change an exam session
     *
     * Send only what changes. The start, place and late window only before it starts; the password and notes at any
     * time. The plan never changes.
     */
    public function update(UpdateExamSessionRequest $request, ExamSession $session, ManageExamSessions $sessions): ExamSessionResource
    {
        $session = $sessions->update($session, $request->validated(), $request->user());

        return new ExamSessionResource($session->load(ExamSessionResource::RELATIONS)->loadCount(['questions', 'bookings']));
    }

    /**
     * Delete an exam session
     *
     * Only one nobody was ever booked into (422 on `session` otherwise).
     */
    public function destroy(Request $request, ExamSession $session, ManageExamSessions $sessions): Response
    {
        $sessions->delete($session, $request->user());

        return response()->noContent();
    }

    /**
     * Draw the questions again
     *
     * Before the session starts (after a question was corrected or added in the bank).
     */
    public function redraw(Request $request, ExamSession $session, ManageExamSessions $sessions): ExamSessionResource
    {
        $session = $sessions->redraw($session, $request->user());

        return new ExamSessionResource($session->load(ExamSessionResource::RELATIONS)->loadCount(['questions', 'bookings']));
    }

    /** The drawn questions, in the session's order, with their answers (exam_bank.manage). */
    public function questions(ExamSession $session): AnonymousResourceCollection
    {
        return SessionQuestionResource::collection($session->questions()->with(SessionQuestionResource::RELATIONS)->get());
    }

    /**
     * More time for everyone
     *
     * Individual permission attempts.extend. The session ends `minutes` later and every attempt still running gets
     * them on its deadline (each recorded on the attempt, with the reason). Before the session ends.
     */
    public function extend(ExtendExamRequest $request, ExamSession $session, ManageExamSessions $sessions): ExamSessionResource
    {
        $session = $sessions->extend($session, (int) $request->validated('minutes'), $request->validated('reason'), $request->user());

        return new ExamSessionResource($session->load([...ExamSessionResource::RELATIONS, 'extensions.author'])->loadCount(['questions', 'bookings']));
    }

    /**
     * The session's candidates
     *
     * Every booking with its attempt (start, deadline, answered questions, the last save, right answers once closed,
     * extensions), hand marks and result. Follow the session live from here. `status` narrows it.
     */
    public function bookings(Request $request, ExamSession $session, SweepExamSessions $sweep): AnonymousResourceCollection
    {
        $filters = $request->validate(['status' => ['nullable', 'in:booked,cancelled,absent,attended']]);
        $sweep->sweep($session->id);

        $bookings = ExamBooking::query()
            ->with(ExamBookingResource::relations())
            ->where('session_id', $session->id)
            ->when($filters['status'] ?? null, fn($query, $status) => $query->where('status', $status))
            ->join('applications', 'applications.id', '=', 'exam_bookings.application_id')
            ->orderBy('applications.nomination_number')
            ->select('exam_bookings.*')
            ->get();

        return ExamBookingResource::collection($bookings);
    }

    /**
     * Book applications into a session
     *
     * Applications awaiting their exam, of the session's job position, with no booking waiting or undecided (take
     * them from the exam queue). Until the late window closes. All or nothing: one refused application (422 on
     * `application_ids.N`) books none.
     */
    public function book(BookExamSessionRequest $request, ExamSession $session, ManageExamBookings $bookings): JsonResponse
    {
        $booked = $bookings->bookMany($session, $request->validated('application_ids'), $request->user());
        $ids = array_map(fn(ExamBooking $booking) => $booking->id, $booked);

        return ExamBookingResource::collection(ExamBooking::query()->with(ExamBookingResource::relations())->whereIn('id', $ids)->get())
            ->response()->setStatusCode(201);
    }
}
