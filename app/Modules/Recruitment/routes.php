<?php

use App\Modules\Identity\Http\Middleware\EnsureStaffIsActive;
use App\Modules\Identity\Http\Middleware\RequireAnyPermission;
use App\Modules\Recruitment\Http\Controllers\ApplicationController;
use App\Modules\Recruitment\Http\Controllers\CandidateController;
use App\Modules\Recruitment\Http\Controllers\ReferenceDataController;
use Illuminate\Support\Facades\Route;

// Every route here needs a signed-in, active staff user.
Route::middleware(['api', 'auth:staff', EnsureStaffIsActive::class])->prefix('api/v1/staff')->group(function () {
    // Managed lists: any staff user may read, candidates.manage may change. {type} binds to the
    // ReferenceType enum (an unknown list is 404); {id} is an integer.
    Route::get('reference/{type}', [ReferenceDataController::class, 'index']);

    Route::middleware('can:candidates.manage')->group(function () {
        Route::post('reference/{type}', [ReferenceDataController::class, 'store']);
        Route::put('reference/{type}/{id}', [ReferenceDataController::class, 'update'])->whereNumber('id');
        Route::delete('reference/{type}/{id}', [ReferenceDataController::class, 'archive'])->whereNumber('id');
        Route::post('reference/{type}/{id}/restore', [ReferenceDataController::class, 'restore'])->whereNumber('id');

        Route::post('applications', [ApplicationController::class, 'store']);
        Route::patch('applications/{application}', [ApplicationController::class, 'update'])->whereUuid('application');

        Route::patch('candidates/{candidate}', [CandidateController::class, 'update'])->whereUuid('candidate');
        Route::post('candidates/{candidate}/deactivate', [CandidateController::class, 'deactivate'])->whereUuid('candidate');
        Route::post('candidates/{candidate}/activate', [CandidateController::class, 'activate'])->whereUuid('candidate');
    });

    // Reading files: the general manager (reports.view) and hiring staff (candidates.manage).
    Route::middleware(RequireAnyPermission::class . ':reports.view,candidates.manage')->group(function () {
        Route::get('applications', [ApplicationController::class, 'index']);
        Route::get('applications/{application}', [ApplicationController::class, 'show'])->whereUuid('application');
        Route::get('candidates', [CandidateController::class, 'index']);
        Route::get('candidates/{candidate}', [CandidateController::class, 'show'])->whereUuid('candidate');
    });

    // Ending an application frees the person for a new nomination (D-037): an individual permission.
    Route::post('applications/{application}/close', [ApplicationController::class, 'close'])
        ->whereUuid('application')
        ->middleware('can:applications.close');
});
