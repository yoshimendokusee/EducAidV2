<?php

namespace App\Services;

use App\Traits\UsesDatabaseConnection;
use Exception;

/**
 * DocumentService (Laravel Compatible)
 * 
 * Handles all document operations:
 * - Upload to temp folder during registration
 * - Move to permanent storage after approval
 * - OCR processing and verification
 * - Database operations with new unified schema
 * 
 * Migrated to Laravel with:
 * - Proper namespacing
 * - Dependency injection support  
 * - Database trait for connection management
 * - Audit logging support
 * 
 * @package App\Services
 */
class DocumentService
{
    use UsesDatabaseConnection;

    private $baseDir;
    private $pathConfig;

    // Document type mapping
    const DOCUMENT_TYPES = [
        'eaf' => ['code' => '00', 'name' => 'eaf', 'folder' => 'enrollment_forms'],
        'academic_grades' => ['code' => '01', 'name' => 'academic_grades', 'folder' => 'grades'],
        'letter_to_mayor' => ['code' => '02', 'name' => 'letter_to_mayor', 'folder' => 'letter_to_mayor'],
        'certificate_of_indigency' => ['code' => '03', 'name' => 'certificate_of_indigency', 'folder' => 'indigency'],
        'id_picture' => ['code' => '04', 'name' => 'id_picture', 'folder' => 'id_pictures']
    ];

