<?php

use App\Modules\Exams\Http\Controllers\ExamPlanController;
use App\Modules\Exams\Http\Controllers\ExamQuestionController;
use App\Modules\Exams\Http\Controllers\ExamSubjectController;
use App\Modules\Identity\Http\Middleware\EnsureStaffIsActive;
use App\Modules\Identity\Http\Middleware\RequireAnyPermission;
use Illuminate\Support\Facades\Route;

Route::middleware('api')->prefix('api/v1/staff')->group(function () {
    Route::middleware(['auth:staff', EnsureStaffIsActive::class])->group(function () {
        // Reading subjects and plans: whoever prepares exams, hiring staff and the general manager.
        Route::middleware(RequireAnyPermission::class . ':exam_bank.manage,candidates.manage,reports.view')->group(function () {
            Route::get('exam-subjects', [ExamSubjectController::class, 'index']);
            Route::get('exam-plans', [ExamPlanController::class, 'index']);
            Route::get('exam-plans/{plan}', [ExamPlanController::class, 'show'])->whereUuid('plan');
        });

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
});
