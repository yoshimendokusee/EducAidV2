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

    Route::match(['GET', 'POST'], '/modules/admin/manage_applicants.php', [AdminModulesController::class, 'manageApplicants']);
    Route::match(['GET', 'POST'], '/modules/admin/distribution_control.php', [AdminModulesController::class, 'distributionControl']);
    Route::match(['GET', 'POST'], '/modules/admin/verify_students.php', [AdminModulesController::class, 'verifyStudents']);
    Route::match(['GET', 'POST'], '/modules/admin/reports.php', [AdminModulesController::class, 'reports']);
    Route::match(['GET', 'POST'], '/modules/admin/archived_students.php', [AdminModulesController::class, 'archivedStudents']);
    Route::match(['GET', 'POST'], '/modules/admin/view_documents.php', [AdminModulesController::class, 'viewDocuments']);
    Route::match(['GET', 'POST'], '/modules/admin/admin_notifications.php', [AdminModulesController::class, 'adminNotifications']);
    Route::match(['GET', 'POST'], '/modules/admin/admin_profile.php', [AdminModulesController::class, 'adminProfile']);
    Route::match(['GET', 'POST'], '/modules/admin/settings.php', [AdminModulesController::class, 'settings']);
    Route::match(['GET', 'POST'], '/modules/admin/sidebar_settings.php', [AdminModulesController::class, 'sidebarSettings']);
    Route::match(['GET', 'POST'], '/modules/admin/sidebar_settings_enhanced.php', [AdminModulesController::class, 'sidebarSettingsEnhanced']);
    Route::match(['GET', 'POST'], '/modules/admin/footer_settings.php', [AdminModulesController::class, 'footerSettings']);
    Route::match(['GET', 'POST'], '/modules/admin/topbar_settings.php', [AdminModulesController::class, 'topbarSettings']);
    Route::match(['GET', 'POST'], '/modules/admin/header_appearance.php', [AdminModulesController::class, 'headerAppearance']);
    Route::match(['GET', 'POST'], '/modules/admin/manage_slots.php', [AdminModulesController::class, 'manageSlots']);
    Route::match(['GET', 'POST'], '/modules/admin/manage_schedules.php', [AdminModulesController::class, 'manageSchedules']);
    Route::match(['GET', 'POST'], '/modules/admin/manage_announcements.php', [AdminModulesController::class, 'manageAnnouncements']);
    Route::match(['GET', 'POST'], '/modules/admin/audit_logs.php', [AdminModulesController::class, 'auditLogs']);
    Route::match(['GET', 'POST'], '/modules/admin/storage_dashboard.php', [AdminModulesController::class, 'storageDashboard']);
    Route::match(['GET', 'POST'], '/modules/admin/blacklist_archive.php', [AdminModulesController::class, 'blacklistArchive']);
    Route::match(['GET', 'POST'], '/modules/admin/blacklist_details.php', [AdminModulesController::class, 'blacklistDetails']);
    Route::match(['GET', 'POST'], '/modules/admin/scan_qr.php', [AdminModulesController::class, 'scanQr']);
    Route::match(['GET', 'POST'], '/modules/admin/scanner.php', [AdminModulesController::class, 'scanner']);
    Route::match(['GET', 'POST'], '/modules/admin/admin_management.php', [AdminModulesController::class, 'adminManagement']);
    Route::match(['GET', 'POST'], '/modules/admin/review_registrations.php', [AdminModulesController::class, 'reviewRegistrations']);
    Route::match(['GET', 'POST'], '/modules/admin/view_graduating_students.php', [AdminModulesController::class, 'viewGraduatingStudents']);
    Route::match(['GET', 'POST'], '/modules/admin/view_qr_codes_dev.php', [AdminModulesController::class, 'viewQrCodesDev']);
    Route::match(['GET', 'POST'], '/modules/admin/system_data.php', [AdminModulesController::class, 'systemData']);
    Route::match(['GET', 'POST'], '/modules/admin/file_browser.php', [AdminModulesController::class, 'fileBrowser']);
    Route::match(['GET', 'POST'], '/modules/admin/municipality_content.php', [AdminModulesController::class, 'municipalityContent']);
    Route::match(['GET', 'POST'], '/modules/admin/addbarangay.php', [AdminModulesController::class, 'addBarangay']);
    Route::match(['GET', 'POST'], '/modules/admin/download_distribution.php', [AdminModulesController::class, 'downloadDistribution']);
    Route::match(['GET', 'POST'], '/modules/admin/distribution_archives.php', [AdminModulesController::class, 'distributionArchives']);
    Route::match(['GET', 'POST'], '/modules/admin/get_applicant_details.php', [AdminModulesController::class, 'getApplicantDetails']);
    Route::match(['GET', 'POST'], '/modules/admin/get_distribution_details.php', [AdminModulesController::class, 'getDistributionDetails']);
    Route::match(['GET', 'POST'], '/modules/admin/get_grade_details.php', [AdminModulesController::class, 'getGradeDetails']);
    Route::match(['GET', 'POST'], '/modules/admin/get_registrant_details.php', [AdminModulesController::class, 'getRegistrantDetails']);
    Route::match(['GET', 'POST'], '/modules/admin/get_slot_stats.php', [AdminModulesController::class, 'getSlotStats']);
    Route::match(['GET', 'POST'], '/modules/admin/get_student_document.php', [AdminModulesController::class, 'getStudentDocument']);
    Route::match(['GET', 'POST'], '/modules/admin/get_archived_student_details.php', [AdminModulesController::class, 'getArchivedStudentDetails']);
    Route::match(['GET', 'POST'], '/modules/admin/get_graduating_students_list.php', [AdminModulesController::class, 'getGraduatingStudentsList']);
    Route::match(['GET', 'POST'], '/modules/admin/get_confidence_breakdown.php', [AdminModulesController::class, 'getConfidenceBreakdown']);
    Route::match(['GET', 'POST'], '/modules/admin/refresh_confidence_scores.php', [AdminModulesController::class, 'refreshConfidenceScores']);
    Route::match(['GET', 'POST'], '/modules/admin/save_manual_grades.php', [AdminModulesController::class, 'saveManualGrades']);
    Route::match(['GET', 'POST'], '/modules/admin/restore_blacklisted_student.php', [AdminModulesController::class, 'restoreBlacklistedStudent']);
    Route::match(['GET', 'POST'], '/modules/admin/compress_archived_students.php', [AdminModulesController::class, 'compressArchivedStudents']);
    Route::match(['GET', 'POST'], '/modules/admin/download_blacklist_zip.php', [AdminModulesController::class, 'downloadBlacklistZip']);
    Route::match(['GET', 'POST'], '/modules/admin/end_distribution.php', [AdminModulesController::class, 'endDistribution']);
    Route::match(['GET', 'POST'], '/modules/admin/reset_distribution.php', [AdminModulesController::class, 'resetDistribution']);
    Route::match(['GET', 'POST'], '/modules/admin/generate_and_apply_theme.php', [AdminModulesController::class, 'generateAndApplyTheme']);
    Route::match(['GET', 'POST'], '/modules/admin/auto_approve_high_confidence.php', [AdminModulesController::class, 'autoApproveHighConfidence']);
    Route::match(['GET', 'POST'], '/modules/admin/blacklist_service.php', [AdminModulesController::class, 'blacklistService']);
    Route::match(['GET', 'POST'], '/modules/admin/check_automatic_archiving.php', [AdminModulesController::class, 'checkAutomaticArchiving']);
    Route::match(['GET', 'POST'], '/modules/admin/check_file_status.php', [AdminModulesController::class, 'checkFileStatus']);
    Route::match(['GET', 'POST'], '/modules/admin/household_blocked_registrations.php', [AdminModulesController::class, 'householdBlockedRegistrations']);
    Route::match(['GET', 'POST'], '/modules/admin/recaptcha_diagnostics.php', [AdminModulesController::class, 'recaptchaDiagnostics']);
    Route::match(['GET', 'POST'], '/modules/admin/slot_threshold_admin.php', [AdminModulesController::class, 'slotThresholdAdmin']);
    Route::match(['GET', 'POST'], '/modules/admin/upload_municipality_logo.php', [AdminModulesController::class, 'uploadMunicipalityLogo']);
    Route::match(['GET', 'POST'], '/modules/admin/toggle_municipality_logo.php', [AdminModulesController::class, 'toggleMunicipalityLogo']);
    Route::match(['GET', 'POST'], '/modules/admin/update_municipality_colors.php', [AdminModulesController::class, 'updateMunicipalityColors']);
    Route::match(['GET', 'POST'], '/modules/admin/verify_password.php', [AdminModulesController::class, 'verifyPassword']);
    Route::match(['GET', 'POST'], '/modules/admin/verify_password_debug.php', [AdminModulesController::class, 'verifyPasswordDebug']);
    Route::match(['GET', 'POST'], '/modules/admin/notifications_api.php', [AdminModulesController::class, 'notificationsApi']);

    Route::match(['GET', 'POST'], '/modules/student/upload_document.php', [StudentModulesController::class, 'uploadDocument']);
    Route::match(['GET', 'POST'], '/modules/student/student_register.php', [StudentModulesController::class, 'studentRegister']);
    Route::match(['GET', 'POST'], '/modules/student/student_profile.php', [StudentModulesController::class, 'studentProfile']);
    Route::match(['GET', 'POST'], '/modules/student/student_settings.php', [StudentModulesController::class, 'studentSettings']);
    Route::match(['GET', 'POST'], '/modules/student/student_notifications.php', [StudentModulesController::class, 'studentNotifications']);
    Route::match(['GET', 'POST'], '/modules/student/security_privacy.php', [StudentModulesController::class, 'securityPrivacy']);
    Route::match(['GET', 'POST'], '/modules/student/security_activity.php', [StudentModulesController::class, 'securityActivity']);
    Route::match(['GET', 'POST'], '/modules/student/privacy_data.php', [StudentModulesController::class, 'privacyData']);
    Route::match(['GET', 'POST'], '/modules/student/student_homepage.php', [StudentModulesController::class, 'studentHomepage']);
    Route::match(['GET', 'POST'], '/modules/student/logout.php', [StudentModulesController::class, 'logout']);
    Route::match(['GET', 'POST'], '/modules/student/active_sessions.php', [StudentModulesController::class, 'activeSessions']);
    Route::match(['GET', 'POST'], '/modules/student/accessibility.php', [StudentModulesController::class, 'accessibility']);
    Route::match(['GET', 'POST'], '/modules/student/serve_profile_image.php', [StudentModulesController::class, 'serveProfileImage']);
    Route::match(['GET', 'POST'], '/modules/student/qr_code.php', [StudentModulesController::class, 'qrCode']);
    Route::match(['GET', 'POST'], '/modules/student/download_qr.php', [StudentModulesController::class, 'downloadQr']);
    Route::match(['GET', 'POST'], '/modules/student/phone_check.php', [StudentModulesController::class, 'phoneCheck']);
    Route::match(['GET', 'POST'], '/modules/student/process_id_picture.php', [StudentModulesController::class, 'processIdPicture']);
    Route::match(['GET', 'POST'], '/modules/student/update_year_level.php', [StudentModulesController::class, 'updateYearLevel']);
    Route::match(['GET', 'POST'], '/modules/student/notifications_api.php', [StudentModulesController::class, 'notificationsApi']);
    Route::match(['GET', 'POST'], '/modules/student/api_payroll_history.php', [StudentModulesController::class, 'apiPayrollHistory']);
    Route::match(['GET', 'POST'], '/modules/student/create_session.php', [StudentModulesController::class, 'createSession']);
    Route::match(['GET', 'POST'], '/modules/student/get_validation_details.php', [StudentModulesController::class, 'getValidationDetails']);
    Route::match(['GET', 'POST'], '/modules/student/grade_validation_functions.php', [StudentModulesController::class, 'gradeValidationFunctions']);
    Route::match(['GET', 'POST'], '/modules/student/ignore_student_register2.php', [StudentModulesController::class, 'ignoreStudentRegister']);
    Route::match(['GET', 'POST'], '/modules/student/ignore_upload_document.php', [StudentModulesController::class, 'ignoreUploadDocument']);
    Route::match(['GET', 'POST'], '/modules/student/debug_logger.php', [StudentModulesController::class, 'debugLogger']);

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
