<?php

namespace App\Modules\Exams\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Exams\Actions\ExamDecisions;
use App\Modules\Exams\Http\Requests\ExamExceptionRequest;
use App\Modules\Exams\Http\Requests\ExamExcuseRequest;
use App\Modules\Exams\Http\Resources\ExamBookingResource;
use App\Modules\Recruitment\Models\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Date;

/** Decisions after an exam (analysis doc §07): the individual permission exceptions.approve. */
class ExamDecisionController extends Controller
{
    /**
     * Accept an exam absence excuse
     *
     * The application goes back to awaiting its exam (the absence stays). `session_id` books it at once into that
     * session (a new round). `occurred_at`: when it was decided (default now).
     */
    public function excuse(ExamExcuseRequest $request, Application $application, ExamDecisions $decisions): JsonResponse
    {
        $occurredAt = $request->validated('occurred_at') === null ? null : Date::parse($request->validated('occurred_at'));
        $booking = $decisions->acceptExcuse($application, $request->validated('reason'), $occurredAt, $request->validated('session_id'), $request->user());

        return response()->json([
            'application_status' => $application->fresh()->current_status->value,
            'booking' => $booking === null ? null : new ExamBookingResource($booking->fresh(ExamBookingResource::relations())),
        ]);
    }

    /**
     * Admit a failed exam to the interview by exception
     *
     * The application moves to awaiting its interview, marked as an exception; the exam's computed result stays
     * failed. `occurred_at`: when it was decided (default now).
     */
    public function exception(ExamExceptionRequest $request, Application $application, ExamDecisions $decisions): JsonResponse
    {
        $occurredAt = $request->validated('occurred_at') === null ? null : Date::parse($request->validated('occurred_at'));
        $decisions->approveException($application, $request->validated('reason'), $occurredAt, $request->user());

        return response()->json(['application_status' => $application->fresh()->current_status->value]);
    }
}
