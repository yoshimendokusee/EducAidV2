<?php

use App\Http\Controllers\LegacyApiController;
use Illuminate\Support\Facades\Route;

Route::middleware(['api', 'legacy.session.bridge'])->group(function () {
    Route::match(['GET', 'POST'], '/student/get_notification_count.php', [LegacyApiController::class, 'getNotificationCount']);
    Route::match(['GET', 'POST'], '/student/get_notification_preferences.php', [LegacyApiController::class, 'getNotificationPreferences']);
    Route::match(['GET', 'POST'], '/student/save_notification_preferences.php', [LegacyApiController::class, 'saveNotificationPreferences']);
    Route::match(['GET', 'POST'], '/reports/generate_report.php', [LegacyApiController::class, 'generateReport']);
    Route::match(['GET', 'POST'], '/eligibility/subject-check.php', [LegacyApiController::class, 'subjectCheck']);

    // AJAX/legacy fallback for api-like handlers.
    Route::match(['GET', 'POST'], '/legacy/{path}', [LegacyApiController::class, 'ajax'])
        ->where('path', '.*\\.php');
});