    /**
     * Initialize Document Service
     * 
     * @param resource|null $dbConnection Database connection (optional, will use global if null)
     */
    public function __construct($dbConnection = null)
    {
        $this->setConnection($dbConnection);
        $this->initializePathConfig();
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
                $this->baseDir = $this->pathConfig->getUploadsDir();
                error_log("DocumentService: Using FilePathConfig");
            }
        }

        // Fallback to simple environment detection
        if (!$this->pathConfig) {
            $this->baseDir = file_exists('/mnt/assets/uploads/') 
                ? '/mnt/assets/uploads/' 
                : dirname(__DIR__) . '/../../assets/uploads/';
            error_log("DocumentService: Using fallback path: " . $this->baseDir);
        }
    }

    /**
     * Convert absolute file path to web-accessible relative path
     * 
     * @param string $filePath Absolute file path
     * @return string Relative web path
     */
    private function convertToWebPath($filePath)
    {
        // If pathConfig exists, use it
        if ($this->pathConfig && method_exists($this->pathConfig, 'getRelativePath')) {
            return $this->pathConfig->getRelativePath($filePath);
        }

        // Fallback: simple relative path conversion
        $baseDir = realpath($this->baseDir);
        if (strpos($filePath, $baseDir) === 0) {
            $relativePath = str_replace($baseDir, '', $filePath);
            return 'assets/uploads' . $relativePath;
        }

        return $filePath;
    }

    /**
     * Log action to audit_logs table
     * 
     * @param int|null $userId User ID
     * @param string $userType User type (student, admin)
     * @param string $username Username
     * @param string $eventType Event type
     * @param string $eventCategory Event category
     * @param string $description Description
     * @param array|null $metadata Additional metadata
     * @param string $status Status (success, failed, etc.)
     */
    private function logAudit($userId, $userType, $username, $eventType, $eventCategory, $description, $metadata = null, $status = 'success')
    {
        try {
            $query = "INSERT INTO audit_logs (
                user_id, user_type, username, event_type, event_category,
                action_description, status, ip_address, user_agent,
                affected_table, metadata
            ) VALUES (
                $1, $2, $3, $4, $5, $6, $7, $8, $9, 'documents', $10
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
        } catch (Exception $e) {
            error_log("Audit log failed: " . $e->getMessage());
        }
    }

    /**
     * Save document to database after upload during registration
     * 
     * @param string $studentId Student ID
     * @param string $docTypeName Document type name
     * @param string $filePath Full file path
     * @param array $ocrData OCR and verification results
     * @return array Result array with keys: success, document_id, message, error
     */
    public function saveDocument($studentId, $docTypeName, $filePath, $ocrData = [])
    {
        try {
            if (!isset(self::DOCUMENT_TYPES[$docTypeName])) {
                throw new Exception("Invalid document type: $docTypeName");
            }

            $docInfo = self::DOCUMENT_TYPES[$docTypeName];
            $currentYear = date('Y');

            // Generate document ID
            $documentId = $this->generateDocumentId($studentId, $docInfo['code'], $currentYear);

            // Convert absolute path to web-accessible relative path for storage
            $webPath = $this->convertToWebPath($filePath);

            // Extract file information
            $fileName = basename($filePath);
            $fileExtension = pathinfo($filePath, PATHINFO_EXTENSION);
            $fileSize = file_exists($filePath) ? filesize($filePath) : 0;

            // Extract verification data
            $ocrConfidence = $ocrData['ocr_confidence'] ?? 0;
            $verificationScore = $ocrData['verification_score'] ?? 0;
            $verificationStatus = $ocrData['verification_status'] ?? 'pending';

            // Debug logging
            error_log("DocumentService::saveDocument - DocType: {$docTypeName}, OCR: {$ocrConfidence}%, Verification: {$verificationScore}%");
            error_log("DocumentService::saveDocument - Storing web path: {$webPath}");

            // Prepare verification details JSONB
            $verificationDetails = null;
            if (isset($ocrData['verification_details'])) {
                $verificationDetails = json_encode($ocrData['verification_details']);
            }

            // Insert into documents table
            $query = "INSERT INTO documents (
                document_id,
                student_id,
                document_type_code,
                document_type_name,
                file_path,
                file_name,
                file_extension,
                file_size_bytes,
                ocr_confidence,
                verification_score,
                verification_status,
                verification_details,
                status,
                upload_year
            ) VALUES (
                $1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, 'temp', $13
            )
            ON CONFLICT (document_id) 
            DO UPDATE SET
                file_path = EXCLUDED.file_path,
                file_name = EXCLUDED.file_name,
                file_size_bytes = EXCLUDED.file_size_bytes,
                ocr_confidence = EXCLUDED.ocr_confidence,
                verification_score = EXCLUDED.verification_score,
                verification_status = EXCLUDED.verification_status,
                verification_details = EXCLUDED.verification_details,
                last_modified = NOW()";

            $this->executeQuery($query, [
                $documentId,
                $studentId,
                $docInfo['code'],
                $docInfo['name'],
                $webPath,
                $fileName,
                $fileExtension,
                $fileSize,
                $ocrConfidence,
                $verificationScore,
                $verificationStatus,
                $verificationDetails,
                $currentYear
            ]);

            // Log to audit trail
            $this->logAudit(
                null,
                'student',
                $studentId,
                'document_uploaded',
                'applicant_management',
                "Document uploaded: {$docInfo['name']}",
                [
                    'document_id' => $documentId,
                    'document_type' => $docTypeName,
                    'file_name' => $fileName,
                    'ocr_confidence' => $ocrConfidence,
                    'verification_score' => $verificationScore,
                    'verification_status' => $verificationStatus
                ]
            );

            return [
                'success' => true,
                'document_id' => $documentId,
                'message' => 'Document saved successfully'
            ];

        } catch (Exception $e) {
            error_log("DocumentService::saveDocument error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Generate standardized document ID
     * Format: STUDENTID-DOCU-YEAR-TYPE
     * 
     * @param string $studentId Student ID
     * @param string $typeCode Document type code
     * @param string $year Year
     * @return string Document ID
     */
    private function generateDocumentId($studentId, $typeCode, $year)
    {
        return "{$studentId}-DOCU-{$year}-{$typeCode}";
    }

    /**
     * Move documents from temp to permanent storage after approval
     * 
     * @param string $studentId Student ID
     * @return array Result array with keys: success, moved_count, errors
     */
    public function moveToPermStorage($studentId)
    {
        try {
            // Get all temp documents for this student
            $query = "SELECT * FROM documents WHERE student_id = $1 AND status = 'temp'";
            $result = $this->executeQuery($query, [$studentId]);

            $movedCount = 0;
            $errors = [];

            while ($doc = $this->fetchOne($result)) {
                $oldPath = $doc['file_path'];

                if (!file_exists($oldPath)) {
                    $errors[] = "File not found: {$oldPath}";
                    continue;
                }

                // Build student-organized path
                if (preg_match('#/temp/([^/]+)/([^/]+)$#', $oldPath, $matches)) {
                    $docTypeFolder = $matches[1];
                    $originalFilename = $matches[2];

                    // Generate timestamped filename to prevent overwrites
                    $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);
                    $baseName = pathinfo($originalFilename, PATHINFO_FILENAME);
                    $timestamp = date('YmdHis');
                    $newFilename = $baseName . '_' . $timestamp . '.' . $extension;

                    // Create student-specific folder
                    $targetDir = dirname(dirname(dirname($oldPath))) . '/student/' . $docTypeFolder . '/' . $studentId . '/';
                    $newPath = $targetDir . $newFilename;

                    error_log("DocumentService::moveToPermStorage - Moving to student folder: {$newPath}");
                } else {
                    // Fallback to old behavior
                    $newPath = str_replace('/temp/', '/student/', $oldPath);
                    $targetDir = dirname($newPath);
                    error_log("DocumentService::moveToPermStorage - Using fallback path: {$newPath}");
                }

                // Ensure target directory exists
                if (!is_dir($targetDir)) {
                    mkdir($targetDir, 0755, true);
                    error_log("DocumentService::moveToPermStorage - Created directory: {$targetDir}");
                }

                // Move main file
                if (!rename($oldPath, $newPath)) {
                    $errors[] = "Failed to move: {$oldPath}";
                    continue;
                }

                // Move associated OCR files with timestamped naming
                if (isset($baseName) && isset($timestamp) && isset($targetDir)) {
                    $this->moveAssociatedFilesWithTimestamp($oldPath, $targetDir, $baseName, $timestamp);
                } else {
                    $this->moveAssociatedFiles($oldPath, $newPath);
                }

                // Convert new path to web-accessible relative path
                $webPath = $this->convertToWebPath($newPath);

                // Update database
                $updateQuery = "UPDATE documents 
                               SET file_path = $1, 
                                   status = 'approved',
                                   approved_date = NOW(),
                                   last_modified = NOW()
                               WHERE document_id = $2";

                $this->executeQuery($updateQuery, [
                    $webPath,
                    $doc['document_id']
                ]);

                $movedCount++;
            }

            // Log approval to audit trail
            if ($movedCount > 0 && isset($_SESSION['admin_id'])) {
                $this->logAudit(
                    $_SESSION['admin_id'],
                    'admin',
                    $_SESSION['admin_username'] ?? 'admin',
                    'applicant_approved',
                    'applicant_management',
                    "Student documents approved and moved to permanent storage",
                    [
                        'student_id' => $studentId,
                        'documents_moved' => $movedCount,
                        'errors' => count($errors)
                    ]
                );
            }

            return [
                'success' => true,
                'moved_count' => $movedCount,
                'errors' => $errors
            ];

        } catch (Exception $e) {
            error_log("DocumentService::moveToPermStorage error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'errors' => []
            ];
        }
    }

    /**
     * Move associated OCR files with timestamped naming
     * 
     * @param string $oldPath Original file path
     * @param string $targetDir Target directory
     * @param string $baseName Base filename
     * @param string $timestamp Timestamp string
     */
    private function moveAssociatedFilesWithTimestamp($oldPath, $targetDir, $baseName, $timestamp)
    {
        $extensions = ['.ocr.txt', '.verify.json', '.tsv', '.confidence.json'];

        foreach ($extensions as $ext) {
            $oldFile = $oldPath . $ext;
            $newFile = $targetDir . $baseName . '_' . $timestamp . $ext;

            if (file_exists($oldFile)) {
                if (@rename($oldFile, $newFile)) {
                    error_log("DocumentService: Moved associated file: {$ext}");
                } else {
                    error_log("DocumentService: Failed to move associated file: {$ext}");
                }
            }
        }
    }

    /**
     * Move associated OCR files (legacy method)
     * 
     * @param string $oldPath Original file path
     * @param string $newPath New file path
     */
    private function moveAssociatedFiles($oldPath, $newPath)
    {
        $extensions = ['.ocr.txt', '.verify.json', '.tsv', '.confidence.json'];

        foreach ($extensions as $ext) {
            $oldFile = $oldPath . $ext;
            $newFile = $newPath . $ext;

            if (file_exists($oldFile)) {
                @rename($oldFile, $newFile);
            }
        }
    }

    /**
     * Get document with full validation data for admin view
     * 
     * @param string $studentId Student ID
     * @param string $docTypeName Document type name
     * @return array Result array with keys: success, validation, message, error
     */
    public function getDocumentWithValidation($studentId, $docTypeName)
    {
        try {
            if (!isset(self::DOCUMENT_TYPES[$docTypeName])) {
                return ['success' => false, 'message' => 'Invalid document type'];
            }

            $docInfo = self::DOCUMENT_TYPES[$docTypeName];

            // Get document from database
            $query = "SELECT * FROM documents 
                      WHERE student_id = $1 AND document_type_name = $2 
                      ORDER BY upload_date DESC 
                      LIMIT 1";

            $result = $this->executeQuery($query, [$studentId, $docTypeName]);

            if ($this->getRowCount($result) === 0) {
                return ['success' => false, 'message' => 'Document not found'];
            }

            $doc = $this->fetchOne($result);

            // Parse verification details from JSONB
            if ($doc['verification_details']) {
                $verificationData = json_decode($doc['verification_details'], true);
                $doc['identity_verification'] = $verificationData;

                // Extract grades from verification_details
                if (isset($verificationData['extracted_grades'])) {
                    $doc['extracted_grades_array'] = $verificationData['extracted_grades'];
                }
            }

            return [
                'success' => true,
                'validation' => $doc
            ];

        } catch (Exception $e) {
            error_log("getDocumentWithValidation error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get all documents for a student
     * 
     * @param string $studentId Student ID
     * @return array Result array with keys: success, documents, error
     */
    public function getStudentDocuments($studentId)
    {
        try {
            $query = "SELECT * FROM documents 
                      WHERE student_id = $1 
                      ORDER BY document_type_code, upload_date DESC";

            $result = $this->executeQuery($query, [$studentId]);

            $documents = [];
            while ($doc = $this->fetchOne($result)) {
                $documents[] = $doc;
            }

            return [
                'success' => true,
                'documents' => $documents
            ];

        } catch (Exception $e) {
            error_log("getStudentDocuments error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Delete document and associated files
     * 
     * @param string $documentId Document ID
     * @return array Result array with keys: success, error
     */
    public function deleteDocument($documentId)
    {
        try {
            // Get file path first
            $query = "SELECT * FROM documents WHERE document_id = $1";
            $result = $this->executeQuery($query, [$documentId]);

            if ($this->getRowCount($result) > 0) {
                $doc = $this->fetchOne($result);
                $filePath = $doc['file_path'];

                // Delete physical files
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
                if (file_exists($filePath . '.ocr.txt')) {
                    unlink($filePath . '.ocr.txt');
                }
                if (file_exists($filePath . '.verify.json')) {
                    unlink($filePath . '.verify.json');
                }

                // Delete from database
                $this->executeQuery(
                    "DELETE FROM documents WHERE document_id = $1",
                    [$documentId]
                );
            }

            return ['success' => true];

        } catch (Exception $e) {
            error_log("deleteDocument error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
