<?php

use App\Modules\Exams\Http\Controllers\CandidateExamController;
use App\Modules\Exams\Http\Controllers\ExamBookingController;
use App\Modules\Exams\Http\Controllers\ExamDecisionController;
use App\Modules\Exams\Http\Controllers\ExamPlanController;
use App\Modules\Exams\Http\Controllers\ExamQuestionController;
use App\Modules\Exams\Http\Controllers\ExamSessionController;
use App\Modules\Exams\Http\Controllers\ExamSubjectController;
use App\Modules\Exams\Http\Controllers\PassPolicyController;
use App\Modules\Identity\Http\Middleware\EnsureStaffIsActive;
use App\Modules\Identity\Http\Middleware\RequireAnyPermission;
use Illuminate\Support\Facades\Route;

// Every route here needs a signed-in, active staff user.
Route::middleware(['api', 'auth:staff', EnsureStaffIsActive::class])->prefix('api/v1/staff')->group(function () {
    // Reading subjects and plans: whoever prepares exams, hiring staff and the general manager.
    Route::middleware(RequireAnyPermission::class . ':exam_bank.manage,candidates.manage,reports.view')->group(function () {
        Route::get('exam-subjects', [ExamSubjectController::class, 'index']);
        Route::get('exam-plans', [ExamPlanController::class, 'index']);
        Route::get('exam-plans/{plan}', [ExamPlanController::class, 'show'])->whereUuid('plan');
        Route::get('exam-pass-policies', [PassPolicyController::class, 'index']);
    });

    // Individual permission (never given by a role).
    Route::post('exam-pass-policies', [PassPolicyController::class, 'store'])->middleware('can:pass_policies.manage');

    Route::middleware('can:exam_bank.manage')->group(function () {
        Route::post('exam-subjects', [ExamSubjectController::class, 'store']);
        Route::put('exam-subjects/{id}', [ExamSubjectController::class, 'update'])->whereNumber('id');
        Route::delete('exam-subjects/{id}', [ExamSubjectController::class, 'archive'])->whereNumber('id');
        Route::post('exam-subjects/{id}/restore', [ExamSubjectController::class, 'restore'])->whereNumber('id');
        Route::post('exam-subjects/{id}/parts', [ExamSubjectController::class, 'storePart'])->whereNumber('id');
        Route::patch('exam-subject-parts/{id}', [ExamSubjectController::class, 'updatePart'])->whereNumber('id');
        Route::delete('exam-subject-parts/{id}', [ExamSubjectController::class, 'archivePart'])->whereNumber('id');
        Route::post('exam-subject-parts/{id}/restore', [ExamSubjectController::class, 'restorePart'])->whereNumber('id');

        // The question bank holds the answers: only exam_bank.manage reads it.
        Route::get('exam-questions', [ExamQuestionController::class, 'index']);
        Route::get('exam-questions/stats', [ExamQuestionController::class, 'stats']);
        Route::get('exam-questions/{id}', [ExamQuestionController::class, 'show'])->whereNumber('id');
        Route::post('exam-questions', [ExamQuestionController::class, 'store']);
        Route::patch('exam-questions/{id}', [ExamQuestionController::class, 'update'])->whereNumber('id');
        Route::delete('exam-questions/{id}', [ExamQuestionController::class, 'archive'])->whereNumber('id');
        Route::post('exam-questions/{id}/restore', [ExamQuestionController::class, 'restore'])->whereNumber('id');

        Route::post('exam-plans', [ExamPlanController::class, 'store']);
        Route::put('exam-plans/{plan}', [ExamPlanController::class, 'update'])->whereUuid('plan');
        Route::delete('exam-plans/{plan}', [ExamPlanController::class, 'destroy'])->whereUuid('plan');
        Route::post('exam-plans/{plan}/publish', [ExamPlanController::class, 'publish'])->whereUuid('plan');
        Route::get('exam-plans/{plan}/readiness', [ExamPlanController::class, 'readiness'])->whereUuid('plan');
    });
});

// Exam sessions, bookings and results (D-073 to D-075).
Route::middleware(['api', 'auth:staff', EnsureStaffIsActive::class])->prefix('api/v1/staff')->group(function () {
    Route::middleware(RequireAnyPermission::class . ':exams.manage,candidates.manage,reports.view,exam_bank.manage')->group(function () {
        Route::get('exam-sessions', [ExamSessionController::class, 'index']);
        Route::get('exam-sessions/{session}', [ExamSessionController::class, 'show'])->whereUuid('session');
        Route::get('exam-sessions/{session}/bookings', [ExamSessionController::class, 'bookings'])->whereUuid('session');
        Route::get('exam-bookings/{booking}', [ExamBookingController::class, 'show'])->whereUuid('booking');
        Route::get('applications/{application}/exam-bookings', [ExamBookingController::class, 'forApplication'])->whereUuid('application');
        Route::get('exams/queue', [ExamBookingController::class, 'queue']);
    });

    Route::middleware('can:exams.manage')->group(function () {
        Route::post('exam-sessions', [ExamSessionController::class, 'store']);
        Route::patch('exam-sessions/{session}', [ExamSessionController::class, 'update'])->whereUuid('session');
        Route::delete('exam-sessions/{session}', [ExamSessionController::class, 'destroy'])->whereUuid('session');
        Route::post('exam-sessions/{session}/draw', [ExamSessionController::class, 'redraw'])->whereUuid('session');
        Route::post('exam-sessions/{session}/bookings', [ExamSessionController::class, 'book'])->whereUuid('session');
        Route::post('exam-bookings/{booking}/cancel', [ExamBookingController::class, 'cancel'])->whereUuid('booking');
        Route::post('exam-bookings/{booking}/marks', [ExamBookingController::class, 'marks'])->whereUuid('booking');
    });

    // The questions with their answers: only who manages the bank.
    Route::get('exam-sessions/{session}/questions', [ExamSessionController::class, 'questions'])->whereUuid('session')->middleware('can:exam_bank.manage');

    // Individual permissions (never given by a role).
    Route::middleware('can:attempts.extend')->group(function () {
        Route::post('exam-sessions/{session}/extend', [ExamSessionController::class, 'extend'])->whereUuid('session');
        Route::post('exam-attempts/{attempt}/extend', [ExamBookingController::class, 'extend'])->whereUuid('attempt');
    });

    Route::middleware('can:exceptions.approve')->group(function () {
        Route::post('applications/{application}/exam-excuse', [ExamDecisionController::class, 'excuse'])->whereUuid('application');
        Route::post('applications/{application}/exam-exception', [ExamDecisionController::class, 'exception'])->whereUuid('application');
    });
});

// The candidate's exam page (D-072): a token for one booking, from the national number and the session's password.
Route::middleware('api')->prefix('api/v1/candidate')->group(function () {
    Route::post('auth/login', [CandidateExamController::class, 'login'])->middleware('throttle:candidate-login');

    Route::middleware('auth:candidate')->group(function () {
        Route::post('auth/logout', [CandidateExamController::class, 'logout']);
        Route::get('exam', [CandidateExamController::class, 'show']);
        Route::post('exam/start', [CandidateExamController::class, 'start']);
        Route::get('exam/questions', [CandidateExamController::class, 'questions']);
        Route::put('exam/answers/{position}', [CandidateExamController::class, 'answer'])->whereNumber('position');
        Route::post('exam/submit', [CandidateExamController::class, 'submit']);
    });
});
