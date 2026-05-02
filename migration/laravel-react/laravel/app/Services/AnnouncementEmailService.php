<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * AnnouncementEmailService
 * Handles bulk email announcements from admin
 */
class AnnouncementEmailService
{
    private StudentEmailNotificationService $emailService;

    public function __construct(StudentEmailNotificationService $emailService)
    {
        $this->emailService = $emailService;
    }

    /**
     * Send announcement email to students based on criteria
     *
     * @param string $title
     * @param string $message
     * @param string $recipientType One of: all_students, eligible_only, by_status, by_municipality
     * @param string|null $recipientStatus Student status (applicant, approved, rejected, etc.)
     * @param int|null $municipalityId Municipality ID for filtering
     * @param string $adminUsername Admin who sent the announcement
     * @return array ['success' => bool, 'sent_count' => int, 'failed_count' => int]
     */
    public function sendAnnouncement(
        string $title,
        string $message,
        string $recipientType,
        ?string $recipientStatus = null,
        ?int $municipalityId = null,
        ?string $adminUsername = null
    ): array {
        try {
            Log::info("AnnouncementEmailService::sendAnnouncement - START", [
                'title' => $title,
                'recipient_type' => $recipientType,
                'admin' => $adminUsername
            ]);

            // Get recipient students based on type
            $students = $this->getRecipientStudents($recipientType, $recipientStatus, $municipalityId);

            if ($students->isEmpty()) {
                return [
                    'success' => true,
                    'sent_count' => 0,
                    'failed_count' => 0,
                    'message' => 'No recipients matched the criteria'
                ];
            }

            $sentCount = 0;
            $failedCount = 0;

            foreach ($students as $student) {
                try {
                    // Send email via StudentEmailNotificationService
                    $success = $this->emailService->sendImmediateEmail(
                        $student->student_id,
                        $title,
                        $message,
                        'info'
                    );

                    if ($success) {
                        $sentCount++;
                    } else {
                        $failedCount++;
                    }
                } catch (Exception $e) {
                    Log::error("AnnouncementEmailService - Failed to send announcement to {$student->email}", [
                        'student_id' => $student->student_id,
                        'error' => $e->getMessage()
                    ]);
                    $failedCount++;
                }
            }

            // Log announcement to audit table if it exists
            $this->logAnnouncement($title, $recipientType, $sentCount, $failedCount, $adminUsername);

            Log::info("AnnouncementEmailService::sendAnnouncement - COMPLETED", [
                'sent_count' => $sentCount,
                'failed_count' => $failedCount,
                'total' => $students->count()
            ]);

            return [
                'success' => true,
                'sent_count' => $sentCount,
                'failed_count' => $failedCount,
                'total_attempted' => $students->count()
            ];
        } catch (Exception $e) {
            Log::error("AnnouncementEmailService::sendAnnouncement - Error", [
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'sent_count' => 0,
                'failed_count' => 0,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Get list of students to receive announcement based on recipient type
     *
     * @param string $recipientType
     * @param string|null $status
     * @param int|null $municipalityId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    private function getRecipientStudents(string $recipientType, ?string $status = null, ?int $municipalityId = null)
    {
        $query = DB::table('students')
            ->whereNotNull('email')
            ->where('email', '!=', '');

        switch ($recipientType) {
            case 'all_students':
                // All students with valid email
                break;

            case 'eligible_only':
                // Only approved students eligible for distribution
                $query->where('status', 'approved');
                break;

            case 'by_status':
                // Filter by specific status
                if ($status) {
                    $query->where('status', $status);
                }
                break;

            case 'by_municipality':
                // Filter by municipality
                if ($municipalityId) {
                    $query->where('municipality_id', $municipalityId);
                }
                break;
        }

        return $query->select('student_id', 'email', 'first_name', 'last_name')->get();
    }

    /**
     * Log announcement to audit trail if table exists
     *
     * @param string $title
     * @param string $recipientType
     * @param int $sentCount
     * @param int $failedCount
     * @param string|null $adminUsername
     * @return void
     */
    private function logAnnouncement(
        string $title,
        string $recipientType,
        int $sentCount,
        int $failedCount,
        ?string $adminUsername = null
    ): void {
        try {
            // Check if audit table exists
            $tableExists = DB::getSchemaBuilder()->hasTable('announcement_audit');

            if ($tableExists) {
                DB::table('announcement_audit')->insert([
                    'title' => $title,
                    'recipient_type' => $recipientType,
                    'sent_count' => $sentCount,
                    'failed_count' => $failedCount,
                    'admin_username' => $adminUsername,
                    'sent_at' => now(),
                    'created_at' => now()
                ]);
            }
        } catch (Exception $e) {
            Log::warning("AnnouncementEmailService - Could not log announcement", [
                'error' => $e->getMessage()
            ]);
        }
    }
}
