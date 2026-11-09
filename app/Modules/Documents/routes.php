<?php

use App\Modules\Documents\Http\Controllers\DocumentController;
use App\Modules\Documents\Http\Controllers\DocumentTypeController;
use App\Modules\Identity\Http\Middleware\EnsureStaffIsActive;
use App\Modules\Identity\Http\Middleware\RequireAnyPermission;
use Illuminate\Support\Facades\Route;

// Every route here needs a signed-in, active staff user. Documents are the Documenting office's (documents.view,
// documents.manage); the Contracts office reads and attaches the documents of an application, like its contract
// (contracts.view, contracts.manage; D-085).
Route::middleware(['api', 'auth:staff', EnsureStaffIsActive::class])->prefix('api/v1/staff')->group(function () {
    Route::get('document-types', [DocumentTypeController::class, 'index']);

    Route::middleware('can:documents.manage')->group(function () {
        Route::post('document-types', [DocumentTypeController::class, 'store']);
        Route::patch('document-types/{id}', [DocumentTypeController::class, 'update'])->whereNumber('id');
        Route::delete('document-types/{id}', [DocumentTypeController::class, 'archive'])->whereNumber('id');
        Route::post('document-types/{id}/restore', [DocumentTypeController::class, 'restore'])->whereNumber('id');
        Route::post('candidates/{candidate}/documents', [DocumentController::class, 'storeForCandidate'])->whereUuid('candidate');
    });

    Route::get('candidates/{candidate}/documents', [DocumentController::class, 'forCandidate'])->whereUuid('candidate')->middleware('can:documents.view');

    Route::middleware(RequireAnyPermission::class . ':documents.view,contracts.view')->group(function () {
        Route::get('applications/{application}/documents', [DocumentController::class, 'forApplication'])->whereUuid('application');
        Route::get('documents/{document}/download', [DocumentController::class, 'download'])->whereUuid('document');
    });

    Route::middleware(RequireAnyPermission::class . ':documents.manage,contracts.manage')->group(function () {
        Route::post('applications/{application}/documents', [DocumentController::class, 'storeForApplication'])->whereUuid('application');
        Route::post('documents/{document}/archive', [DocumentController::class, 'archive'])->whereUuid('document');
    });
});
