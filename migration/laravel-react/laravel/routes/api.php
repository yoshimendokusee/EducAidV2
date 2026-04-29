<?php

use App\Http\Controllers\CompatApiController;
use App\Http\Controllers\EligibilityController;
use App\Http\Controllers\AdminApplicantController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\DistributionController;
use App\Http\Controllers\EnrollmentOcrController;
use App\Http\Controllers\FileCompressionController;
use App\Http\Controllers\NotificationController;
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

    // New migrated services: Documents and File Management
    Route::prefix('/documents')->group(function () {
        Route::get('/student-documents', [DocumentController::class, 'getStudentDocuments']);
        Route::post('/move-to-perm-storage', [DocumentController::class, 'moveToPermStorage']);
        Route::post('/archive', [DocumentController::class, 'archiveDocuments']);
        Route::post('/delete', [DocumentController::class, 'deleteDocuments']);
        Route::post('/process-grade-ocr', [DocumentController::class, 'processGradeOcr']);
        Route::get('/export-zip', [DocumentController::class, 'exportZip']);
        Route::get('/reupload-status', [DocumentController::class, 'getReuploadStatus']);
        Route::post('/reupload', [DocumentController::class, 'reuploadDocument']);
        Route::post('/complete-reupload', [DocumentController::class, 'completeReupload']);
    });

    // Admin login page content editing
    Route::prefix('/admin/login-content')->group(function () {
        Route::post('/save', '\App\Http\Controllers\LoginContentController@save');
        Route::post('/toggle-section', '\App\Http\Controllers\LoginContentController@toggleSection');
    });

    // File Compression Service
    Route::prefix('/compression')->group(function () {
        Route::post('/compress-distribution', [FileCompressionController::class, 'compressDistribution']);
        Route::post('/decompress-distribution', [FileCompressionController::class, 'decompressDistribution']);
        Route::post('/cleanup-archives', [FileCompressionController::class, 'cleanupOldArchives']);
    });

    // Distribution Management
    Route::prefix('/distributions')->group(function () {
        Route::post('/end-distribution', [DistributionController::class, 'endDistribution']);
        Route::get('/stats', [DistributionController::class, 'getDistributionStats']);
    });

    // Notifications
    Route::prefix('/notifications')->group(function () {
        Route::post('/create', [NotificationController::class, 'createNotification']);
        Route::get('/unread', [NotificationController::class, 'getUnreadNotifications']);
        Route::post('/mark-read', [NotificationController::class, 'markAsRead']);
        Route::post('/mark-all-read', [NotificationController::class, 'markAllAsRead']);
        Route::post('/delete', [NotificationController::class, 'deleteNotification']);
        Route::get('/stats', [NotificationController::class, 'getStatistics']);
    });

    // Enrollment Form OCR
    Route::prefix('/enrollment-ocr')->group(function () {
        Route::post('/extract-data', [EnrollmentOcrController::class, 'extractFormData']);
        Route::post('/validate-data', [EnrollmentOcrController::class, 'validateFormData']);
    });

    // AJAX/compat fallback for api-like handlers.
    Route::match(['GET', 'POST'], '/compat/{path}', [CompatApiController::class, 'ajax'])
        ->where('path', '.*\\.php');
});
