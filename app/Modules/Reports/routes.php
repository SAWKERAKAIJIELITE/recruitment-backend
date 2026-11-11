<?php

use App\Modules\Identity\Http\Middleware\EnsureStaffIsActive;
use App\Modules\Identity\Http\Middleware\RequireAnyPermission;
use App\Modules\Reports\Http\Controllers\ReportController;
use App\Modules\Reports\Http\Controllers\ResultsReportController;
use Illuminate\Support\Facades\Route;

// Every route here needs a signed-in, active staff user (D-066, D-068). Each report belongs to a section (D-086).
Route::middleware(['api', 'auth:staff', EnsureStaffIsActive::class])->prefix('api/v1/staff')->group(function () {
    // For the candidates: Recruitment, who talks to them, and the general manager.
    Route::middleware('can:applications.view')->group(function () {
        Route::get('applications/{application}/results-statement', [ResultsReportController::class, 'statement'])->whereUuid('application');
        Route::get('reports/results', [ResultsReportController::class, 'list']);
    });

    // Across every section: the general manager and the system administrator.
    Route::middleware('can:reports.view')->group(function () {
        Route::get('reports/summary', [ReportController::class, 'summary']);
        Route::get('reports/workbook', [ReportController::class, 'workbook']);
    });

    Route::get('reports/conscription-list', [ReportController::class, 'conscription'])->middleware('can:candidates.manage');

    // Each report its section's: applications (applications.view), exams (exams.view), interviews (interviews.view),
    // dashboard (reports.view).
    // {report} binds to the ReportName enum: an unknown report is 404.
    Route::get('reports/{report}', [ReportController::class, 'export'])
        ->middleware(RequireAnyPermission::class . ':applications.view,exams.view,interviews.view,reports.view');
});
