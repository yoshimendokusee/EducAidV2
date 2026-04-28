<?php

namespace App\Services;

use App\Traits\UsesDatabaseConnection;
use Exception;

/**
 * AuditLogger (Laravel Compatible)
 * 
 * Comprehensive audit trail logging for EducAid system
 * Tracks all major events including:
 * - Authentication (login/logout)
 * - Slot management
 * - Applicant management
 * - Payroll and schedule operations
 * - Profile changes
 * - Distribution lifecycle
 * 
 * Migrated to Laravel with:
 * - Proper namespacing
 * - Dependency injection support
 * - Database trait for connection management
 * 
 * @package App\Services
 */
class AuditLogger
{
    use UsesDatabaseConnection;

    private static $auditTableExists = null;

    /**
     * Initialize AuditLogger with database connection
     * 
     * @param resource|null $dbConnection Database connection (optional, will use global if null)
     */
    public function __construct($dbConnection = null)
    {
        $this->setConnection($dbConnection);
    }

    /**
     * Check once if audit_logs table exists; cache the result
     * 
     * @return bool
     */
    private function ensureTableExists()
    {
        if (self::$auditTableExists !== null) {
            return self::$auditTableExists;
        }

        try {
            $result = @$this->executeQuery(
                "SELECT to_regclass('public.audit_logs') AS tbl",
                []
            );

            if ($result) {
                $row = $this->fetchOne($result);
                self::$auditTableExists = ($row && $row['tbl'] !== null);
            } else {
                self::$auditTableExists = false;
            }
        } catch (Exception $e) {
            error_log("AuditLogger::ensureTableExists - Error: " . $e->getMessage());
            self::$auditTableExists = false;
        }

        return self::$auditTableExists;
    }

