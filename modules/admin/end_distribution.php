<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['admin_username'])) {
    header("Location: ../../unified_login.php");
    exit;
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/FilePathConfig.php';
require_once __DIR__ . '/../../services/DistributionManager.php';
require_once __DIR__ . '/../../bootstrap_services.php';

$pathConfig = FilePathConfig::getInstance();

/**
 * Delete all student uploaded documents from the file system
 * INCLUDING associated OCR and verification files (.ocr.txt, .verify.json, etc.)
 */
function deleteAllStudentUploads() {
    global $pathConfig;
    $uploadsPath = $pathConfig->getStudentPath();
    $documentTypes = ['enrollment_forms', 'grades', 'id_pictures', 'indigency', 'letter_to_mayor']; // Standardized: letter_to_mayor
    
    $totalDeleted = 0;
    $associatedDeleted = 0;
    $errors = [];
    
    // Associated file extensions to delete alongside main files
    $associatedExtensions = ['.ocr.txt', '.verify.json', '.confidence.json', '.tsv', '.ocr.json'];
    
    foreach ($documentTypes as $type) {
        $folderPath = $uploadsPath . DIRECTORY_SEPARATOR . $type;
        if (is_dir($folderPath)) {
            $items = scandir($folderPath);
            $hasStudentFolders = false;
            
            // Check if we have student subdirectories (new structure)
            foreach ($items as $item) {
                if ($item !== '.' && $item !== '..' && is_dir($folderPath . DIRECTORY_SEPARATOR . $item)) {
                    // If it looks like a student ID folder, we have new structure
                    $hasStudentFolders = true;
                    break;
                }
            }
            
            $filesToProcess = [];
            
            if ($hasStudentFolders) {
                // NEW STRUCTURE: student/{doc_type}/{student_id}/files
                error_log("  Deleting from new structure: $type/");
                foreach ($items as $item) {
                    if ($item !== '.' && $item !== '..') {
                        $studentFolder = $folderPath . DIRECTORY_SEPARATOR . $item;
                        if (is_dir($studentFolder)) {
                            // Get all files in student folder
                            $studentFiles = glob($studentFolder . DIRECTORY_SEPARATOR . '*.*');
                            foreach ($studentFiles as $file) {
                                if (is_file($file)) {
                                    $filesToProcess[] = $file;
                                }
                            }
                        }
                    }
                }
            } else {
                // OLD STRUCTURE: flat files in student/{doc_type}/
                error_log("  Deleting from legacy structure: $type/");
                $filesToProcess = glob($folderPath . DIRECTORY_SEPARATOR . '*.*');
            }
            
            foreach ($filesToProcess as $file) {
                if (is_file($file)) {
                    // Skip if this is already an associated file (will be deleted with main file)
                    $isAssociated = false;
                    foreach ($associatedExtensions as $ext) {
                        if (substr($file, -strlen($ext)) === $ext) {
                            $isAssociated = true;
                            break;
                        }
                    }
                    
                    if (!$isAssociated) {
                        // Delete main file
                        if (unlink($file)) {
                            $totalDeleted++;
                            
                            // Delete associated files - try both patterns
                            $pathInfo = pathinfo($file);
                            $fileDir = $pathInfo['dirname'];
                            $fileBasename = $pathInfo['basename']; // Includes extension
                            $fileWithoutExt = $pathInfo['filename']; // Without extension
                            
                            foreach ($associatedExtensions as $ext) {
                                // Try both patterns:
                                // 1. file.jpg.verify.json (new style)
                                // 2. file.verify.json (old style)
                                $associatedFile1 = $fileDir . '/' . $fileBasename . $ext;
                                $associatedFile2 = $fileDir . '/' . $fileWithoutExt . $ext;
                                
                                if (file_exists($associatedFile1)) {
                                    if (unlink($associatedFile1)) {
                                        $associatedDeleted++;
                                    } else {
                                        $errors[] = "Failed to delete associated: " . basename($associatedFile1);
                                    }
                                } elseif (file_exists($associatedFile2)) {
                                    if (unlink($associatedFile2)) {
                                        $associatedDeleted++;
                                    } else {
                                        $errors[] = "Failed to delete associated: " . basename($associatedFile2);
                                    }
                                }
                            }
                        } else {
                            $errors[] = "Failed to delete: " . basename($file);
                        }
                    }
                }
            }
        }
    }
    
    error_log("Deleted $totalDeleted main files and $associatedDeleted associated files (OCR/JSON) during distribution end");
    if (!empty($errors)) {
        error_log("File deletion errors: " . implode(', ', $errors));
    }
    
    return $totalDeleted + $associatedDeleted;
}

/**
 * Reset students who received aid back to applicant status, clearing payroll numbers, QR codes, and uploaded documents.
 * Handles schema differences (payroll_no vs payroll_number, qr_code vs qr_code_path).
 * Dynamically discovers document path column names.
 */
function resetGivenStudents($connection) {
    static $columnCache = null;

    if ($columnCache === null) {
        $columnCache = [
            'payroll_no' => false,
            'payroll_number' => false,
            'qr_code_path' => false,
            'qr_code' => false,
            'needs_document_upload' => false,
            'student_type' => false,
            'admin_review_required' => false,
        ];

        // Check for payroll and QR code columns
        $columnQuery = "SELECT column_name FROM information_schema.columns WHERE table_name = 'students' AND column_name IN ('payroll_no','payroll_number','qr_code_path','qr_code','needs_document_upload','student_type','admin_review_required')";
        $columnResult = pg_query($connection, $columnQuery);
        if ($columnResult) {
            while ($row = pg_fetch_assoc($columnResult)) {
                $name = $row['column_name'];
                if (array_key_exists($name, $columnCache)) {
                    $columnCache[$name] = true;
                }
            }
        }
        
        // Find all columns that likely contain document paths
        // Look for columns ending in _path, _file, or containing 'document', 'upload', etc.
        $docColumnsQuery = "SELECT column_name FROM information_schema.columns 
                           WHERE table_name = 'students' 
                           AND (column_name LIKE '%_path' 
                                OR column_name LIKE '%_file' 
                                OR column_name LIKE '%document%'
                                OR column_name LIKE '%upload%')";
        $docColumnsResult = pg_query($connection, $docColumnsQuery);
        $columnCache['document_columns'] = [];
        if ($docColumnsResult) {
            while ($row = pg_fetch_assoc($docColumnsResult)) {
                $columnCache['document_columns'][] = $row['column_name'];
            }
        }
        // Safety: ensure unique column names to avoid duplicate SET assignments
        if (!empty($columnCache['document_columns'])) {
            $columnCache['document_columns'] = array_values(array_unique($columnCache['document_columns']));
            
            // EXCLUDE explicitly handled columns to prevent multiple assignments in UPDATE
            // needs_document_upload matches '%upload%' and qr_code_path matches '%_path', etc.
            $explicitHandled = ['payroll_no','payroll_number','qr_code_path','qr_code','needs_document_upload','student_type','admin_review_required'];
            $toExclude = [];
            foreach ($explicitHandled as $colName) {
                if (isset($columnCache[$colName]) && $columnCache[$colName] === true) {
                    $toExclude[] = $colName;
                }
            }
            if (!empty($toExclude)) {
                $columnCache['document_columns'] = array_values(array_diff($columnCache['document_columns'], $toExclude));
            }
        }
    }

    // IMPORTANT: Clear document records BEFORE changing status
    // Delete document records for students who have 'given' status (they're about to be reset)
    // This ensures upload pages show clean slate for next cycle
    $deleteDocuments = pg_query($connection, "DELETE FROM documents WHERE student_id IN (SELECT student_id FROM students WHERE status = 'given')");
    if ($deleteDocuments === false) {
        error_log('Warning: Failed to clear document records: ' . pg_last_error($connection));
        $docsDeleted = 0;
    } else {
        $docsDeleted = pg_affected_rows($deleteDocuments);
        error_log("Cleared $docsDeleted document records from documents table");
    }
    
    // Clear grade_uploads table records as well (before status change)
    $deleteGradeUploads = pg_query($connection, "DELETE FROM grade_uploads WHERE student_id IN (SELECT student_id FROM students WHERE status = 'given')");
    if ($deleteGradeUploads === false) {
        error_log('Warning: Failed to clear grade_uploads records: ' . pg_last_error($connection));
        $gradesDeleted = 0;
    } else {
        $gradesDeleted = pg_affected_rows($deleteGradeUploads);
        error_log("Cleared $gradesDeleted grade upload records from grade_uploads table");
    }

    // Build SET clause for resetting student data
    $setParts = ["status = 'applicant'"];

    if ($columnCache['payroll_no']) {
        $setParts[] = 'payroll_no = NULL';
    } elseif ($columnCache['payroll_number']) {
        $setParts[] = 'payroll_number = NULL';
    }

    if ($columnCache['qr_code_path']) {
        $setParts[] = 'qr_code_path = NULL';
    } elseif ($columnCache['qr_code']) {
        $setParts[] = 'qr_code = NULL';
    }
    
    // Mark students as requiring document re-upload for the next cycle
    if ($columnCache['needs_document_upload']) {
        $setParts[] = 'needs_document_upload = TRUE';
    }
    // If a simple student_type flag exists, classify as existing_student
    if ($columnCache['student_type']) {
        $setParts[] = "student_type = 'existing_student'";
    }
    // Migrated students should no longer be labeled as migrated next cycle
    if ($columnCache['admin_review_required']) {
        $setParts[] = 'admin_review_required = FALSE';
    }
    
    // Reset all discovered document path columns (deduplicated)
    $seenCols = [];
    foreach ($columnCache['document_columns'] as $docColumn) {
        if (!isset($seenCols[$docColumn])) {
            $seenCols[$docColumn] = true;
            $setParts[] = pg_escape_identifier($connection, $docColumn) . " = NULL";
        }
    }

    $query = "UPDATE students SET " . implode(', ', $setParts) . " WHERE status = 'given'";
    $result = pg_query($connection, $query);

    if (!$result) {
        throw new Exception('Failed to reset students: ' . pg_last_error($connection));
    }

    $affectedRows = pg_affected_rows($result);

    // Remove generated QR codes for all students
    $deleteQr = pg_query($connection, "DELETE FROM qr_codes");
    if ($deleteQr === false) {
        throw new Exception('Failed to clear QR codes: ' . pg_last_error($connection));
    }
    
    // Delete all uploaded files from the file system
    // Note: This happens AFTER compression, so files are already archived
    $filesDeleted = deleteAllStudentUploads();
    
    error_log("Reset $affectedRows students, cleared " . count($columnCache['document_columns']) . " document columns, deleted $docsDeleted document records, $gradesDeleted grade records, and $filesDeleted upload files");

    return $affectedRows;
}

/**
 * Clear schedule records and reset schedule metadata.
 */
function clearScheduleData($connection) {
    $deleteResult = pg_query($connection, "DELETE FROM schedules");
    if ($deleteResult === false) {
        throw new Exception('Failed to clear schedules: ' . pg_last_error($connection));
    }

    $settingsPath = __DIR__ . '/../../data/municipal_settings.json';
    $settings = [];
    if (file_exists($settingsPath)) {
        $decoded = json_decode(file_get_contents($settingsPath), true);
        if (is_array($decoded)) {
            $settings = $decoded;
        }
    }

    $settings['schedule_published'] = false;
    if (isset($settings['schedule_meta'])) {
        unset($settings['schedule_meta']);
    }

    $encoded = json_encode($settings, JSON_PRETTY_PRINT);
    if ($encoded === false) {
        throw new Exception('Failed to encode schedule settings to JSON');
    }

    if (file_put_contents($settingsPath, $encoded) === false) {
        throw new Exception('Failed to update schedule settings file');
    }
}

$distManager = new DistributionManager();
$compressionService = new FileCompressionService();

// Handle AJAX requests BEFORE workflow check
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Extend execution time for compression operations (can take several minutes)
    set_time_limit(600); // 10 minutes
    ini_set('max_execution_time', 600);
    
    // Prevent connection timeout during long operations
    ignore_user_abort(true);
    
    // Start output buffering to catch any stray output
    ob_start();
    
    // Clear any previous output
    if (ob_get_length()) ob_clean();
    
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'verify_password') {
        $password = $_POST['password'] ?? '';
        $adminId = $_SESSION['admin_id'];
        
        // Verify admin password
        $query = "SELECT password FROM admins WHERE admin_id = $1";
        $result = pg_query_params($connection, $query, [$adminId]);
        
        if ($result && $row = pg_fetch_assoc($result)) {
            if (password_verify($password, $row['password'])) {
                ob_clean();
                echo json_encode(['success' => true, 'message' => 'Password verified']);
            } else {
                ob_clean();
                echo json_encode(['success' => false, 'message' => 'Incorrect password']);
            }
        } else {
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'Authentication failed']);
        }
        exit();
    }
    
    if ($action === 'end_distribution') {
        $distributionId = $_POST['distribution_id'] ?? '';
        $password = $_POST['password'] ?? '';
        $allowEmptyOverride = isset($_POST['allow_empty']) && $_POST['allow_empty'] === '1';
        $adminId = $_SESSION['admin_id'];
        
        // Log what we're receiving
        error_log("End Distribution Request - ID: " . $distributionId . ", Admin: " . $adminId);
        
        // Verify password before proceeding
        $query = "SELECT password FROM admins WHERE admin_id = $1";
        $result = pg_query_params($connection, $query, [$adminId]);
        
        if (!$result || !($row = pg_fetch_assoc($result)) || !password_verify($password, $row['password'])) {
            echo json_encode(['success' => false, 'message' => 'Password verification failed']);
            exit();
        }
        
        // CRITICAL: Check if this distribution has already been compressed
        $compressionCheckQuery = "SELECT files_compressed FROM distribution_snapshots WHERE distribution_id = $1 LIMIT 1";
        $compressionCheckResult = pg_query_params($connection, $compressionCheckQuery, [$distributionId]);
        if ($compressionCheckResult && pg_num_rows($compressionCheckResult) > 0) {
            $compressionCheck = pg_fetch_assoc($compressionCheckResult);
            if ($compressionCheck['files_compressed'] === 't' || $compressionCheck['files_compressed'] === true) {
                ob_clean();
                echo json_encode([
                    'success' => false,
                    'message' => 'This distribution has already been ended and compressed. Please refresh the page.',
                    'already_completed' => true
                ]);
                exit();
            }
        }
        
        // For config-based distributions, use FileCompressionService directly
        try {
            // DO NOT start transaction here - it causes issues with compression service
            // The compression service doesn't use transactions anyway
            
            error_log("end_distribution.php: Calling compressDistribution with ID = '$distributionId'");
            
            // Compress files FIRST (while students still have 'given' status)
            $compressionResult = $compressionService->compressDistribution($distributionId, $adminId);

            if (!$compressionResult['success']) {
                $compressionMessage = $compressionResult['message'] ?? 'Compression failed';
                $emptyFailure = stripos($compressionMessage, 'no files') !== false || stripos($compressionMessage, "no students") !== false;

                if ($emptyFailure && !$allowEmptyOverride) {
                    ob_clean();
                    echo json_encode([
                        'success' => false,
                        'message' => $compressionMessage,
                        'can_override' => true,
                        'override_reason' => $compressionMessage
                    ]);
                    exit();
                }

                if (!$emptyFailure || !$allowEmptyOverride) {
                    ob_clean();
                    echo json_encode(['success' => false, 'message' => 'Compression failed: ' . $compressionMessage]);
                    exit();
                }

                // Developer override: proceed even if no files were found
                $compressionResult['skipped'] = true;
                $compressionResult['override_used'] = true;
                if (empty($compressionResult['message'])) {
                    $compressionResult['message'] = 'Compression skipped: no files were detected.';
                }
            }
            
            
            // Update distribution_snapshots with compression information
            // ALWAYS mark as compressed (even if skipped) to prevent re-showing the distribution
            $archive_filename = null;
            $compressed_size = 0;
            $file_count = 0;
            $compression_ratio = 0.0;
            
            if (!empty($compressionResult['success']) && !empty($compressionResult['archive_path'])) {
                $archive_filename = basename($compressionResult['archive_path']);
                $compressed_size = isset($compressionResult['size']) ? intval($compressionResult['size']) : 0;
                $file_count = isset($compressionResult['file_count']) ? intval($compressionResult['file_count']) : 0;
                $compression_ratio = isset($compressionResult['compression_ratio']) ? floatval($compressionResult['compression_ratio']) : 0.0;
            }
            
            // Find the snapshot by distribution_id and mark as compressed
            // This prevents the distribution from appearing on end_distribution.php again
            $update_snapshot_query = "
                UPDATE distribution_snapshots 
                SET 
                    files_compressed = true,
                    compression_date = NOW(),
                    archive_filename = $1,
                    compressed_size = $2,
                    compression_ratio = $3,
                    total_files_count = $4
                WHERE distribution_id = $5
            ";
            $update_result = pg_query_params($connection, $update_snapshot_query, [
                $archive_filename,
                $compressed_size,
                $compression_ratio,
                $file_count,
                $distributionId
            ]);
            
            if (!$update_result) {
                error_log("Warning: Failed to update distribution snapshot with compression info: " . pg_last_error($connection));
            }
            
            // Reset all students with 'given' status back to 'applicant'
            // Payroll numbers and QR codes are cleared for the next cycle
            $studentsReset = resetGivenStudents($connection);
            
            error_log("End Distribution: Reset $studentsReset students from 'given' to 'applicant' and cleared payroll/QR codes");

            // Clear all schedule data for the next cycle
            clearScheduleData($connection);
            
            // CRITICAL: Set distribution status back to inactive and clear academic period
            $config_resets = [
                ['distribution_status', 'inactive'],
                ['uploads_enabled', '0']
            ];
            
            // Also clear the current academic period to allow new distribution
            $clear_period_keys = ['current_academic_year', 'current_semester', 'documents_deadline'];
            
            foreach ($config_resets as [$key, $value]) {
                pg_query_params($connection, 
                    "INSERT INTO config (key, value) VALUES ($1, $2) ON CONFLICT (key) DO UPDATE SET value = $2",
                    [$key, $value]
                );
            }
            
            foreach ($clear_period_keys as $key) {
                pg_query_params($connection, "DELETE FROM config WHERE key = $1", [$key]);
            }
            
            error_log("End Distribution: Set distribution status to inactive and cleared academic period configuration");
            
            // No transaction to commit - each operation is auto-committed
            
            $resultMessage = (!empty($compressionResult['skipped']))
                ? 'Distribution ended successfully (compression skipped)'
                : 'Distribution ended successfully and status set to inactive';

            $result = [
                'success' => true,
                'message' => $resultMessage,
                'distribution_id' => $distributionId,
                'students_reset' => $studentsReset,
                'compression' => $compressionResult
            ];
            
            ob_clean();
            echo json_encode($result);
            
        } catch (Exception $e) {
            // No transaction to rollback
            ob_clean();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit();
    }
    
    if ($action === 'compress_distribution') {
        $distributionId = intval($_POST['distribution_id'] ?? 0);
        $result = $compressionService->compressDistribution($distributionId, $_SESSION['admin_id']);
        ob_clean();
        echo json_encode($result);
        exit();
    }
}

