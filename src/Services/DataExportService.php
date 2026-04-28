<?php

namespace App\Services;

use App\Traits\UsesDatabaseConnection;
use Exception;
use ZipArchive;

/**
 * DataExportService (Laravel Compatible)
 * 
 * Compiles a student's personal data into a downloadable ZIP archive of JSON files
 * Exports include profile, login history, sessions, notifications, documents, audit logs
 * 
 * Migrated to Laravel with:
 * - Proper namespacing
 * - Dependency injection support
 * - Database trait for connection management
 * - Secure token generation
 * 
 * @package App\Services
 */
class DataExportService
{
    use UsesDatabaseConnection;

    private $baseExportDir;
    private $pathConfig;

    /**
     * Initialize Data Export Service
     * 
     * @param resource|null $dbConnection Database connection (optional, will use global if null)
     * @param string|null $baseExportDir Base export directory (optional, will auto-detect if null)
     */
    public function __construct($dbConnection = null, $baseExportDir = null)
    {
        $this->setConnection($dbConnection);
        $this->initializePathConfig();

        // Set export directory
        if ($baseExportDir) {
            $this->baseExportDir = $baseExportDir;
        } elseif ($this->pathConfig && method_exists($this->pathConfig, 'getDataExportsPath')) {
            $this->baseExportDir = $this->pathConfig->getDataExportsPath();
        } else {
            // Fallback to storage directory
            $this->baseExportDir = sys_get_temp_dir() . '/educaid_exports';
        }

        error_log("DataExportService: ExportDir=" . $this->baseExportDir);
    }

    /**
     * Initialize path configuration
     */
    private function initializePathConfig()
    {
        // Try to load FilePathConfig if it exists (backward compatibility)
        if (file_exists(__DIR__ . '/../../config/FilePathConfig.php')) {
            require_once __DIR__ . '/../../config/FilePathConfig.php';
            if (class_exists('FilePathConfig')) {
                $this->pathConfig = FilePathConfig::getInstance();
                error_log("DataExportService: Using FilePathConfig");
            }
        }
    }

    /**
     * Ensure export directory exists
     */
    private function ensureExportDir()
    {
        if (!is_dir($this->baseExportDir)) {
            mkdir($this->baseExportDir, 0755, true);
        }
    }

    /**
     * Generate a new secure download token
     * 
     * @param int $bytes Number of random bytes
     * @return string Hex-encoded token
     */
    public function generateToken($bytes = 32)
    {
        try {
            return bin2hex(random_bytes($bytes));
        } catch (Exception $e) {
            error_log("DataExportService::generateToken error: " . $e->getMessage());
            // Fallback to simpler random generation
            return bin2hex(openssl_random_pseudo_bytes($bytes));
        }
    }

