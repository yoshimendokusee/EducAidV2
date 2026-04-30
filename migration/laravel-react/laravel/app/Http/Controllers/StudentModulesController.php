<?php

namespace App\Http\Controllers;

use App\Services\CompatScriptRunner;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class StudentModulesController extends Controller
{
    public function __construct(private readonly CompatScriptRunner $runner)
    {
    }

    public function uploadDocument(Request $request): Response
    {
        return $this->runner->run($request, 'modules/student/upload_document.php');
    }

    public function studentRegister(Request $request): Response
    {
        return $this->runner->run($request, 'modules/student/student_register.php');
    }

    public function studentProfile(Request $request): Response
    {
        return $this->runner->run($request, 'modules/student/student_profile.php');
    }

    public function studentSettings(Request $request): Response
    {
        return $this->runner->run($request, 'modules/student/student_settings.php');
    }

    public function studentNotifications(Request $request): Response
    {
        return $this->runner->run($request, 'modules/student/student_notifications.php');
    }

    public function securityPrivacy(Request $request): Response
    {
        return $this->runner->run($request, 'modules/student/security_privacy.php');
    }

    public function securityActivity(Request $request): Response
    {
        return $this->runner->run($request, 'modules/student/security_activity.php');
    }

    public function privacyData(Request $request): Response
    {
        return $this->runner->run($request, 'modules/student/privacy_data.php');
    }

    public function studentHomepage(Request $request): Response
    {
        return $this->runner->run($request, 'modules/student/student_homepage.php');
    }

    public function logout(Request $request): Response
    {
        return $this->runner->run($request, 'modules/student/logout.php');
    }

    public function activeSessions(Request $request): Response
    {
        return $this->runner->run($request, 'modules/student/active_sessions.php');
    }

    public function accessibility(Request $request): Response
    {
        return $this->runner->run($request, 'modules/student/accessibility.php');
    }

    public function serveProfileImage(Request $request): Response
    {
        return $this->runner->run($request, 'modules/student/serve_profile_image.php');
    }

    public function qrCode(Request $request): Response
    {
        return $this->runner->run($request, 'modules/student/qr_code.php');
    }

    public function downloadQr(Request $request): Response
    {
        return $this->runner->run($request, 'modules/student/download_qr.php');
    }

    public function phoneCheck(Request $request): Response
    {
        return $this->runner->run($request, 'modules/student/phone_check.php');
    }

    public function processIdPicture(Request $request): Response
    {
        return $this->runner->run($request, 'modules/student/process_id_picture.php');
    }

    public function updateYearLevel(Request $request): Response
    {
        return $this->runner->run($request, 'modules/student/update_year_level.php');
    }

    public function notificationsApi(Request $request): Response
    {
        return $this->runner->run($request, 'modules/student/notifications_api.php');
    }

    public function apiPayrollHistory(Request $request): Response
    {
        return $this->runner->run($request, 'modules/student/api_payroll_history.php');
    }

    public function createSession(Request $request): Response
    {
        return $this->runner->run($request, 'modules/student/create_session.php');
    }

    public function getValidationDetails(Request $request): Response
    {
        return $this->runner->run($request, 'modules/student/get_validation_details.php');
    }

    public function gradeValidationFunctions(Request $request): Response
    {
        return $this->runner->run($request, 'modules/student/grade_validation_functions.php');
    }

    public function ignoreStudentRegister(Request $request): Response
    {
        return $this->runner->run($request, 'modules/student/ignore_student_register2.php');
    }

    public function ignoreUploadDocument(Request $request): Response
    {
        return $this->runner->run($request, 'modules/student/ignore_upload_document.php');
    }

    public function debugLogger(Request $request): Response
    {
        return $this->runner->run($request, 'modules/student/debug_logger.php');
    }


    public function render(Request $request, string $path): Response
    {
        if (!str_ends_with($path, '.php')) {
            return response('Not Found', 404);
        }

        return $this->runner->run($request, 'modules/student/' . ltrim($path, '/'));
    }
}
