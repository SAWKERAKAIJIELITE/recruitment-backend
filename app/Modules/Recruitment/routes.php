<?php

use App\Modules\Identity\Http\Middleware\EnsureStaffIsActive;
use App\Modules\Identity\Http\Middleware\RequireAnyPermission;
use App\Modules\Recruitment\Enums\ReferenceType;
use App\Modules\Recruitment\Http\Controllers\ApplicationController;
use App\Modules\Recruitment\Http\Controllers\ReferenceDataController;
use Illuminate\Support\Facades\Route;

Route::middleware('api')->prefix('api/v1/staff')->group(function () {
    $types = ReferenceType::values();

    Route::middleware(['auth:staff', EnsureStaffIsActive::class])->group(function () use ($types) {
        // Managed lists: any staff user may read, candidates.manage may change.
        Route::get('reference/{type}', [ReferenceDataController::class, 'index'])->whereIn('type', $types);

        Route::middleware('can:candidates.manage')->group(function () use ($types) {
            Route::post('reference/{type}', [ReferenceDataController::class, 'store'])->whereIn('type', $types);
            Route::put('reference/{type}/{id}', [ReferenceDataController::class, 'update'])->whereIn('type', $types)->whereNumber('id');
            Route::delete('reference/{type}/{id}', [ReferenceDataController::class, 'archive'])->whereIn('type', $types)->whereNumber('id');
            Route::post('reference/{type}/{id}/restore', [ReferenceDataController::class, 'restore'])->whereIn('type', $types)->whereNumber('id');

            Route::post('applications', [ApplicationController::class, 'store']);
        });

        // Reading files: the general manager (reports.view) and hiring staff (candidates.manage).
        Route::middleware(RequireAnyPermission::class . ':reports.view,candidates.manage')->group(function () {
            Route::get('applications', [ApplicationController::class, 'index']);
            Route::get('applications/{application}', [ApplicationController::class, 'show'])->whereUuid('application');
        });
    });
});
