<?php
/**
 * StudentArchivalService
 * 
 * Centralized service for all student archival operations
 * Handles: Manual archival, Graduation archival, Household duplicate archival
 * Integrates with: UnifiedFileService (for file compression), AuditLogger
 * 
 * DOES NOT handle blacklisting - that's in BlacklistService
 */

require_once __DIR__ . '/../bootstrap_services.php';

class StudentArchivalService {
    private $connection;
    private $fileService;
    private $auditLogger;
    
    // Archival types
    const TYPE_MANUAL = 'manual';
    const TYPE_GRADUATED = 'graduated';
    const TYPE_HOUSEHOLD_DUPLICATE = 'household_duplicate';
    
    public function __construct($dbConnection = null) {
        global $connection;
        $this->connection = $dbConnection ?? $connection;
        $this->fileService = new UnifiedFileService($this->connection);
        $this->auditLogger = new AuditLogger($this->connection);
    }
    
    /**
     * Core archival method - all archival operations go through here
     * 
     * @param string $student_id Student ID to archive
     * @param string $reason Human-readable reason for archival
     * @param int|null $archived_by Admin ID (NULL for automatic system actions)
     * @param string $archival_type Type of archival (manual, graduated, household_duplicate)
     * @param array $metadata Additional metadata for specific archival types
     * @return array ['success' => bool, 'message' => string, 'student' => array]
     */
    private function archiveStudent($student_id, $reason, $archived_by, $archival_type, $metadata = []) {
        pg_query($this->connection, "BEGIN");
        
        try {
            // Get student details before archiving
            $studentQuery = pg_query_params($this->connection,
                "SELECT student_id, first_name, middle_name, last_name, email, status, is_archived
                 FROM students WHERE student_id = $1",
                [$student_id]
            );
            
            if (!$studentQuery || pg_num_rows($studentQuery) === 0) {
                throw new Exception("Student not found: {$student_id}");
            }
            
            $student = pg_fetch_assoc($studentQuery);
            
            // Check if already archived
            if ($student['is_archived'] === 't' || $student['is_archived'] === true) {
                throw new Exception("Student is already archived");
            }
            
            // Check if blacklisted (cannot archive blacklisted students via this service)
            if ($student['status'] === 'blacklisted') {
                throw new Exception("Cannot archive blacklisted students through archival service. Use BlacklistService.");
            }
            
            // Archive the student
            $archiveQuery = pg_query_params($this->connection,
                "UPDATE students 
                 SET is_archived = TRUE,
                     archived_at = NOW(),
                     archived_by = $1,
                     archive_reason = $2,
                     archival_type = $3,
                     status = 'archived'
                 WHERE student_id = $4",
                [$archived_by, $reason, $archival_type, $student_id]
            );
            
            if (!$archiveQuery || pg_affected_rows($archiveQuery) === 0) {
                throw new Exception("Failed to archive student in database");
            }
            
            // Handle household_block_attempts references (if student blocked other registrations)
            // OPTION 1 (Default): Nullify references - preserves block history for audit trail
            @pg_query_params($this->connection,
                "UPDATE household_block_attempts SET blocked_by_student_id = NULL WHERE blocked_by_student_id = $1",
                [$student_id]
            );
            
            // OPTION 2 (Alternative): Delete orphaned attempts - uncomment if preferred
            // @pg_query_params($this->connection,
            //     "DELETE FROM household_block_attempts WHERE blocked_by_student_id = $1",
            //     [$student_id]
            // );
            
            // Apply type-specific metadata updates
            $this->applyTypeSpecificUpdates($student_id, $archival_type, $metadata);
            
            pg_query($this->connection, "COMMIT");
            
            // Compress files AFTER successful database commit
            $compressionResult = null;
            try {
                $compressionResult = $this->fileService->compressArchivedStudent($student_id);
                
                if ($compressionResult['success']) {
                    error_log("StudentArchivalService: Files compressed for {$student_id} - {$compressionResult['files_added']} files");
                } else {
                    error_log("StudentArchivalService: File compression failed for {$student_id} - {$compressionResult['message']}");
                }
            } catch (Exception $e) {
                error_log("StudentArchivalService: File compression error for {$student_id} - " . $e->getMessage());
                // Don't fail archival if compression fails
            }
            
            // Log to audit trail
            if ($archived_by) {
                $this->auditLogger->logEvent(
                    'student_archived',
                    'student_management',
                    "Archived student: {$student['first_name']} {$student['last_name']}",
                    [
                        'user_id' => $archived_by,
                        'user_type' => 'admin',
                        'student_id' => $student_id,
                        'archival_type' => $archival_type,
                        'reason' => $reason,
                        'files_compressed' => $compressionResult['success'] ?? false,
                        'metadata' => $metadata
                    ]
                );
            }
            
            return [
                'success' => true,
                'message' => 'Student archived successfully',
                'student' => $student,
                'compression' => $compressionResult
            ];
            
        } catch (Exception $e) {
            pg_query($this->connection, "ROLLBACK");
            error_log("StudentArchivalService::archiveStudent - Error: " . $e->getMessage());
            
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'student' => null
            ];
        }
    }
    
    /**
     * Apply type-specific database updates
     */
    private function applyTypeSpecificUpdates($student_id, $archival_type, $metadata) {
        switch ($archival_type) {
            case self::TYPE_HOUSEHOLD_DUPLICATE:
                // Mark household relationship
                pg_query_params($this->connection,
                    "UPDATE students 
                     SET household_verified = TRUE,
                         household_primary = FALSE,
                         household_group_id = $1
                     WHERE student_id = $2",
                    [$metadata['household_group_id'] ?? null, $student_id]
                );
                
                // Mark primary recipient
                if (!empty($metadata['primary_recipient_id'])) {
                    pg_query_params($this->connection,
                        "UPDATE students 
                         SET household_verified = TRUE,
                             household_primary = TRUE,
                             household_group_id = $1
                         WHERE student_id = $2",
                        [$metadata['household_group_id'] ?? null, $metadata['primary_recipient_id']]
                    );
                }
                break;
                
            case self::TYPE_GRADUATED:
                // Additional graduation-specific updates can go here
                // e.g., update year_level_history, send congratulations email
                break;
                
            case self::TYPE_MANUAL:
                // Manual archival may not need additional updates
                break;
        }
    }
    
    // ============================================================================
    // PUBLIC METHODS - Specific Archival Scenarios
    // ============================================================================
    
    /**
     * Manual archival by admin (dropout, transfer, etc.)
     * 
     * @param string $student_id Student to archive
     * @param string $reason Custom reason provided by admin
     * @param int $admin_id Admin performing the action
     * @return array Result of archival operation
     */
    public function archiveStudentManually($student_id, $reason, $admin_id) {
        return $this->archiveStudent(
            $student_id,
            $reason,
            $admin_id,
            self::TYPE_MANUAL,
            ['manual_reason' => $reason]
        );
    }
    
    /**
     * Archive student as graduated (called after year advancement)
     * 
     * @param string $student_id Student who graduated
     * @param string $academic_year Academic year of graduation (e.g., "2025-2026")
     * @param string|null $program Course/program completed
     * @return array Result of archival operation
     */
    public function archiveAsGraduated($student_id, $academic_year, $program = null) {
        $reason = "Graduated";
        if ($program) {
            $reason .= " from {$program}";
        }
        $reason .= " - Academic Year {$academic_year}";
        
        return $this->archiveStudent(
            $student_id,
            $reason,
            null, // Automatic system action
            self::TYPE_GRADUATED,
            [
                'academic_year' => $academic_year,
                'program_completed' => $program,
                'graduation_date' => date('Y-m-d')
            ]
        );
    }
    
    /**
     * Archive household duplicate (same household, one per household policy)
     * 
     * @param string $student_id Student to archive (duplicate household member)
     * @param string $primary_recipient_id Student who keeps the assistance
     * @param int $admin_id Admin performing the action
     * @param string|null $household_group_id Optional group ID to link household members
     * @return array Result of archival operation
     */
    public function archiveHouseholdDuplicate($student_id, $primary_recipient_id, $admin_id, $household_group_id = null) {
        // Generate household group ID if not provided
        if (!$household_group_id) {
            $household_group_id = 'HOUSEHOLD_' . strtoupper(substr($primary_recipient_id, 0, 15)) . '_' . time();
        }
        
        $reason = "Same household as primary recipient {$primary_recipient_id} - One per household policy";
        
        return $this->archiveStudent(
            $student_id,
            $reason,
            $admin_id,
            self::TYPE_HOUSEHOLD_DUPLICATE,
            [
                'primary_recipient_id' => $primary_recipient_id,
                'household_group_id' => $household_group_id,
                'can_unarchive' => true
            ]
        );
    }
    
    /**
     * Batch archive students (for mass operations like year advancement)
     * 
     * @param array $student_ids Array of student IDs to archive
     * @param string $reason Reason for batch archival
     * @param int|null $admin_id Admin ID (null for automatic)
     * @param string $archival_type Type of archival
     * @return array Results for each student
     */
    public function batchArchiveStudents($student_ids, $reason, $admin_id, $archival_type, $metadata = []) {
        $results = [
            'success' => 0,
            'failed' => 0,
            'details' => []
        ];
        
        foreach ($student_ids as $student_id) {
            $result = $this->archiveStudent($student_id, $reason, $admin_id, $archival_type, $metadata);
            
            if ($result['success']) {
                $results['success']++;
            } else {
                $results['failed']++;
            }
            
            $results['details'][$student_id] = $result;
        }
        
        return $results;
    }
    
    // ============================================================================
    // UNARCHIVAL METHODS
    // ============================================================================
    
    /**
     * Unarchive a student (only for household duplicates or manual archival)
     * Graduated and blacklisted students cannot be unarchived
     * 
     * @param string $student_id Student to unarchive
     * @param string $reason Reason for unarchiving
     * @param int $admin_id Admin performing the action
     * @return array Result of unarchival operation
     */
    public function unarchiveStudent($student_id, $reason, $admin_id) {
        pg_query($this->connection, "BEGIN");
        
        try {
            // Get student details
            $studentQuery = pg_query_params($this->connection,
                "SELECT student_id, first_name, last_name, archival_type, is_archived, status
                 FROM students WHERE student_id = $1",
                [$student_id]
            );
            
            if (!$studentQuery || pg_num_rows($studentQuery) === 0) {
                throw new Exception("Student not found");
            }
            
            $student = pg_fetch_assoc($studentQuery);
            
            // Check if student is archived
            if ($student['is_archived'] !== 't' && $student['is_archived'] !== true) {
                throw new Exception("Student is not archived");
            }
            
            // Check if can be unarchived
            if ($student['archival_type'] === self::TYPE_GRADUATED) {
                throw new Exception("Cannot unarchive graduated students");
            }
            
            if ($student['status'] === 'blacklisted') {
                throw new Exception("Cannot unarchive blacklisted students");
            }
            
            // Unarchive the student
            $unarchiveQuery = pg_query_params($this->connection,
                "UPDATE students 
                 SET is_archived = FALSE,
                     archived_at = NULL,
                     archived_by = NULL,
                     archive_reason = NULL,
                     archival_type = NULL,
                     status = 'applicant',
                     unarchived_at = NOW(),
                     unarchived_by = $1,
                     unarchive_reason = $2,
                     household_primary = TRUE
                 WHERE student_id = $3",
                [$admin_id, $reason, $student_id]
            );
            
            if (!$unarchiveQuery || pg_affected_rows($unarchiveQuery) === 0) {
                throw new Exception("Failed to unarchive student");
            }
            
            pg_query($this->connection, "COMMIT");
            
            // Log action
            $this->auditLogger->logEvent(
                'student_unarchived',
                'student_management',
                "Unarchived student: {$student['first_name']} {$student['last_name']}",
                [
                    'user_id' => $admin_id,
                    'user_type' => 'admin',
                    'student_id' => $student_id,
                    'reason' => $reason,
                    'previous_archival_type' => $student['archival_type']
                ]
            );
            
            return [
                'success' => true,
                'message' => 'Student unarchived successfully',
                'student' => $student
            ];
            
        } catch (Exception $e) {
            pg_query($this->connection, "ROLLBACK");
            error_log("StudentArchivalService::unarchiveStudent - Error: " . $e->getMessage());
            
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    // ============================================================================
    // QUERY / HELPER METHODS
    // ============================================================================
    
    /**
     * Check if a student can be unarchived
     */
    public function canUnarchive($student_id) {
        $query = pg_query_params($this->connection,
            "SELECT archival_type, status, is_archived 
             FROM students WHERE student_id = $1",
            [$student_id]
        );
        
        if (!$query || pg_num_rows($query) === 0) {
            return ['can_unarchive' => false, 'reason' => 'Student not found'];
        }
        
        $student = pg_fetch_assoc($query);
        
        if ($student['is_archived'] !== 't' && $student['is_archived'] !== true) {
            return ['can_unarchive' => false, 'reason' => 'Student is not archived'];
        }
        
        if ($student['archival_type'] === self::TYPE_GRADUATED) {
            return ['can_unarchive' => false, 'reason' => 'Cannot unarchive graduated students'];
        }
        
        if ($student['status'] === 'blacklisted') {
            return ['can_unarchive' => false, 'reason' => 'Cannot unarchive blacklisted students'];
        }
        
        return ['can_unarchive' => true, 'reason' => null];
    }
    
    /**
     * Get household members linked to a student
     */
    public function getHouseholdMembers($student_id) {
        $query = pg_query_params($this->connection,
            "SELECT household_group_id FROM students WHERE student_id = $1",
            [$student_id]
        );
        
        if (!$query || pg_num_rows($query) === 0) {
            return [];
        }
        
        $row = pg_fetch_assoc($query);
        $household_group_id = $row['household_group_id'];
        
        if (!$household_group_id) {
            return [];
        }
        
        $membersQuery = pg_query_params($this->connection,
            "SELECT student_id, first_name, last_name, status, is_archived, 
                    household_primary, archived_at
             FROM students 
             WHERE household_group_id = $1
             ORDER BY household_primary DESC, archived_at ASC",
            [$household_group_id]
        );
        
        return pg_fetch_all($membersQuery) ?: [];
    }
    
    /**
     * Get archival history for a student
     */
    public function getArchivalHistory($student_id) {
        return [
            'current_status' => $this->getCurrentArchivalStatus($student_id),
            'audit_logs' => $this->getArchivalAuditLogs($student_id)
        ];
    }
    
    private function getCurrentArchivalStatus($student_id) {
        $query = pg_query_params($this->connection,
            "SELECT is_archived, archived_at, archived_by, archive_reason, archival_type,
                    unarchived_at, unarchived_by, unarchive_reason,
                    household_verified, household_primary, household_group_id
             FROM students WHERE student_id = $1",
            [$student_id]
        );
        
        return pg_fetch_assoc($query) ?: null;
    }
    
    private function getArchivalAuditLogs($student_id) {
        $query = pg_query_params($this->connection,
            "SELECT * FROM audit_logs 
             WHERE action IN ('student_archived', 'student_unarchived')
               AND details::text LIKE $1
             ORDER BY timestamp DESC",
            ['%"student_id":"' . $student_id . '"%']
        );
        
        return pg_fetch_all($query) ?: [];
    }
}
?>
