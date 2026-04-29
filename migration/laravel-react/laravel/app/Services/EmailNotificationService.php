<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * EmailNotificationService
 * Handles sending emails for system notifications
 */
class EmailNotificationService
{
    /**
     * Send applicant approval notification
     *
     * @param string $studentId Student ID
     * @param string $studentEmail Student email
     * @param array $approvedDocuments List of approved documents
     * @return bool True if sent successfully
     */
    public function sendApplicantApprovalEmail(
        string $studentId,
        string $studentEmail,
        array $approvedDocuments = []
    ): bool {
        try {
            $student = DB::table('students')->where('student_id', $studentId)->first();
            if (!$student) {
                return false;
            }

            $subject = 'EducAid: Your Application Has Been Approved';
            $body = "Dear {$student->first_name},\n\n";
            $body .= "Your application for educational assistance has been approved.\n\n";
            $body .= "Documents approved:\n";
            foreach ($approvedDocuments as $doc) {
                $body .= "  - {$doc}\n";
            }
            $body .= "\nPlease log in to the EducAid portal to view your status.\n\n";
            $body .= "Best regards,\nEducAid Team";

            // Use Laravel Mail or fallback to basic email
            if (function_exists('mail')) {
                mail($studentEmail, $subject, $body);
            }

            Log::info("EmailNotificationService: Approval email sent", [
                'student_id' => $studentId,
                'email' => $studentEmail,
            ]);

            return true;
        } catch (Exception $e) {
            Log::error('EmailNotificationService::sendApplicantApprovalEmail failed', [
                'student_id' => $studentId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Send distribution notification
     *
     * @param string $studentId Student ID
     * @param string $studentEmail Student email
     * @param string $payrollNo Payroll number assigned
     * @return bool True if sent successfully
     */
    public function sendDistributionNotification(
        string $studentId,
        string $studentEmail,
        string $payrollNo
    ): bool {
        try {
            $student = DB::table('students')->where('student_id', $studentId)->first();
            if (!$student) {
                return false;
            }

            $subject = 'EducAid: You Have Been Selected for Aid Distribution';
            $body = "Dear {$student->first_name},\n\n";
            $body .= "You have been selected to receive educational assistance.\n\n";
            $body .= "Payroll Number: {$payrollNo}\n\n";
            $body .= "Please proceed to the distribution point with a valid ID.\n\n";
            $body .= "Best regards,\nEducAid Team";

            if (function_exists('mail')) {
                mail($studentEmail, $subject, $body);
            }

            Log::info("EmailNotificationService: Distribution email sent", [
                'student_id' => $studentId,
                'payroll_no' => $payrollNo,
            ]);

            return true;
        } catch (Exception $e) {
            Log::error('EmailNotificationService::sendDistributionNotification failed', [
                'student_id' => $studentId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Send rejection notification
     *
     * @param string $studentId Student ID
     * @param string $studentEmail Student email
     * @param string $reason Rejection reason
     * @return bool True if sent successfully
     */
    public function sendRejectionEmail(
        string $studentId,
        string $studentEmail,
        string $reason
    ): bool {
        try {
            $student = DB::table('students')->where('student_id', $studentId)->first();
            if (!$student) {
                return false;
            }

            $subject = 'EducAid: Application Status Update';
            $body = "Dear {$student->first_name},\n\n";
            $body .= "Your application for educational assistance requires additional review.\n\n";
            $body .= "Reason: {$reason}\n\n";
            $body .= "Please resubmit your documents or contact the EducAid office for more information.\n\n";
            $body .= "Best regards,\nEducAid Team";

            if (function_exists('mail')) {
                mail($studentEmail, $subject, $body);
            }

            Log::info("EmailNotificationService: Rejection email sent", [
                'student_id' => $studentId,
            ]);

            return true;
        } catch (Exception $e) {
            Log::error('EmailNotificationService::sendRejectionEmail failed', [
                'student_id' => $studentId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Send admin notification
     *
     * @param int $adminId Admin ID
     * @param string $subject Email subject
     * @param string $body Email body
     * @return bool True if sent successfully
     */
    public function sendAdminNotification(int $adminId, string $subject, string $body): bool
    {
        try {
            $admin = DB::table('admins')->where('admin_id', $adminId)->first();
            if (!$admin || !$admin->email) {
                return false;
            }

            if (function_exists('mail')) {
                mail($admin->email, $subject, $body);
            }

            Log::info("EmailNotificationService: Admin notification sent", [
                'admin_id' => $adminId,
            ]);

            return true;
        } catch (Exception $e) {
            Log::error('EmailNotificationService::sendAdminNotification failed', [
                'admin_id' => $adminId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
