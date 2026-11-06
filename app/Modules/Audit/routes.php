<?php

use App\Modules\Audit\Http\Controllers\AuditEventController;
use App\Modules\Identity\Http\Middleware\EnsureStaffIsActive;
use App\Modules\Identity\Http\Middleware\RequireAnyPermission;
use Illuminate\Support\Facades\Route;

// The audit log (D-078): audit.view reads everything, audit.view_related the operations only.
Route::middleware(['api', 'auth:staff', EnsureStaffIsActive::class, RequireAnyPermission::class.':audit.view,audit.view_related'])
    ->prefix('api/v1/staff')
    ->group(function () {
        Route::get('audit-events', [AuditEventController::class, 'index']);
    });
