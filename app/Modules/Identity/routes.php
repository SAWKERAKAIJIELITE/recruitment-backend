<?php

use App\Modules\Identity\Http\Controllers\StaffAuthController;
use App\Modules\Identity\Http\Middleware\EnsureStaffIsActive;
use Illuminate\Support\Facades\Route;

Route::middleware('api')->prefix('api/v1')->group(function () {
    Route::get('/ping', fn() => response()->json([
        'status' => 'ok',
        'module' => 'identity',
    ]));

    Route::prefix('staff/auth')->group(function () {
    Route::post('login', [StaffAuthController::class, 'login'])->middleware('throttle:staff-login');

    Route::middleware(['auth:staff',EnsureStaffIsActive::class])->group(function () {
        Route::post('logout', [StaffAuthController::class, 'logout']);
        Route::get('me', [StaffAuthController::class, 'me']);
    });
    });
});
