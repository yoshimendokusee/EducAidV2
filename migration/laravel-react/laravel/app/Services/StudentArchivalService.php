<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * StudentArchivalService
 * Handles student archival and status lifecycle management
 */
class StudentArchivalService
{
    /**
     * Archive a student
     *
     * @param string $studentId Student ID
     * @param string $reason Archival reason
     * @param int $archivedBy Admin ID
     * @param string $type Type of archival (graduated, dropped, rejected, etc.)
     * @return array ['success' => bool, 'message' => string]
     */
    public function archiveStudent(
        string $studentId,
        string $reason,
        int $archivedBy,
        string $type = 'graduated'
    ): array {
        try {
            DB::beginTransaction();

            // Check if student exists
            $student = DB::table('students')->where('student_id', $studentId)->first();
            if (!$student) {
                return ['success' => false, 'message' => 'Student not found'];
            }

            // Update student record
            DB::table('students')
                ->where('student_id', $studentId)
                ->update([
                    'is_archived' => true,
                    'archived_at' => now(),
                    'archived_by' => $archivedBy,
                    'archive_reason' => $reason,
                    'archival_type' => $type,
                ]);

            // Log audit trail
            DB::table('audit_logs')->insert([
                'admin_id' => $archivedBy,
                'action' => 'student_archived',
                'table_name' => 'students',
                'record_id' => $studentId,
                'details' => json_encode([
                    'reason' => $reason,
                    'type' => $type,
                ]),
                'created_at' => now(),
            ]);

            DB::commit();

            Log::info("StudentArchivalService: Student archived", [
                'student_id' => $studentId,
                'type' => $type,
                'reason' => $reason,
            ]);

            return ['success' => true, 'message' => "Student archived successfully"];
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('StudentArchivalService::archiveStudent failed', [
                'student_id' => $studentId,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Unarchive a student
     *
     * @param string $studentId Student ID
     * @param int $unarchivedBy Admin ID
     * @return array ['success' => bool, 'message' => string]
     */
    public function unarchiveStudent(string $studentId, int $unarchivedBy): array
    {
        try {
            DB::beginTransaction();

            // Check if student exists and is archived
            $student = DB::table('students')->where('student_id', $studentId)->first();
            if (!$student) {
                return ['success' => false, 'message' => 'Student not found'];
            }

            if (!$student->is_archived) {
                return ['success' => false, 'message' => 'Student is not archived'];
            }

            // Cannot unarchive blacklisted students
            if ($student->status === 'blacklisted') {
                return ['success' => false, 'message' => 'Cannot unarchive blacklisted students'];
            }

            // Update student record
            DB::table('students')
                ->where('student_id', $studentId)
                ->update([
                    'is_archived' => false,
                    'archived_at' => null,
                    'archived_by' => null,
                    'archive_reason' => null,
                    'archival_type' => null,
                ]);

            // Log audit trail
            DB::table('audit_logs')->insert([
                'admin_id' => $unarchivedBy,
                'action' => 'student_unarchived',
                'table_name' => 'students',
                'record_id' => $studentId,
                'details' => json_encode(['reason' => 'Unarchived by admin']),
                'created_at' => now(),
            ]);

            DB::commit();

            Log::info("StudentArchivalService: Student unarchived", [
                'student_id' => $studentId,
            ]);

            return ['success' => true, 'message' => 'Student unarchived successfully'];
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('StudentArchivalService::unarchiveStudent failed', [
                'student_id' => $studentId,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get archived students for reporting
     *
     * @param string|null $type Filter by archival type (graduated, dropped, rejected, blacklisted, etc.)
     * @param int $limit Limit results
     * @return array Array of archived student records
     */
    public function getArchivedStudents(?string $type = null, int $limit = 100): array
    {
        try {
            $query = DB::table('students')
                ->where('is_archived', true)
                ->orderBy('archived_at', 'desc')
                ->limit($limit);

            if ($type) {
                $query->where('archival_type', $type);
            }

            return $query->get()->toArray();
        } catch (Exception $e) {
            Log::error('StudentArchivalService::getArchivedStudents failed', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Get archival statistics
     *
     * @return array Statistics by archival type
     */
    public function getStatistics(): array
    {
        try {
            return DB::table('students')
                ->where('is_archived', true)
                ->selectRaw('archival_type, COUNT(*) as count')
                ->groupBy('archival_type')
                ->get()
                ->keyBy('archival_type')
                ->map(fn($row) => $row->count)
                ->toArray();
        } catch (Exception $e) {
            Log::error('StudentArchivalService::getStatistics failed', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }
}
