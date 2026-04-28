<?php

use App\Http\Controllers\CompatApiController;
use App\Http\Controllers\EligibilityController;
use App\Http\Controllers\AdminApplicantController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\StudentApiController;
use App\Http\Controllers\WorkflowController;
use Illuminate\Support\Facades\Route;

Route::middleware(['api', 'compat.session.bridge'])->group(function () {
    // Native migrated module: includes/workflow_control.php
    Route::get('/workflow/status', [WorkflowController::class, 'status']);
    Route::get('/workflow/student-counts', [WorkflowController::class, 'studentCounts']);

    // Native migrated module: api/student/*.php (notifications/privacy + export bridge)
    Route::get('/student/get_notification_count.php', [StudentApiController::class, 'getNotificationCount']);
    Route::get('/student/get_notification_preferences.php', [StudentApiController::class, 'getNotificationPreferences']);
    Route::post('/student/save_notification_preferences.php', [StudentApiController::class, 'saveNotificationPreferences']);
    Route::post('/student/mark_notification_read.php', [StudentApiController::class, 'markNotificationRead']);
    Route::post('/student/mark_all_notifications_read.php', [StudentApiController::class, 'markAllNotificationsRead']);
    Route::post('/student/delete_notification.php', [StudentApiController::class, 'deleteNotification']);
    Route::get('/student/get_privacy_settings.php', [StudentApiController::class, 'getPrivacySettings']);
    Route::post('/student/save_privacy_settings.php', [StudentApiController::class, 'savePrivacySettings']);

    // Export kept behavior-identical by delegating to legacy scripts for now.
    Route::post('/student/request_data_export.php', [StudentApiController::class, 'requestDataExport']);
    Route::get('/student/export_status.php', [StudentApiController::class, 'exportStatus']);
    Route::get('/student/download_export.php', [StudentApiController::class, 'downloadExport']);

    // Admin applicants module (native + bridged)
    Route::get('/admin/applicants/badge-count', [AdminApplicantController::class, 'badgeCount']);
    Route::get('/admin/applicants/details', [AdminApplicantController::class, 'details']);
    Route::post('/admin/applicants/actions', [AdminApplicantController::class, 'actions']);

    Route::match(['GET', 'POST'], '/reports/generate_report.php', [ReportController::class, 'generate']);
    Route::match(['POST', 'OPTIONS'], '/eligibility/subject-check.php', [EligibilityController::class, 'subjectCheck']);

    // AJAX/compat fallback for api-like handlers.
    Route::match(['GET', 'POST'], '/compat/{path}', [CompatApiController::class, 'ajax'])
        ->where('path', '.*\\.php');
});
