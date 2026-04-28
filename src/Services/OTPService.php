<?php

namespace App\Services;

use App\Traits\UsesDatabaseConnection;
use Exception;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * OTPService (Laravel Compatible)
 * 
 * Generates and manages One-Time Passwords for authentication
 * Handles OTP generation, storage, verification, and email delivery
 * 
 * Migrated to Laravel with:
 * - Proper namespacing
 * - Dependency injection support
 * - Database trait for connection management
 * - PHPMailer integration
 * 
 * @package App\Services
 */
class OTPService
{
    use UsesDatabaseConnection;

    /**
     * Initialize OTP Service
     * 
     * @param resource|null $dbConnection Database connection (optional, will use global if null)
     */
    public function __construct($dbConnection = null)
    {
        $this->setConnection($dbConnection);
    }

    /**
     * Generate and send OTP to email
     * 
     * @param string $email Recipient email address
     * @param string $purpose Purpose of OTP (e.g., 'verification', 'password_reset')
     * @param int|null $adminId Admin ID requesting the OTP
     * @return bool
     */
    public function sendOTP($email, $purpose, $adminId = null)
    {
        try {
            error_log("OTPService: sendOTP called with email=$email, purpose=$purpose, adminId=$adminId");

            $otp = $this->generateOTP();
            error_log("OTPService: Generated OTP: $otp");

            // Store OTP in database
            $this->storeOTP($adminId, $otp, $email, $purpose);
            error_log("OTPService: OTP stored in database");

            // Send email
            $emailResult = $this->sendOTPEmail($email, $otp, $purpose, $adminId);
            error_log("OTPService: Email sending result: " . ($emailResult ? 'SUCCESS' : 'FAILED'));

            return $emailResult;

        } catch (Exception $e) {
            error_log("OTPService::sendOTP error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Verify OTP
     * 
     * @param int $adminId Admin ID
     * @param string $otp OTP code to verify
     * @param string $purpose OTP purpose
     * @return bool
     */
    public function verifyOTP($adminId, $otp, $purpose)
    {
        try {
            $query = "
                SELECT * FROM admin_otp_verifications 
                WHERE admin_id = $1 AND otp = $2 AND purpose = $3 
                AND expires_at > NOW() AND used = FALSE
                ORDER BY created_at DESC LIMIT 1
            ";

            error_log("OTPService verifyOTP: adminId=$adminId, otp=$otp, purpose=$purpose");

            $result = $this->executeQuery($query, [$adminId, $otp, $purpose]);
            $rowCount = $this->getRowCount($result);

            error_log("OTPService verifyOTP: Query executed, found $rowCount matching records");

            if ($rowCount > 0) {
                // Mark OTP as used
                $otpData = $this->fetchOne($result);
                error_log("OTPService verifyOTP: Found valid OTP, marking as used");
                $this->markOTPAsUsed($otpData['id']);
                return true;
            } else {
                error_log("OTPService verifyOTP: No valid OTP found");
            }

            return false;

        } catch (Exception $e) {
            error_log("OTPService::verifyOTP error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Generate 6-digit OTP
     * 
     * @return string
     */
    private function generateOTP()
    {
        return sprintf('%06d', mt_rand(0, 999999));
    }

    /**
     * Store OTP in database
     * 
     * @param int $adminId Admin ID
     * @param string $otp OTP code
     * @param string $email Email address
     * @param string $purpose OTP purpose
     */
    private function storeOTP($adminId, $otp, $email, $purpose)
    {
        try {
            // Clean up old OTPs for this admin and purpose
            $this->executeQuery(
                "UPDATE admin_otp_verifications 
                 SET used = TRUE 
                 WHERE admin_id = $1 AND purpose = $2 AND used = FALSE",
                [$adminId, $purpose]
            );

            // Insert new OTP with 10 minute expiration
            $this->executeQuery(
                "INSERT INTO admin_otp_verifications (admin_id, otp, email, purpose, expires_at) 
                 VALUES ($1, $2, $3, $4, NOW() + INTERVAL '10 minutes')",
                [$adminId, $otp, $email, $purpose]
            );

        } catch (Exception $e) {
            error_log("OTPService::storeOTP error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Mark OTP as used
     * 
     * @param int $otpId OTP ID
     */
    private function markOTPAsUsed($otpId)
    {
        try {
            $this->executeQuery(
                "UPDATE admin_otp_verifications SET used = TRUE WHERE id = $1",
                [$otpId]
            );
        } catch (Exception $e) {
            error_log("OTPService::markOTPAsUsed error: " . $e->getMessage());
        }
    }

    /**
     * Send OTP email using PHPMailer
     * 
     * @param string $email Recipient email
     * @param string $otp OTP code
     * @param string $purpose OTP purpose
     * @param int|null $adminId Admin ID
     * @return bool
     */
    private function sendOTPEmail($email, $otp, $purpose, $adminId = null)
    {
        try {
            error_log("OTPService: sendOTPEmail called - Sending to: $email");

            // Check if PHPMailer is available
            if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
                error_log("PHPMailer not available");
                return false;
            }

            $mail = new PHPMailer(true);

            // Server settings (using environment variables for security)
            $mail->isSMTP();
            $mail->Host = getenv('MAIL_HOST') ?: 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = getenv('MAIL_USERNAME') ?: '';
            $mail->Password = getenv('MAIL_PASSWORD') ?: '';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = getenv('MAIL_PORT') ?: 587;

            // Recipients
            $fromAddress = getenv('MAIL_FROM_ADDRESS') ?: 'noreply@educaid.local';
            $fromName = getenv('MAIL_FROM_NAME') ?: 'EducAid System';
            $mail->setFrom($fromAddress, $fromName);
            $mail->addAddress($email);

            error_log("OTPService: PHPMailer configured, recipient added: $email");

            // Content
            $mail->isHTML(true);
            $mail->Subject = 'EducAid Verification Code - ' . $otp;

            // Get user's name from database
            $recipientName = 'Admin User';
            if ($adminId) {
                $nameQuery = "SELECT first_name, last_name FROM admins WHERE admin_id = $1";
                try {
                    $nameResult = $this->executeQuery($nameQuery, [$adminId]);
                    if ($this->getRowCount($nameResult) > 0) {
                        $nameRow = $this->fetchOne($nameResult);
                        $recipientName = trim($nameRow['first_name'] . ' ' . $nameRow['last_name']) ?: 'Admin User';
                    }
                } catch (Exception $e) {
                    error_log("Could not fetch admin name: " . $e->getMessage());
                }
            }

            // Build email body
            $mail->Body = $this->buildOTPEmailBody($recipientName, $otp, $purpose);
            $mail->AltBody = strip_tags($mail->Body);

            // Send
            $mail->send();
            error_log("OTPService: Email sent successfully to $email");
            return true;

        } catch (Exception $e) {
            error_log("OTPService::sendOTPEmail error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Build OTP email body HTML
     * 
     * @param string $recipientName Recipient name
     * @param string $otp OTP code
     * @param string $purpose OTP purpose
     * @return string HTML email body
     */
    private function buildOTPEmailBody($recipientName, $otp, $purpose)
    {
        $purposeLabel = match ($purpose) {
            'verification' => 'Verification',
            'password_reset' => 'Password Reset',
            'two_factor' => 'Two-Factor Authentication',
            default => 'Authentication'
        };

        return "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; background-color: #f5f5f5; }
                .container { background-color: white; padding: 20px; border-radius: 5px; margin: 20px; }
                .header { color: #333; margin-bottom: 20px; }
                .otp-box { background-color: #f0f0f0; padding: 15px; border-left: 4px solid #007bff; margin: 20px 0; }
                .otp-code { font-size: 32px; font-weight: bold; color: #007bff; letter-spacing: 2px; }
                .footer { color: #999; font-size: 12px; margin-top: 20px; border-top: 1px solid #eee; padding-top: 10px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <h2 class='header'>$purposeLabel Code</h2>
                <p>Dear $recipientName,</p>
                <p>You have requested a $purposeLabel code for your EducAid account.</p>
                <p>Your verification code is:</p>
                <div class='otp-box'>
                    <div class='otp-code'>$otp</div>
                </div>
                <p>This code will expire in 10 minutes. If you didn't request this code, please ignore this email.</p>
                <div class='footer'>
                    <p>© 2025 EducAid System. All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>";
    }

    /**
     * Check if OTP is valid and not expired
     * 
     * @param int $adminId Admin ID
     * @param string $otp OTP code
     * @param string $purpose OTP purpose
     * @return bool
     */
    public function isOTPValid($adminId, $otp, $purpose)
    {
        try {
            $query = "
                SELECT * FROM admin_otp_verifications 
                WHERE admin_id = $1 AND otp = $2 AND purpose = $3 
                AND expires_at > NOW() AND used = FALSE
            ";

            $result = $this->executeQuery($query, [$adminId, $otp, $purpose]);
            return $this->getRowCount($result) > 0;

        } catch (Exception $e) {
            error_log("OTPService::isOTPValid error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get remaining time for OTP in seconds
     * 
     * @param int $adminId Admin ID
     * @param string $purpose OTP purpose
     * @return int Remaining seconds or 0 if expired
     */
    public function getOTPRemainingTime($adminId, $purpose)
    {
        try {
            $query = "
                SELECT EXTRACT(EPOCH FROM (expires_at - NOW())) as seconds_remaining
                FROM admin_otp_verifications 
                WHERE admin_id = $1 AND purpose = $2 AND used = FALSE AND expires_at > NOW()
                ORDER BY created_at DESC LIMIT 1
            ";

            $result = $this->executeQuery($query, [$adminId, $purpose]);
            if ($this->getRowCount($result) > 0) {
                $row = $this->fetchOne($result);
                return max(0, (int)$row['seconds_remaining']);
            }

            return 0;

        } catch (Exception $e) {
            error_log("OTPService::getOTPRemainingTime error: " . $e->getMessage());
            return 0;
        }
    }
}
