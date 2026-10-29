<?php

use App\Modules\Documents\Http\Controllers\DocumentController;
use App\Modules\Documents\Http\Controllers\DocumentTypeController;
use App\Modules\Identity\Http\Middleware\EnsureStaffIsActive;
use App\Modules\Identity\Http\Middleware\RequireAnyPermission;
use Illuminate\Support\Facades\Route;

Route::middleware('api')->prefix('api/v1/staff')->group(function () {
    Route::middleware(['auth:staff', EnsureStaffIsActive::class])->group(function () {
        Route::get('document-types', [DocumentTypeController::class, 'index']);

        Route::middleware('can:candidates.manage')->group(function () {
            Route::post('document-types', [DocumentTypeController::class, 'store']);
            Route::patch('document-types/{id}', [DocumentTypeController::class, 'update'])->whereNumber('id');
            Route::delete('document-types/{id}', [DocumentTypeController::class, 'archive'])->whereNumber('id');
            Route::post('document-types/{id}/restore', [DocumentTypeController::class, 'restore'])->whereNumber('id');
        });

        Route::middleware(RequireAnyPermission::class . ':reports.view,candidates.manage,contracts.manage')->group(function () {
            Route::get('candidates/{candidate}/documents', [DocumentController::class, 'forCandidate'])->whereUuid('candidate');
            Route::get('applications/{application}/documents', [DocumentController::class, 'forApplication'])->whereUuid('application');
            Route::get('documents/{document}/download', [DocumentController::class, 'download'])->whereUuid('document');
        });

        Route::middleware(RequireAnyPermission::class . ':candidates.manage,contracts.manage')->group(function () {
            Route::post('candidates/{candidate}/documents', [DocumentController::class, 'storeForCandidate'])->whereUuid('candidate');
            Route::post('applications/{application}/documents', [DocumentController::class, 'storeForApplication'])->whereUuid('application');
            Route::post('documents/{document}/archive', [DocumentController::class, 'archive'])->whereUuid('document');
        });
    });
});
