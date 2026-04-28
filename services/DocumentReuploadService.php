<?php
/**
 * DocumentReuploadService - Handles document re-upload for rejected applicants
 * 
 * Key differences from registration:
 * - Uploads directly to permanent storage (skips temp folder)
 * - Uses DocumentService for database operations
 * - Minimal OCR processing (only for grades)
 */

class DocumentReuploadService {
    private $db;
    private $baseDir;
    private $docService;
    private $pathConfig;
    
    const DOCUMENT_TYPES = [
        '04' => ['name' => 'id_picture', 'folder' => 'id_pictures'],
        '00' => ['name' => 'eaf', 'folder' => 'enrollment_forms'],
        '01' => ['name' => 'academic_grades', 'folder' => 'grades'],
        '02' => ['name' => 'letter_to_mayor', 'folder' => 'letter_to_mayor'],
        '03' => ['name' => 'certificate_of_indigency', 'folder' => 'indigency']
    ];

    private function buildOcrService(array $overrides = []) {
        if (!defined('TESSERACT_PATH')) {
            $configPath = __DIR__ . '/../config/ocr_config.php';
            if (file_exists($configPath)) {
                require_once $configPath;
            }
        }

        $config = [
            'tesseract_path' => defined('TESSERACT_PATH') ? TESSERACT_PATH : 'tesseract',
            'temp_dir' => dirname(__DIR__) . '/temp',
            'max_file_size' => 10 * 1024 * 1024,
            'allowed_extensions' => ['pdf', 'png', 'jpg', 'jpeg', 'tiff', 'bmp']
        ];

        require_once __DIR__ . '/../bootstrap_services.php';
        return new OCRProcessingService(array_merge($config, $overrides));
    }
    
