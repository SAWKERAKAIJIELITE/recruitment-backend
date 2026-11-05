<?php

namespace App\Modules\Exams\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Exams\Actions\ExamResults;
use App\Modules\Exams\Actions\ExtendAttempt;
use App\Modules\Exams\Actions\ManageExamBookings;
use App\Modules\Exams\Actions\SweepExamSessions;
use App\Modules\Exams\Http\Requests\ExamReasonRequest;
use App\Modules\Exams\Http\Requests\ExtendExamRequest;
use App\Modules\Exams\Http\Requests\ManualMarksRequest;
use App\Modules\Exams\Http\Resources\ExamBookingResource;
use App\Modules\Exams\Models\ExamAttempt;
use App\Modules\Exams\Models\ExamBooking;
use App\Modules\Exams\Queries\ExamQueue;
use App\Modules\Recruitment\Http\Resources\ApplicationSummaryResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * Bookings, attempts and hand marks (D-074, D-075). Reading: exams.manage, candidates.manage or reports.view.
 * Cancelling and entering marks: exams.manage. More time for one attempt: attempts.extend.
 */
class ExamBookingController extends Controller
{
    /**
     * The exam queue
     *
     * Applications awaiting their exam with no booking waiting or undecided, longest wait first. Takes the filters
     * of the applications list (`job_position_id` for a session's position); 25 per page.
     */
    public function queue(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate(ExamQueue::rules());

        return ApplicationSummaryResource::collection(ExamQueue::query($filters)->with(ApplicationSummaryResource::RELATIONS)->paginate(25)->withQueryString());
    }

    /** One booking, with its attempt, hand marks (every revision) and result. */
    public function show(ExamBooking $booking, SweepExamSessions $sweep): ExamBookingResource
    {
        $sweep->sweep($booking->session_id);

        return new ExamBookingResource($booking->fresh(ExamBookingResource::relations()));
    }

    /** Every exam booking of an application, by round. */
    public function forApplication(string $application): AnonymousResourceCollection
    {
        return ExamBookingResource::collection(ExamBooking::query()->with(ExamBookingResource::relations())->where('application_id', $application)->orderBy('round')->get());
    }

    /**
     * Cancel a booking
     *
     * Only while its candidate has not started (and before being found absent), with a reason.
     */
    public function cancel(ExamReasonRequest $request, ExamBooking $booking, ManageExamBookings $bookings): ExamBookingResource
    {
        $bookings->cancel($booking, $request->validated('reason'), $request->user());

        return new ExamBookingResource($booking->fresh(ExamBookingResource::relations()));
    }

    /**
     * Enter hand marks
     *
     * The marks of the hand-marked parts (التجويد) for a candidate who started the exam: `marks` [{part_id, mark}],
     * out of each part's maximum. When the online part is closed and every hand mark is in, the result is decided
     * and the application moves. A mark already entered changes only with `reason` (a correction, kept); once the
     * result is decided, that also needs the individual permission exam_results.correct, and the result is revised
     * (a correction event when passed / failed changes).
     */
    public function marks(ManualMarksRequest $request, ExamBooking $booking, ExamResults $results): ExamBookingResource
    {
        $attempt = ExamAttempt::query()->where('booking_id', $booking->id)->first()
            ?? throw ValidationException::withMessages(['booking' => [__('exams.marks.not_attended')]]);

        $results->enterMarks($attempt, $request->marks(), $request->validated('reason'), $request->user());

        return new ExamBookingResource($booking->fresh(ExamBookingResource::relations()));
    }

    /**
     * More time for one attempt
     *
     * Individual permission attempts.extend: a latecomer, a power cut at one desk. Only while it is running; the
     * previous and new deadline and the reason are kept.
     */
    public function extend(ExtendExamRequest $request, ExamAttempt $attempt, ExtendAttempt $extend): ExamBookingResource
    {
        $extend->extend($attempt, (int) $request->validated('minutes'), $request->validated('reason'), $request->user());

        return new ExamBookingResource(ExamBooking::query()->with(ExamBookingResource::relations())->findOrFail($attempt->booking_id));
    }
}
