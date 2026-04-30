<?php

use App\Http\Controllers\CompatApiController;
use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

// Public authentication routes (no session middleware required for initial check)
Route::prefix('/auth')->group(function () {
    Route::post('/status', [AuthController::class, 'status']);
    Route::post('/student-login', [AuthController::class, 'studentLogin']);
    Route::post('/admin-login', [AuthController::class, 'adminLogin']);
    Route::post('/logout', [AuthController::class, 'logout']);
});

Route::middleware(['api', 'compat.session.bridge'])->group(function () {
    Route::match(['GET', 'POST'], '/compat/{path}', [CompatApiController::class, 'ajax'])
        ->where('path', '.*\\.php');
});
