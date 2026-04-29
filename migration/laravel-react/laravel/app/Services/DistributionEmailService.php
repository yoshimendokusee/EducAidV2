<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Exception;

class DistributionEmailService
{
    private string $fromEmail;
    private string $fromName;

    public function __construct()
    {
        $this->fromEmail = config('mail.from.address', 'noreply@educaid.gov.ph');
        $this->fromName = config('mail.from.name', 'EducAid General Trias');
    }

    /**
     * Notify all applicant students when distribution opens
     *
     * @param int $distributionId
     * @param string $deadline
     * @return array ['success' => bool, 'sent_count' => int, 'failed_count' => int]
     */
    public function notifyDistributionOpened(int $distributionId, string $deadline): array
    {
        try {
            Log::info("DistributionEmailService::notifyDistributionOpened - START for distribution: $distributionId");

            // Get distribution details
            $distribution = DB::table('distributions')
                ->where('distribution_id', $distributionId)
                ->first();

            if (!$distribution) {
                return [
                    'success' => false,
                    'sent_count' => 0,
                    'failed_count' => 0,
                    'message' => 'Distribution not found'
                ];
            }

            // Get all applicant students with valid emails
            $students = DB::table('students')
                ->where('status', 'applicant')
                ->whereNotNull('email')
                ->where('email', '!=', '')
                ->get();

            $sentCount = 0;
            $failedCount = 0;

            foreach ($students as $student) {
                try {
                    $this->sendDistributionOpenedEmail(
                        $student->email,
                        $student->first_name . ' ' . $student->last_name,
                        $deadline
                    );
                    $sentCount++;
                } catch (Exception $e) {
                    Log::error("DistributionEmailService - Failed to send email to {$student->email}: {$e->getMessage()}");
                    $failedCount++;
                }
            }

            Log::info("DistributionEmailService::notifyDistributionOpened - COMPLETED: sent=$sentCount, failed=$failedCount");

            return [
                'success' => true,
                'sent_count' => $sentCount,
                'failed_count' => $failedCount
            ];
        } catch (Exception $e) {
            Log::error("DistributionEmailService::notifyDistributionOpened - Error: {$e->getMessage()}");

            return [
                'success' => false,
                'sent_count' => 0,
                'failed_count' => 0,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Notify students that distribution has closed
     *
     * @param int $distributionId
     * @return array ['success' => bool, 'sent_count' => int, 'failed_count' => int]
     */
    public function notifyDistributionClosed(int $distributionId): array
    {
        try {
            Log::info("DistributionEmailService::notifyDistributionClosed - START for distribution: $distributionId");

            // Get students who received distribution in this cycle
            $snapshot = DB::table('distribution_snapshots')
                ->where('distribution_id', $distributionId)
                ->first();

            if (!$snapshot) {
                return [
                    'success' => false,
                    'sent_count' => 0,
                    'failed_count' => 0,
                    'message' => 'Distribution snapshot not found'
                ];
            }

            $students = DB::table('students')
                ->whereIn('student_id', function ($query) use ($snapshot) {
                    $query->select('student_id')
                        ->from('distribution_student_records')
                        ->where('snapshot_id', $snapshot->snapshot_id);
                })
                ->whereNotNull('email')
                ->where('email', '!=', '')
                ->get();

            $sentCount = 0;
            $failedCount = 0;

            foreach ($students as $student) {
                try {
                    $this->sendDistributionClosedEmail(
                        $student->email,
                        $student->first_name . ' ' . $student->last_name
                    );
                    $sentCount++;
                } catch (Exception $e) {
                    Log::error("DistributionEmailService - Failed to send closed email to {$student->email}: {$e->getMessage()}");
                    $failedCount++;
                }
            }

            Log::info("DistributionEmailService::notifyDistributionClosed - COMPLETED: sent=$sentCount, failed=$failedCount");

            return [
                'success' => true,
                'sent_count' => $sentCount,
                'failed_count' => $failedCount
            ];
        } catch (Exception $e) {
            Log::error("DistributionEmailService::notifyDistributionClosed - Error: {$e->getMessage()}");

            return [
                'success' => false,
                'sent_count' => 0,
                'failed_count' => 0,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Send distribution opened email
     *
     * @param string $email
     * @param string $studentName
     * @param string $deadline
     * @return bool
     */
    private function sendDistributionOpenedEmail(string $email, string $studentName, string $deadline): bool
    {
        $subject = 'Distribution of Educational Assistance Has Opened';
        $body = "Dear $studentName,\n\n"
            . "Good news! The latest distribution of educational assistance is now open for all qualified applicants.\n\n"
            . "Distribution Deadline: $deadline\n\n"
            . "Please log in to your account to claim your educational assistance.\n\n"
            . "If you have any questions, please contact the EducAid office.\n\n"
            . "Best regards,\n"
            . "EducAid General Trias";

        try {
            Mail::raw($body, function ($message) use ($email, $studentName, $subject) {
                $message->to($email, $studentName)
                    ->subject($subject)
                    ->from($this->fromEmail, $this->fromName);
            });

            return true;
        } catch (Exception $e) {
            Log::error("DistributionEmailService::sendDistributionOpenedEmail - Error: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * Send distribution closed email
     *
     * @param string $email
     * @param string $studentName
     * @return bool
     */
    private function sendDistributionClosedEmail(string $email, string $studentName): bool
    {
        $subject = 'Distribution of Educational Assistance Has Closed';
        $body = "Dear $studentName,\n\n"
            . "The current distribution of educational assistance has been closed.\n\n"
            . "If you did not claim your assistance during the distribution period, please contact the EducAid office for more information.\n\n"
            . "Best regards,\n"
            . "EducAid General Trias";

        try {
            Mail::raw($body, function ($message) use ($email, $studentName, $subject) {
                $message->to($email, $studentName)
                    ->subject($subject)
                    ->from($this->fromEmail, $this->fromName);
            });

            return true;
        } catch (Exception $e) {
            Log::error("DistributionEmailService::sendDistributionClosedEmail - Error: {$e->getMessage()}");
            throw $e;
        }
    }
}