// NOW check workflow permissions for regular page loads
require_once __DIR__ . '/../../includes/workflow_control.php';
$workflow_status = getWorkflowStatus($connection);
if (!$workflow_status['can_manage_applicants']) {
    $_SESSION['error_message'] = "Please start a distribution first before accessing end distribution. Go to Distribution Control to begin.";
    header("Location: distribution_control.php");
    exit;
}

// CRITICAL ACCESS CONTROL: Check if distribution has been completed
// Admin must click "Complete Distribution" in scan_qr.php before accessing this page
// AND the distribution must have actual students who received aid
// IMPORTANT: Only show distributions that have NOT been compressed/archived yet
$has_completed_snapshot = false;
$completed_snapshot_id = null;
$check_snapshot_query = "
    SELECT ds.snapshot_id, ds.distribution_id, ds.academic_year, ds.semester, 
           ds.total_students_count, ds.finalized_at,
           COUNT(dsr.student_id) as actual_distributed_count
    FROM distribution_snapshots ds
    LEFT JOIN distribution_student_records dsr ON ds.snapshot_id = dsr.snapshot_id
    WHERE ds.finalized_at IS NOT NULL 
    AND ds.finalized_at >= CURRENT_DATE - INTERVAL '7 days'
    AND (ds.files_compressed = FALSE OR ds.files_compressed IS NULL)
    GROUP BY ds.snapshot_id, ds.distribution_id, ds.academic_year, ds.semester, 
             ds.total_students_count, ds.finalized_at
    HAVING COUNT(dsr.student_id) > 0
    ORDER BY ds.finalized_at DESC
    LIMIT 1
