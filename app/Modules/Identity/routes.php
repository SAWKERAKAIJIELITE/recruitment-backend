<?php

use App\Modules\Identity\Http\Controllers\StaffDeviceController;
use App\Modules\Identity\Http\Controllers\StaffUserController;
use App\Modules\Identity\Http\Controllers\PermissionController;
use App\Modules\Identity\Http\Controllers\RoleController;
use App\Modules\Identity\Http\Controllers\StaffAuthController;
use App\Modules\Identity\Http\Middleware\EnsureStaffIsActive;
use Illuminate\Support\Facades\Route;

Route::middleware('api')->prefix('api/v1')->group(function () {
    Route::get('/ping', fn() => response()->json([
        'status' => 'ok',
        'module' => 'identity',
    ]));

    Route::prefix('staff')->group(function () {

        Route::post('devices/register', [StaffDeviceController::class, 'register'])
            ->middleware('throttle:device-register');

        Route::get('devices/{device}/status', [StaffDeviceController::class, 'status'])
            ->whereUuid('device')
            ->middleware('throttle:device-status');

        Route::prefix('auth')->group(function () {
            Route::post('challenge', [StaffAuthController::class, 'challenge'])->middleware('throttle:device-challenge');
            Route::post('login', [StaffAuthController::class, 'login'])->middleware('throttle:staff-login');

            Route::middleware(['auth:staff', EnsureStaffIsActive::class])->group(function () {
                Route::post('logout', [StaffAuthController::class, 'logout']);
                Route::get('me', [StaffAuthController::class, 'me']);
            });
        });

        // Device administration
        Route::middleware(['auth:staff', EnsureStaffIsActive::class, 'can:devices.approve'])
            ->prefix('devices')
            ->group(function () {
                Route::get('/', [StaffDeviceController::class, 'index']);
                Route::post('{device}/approve', [StaffDeviceController::class, 'approve'])->whereUuid('device');
                Route::post('{device}/revoke', [StaffDeviceController::class, 'revoke'])->whereUuid('device');
            });

        // Access management: roles, permissions and per-user overrides
        Route::middleware(['auth:staff', EnsureStaffIsActive::class, 'can:users.manage'])->group(function () {
            Route::get('permissions', [PermissionController::class, 'index']);

            Route::get('roles', [RoleController::class, 'index']);
            Route::post('roles/{role:slug}/permissions', [RoleController::class, 'addPermissions']);
            Route::delete('roles/{role:slug}/permissions/{permission:slug}', [RoleController::class, 'removePermission'])
                ->scopeBindings();

            Route::get('users', [StaffUserController::class, 'index']);
            Route::get('users/{user}', [StaffUserController::class, 'show'])->whereUuid('user');
            Route::post('users/{user}/roles', [StaffUserController::class, 'addRole'])->whereUuid('user');
            Route::delete('users/{user}/roles/{role:slug}', [StaffUserController::class, 'removeRole'])
                ->whereUuid('user')->scopeBindings();
            Route::post('users/{user}/permissions', [StaffUserController::class, 'setPermission'])->whereUuid('user');
            Route::delete('users/{user}/permissions/{permission:slug}', [StaffUserController::class, 'clearPermission'])
                ->whereUuid('user')->scopeBindings();
        });
    });
});
