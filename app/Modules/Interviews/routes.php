<?php

use App\Modules\Identity\Http\Middleware\EnsureStaffIsActive;
use App\Modules\Identity\Http\Middleware\RequireAnyPermission;
use App\Modules\Interviews\Http\Controllers\InterviewController;
use App\Modules\Interviews\Http\Controllers\InterviewQueueController;
use App\Modules\Interviews\Http\Controllers\InterviewSittingController;
use Illuminate\Support\Facades\Route;

// Every route here needs a signed-in, active staff user (D-059).
Route::middleware(['api', 'auth:staff', EnsureStaffIsActive::class])->prefix('api/v1/staff')->group(function () {
    Route::middleware(RequireAnyPermission::class . ':interviews.manage,candidates.manage,reports.view')->group(function () {
        Route::get('interview-sittings', [InterviewSittingController::class, 'index']);
        Route::get('interview-sittings/{sitting}', [InterviewSittingController::class, 'show'])->whereUuid('sitting');
        Route::get('interviews', [InterviewController::class, 'index']);
        Route::get('interviews/queue', [InterviewQueueController::class, 'index']);
        Route::get('interviews/{interview}', [InterviewController::class, 'show'])->whereUuid('interview');
        Route::get('applications/{application}/interviews', [InterviewController::class, 'forApplication'])->whereUuid('application');
    });

    Route::middleware('can:interviews.manage')->group(function () {
        Route::post('interview-sittings', [InterviewSittingController::class, 'store']);
        Route::patch('interview-sittings/{sitting}', [InterviewSittingController::class, 'update'])->whereUuid('sitting');
        Route::post('interview-sittings/{sitting}/bookings', [InterviewSittingController::class, 'book'])->whereUuid('sitting');
        Route::post('applications/{application}/interviews', [InterviewController::class, 'store'])->whereUuid('application');
        Route::post('interviews/{interview}/reschedule', [InterviewController::class, 'reschedule'])->whereUuid('interview');
        Route::post('interviews/{interview}/cancel', [InterviewController::class, 'cancel'])->whereUuid('interview');
        Route::post('interviews/{interview}/result', [InterviewController::class, 'record'])->whereUuid('interview');
    });

    // Individual permissions (never given by a role).
    Route::post('interviews/{interview}/correct', [InterviewController::class, 'correct'])->whereUuid('interview')->middleware('can:interviews.correct');
    Route::post('applications/{application}/interview-excuse', [InterviewController::class, 'excuse'])->whereUuid('application')->middleware('can:exceptions.approve');
});
