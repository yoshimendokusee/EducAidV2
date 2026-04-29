<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Exception;

class StudentEmailNotificationService
{
    private string $fromEmail;
    private string $fromName;

    public function __construct()
    {
        $this->fromEmail = config('mail.from.address', 'noreply@educaid.gov.ph');
        $this->fromName = config('mail.from.name', 'EducAid General Trias');
    }

    /**
     * Send immediate notification email to student
     *
     * @param string $studentId
     * @param string $title
     * @param string $message
     * @param string $type
     * @param string|null $actionUrl
     * @return bool
     */
    public function sendImmediateEmail(
        string $studentId,
        string $title,
        string $message,
        string $type = 'info',
        ?string $actionUrl = null
    ): bool {
        try {
            $student = DB::table('students')
                ->where('student_id', $studentId)
                ->first();

            if (!$student || empty($student->email)) {
                return false;
            }

            $studentName = $student->first_name . ' ' . ($student->middle_name ? $student->middle_name . ' ' : '') . $student->last_name;

            $subject = $this->formatSubject($type, $title);
            $htmlBody = $this->formatHtmlBody($title, $message, $type, $actionUrl);
            $textBody = $this->formatTextBody($title, $message, $type, $actionUrl);

            Mail::send([], [], function ($message) use ($student, $studentName, $subject, $htmlBody, $textBody) {
                $message->to($student->email, $studentName)
                    ->subject($subject)
                    ->from($this->fromEmail, $this->fromName)
                    ->setBody($htmlBody, 'text/html')
                    ->addPart($textBody, 'text/plain');
            });

            Log::info("StudentEmailNotificationService::sendImmediateEmail - Sent to {$student->email}");

            return true;
        } catch (Exception $e) {
            Log::error("StudentEmailNotificationService::sendImmediateEmail - Error: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Send application approved email
     *
     * @param string $studentId
     * @return bool
     */
    public function sendApprovalEmail(string $studentId): bool
    {
        return $this->sendImmediateEmail(
            $studentId,
            'Application Approved',
            'Congratulations! Your application for educational assistance has been approved. You can now claim your aid during the next distribution cycle.',
            'success',
            route('student.dashboard')
        );
    }

    /**
     * Send application rejected email
     *
     * @param string $studentId
     * @param string|null $reason
     * @return bool
     */
    public function sendRejectionEmail(string $studentId, ?string $reason = null): bool
    {
        $message = "Unfortunately, your application for educational assistance was not approved.";

        if ($reason) {
            $message .= "\n\nReason: " . $reason;
            $message .= "\n\nYou may resubmit your application with the required documents.";
        }

        return $this->sendImmediateEmail(
            $studentId,
            'Application Status Update',
            $message,
            'warning',
            route('student.reupload')
        );
    }

    /**
     * Send distribution notification email
     *
     * @param string $studentId
     * @param string $distributionName
     * @param float|null $amount
     * @return bool
     */
    public function sendDistributionNotificationEmail(
        string $studentId,
        string $distributionName,
        ?float $amount = null
    ): bool {
        $message = "You have been selected to receive educational assistance in the $distributionName distribution cycle.";

        if ($amount) {
            $message .= "\n\nAssistance Amount: ₱" . number_format($amount, 2);
        }

        $message .= "\n\nPlease visit your account to claim your aid.";

        return $this->sendImmediateEmail(
            $studentId,
            'Distribution Notification',
            $message,
            'info',
            route('student.distribution')
        );
    }

    /**
     * Send document processing update
     *
     * @param string $studentId
     * @param string $documentType
     * @param string $status
     * @return bool
     */
    public function sendDocumentProcessingUpdate(
        string $studentId,
        string $documentType,
        string $status
    ): bool {
        $messages = [
            'submitted' => "Your $documentType has been received and is being processed.",
            'verified' => "Your $documentType has been verified successfully.",
            'rejected' => "Your $documentType was not accepted. Please resubmit with correct information.",
            'processed' => "Your $documentType has been processed successfully."
        ];

        $types = [
            'submitted' => 'info',
            'verified' => 'success',
            'rejected' => 'warning',
            'processed' => 'success'
        ];

        $message = $messages[$status] ?? "Your $documentType status has been updated.";
        $type = $types[$status] ?? 'info';

        return $this->sendImmediateEmail(
            $studentId,
            'Document Processing Update',
            $message,
            $type
        );
    }

    /**
     * Format email subject based on type
     *
     * @param string $type
     * @param string $title
     * @return string
     */
    private function formatSubject(string $type, string $title): string
    {
        $prefixes = [
            'success' => '[✓] ',
            'warning' => '[!] ',
            'error' => '[✗] ',
            'info' => '[i] '
        ];

        $prefix = $prefixes[$type] ?? '';
        return $prefix . $title . ' - EducAid';
    }

    /**
     * Format HTML email body
     *
     * @param string $title
     * @param string $message
     * @param string $type
     * @param string|null $actionUrl
     * @return string
     */
    private function formatHtmlBody(string $title, string $message, string $type, ?string $actionUrl = null): string
    {
        $colorMap = [
            'success' => '#28a745',
            'warning' => '#ffc107',
            'error' => '#dc3545',
            'info' => '#17a2b8'
        ];

        $color = $colorMap[$type] ?? '#17a2b8';

        $html = "<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: $color; color: white; padding: 20px; text-align: center; border-radius: 5px 5px 0 0; }
        .header h2 { margin: 0; }
        .content { background-color: #f9f9f9; padding: 20px; border: 1px solid #ddd; }
        .content p { line-height: 1.6; }
        .footer { background-color: #f0f0f0; padding: 10px; text-align: center; font-size: 12px; border-radius: 0 0 5px 5px; }
        .button { display: inline-block; background-color: $color; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; margin-top: 10px; }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h2>$title</h2>
        </div>
        <div class='content'>
            <p>" . nl2br(htmlspecialchars($message)) . "</p>
            " . ($actionUrl ? "<p><a href='$actionUrl' class='button'>Take Action</a></p>" : "") . "
        </div>
        <div class='footer'>
            <p>© 2024 EducAid General Trias. All rights reserved.</p>
        </div>
    </div>
</body>
</html>";

        return $html;
    }

    /**
     * Format plain text email body
     *
     * @param string $title
     * @param string $message
     * @param string $type
     * @param string|null $actionUrl
     * @return string
     */
    private function formatTextBody(string $title, string $message, string $type, ?string $actionUrl = null): string
    {
        $text = "=== $title ===\n\n";
        $text .= $message . "\n";

        if ($actionUrl) {
            $text .= "\n" . $actionUrl . "\n";
        }

        $text .= "\n---\n";
        $text .= "© 2024 EducAid General Trias\n";

        return $text;
    }
}
