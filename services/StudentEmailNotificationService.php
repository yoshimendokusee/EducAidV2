<?php
/**
 * StudentEmailNotificationService
 * 
 * Sends immediate and digest emails to students for notifications, using PHPMailer.
 */

class StudentEmailNotificationService {
    private $connection;

    public function __construct($connection) {
        $this->connection = $connection;
    }

    /**
     * Send a single notification email immediately based on a created notification.
     * @return bool
     */
    public function sendImmediateEmail($studentId, $title, $message, $type = 'info', $actionUrl = null) {
        $student = $this->getStudentContact($studentId);
        if (!$student || empty($student['email'])) return false;

        try {
            require_once __DIR__ . '/../phpmailer/vendor/autoload.php';
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);

            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            // NOTE: Replace with environment variables or config in production
            $mail->Username   = 'dilucayaka02@gmail.com';
            $mail->Password   = 'jlld eygl hksj flvg';
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;

            $mail->setFrom('noreply@educaid.gov.ph', 'EducAid');
            $mail->addAddress($student['email'], $student['full_name']);

            $mail->isHTML(true);
            $mail->Subject = $this->formatSubject($type, $title);
            $mail->Body    = $this->formatHtmlBody($title, $message, $type, $actionUrl);
            $mail->AltBody = $this->formatTextBody($title, $message, $type, $actionUrl);

            $mail->send();
            return true;
        } catch (\Exception $e) {
            error_log('Student immediate email failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Send a daily digest email with a list of notifications.
     * @param array $items Array of [title, message, type, action_url, created_at]
     * @return bool
     */
    public function sendDigestEmail($studentId, array $items) {
        if (empty($items)) return false;
        $student = $this->getStudentContact($studentId);
        if (!$student || empty($student['email'])) return false;

        try {
            require_once __DIR__ . '/../phpmailer/vendor/autoload.php';
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);

            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            // NOTE: Replace with environment variables or config in production
            $mail->Username   = 'dilucayaka02@gmail.com';
            $mail->Password   = 'jlld eygl hksj flvg';
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;

            $mail->setFrom('noreply@educaid.gov.ph', 'EducAid');
            $mail->addAddress($student['email'], $student['full_name']);

            $mail->isHTML(true);
            $mail->Subject = 'Your EducAid Daily Notifications Digest';

            $mail->Body = $this->formatDigestHtmlBody($items);
            $mail->AltBody = $this->formatDigestTextBody($items);

            $mail->send();
            return true;
        } catch (\Exception $e) {
            error_log('Student digest email failed: ' . $e->getMessage());
            return false;
        }
    }

    private function getStudentContact($studentId) {
        $res = @pg_query_params($this->connection, "SELECT email, first_name, last_name FROM students WHERE student_id = $1", [$studentId]);
        if (!$res) return null;
        $row = pg_fetch_assoc($res);
        if (!$row) return null;
        $fullName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        return [ 'email' => $row['email'], 'full_name' => $fullName ?: $studentId ];
    }

    private function formatSubject($type, $title) {
        $prefix = '';
        switch ($type) {
            case 'warning': $prefix = '[Warning] '; break;
            case 'error':   $prefix = '[Alert] '; break;
            case 'success': $prefix = '[Update] '; break;
            case 'announcement': $prefix = '[Announcement] '; break;
            case 'schedule': $prefix = '[Schedule] '; break;
            case 'document': $prefix = '[Documents] '; break;
            case 'system': $prefix = '[System] '; break;
            default: $prefix = '[EducAid] ';
        }
        return $prefix . $title;
    }

    private function formatHtmlBody($title, $message, $type, $actionUrl) {
        $safeTitle = htmlspecialchars($title, ENT_QUOTES);
        $safeMsg = nl2br(htmlspecialchars($message, ENT_QUOTES));
        $cta = '';
        if ($actionUrl) {
            require_once __DIR__ . '/../includes/env_url_helper.php';
            // Build environment-aware absolute URL - always point to login
            $loginUrl = buildAbsoluteUrl('unified_login.php');
            $cta = '<p><a href="' . htmlspecialchars($loginUrl, ENT_QUOTES) . '" style="display:inline-block;padding:10px 16px;background:#2563eb;color:#fff;text-decoration:none;border-radius:6px;font-weight:600;">Login Now</a></p>';    
        }
        return "<div style='font-family:Arial,sans-serif;color:#111;line-height:1.6'>
            <h2 style='margin:0 0 8px'>{$safeTitle}</h2>
            <div style='font-size:14px'>{$safeMsg}</div>
            {$cta}
            <hr style='margin:16px 0;border:none;border-top:1px solid #eee' />
            <div style='font-size:12px;color:#666'>This is an automated message from EducAid. Please do not reply.</div>
        </div>";
    }

    private function formatTextBody($title, $message, $type, $actionUrl) {
        $lines = [];
        $lines[] = $title;
        $lines[] = str_repeat('-', max(10, strlen($title)));
        $lines[] = $message;
        if ($actionUrl) $lines[] = "\nOpen: $actionUrl";
        $lines[] = "\n--\nThis is an automated message from EducAid.";
        return implode("\n", $lines);
    }

    private function formatDigestHtmlBody(array $items) {
        $htmlItems = '';
        foreach ($items as $it) {
            $t = htmlspecialchars($it['title'] ?? 'Notification', ENT_QUOTES);
            $m = nl2br(htmlspecialchars($it['message'] ?? '', ENT_QUOTES));
            $created = isset($it['created_at']) ? date('M j, Y g:i A', strtotime($it['created_at'])) : '';
            require_once __DIR__ . '/../includes/env_url_helper.php';
            $loginUrl = buildAbsoluteUrl('unified_login.php');
            $btn = !empty($it['action_url']) ? ("<p style='margin:8px 0'><a href='" . htmlspecialchars($loginUrl, ENT_QUOTES) . "' style='display:inline-block;padding:8px 12px;background:#2563eb;color:#fff;text-decoration:none;border-radius:6px;'>Login Now</a></p>") : '';
            $htmlItems .= "<div style='padding:12px;border:1px solid #eee;border-radius:8px;margin:10px 0'>
                <div style='font-weight:600;margin-bottom:4px'>{$t}</div>
                <div style='font-size:14px;color:#333'>{$m}</div>
                <div style='font-size:12px;color:#666;margin-top:6px'>{$created}</div>
                {$btn}
            </div>";
        }
        return "<div style='font-family:Arial,sans-serif;color:#111;line-height:1.6'>
            <h2 style='margin:0 0 8px'>Your EducAid Daily Digest</h2>
            <p style='font-size:14px;color:#333'>Here are your recent notifications:</p>
            {$htmlItems}
            <hr style='margin:16px 0;border:none;border-top:1px solid #eee' />
            <div style='font-size:12px;color:#666'>You can manage your email notification preferences in Settings → Notification Preferences.</div>
        </div>";
    }

    private function formatDigestTextBody(array $items) {
        $lines = [
            'Your EducAid Daily Digest',
            '--------------------------------',
            'Recent notifications:'
        ];
        foreach ($items as $it) {
            $lines[] = '';
            $lines[] = ($it['title'] ?? 'Notification');
            $lines[] = str_repeat('-', max(10, strlen($it['title'] ?? 'Notification')));
            $lines[] = trim($it['message'] ?? '');
            if (!empty($it['action_url'])) $lines[] = 'Open: ' . $it['action_url'];
            if (!empty($it['created_at'])) $lines[] = 'Date: ' . date('M j, Y g:i A', strtotime($it['created_at']));
        }
        $lines[] = "\nManage your email preferences in Settings → Notification Preferences.";
        return implode("\n", $lines);
    }
}
