<?php

use App\Modules\Identity\Http\Middleware\EnsureStaffIsActive;
use App\Modules\Identity\Http\Middleware\RequireAnyPermission;
use App\Modules\Recruitment\Http\Controllers\ApplicationController;
use App\Modules\Recruitment\Http\Controllers\CandidateController;
use App\Modules\Recruitment\Http\Controllers\ReferenceDataController;
use Illuminate\Support\Facades\Route;

// Every route here needs a signed-in, active staff user.
Route::middleware(['api', 'auth:staff', EnsureStaffIsActive::class])->prefix('api/v1/staff')->group(function () {
    // Managed lists: any staff user may read; candidates.manage changes them, contracts.manage the contract types
    // (the controller checks which). {type} binds to the ReferenceType enum (an unknown list is 404); {id} is an integer.
    Route::get('reference/{type}', [ReferenceDataController::class, 'index']);

    Route::middleware(RequireAnyPermission::class . ':candidates.manage,contracts.manage')->group(function () {
        Route::post('reference/{type}', [ReferenceDataController::class, 'store']);
        Route::put('reference/{type}/{id}', [ReferenceDataController::class, 'update'])->whereNumber('id');
        Route::delete('reference/{type}/{id}', [ReferenceDataController::class, 'archive'])->whereNumber('id');
        Route::post('reference/{type}/{id}/restore', [ReferenceDataController::class, 'restore'])->whereNumber('id');
    });

    Route::middleware('can:candidates.manage')->group(function () {
        Route::post('applications', [ApplicationController::class, 'store']);
        Route::patch('applications/{application}', [ApplicationController::class, 'update'])->whereUuid('application');

        Route::patch('candidates/{candidate}', [CandidateController::class, 'update'])->whereUuid('candidate');
        Route::post('candidates/{candidate}/deactivate', [CandidateController::class, 'deactivate'])->whereUuid('candidate');
        Route::post('candidates/{candidate}/activate', [CandidateController::class, 'activate'])->whereUuid('candidate');
    });

    // The full files: Recruitment and the general manager (applications.view).
    Route::middleware('can:applications.view')->group(function () {
        Route::get('candidates', [CandidateController::class, 'index']);
        Route::get('candidates/{candidate}', [CandidateController::class, 'show'])->whereUuid('candidate');
    });

    // The applications list and one application: the full file with applications.view; the other offices see who
    // the candidate is and where the application stands, nothing more (D-086).
    Route::middleware(RequireAnyPermission::class . ':' . ApplicationController::READERS)->group(function () {
        Route::get('applications', [ApplicationController::class, 'index']);
        Route::get('applications/{application}', [ApplicationController::class, 'show'])->whereUuid('application');
    });

    // Received applications enter the lifecycle when Recruitment starts them (D-087).
    Route::middleware('can:applications.start')->group(function () {
        Route::post('applications/start', [ApplicationController::class, 'startMany']);
        Route::post('applications/{application}/start', [ApplicationController::class, 'start'])->whereUuid('application');
    });

    // Ending an application frees the person for a new nomination (D-037).
    Route::post('applications/{application}/close', [ApplicationController::class, 'close'])
        ->whereUuid('application')
        ->middleware('can:applications.close');
});
