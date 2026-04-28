<?php
/**
 * BlacklistService
 * 
 * Handles permanent blacklisting of students for fraud, policy violations, etc.
 * This is SEPARATE from archival - blacklisted students cannot be unarchived
 * 
 * Features:
 * - Two-step verification (password + OTP)
 * - Email notification to admin
 * - Files compressed to blacklisted_students/ folder (not archived_students/)
 * - Inserts into blacklisted_students table
 * - Prevents login and registration permanently
 */

require_once __DIR__ . '/../bootstrap_services.php';

class BlacklistService {
    private $connection;
    private $fileService;
    private $auditLogger;
    
    // Blacklist reason categories
    const REASON_FRAUDULENT_ACTIVITY = 'fraudulent_activity';
    const REASON_ACADEMIC_MISCONDUCT = 'academic_misconduct';
    const REASON_SYSTEM_ABUSE = 'system_abuse';
    const REASON_DUPLICATE_ACCOUNT = 'duplicate_account';
    const REASON_OTHER = 'other';
    
    public function __construct($dbConnection = null) {
        global $connection;
        $this->connection = $dbConnection ?? $connection;
        $this->fileService = new UnifiedFileService($this->connection);
        $this->auditLogger = new AuditLogger($this->connection);
    }
    
    /**
     * Blacklist a student permanently
     * 
     * @param string $student_id Student to blacklist
     * @param string $reason_category Category of blacklist reason
     * @param string $detailed_reason Detailed explanation
     * @param int $admin_id Admin performing the action
     * @param string|null $admin_notes Optional admin notes
     * @param string|null $admin_email Admin email for notifications
     * @return array Result of blacklist operation
     */
    public function blacklistStudent($student_id, $reason_category, $detailed_reason, $admin_id, $admin_notes = null, $admin_email = null) {
        pg_query($this->connection, "BEGIN");
        
        try {
            // Get student details
            $studentQuery = pg_query_params($this->connection,
                "SELECT student_id, first_name, middle_name, last_name, email, status, is_archived
                 FROM students WHERE student_id = $1",
                [$student_id]
            );
            
            if (!$studentQuery || pg_num_rows($studentQuery) === 0) {
                throw new Exception("Student not found: {$student_id}");
            }
            
            $student = pg_fetch_assoc($studentQuery);
            
            // Check if already blacklisted
            if ($student['status'] === 'blacklisted') {
                throw new Exception("Student is already blacklisted");
            }
            
            // Update student status to blacklisted
            // Truncate archive_reason to prevent text field overflow
            $archiveReason = substr("Blacklisted: {$reason_category} - {$detailed_reason}", 0, 500);
            
            $updateStudent = pg_query_params($this->connection,
                "UPDATE students 
                 SET status = 'blacklisted',
                     is_archived = TRUE,
                     archived_at = NOW(),
                     archived_by = $1,
                     archive_reason = $2,
                     archival_type = 'blacklisted'
                 WHERE student_id = $3",
                [
                    $admin_id,
                    $archiveReason,
                    $student_id
                ]
            );
            
            if (!$updateStudent) {
                $dbError = pg_last_error($this->connection);
                error_log("BlacklistService: UPDATE failed - " . $dbError);
                throw new Exception("Failed to update student status: " . $dbError);
            }
            
            if (pg_affected_rows($updateStudent) === 0) {
                error_log("BlacklistService: No rows affected for student_id: {$student_id}");
                throw new Exception("Student not found or already blacklisted");
            }
            
            // Insert into blacklisted_students table
            $insertBlacklist = pg_query_params($this->connection,
                "INSERT INTO blacklisted_students 
                 (student_id, reason_category, detailed_reason, blacklisted_by, admin_email, admin_notes, blacklisted_at)
                 VALUES ($1, $2, $3, $4, $5, $6, NOW())",
                [
                    $student_id,
                    $reason_category,
                    $detailed_reason,
                    $admin_id,
                    $admin_email,
                    $admin_notes
                ]
            );
            
            if (!$insertBlacklist) {
                throw new Exception("Failed to create blacklist record");
            }
            
            // Add admin notification
            pg_query_params($this->connection,
                "INSERT INTO admin_notifications (message, created_at)
                 VALUES ($1, NOW())",
                ["BLACKLIST: {$student['first_name']} {$student['last_name']} has been permanently blacklisted"]
            );
            
            pg_query($this->connection, "COMMIT");
            
            // Compress files to blacklisted_students folder AFTER successful commit
            $compressionResult = null;
            try {
                $compressionResult = $this->fileService->compressBlacklistedStudent(
                    $student_id,
                    $reason_category,
                    $detailed_reason
                );
                
                if ($compressionResult['success']) {
                    error_log("BlacklistService: Files compressed for {$student_id} - {$compressionResult['files_added']} files archived to blacklisted_students/");
                } else {
                    error_log("BlacklistService: File compression failed for {$student_id} - {$compressionResult['message']}");
                }
            } catch (Exception $e) {
                error_log("BlacklistService: File compression error for {$student_id} - " . $e->getMessage());
                // Don't fail blacklist if compression fails
            }
            
            // Log to audit trail
            $this->auditLogger->logEvent(
                'student_blacklisted',
                'blacklist_management',
                "Blacklisted student: {$student['first_name']} {$student['last_name']}",
                [
                    'user_id' => $admin_id,
                    'user_type' => 'admin',
                    'student_id' => $student_id,
                    'student_email' => $student['email'],
                    'reason_category' => $reason_category,
                    'detailed_reason' => $detailed_reason,
                    'files_compressed' => $compressionResult['success'] ?? false,
                    'permanent' => true
                ]
            );
            
            return [
                'success' => true,
                'message' => 'Student has been permanently blacklisted and files archived',
                'student' => $student,
                'compression' => $compressionResult
            ];
            
        } catch (Exception $e) {
            pg_query($this->connection, "ROLLBACK");
            error_log("BlacklistService::blacklistStudent - Error: " . $e->getMessage());
            
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'student' => null
            ];
        }
    }
    
    /**
     * Check if a student is blacklisted
     */
    public function isBlacklisted($student_id) {
        $query = pg_query_params($this->connection,
            "SELECT status, is_archived FROM students WHERE student_id = $1",
            [$student_id]
        );
        
        if (!$query || pg_num_rows($query) === 0) {
            return false;
        }
        
        $student = pg_fetch_assoc($query);
        return $student['status'] === 'blacklisted';
    }
    
    /**
     * Check if email is blacklisted
     */
    public function isEmailBlacklisted($email) {
        $query = pg_query_params($this->connection,
            "SELECT student_id FROM students 
             WHERE LOWER(email) = LOWER($1) AND status = 'blacklisted'",
            [$email]
        );
        
        return ($query && pg_num_rows($query) > 0);
    }
    
    /**
     * Check if mobile number is blacklisted
     */
    public function isMobileBlacklisted($mobile) {
        $query = pg_query_params($this->connection,
            "SELECT student_id FROM students 
             WHERE mobile = $1 AND status = 'blacklisted'",
            [$mobile]
        );
        
        return ($query && pg_num_rows($query) > 0);
    }
    
    /**
     * Get blacklist details for a student
     */
    public function getBlacklistDetails($student_id) {
        $query = pg_query_params($this->connection,
            "SELECT bl.*, 
                    s.first_name, s.last_name, s.email, s.mobile,
                    a.first_name as admin_first_name, a.last_name as admin_last_name
             FROM blacklisted_students bl
             JOIN students s ON bl.student_id = s.student_id
             LEFT JOIN admins a ON bl.blacklisted_by = a.admin_id
             WHERE bl.student_id = $1",
            [$student_id]
        );
        
        return pg_fetch_assoc($query) ?: null;
    }
    
    /**
     * Get all blacklisted students with pagination
     */
    public function getAllBlacklisted($limit = 25, $offset = 0, $filters = []) {
        $where = ["s.status = 'blacklisted'"];
        $params = [];
        $paramCount = 1;
        
        // Search filter
        if (!empty($filters['search'])) {
            $where[] = "(s.first_name ILIKE $" . $paramCount . " OR s.last_name ILIKE $" . $paramCount . " OR s.email ILIKE $" . $paramCount . ")";
            $params[] = "%{$filters['search']}%";
            $paramCount++;
        }
        
        // Reason category filter
        if (!empty($filters['reason'])) {
            $where[] = "bl.reason_category = $" . $paramCount;
            $params[] = $filters['reason'];
            $paramCount++;
        }
        
        $whereClause = implode(' AND ', $where);
        
        $query = "SELECT bl.*, 
                         s.first_name, s.last_name, s.email, s.mobile,
                         a.first_name as admin_first_name, a.last_name as admin_last_name
                  FROM blacklisted_students bl
                  JOIN students s ON bl.student_id = s.student_id
                  LEFT JOIN admins a ON bl.blacklisted_by = a.admin_id
                  WHERE {$whereClause}
                  ORDER BY bl.blacklisted_at DESC
                  LIMIT {$limit} OFFSET {$offset}";
        
        $result = pg_query_params($this->connection, $query, $params);
        return pg_fetch_all($result) ?: [];
    }
    
    /**
     * Validate blacklist reason category
     */
    public function isValidReasonCategory($category) {
        return in_array($category, [
            self::REASON_FRAUDULENT_ACTIVITY,
            self::REASON_ACADEMIC_MISCONDUCT,
            self::REASON_SYSTEM_ABUSE,
            self::REASON_DUPLICATE_ACCOUNT,
            'duplicate', // Alias for duplicate_account (form compatibility)
            self::REASON_OTHER
        ]);
    }
}
?>
