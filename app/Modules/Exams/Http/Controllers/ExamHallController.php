<?php

namespace App\Modules\Exams\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Exams\Actions\HallExam;
use App\Modules\Exams\Http\Requests\HallCancelRequest;
use App\Modules\Exams\Http\Requests\HallUploadRequest;
use App\Modules\Exams\Http\Resources\ExamSessionResource;
use App\Modules\Exams\Models\ExamSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Exams in a hall with no internet (D-102): a session taken out to the moderator's approved laptop, sat there, brought
 * back. Permission exams.offline; an upload with extensions also needs attempts.extend. The full contract, with the
 * bundle's shape and the receipt: docs/hall-exam-reply.md.
 */
class ExamHallController extends Controller
{
    /**
     * Take a session out to a hall
     *
     * From the moderator's approved laptop (the token's device), before the session starts and at most
     * `exams.hall_download_hours` (48) before it. Gives the bookings that can sit (booked, active candidate, application
     * awaiting the exam) and the drawn questions in the session's order, **never the correct answers**. The session is
     * then frozen until brought back or cancelled; its candidates cannot log in online, and tokens they already held are
     * revoked. Taking it out again before the start replaces the earlier download (its id stops working).
     * 422 when it started, is too far ahead, has no drawn questions or no candidate who can sit, or the token has no
     * approved device of this user.
     *
     * @response 201 array{download_id: string, server_time: string, session: array{id: string, starts_at: string, late_minutes: int, duration_minutes: int, extra_minutes: int, location: string, job_position: string, plan: array{id: string, version: int}}, bookings: list<array{booking_id: string, national_number: string, full_name: string, nomination_number: string}>, questions: list<array{session_question_id: int, prompt: string, options: list<string>}>}
     */
    public function download(Request $request, ExamSession $session, HallExam $hall): JsonResponse
    {
        return response()->json($hall->download($session, $request->user(), $this->deviceId($request)), 201);
    }

    /**
     * Bring a hall's results back
     *
     * `bundle`: the JSON text the laptop sealed at the exam's end, byte for byte; `signature`: its seal (see the request).
     * Checked whole against the download (the same bookings, each attempt with every question once, times inside the
     * session's rules, the receipts, the attendance count), then recorded as if online: the server marks and decides each
     * attempt, dated with the hall's times; who did not come is absent. All or nothing. Sending the same upload again
     * changes nothing and returns the same answer.
     * 422 with every problem found, keyed by its place in the bundle (`bundle.bookings.3.attempt.items.7.position`).
     *
     * @response array{download_id: string, session_id: string, received_at: string, sat: int, absent: int, cancelled: int, bookings: list<array{booking_id: string, outcome: string, receipt: ?string}>}
     */
    public function upload(HallUploadRequest $request, ExamSession $session, HallExam $hall): JsonResponse
    {
        return response()->json($hall->upload($session, $request->validated('bundle'), $request->validated('signature'), $request->user(), $this->deviceId($request)));
    }

    /**
     * Cancel a session's taking-out
     *
     * With a reason. Before the start the session is back to normal (it can change, be taken out again, or be sat
     * online). After it, the downloaded bookings are cancelled: those candidates return to the queue. With
     * `questions_exposed`, the session's questions leave the bank, and before the start new ones are drawn when the
     * bank allows (`meta.redrawn`; false: draw before the session). An upload of a cancelled download is refused.
     */
    public function cancel(HallCancelRequest $request, ExamSession $session, HallExam $hall): JsonResponse
    {
        $result = $hall->cancel($session, $request->validated('download_id'), $request->validated('reason'), $request->boolean('questions_exposed'), $request->user());
        $fresh = ExamSession::query()->whereKey($session->id)->with(ExamSessionResource::RELATIONS)->withCount(['questions', 'bookings'])->firstOrFail();

        return (new ExamSessionResource($fresh))->additional(['meta' => ['redrawn' => $result['redrawn']]])->response();
    }

    /** The approved device the staff token was issued to (device login); null for a token without one. */
    private function deviceId(Request $request): ?string
    {
        $token = $request->user()->currentAccessToken();

        return $token === null ? null : ($token->getAttribute('staff_device_id') ?? null);
    }
}