";
$check_result = pg_query($connection, $check_snapshot_query);
if ($check_result && pg_num_rows($check_result) > 0) {
    $has_completed_snapshot = true;
    $completed_snapshot = pg_fetch_assoc($check_result);
    $completed_snapshot_id = $completed_snapshot['snapshot_id'];
}

// If no completed snapshot exists, do NOT redirect; we will render the page
// and show a helpful message with disabled actions instead.

// Get current distribution info from config and students
$activeDistributions = [];
$distribution_status = $workflow_status['distribution_status'] ?? 'inactive';

if (in_array($distribution_status, ['preparing', 'active']) && $has_completed_snapshot) {
    // Use data from completed snapshot
    $distribution_id = $completed_snapshot['distribution_id'];
    error_log("end_distribution.php: Using distribution_id from snapshot = '$distribution_id'");
    $academic_year = $completed_snapshot['academic_year'];
    $semester = $completed_snapshot['semester'];
    $student_count = $completed_snapshot['total_students_count'];
    
    // Count students in distribution_student_records for accuracy
    $record_count_query = pg_query_params($connection, 
        "SELECT COUNT(*) as count FROM distribution_student_records WHERE snapshot_id = $1",
        [$completed_snapshot_id]
    );
    if ($record_count_query) {
        $record_row = pg_fetch_assoc($record_count_query);
        $student_count = intval($record_row['count']); // More accurate than students table
    }
    
    // Count total files in student folders (recursive; exclude OCR/auxiliary files)
    $file_count = 0;
    $total_size = 0;
    $document_types = ['enrollment_forms', 'grades', 'id_pictures', 'indigency', 'letter_to_mayor'];
    $exclude_exts = ['.ocr.txt', '.verify.json', '.confidence.json', '.tsv', '.ocr.json'];

    $countFilesRecursively = function($baseDir) use ($exclude_exts) {
        $count = 0;
        $size = 0;
        if (!is_dir($baseDir)) {
            return [$count, $size];
        }
        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($it as $spl) {
                if ($spl->isFile()) {
                    $path = $spl->getPathname();
                    $isAssoc = false;
                    foreach ($exclude_exts as $ext) {
                        if (substr($path, -strlen($ext)) === $ext) {
                            $isAssoc = true;
                            break;
                        }
                    }
                    if (!$isAssoc) {
                        $count++;
                        $size += (int)$spl->getSize();
                    }
                }
            }
        } catch (Exception $e) {
            // Ignore counting errors; return what we have
        }
        return [$count, $size];
    };

    foreach ($document_types as $type) {
        $folder = $pathConfig->getStudentPath() . DIRECTORY_SEPARATOR . $type;
        [$c, $s] = $countFilesRecursively($folder);
        $file_count += $c;
        $total_size += $s;
    }
    
    $activeDistributions[] = [
        'id' => $distribution_id,
        'created_at' => $completed_snapshot['finalized_at'], // Actual finalization time
        'year_level' => null,
        'semester' => $semester,
        'academic_year' => $academic_year,
        'student_count' => $student_count,
        'file_count' => $file_count,
        'total_size' => $total_size,
        'snapshot_id' => $completed_snapshot_id
    ];
}

