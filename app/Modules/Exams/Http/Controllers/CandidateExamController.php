<?php

namespace App\Modules\Exams\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Exams\Actions\CandidateExamLogin;
use App\Modules\Exams\Actions\TakeExam;
use App\Modules\Exams\Http\Requests\CandidateLoginRequest;
use App\Modules\Exams\Http\Requests\SaveAnswerRequest;
use App\Modules\Exams\Http\Resources\CandidateExamResource;
use App\Modules\Exams\Http\Resources\CandidateQuestionResource;
use App\Modules\Exams\Models\ExamAttempt;
use App\Modules\Exams\Models\ExamBooking;
use App\Modules\Recruitment\Models\Candidate;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * The candidate's exam page (analysis doc §06; D-072, D-075). The candidate logs in with their national number
 * and their session's password; the token belongs to that booking. The server keeps the time and the answers;
 * the candidate never receives an answer key or a result.
 */
class CandidateExamController extends Controller
{
    /**
     * Candidate login
     *
     * The national number and the session's password, from an hour before the session (config
     * exams.login_early_minutes) until it ends. A new login ends the previous one. One message for every failure.
     *
     * @unauthenticated
     */
    public function login(CandidateLoginRequest $request, CandidateExamLogin $login): JsonResponse
    {
        $result = $login->login($request->validated('national_number'), $request->validated('password'), $request->ip());
        $booking = $result['booking']->load(['session', 'attempt', 'application.candidate', 'application.jobPosition']);

        return response()->json([
            'token' => $result['token'],
            'token_type' => 'Bearer',
            'exam' => new CandidateExamResource($booking),
        ]);
    }

    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    /**
     * My exam
     *
     * When and where, the late window, the server's time, and the attempt (deadline, seconds left, answered
     * questions; the receipt once submitted).
     */
    public function show(Request $request, TakeExam $exam): CandidateExamResource
    {
        $booking = $this->booking($request);

        if ($booking->attempt !== null) {
            $exam->closeIfExpired($booking->attempt);
        }

        return new CandidateExamResource($booking->fresh(['session', 'attempt', 'application.candidate', 'application.jobPosition']));
    }

    /**
     * Start the exam
     *
     * From the session's start until the late window closes. The attempt ends with the session (a latecomer has less
     * time). Starting again returns the same attempt.
     */
    public function start(Request $request, TakeExam $exam): CandidateExamResource
    {
        $booking = $this->booking($request);
        $exam->start($booking, $request->ip());

        return new CandidateExamResource($booking->fresh(['session', 'attempt', 'application.candidate', 'application.jobPosition']));
    }

    /** The questions in my order, with the answers saved (also after submitting). */
    public function questions(Request $request, TakeExam $exam): AnonymousResourceCollection
    {
        $attempt = $exam->closeIfExpired($this->attempt($request));

        return CandidateQuestionResource::collection($attempt->items()->with('question.version')->get());
    }

    /**
     * Save an answer
     *
     * `option`: the index of the chosen option (from 0), or null to clear. Saved on the server at once (the last
     * saved answer counts). After the deadline: 422 and the exam is closed with what was saved.
     */
    public function answer(SaveAnswerRequest $request, int $position, TakeExam $exam): JsonResponse
    {
        $option = $request->validated('option');
        $item = $exam->save($this->attempt($request), $position, $option === null ? null : (int) $option, $request->ip());
        $attempt = ExamAttempt::query()->findOrFail($item->attempt_id);

        return response()->json([
            'position' => $item->position,
            'selected_option' => $item->selected_option,
            'saved_at' => $item->revisions()->max('saved_at') === null ? null : Carbon::parse($item->revisions()->max('saved_at'))->toIso8601String(),
            'deadline_at' => $attempt->deadline_at->toIso8601String(),
        ]);
    }

    /**
     * Submit the exam
     *
     * Closes it; submitting again changes nothing and returns the same receipt.
     */
    public function submit(Request $request, TakeExam $exam): CandidateExamResource
    {
        $booking = $this->booking($request);
        $exam->submit($this->attempt($request));

        return new CandidateExamResource($booking->fresh(['session', 'attempt', 'application.candidate', 'application.jobPosition']));
    }

    /** The booking the candidate logged in for (their token's). */
    private function booking(Request $request): ExamBooking
    {
        $candidate = $request->user();
        $bookingId = $candidate instanceof Candidate ? $candidate->currentAccessToken()?->exam_booking_id : null;

        if ($bookingId === null || ! $candidate->is_active) {
            throw new AuthenticationException;
        }

        return ExamBooking::query()->with(['session', 'attempt', 'application.candidate', 'application.jobPosition'])->findOrFail($bookingId);
    }

    private function attempt(Request $request): ExamAttempt
    {
        return $this->booking($request)->attempt ?? throw ValidationException::withMessages(['attempt' => [__('exams.candidate.not_started')]]);
    }
}
