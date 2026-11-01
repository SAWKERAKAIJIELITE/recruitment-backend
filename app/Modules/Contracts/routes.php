<?php

use App\Modules\Contracts\Http\Controllers\ContractController;
use App\Modules\Identity\Http\Middleware\EnsureStaffIsActive;
use App\Modules\Identity\Http\Middleware\RequireAnyPermission;
use Illuminate\Support\Facades\Route;

// Every route here needs a signed-in, active staff user (D-064).
Route::middleware(['api', 'auth:staff', EnsureStaffIsActive::class])->prefix('api/v1/staff')->group(function () {
    Route::middleware(RequireAnyPermission::class . ':contracts.manage,candidates.manage,reports.view')->group(function () {
        Route::get('contracts', [ContractController::class, 'index']);
        Route::get('contracts/{contract}', [ContractController::class, 'show'])->whereUuid('contract');
        Route::get('applications/{application}/contracts', [ContractController::class, 'forApplication'])->whereUuid('application');
    });

    Route::middleware('can:contracts.manage')->group(function () {
        Route::post('applications/{application}/contracts', [ContractController::class, 'store'])->whereUuid('application');
        Route::post('contracts/{contract}/renew', [ContractController::class, 'renew'])->whereUuid('contract');
        Route::patch('contracts/{contract}', [ContractController::class, 'correct'])->whereUuid('contract');
        Route::post('contracts/{contract}/end', [ContractController::class, 'end'])->whereUuid('contract');
    });
});
