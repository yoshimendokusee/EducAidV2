<?php

namespace App\Http\Controllers;

use App\Services\CompatScriptRunner;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminModulesController extends Controller
{
    public function __construct(private readonly CompatScriptRunner $runner)
    {
    }

    public function manageApplicants(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/manage_applicants.php');
    }

    public function distributionControl(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/distribution_control.php');
    }

    public function verifyStudents(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/verify_students.php');
    }

    public function reports(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/reports.php');
    }

    public function archivedStudents(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/archived_students.php');
    }

    public function viewDocuments(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/view_documents.php');
    }

    public function adminNotifications(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/admin_notifications.php');
    }

    public function adminProfile(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/admin_profile.php');
    }

    public function settings(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/settings.php');
    }

    public function sidebarSettings(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/sidebar_settings.php');
    }

    public function sidebarSettingsEnhanced(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/sidebar_settings_enhanced.php');
    }

    public function footerSettings(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/footer_settings.php');
    }

    public function topbarSettings(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/topbar_settings.php');
    }

    public function headerAppearance(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/header_appearance.php');
    }

    public function manageSlots(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/manage_slots.php');
    }

    public function manageSchedules(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/manage_schedules.php');
    }

    public function manageAnnouncements(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/manage_announcements.php');
    }

    public function auditLogs(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/audit_logs.php');
    }

    public function storageDashboard(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/storage_dashboard.php');
    }

    public function blacklistArchive(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/blacklist_archive.php');
    }

    public function blacklistDetails(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/blacklist_details.php');
    }

    public function scanQr(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/scan_qr.php');
    }

    public function scanner(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/scanner.php');
    }

    public function adminManagement(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/admin_management.php');
    }

    public function reviewRegistrations(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/review_registrations.php');
    }

    public function viewGraduatingStudents(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/view_graduating_students.php');
    }

    public function viewQrCodesDev(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/view_qr_codes_dev.php');
    }

    public function systemData(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/system_data.php');
    }

    public function fileBrowser(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/file_browser.php');
    }

    public function municipalityContent(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/municipality_content.php');
    }

    public function addBarangay(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/addbarangay.php');
    }

    public function downloadDistribution(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/download_distribution.php');
    }

    public function distributionArchives(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/distribution_archives.php');
    }

    public function getApplicantDetails(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/get_applicant_details.php');
    }

    public function getDistributionDetails(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/get_distribution_details.php');
    }

    public function getGradeDetails(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/get_grade_details.php');
    }

    public function getRegistrantDetails(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/get_registrant_details.php');
    }

    public function getSlotStats(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/get_slot_stats.php');
    }

    public function getStudentDocument(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/get_student_document.php');
    }

    public function getArchivedStudentDetails(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/get_archived_student_details.php');
    }

    public function getGraduatingStudentsList(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/get_graduating_students_list.php');
    }

    public function getConfidenceBreakdown(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/get_confidence_breakdown.php');
    }

    public function refreshConfidenceScores(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/refresh_confidence_scores.php');
    }

    public function saveManualGrades(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/save_manual_grades.php');
    }

    public function restoreBlacklistedStudent(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/restore_blacklisted_student.php');
    }

    public function compressArchivedStudents(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/compress_archived_students.php');
    }

    public function downloadBlacklistZip(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/download_blacklist_zip.php');
    }

    public function endDistribution(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/end_distribution.php');
    }

    public function resetDistribution(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/reset_distribution.php');
    }

    public function generateAndApplyTheme(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/generate_and_apply_theme.php');
    }

    public function autoApproveHighConfidence(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/auto_approve_high_confidence.php');
    }

    public function blacklistService(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/blacklist_service.php');
    }

    public function checkAutomaticArchiving(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/check_automatic_archiving.php');
    }

    public function checkFileStatus(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/check_file_status.php');
    }

    public function householdBlockedRegistrations(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/household_blocked_registrations.php');
    }

    public function recaptchaDiagnostics(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/recaptcha_diagnostics.php');
    }

    public function slotThresholdAdmin(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/slot_threshold_admin.php');
    }

    public function uploadMunicipalityLogo(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/upload_municipality_logo.php');
    }

    public function toggleMunicipalityLogo(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/toggle_municipality_logo.php');
    }

    public function updateMunicipalityColors(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/update_municipality_colors.php');
    }

    public function verifyPassword(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/verify_password.php');
    }

    public function verifyPasswordDebug(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/verify_password_debug.php');
    }

    public function notificationsApi(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/notifications_api.php');
    }

    public function render(Request $request, string $path): Response
    {
        if (!str_ends_with($path, '.php')) {
            return response('Not Found', 404);
        }

        return $this->runner->run($request, 'modules/admin/' . ltrim($path, '/'));
    }
}