    /**
     * Build data export for a given student and return file info
     * 
     * @param string $studentId Student ID
     * @return array Result array with keys: success, zip_path, size, error
     */
    public function buildExport($studentId)
    {
        try {
            $this->ensureExportDir();

            // Create a working directory
            $ts = date('Ymd_His');
            $workDir = $this->baseExportDir . "/{$studentId}_{$ts}";
            if (!mkdir($workDir, 0755, true)) {
                return ['success' => false, 'error' => 'Failed to create export work directory'];
            }

            // Collect datasets
            $datasets = [
                'profile' => $this->getStudentProfile($studentId),
                'login_history' => $this->getLoginHistory($studentId),
                'active_sessions' => $this->getActiveSessions($studentId),
                'notifications' => $this->getNotifications($studentId),
                'documents' => $this->getDocuments($studentId),
                'audit_logs' => $this->getAuditLogs($studentId)
            ];

            // Write each dataset as JSON
            foreach ($datasets as $name => $data) {
                $this->writeJson($workDir . "/{$name}.json", $data);
            }

            // Zip it up
            $zipPath = $workDir . '.zip';
            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                return ['success' => false, 'error' => 'Failed to create ZIP archive'];
            }

            // Add files from workDir
            $files = scandir($workDir);
            foreach ($files as $f) {
                if ($f === '.' || $f === '..') {
                    continue;
                }
                $zip->addFile($workDir . '/' . $f, $f);
            }
            $zip->close();

            // Compute size
            $size = file_exists($zipPath) ? filesize($zipPath) : 0;

            // Clean up workDir (leave only ZIP)
            $this->rrmdir($workDir);

            return [
                'success' => true,
                'zip_path' => $zipPath,
                'size' => $size,
                'error' => null
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Write data to JSON file
     * 
     * @param string $path File path
     * @param mixed $data Data to encode
     */
    private function writeJson($path, $data)
    {
        file_put_contents(
            $path,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Recursively remove directory and all contents
     * 
     * @param string $dir Directory path
     */
    private function rrmdir($dir)
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $p = $dir . '/' . $item;
            if (is_dir($p)) {
                $this->rrmdir($p);
            } else {
                @unlink($p);
            }
        }

        @rmdir($dir);
    }

    /**
     * Get student profile data
     * 
     * @param string $studentId Student ID
     * @return array Student profile
     */
    private function getStudentProfile($studentId)
    {
        try {
            $query = "SELECT * FROM students WHERE student_id = $1 LIMIT 1";
            $result = $this->executeQuery($query, [$studentId]);

            if ($this->getRowCount($result) > 0) {
                return $this->fetchOne($result) ?: [];
            }

            return [];

        } catch (Exception $e) {
            error_log("DataExportService::getStudentProfile error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get student login history
     * 
     * @param string $studentId Student ID
     * @return array Login history records
     */
    private function getLoginHistory($studentId)
    {
        try {
            $query = "SELECT * FROM student_login_history 
                      WHERE student_id = $1 
                      ORDER BY login_time DESC";
            $result = $this->executeQuery($query, [$studentId]);

            return $this->fetchAll($result) ?: [];

        } catch (Exception $e) {
            error_log("DataExportService::getLoginHistory error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get active sessions for student
     * 
     * @param string $studentId Student ID
     * @return array Session records
     */
    private function getActiveSessions($studentId)
    {
        try {
            $query = "SELECT * FROM student_active_sessions 
                      WHERE student_id = $1 
                      ORDER BY is_current DESC, last_activity DESC";
            $result = $this->executeQuery($query, [$studentId]);

            return $this->fetchAll($result) ?: [];

        } catch (Exception $e) {
            error_log("DataExportService::getActiveSessions error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get student notifications
     * 
     * @param string $studentId Student ID
     * @return array Notification records
     */
    private function getNotifications($studentId)
    {
        try {
            // Check if table exists
            $checkQuery = "SELECT to_regclass('public.student_notifications') AS tbl";
            $checkResult = @$this->executeQuery($checkQuery, []);

            if ($checkResult) {
                $row = $this->fetchOne($checkResult);
                if (!$row || $row['tbl'] === null) {
                    return [];
                }
            } else {
                return [];
            }

            $query = "SELECT * FROM student_notifications 
                      WHERE student_id = $1 
                      ORDER BY created_at DESC NULLS LAST";
            $result = $this->executeQuery($query, [$studentId]);

            return $this->fetchAll($result) ?: [];

        } catch (Exception $e) {
            error_log("DataExportService::getNotifications error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get student documents
     * 
     * @param string $studentId Student ID
     * @return array Document records
     */
    private function getDocuments($studentId)
    {
        try {
            // Check if table exists
            $checkQuery = "SELECT to_regclass('public.documents') AS tbl";
            $checkResult = @$this->executeQuery($checkQuery, []);

            if ($checkResult) {
                $row = $this->fetchOne($checkResult);
                if (!$row || $row['tbl'] === null) {
                    return [];
                }
            } else {
                return [];
            }

            $query = "SELECT document_id, document_type_code, document_type_name, file_name, file_path, 
                             file_size_bytes, verification_status, upload_year, status, last_modified 
                      FROM documents 
                      WHERE student_id = $1 
                      ORDER BY last_modified DESC NULLS LAST";
            $result = $this->executeQuery($query, [$studentId]);

            return $this->fetchAll($result) ?: [];

        } catch (Exception $e) {
            error_log("DataExportService::getDocuments error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get audit logs for student
     * 
     * @param string $studentId Student ID
     * @return array Audit log records
     */
    private function getAuditLogs($studentId)
    {
        try {
            // Check if table exists
            $checkQuery = "SELECT to_regclass('public.audit_logs') AS tbl";
            $checkResult = @$this->executeQuery($checkQuery, []);

            if ($checkResult) {
                $row = $this->fetchOne($checkResult);
                if (!$row || $row['tbl'] === null) {
                    return [];
                }
            } else {
                return [];
            }

            $query = "SELECT * FROM audit_logs 
                      WHERE user_type = 'student' AND (user_id::text = $1 OR username = $1) 
                      ORDER BY created_at DESC NULLS LAST";
            $result = $this->executeQuery($query, [$studentId]);

            return $this->fetchAll($result) ?: [];

        } catch (Exception $e) {
            error_log("DataExportService::getAuditLogs error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Delete exported ZIP file
     * 
     * @param string $zipPath ZIP file path
     * @return bool
     */
    public function deleteExport($zipPath)
    {
        try {
            if (file_exists($zipPath)) {
                return @unlink($zipPath);
            }
            return true;

        } catch (Exception $e) {
            error_log("DataExportService::deleteExport error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Clean up old exports (older than specified days)
     * 
     * @param int $daysOld Number of days to keep
     * @return int Number of files deleted
     */
    public function cleanupOldExports($daysOld = 7)
    {
        try {
            $this->ensureExportDir();

            $cutoffTime = time() - ($daysOld * 24 * 60 * 60);
            $deletedCount = 0;

            $files = glob($this->baseExportDir . '/*.zip');
            foreach ($files as $file) {
                if (filemtime($file) < $cutoffTime) {
                    if (@unlink($file)) {
                        $deletedCount++;
                    }
                }
            }

            return $deletedCount;

        } catch (Exception $e) {
            error_log("DataExportService::cleanupOldExports error: " . $e->getMessage());
            return 0;
        }
    }
}
