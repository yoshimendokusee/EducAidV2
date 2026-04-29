<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * BlacklistService
 * Handles permanent blacklisting of students for fraud, policy violations, etc.
 */
class BlacklistService
{
    public const REASON_FRAUDULENT = 'fraudulent_activity';
    public const REASON_MISCONDUCT = 'academic_misconduct';
    public const REASON_ABUSE = 'system_abuse';
    public const REASON_DUPLICATE = 'duplicate_account';
    public const REASON_OTHER = 'other';

    /**
     * Blacklist a student permanently
     *
     * @param string $studentId Student to blacklist
     * @param string $reasonCategory Category of blacklist reason
     * @param string $detailedReason Detailed explanation
     * @param int $adminId Admin performing the action
     * @param string|null $adminNotes Optional admin notes
     * @return array ['success' => bool, 'message' => string]
     */
    public function blacklistStudent(
        string $studentId,
        string $reasonCategory,
        string $detailedReason,
        int $adminId,
        ?string $adminNotes = null
    ): array {
        try {
            DB::beginTransaction();

            // Get student details
            $student = DB::table('students')->where('student_id', $studentId)->first();
            if (!$student) {
                return ['success' => false, 'message' => 'Student not found'];
            }

            // Check if already blacklisted
            if ($student->status === 'blacklisted') {
                return ['success' => false, 'message' => 'Student is already blacklisted'];
            }

            // Update student status
            $archiveReason = substr("Blacklisted: {$reasonCategory} - {$detailedReason}", 0, 500);

            DB::table('students')
                ->where('student_id', $studentId)
                ->update([
                    'status' => 'blacklisted',
                    'is_archived' => true,
                    'archived_at' => now(),
                    'archived_by' => $adminId,
                    'archive_reason' => $archiveReason,
                    'archival_type' => 'blacklisted',
                ]);

            // Insert blacklist record
            DB::table('blacklisted_students')->insert([
                'student_id' => $studentId,
                'reason_category' => $reasonCategory,
                'detailed_reason' => $detailedReason,
                'admin_notes' => $adminNotes,
                'blacklisted_by' => $adminId,
                'blacklisted_at' => now(),
                'permanent' => true,
            ]);

            // Log audit trail
            DB::table('audit_logs')->insert([
                'admin_id' => $adminId,
                'action' => 'student_blacklisted',
                'table_name' => 'students',
                'record_id' => $studentId,
                'details' => json_encode([
                    'reason' => $reasonCategory,
                    'detail' => $detailedReason,
                ]),
                'created_at' => now(),
            ]);

            DB::commit();

            Log::warning("BlacklistService: Student blacklisted", [
                'student_id' => $studentId,
                'reason' => $reasonCategory,
                'admin_id' => $adminId,
            ]);

            return ['success' => true, 'message' => 'Student blacklisted successfully'];
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('BlacklistService::blacklistStudent failed', [
                'student_id' => $studentId,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Check if a student is blacklisted
     *
     * @param string $studentId Student ID
     * @return bool True if blacklisted
     */
    public function isBlacklisted(string $studentId): bool
    {
        try {
            $student = DB::table('students')->where('student_id', $studentId)->first();
            return $student && $student->status === 'blacklisted';
        } catch (Exception $e) {
            Log::error('BlacklistService::isBlacklisted failed', [
                'student_id' => $studentId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get blacklist record for a student
     *
     * @param string $studentId Student ID
     * @return array|null Blacklist record or null
     */
    public function getBlacklistRecord(string $studentId): ?array
    {
        try {
            $record = DB::table('blacklisted_students')
                ->where('student_id', $studentId)
                ->first();

            return $record ? (array)$record : null;
        } catch (Exception $e) {
            Log::error('BlacklistService::getBlacklistRecord failed', [
                'student_id' => $studentId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get all blacklisted students
     *
     * @param int $limit Number of records to return
     * @return array Array of blacklisted students
     */
    public function getBlacklistedStudents(int $limit = 100): array
    {
        try {
            return DB::table('blacklisted_students')
                ->join('students', 'blacklisted_students.student_id', '=', 'students.student_id')
                ->select(
                    'blacklisted_students.*',
                    'students.first_name',
                    'students.last_name',
                    'students.email'
                )
                ->orderBy('blacklisted_students.blacklisted_at', 'desc')
                ->limit($limit)
                ->get()
                ->toArray();
        } catch (Exception $e) {
            Log::error('BlacklistService::getBlacklistedStudents failed', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Get blacklist statistics
     *
     * @return array Statistics by reason
     */
    public function getStatistics(): array
    {
        try {
            return DB::table('blacklisted_students')
                ->selectRaw('reason_category, COUNT(*) as count')
                ->groupBy('reason_category')
                ->get()
                ->keyBy('reason_category')
                ->map(fn($row) => $row->count)
                ->toArray();
        } catch (Exception $e) {
            Log::error('BlacklistService::getStatistics failed', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }
}
