<?php

use App\Http\Controllers\LegacyWebController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'legacy.session.bridge'])->group(function () {
    // Core entry points.
    Route::match(['GET', 'POST'], '/', [LegacyWebController::class, 'root']);
    Route::match(['GET', 'POST'], '/unified_login.php', [LegacyWebController::class, 'unifiedLogin']);
    Route::get('/legacy/render', [LegacyWebController::class, 'render']);

    // Login alias used by React frontend route.
    Route::match(['GET', 'POST'], '/login', [LegacyWebController::class, 'unifiedLogin']);

    // Module index flows.
    Route::match(['GET', 'POST'], '/modules/admin/index.php', [LegacyWebController::class, 'adminIndex']);
    Route::match(['GET', 'POST'], '/modules/student/student_login.php', [LegacyWebController::class, 'studentLogin']);

    // Fallback for any remaining php page route during incremental migration.
    Route::match(['GET', 'POST'], '/{path}', [LegacyWebController::class, 'fallback'])
        ->where('path', '.*\\.php');
});
