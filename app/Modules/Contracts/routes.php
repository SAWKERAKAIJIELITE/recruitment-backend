<?php

use App\Modules\Contracts\Http\Controllers\ContractController;
use App\Modules\Contracts\Http\Controllers\VacancyPlanController;
use App\Modules\Identity\Http\Middleware\EnsureStaffIsActive;
use Illuminate\Support\Facades\Route;

// Every route here needs a signed-in, active staff user (D-064).
Route::middleware(['api', 'auth:staff', EnsureStaffIsActive::class])->prefix('api/v1/staff')->group(function () {
    // The Contracts office and the general manager (D-085).
    Route::middleware('can:contracts.view')->group(function () {
        Route::get('contracts', [ContractController::class, 'index']);
        Route::get('contracts/{contract}', [ContractController::class, 'show'])->whereUuid('contract');
        Route::get('applications/{application}/contracts', [ContractController::class, 'forApplication'])->whereUuid('application');

        Route::get('vacancy-plans', [VacancyPlanController::class, 'index']);
        Route::get('vacancy-plans/current', [VacancyPlanController::class, 'current']);
        Route::get('vacancy-plans/{plan}', [VacancyPlanController::class, 'show'])->whereUuid('plan');
        Route::get('vacancy-plans/{plan}/original', [VacancyPlanController::class, 'original'])->whereUuid('plan');
    });

    Route::middleware('can:contracts.manage')->group(function () {
        Route::post('applications/{application}/contracts', [ContractController::class, 'store'])->whereUuid('application');
        Route::post('contracts/{contract}/renew', [ContractController::class, 'renew'])->whereUuid('contract');
        Route::patch('contracts/{contract}', [ContractController::class, 'correct'])->whereUuid('contract');
        Route::post('contracts/{contract}/end', [ContractController::class, 'end'])->whereUuid('contract');
    });

    // The vacancies file (D-088): the system administrator.
    Route::middleware('can:vacancies.manage')->group(function () {
        Route::post('vacancy-plans', [VacancyPlanController::class, 'store'])->middleware('throttle:10,1');
        Route::post('vacancy-plans/{plan}/replace', [VacancyPlanController::class, 'replace'])->whereUuid('plan')->middleware('throttle:10,1');
    });
});
