<?php

use App\Modules\Identity\Http\Middleware\EnsureStaffIsActive;
use App\Modules\Identity\Http\Middleware\RequireAnyPermission;
use App\Modules\Imports\Http\Controllers\ImportBatchController;
use App\Modules\Imports\Http\Controllers\ImportRowController;
use Illuminate\Support\Facades\Route;

/*
 * Excel import of candidates (analysis doc §09).
 * - imports.manage: upload, correct, review, exclude, cancel (D-041)
 * - imports.approve (individual permission): approve, never one's own batch (D-047)
 * - either of them: read batches, rows, the original file and the report
 */
Route::middleware(['api', 'auth:staff', EnsureStaffIsActive::class])
    ->prefix('api/v1/staff/imports')
    ->whereUuid(['batch', 'row'])
    ->group(function () {
        Route::middleware(RequireAnyPermission::class . ':imports.manage,imports.approve')->group(function () {
            Route::get('/', [ImportBatchController::class, 'index']);
            Route::get('{batch}', [ImportBatchController::class, 'show']);
            Route::get('{batch}/original', [ImportBatchController::class, 'original']);
            Route::get('{batch}/report', [ImportBatchController::class, 'report']);
            Route::get('{batch}/rows', [ImportRowController::class, 'index'])->scopeBindings();
            Route::get('{batch}/rows/{row}', [ImportRowController::class, 'show'])->scopeBindings();
        });

        Route::middleware('can:imports.manage')->group(function () {
            // Uploading parses a whole workbook: a few per minute per user is plenty.
            Route::post('/', [ImportBatchController::class, 'store'])->middleware('throttle:10,1');
            Route::post('{batch}/revalidate', [ImportBatchController::class, 'revalidate']);
            Route::post('{batch}/cancel', [ImportBatchController::class, 'cancel']);

            // A row is found only inside its own batch.
            Route::scopeBindings()->group(function () {
                Route::patch('{batch}/rows/{row}', [ImportRowController::class, 'update']);
                Route::post('{batch}/rows/{row}/confirm-person', [ImportRowController::class, 'confirmPerson']);
                Route::post('{batch}/rows/{row}/exclude', [ImportRowController::class, 'exclude']);
                Route::post('{batch}/rows/{row}/include', [ImportRowController::class, 'include']);
            });
        });

        Route::post('{batch}/approve', [ImportBatchController::class, 'approve'])->middleware('can:imports.approve');
    });
