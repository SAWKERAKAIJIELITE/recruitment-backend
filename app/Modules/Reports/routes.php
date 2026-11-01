<?php

use App\Modules\Identity\Http\Middleware\EnsureStaffIsActive;
use App\Modules\Identity\Http\Middleware\RequireAnyPermission;
use App\Modules\Reports\Http\Controllers\ResultsReportController;
use Illuminate\Support\Facades\Route;

// Every route here needs a signed-in, active staff user (D-066).
Route::middleware(['api', 'auth:staff', EnsureStaffIsActive::class, RequireAnyPermission::class . ':reports.view,candidates.manage,interviews.manage'])
    ->prefix('api/v1/staff')
    ->group(function () {
        Route::get('applications/{application}/results-statement', [ResultsReportController::class, 'statement'])->whereUuid('application');
        Route::get('reports/results', [ResultsReportController::class, 'list']);
    });