$endedAwaitingCompression = []; // Not needed for config-based system

$pageTitle = "End Distribution";
?>
<?php $page_title='End Distribution'; $extra_css=['../../assets/css/admin/table_core.css']; include __DIR__ . '/../../includes/admin/admin_head.php'; ?>
<body>
<?php include __DIR__ . '/../../includes/admin/admin_topbar.php'; ?>
    <div id="wrapper" class="admin-wrapper">
        <?php include __DIR__ . '/../../includes/admin/admin_sidebar.php'; ?>
        <?php include __DIR__ . '/../../includes/admin/admin_header.php'; ?>
        <section class="home-section" id="mainContent">
            <div class="container-fluid py-4 px-4">
                
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="fw-bold mb-1">End Distribution</h1>
                        <p class="text-muted mb-0">Compress student files and reset system for next cycle</p>
                    </div>
                </div>

                <!-- Finalized Distributions Ready for Compression -->
                <div class="card shadow mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-file-zip"></i> Finalized Distributions</h5>
                    </div>
                    <div class="card-body">
                    <?php if (empty($activeDistributions)): ?>
                        <?php if (!$has_completed_snapshot): ?>
                            <div class="alert alert-info d-flex align-items-center" role="alert">
                                <i class="bi bi-info-circle me-2"></i>
                                <div>
                                    Complete the distribution first in QR Scanning (click "Complete Distribution"). Once finalized, you can compress and reset here.
                                </div>
                            </div>
                            <div class="text-center">
                                <button class="btn btn-primary" disabled title="Disabled until distribution is completed">
                                    <i class="bi bi-file-zip-fill"></i> Compress & Reset
                                </button>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle"></i> No finalized distributions found in the recent period.
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Created</th>
                                        <th>Academic Year</th>
                                        <th>Semester</th>
                                        <th>Students</th>
                                        <th>Files</th>
                                        <th>Total Size</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($activeDistributions as $dist): ?>
                                        <tr>
                                            <td>#<?php echo $dist['id']; ?></td>
                                            <td><?php echo date('M d, Y', strtotime($dist['created_at'])); ?></td>
                                            <td><?php echo $dist['academic_year'] ?: 'N/A'; ?></td>
                                            <td><?php echo $dist['semester'] ?: 'N/A'; ?></td>
                                            <td><?php echo $dist['student_count']; ?></td>
                                            <td><?php echo $dist['file_count']; ?></td>
                                            <td><?php echo number_format($dist['total_size'] / 1024 / 1024, 2); ?> MB</td>
                                            <td><span class="badge bg-success">Active</span></td>
                                            <td class="action-buttons">
                                                <button class="btn btn-primary btn-sm" 
                                                        onclick="showPasswordModal('<?php echo $dist['id']; ?>')"
                                                        title="Compress files and reset system">
                                                    <i class="bi bi-file-zip-fill"></i> Compress & Reset
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                    </div>
                </div>

                <!-- Ended Distributions Awaiting Compression -->
                <?php if (!empty($endedAwaitingCompression)): ?>
                <div class="card shadow mb-4">
                    <div class="card-header bg-warning text-dark">
                        <h5 class="mb-0"><i class="bi bi-hourglass-split"></i> Ended Distributions Awaiting Compression</h5>
                    </div>
                    <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Created</th>
                                    <th>Ended</th>
                                    <th>Academic Year</th>
                                    <th>Semester</th>
                                    <th>Students</th>
                                    <th>Files</th>
                                    <th>Total Size</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($endedAwaitingCompression as $dist): ?>
                                    <tr>
                                        <td>#<?php echo $dist['id']; ?></td>
                                        <td><?php echo date('M d, Y', strtotime($dist['created_at'])); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($dist['ended_at'])); ?></td>
                                        <td><?php echo $dist['academic_year'] ?: 'N/A'; ?></td>
                                        <td><?php echo $dist['semester'] ?: 'N/A'; ?></td>
                                        <td><?php echo $dist['student_count']; ?></td>
                                        <td><?php echo $dist['file_count']; ?></td>
                                        <td><?php echo number_format($dist['original_size'] / 1024 / 1024, 2); ?> MB</td>
                                        <td>
                                            <button class="btn btn-sm btn-primary" 
                                                    onclick="compressDistribution(<?php echo $dist['id']; ?>)">
                                                <i class="bi bi-file-zip"></i> Compress Now
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <!-- Password Confirmation Modal -->
    <div class="modal fade" id="passwordModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-shield-lock-fill"></i> Confirm Action</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning mb-3">
                        <i class="bi bi-exclamation-triangle-fill"></i> <strong>Critical Action!</strong>
                        <p class="mb-0 mt-2">This will:</p>
                        <ul class="mb-0 mt-2">
                            <li>Compress all student files into a ZIP archive</li>
                            <li>Delete original student uploads from the server</li>
                            <li>Reset all students from "Given" back to "Applicant" status</li>
                            <li>Clear payroll numbers and QR codes</li>
                            <li>Clear all distribution schedules</li>
                            <li>Prepare the system for the next distribution cycle</li>
                        </ul>
                    </div>
                    
                    <div class="mb-3">
                        <label for="confirmPassword" class="form-label">
                            <i class="bi bi-key-fill"></i> Enter your password to confirm:
                        </label>
                        <input type="password" 
                               class="form-control form-control-lg" 
                               id="confirmPassword" 
                               placeholder="Enter your password"
                               autocomplete="current-password">
                        <div id="passwordError" class="text-danger mt-2" style="display: none;">
                            <i class="bi bi-x-circle"></i> <span id="passwordErrorText"></span>
                        </div>
                    </div>
                    
                    <div class="text-muted small">
                        <i class="bi bi-info-circle"></i> This action cannot be undone. Please ensure all data is correct before proceeding.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle"></i> Cancel
                    </button>
                    <button type="button" class="btn btn-danger" id="confirmEndBtn" onclick="confirmEndDistribution()">
                        <i class="bi bi-lock-fill"></i> Confirm & Proceed
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Progress Modal -->
    <div class="modal fade" id="progressModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-gear-fill"></i> Processing Distribution</h5>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <div class="progress">
                            <div id="progressBar" class="progress-bar progress-bar-striped progress-bar-animated" 
                                 role="progressbar" style="width: 0%">0%</div>
                        </div>
                    </div>
                    <div id="statusMessage" class="alert alert-info">
                        <i class="bi bi-info-circle"></i> Initializing...
                    </div>
                    <div class="progress-log" id="progressLog"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" id="closeProgressBtn" disabled>Close</button>
                </div>
            </div>
        </div>
    </div>

    <style>
        .badge {
            padding: 0.5rem 0.75rem;
            font-size: 0.875rem;
        }
        
        .action-buttons .btn {
            min-width: 140px;
        }
        
        .progress-log {
            max-height: 300px;
            overflow-y: auto;
            background: #f8f9fa;
            border-radius: 0.375rem;
            padding: 1rem;
        }
        
        .log-entry {
            font-family: 'Courier New', monospace;
            font-size: 0.875rem;
            padding: 0.25rem 0;
            border-bottom: 1px solid #e9ecef;
        }
        
        .log-entry:last-child {
            border-bottom: none;
        }
        
        #passwordError {
            animation: shake 0.3s;
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
        }
    </style>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let progressModal;
        let passwordModal;
        let currentDistributionId = null;

        document.addEventListener('DOMContentLoaded', () => {
            const progressModalEl = document.getElementById('progressModal');
            const passwordModalEl = document.getElementById('passwordModal');

            if (progressModalEl && window.bootstrap?.Modal) {
                progressModal = new bootstrap.Modal(progressModalEl);
            }

            if (passwordModalEl && window.bootstrap?.Modal) {
                passwordModal = new bootstrap.Modal(passwordModalEl);
            }

            const confirmPasswordInput = document.getElementById('confirmPassword');
            if (confirmPasswordInput) {
                confirmPasswordInput.addEventListener('keypress', (e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        confirmEndDistribution();
                    }
                });
            }

            const closeProgressBtn = document.getElementById('closeProgressBtn');
            if (closeProgressBtn) {
                closeProgressBtn.addEventListener('click', () => {
                    progressModal?.hide();
                    location.reload();
                });
            }
        });

        function showPasswordModal(distId) {
            currentDistributionId = distId;

            const passwordInput = document.getElementById('confirmPassword');
            const errorDiv = document.getElementById('passwordError');

            if (passwordInput) {
                passwordInput.value = '';
            }

            if (errorDiv) {
                errorDiv.style.display = 'none';
            }

            if (passwordModal) {
                passwordModal.show();

                // Focus on password field after modal is shown
                if (passwordInput) {
                    setTimeout(() => passwordInput.focus(), 500);
                }
            }
        }

        function confirmEndDistribution() {
            const passwordInput = document.getElementById('confirmPassword');
            const password = passwordInput ? passwordInput.value : '';
            const errorDiv = document.getElementById('passwordError');
            const errorText = document.getElementById('passwordErrorText');
            const confirmBtn = document.getElementById('confirmEndBtn');
            
            if (!password) {
                errorText.textContent = 'Password is required';
                errorDiv.style.display = 'block';
                return;
            }
            
            // Disable button and show loading
            confirmBtn.disabled = true;
            confirmBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Verifying...';
            
            // Verify password first
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=verify_password&password=' + encodeURIComponent(password)
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Server returned ' + response.status);
                }
                return response.text();
            })
            .then(text => {
                let data;
                try {
                    data = JSON.parse(text);
                } catch (e) {
                    console.error('Invalid JSON response:', text);
                    throw new Error('Invalid server response');
                }
                
                if (data.success) {
                    // Password verified, proceed with ending distribution
                    passwordModal?.hide();
                    endDistribution(currentDistributionId, password);
                } else {
                    // Show error
                    errorText.textContent = data.message || 'Incorrect password';
                    errorDiv.style.display = 'block';
                    confirmBtn.disabled = false;
                    confirmBtn.innerHTML = '<i class="bi bi-lock-fill"></i> Confirm & Proceed';
                    
                    // Clear password field
                    if (passwordInput) {
                        passwordInput.value = '';
                        passwordInput.focus();
                    }
                }
            })
            .catch(error => {
                console.error('Password verification error:', error);
                errorText.textContent = error.message || 'Network error. Please try again.';
                errorDiv.style.display = 'block';
                confirmBtn.disabled = false;
                confirmBtn.innerHTML = '<i class="bi bi-lock-fill"></i> Confirm & Proceed';
            });
        }
        
        function updateProgress(percent, message, logEntry = null) {
            const progressBar = document.getElementById('progressBar');
            if (progressBar && typeof percent === 'number') {
                progressBar.style.width = percent + '%';
                progressBar.textContent = percent + '%';
            }

            const statusMessage = document.getElementById('statusMessage');
            if (statusMessage && message) {
                statusMessage.innerHTML = '<i class="bi bi-info-circle"></i> ' + message;
            }
            
            if (logEntry) {
                const logDiv = document.getElementById('progressLog');
                const entry = document.createElement('div');
                entry.className = 'log-entry';
                entry.textContent = logEntry;
                logDiv?.appendChild(entry);
                if (logDiv) {
                    logDiv.scrollTop = logDiv.scrollHeight;
                }
            }
        }
        
        function endDistribution(distId, password, allowEmpty = false) {
            progressModal?.show();

            const logDiv = document.getElementById('progressLog');
            if (!allowEmpty && logDiv) {
                logDiv.innerHTML = '';
            }

            document.getElementById('closeProgressBtn').disabled = true;

            if (allowEmpty) {
                updateProgress(15, 'Continuing without compression files', 'Developer override enabled. Proceeding without archive.');
            } else {
                updateProgress(10, 'Ending distribution...', 'Starting end & compress process...');
            }

            const params = new URLSearchParams();
            params.append('action', 'end_distribution');
            params.append('distribution_id', distId);
            params.append('password', password);
            if (allowEmpty) {
                params.append('allow_empty', '1');
            }

            // Use AbortController for timeout (10 minutes for compression)
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 600000); // 10 minutes

            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: params.toString(),
                signal: controller.signal
            })
            .then(response => {
                clearTimeout(timeoutId);
                if (!response.ok) {
                    throw new Error('Server returned ' + response.status);
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    updateProgress(50, data.message || 'Distribution ended successfully', '✓ Distribution marked as ended');

                    if (data.compression) {
                        if (data.compression.success) {
                            updateProgress(60, 'Starting compression...', 'Compressing and archiving files...');
                            const stats = data.compression.statistics;
                            updateProgress(90, 'Compression completed', '✓ Files compressed successfully');
                            updateProgress(100, 'Complete!', 
                                `✓ Processed ${stats.students_processed} students, ${stats.files_compressed} files`);
                            updateProgress(null, null, 
                                `✓ Space saved: ${(stats.space_saved / 1024 / 1024).toFixed(2)} MB (${stats.compression_ratio}% compression)`);
                            updateProgress(null, null, 
                                `✓ Archive: ${stats.archive_location}`);
                            
                            document.getElementById('statusMessage').innerHTML = 
                                '<i class="bi bi-check-circle"></i> <strong>Distribution Ended & Compressed!</strong><br>' +
                                'Archive location: ' + stats.archive_location + '<br>Student uploads have been deleted.';
                            document.getElementById('statusMessage').className = 'alert alert-success';
                        } else if (data.compression.skipped) {
                            updateProgress(90, 'Compression skipped (no files detected)', '⚠️ ' + (data.compression.message || 'No files were available for compression.'));
                            updateProgress(100, 'Complete!', '✓ Distribution ended without compression');
                            document.getElementById('statusMessage').innerHTML =
                                '<i class="bi bi-check-circle"></i> <strong>Distribution ended.</strong><br>' +
                                'No files were available for compression. Developer override completed the workflow.';
                            document.getElementById('statusMessage').className = 'alert alert-success';
                        } else {
                            updateProgress(100, 'Ended but compression failed', '⚠️ ' + data.compression.message);
                            document.getElementById('statusMessage').className = 'alert alert-warning';
                        }
                    }

                    document.getElementById('closeProgressBtn').disabled = false;
                    setTimeout(() => location.reload(), 3000);
                } else if (data.already_completed) {
                    // Distribution was already compressed - show message and reload
                    updateProgress(100, data.message, '⚠️ Already completed');
                    document.getElementById('statusMessage').innerHTML =
                        '<i class="bi bi-info-circle"></i> ' + data.message;
                    document.getElementById('statusMessage').className = 'alert alert-info';
                    document.getElementById('closeProgressBtn').disabled = false;
                    setTimeout(() => location.reload(), 2000);
                } else if (data.can_override) {
                    const reason = data.override_reason || data.message || 'No files were found to compress.';
                    updateProgress(0, 'Override available', '⚠️ ' + reason);
                    document.getElementById('statusMessage').innerHTML =
                        '<i class="bi bi-exclamation-triangle"></i> No files were detected for compression. You can override this check for development purposes.';
                    document.getElementById('statusMessage').className = 'alert alert-warning';
                    document.getElementById('closeProgressBtn').disabled = false;

                    if (confirm(reason + '\n\nProceed without compression?')) {
                        endDistribution(distId, password, true);
                    }
                } else {
                    updateProgress(0, 'Error: ' + data.message, '✗ Failed: ' + data.message);
                    document.getElementById('statusMessage').className = 'alert alert-danger';
                    document.getElementById('closeProgressBtn').disabled = false;
                }
            })
            .catch(error => {
                clearTimeout(timeoutId);
                
                // Check if the error is a timeout/abort
                if (error.name === 'AbortError') {
                    updateProgress(50, 'Request timeout', '⚠️ The operation is taking longer than expected...');
                    document.getElementById('statusMessage').innerHTML =
                        '<i class="bi bi-hourglass-split"></i> <strong>Operation may still be running.</strong><br>' +
                        'The server is still processing. Please wait a moment and then refresh the page to check if the distribution was completed successfully.';
                    document.getElementById('statusMessage').className = 'alert alert-warning';
                } else {
                    // Network error - operation may have completed on server
                    updateProgress(50, 'Connection issue', '⚠️ Lost connection to server');
                    document.getElementById('statusMessage').innerHTML =
                        '<i class="bi bi-exclamation-triangle"></i> <strong>Connection lost during processing.</strong><br>' +
                        'The operation may have completed successfully on the server. Please refresh the page to verify.';
                    document.getElementById('statusMessage').className = 'alert alert-warning';
                }
                document.getElementById('closeProgressBtn').disabled = false;
            });
        }
        
        // Fallback in case DOMContentLoaded didn't attach listener (script defer issues)
        if (!document.getElementById('closeProgressBtn')?.onclick) {
            document.getElementById('closeProgressBtn')?.addEventListener('click', () => {
                progressModal?.hide();
                location.reload();
            });
        }
    </script>
</body>
</html>