    /**
     * Log any audit event
     * 
     * @param string $eventType Specific event type (e.g., 'admin_login', 'slot_opened')
     * @param string $eventCategory Event category (e.g., 'authentication', 'slot_management')
     * @param string $description Event description
     * @param string $userType User type (admin, student, system)
     * @param string $username Username of the user performing action
     * @param int|null $userId User ID (optional)
     * @param string $status Status (success, failed, etc.)
     * @param array|null $metadata Additional metadata
     * @return bool
     */
    public function log(
        $eventType,
        $eventCategory,
        $description,
        $userType,
        $username,
        $userId = null,
        $status = 'success',
        $metadata = null
    ) {
        try {
            if (!$this->ensureTableExists()) {
                error_log("AuditLogger: audit_logs table does not exist");
                return false;
            }

            $query = "INSERT INTO audit_logs (
                user_id, user_type, username, event_type, event_category,
                action_description, status, ip_address, user_agent, metadata
            ) VALUES (
                $1, $2, $3, $4, $5, $6, $7, $8, $9, $10
            )";

            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

            $this->executeQuery($query, [
                $userId,
                $userType,
                $username,
                $eventType,
                $eventCategory,
                $description,
                $status,
                $ipAddress,
                $userAgent,
                $metadata ? json_encode($metadata) : null
            ]);

            return true;

        } catch (Exception $e) {
            error_log("AuditLogger::log error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Log authentication event
     * 
     * @param string $username Username
     * @param string $userType User type (admin, student)
     * @param string $action Action (login, logout)
     * @param int|null $userId User ID
     * @param string $status Status
     * @return bool
     */
    public function logAuth($username, $userType, $action, $userId = null, $status = 'success')
    {
        return $this->log(
            "auth_{$action}",
            'authentication',
            "{$userType} {$action}: {$username}",
            $userType,
            $username,
            $userId,
            $status
        );
    }

    /**
     * Backward-compatible login wrapper used by legacy callers.
     *
     * @param int|string|null $userId User ID
     * @param string $userType User type
     * @param string $username Username
     * @param string $status Status
     * @return bool
     */
    public function logLogin($userId, $userType, $username, $status = 'success')
    {
        return $this->logAuth($username, $userType, 'login', $userId, $status);
    }

    /**
     * Backward-compatible event wrapper used by legacy callers.
     *
     * @param string $eventType Event type
     * @param string $eventCategory Event category
     * @param string $actionDescription Description
     * @param array $options Legacy options array
     * @return bool
     */
    public function logEvent($eventType, $eventCategory, $actionDescription, $options = [])
    {
        $userType = $options['user_type'] ?? ($options['userType'] ?? 'system');
        $username = $options['username'] ?? ($options['user_name'] ?? 'SYSTEM');
        $userId = $options['user_id'] ?? ($options['userId'] ?? null);
        $status = $options['status'] ?? 'success';
        $metadata = $options['metadata'] ?? null;

        return $this->log(
            $eventType,
            $eventCategory,
            $actionDescription,
            $userType,
            $username,
            $userId,
            $status,
            $metadata
        );
    }

    /**
     * Backward-compatible applicant approval wrapper used by legacy callers.
     *
     * @param int|string|null $adminId Admin ID
     * @param string $adminUsername Admin username
     * @param string $studentId Student ID
     * @param array $metadata Additional metadata
     * @return bool
     */
    public function logApplicantApproved($adminId, $adminUsername, $studentId, $metadata = [])
    {
        return $this->logEvent(
            'applicant_approved',
            'applicant_management',
            'Applicant approved: ' . $studentId,
            [
                'user_id' => $adminId,
                'user_type' => 'admin',
                'username' => $adminUsername,
                'metadata' => array_merge(['student_id' => $studentId], is_array($metadata) ? $metadata : [])
            ]
        );
    }

    /**
     * Backward-compatible archival wrapper used by legacy callers.
     *
     * @param int|string|null $adminId Admin ID
     * @param string $adminUsername Admin username
     * @param string $studentId Student ID
     * @param array $metadata Additional metadata
     * @param string|null $reason Archive reason
     * @param bool $automatic Whether the archival was automatic
     * @return bool
     */
    public function logStudentArchived($adminId, $adminUsername, $studentId, $metadata = [], $reason = null, $automatic = false)
    {
        return $this->logEvent(
            'student_archived',
            'student_management',
            'Student archived: ' . $studentId,
            [
                'user_id' => $adminId,
                'user_type' => 'admin',
                'username' => $adminUsername,
                'metadata' => array_merge([
                    'student_id' => $studentId,
                    'archive_reason' => $reason,
                    'automatic' => (bool) $automatic,
                ], is_array($metadata) ? $metadata : [])
            ]
        );
    }

    /**
     * Backward-compatible unarchive wrapper used by legacy callers.
     *
     * @param int|string|null $adminId Admin ID
     * @param string $adminUsername Admin username
     * @param string $studentId Student ID
     * @param array $metadata Additional metadata
     * @return bool
     */
    public function logStudentUnarchived($adminId, $adminUsername, $studentId, $metadata = [])
    {
        return $this->logEvent(
            'student_unarchived',
            'student_management',
            'Student unarchived: ' . $studentId,
            [
                'user_id' => $adminId,
                'user_type' => 'admin',
                'username' => $adminUsername,
                'metadata' => array_merge(['student_id' => $studentId], is_array($metadata) ? $metadata : [])
            ]
        );
    }

    /**
     * Log admin action
     * 
     * @param string $username Admin username
     * @param int $adminId Admin ID
     * @param string $action Action description
     * @param string $category Action category
     * @param array|null $metadata Additional data
     * @param string $status Status
     * @return bool
     */
    public function logAdminAction($username, $adminId, $action, $category, $metadata = null, $status = 'success')
    {
        return $this->log(
            'admin_action',
            $category,
            $action,
            'admin',
            $username,
            $adminId,
            $status,
            $metadata
        );
    }

    /**
     * Log student action
     * 
     * @param string $studentId Student ID
     * @param string $action Action description
     * @param string $category Action category
     * @param array|null $metadata Additional data
     * @param string $status Status
     * @return bool
     */
    public function logStudentAction($studentId, $action, $category, $metadata = null, $status = 'success')
    {
        return $this->log(
            'student_action',
            $category,
            $action,
            'student',
            $studentId,
            null,
            $status,
            $metadata
        );
    }

    /**
     * Log system event
     * 
     * @param string $action Action description
     * @param string $category Event category
     * @param array|null $metadata Additional data
     * @param string $status Status
     * @return bool
     */
    public function logSystemEvent($action, $category, $metadata = null, $status = 'success')
    {
        return $this->log(
            'system_event',
            $category,
            $action,
            'system',
            'SYSTEM',
            null,
            $status,
            $metadata
        );
    }

    /**
     * Get audit logs for user
     * 
     * @param string $username Username to filter by
     * @param int $limit Number of records to return
     * @param int $offset Offset for pagination
     * @return array Array of audit log records
     */
    public function getUserLogs($username, $limit = 100, $offset = 0)
    {
        try {
            if (!$this->ensureTableExists()) {
                return [];
            }

            $query = "SELECT * FROM audit_logs 
                      WHERE username = $1 
                      ORDER BY created_at DESC 
                      LIMIT $2 OFFSET $3";

            $result = $this->executeQuery($query, [$username, $limit, $offset]);

            return $this->fetchAll($result);

        } catch (Exception $e) {
            error_log("AuditLogger::getUserLogs error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get audit logs by category
     * 
     * @param string $category Event category
     * @param int $limit Number of records to return
     * @param int $offset Offset for pagination
     * @return array Array of audit log records
     */
    public function getLogsByCategory($category, $limit = 100, $offset = 0)
    {
        try {
            if (!$this->ensureTableExists()) {
                return [];
            }

            $query = "SELECT * FROM audit_logs 
                      WHERE event_category = $1 
                      ORDER BY created_at DESC 
                      LIMIT $2 OFFSET $3";

            $result = $this->executeQuery($query, [$category, $limit, $offset]);

            return $this->fetchAll($result);

        } catch (Exception $e) {
            error_log("AuditLogger::getLogsByCategory error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get audit logs by date range
     * 
     * @param string $startDate Start date (Y-m-d)
     * @param string $endDate End date (Y-m-d)
     * @param int $limit Number of records to return
     * @return array Array of audit log records
     */
    public function getLogsByDateRange($startDate, $endDate, $limit = 1000)
    {
        try {
            if (!$this->ensureTableExists()) {
                return [];
            }

            $query = "SELECT * FROM audit_logs 
                      WHERE created_at >= $1 AND created_at <= $2 
                      ORDER BY created_at DESC 
                      LIMIT $3";

            $startDateTime = $startDate . ' 00:00:00';
            $endDateTime = $endDate . ' 23:59:59';

            $result = $this->executeQuery($query, [$startDateTime, $endDateTime, $limit]);

            return $this->fetchAll($result);

        } catch (Exception $e) {
            error_log("AuditLogger::getLogsByDateRange error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get failed audit logs
     * 
     * @param int $limit Number of records to return
     * @return array Array of failed audit log records
     */
    public function getFailedLogs($limit = 100)
    {
        try {
            if (!$this->ensureTableExists()) {
                return [];
            }

            $query = "SELECT * FROM audit_logs 
                      WHERE status = 'failed' 
                      ORDER BY created_at DESC 
                      LIMIT $1";

            $result = $this->executeQuery($query, [$limit]);

            return $this->fetchAll($result);

        } catch (Exception $e) {
            error_log("AuditLogger::getFailedLogs error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Clear old audit logs (older than specified days)
     * 
     * @param int $daysOld Number of days to keep
     * @return bool
     */
    public function clearOldLogs($daysOld = 90)
    {
        try {
            if (!$this->ensureTableExists()) {
                return false;
            }

            $query = "DELETE FROM audit_logs 
                      WHERE created_at < NOW() - INTERVAL '1 day' * $1";

            $this->executeQuery($query, [$daysOld]);

            return true;

        } catch (Exception $e) {
            error_log("AuditLogger::clearOldLogs error: " . $e->getMessage());
            return false;
        }
    }
}
