<?php

use App\Http\Controllers\AdminModulesController;
use App\Http\Controllers\CompatWebController;
use App\Http\Controllers\StudentModulesController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'compat.session.bridge'])->group(function () {
    // Core entry points.
    Route::match(['GET', 'POST'], '/', [CompatWebController::class, 'root']);
    Route::match(['GET', 'POST'], '/unified_login.php', [CompatWebController::class, 'unifiedLogin']);
    Route::get('/compat/render', [CompatWebController::class, 'render']);

    // Login alias used by React frontend route.
    Route::match(['GET', 'POST'], '/login', [CompatWebController::class, 'unifiedLogin']);

    // Module index flows.
    Route::match(['GET', 'POST'], '/modules/admin/index.php', [CompatWebController::class, 'adminIndex']);
    Route::match(['GET', 'POST'], '/modules/student/student_login.php', [CompatWebController::class, 'studentLogin']);



    // Module-folder bridges kept separate by area.
    Route::prefix('/modules/admin')->group(function () {
        Route::match(['GET', 'POST'], '/{path}', [AdminModulesController::class, 'render'])
            ->where('path', '.*\.php');
    });

    Route::prefix('/modules/student')->group(function () {
        Route::match(['GET', 'POST'], '/{path}', [StudentModulesController::class, 'render'])
            ->where('path', '.*\.php');
    });

    // Fallback for any remaining php page route during incremental migration.
    Route::match(['GET', 'POST'], '/{path}', [CompatWebController::class, 'fallback'])
        ->where('path', '.*\\.php');
});