    public function __construct($dbConnection) {
        $this->db = $dbConnection;
        
        // Initialize FilePathConfig for path management
        require_once __DIR__ . '/../config/FilePathConfig.php';
        $this->pathConfig = FilePathConfig::getInstance();
        $this->baseDir = $this->pathConfig->getUploadsDir();
        
        // Initialize DocumentService for database operations
        require_once __DIR__ . '/../bootstrap_services.php';
        $this->docService = new DocumentService($dbConnection);
    }
    
    
    /**
     * STAGE 1: Upload document to TEMPORARY storage for preview (like registration)
     * User can see OCR results before confirming
     */
    public function uploadToTemp($studentId, $docTypeCode, $tmpPath, $originalName, $studentData = []) {
        try {
            // Validate document type
            if (!isset(self::DOCUMENT_TYPES[$docTypeCode])) {
                return ['success' => false, 'message' => 'Invalid document type'];
            }
            
            $docInfo = self::DOCUMENT_TYPES[$docTypeCode];
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            
            // Validate file extension
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'pdf'];
            if (!in_array($extension, $allowedExtensions)) {
                return ['success' => false, 'message' => 'Invalid file type. Only JPG, PNG, and PDF allowed.'];
            }
            
            // Generate filename: STUDENTID_doctype_timestamp.ext
            $timestamp = time();
            $newFilename = "{$studentId}_{$docInfo['name']}_{$timestamp}.{$extension}";
            
            // Create TEMP path using FilePathConfig
            $tempFolder = $this->pathConfig->getTempPath($docInfo['folder']);
            if (!is_dir($tempFolder)) {
                mkdir($tempFolder, 0755, true);
            }
            
            $tempPath = $tempFolder . DIRECTORY_SEPARATOR . $newFilename;
            
            // Move file to TEMP storage
            if (!move_uploaded_file($tmpPath, $tempPath)) {
                return ['success' => false, 'message' => 'Failed to move uploaded file'];
            }
            
            error_log("DocumentReuploadService: File uploaded to TEMP: $tempPath");
            
            // DO NOT process OCR automatically - let user trigger it manually
            // This prevents creating null JSON files before OCR button is clicked
            $ocrData = [
                'ocr_confidence' => 0,
                'verification_score' => 0,
                'verification_status' => 'pending',
                'verification_details' => null
            ];
            
            return [
                'success' => true,
                'message' => 'Document uploaded to preview',
                'temp_path' => $tempPath,
                'filename' => $newFilename,
                'ocr_confidence' => 0,
                'verification_score' => 0,
                'verification_status' => 'pending'
            ];
            
        } catch (Exception $e) {
            error_log("DocumentReuploadService::uploadToTemp error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Upload failed: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * STAGE 2: Confirm upload - Move from TEMP to PERMANENT storage (like registration)
     * Called when user clicks "Confirm Upload" button
     * NEW: Organized by student ID - assets/uploads/student/{doc_type}/{student_id}/
     */
    public function confirmUpload($studentId, $docTypeCode, $tempPath) {
        try {
            // Validate document type
            if (!isset(self::DOCUMENT_TYPES[$docTypeCode])) {
                return ['success' => false, 'message' => 'Invalid document type'];
            }
            
            // Validate temp file exists
            if (!file_exists($tempPath)) {
                return ['success' => false, 'message' => 'Temporary file not found. Please upload again.'];
            }
            
            $docInfo = self::DOCUMENT_TYPES[$docTypeCode];
            
            // Create organized permanent directory structure using FilePathConfig
            // Pattern: assets/uploads/student/{doc_type}/{student_id}/
            $permanentFolder = $this->pathConfig->getStudentPath($docInfo['folder']) . DIRECTORY_SEPARATOR . $studentId . DIRECTORY_SEPARATOR;
            if (!is_dir($permanentFolder)) {
                mkdir($permanentFolder, 0755, true);
                error_log("DocumentReuploadService: Created student folder: $permanentFolder");
            }
            
            // DELETE ALL EXISTING FILES for this student and document type to prevent duplicates
            // This ensures clean re-upload without accumulating old versions
            $existingFiles = glob($permanentFolder . $studentId . '_' . $docInfo['name'] . '_*');
            if (!empty($existingFiles)) {
                foreach ($existingFiles as $oldFile) {
                    if (is_file($oldFile)) {
                        @unlink($oldFile);
                        error_log("DocumentReuploadService: Deleted old file during reupload: " . basename($oldFile));
                    }
                }
                error_log("DocumentReuploadService: Cleaned up " . count($existingFiles) . " old files before new upload");
            }
            
            // Generate unique filename with timestamp to prevent overwrites
            $originalFilename = basename($tempPath);
            $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);
            $baseName = pathinfo($originalFilename, PATHINFO_FILENAME);
            $timestamp = date('YmdHis');
            $uniqueFilename = $baseName . '_' . $timestamp . '.' . $extension;
            
            $permanentPath = $permanentFolder . $uniqueFilename;
            
            // Collect ALL associated OCR files BEFORE moving main file
            // Include .tsv (Tesseract TSV output), .txt (temp OCR outputs), and any other artifacts
            $associatedExtensions = ['.ocr.txt', '.verify.json', '.confidence.json', '.tsv'];
            $tempAssociatedFiles = [];
            foreach ($associatedExtensions as $ext) {
                $tempAssocPath = $tempPath . $ext;
                if (file_exists($tempAssocPath)) {
                    $tempAssociatedFiles[$ext] = $tempAssocPath;
                    error_log("DocumentReuploadService: Found associated file to move: " . basename($tempAssocPath));
                }
            }
            
            // Also check for any temp output files in the same directory (ocr_output_*, etc.)
            $tempDir = dirname($tempPath);
            $baseFilename = pathinfo($tempPath, PATHINFO_FILENAME);
            $tempOutputPattern = $tempDir . '/ocr_output_*';
            $tempOutputFiles = glob($tempOutputPattern);
            foreach ($tempOutputFiles as $tempFile) {
                if (is_file($tempFile)) {
                    $tempAssociatedFiles['_temp_' . basename($tempFile)] = $tempFile;
                }
            }
            
            // Move file from TEMP to PERMANENT (using rename for same filesystem)
            $moveSuccess = @rename($tempPath, $permanentPath);
            if (!$moveSuccess) {
                // Fallback to copy if rename fails (different filesystems)
                if (!copy($tempPath, $permanentPath)) {
                    return ['success' => false, 'message' => 'Failed to move file to permanent storage'];
                }
                @unlink($tempPath); // Delete temp file after successful copy
                error_log("DocumentReuploadService: Copied (not renamed) from TEMP to PERMANENT: $permanentPath");
            } else {
                error_log("DocumentReuploadService: Renamed from TEMP to PERMANENT: $permanentPath");
            }
            
            // Move associated OCR files to permanent location with timestamp naming
            foreach ($tempAssociatedFiles as $ext => $tempAssocPath) {
                // For temp output files, just delete them instead of moving
                if (strpos($ext, '_temp_') === 0) {
                    @unlink($tempAssocPath);
                    error_log("DocumentReuploadService: Deleted temp file: " . basename($tempAssocPath));
                    continue;
                }
                
                // Use same timestamp for associated files
                $permAssocPath = $permanentFolder . $baseName . '_' . $timestamp . $ext;
                $assocMoveSuccess = @rename($tempAssocPath, $permAssocPath);
                if (!$assocMoveSuccess) {
                    // Fallback to copy+delete
                    if (@copy($tempAssocPath, $permAssocPath)) {
                        @unlink($tempAssocPath);
                        error_log("DocumentReuploadService: Copied and deleted associated file: $ext");
                    } else {
                        error_log("DocumentReuploadService: Failed to move associated file: $ext");
                    }
                } else {
                    error_log("DocumentReuploadService: Moved associated file: $ext");
                }
            }
            
            // Read OCR data from .verify.json if exists
            $ocrData = [
                'ocr_confidence' => 0,
                'verification_score' => 0,
                'verification_status' => 'pending',
                'verification_details' => null
            ];
            
            $verifyJsonPath = $permanentFolder . $baseName . '_' . $timestamp . '.verify.json';
            if (file_exists($verifyJsonPath)) {
                $verifyJson = json_decode(file_get_contents($verifyJsonPath), true);
                
                // Handle different verification JSON structures:
                // Grades have ocr_confidence at root, EAF/Letter/Certificate have it in summary
                if (isset($verifyJson['ocr_confidence'])) {
                    // Grades format (has ocr_confidence at root)
                    $ocrData['ocr_confidence'] = $verifyJson['ocr_confidence'];
                    $ocrData['verification_score'] = $verifyJson['verification_score'] ?? $verifyJson['ocr_confidence'];
                } elseif (isset($verifyJson['summary']['average_confidence'])) {
                    // EAF/Letter/Certificate format (has average_confidence in summary)
                    $ocrData['ocr_confidence'] = $verifyJson['summary']['average_confidence'];
                    $ocrData['verification_score'] = $verifyJson['summary']['average_confidence'];
                } else {
                    // Fallback: use 0
                    $ocrData['ocr_confidence'] = 0;
                    $ocrData['verification_score'] = 0;
                }
                
                $ocrData['verification_status'] = $verifyJson['verification_status'] ?? 
                    ($verifyJson['overall_success'] ?? false ? 'passed' : 'manual_review');
                $ocrData['verification_details'] = $verifyJson;
                
                error_log("confirmUpload: Read verification data - Confidence: {$ocrData['ocr_confidence']}%, Status: {$ocrData['verification_status']}");
            }
            
            // DELETE existing database record for this document type to prevent duplicates
            // This ensures only ONE record per student per document type
            $deleteResult = pg_query_params($this->db,
                "DELETE FROM documents WHERE student_id = $1 AND document_type_code = $2",
                [$studentId, $docTypeCode]
            );
            if ($deleteResult) {
                $deletedRows = pg_affected_rows($deleteResult);
                if ($deletedRows > 0) {
                    error_log("DocumentReuploadService: Deleted $deletedRows existing database record(s) for $studentId - $docTypeCode");
                }
            }
            
            // Use DocumentService to save to database
            $saveResult = $this->docService->saveDocument(
                $studentId,
                $docInfo['name'],
                $permanentPath,
                $ocrData
            );
            
            if (!$saveResult['success']) {
                // Cleanup file if database save failed
                @unlink($permanentPath);
                return [
                    'success' => false,
                    'message' => $saveResult['error'] ?? 'Failed to save to database'
                ];
            }
            
            error_log("DocumentReuploadService: Saved to database - " . ($saveResult['document_id'] ?? 'unknown ID'));
            
            // CRITICAL: Mark documents as submitted on first upload
            // This allows the student to see their documents are being processed
            $this->markDocumentsSubmitted($studentId);
            
            // Log audit trail
            $this->logAudit($studentId, $docInfo['name'], $ocrData);
            
            // Check if all rejected documents are uploaded
            $this->checkAndClearRejectionStatus($studentId);
            
            return [
                'success' => true,
                'message' => 'Document uploaded successfully',
                'document_id' => $saveResult['document_id'] ?? null,
                'file_path' => $permanentPath,
                'ocr_confidence' => $ocrData['ocr_confidence'] ?? 0,
                'verification_score' => $ocrData['verification_score'] ?? 0
            ];
            
        } catch (Exception $e) {
            error_log("DocumentReuploadService::confirmUpload error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Confirmation failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Process OCR for a temporary upload and produce artifacts for preview.
     * Uses the SAME proven OCR approach as registration for consistent quality
     */
    public function processTempOcr($studentId, $docTypeCode, $tempPath, $studentData = []) {
        try {
            if (!isset(self::DOCUMENT_TYPES[$docTypeCode])) {
                return ['success' => false, 'message' => 'Invalid document type'];
            }

            if (!file_exists($tempPath)) {
                return ['success' => false, 'message' => 'Temporary file missing. Please re-upload.'];
            }

            $docInfo = self::DOCUMENT_TYPES[$docTypeCode];
            $extension = strtolower(pathinfo($tempPath, PATHINFO_EXTENSION));

            if (!in_array($extension, ['jpg', 'jpeg', 'png', 'pdf', 'tiff', 'bmp'])) {
                return ['success' => false, 'message' => 'Unsupported file type for OCR'];
            }
            
            // CROSS-DOCUMENT TYPE VALIDATION: Quick OCR check to prevent uploading wrong document
            // This helps users catch mistakes early (e.g., uploading certificate instead of grades)
            $quickOcrResult = $this->runDirectTesseractOCR($tempPath, $docTypeCode);
            $quickText = strtolower($quickOcrResult['text'] ?? '');
            
            // Define document type signatures (keywords that strongly indicate document type)
            // IMPROVED: More specific and comprehensive keywords
            $documentSignatures = [
                // Grades: Focus on STRUCTURAL keywords (table headers, grade columns)
                '01' => ['subject', 'final grade', 'prelim', 'midterm', 'finals', 'general average', 'units', 'remarks'],
                // Letter: Focus on letter-specific keywords
                '02' => ['dear mayor', 'honorable mayor', 'mayor ferrer', 'request', 'assistance', 'respectfully', 'sincerely', 'gratitude', 'scholarship'],
                // Certificate: Focus on certification keywords
                '03' => ['certificate of indigency', 'certify', 'indigent', 'barangay captain'],
                '04' => ['student id', 'identification card', 'id number', 'valid until'],
                // Enrollment: Focus on enrollment-specific keywords
                '00' => ['enrollment', 'assessment', 'eaf', 'tuition fee', 'billing statement', 'certificate of registration', 'matriculation']
            ];
            
            // Get expected keywords for THIS document type
            $expectedKeywords = $documentSignatures[$docTypeCode] ?? [];
            
            // Check for cross-document confusion
            foreach ($documentSignatures as $otherDocCode => $keywords) {
                if ($otherDocCode === $docTypeCode) continue; // Skip self
                
                $matchCount = 0;
                $matchedKeywords = [];
                
                foreach ($keywords as $keyword) {
                    if (stripos($quickText, $keyword) !== false) {
                        $matchCount++;
                        $matchedKeywords[] = $keyword;
                    }
                }
                
                // STRICTER MATCHING LOGIC to prevent false positives:
                // 1. Letter vs Grades: Need 6+ grade STRUCTURAL keywords (prevent "I got good grades" from triggering)
                // 2. Enrollment vs Grades: Need 5+ matches
                // 3. Other documents: Need 3+ matches OR 2+ if very specific (15+ chars)
                $isStrongMatch = false;
                
                // Special case: Letter to Mayor vs Grades (HIGH FALSE POSITIVE RISK)
                // Letters often mention "grades", "GPA", "semester" when discussing academic performance
                // Only flag as grades if it has STRUCTURAL keywords (subject, prelim, midterm, finals, units)
                if (($docTypeCode === '02' && $otherDocCode === '01')) {
                    // Need 6+ STRUCTURAL grade keywords (very strict to avoid false positives)
                    // AND must NOT have letter-specific keywords
                    $hasLetterKeywords = stripos($quickText, 'dear mayor') !== false || 
                                        stripos($quickText, 'honorable') !== false ||
                                        stripos($quickText, 'respectfully') !== false ||
                                        stripos($quickText, 'sincerely') !== false ||
                                        stripos($quickText, 'scholarship') !== false;
                    
                    // If it has letter keywords, don't flag as grades (even if it mentions "grades")
                    if ($hasLetterKeywords) {
                        $isStrongMatch = false;
                        error_log("CROSS-DOCUMENT: Letter has grade keywords but also has letter structure - PASS");
                    } else {
                        // Only flag if 6+ structural grade keywords AND no letter keywords
                        $isStrongMatch = ($matchCount >= 6);
                    }
                }
                // Special case: Grades vs Letter (also check)
                elseif (($docTypeCode === '01' && $otherDocCode === '02')) {
                    // Need 5+ letter keywords to flag as letter (not grades)
                    $isStrongMatch = ($matchCount >= 5);
                }
                // Special case: Enrollment form vs Grades (high confusion risk)
                elseif (($docTypeCode === '00' && $otherDocCode === '01') || 
                        ($docTypeCode === '01' && $otherDocCode === '00')) {
                    // Need 5+ keyword matches to flag as wrong document type
                    $isStrongMatch = ($matchCount >= 5);
                } else {
                    // Standard matching for other document types
                    $isStrongMatch = ($matchCount >= 3) || 
                                     ($matchCount >= 2 && strlen($keywords[0]) > 15);
                }
                
                if ($isStrongMatch) {
                    $docTypeNames = [
                        '01' => 'Grades',
                        '02' => 'Letter to Mayor',
                        '03' => 'Certificate of Indigency',
                        '04' => 'ID Picture',
                        '00' => 'Enrollment Form'
                    ];
                    
                    $expectedDocName = $docTypeNames[$docTypeCode] ?? 'this document';
                    $detectedDocName = $docTypeNames[$otherDocCode] ?? 'another document';
                    
                    error_log("CROSS-DOCUMENT CONFUSION: Expected $expectedDocName ($docTypeCode), but detected $detectedDocName ($otherDocCode) with $matchCount keywords: " . implode(', ', $matchedKeywords));
                    
                    return [
                        'success' => false,
                        'message' => "This appears to be a \"$detectedDocName\", not a \"$expectedDocName\". Please upload it in the correct document field."
                    ];
                }
            }

            // Grades keep existing specialised flow
            if ($docTypeCode === '01') {
                $ocrData = $this->processGradesOCR($tempPath, $studentData);

                if (($ocrData['ocr_confidence'] ?? 0) <= 0) {
                    return ['success' => false, 'message' => 'Unable to extract text from grades document'];
                }

                return [
                    'success' => true,
                    'ocr_confidence' => $ocrData['ocr_confidence'] ?? 0,
                    'verification_score' => $ocrData['verification_score'] ?? 0,
                    'verification_status' => $ocrData['verification_status'] ?? 'manual_review'
                ];
            }

            // EAF comprehensive verification (matching registration)
            if ($docTypeCode === '00') {
                return $this->processEafOCR($tempPath, $studentData);
            }

            // Letter to Mayor comprehensive verification
            if ($docTypeCode === '02') {
                return $this->processLetterOCR($tempPath, $studentData);
            }

            // Certificate of Indigency comprehensive verification
            if ($docTypeCode === '03') {
                return $this->processCertificateOCR($tempPath, $studentData);
            }

            // ID Picture comprehensive verification
            if ($docTypeCode === '04') {
                return $this->processIdPictureOCR($tempPath, $studentData);
            }

            // Use DIRECT Tesseract approach (same as registration) for better quality
            $ocrResult = $this->runDirectTesseractOCR($tempPath, $docTypeCode);
            
            if (empty($ocrResult['text'])) {
                return ['success' => false, 'message' => 'No readable text detected'];
            }

            $confidence = $ocrResult['confidence'] ?? 0;
            $status = $confidence >= 75 ? 'passed' : ($confidence >= 50 ? 'manual_review' : 'failed');

            // Persist ALL OCR artifacts beside the temp file (matching registration)
            @file_put_contents($tempPath . '.ocr.txt', $ocrResult['text'] ?? '');

            $verificationPayload = [
                'timestamp' => date('Y-m-d H:i:s'),
                'student_id' => $studentData['student_id'] ?? $studentId,
                'document_type' => $docInfo['name'],
                'ocr_confidence' => $confidence,
                'verification_score' => $confidence,
                'verification_status' => $status,
                'word_count' => str_word_count($ocrResult['text'] ?? ''),
                'ocr_text_preview' => substr($ocrResult['text'] ?? '', 0, 500)
            ];

            @file_put_contents($tempPath . '.verify.json', json_encode($verificationPayload, JSON_PRETTY_PRINT));
            
            // Save confidence.json (separate file for confidence tracking)
            $confidencePayload = [
                'ocr_confidence' => $confidence,
                'verification_score' => $confidence,
                'status' => $status,
                'timestamp' => date('Y-m-d H:i:s')
            ];
            @file_put_contents($tempPath . '.confidence.json', json_encode($confidencePayload, JSON_PRETTY_PRINT));
            
            // Generate TSV output if applicable (for structured OCR data)
            $tsvCmd = "tesseract " . escapeshellarg($tempPath) . " " . escapeshellarg($tempPath) . " -l eng --oem 1 --psm " . $this->getPSMForDocType($docTypeCode) . " tsv 2>&1";
            @shell_exec($tsvCmd);
            // TSV file will be saved as {tempPath}.tsv by Tesseract

            return [
                'success' => true,
                'ocr_confidence' => $confidence,
                'verification_score' => $confidence,
                'verification_status' => $status
            ];

        } catch (Exception $e) {
            error_log('DocumentReuploadService::processTempOcr error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'OCR failed: ' . $e->getMessage()];
        }
    }

    /**
     * Run direct Tesseract OCR with multiple passes (same as registration)
     * This provides much better quality than the generic OCRProcessingService
     */
    private function runDirectTesseractOCR($filePath, $docTypeCode) {
        $result = ['text' => '', 'confidence' => 0];
        
        try {
            // Different PSM modes for different document types
            $psmMode = $this->getPSMForDocType($docTypeCode);
            
            // Primary OCR pass with appropriate PSM
            $cmd = "tesseract " . escapeshellarg($filePath) . " stdout --oem 1 --psm $psmMode -l eng 2>&1";
            $tessOut = @shell_exec($cmd);
            
            if (!empty($tessOut)) {
                $result['text'] = $tessOut;
            }
            
            // Additional passes for better text extraction (like registration does)
            if ($docTypeCode === '04') {
                // ID Picture: Multiple passes for better name extraction
                $passA = @shell_exec("tesseract " . escapeshellarg($filePath) . " stdout -l eng --oem 1 --psm 11 2>&1");
                $passB = @shell_exec("tesseract " . escapeshellarg($filePath) . " stdout -l eng --oem 1 --psm 7 -c tessedit_char_whitelist=ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz,.- 2>&1");
                $result['text'] = trim($result['text'] . "\n" . $passA . "\n" . $passB);
            } elseif ($docTypeCode === '02') {
                // Letter to Mayor: Try multiple passes for better text extraction
                $passA = @shell_exec("tesseract " . escapeshellarg($filePath) . " stdout -l eng --oem 1 --psm 3 2>&1"); // Fully automatic
                $result['text'] = trim($result['text'] . "\n" . $passA);
            }
            
            // Get confidence from TSV (same as registration)
            $tsv = @shell_exec("tesseract " . escapeshellarg($filePath) . " stdout -l eng --oem 1 --psm $psmMode tsv 2>&1");
            if (!empty($tsv)) {
                $lines = explode("\n", $tsv);
                if (count($lines) > 1) {
                    array_shift($lines); // Remove header
                    $sum = 0;
                    $cnt = 0;
                    foreach ($lines as $line) {
                        if (!trim($line)) continue;
                        $cols = explode("\t", $line);
                        if (count($cols) >= 12) {
                            $conf = floatval($cols[10] ?? 0);
                            if ($conf > 0) {
                                $sum += $conf;
                                $cnt++;
                            }
                        }
                    }
                    if ($cnt > 0) {
                        $result['confidence'] = round($sum / $cnt, 2);
                    }
                }
            }
            
            error_log("DirectTesseractOCR for $docTypeCode: " . strlen($result['text']) . " chars, confidence: " . $result['confidence']);
            
        } catch (Exception $e) {
            error_log("DirectTesseractOCR error: " . $e->getMessage());
        }
        
        return $result;
    }
    
    /**
     * Get optimal PSM mode for each document type
     */
    private function getPSMForDocType($docTypeCode) {
        switch ($docTypeCode) {
            case '04': // ID Picture
                return 6; // Uniform block of text
            case '00': // EAF
                return 6; // Uniform block of text
            case '02': // Letter to Mayor
                return 4; // Single column text
            case '03': // Certificate of Indigency
                return 6; // Uniform block of text
            default:
                return 6; // Default
        }
    }

    private function getOcrOptionsForType($docTypeCode) {
        // This function is now deprecated - using direct Tesseract instead
        // Kept for backwards compatibility
        switch ($docTypeCode) {
            case '04':
                return ['psm' => 6];
            case '02':
                return ['psm' => 4];
            case '03':
                return ['psm' => 6];
            case '00':
                return ['psm' => 6];
            default:
                return ['psm' => 6];
        }
    }
    
    /**
     * Cancel preview - Delete temp file
     */
    public function cancelPreview($tempPath) {
        try {
            $deleted = [];
            $failed = [];
            
            // Delete main file
            if (file_exists($tempPath)) {
                if (@unlink($tempPath)) {
                    $deleted[] = basename($tempPath);
                } else {
                    $failed[] = basename($tempPath);
                }
            }
            
            // Delete all associated OCR/verification files
            $associatedFiles = [
                '.ocr.txt',
                '.verify.json', 
                '.confidence.json',
                '.preprocessed.png',
                '.preprocessed.jpg',
                '.tsv'  // Tesseract output
            ];
            
            foreach ($associatedFiles as $ext) {
                $fullPath = $tempPath . $ext;
                if (file_exists($fullPath)) {
                    if (@unlink($fullPath)) {
                        $deleted[] = basename($fullPath);
                    } else {
                        $failed[] = basename($fullPath);
                    }
                }
            }
            
            // Also check for files without extension prefix (e.g., filename.tsv)
            $basePath = pathinfo($tempPath, PATHINFO_DIRNAME);
            $baseFilename = pathinfo($tempPath, PATHINFO_FILENAME);
            $tsvPath = $basePath . '/' . $baseFilename . '.tsv';
            if (file_exists($tsvPath)) {
                if (@unlink($tsvPath)) {
                    $deleted[] = basename($tsvPath);
                } else {
                    $failed[] = basename($tsvPath);
                }
            }
            
            error_log("CancelPreview - Deleted: " . implode(', ', $deleted) . 
                     ($failed ? " | Failed: " . implode(', ', $failed) : ''));
            
            return [
                'success' => true, 
                'message' => 'Preview cancelled',
                'deleted' => $deleted,
                'failed' => $failed
            ];
            
        } catch (Exception $e) {
            error_log("DocumentReuploadService::cancelPreview error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to cancel preview: ' . $e->getMessage()];
        }
    }
    
    /**
     * Process OCR for grades document only (minimal processing)
     */
    /**
     * Process grades OCR using the SAME comprehensive approach as registration
     * This includes validation against grading systems, year level verification, etc.
     */
    private function processGradesOCR($filePath, $studentData) {
        $ocrData = [
            'ocr_confidence' => 0,
            'verification_score' => 0,
            'verification_status' => 'pending',
            'verification_details' => null
        ];
        
        try {
            $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            $ocrText = '';
            $uploadDir = dirname($filePath) . '/';
            
            // STEP 1: Extract text from document (PDF or Image)
            if ($extension === 'pdf') {
                // Try pdftotext first
                $pdfTextCommand = "pdftotext " . escapeshellarg($filePath) . " - 2>nul";
                $pdfText = @shell_exec($pdfTextCommand);
                if (!empty(trim($pdfText))) {
                    $ocrText = $pdfText;
                } else {
                    // Fallback PDF extraction
                    $pdfContent = @file_get_contents($filePath);
                    if ($pdfContent !== false) {
                        preg_match_all('/\(([^)]+)\)/', $pdfContent, $matches);
                        if (!empty($matches[1])) {
                            $extractedText = implode(' ', $matches[1]);
                            $extractedText = preg_replace('/[^\x20-\x7E]/', ' ', $extractedText);
                            $extractedText = preg_replace('/\s+/', ' ', trim($extractedText));
                            if (strlen($extractedText) > 10) {
                                $ocrText = $extractedText;
                            }
                        }
                    }
                }
            } else {
                // Image processing with Tesseract (multiple PSM modes for best results)
                $psmModes = [6, 4, 7, 8, 3]; // Different page segmentation modes
                $success = false;
                $successPsm = 6;
                
                foreach ($psmModes as $psm) {
                    $cmd = "tesseract " . escapeshellarg($filePath) . " stdout --oem 1 --psm $psm -l eng 2>&1";
                    $tesseractOutput = @shell_exec($cmd);
                    
                    if (!empty(trim($tesseractOutput)) && strlen(trim($tesseractOutput)) > 10) {
                        $ocrText = $tesseractOutput;
                        $success = true;
                        $successPsm = $psm;
                        break;
                    }
                }
                
                if (!$success) {
                    error_log("Grades OCR: Failed to extract text from image");
                    return $ocrData;
                }
                
                // Generate TSV data with the successful PSM mode
                $tsvOutputBase = $uploadDir . pathinfo($filePath, PATHINFO_FILENAME);
                $tsvCmd = "tesseract " . escapeshellarg($filePath) . " " . 
                         escapeshellarg($tsvOutputBase) . " -l eng --oem 1 --psm $successPsm tsv 2>&1";
                @shell_exec($tsvCmd);
                
                // Move TSV file to permanent location
                $generatedTsvFile = $tsvOutputBase . '.tsv';
                $permanentTsvFile = $filePath . '.tsv';
                if (file_exists($generatedTsvFile)) {
                    @rename($generatedTsvFile, $permanentTsvFile);
                }
            }
            
            if (empty(trim($ocrText))) {
                error_log("Grades OCR: No text extracted");
                return $ocrData;
            }
            
            // STEP 2: Get student information for validation
            $studentId = $studentData['student_id'] ?? null;
            $firstName = '';
            $lastName = '';
            $yearLevelId = 0;
            $universityId = 0;
            $declaredYearName = '';
            $declaredUniversityName = '';
            $universityCode = '';
            
            if ($studentId) {
                $studentQuery = pg_query_params($this->db,
                    "SELECT s.first_name, s.last_name, s.year_level_id, s.university_id,
                            yl.name as year_level_name, u.name as university_name, u.code as university_code
                     FROM students s
                     LEFT JOIN year_levels yl ON s.year_level_id = yl.year_level_id
                     LEFT JOIN universities u ON s.university_id = u.university_id
                     WHERE s.student_id = $1",
                    [$studentId]
                );
                
                if ($studentQuery && pg_num_rows($studentQuery) > 0) {
                    $student = pg_fetch_assoc($studentQuery);
                    $firstName = $student['first_name'] ?? '';
                    $lastName = $student['last_name'] ?? '';
                    $yearLevelId = $student['year_level_id'] ?? 0;
                    $universityId = $student['university_id'] ?? 0;
                    $declaredYearName = $student['year_level_name'] ?? '';
                    $declaredUniversityName = $student['university_name'] ?? '';
                    $universityCode = $student['university_code'] ?? '';
                }
            }
            
            // STEP 3: Get admin-specified semester and school year
            $adminSemester = '';
            $adminSchoolYear = '';
            
            $configRes = pg_query($this->db, "SELECT key, value FROM config WHERE key IN ('valid_semester', 'valid_school_year')");
            while ($configRow = pg_fetch_assoc($configRes)) {
                if ($configRow['key'] === 'valid_semester') {
                    $adminSemester = $configRow['value'];
                } elseif ($configRow['key'] === 'valid_school_year') {
                    $adminSchoolYear = $configRow['value'];
                }
            }
            
            // STEP 4: Perform comprehensive validation (same as registration)
            $ocrTextNormalized = strtolower($ocrText);
            
            // Include the validation functions from registration
            require_once __DIR__ . '/../modules/student/grade_validation_functions.php';
            
            // Year level validation
            $yearValidationResult = validateDeclaredYear($ocrText, $declaredYearName, $adminSemester);
            $yearLevelMatch = $yearValidationResult['match'];
            $yearLevelSection = $yearValidationResult['section'];
            $yearLevelConfidence = $yearValidationResult['confidence'];
            
            if (!$yearLevelMatch) {
                $yearLevelSection = '';
            }
            
            // Semester validation
            $semesterValidationResult = validateAdminSemester($ocrText, $adminSemester);
            $semesterMatch = $semesterValidationResult['match'];
            $semesterConfidence = $semesterValidationResult['confidence'];
            $foundSemesterText = $semesterValidationResult['found_text'];
            
            // School year validation (temporarily disabled as in registration)
            $schoolYearMatch = true;
            $schoolYearConfidence = 100;
            $foundSchoolYearText = 'Temporarily disabled for testing';
            
            // Construct TSV file path
            $tsvFilePath = $filePath . '.tsv';
            if (!file_exists($tsvFilePath)) {
                // Try without extension prefix
                $pathInfo = pathinfo($filePath);
                $tsvFilePath = $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '.tsv';
            }
            
            // Prepare student data for security validation
            $studentValidationData = [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'university_name' => $declaredUniversityName
            ];
            
            // Grade threshold validation - PASS TSV FILE PATH and STUDENT DATA for accurate parsing and security checks
            $gradeValidationResult = validateGradeThreshold(
                $yearLevelSection, 
                $declaredYearName, 
                false, 
                $adminSemester,
                file_exists($tsvFilePath) ? $tsvFilePath : null,  // Pass TSV path if exists
                $studentValidationData  // Pass student data for security validation
            );
            
            // Check for security failures (fraudulent transcripts)
            if (isset($gradeValidationResult['security_failure'])) {
                error_log("SECURITY ALERT: Grades validation failed - " . ($gradeValidationResult['error'] ?? 'Unknown security issue'));
                error_log("Student ID: $studentId");
                error_log("Security failure type: " . $gradeValidationResult['security_failure']);
                
                // Return error - do not accept the document
                return [
                    'ocr_confidence' => 0,
                    'verification_score' => 0,
                    'verification_status' => 'failed',
                    'verification_details' => null,
                    'error' => $gradeValidationResult['error'],
                    'security_alert' => true
                ];
            }
            
            $legacyAllGradesPassing = $gradeValidationResult['all_passing'];
            $legacyValidGrades = $gradeValidationResult['grades'];
            $legacyFailingGrades = $gradeValidationResult['failing_grades'];
            
            // Enhanced per-subject grade validation
            $allGradesPassing = $legacyAllGradesPassing;
            $validGrades = $legacyValidGrades;
            $failingGrades = $legacyFailingGrades;
            $enhancedGradeResult = null;
            
            if (!empty($universityCode) && !empty($legacyValidGrades)) {
                $subjectsForValidation = array_map(function($grade) {
                    $s = [
                        'name' => $grade['subject'],
                        'rawGrade' => $grade['grade'],
                        'confidence' => 95
                    ];
                    if (isset($grade['prelim'])) $s['prelim'] = $grade['prelim'];
                    if (isset($grade['midterm'])) $s['midterm'] = $grade['midterm'];
                    if (isset($grade['final'])) $s['final'] = $grade['final'];
                    $s['grade'] = $grade['grade'] ?? ($s['final'] ?? $s['midterm'] ?? $s['prelim'] ?? null);
                    return $s;
                }, $legacyValidGrades);
                
                $enhancedGradeResult = validatePerSubjectGrades($universityCode, null, $subjectsForValidation);
                
                if ($enhancedGradeResult['success']) {
                    $allGradesPassing = $enhancedGradeResult['eligible'];
                    $failingGrades = array_map(function($failedSubject) {
                        $parts = explode(':', $failedSubject, 2);
                        return [
                            'subject' => trim($parts[0] ?? ''),
                            'grade' => trim($parts[1] ?? '')
                        ];
                    }, $enhancedGradeResult['failed_subjects']);
                }
            }
            
            // University verification
            $universityValidationResult = validateUniversity($ocrText, $declaredUniversityName);
            $universityMatch = $universityValidationResult['matched'];
            $universityConfidence = $universityValidationResult['confidence'];
            $foundUniversityText = $universityValidationResult['found_text'];
            
            // Name verification
            $nameMatch = validateStudentName($ocrText, $firstName, $lastName);
            
            // Eligibility decision
            $isEligible = ($yearLevelMatch && $semesterMatch && $schoolYearMatch && $allGradesPassing && $universityMatch && $nameMatch);
            
            // Calculate average confidence
            $confidenceValues = [
                $yearLevelConfidence,
                $semesterConfidence,
                $schoolYearConfidence,
                $universityConfidence,
                $nameMatch ? 95 : 0,
                !empty($validGrades) ? 90 : 0
            ];
            $averageConfidence = round(array_sum($confidenceValues) / count($confidenceValues), 1);
            
            // Build verification data
            $verification = [
                'year_level_match' => $yearLevelMatch,
                'semester_match' => $semesterMatch,
                'school_year_match' => $schoolYearMatch,
                'university_match' => $universityMatch,
                'name_match' => $nameMatch,
                'all_grades_passing' => $allGradesPassing,
                'is_eligible' => $isEligible,
                'grades' => $validGrades,
                'failing_grades' => $failingGrades,
                'enhanced_grade_validation' => $enhancedGradeResult,
                'university_code' => $universityCode,
                'validation_method' => !empty($universityCode) && $enhancedGradeResult && $enhancedGradeResult['success'] ? 'enhanced_per_subject' : 'legacy_threshold',
                'confidence_scores' => [
                    'year_level' => $yearLevelConfidence,
                    'semester' => $semesterConfidence,
                    'school_year' => $schoolYearConfidence,
                    'university' => $universityConfidence,
                    'name' => $nameMatch ? 95 : 0,
                    'grades' => !empty($validGrades) ? 90 : 0
                ],
                'found_text_snippets' => [
                    'year_level' => $declaredYearName,
                    'semester' => $foundSemesterText,
                    'school_year' => $foundSchoolYearText,
                    'university' => $foundUniversityText
                ],
                'admin_requirements' => [
                    'required_semester' => $adminSemester,
                    'required_school_year' => $adminSchoolYear
                ],
                'overall_success' => $isEligible,
                'summary' => [
                    'passed_checks' => 
                        ($yearLevelMatch ? 1 : 0) + 
                        ($semesterMatch ? 1 : 0) + 
                        ($schoolYearMatch ? 1 : 0) + 
                        ($universityMatch ? 1 : 0) + 
                        ($nameMatch ? 1 : 0) + 
                        ($allGradesPassing ? 1 : 0),
                    'total_checks' => 6,
                    'eligibility_status' => $isEligible ? 'ELIGIBLE' : 'INELIGIBLE',
                    'recommendation' => $isEligible ? 
                        'All validations passed - Student is eligible' : 
                        'Validation failed - Student is not eligible',
                    'average_confidence' => $averageConfidence
                ],
                'timestamp' => date('Y-m-d H:i:s'),
                'student_id' => $studentId,
                'document_type' => 'academic_grades'
            ];
            
            // Save OCR text
            @file_put_contents($filePath . '.ocr.txt', $ocrText);
            
            // Add root-level confidence values for easier parsing in confirmUpload
            $verification['ocr_confidence'] = $averageConfidence;
            $verification['verification_score'] = $averageConfidence;
            $verification['verification_status'] = $isEligible ? 'passed' : ($averageConfidence >= 50 ? 'manual_review' : 'failed');
            
            // Save verification JSON
            @file_put_contents($filePath . '.verify.json', json_encode($verification, JSON_PRETTY_PRINT));
            
            // Save confidence file
            @file_put_contents($filePath . '.confidence.json', json_encode([
                'overall_confidence' => $averageConfidence,
                'ocr_confidence' => $averageConfidence,
                'detailed_scores' => $verification['confidence_scores'],
                'extracted_grades' => $validGrades,
                'passing_status' => $allGradesPassing,
                'eligibility_status' => $verification['summary']['eligibility_status'],
                'timestamp' => time()
            ]));
            
            // Update return data
            $ocrData['ocr_confidence'] = $averageConfidence;
            $ocrData['verification_score'] = $averageConfidence;
            $ocrData['verification_status'] = $isEligible ? 'passed' : ($averageConfidence >= 50 ? 'manual_review' : 'failed');
            $ocrData['verification_details'] = $verification;
            $ocrData['extracted_grades'] = $validGrades;
            $ocrData['passing_status'] = $allGradesPassing;
            
            error_log("Grades OCR completed: Confidence={$averageConfidence}%, Eligible=" . ($isEligible ? 'YES' : 'NO'));
            
        } catch (Exception $e) {
            error_log("DocumentReuploadService::processGradesOCR error: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
        }
        
        return $ocrData;
    }
    
    /**
     * Process EAF OCR with comprehensive field-level verification (matching registration)
     */
    private function processEafOCR($tempPath, $studentData) {
        $ocrData = [
            'ocr_confidence' => 0,
            'verification_score' => 0,
            'verification_status' => 'failed',
            'extracted_text' => ''
        ];

        try {
            // Run Tesseract OCR with multiple PSM modes
            $ocrResult = $this->runDirectTesseractOCR($tempPath, '00');
            $ocrText = $ocrResult['text'] ?? '';
            
            if (empty(trim($ocrText))) {
                return ['success' => false, 'message' => 'No text extracted from EAF document'];
            }

            $ocrData['extracted_text'] = $ocrText;
            $ocrData['ocr_confidence'] = $ocrResult['confidence'] ?? 0;
            
            // Save OCR text
            @file_put_contents($tempPath . '.ocr.txt', $ocrText);
            
            // Initialize verification structure
            $verification = [
                'first_name_match' => false,
                'middle_name_match' => false,
                'last_name_match' => false,
                'year_level_match' => false,
                'university_match' => false,
                'document_keywords_found' => false,
                'confidence_scores' => [],
                'found_text_snippets' => []
            ];
            
            $ocrTextLower = strtolower($ocrText);
            
            // Helper function for similarity calculation
            $calculateSimilarity = function($needle, $haystack) {
                $needle = strtolower(trim($needle));
                $haystack = strtolower(trim($haystack));
                
                if (stripos($haystack, $needle) !== false) {
                    return 100;
                }
                
                $words = explode(' ', $haystack);
                $maxSimilarity = 0;
                
                foreach ($words as $word) {
                    if (strlen($word) >= 3 && strlen($needle) >= 3) {
                        $similarity = 0;
                        similar_text($needle, $word, $similarity);
                        $maxSimilarity = max($maxSimilarity, $similarity);
                    }
                }
                
                return $maxSimilarity;
            };
            
            // Verify first name
            if (!empty($studentData['first_name'])) {
                $similarity = $calculateSimilarity($studentData['first_name'], $ocrTextLower);
                $verification['confidence_scores']['first_name'] = $similarity;
                
                if ($similarity >= 80) {
                    $verification['first_name_match'] = true;
                    $pattern = '/\b\w*' . preg_quote(substr($studentData['first_name'], 0, 3), '/') . '\w*\b/i';
                    if (preg_match($pattern, $ocrText, $matches)) {
                        $verification['found_text_snippets']['first_name'] = $matches[0];
                    }
                }
            }
            
            // Verify middle name (optional)
            if (empty($studentData['middle_name'])) {
                $verification['middle_name_match'] = true;
                $verification['confidence_scores']['middle_name'] = 100;
            } else {
                $similarity = $calculateSimilarity($studentData['middle_name'], $ocrTextLower);
                $verification['confidence_scores']['middle_name'] = $similarity;
                
                if ($similarity >= 70) {
                    $verification['middle_name_match'] = true;
                    $pattern = '/\b\w*' . preg_quote(substr($studentData['middle_name'], 0, 3), '/') . '\w*\b/i';
                    if (preg_match($pattern, $ocrText, $matches)) {
                        $verification['found_text_snippets']['middle_name'] = $matches[0];
                    }
                }
            }
            
            // Verify last name
            if (!empty($studentData['last_name'])) {
                $similarity = $calculateSimilarity($studentData['last_name'], $ocrTextLower);
                $verification['confidence_scores']['last_name'] = $similarity;
                
                if ($similarity >= 80) {
                    $verification['last_name_match'] = true;
                    $pattern = '/\b\w*' . preg_quote(substr($studentData['last_name'], 0, 3), '/') . '\w*\b/i';
                    if (preg_match($pattern, $ocrText, $matches)) {
                        $verification['found_text_snippets']['last_name'] = $matches[0];
                    }
                }
            }
            
            // Verify year level
            if (!empty($studentData['year_level_name'])) {
                $yearLevelName = $studentData['year_level_name'];
                $selectedYearVariations = [];
                
                if (stripos($yearLevelName, '1st') !== false || stripos($yearLevelName, 'first') !== false) {
                    $selectedYearVariations = ['1st year', 'first year', '1st yr', 'year 1', 'yr 1', 'freshman'];
                } elseif (stripos($yearLevelName, '2nd') !== false || stripos($yearLevelName, 'second') !== false) {
                    $selectedYearVariations = ['2nd year', 'second year', '2nd yr', 'year 2', 'yr 2', 'sophomore'];
                } elseif (stripos($yearLevelName, '3rd') !== false || stripos($yearLevelName, 'third') !== false) {
                    $selectedYearVariations = ['3rd year', 'third year', '3rd yr', 'year 3', 'yr 3', 'junior'];
                } elseif (stripos($yearLevelName, '4th') !== false || stripos($yearLevelName, 'fourth') !== false) {
                    $selectedYearVariations = ['4th year', 'fourth year', '4th yr', 'year 4', 'yr 4', 'senior'];
                } elseif (stripos($yearLevelName, '5th') !== false || stripos($yearLevelName, 'fifth') !== false) {
                    $selectedYearVariations = ['5th year', 'fifth year', '5th yr', 'year 5', 'yr 5'];
                }
                
                foreach ($selectedYearVariations as $variation) {
                    if (stripos($ocrText, $variation) !== false) {
                        $verification['year_level_match'] = true;
                        break;
                    }
                }
            }
            
            // Verify university
            if (!empty($studentData['university_name'])) {
                $universityName = $studentData['university_name'];
                $universityWords = array_filter(explode(' ', strtolower($universityName)));
                $foundWords = 0;
                $totalWords = count($universityWords);
                $foundSnippets = [];
                
                foreach ($universityWords as $word) {
                    if (strlen($word) > 2) {
                        $similarity = $calculateSimilarity($word, $ocrTextLower);
                        if ($similarity >= 70) {
                            $foundWords++;
                            $pattern = '/\b\w*' . preg_quote(substr($word, 0, 3), '/') . '\w*\b/i';
                            if (preg_match($pattern, $ocrText, $matches)) {
                                $foundSnippets[] = $matches[0];
                            }
                        }
                    }
                }
                
                $universityScore = ($foundWords / max($totalWords, 1)) * 100;
                $verification['confidence_scores']['university'] = round($universityScore, 1);
                
                if ($universityScore >= 60 || ($totalWords <= 2 && $foundWords >= 1)) {
                    $verification['university_match'] = true;
                    if (!empty($foundSnippets)) {
                        $verification['found_text_snippets']['university'] = implode(', ', array_unique($foundSnippets));
                    }
                }
            }
            
            // Verify document keywords
            $documentKeywords = [
                'enrollment', 'assessment', 'form', 'official', 'academic', 'student',
                'tuition', 'fees', 'semester', 'registration', 'course', 'subject',
                'grade', 'transcript', 'record', 'university', 'college', 'school',
                'eaf', 'assessment form', 'billing', 'statement', 'certificate'
            ];
            
            $keywordMatches = 0;
            $foundKeywords = [];
            $keywordScore = 0;
            
            foreach ($documentKeywords as $keyword) {
                $similarity = $calculateSimilarity($keyword, $ocrTextLower);
                if ($similarity >= 80) {
                    $keywordMatches++;
                    $foundKeywords[] = $keyword;
                    $keywordScore += $similarity;
                }
            }
            
            $averageKeywordScore = $keywordMatches > 0 ? ($keywordScore / $keywordMatches) : 0;
            $verification['confidence_scores']['document_keywords'] = round($averageKeywordScore, 1);
            
            if ($keywordMatches >= 3) {
                $verification['document_keywords_found'] = true;
                $verification['found_text_snippets']['document_keywords'] = implode(', ', $foundKeywords);
            }
            
            // Calculate overall success
            $requiredChecks = ['first_name_match', 'middle_name_match', 'last_name_match', 'year_level_match', 'university_match', 'document_keywords_found'];
            $passedChecks = 0;
            $totalConfidence = 0;
            $confidenceCount = 0;
            
            foreach ($requiredChecks as $check) {
                if ($verification[$check]) {
                    $passedChecks++;
                }
            }
            
            foreach ($verification['confidence_scores'] as $score) {
                $totalConfidence += $score;
                $confidenceCount++;
            }
            $averageConfidence = $confidenceCount > 0 ? ($totalConfidence / $confidenceCount) : 0;
            
            $verification['overall_success'] = ($passedChecks >= 4) || ($passedChecks >= 3 && $averageConfidence >= 80);
            
            $verification['summary'] = [
                'passed_checks' => $passedChecks,
                'total_checks' => 6,
                'average_confidence' => round($averageConfidence, 1),
                'recommendation' => $verification['overall_success'] ? 
                    'Document validation successful' : 
                    'Please ensure the document clearly shows your name, university, year level, and appears to be an official enrollment form'
            ];
            
            // Add identity_verification object for modal display
            $verification['identity_verification'] = [
                'document_type' => 'eaf',
                'first_name_match' => $verification['first_name_match'],
                'first_name_confidence' => $verification['confidence_scores']['first_name'] ?? 0,
                'middle_name_match' => $verification['middle_name_match'],
                'middle_name_confidence' => $verification['confidence_scores']['middle_name'] ?? 0,
                'last_name_match' => $verification['last_name_match'],
                'last_name_confidence' => $verification['confidence_scores']['last_name'] ?? 0,
                'year_level_match' => $verification['year_level_match'],
                'school_match' => $verification['university_match'],
                'school_confidence' => $verification['confidence_scores']['university'] ?? 0,
                'official_keywords' => $verification['document_keywords_found'],
                'keywords_confidence' => $verification['confidence_scores']['document_keywords'] ?? 0,
                'verification_score' => round($averageConfidence, 1),
                'passed_checks' => $passedChecks,
                'total_checks' => 6,
                'average_confidence' => round($averageConfidence, 1),
                'recommendation' => $verification['summary']['recommendation']
            ];
            
            $ocrData['ocr_confidence'] = round($averageConfidence, 1);
            $ocrData['verification_score'] = round($averageConfidence, 1);
            $ocrData['verification_status'] = $verification['overall_success'] ? 'passed' : 'manual_review';
            
            // Add root-level confidence values for easier parsing in confirmUpload
            $verification['ocr_confidence'] = $ocrData['ocr_confidence'];
            $verification['verification_score'] = $ocrData['verification_score'];
            $verification['verification_status'] = $ocrData['verification_status'];
            
            // Save verification data
            @file_put_contents($tempPath . '.verify.json', json_encode($verification, JSON_PRETTY_PRINT));
            
            // Save confidence.json (matching registration format)
            @file_put_contents($tempPath . '.confidence.json', json_encode([
                'ocr_confidence' => $ocrData['ocr_confidence'],
                'verification_score' => $ocrData['verification_score'],
                'status' => $ocrData['verification_status'],
                'timestamp' => date('Y-m-d H:i:s'),
                'checks_passed' => $passedChecks,
                'total_checks' => 6
            ], JSON_PRETTY_PRINT));
            
            error_log("EAF OCR: Confidence={$ocrData['ocr_confidence']}%, Passed={$passedChecks}/6 checks");
            
            return [
                'success' => true,
                'ocr_confidence' => $ocrData['ocr_confidence'],
                'verification_score' => $ocrData['verification_score'],
                'verification_status' => $ocrData['verification_status']
            ];
            
        } catch (Exception $e) {
            error_log("DocumentReuploadService::processEafOCR error: " . $e->getMessage());
            return ['success' => false, 'message' => 'EAF OCR failed: ' . $e->getMessage()];
        }
    }
    
    /**
     * Process Letter to Mayor OCR with comprehensive verification (matching registration)
     */
    private function processLetterOCR($tempPath, $studentData) {
        $ocrData = [
            'ocr_confidence' => 0,
            'verification_score' => 0,
            'verification_status' => 'failed',
            'extracted_text' => ''
        ];

        try {
            // Run Tesseract OCR
            $ocrResult = $this->runDirectTesseractOCR($tempPath, '02');
            $ocrText = $ocrResult['text'] ?? '';
            
            if (empty(trim($ocrText))) {
                return ['success' => false, 'message' => 'No text extracted from Letter document'];
            }

            $ocrData['extracted_text'] = $ocrText;
            $ocrData['ocr_confidence'] = $ocrResult['confidence'] ?? 0;
            
            // Save OCR text
            @file_put_contents($tempPath . '.ocr.txt', $ocrText);
            
            // Initialize verification structure (matching registration)
            $verification = [
                'first_name' => false,
                'last_name' => false,
                'barangay' => false,
                'mayor_header' => false,
                'municipality' => false,
                'confidence_scores' => [],
                'found_text_snippets' => []
            ];
            
            $ocrTextLower = strtolower($ocrText);
            
            // Helper function for similarity calculation
            $calculateSimilarity = function($needle, $haystack) {
                $needle = strtolower(trim($needle));
                $haystack = strtolower(trim($haystack));
                
                if (stripos($haystack, $needle) !== false) {
                    return 100;
                }
                
                $words = explode(' ', $haystack);
                $maxSimilarity = 0;
                
                foreach ($words as $word) {
                    if (strlen($word) >= 3 && strlen($needle) >= 3) {
                        $similarity = 0;
                        similar_text($needle, $word, $similarity);
                        $maxSimilarity = max($maxSimilarity, $similarity);
                    }
                }
                
                return $maxSimilarity;
            };
            
            // Verify first name
            if (!empty($studentData['first_name'])) {
                $similarity = $calculateSimilarity($studentData['first_name'], $ocrTextLower);
                $verification['confidence_scores']['first_name'] = $similarity;
                
                if ($similarity >= 70) {
                    $verification['first_name'] = true;
                    $pattern = '/\b\w*' . preg_quote(substr($studentData['first_name'], 0, 3), '/') . '\w*\b/i';
                    if (preg_match($pattern, $ocrText, $matches)) {
                        $verification['found_text_snippets']['first_name'] = $matches[0];
                    }
                }
            }
            
            // Verify last name
            if (!empty($studentData['last_name'])) {
                $similarity = $calculateSimilarity($studentData['last_name'], $ocrTextLower);
                $verification['confidence_scores']['last_name'] = $similarity;
                
                if ($similarity >= 70) {
                    $verification['last_name'] = true;
                    $pattern = '/\b\w*' . preg_quote(substr($studentData['last_name'], 0, 3), '/') . '\w*\b/i';
                    if (preg_match($pattern, $ocrText, $matches)) {
                        $verification['found_text_snippets']['last_name'] = $matches[0];
                    }
                }
            }
            
            // Verify barangay
            if (!empty($studentData['barangay_name'])) {
                $barangayName = $studentData['barangay_name'];
                $barangayWords = array_filter(explode(' ', strtolower($barangayName)));
                $foundWords = 0;
                $totalWords = count($barangayWords);
                
                foreach ($barangayWords as $word) {
                    if (strlen($word) > 2 && stripos($ocrText, $word) !== false) {
                        $foundWords++;
                    }
                }
                
                $barangayScore = $totalWords > 0 ? ($foundWords / $totalWords) * 100 : 0;
                $verification['confidence_scores']['barangay'] = round($barangayScore, 1);
                
                if ($barangayScore >= 60 || stripos($ocrTextLower, strtolower($barangayName)) !== false) {
                    $verification['barangay'] = true;
                    $verification['found_text_snippets']['barangay'] = $barangayName;
                }
            }
            
            // Verify mayor/office header keywords
            $mayorKeywords = ['mayor', 'office', 'municipal', 'city hall', 'government', 'hon.', 'honorable'];
            $mayorMatches = 0;
            $foundMayorKeywords = [];
            
            foreach ($mayorKeywords as $keyword) {
                if (stripos($ocrTextLower, $keyword) !== false) {
                    $mayorMatches++;
                    $foundMayorKeywords[] = $keyword;
                }
            }
            
            $mayorConfidence = ($mayorMatches / count($mayorKeywords)) * 100;
            $verification['confidence_scores']['mayor_header'] = round($mayorConfidence, 1);
            
            if ($mayorMatches >= 2) {
                $verification['mayor_header'] = true;
                $verification['found_text_snippets']['mayor_header'] = implode(', ', $foundMayorKeywords);
            }
            
            // Verify municipality (matching registration logic)
            // Get active municipality from session or default to General Trias
            $activeMunicipality = $_SESSION['active_municipality'] ?? 'General Trias';
            
            // Create municipality variants for flexible matching
            $municipalityVariants = [
                $activeMunicipality,
                strtolower($activeMunicipality),
                str_replace(' ', '', strtolower($activeMunicipality))
            ];
            
            // Add common abbreviations if municipality is "General Trias"
            if (stripos($activeMunicipality, 'general trias') !== false) {
                $municipalityVariants[] = 'gen trias';
                $municipalityVariants[] = 'gen. trias';
                $municipalityVariants[] = 'gentrias';
            }
            
            $municipalityFound = false;
            $municipalityConfidence = 0;
            $foundMunicipalityText = '';
            
            foreach ($municipalityVariants as $variant) {
                $similarity = $calculateSimilarity($variant, $ocrTextLower);
                if ($similarity > $municipalityConfidence) {
                    $municipalityConfidence = $similarity;
                }
                
                if ($similarity >= 70) {
                    $municipalityFound = true;
                    // Try to find the actual text snippet
                    $pattern = '/[^\n]*' . preg_quote(explode(' ', $variant)[0], '/') . '[^\n]*/i';
                    if (preg_match($pattern, $ocrText, $matches)) {
                        $foundMunicipalityText = trim($matches[0]);
                    }
                    break;
                }
            }
            
            $verification['municipality'] = $municipalityFound;
            $verification['confidence_scores']['municipality'] = round($municipalityConfidence, 1);
            if (!empty($foundMunicipalityText)) {
                $verification['found_text_snippets']['municipality'] = $foundMunicipalityText;
            }
            
            // Calculate overall success
            $requiredChecks = ['first_name', 'last_name', 'barangay', 'mayor_header', 'municipality'];
            $passedChecks = 0;
            $totalConfidence = 0;
            
            foreach ($requiredChecks as $check) {
                if ($verification[$check]) {
                    $passedChecks++;
                }
                $totalConfidence += $verification['confidence_scores'][$check] ?? 0;
            }
            
            $averageConfidence = $totalConfidence / 5;
            
            // CRITICAL: Check for cross-document confusion (Letter vs Certificate)
            $indigencyKeywords = ['indigency', 'indigent', 'certificate of indigency'];
            $hasIndigencyKeywords = false;
            foreach ($indigencyKeywords as $keyword) {
                if (stripos($ocrTextLower, $keyword) !== false) {
                    $hasIndigencyKeywords = true;
                    break;
                }
            }
            
            if ($hasIndigencyKeywords) {
                // This is likely a Certificate of Indigency, not a Letter to Mayor
                error_log("Letter OCR REJECTED: Document contains indigency keywords - likely Certificate of Indigency");
                
                // Return structured validation failure (not an error)
                $verification['overall_success'] = false;
                $verification['wrong_document_type'] = true;
                $verification['detected_document_type'] = 'Certificate of Indigency';
                $verification['summary'] = [
                    'passed_checks' => 0,
                    'total_checks' => 5,
                    'average_confidence' => 0,
                    'recommendation' => 'This appears to be a "Certificate of Indigency", not a "Letter to Mayor". Please upload it in the correct document field.',
                    'error_type' => 'cross_document_confusion'
                ];
                
                // Save verification data for display
                @file_put_contents($tempPath . '.verify.json', json_encode($verification, JSON_PRETTY_PRINT));
                
                return [
                    'success' => true, // OCR succeeded, but validation failed
                    'ocr_confidence' => 0,
                    'verification_score' => 0,
                    'verification_status' => 'failed',
                    'verification_passed' => false
                ];
            }
            
            // STRICTER SUCCESS CRITERIA: ALL 5 checks must pass (matching registration)
            // This prevents wrong documents (like indigency certificates) from being accepted
            $verification['overall_success'] = ($passedChecks >= 5 && $averageConfidence >= 70);
            $verification['verification_passed'] = $verification['overall_success'];
            
            $verification['summary'] = [
                'passed_checks' => $passedChecks,
                'total_checks' => 5,
                'average_confidence' => round($averageConfidence, 1),
                'recommendation' => $verification['overall_success'] ? 
                    'Document validation successful' : 
                    'Please ensure the document contains your name, barangay, mayor office header, and municipality (' . $activeMunicipality . ') clearly',
                'required_municipality' => $activeMunicipality
            ];
            
            $ocrData['ocr_confidence'] = round($averageConfidence, 1);
            $ocrData['verification_score'] = round($averageConfidence, 1);
            $ocrData['verification_status'] = $verification['overall_success'] ? 'passed' : 'manual_review';
            
            // Add root-level fields for UI display
            $verification['ocr_confidence'] = $ocrData['ocr_confidence'];
            $verification['verification_score'] = $ocrData['verification_score'];
            $verification['verification_status'] = $ocrData['verification_status'];
            $verification['verification_passed'] = $verification['overall_success'];
            
            // Save verification data
            @file_put_contents($tempPath . '.verify.json', json_encode($verification, JSON_PRETTY_PRINT));
            
            // Save confidence.json (matching registration format)
            @file_put_contents($tempPath . '.confidence.json', json_encode([
                'ocr_confidence' => $ocrData['ocr_confidence'],
                'verification_score' => $ocrData['verification_score'],
                'status' => $ocrData['verification_status'],
                'verification_passed' => $verification['verification_passed'],
                'timestamp' => date('Y-m-d H:i:s'),
                'checks_passed' => $passedChecks,
                'total_checks' => 5
            ], JSON_PRETTY_PRINT));
            
            error_log("Letter OCR: Confidence={$ocrData['ocr_confidence']}%, Passed={$passedChecks}/4 checks");
            
            return [
                'success' => true,
                'ocr_confidence' => $ocrData['ocr_confidence'],
                'verification_score' => $ocrData['verification_score'],
                'verification_status' => $ocrData['verification_status'],
                'verification_passed' => $verification['verification_passed']
            ];
            
        } catch (Exception $e) {
            error_log("DocumentReuploadService::processLetterOCR error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Letter OCR failed: ' . $e->getMessage()];
        }
    }
    
    /**
     * Process Certificate of Indigency OCR with comprehensive verification (matching registration)
     */
    private function processCertificateOCR($tempPath, $studentData) {
        $ocrData = [
            'ocr_confidence' => 0,
            'verification_score' => 0,
            'verification_status' => 'failed',
            'extracted_text' => ''
        ];

        try {
            // Run Tesseract OCR
            $ocrResult = $this->runDirectTesseractOCR($tempPath, '03');
            $ocrText = $ocrResult['text'] ?? '';
            
            if (empty(trim($ocrText))) {
                return ['success' => false, 'message' => 'No text extracted from Certificate document'];
            }

            $ocrData['extracted_text'] = $ocrText;
            $ocrData['ocr_confidence'] = $ocrResult['confidence'] ?? 0;
            
            // Save OCR text
            @file_put_contents($tempPath . '.ocr.txt', $ocrText);
            
            // Initialize verification structure (matching registration)
            $verification = [
                'certificate_title' => false,
                'first_name' => false,
                'last_name' => false,
                'barangay' => false,
                'municipality' => false,
                'confidence_scores' => [],
                'found_text_snippets' => []
            ];
            
            $ocrTextLower = strtolower($ocrText);
            
            // Helper function for similarity calculation
            $calculateSimilarity = function($needle, $haystack) {
                $needle = strtolower(trim($needle));
                $haystack = strtolower(trim($haystack));
                
                if (stripos($haystack, $needle) !== false) {
                    return 100;
                }
                
                $words = explode(' ', $haystack);
                $maxSimilarity = 0;
                
                foreach ($words as $word) {
                    if (strlen($word) >= 3 && strlen($needle) >= 3) {
                        $similarity = 0;
                        similar_text($needle, $word, $similarity);
                        $maxSimilarity = max($maxSimilarity, $similarity);
                    }
                }
                
                return $maxSimilarity;
            };
            
            // Verify certificate title
            $titleKeywords = ['certificate', 'indigency', 'indigent', 'low income', 'certificate of indigency'];
            $titleMatches = 0;
            $foundTitleKeywords = [];
            
            foreach ($titleKeywords as $keyword) {
                $similarity = $calculateSimilarity($keyword, $ocrTextLower);
                if ($similarity >= 70) {
                    $titleMatches++;
                    $foundTitleKeywords[] = $keyword;
                }
            }
            
            $titleConfidence = ($titleMatches / count($titleKeywords)) * 100;
            $verification['confidence_scores']['certificate_title'] = round($titleConfidence, 1);
            
            if ($titleMatches >= 2) {
                $verification['certificate_title'] = true;
                $verification['found_text_snippets']['certificate_title'] = implode(', ', $foundTitleKeywords);
            }
            
            // Verify first name
            if (!empty($studentData['first_name'])) {
                $similarity = $calculateSimilarity($studentData['first_name'], $ocrTextLower);
                $verification['confidence_scores']['first_name'] = $similarity;
                
                if ($similarity >= 70) {
                    $verification['first_name'] = true;
                    $pattern = '/\b\w*' . preg_quote(substr($studentData['first_name'], 0, 3), '/') . '\w*\b/i';
                    if (preg_match($pattern, $ocrText, $matches)) {
                        $verification['found_text_snippets']['first_name'] = $matches[0];
                    }
                }
            }
            
            // Verify last name
            if (!empty($studentData['last_name'])) {
                $similarity = $calculateSimilarity($studentData['last_name'], $ocrTextLower);
                $verification['confidence_scores']['last_name'] = $similarity;
                
                if ($similarity >= 70) {
                    $verification['last_name'] = true;
                    $pattern = '/\b\w*' . preg_quote(substr($studentData['last_name'], 0, 3), '/') . '\w*\b/i';
                    if (preg_match($pattern, $ocrText, $matches)) {
                        $verification['found_text_snippets']['last_name'] = $matches[0];
                    }
                }
            }
            
            // Verify barangay
            if (!empty($studentData['barangay_name'])) {
                $barangayName = $studentData['barangay_name'];
                $barangayWords = array_filter(explode(' ', strtolower($barangayName)));
                $foundWords = 0;
                $totalWords = count($barangayWords);
                
                foreach ($barangayWords as $word) {
                    if (strlen($word) > 2 && stripos($ocrText, $word) !== false) {
                        $foundWords++;
                    }
                }
                
                $barangayScore = $totalWords > 0 ? ($foundWords / $totalWords) * 100 : 0;
                $verification['confidence_scores']['barangay'] = round($barangayScore, 1);
                
                if ($barangayScore >= 60 || stripos($ocrTextLower, strtolower($barangayName)) !== false) {
                    $verification['barangay'] = true;
                    $verification['found_text_snippets']['barangay'] = $barangayName;
                }
            }
            
            // Verify municipality (matching registration and letter logic)
            // Get active municipality from session or default to General Trias
            $activeMunicipality = $_SESSION['active_municipality'] ?? 'General Trias';
            
            // Create municipality variants for flexible matching
            $municipalityVariants = [
                $activeMunicipality,
                strtolower($activeMunicipality),
                str_replace(' ', '', strtolower($activeMunicipality))
            ];
            
            // Add common abbreviations if municipality is "General Trias"
            if (stripos($activeMunicipality, 'general trias') !== false) {
                $municipalityVariants[] = 'gen trias';
                $municipalityVariants[] = 'gen. trias';
                $municipalityVariants[] = 'gentrias';
            }
            
            $municipalityFound = false;
            $municipalityConfidence = 0;
            $foundMunicipalityText = '';
            
            foreach ($municipalityVariants as $variant) {
                $similarity = $calculateSimilarity($variant, $ocrTextLower);
                if ($similarity > $municipalityConfidence) {
                    $municipalityConfidence = $similarity;
                }
                
                if ($similarity >= 70) {
                    $municipalityFound = true;
                    // Try to find the actual text snippet
                    $pattern = '/[^\n]*' . preg_quote(explode(' ', $variant)[0], '/') . '[^\n]*/i';
                    if (preg_match($pattern, $ocrText, $matches)) {
                        $foundMunicipalityText = trim($matches[0]);
                    }
                    break;
                }
            }
            
            $verification['municipality'] = $municipalityFound;
            $verification['confidence_scores']['municipality'] = round($municipalityConfidence, 1);
            if (!empty($foundMunicipalityText)) {
                $verification['found_text_snippets']['municipality'] = $foundMunicipalityText;
            }
            
            // Calculate overall success
            $requiredChecks = ['certificate_title', 'first_name', 'last_name', 'barangay', 'municipality'];
            $passedChecks = 0;
            $totalConfidence = 0;
            
            foreach ($requiredChecks as $check) {
                if ($verification[$check]) {
                    $passedChecks++;
                }
                $totalConfidence += $verification['confidence_scores'][$check] ?? 0;
            }
            
            $averageConfidence = $totalConfidence / 5;
            
            // CRITICAL: Check for cross-document confusion (Certificate vs Letter)
            $mayorKeywords = ['mayor', 'endorse', 'recommend', "mayor's office", 'municipal office'];
            $hasMayorKeywords = 0;
            foreach ($mayorKeywords as $keyword) {
                if (stripos($ocrTextLower, $keyword) !== false) {
                    $hasMayorKeywords++;
                }
            }
            
            // If has 2+ mayor keywords AND no indigency keyword, it's likely a Letter
            $hasIndigency = stripos($ocrTextLower, 'indigency') !== false || stripos($ocrTextLower, 'indigent') !== false;
            if ($hasMayorKeywords >= 2 && !$hasIndigency) {
                error_log("Certificate OCR REJECTED: Document contains mayor/endorsement keywords but no indigency - likely Letter to Mayor");
                
                // Return structured validation failure (not an error)
                $verification['overall_success'] = false;
                $verification['wrong_document_type'] = true;
                $verification['detected_document_type'] = 'Letter to Mayor';
                $verification['summary'] = [
                    'passed_checks' => 0,
                    'total_checks' => 5,
                    'average_confidence' => 0,
                    'recommendation' => 'This appears to be a "Letter to Mayor", not a "Certificate of Indigency". Please upload it in the correct document field.',
                    'error_type' => 'cross_document_confusion'
                ];
                
                // Save verification data for display
                @file_put_contents($tempPath . '.verify.json', json_encode($verification, JSON_PRETTY_PRINT));
                
                return [
                    'success' => true, // OCR succeeded, but validation failed
                    'ocr_confidence' => 0,
                    'verification_score' => 0,
                    'verification_status' => 'failed',
                    'verification_passed' => false
                ];
            }
            
            // CRITICAL: Must have "indigency" or "indigent" keyword
            if (!$hasIndigency) {
                error_log("Certificate OCR REJECTED: No 'indigency' or 'indigent' keyword found");
                
                // Return structured validation failure
                $verification['overall_success'] = false;
                $verification['missing_keywords'] = true;
                $verification['summary'] = [
                    'passed_checks' => 0,
                    'total_checks' => 5,
                    'average_confidence' => 0,
                    'recommendation' => 'Document does not contain "indigency" or "indigent" keyword - not a valid Certificate of Indigency.',
                    'error_type' => 'missing_critical_keyword'
                ];
                
                // Save verification data for display
                @file_put_contents($tempPath . '.verify.json', json_encode($verification, JSON_PRETTY_PRINT));
                
                return [
                    'success' => true, // OCR succeeded, but validation failed
                    'ocr_confidence' => 0,
                    'verification_score' => 0,
                    'verification_status' => 'failed',
                    'verification_passed' => false
                ];
            }
            
            $verification['overall_success'] = ($passedChecks >= 5 && $averageConfidence >= 70);
            $verification['verification_passed'] = $verification['overall_success'];
            
            $verification['summary'] = [
                'passed_checks' => $passedChecks,
                'total_checks' => 5,
                'average_confidence' => round($averageConfidence, 1),
                'recommendation' => $verification['overall_success'] ? 
                    'Certificate validation successful' : 
                    'Please ensure the certificate contains your name, barangay, "Certificate of Indigency" title, and "General Trias" clearly'
            ];
            
            $ocrData['ocr_confidence'] = round($averageConfidence, 1);
            $ocrData['verification_score'] = round($averageConfidence, 1);
            $ocrData['verification_status'] = $verification['overall_success'] ? 'passed' : 'manual_review';
            
            // Add root-level fields for UI display
            $verification['ocr_confidence'] = $ocrData['ocr_confidence'];
            $verification['verification_score'] = $ocrData['verification_score'];
            $verification['verification_status'] = $ocrData['verification_status'];
            $verification['verification_passed'] = $verification['overall_success'];
            
            // Save verification data
            @file_put_contents($tempPath . '.verify.json', json_encode($verification, JSON_PRETTY_PRINT));
            
            // Save confidence.json (matching registration format)
            @file_put_contents($tempPath . '.confidence.json', json_encode([
                'ocr_confidence' => $ocrData['ocr_confidence'],
                'verification_score' => $ocrData['verification_score'],
                'status' => $ocrData['verification_status'],
                'verification_passed' => $verification['verification_passed'],
                'timestamp' => date('Y-m-d H:i:s'),
                'checks_passed' => $passedChecks,
                'total_checks' => 5
            ], JSON_PRETTY_PRINT));
            
            error_log("Certificate OCR: Confidence={$ocrData['ocr_confidence']}%, Passed={$passedChecks}/5 checks");
            
            return [
                'success' => true,
                'ocr_confidence' => $ocrData['ocr_confidence'],
                'verification_score' => $ocrData['verification_score'],
                'verification_status' => $ocrData['verification_status'],
                'verification_passed' => $verification['verification_passed']
            ];
            
        } catch (Exception $e) {
            error_log("DocumentReuploadService::processCertificateOCR error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Certificate OCR failed: ' . $e->getMessage()];
        }
    }
    
    /**
     * Process ID Picture OCR with comprehensive verification (matching registration)
     */
    private function processIdPictureOCR($tempPath, $studentData) {
        $ocrData = [
            'ocr_confidence' => 0,
            'verification_score' => 0,
            'verification_status' => 'failed',
            'extracted_text' => ''
        ];

        try {
            // Run Tesseract OCR with multiple PSM modes for ID pictures
            $ocrResult = $this->runDirectTesseractOCR($tempPath, '04');
            $ocrText = $ocrResult['text'] ?? '';
            
            if (empty(trim($ocrText))) {
                return ['success' => false, 'message' => 'No text extracted from ID Picture'];
            }

            $ocrData['extracted_text'] = $ocrText;
            $ocrData['ocr_confidence'] = $ocrResult['confidence'] ?? 0;
            
            // Save OCR text
            @file_put_contents($tempPath . '.ocr.txt', $ocrText);
            
            // Initialize verification structure
            $verification = [
                'first_name_match' => false,
                'middle_name_match' => false,
                'last_name_match' => false,
                'year_level_match' => false,
                'university_match' => false,
                'document_keywords_found' => false,
                'confidence_scores' => [],
                'found_text_snippets' => []
            ];
            
            $ocrTextLower = strtolower($ocrText);
            
            // Helper function for similarity calculation
            $calculateSimilarity = function($needle, $haystack) {
                $needle = strtolower(trim($needle));
                $haystack = strtolower(trim($haystack));
                
                if (stripos($haystack, $needle) !== false) {
                    return 100;
                }
                
                $words = explode(' ', $haystack);
                $maxSimilarity = 0;
                
                foreach ($words as $word) {
                    if (strlen($word) >= 3 && strlen($needle) >= 3) {
                        $similarity = 0;
                        similar_text($needle, $word, $similarity);
                        $maxSimilarity = max($maxSimilarity, $similarity);
                    }
                }
                
                return $maxSimilarity;
            };
            
            // Verify first name
            if (!empty($studentData['first_name'])) {
                $similarity = $calculateSimilarity($studentData['first_name'], $ocrTextLower);
                $verification['confidence_scores']['first_name'] = $similarity;
                
                if ($similarity >= 70) {
                    $verification['first_name_match'] = true;
                    $pattern = '/\b\w*' . preg_quote(substr($studentData['first_name'], 0, 3), '/') . '\w*\b/i';
                    if (preg_match($pattern, $ocrText, $matches)) {
                        $verification['found_text_snippets']['first_name'] = $matches[0];
                    }
                }
            }
            
            // Verify middle name (optional)
            if (empty($studentData['middle_name'])) {
                $verification['middle_name_match'] = true;
                $verification['confidence_scores']['middle_name'] = 100;
            } else {
                $similarity = $calculateSimilarity($studentData['middle_name'], $ocrTextLower);
                $verification['confidence_scores']['middle_name'] = $similarity;
                
                if ($similarity >= 60) {
                    $verification['middle_name_match'] = true;
                    $pattern = '/\b\w*' . preg_quote(substr($studentData['middle_name'], 0, 3), '/') . '\w*\b/i';
                    if (preg_match($pattern, $ocrText, $matches)) {
                        $verification['found_text_snippets']['middle_name'] = $matches[0];
                    }
                }
            }
            
            // Verify last name
            if (!empty($studentData['last_name'])) {
                $similarity = $calculateSimilarity($studentData['last_name'], $ocrTextLower);
                $verification['confidence_scores']['last_name'] = $similarity;
                
                if ($similarity >= 70) {
                    $verification['last_name_match'] = true;
                    $pattern = '/\b\w*' . preg_quote(substr($studentData['last_name'], 0, 3), '/') . '\w*\b/i';
                    if (preg_match($pattern, $ocrText, $matches)) {
                        $verification['found_text_snippets']['last_name'] = $matches[0];
                    }
                }
            }
            
            // Verify year level
            if (!empty($studentData['year_level_name'])) {
                $yearLevelName = $studentData['year_level_name'];
                $selectedYearVariations = [];
                
                if (stripos($yearLevelName, '1st') !== false || stripos($yearLevelName, 'first') !== false) {
                    $selectedYearVariations = ['1st year', 'first year', '1st yr', 'year 1', 'yr 1', 'freshman'];
                } elseif (stripos($yearLevelName, '2nd') !== false || stripos($yearLevelName, 'second') !== false) {
                    $selectedYearVariations = ['2nd year', 'second year', '2nd yr', 'year 2', 'yr 2', 'sophomore'];
                } elseif (stripos($yearLevelName, '3rd') !== false || stripos($yearLevelName, 'third') !== false) {
                    $selectedYearVariations = ['3rd year', 'third year', '3rd yr', 'year 3', 'yr 3', 'junior'];
                } elseif (stripos($yearLevelName, '4th') !== false || stripos($yearLevelName, 'fourth') !== false) {
                    $selectedYearVariations = ['4th year', 'fourth year', '4th yr', 'year 4', 'yr 4', 'senior'];
                } elseif (stripos($yearLevelName, '5th') !== false || stripos($yearLevelName, 'fifth') !== false) {
                    $selectedYearVariations = ['5th year', 'fifth year', '5th yr', 'year 5', 'yr 5'];
                }
                
                foreach ($selectedYearVariations as $variation) {
                    if (stripos($ocrText, $variation) !== false) {
                        $verification['year_level_match'] = true;
                        break;
                    }
                }
            }
            
            // Verify university
            if (!empty($studentData['university_name'])) {
                $universityName = $studentData['university_name'];
                $universityWords = array_filter(explode(' ', strtolower($universityName)));
                $foundWords = 0;
                $totalWords = count($universityWords);
                $foundSnippets = [];
                
                foreach ($universityWords as $word) {
                    if (strlen($word) > 2) {
                        $similarity = $calculateSimilarity($word, $ocrTextLower);
                        if ($similarity >= 70) {
                            $foundWords++;
                            $pattern = '/\b\w*' . preg_quote(substr($word, 0, 3), '/') . '\w*\b/i';
                            if (preg_match($pattern, $ocrText, $matches)) {
                                $foundSnippets[] = $matches[0];
                            }
                        }
                    }
                }
                
                $universityScore = ($foundWords / max($totalWords, 1)) * 100;
                $verification['confidence_scores']['university'] = round($universityScore, 1);
                
                if ($universityScore >= 60 || ($totalWords <= 2 && $foundWords >= 1)) {
                    $verification['university_match'] = true;
                    if (!empty($foundSnippets)) {
                        $verification['found_text_snippets']['university'] = implode(', ', array_unique($foundSnippets));
                    }
                }
            }
            
            // Verify document keywords (ID-specific)
            $documentKeywords = [
                'student', 'id', 'identification', 'university', 'college', 'school',
                'name', 'course', 'year', 'level', 'photo', 'picture'
            ];
            
            $keywordMatches = 0;
            $foundKeywords = [];
            $keywordScore = 0;
            
            foreach ($documentKeywords as $keyword) {
                $similarity = $calculateSimilarity($keyword, $ocrTextLower);
                if ($similarity >= 80) {
                    $keywordMatches++;
                    $foundKeywords[] = $keyword;
                    $keywordScore += $similarity;
                }
            }
            
            $averageKeywordScore = $keywordMatches > 0 ? ($keywordScore / $keywordMatches) : 0;
            $verification['confidence_scores']['document_keywords'] = round($averageKeywordScore, 1);
            
            if ($keywordMatches >= 2) {
                $verification['document_keywords_found'] = true;
                $verification['found_text_snippets']['document_keywords'] = implode(', ', $foundKeywords);
            }
            
            // Calculate overall success
            $requiredChecks = ['first_name_match', 'middle_name_match', 'last_name_match', 'year_level_match', 'university_match', 'document_keywords_found'];
            $passedChecks = 0;
            $totalConfidence = 0;
            $confidenceCount = 0;
            
            foreach ($requiredChecks as $check) {
                if ($verification[$check]) {
                    $passedChecks++;
                }
            }
            
            foreach ($verification['confidence_scores'] as $score) {
                $totalConfidence += $score;
                $confidenceCount++;
            }
            $averageConfidence = $confidenceCount > 0 ? ($totalConfidence / $confidenceCount) : 0;
            
            $verification['overall_success'] = ($passedChecks >= 4) || ($passedChecks >= 3 && $averageConfidence >= 80);
            
            $verification['summary'] = [
                'passed_checks' => $passedChecks,
                'total_checks' => 6,
                'average_confidence' => round($averageConfidence, 1),
                'recommendation' => $verification['overall_success'] ? 
                    'ID Picture validation successful' : 
                    'Please ensure the ID clearly shows your name, university, and year level'
            ];
            
            $ocrData['ocr_confidence'] = round($averageConfidence, 1);
            $ocrData['verification_score'] = round($averageConfidence, 1);
            $ocrData['verification_status'] = $verification['overall_success'] ? 'passed' : 'manual_review';
            
            // Add root-level confidence values for easier parsing in confirmUpload
            $verification['ocr_confidence'] = $ocrData['ocr_confidence'];
            $verification['verification_score'] = $ocrData['verification_score'];
            $verification['verification_status'] = $ocrData['verification_status'];
            
            // Save verification data
            @file_put_contents($tempPath . '.verify.json', json_encode($verification, JSON_PRETTY_PRINT));
            
            // Save confidence.json (matching registration format)
            @file_put_contents($tempPath . '.confidence.json', json_encode([
                'ocr_confidence' => $ocrData['ocr_confidence'],
                'verification_score' => $ocrData['verification_score'],
                'status' => $ocrData['verification_status'],
                'timestamp' => date('Y-m-d H:i:s'),
                'checks_passed' => $passedChecks,
                'total_checks' => 6
            ], JSON_PRETTY_PRINT));
            
            error_log("ID Picture OCR: Confidence={$ocrData['ocr_confidence']}%, Passed={$passedChecks}/6 checks");
            
            return [
                'success' => true,
                'ocr_confidence' => $ocrData['ocr_confidence'],
                'verification_score' => $ocrData['verification_score'],
                'verification_status' => $ocrData['verification_status']
            ];
            
        } catch (Exception $e) {
            error_log("DocumentReuploadService::processIdPictureOCR error: " . $e->getMessage());
            return ['success' => false, 'message' => 'ID Picture OCR failed: ' . $e->getMessage()];
        }
    }
    
    /**
     * Log audit trail for document upload
     */
    private function logAudit($studentId, $docTypeName, $ocrData) {
        try {
            $description = "Student re-uploaded {$docTypeName} (Confidence: " . ($ocrData['ocr_confidence'] ?? 0) . "%)";
            
            $query = "INSERT INTO audit_logs 
                     (user_id, user_type, username, event_type, event_category, 
                      action_description, status, ip_address, user_agent, 
                      request_method, affected_table, affected_record_id, 
                      metadata, created_at)
                     VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13, NOW())";
            
            $metadata = json_encode([
                'document_type' => $docTypeName,
                'ocr_confidence' => $ocrData['ocr_confidence'] ?? 0,
                'verification_score' => $ocrData['verification_score'] ?? 0,
                'verification_status' => $ocrData['verification_status'] ?? 'unknown',
                'student_id' => $studentId
            ]);
            
            pg_query_params($this->db, $query, [
                null, // user_id (null for student actions)
                'student',
                $studentId, // username = student_id
                'document_reupload',
                'document_management',
                $description,
                'success',
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                $_SERVER['REQUEST_METHOD'] ?? 'POST',
                'documents',
                null, // affected_record_id (will be document_id if needed)
                $metadata
            ]);
        } catch (Exception $e) {
            error_log("DocumentReuploadService::logAudit error: " . $e->getMessage());
        }
    }
    
    /**
     * Mark documents as submitted when student uploads their first document
     * This sets documents_submitted = TRUE and documents_submission_date = NOW()
     */
    private function markDocumentsSubmitted($studentId) {
        try {
            // Check if already marked as submitted
            $checkQuery = pg_query_params($this->db,
                "SELECT documents_submitted FROM students WHERE student_id = $1",
                [$studentId]
            );
            
            if ($checkQuery && pg_num_rows($checkQuery) > 0) {
                $row = pg_fetch_assoc($checkQuery);
                $alreadySubmitted = ($row['documents_submitted'] === 't' || $row['documents_submitted'] === true);
                
                // Only update if not already submitted
                if (!$alreadySubmitted) {
                    pg_query_params($this->db,
                        "UPDATE students 
                         SET documents_submitted = TRUE,
                             documents_submission_date = NOW()
                         WHERE student_id = $1",
                        [$studentId]
                    );
                    
                    error_log("DocumentReuploadService: Marked documents as submitted for student $studentId");
                }
            }
        } catch (Exception $e) {
            error_log("DocumentReuploadService::markDocumentsSubmitted error: " . $e->getMessage());
        }
    }
    
    /**
     * Check if all rejected documents are uploaded and clear rejection status
     */
    private function checkAndClearRejectionStatus($studentId) {
        try {
            $studentQuery = pg_query_params($this->db,
                "SELECT documents_to_reupload FROM students WHERE student_id = $1",
                [$studentId]
            );
            
            if (!$studentQuery || pg_num_rows($studentQuery) === 0) {
                return;
            }
            
            $student = pg_fetch_assoc($studentQuery);
            $documentsToReupload = json_decode($student['documents_to_reupload'], true) ?: [];
            
            if (empty($documentsToReupload)) {
                return;
            }
            
            // Check if all required documents are now uploaded
            $query = "SELECT document_type_code FROM documents 
                      WHERE student_id = $1 AND document_type_code = ANY($2::text[])";
            
            $result = pg_query_params($this->db, $query, [
                $studentId,
                '{' . implode(',', $documentsToReupload) . '}'
            ]);
            
            if ($result) {
                $uploadedDocs = [];
                while ($row = pg_fetch_assoc($result)) {
                    $uploadedDocs[] = $row['document_type_code'];
                }
                
                // If all documents are uploaded, notify student but KEEP them in reupload mode
                // Admin must approve the reuploaded documents before clearing the reupload flag
                if (count($uploadedDocs) >= count($documentsToReupload)) {
                    // Don't clear needs_document_upload yet - wait for admin approval
                    // Only clear documents_to_reupload list since all are now uploaded
                    pg_query_params($this->db,
                        "UPDATE students 
                         SET documents_to_reupload = NULL
                         WHERE student_id = $1",
                        [$studentId]
                    );
                    
                    pg_query_params($this->db,
                        "INSERT INTO notifications (student_id, message, is_read, created_at) 
                         VALUES ($1, $2, FALSE, NOW())",
                        [$studentId, 'All required documents have been re-uploaded successfully. An admin will review them shortly.']
                    );
                    
                    error_log("DocumentReuploadService: All documents uploaded for $studentId - Waiting for admin approval");
                }
            }
        } catch (Exception $e) {
            error_log("DocumentReuploadService::checkAndClearRejectionStatus error: " . $e->getMessage());
        }
    }
    
    /**
     * Cleanup orphaned temporary files older than specified age
     * This prevents storage bloat from abandoned uploads
     * 
     * @param int $maxAgeMinutes Files older than this will be deleted (default: 60 minutes)
     * @return array Statistics about cleanup operation
     */
    public function cleanupOrphanedTempFiles($maxAgeMinutes = 60) {
        $stats = [
            'files_deleted' => 0,
            'artifacts_deleted' => 0,
            'bytes_freed' => 0,
            'errors' => []
        ];
        
        try {
            $cutoffTime = time() - ($maxAgeMinutes * 60);
            
            // Iterate through all document type folders
            foreach (self::DOCUMENT_TYPES as $code => $docInfo) {
                $tempFolder = $this->pathConfig->getTempPath($docInfo['folder']);
                
                if (!is_dir($tempFolder)) {
                    continue;
                }
                
                $files = glob($tempFolder . DIRECTORY_SEPARATOR . '*');
                
                foreach ($files as $file) {
                    if (!is_file($file)) {
                        continue;
                    }
                    
                    $fileAge = filemtime($file);
                    
                    // Delete if older than cutoff time
                    if ($fileAge < $cutoffTime) {
                        $fileSize = filesize($file);
                        $filename = basename($file);
                        
                        // Delete main file
                        if (@unlink($file)) {
                            $stats['files_deleted']++;
                            $stats['bytes_freed'] += $fileSize;
                            error_log("CLEANUP: Deleted orphaned temp file: $filename (age: " . 
                                     round((time() - $fileAge) / 60, 1) . " min)");
                            
                            // Delete associated OCR artifacts
                            $artifacts = [
                                $file . '.ocr.txt',
                                $file . '.verify.json',
                                $file . '.confidence.json',
                                $file . '.tsv'
                            ];
                            
                            foreach ($artifacts as $artifact) {
                                if (file_exists($artifact)) {
                                    $artifactSize = filesize($artifact);
                                    if (@unlink($artifact)) {
                                        $stats['artifacts_deleted']++;
                                        $stats['bytes_freed'] += $artifactSize;
                                        error_log("CLEANUP: Deleted artifact: " . basename($artifact));
                                    }
                                }
                            }
                        } else {
                            $stats['errors'][] = "Failed to delete: $filename";
                            error_log("CLEANUP ERROR: Could not delete $filename");
                        }
                    }
                }
            }
            
            error_log("CLEANUP COMPLETE: Deleted {$stats['files_deleted']} files, " .
                     "{$stats['artifacts_deleted']} artifacts, freed " .
                     round($stats['bytes_freed'] / 1024, 2) . " KB");
            
        } catch (Exception $e) {
            $stats['errors'][] = $e->getMessage();
            error_log("CLEANUP EXCEPTION: " . $e->getMessage());
        }
        
        return $stats;
    }
    
    /**
     * Cleanup all processing locks older than specified age
     * Prevents deadlocks from crashed/interrupted processing
     * 
     * @param int $maxAgeSeconds Locks older than this will be removed (default: 60 seconds)
     * @return int Number of locks cleared
     */
    public static function cleanupStaleLocks($maxAgeSeconds = 60) {
        $cleared = 0;
        $currentTime = time();
        
        if (isset($_SESSION['processing_lock']) && is_array($_SESSION['processing_lock'])) {
            foreach ($_SESSION['processing_lock'] as $lockKey => $lockTime) {
                $lockAge = $currentTime - $lockTime;
                
                if ($lockAge > $maxAgeSeconds) {
                    unset($_SESSION['processing_lock'][$lockKey]);
                    $cleared++;
                    error_log("LOCK CLEANUP: Removed stale lock '$lockKey' (age: {$lockAge}s)");
                }
            }
        }
        
        return $cleared;
    }
}
