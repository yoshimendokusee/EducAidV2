<?php
// Start session only if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// CRITICAL: Detect AJAX requests early and set up error handling for JSON responses
$is_ajax_request = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                   strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
$is_ajax_post = $_SERVER['REQUEST_METHOD'] === 'POST' && 
                (isset($_POST['ajax_upload']) || isset($_POST['process_document']) || 
                 isset($_POST['update_year_level']) || isset($_POST['cancel_preview']) || 
                 isset($_POST['start_reupload']));

// For AJAX requests, catch any fatal errors and return JSON
if ($is_ajax_request || $is_ajax_post) {
    // Set up error handler to catch PHP warnings/notices gracefully for AJAX
    set_error_handler(function($errno, $errstr, $errfile, $errline) {
        // Treat minor levels as non-fatal: log and continue
        $nonFatal = [E_NOTICE, E_USER_NOTICE, E_WARNING, E_USER_WARNING, E_DEPRECATED, E_USER_DEPRECATED, E_STRICT];
        if (in_array($errno, $nonFatal, true)) {
            error_log("AJAX non-fatal: $errstr in " . basename($errfile) . ":$errline (errno=$errno)");
            // Return true to indicate the error was handled and prevent output
            return true;
        }
        // For anything else (recoverable, etc.), return JSON 500
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Server error: ' . $errstr,
            'debug' => ['file' => basename($errfile), 'line' => $errline]
        ]);
        exit;
    });
    
    // Set up exception handler
    set_exception_handler(function($exception) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Server exception: ' . $exception->getMessage(),
            'debug' => ['file' => basename($exception->getFile()), 'line' => $exception->getLine()]
        ]);
        exit;
    });
    
    // Ensure fatal errors also return JSON
    register_shutdown_function(function() use ($is_ajax_request, $is_ajax_post) {
        $error = error_get_last();
        if ($error && ($is_ajax_request || $is_ajax_post) && 
            in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Fatal error: ' . $error['message'],
                'debug' => ['file' => basename($error['file']), 'line' => $error['line']]
            ]);
        }
    });
}

// Disable output buffering to prevent header issues
if (ob_get_level()) {
    ob_end_clean();
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/FilePathConfig.php';
require_once __DIR__ . '/../../services/UnifiedFileService.php';
require_once __DIR__ . '/../../services/DocumentReuploadService.php';
require_once __DIR__ . '/../../services/EnrollmentFormOCRService.php';

// Initialize FilePathConfig for Railway-compatible path handling
$pathConfig = FilePathConfig::getInstance();

/**
 * Convert absolute file paths to web-accessible relative paths (Railway-compatible)
 * Handles both localhost (Windows/Linux) and Railway (/mnt/assets/) environments
 * 
 * @param string $absolutePath Absolute file path from database
 * @return string Web-accessible relative path (../../assets/uploads/...)
 */
function convertToWebPath($absolutePath) {
    global $pathConfig;
    
    // If already a relative path, ensure proper prefix
    if (strpos($absolutePath, 'assets/uploads/') === 0) {
        return '../../' . $absolutePath;
    }
    
    // Get the base uploads directory from FilePathConfig
    $uploadsDir = $pathConfig->getUploadsDir();
    
    // Normalize path separators
    $absolutePath = str_replace('\\', '/', $absolutePath);
    $uploadsDir = str_replace('\\', '/', $uploadsDir);
    
    // Remove base directory to get relative path
    if (strpos($absolutePath, $uploadsDir) === 0) {
        $relativePath = substr($absolutePath, strlen($uploadsDir));
        $relativePath = ltrim($relativePath, '/');
        return '../../assets/uploads/' . $relativePath;
    }
    
    // Fallback: Try to extract from common patterns
    if (preg_match('#assets/uploads/(.+)$#', $absolutePath, $matches)) {
        return '../../assets/uploads/' . $matches[1];
    }
    
    // If all else fails, return as-is (might be Railway volume path)
    return $absolutePath;
}

// PHPMailer for email notifications
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require __DIR__ . '/../../phpmailer/vendor/autoload.php';

// Check if student is logged in
if (!isset($_SESSION['student_id'])) {
    header("Location: ../../unified_login.php");
    exit;
}

$student_id = $_SESSION['student_id'];

// Enforce session timeout via middleware
require_once __DIR__ . '/../../includes/SessionTimeoutMiddleware.php';
$timeoutMiddleware = new SessionTimeoutMiddleware();
$timeoutStatus = $timeoutMiddleware->handle();

// Initialize services
$fileService = new UnifiedFileService($connection);
$reuploadService = new DocumentReuploadService($connection);

// Get student information including year level details
$student_query = pg_query_params($connection, 
    "SELECT s.*, 
            b.name as barangay_name, 
            u.name as university_name, 
            yl.name as current_year_level
     FROM students s
     LEFT JOIN barangays b ON s.barangay_id = b.barangay_id
     LEFT JOIN universities u ON s.university_id = u.university_id
     LEFT JOIN year_levels yl ON s.year_level_id = yl.year_level_id
     WHERE s.student_id = $1",
    [$student_id]
);

if (!$student_query || pg_num_rows($student_query) === 0) {
    die("Student not found");
}

$student = pg_fetch_assoc($student_query);

// PRIORITY: Check if student is migrated (has admin_review_required flag) FIRST
$is_migrated = isset($student['admin_review_required']) && ($student['admin_review_required'] === 't' || $student['admin_review_required'] === true);

// Check if migrated student needs to complete their profile (missing required credentials)
// This takes PRIORITY over regular year level updates
$needs_university_selection = $is_migrated && (
    empty($student['university_id']) || 
    empty($student['year_level_id']) || 
    empty($student['mothers_maiden_name']) || 
    empty($student['school_student_id'])
);

// Get current active academic year from distribution config (not just signup_slots)
$current_academic_year = null;

// First try to get from active slot
$current_ay_query = pg_query($connection, "SELECT academic_year FROM signup_slots WHERE is_active = TRUE LIMIT 1");
if ($current_ay_query && pg_num_rows($current_ay_query) > 0) {
    $ay_row = pg_fetch_assoc($current_ay_query);
    $current_academic_year = $ay_row['academic_year'];
}

// If no active slot, check config table for current distribution
if (!$current_academic_year) {
    $config_query = pg_query($connection, "SELECT value FROM config WHERE key = 'current_academic_year'");
    if ($config_query && pg_num_rows($config_query) > 0) {
        $config_row = pg_fetch_assoc($config_query);
        $current_academic_year = $config_row['value'];
    }
}

// If still no academic year, generate one based on current date (July = start of new AY)
if (!$current_academic_year) {
    $current_year = date('Y');
    $current_month = date('n');
    if ($current_month >= 7) {
        $current_academic_year = $current_year . '-' . ($current_year + 1);
    } else {
        $current_academic_year = ($current_year - 1) . '-' . $current_year;
    }
}

// Check if student has updated their year level credentials for the current academic year
// They need to update if:
// 1. They don't have year level data at all, OR
// 2. There's an active distribution and their status_academic_year doesn't match the current one
// BUT: Migrated students with incomplete profiles are handled separately above
$has_year_level_credentials = !empty($student['current_year_level']) &&
                               !empty($student['status_academic_year']) &&
                               $student['is_graduating'] !== null;

// PostgreSQL returns 'f'/'t' strings for booleans.
// Guard against missing column/key (e.g., older schema without needs_upload) to prevent warnings.
$needs_upload = isset($student['needs_upload']) && (
    $student['needs_upload'] === 't' ||
    $student['needs_upload'] === true ||
    $student['needs_upload'] === 1 ||
    $student['needs_upload'] === '1' ||
    $student['needs_upload'] === 'true'
);
$student_status = $student['status'] ?? 'applicant';

// IMPORTANT: Migrated students ALWAYS need to upload documents (treat them like re-upload mode)
// Note: $is_migrated is already defined near the top of the file
if ($is_migrated) {
    $needs_upload = true;
}

// TESTING MODE: Allow re-upload if ?test_reupload=1 is in URL (REMOVE IN PRODUCTION)
$test_mode = isset($_GET['test_reupload']) && $_GET['test_reupload'] == '1';
if ($test_mode) {
    $needs_upload = true;
}

// Determine reupload vs new registrant context
$is_reupload_context = $needs_upload && !$is_migrated; // Migrated uses separate profile modal
$has_prior_year_confirmation = !empty($student['status_academic_year']);

// Year advancement should trigger ONLY when:
// - In reupload context (returning student updating docs)
// - Prior year confirmation exists
// - Distribution academic year is active and changed
// - And student already has some year level credentials (avoid brand new registrant)
$year_changed = $current_academic_year && $has_prior_year_confirmation && $student['status_academic_year'] !== $current_academic_year;
// Missing critical year credential after prior confirmation (e.g., is_graduating null or current_year_level empty)
$credentials_incomplete_after_confirmation = $has_prior_year_confirmation && ($student['is_graduating'] === null || empty($student['current_year_level']));

// Final decision for showing year advancement modal
$needs_year_level_update = false;
if (!$needs_university_selection) {
    if ($is_reupload_context && ($year_changed || $credentials_incomplete_after_confirmation)) {
        $needs_year_level_update = true;
    }
}

$year_level_update_message = '';
$force_update = isset($_GET['force_update']) && $_GET['force_update'] == '1';

// Set appropriate message based on what's needed
if ($needs_university_selection) {
    // PRIORITY: Migrated student profile completion
    $year_level_update_message = "Welcome! As a migrated student, please complete your profile by providing your university, mother's maiden name, school ID, and current year level information.";
} elseif ($needs_year_level_update) {
    if ($year_changed) {
        $year_level_update_message = "Academic Year {$current_academic_year} is active. Please confirm or advance your year level (last recorded A.Y. {$student['status_academic_year']}).";
    } elseif ($credentials_incomplete_after_confirmation) {
        $year_level_update_message = "Please complete missing year credentials (year level or graduation status) to proceed.";
    } elseif ($force_update) {
        $year_level_update_message = "Year credentials update required before continuing.";
    }
}

// (moved needs_upload computation above to prevent undefined variable usage)

// Only allow uploads if:
// 1. Student needs upload (needs_document_upload = true OR is migrated) AND
// 2. Student is NOT active (active students are approved and in read-only mode) AND
// 3. Student is NOT given (students who received aid are in read-only mode)
$can_upload = $needs_upload && $student_status !== 'active' && $student_status !== 'given' && !$test_mode;

// Student is in read-only mode if they're a new registrant (no reupload, not migrated) OR already active OR has received aid
$is_new_registrant = ((!$needs_upload && !$is_migrated) || $student_status === 'active' || $student_status === 'given');

// Get list of documents that need re-upload (if any)
$documents_to_reupload = [];
if ($needs_upload) {
    // Check if documents_to_reupload column exists and has data
    $colCheck = pg_query($connection, 
        "SELECT 1 FROM information_schema.columns 
         WHERE table_name='students' AND column_name='documents_to_reupload'");
    
    if ($colCheck && pg_num_rows($colCheck) > 0 && !empty($student['documents_to_reupload'])) {
        $documents_to_reupload = json_decode($student['documents_to_reupload'], true) ?: [];
    }
    
    // If no specific documents listed, allow all uploads
    if (empty($documents_to_reupload)) {
        $documents_to_reupload = ['00', '01', '02', '03', '04']; // All document types
    }
}

// Parse rejection reasons (if any)
$rejection_reasons_map = [];
if (!empty($student['document_rejection_reasons'])) {
    $rejection_data = json_decode($student['document_rejection_reasons'], true);
    if (is_array($rejection_data)) {
        foreach ($rejection_data as $rejection) {
            if (isset($rejection['code']) && isset($rejection['reason'])) {
                $rejection_reasons_map[$rejection['code']] = [
                    'name' => $rejection['name'] ?? '',
                    'reason' => $rejection['reason']
                ];
            }
        }
    }
}

// Get existing documents
$docs_query = pg_query_params($connection,
    "SELECT document_type_code, file_path, upload_date, 
            ocr_confidence, verification_score,
            verification_status, verification_details
     FROM documents 
     WHERE student_id = $1
     ORDER BY upload_date DESC",
    [$student_id]
);

$existing_documents = [];
while ($doc = pg_fetch_assoc($docs_query)) {
    // Convert absolute file path to web-accessible relative path (Railway-compatible)
    $doc['file_path'] = convertToWebPath($doc['file_path']);
    $existing_documents[$doc['document_type_code']] = $doc;
}

// Document type mapping
$document_types = [
    '04' => [
        'code' => '04',
        'name' => 'ID Picture',
        'icon' => 'person-badge',
        'accept' => 'image/jpeg,image/jpg,image/png',
        'required' => false
    ],
    '00' => [
        'code' => '00',
        'name' => 'Enrollment Assistance Form (EAF)',
        'icon' => 'file-earmark-text',
        'accept' => 'image/jpeg,image/jpg,image/png,application/pdf',
        'required' => true
    ],
    '01' => [
        'code' => '01',
        'name' => 'Academic Grades',
        'icon' => 'file-earmark-bar-graph',
        'accept' => 'image/jpeg,image/jpg,image/png,application/pdf',
        'required' => true
    ],
    '02' => [
        'code' => '02',
        'name' => 'Letter to Mayor',
        'icon' => 'envelope',
        'accept' => 'image/jpeg,image/jpg,image/png,application/pdf',
        'required' => true
    ],
    '03' => [
        'code' => '03',
        'name' => 'Certificate of Indigency',
        'icon' => 'award',
        'accept' => 'image/jpeg,image/jpg,image/png,application/pdf',
        'required' => true
    ]
];

// Initialize session for temporary uploads if not exists
if (!isset($_SESSION['temp_uploads'])) {
    $_SESSION['temp_uploads'] = [];
}

// Initialize session for processing lock
if (!isset($_SESSION['processing_lock'])) {
    $_SESSION['processing_lock'] = [];
}

// Handle AJAX year level update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_year_level'])) {
    // Harden JSON responses: clean buffers and set headers
    while (ob_get_level()) { ob_end_clean(); }
    ini_set('display_errors', '0');
    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    
    try {
        $year_level = $_POST['year_level'] ?? '';
        $is_graduating = isset($_POST['is_graduating']) ? ($_POST['is_graduating'] === '1') : false;
        $academic_year = $_POST['academic_year'] ?? '';
        
        // For migrated students, also require university_id, mothers_maiden_name, and school_student_id
        $university_id = isset($_POST['university_id']) ? intval($_POST['university_id']) : null;
        $mothers_maiden_name = isset($_POST['mothers_maiden_name']) ? trim($_POST['mothers_maiden_name']) : null;
        $school_student_id = isset($_POST['school_student_id']) ? trim($_POST['school_student_id']) : null;
        
        // Debug log
        error_log("UPDATE REQUEST - student_id: {$student_id}, university_id: {$university_id}, mothers_maiden_name: " . ($mothers_maiden_name ?: 'NULL') . ", school_student_id: " . ($school_student_id ?: 'NULL') . ", year_level: {$year_level}, is_graduating: " . ($is_graduating ? 'YES' : 'NO') . ", academic_year: {$academic_year}");
        
        // Idempotency guard: block duplicate identical submissions within a short window
        if (!isset($_SESSION['year_update_guard'])) { $_SESSION['year_update_guard'] = []; }
        $fingerprint = sha1($student_id . '|' . $year_level . '|' . ($is_graduating ? '1' : '0') . '|' . $academic_year . '|' . ($university_id ?? '') . '|' . ($mothers_maiden_name ?? '') . '|' . ($school_student_id ?? ''));
        $now = time();
        // If last success had same fingerprint recently, return success immediately
        if (!empty($_SESSION['year_update_guard']['last_success'])
            && $_SESSION['year_update_guard']['last_success']['fp'] === $fingerprint
            && ($now - $_SESSION['year_update_guard']['last_success']['ts']) < 300) {
            echo json_encode([
                'success' => true,
                'message' => 'Year level already updated. Reloading...'
            ]);
            exit;
        }
        // Prevent concurrent in-progress duplicate
        if (!empty($_SESSION['year_update_guard']['in_progress'])
            && ($now - $_SESSION['year_update_guard']['in_progress']) < 20) {
            http_response_code(200);
            echo json_encode([
                'success' => false,
                'message' => 'Update already in progress. Please wait a moment...'
            ]);
            exit;
        }
        $_SESSION['year_update_guard']['in_progress'] = $now;
        // Guarantee cleanup of the in-progress lock even on fatal/exit
        register_shutdown_function(function(){
            if (isset($_SESSION['year_update_guard']['in_progress'])) {
                unset($_SESSION['year_update_guard']['in_progress']);
            }
        });

        // Password update for migrated students (optional - only required if provided or if completing profile for first time)
        $new_password = isset($_POST['new_password']) ? trim($_POST['new_password']) : null;
        $confirm_password = isset($_POST['confirm_password']) ? trim($_POST['confirm_password']) : null;
        
        // Validate required fields
        if (empty($year_level)) {
            echo json_encode(['success' => false, 'message' => 'Please select your current year level.']);
            exit;
        }
        
        // For academic year, if empty, try to get current year or use default
        if (empty($academic_year)) {
            // Try to get from database
            $ay_query = pg_query($connection, "SELECT academic_year FROM signup_slots WHERE is_active = TRUE LIMIT 1");
            if ($ay_query && pg_num_rows($ay_query) > 0) {
                $ay_row = pg_fetch_assoc($ay_query);
                $academic_year = $ay_row['academic_year'];
            } else {
                // Check config table
                $config_query = pg_query($connection, "SELECT value FROM config WHERE key = 'current_academic_year'");
                if ($config_query && pg_num_rows($config_query) > 0) {
                    $config_row = pg_fetch_assoc($config_query);
                    $academic_year = $config_row['value'];
                } else {
                    // Generate current academic year based on date (July = start of new AY)
                    $current_year = date('Y');
                    $current_month = date('n');
                    if ($current_month >= 7) {
                        $academic_year = $current_year . '-' . ($current_year + 1);
                    } else {
                        $academic_year = ($current_year - 1) . '-' . $current_year;
                    }
                }
            }
        }
        
        // Check if this is a migrated student needing university selection
        $student_check_query = pg_query_params($connection,
            "SELECT status, university_id, year_level_id, mothers_maiden_name, school_student_id, admin_review_required FROM students WHERE student_id = $1",
            [$student_id]
        );
        
        if (!$student_check_query) {
            $db_error = pg_last_error($connection);
            error_log("Student check query failed for {$student_id}: {$db_error}");
            echo json_encode(['success' => false, 'message' => 'Database error: Unable to retrieve student information.']);
            exit;
        }
        
        $student_check = pg_fetch_assoc($student_check_query);
        
        if (!$student_check) {
            error_log("Student not found: {$student_id}");
            echo json_encode(['success' => false, 'message' => 'Student record not found.']);
            exit;
        }
        
        // Check if migrated student (admin_review_required = TRUE)
        $is_migrated_student = ($student_check['admin_review_required'] === 't' || $student_check['admin_review_required'] === true);
        
        // If migrated student without university, require all fields including password
        if ($is_migrated_student && empty($student_check['university_id'])) {
            if (empty($university_id)) {
                echo json_encode(['success' => false, 'message' => 'Please select your university.']);
                exit;
            }
            if (empty($mothers_maiden_name)) {
                echo json_encode(['success' => false, 'message' => 'Please enter your mother\'s maiden name.']);
                exit;
            }
            if (empty($school_student_id)) {
                echo json_encode(['success' => false, 'message' => 'Please enter your school student ID.']);
                exit;
            }
            
            // Validate password for migrated students ONLY if provided
            // Password is optional if they already completed profile before
            if (!empty($new_password) || !empty($confirm_password)) {
                if (empty($new_password)) {
                    echo json_encode(['success' => false, 'message' => 'Please enter your new password in both fields.']);
                    exit;
                }
                if (strlen($new_password) < 8) {
                    echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters long.']);
                    exit;
                }
                if ($new_password !== $confirm_password) {
                    echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
                    exit;
                }
            }
        }
        
        // Get student's current year level info for validation
        $current_info_query = pg_query_params($connection, 
            "SELECT current_year_level, is_graduating, status_academic_year FROM students WHERE student_id = $1",
            [$student_id]
        );
        
        if (!$current_info_query) {
            $db_error = pg_last_error($connection);
            error_log("Current info query failed for {$student_id}: {$db_error}");
            echo json_encode(['success' => false, 'message' => 'Database error: Unable to retrieve current information.']);
            exit;
        }
        
        $current_info = pg_fetch_assoc($current_info_query);
        
        if (!$current_info) {
            error_log("Current info not found for student: {$student_id}");
            echo json_encode(['success' => false, 'message' => 'Unable to retrieve your current academic information.']);
            exit;
        }
        
        // CRITICAL CHECK: Prevent students who already graduated from claiming graduation again
        if ($is_graduating && 
            $current_info['is_graduating'] === 't' && 
            !empty($current_info['status_academic_year']) && 
            $current_info['status_academic_year'] < $academic_year) {
            
            echo json_encode([
                'success' => false,
                'already_graduated' => true,
                'message' => "⚠️ <strong>Already Graduated!</strong><br><br>" .
                            "Our records show you were graduating in <strong>A.Y. {$current_info['status_academic_year']}</strong>. " .
                            "You cannot claim graduation status again for <strong>A.Y. {$academic_year}</strong>.<br><br>" .
                            "<strong>What this means:</strong><br>" .
                            "• You should have completed your degree already<br>" .
                            "• If you're continuing education, uncheck 'Graduating'<br>" .
                            "• If this is an error, contact the admin office<br><br>" .
                            "<strong>Action required:</strong> Your account will be archived for administrator review. Please visit the CSWDO office."
            ]);
            
            // Auto-archive the student since they're claiming graduation multiple times
            $archive_reason = "Student claimed graduation multiple times - Was graduating in A.Y. {$current_info['status_academic_year']}, attempting to graduate again in A.Y. {$academic_year}";
            $archived_by = null; // System auto-archive
            
            $archive_query = "UPDATE students 
                             SET is_archived = TRUE,
                                 archived_at = NOW(),
                                 archived_by = $1,
                                 archive_reason = $2,
                                 status = 'disabled'
                             WHERE student_id = $3";
            
            pg_query_params($connection, $archive_query, [
                $archived_by,
                $archive_reason,
                $student_id
            ]);
            
            // Log in history
            $history_query = "INSERT INTO student_status_history 
                             (student_id, year_level, is_graduating, academic_year, updated_at, update_source, notes)
                             VALUES ($1, $2, $3, $4, NOW(), 'auto_archive_duplicate_graduation', $5)";
            
            pg_query_params($connection, $history_query, [
                $student_id,
                $year_level,
                'true',
                $academic_year,
                $archive_reason
            ]);
            
            // Send email notifications
            $student_email_query = pg_query_params($connection,
                "SELECT email, first_name, last_name FROM students WHERE student_id = $1",
                [$student_id]
            );
            $student_data = pg_fetch_assoc($student_email_query);
            
            if ($student_data && !empty($student_data['email'])) {
                try {
                    $mail = new PHPMailer(true);
                    $mail->isSMTP();
                    $mail->Host = 'smtp.gmail.com';
                    $mail->SMTPAuth = true;
                    $mail->Username = 'dilucayaka02@gmail.com';
                    $mail->Password = 'jlld eygl hksj flvg';
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port = 587;
                    
                    $mail->setFrom('dilucayaka02@gmail.com', 'EducAid System');
                    $mail->addAddress($student_data['email'], $student_data['first_name'] . ' ' . $student_data['last_name']);
                    
                    $mail->isHTML(true);
                    $mail->Subject = '⚠️ EducAid Account Archived - Duplicate Graduation Claim';
                    
                    $student_name = htmlspecialchars($student_data['first_name'] . ' ' . $student_data['last_name']);
                    
                    $mail->Body = "
                        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                            <div style='background: #dc3545; color: white; padding: 20px; text-align: center;'>
                                <h2 style='margin: 0;'>⚠️ Account Archived</h2>
                            </div>
                            
                            <div style='padding: 20px; background: #f8f9fa;'>
                                <p>Dear <strong>{$student_name}</strong>,</p>
                                
                                <p>Your EducAid account has been automatically archived due to a duplicate graduation claim.</p>
                                
                                <div style='background: white; padding: 15px; border-left: 4px solid #dc3545; margin: 20px 0;'>
                                    <h3 style='color: #dc3545; margin-top: 0;'>Issue Detected:</h3>
                                    <p><strong>You were already graduating in A.Y. {$current_info['status_academic_year']}</strong></p>
                                    <p>You attempted to claim graduation status again for A.Y. {$academic_year}</p>
                                </div>
                                
                                <h3>What This Means:</h3>
                                <ul>
                                    <li>You should have completed your degree in {$current_info['status_academic_year']}</li>
                                    <li>Students cannot graduate twice from the same program</li>
                                    <li>Your account has been flagged for administrator review</li>
                                </ul>
                                
                                <h3>Next Steps:</h3>
                                <p><strong>Please visit the CSWDO office immediately</strong> to resolve this issue.</p>
                                
                                <div style='background: #fff3cd; padding: 15px; border-left: 4px solid #ffc107; margin: 20px 0;'>
                                    <p style='margin: 0;'><strong>Contact Information:</strong></p>
                                    <p style='margin: 5px 0;'>CSWDO General Trias</p>
                                    <p style='margin: 5px 0;'>Email: cswdo.generaltrias@gmail.com</p>
                                </div>
                            </div>
                        </div>
                    ";
                    
                    $mail->AltBody = "Your EducAid account has been archived due to a duplicate graduation claim. You were already graduating in A.Y. {$current_info['status_academic_year']}. Please contact CSWDO office.";
                    
                    $mail->send();
                    error_log("✅ Duplicate graduation email sent to student: " . $student_data['email']);
                } catch (Exception $e) {
                    error_log("❌ Failed to send duplicate graduation email: " . $e->getMessage());
                }
            }
            
            // Send admin notification
            try {
                $mail_admin = new PHPMailer(true);
                $mail_admin->isSMTP();
                $mail_admin->Host = 'smtp.gmail.com';
                $mail_admin->SMTPAuth = true;
                $mail_admin->Username = 'dilucayaka02@gmail.com';
                $mail_admin->Password = 'jlld eygl hksj flvg';
                $mail_admin->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail_admin->Port = 587;
                
                $mail_admin->setFrom('dilucayaka02@gmail.com', 'EducAid System');
                $mail_admin->addAddress('cswdo.generaltrias@gmail.com', 'CSWDO Admin');
                
                $mail_admin->isHTML(true);
                $mail_admin->Subject = '🚨 ALERT: Student Claiming Duplicate Graduation - ' . $student_id;
                
                $student_name = htmlspecialchars($student_data['first_name'] . ' ' . $student_data['last_name']);
                
                $mail_admin->Body = "
                    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                        <div style='background: #dc3545; color: white; padding: 20px;'>
                            <h2 style='margin: 0;'>🚨 DUPLICATE GRADUATION ALERT</h2>
                        </div>
                        
                        <div style='padding: 20px; background: #f8f9fa;'>
                            <h3>Student Attempting Multiple Graduations</h3>
                            
                            <div style='background: white; padding: 15px; border: 1px solid #dee2e6; margin: 15px 0;'>
                                <p><strong>Student ID:</strong> {$student_id}</p>
                                <p><strong>Name:</strong> {$student_name}</p>
                                <p><strong>Email:</strong> {$student_data['email']}</p>
                                <p><strong>Previous Graduation:</strong> A.Y. {$current_info['status_academic_year']} ({$current_info['current_year_level']})</p>
                                <p><strong>Attempted Graduation:</strong> A.Y. {$academic_year} ({$year_level})</p>
                            </div>
                            
                            <div style='background: #fff3cd; padding: 15px; border-left: 4px solid #ffc107;'>
                                <p style='margin: 0;'><strong>Action Taken:</strong> Account automatically archived</p>
                            </div>
                            
                            <h4>Possible Scenarios:</h4>
                            <ul>
                                <li><strong>Student Error:</strong> Did not understand they already graduated</li>
                                <li><strong>Data Error:</strong> Previous year data was incorrect</li>
                                <li><strong>Fraud Attempt:</strong> Trying to claim aid multiple times</li>
                                <li><strong>Continuing Education:</strong> Should have unchecked 'graduating'</li>
                            </ul>
                            
                            <p><strong>Recommended Action:</strong> Contact student immediately for verification</p>
                        </div>
                    </div>
                ";
                
                $mail_admin->AltBody = "ALERT: Student {$student_id} ({$student_name}) attempted to claim graduation in A.Y. {$academic_year}, but was already graduating in A.Y. {$current_info['status_academic_year']}. Account automatically archived.";
                
                $mail_admin->send();
                error_log("✅ Duplicate graduation admin notification sent");
            } catch (Exception $e) {
                error_log("❌ Failed to send admin notification: " . $e->getMessage());
            }
            
            exit;
        }
        
        // Define year level hierarchy for comparison
        $year_levels = ['1st Year' => 1, '2nd Year' => 2, '3rd Year' => 3, '4th Year' => 4, '5th Year' => 5];
        
        error_log("YEAR LEVEL ADVANCEMENT CHECK - current: {$current_info['current_year_level']}, new: {$year_level}, is_graduating: " . ($is_graduating ? 'YES' : 'NO'));
        
        // Check if student is trying to stay in same year level or go down
        // This requires admin verification - archive the account
        $confirm_archive = isset($_POST['confirm_archive']) && $_POST['confirm_archive'] === '1';
        error_log("YEAR LEVEL ADVANCEMENT CHECK - confirm_archive: " . ($confirm_archive ? 'YES' : 'NO'));
        
        // SKIP year repetition check for students who never confirmed their year level before
        // (status_academic_year is NULL means they're setting it for the first time)
        $is_first_year_confirmation = empty($current_info['status_academic_year']);
        error_log("YEAR LEVEL ADVANCEMENT CHECK - is_first_year_confirmation: " . ($is_first_year_confirmation ? 'YES' : 'NO') . ", status_academic_year: " . ($current_info['status_academic_year'] ?: 'NULL'));
        
        // Skip repetition check entirely for migrated students completing profile
        if (!$is_migrated_student && !$is_first_year_confirmation && 
            !empty($current_info['current_year_level']) && 
            isset($year_levels[$current_info['current_year_level']]) && 
            isset($year_levels[$year_level])) {
            
            $previous_level_num = $year_levels[$current_info['current_year_level']];
            $new_level_num = $year_levels[$year_level];
            
            error_log("YEAR LEVEL COMPARISON - previous_num: {$previous_level_num}, new_num: {$new_level_num}, requires_admin: " . (($new_level_num <= $previous_level_num && !$is_graduating) ? 'YES' : 'NO'));
            
            // If same or lower year level (not advancing), require admin verification
            if ($new_level_num <= $previous_level_num && !$is_graduating) {
                
                if (!$confirm_archive) {
                    // First warning - ask for confirmation
                    $reason = ($new_level_num < $previous_level_num) 
                        ? "You selected a lower year level ({$year_level}) than last year ({$current_info['current_year_level']})."
                        : "You selected the same year level ({$year_level}) as last year.";
                    
                    echo json_encode([
                        'success' => false,
                        'requires_admin' => true,
                        'message' => "{$reason} Students who are repeating a year or not advancing require administrator verification before receiving aid. Your account will be deactivated and you will need to contact the admin office to reactivate it.",
                        'confirm_required' => true
                    ]);
                    exit;
                } else {
                    // User confirmed - archive their account
                    $archive_reason = ($new_level_num < $previous_level_num)
                        ? "Student went from {$current_info['current_year_level']} to {$year_level} (lower year level) - requires admin verification"
                        : "Student repeated {$year_level} for two consecutive years - requires admin verification";
                    
                    // Get admin ID for archiving (use system if no admin logged in)
                    $archived_by = $_SESSION['admin_id'] ?? null;
                    
                    $archive_query = "UPDATE students 
                                     SET is_archived = TRUE,
                                         archived_at = NOW(),
                                         archived_by = $1,
                                         archive_reason = $2,
                                         status = 'disabled'
                                     WHERE student_id = $3";
                    
                    $archive_result = pg_query_params($connection, $archive_query, [
                        $archived_by,
                        $archive_reason,
                        $student_id
                    ]);
                    
                    if ($archive_result) {
                        // Log in student_status_history
                        $history_query = "INSERT INTO student_status_history 
                                         (student_id, year_level, is_graduating, academic_year, updated_at, update_source, notes)
                                         VALUES ($1, $2, $3, $4, NOW(), 'auto_archive_repeating_year', $5)";
                        
                        pg_query_params($connection, $history_query, [
                            $student_id,
                            $year_level,
                            'false',
                            $academic_year,
                            $archive_reason
                        ]);
                        
                        // Create admin notification
                        $notification_query = "INSERT INTO admin_notifications 
                                             (message, created_at, is_read)
                                             VALUES ($1, NOW(), FALSE)";
                        
                        $notification_message = "⚠️ STUDENT AUTO-ARCHIVED: {$student_id} - {$archive_reason}";
                        pg_query_params($connection, $notification_query, [
                            $notification_message
                        ]);
                        
                        // Get student email and name for notification
                        $student_email_query = pg_query_params($connection,
                            "SELECT email, first_name, last_name FROM students WHERE student_id = $1",
                            [$student_id]
                        );
                        $student_data = pg_fetch_assoc($student_email_query);
                        
                        // Send email notification to student
                        if ($student_data && !empty($student_data['email'])) {
                            try {
                                $mail = new PHPMailer(true);
                                
                                $mail->isSMTP();
                                $mail->Host = 'smtp.gmail.com';
                                $mail->SMTPAuth = true;
                                $mail->Username = 'dilucayaka02@gmail.com';
                                $mail->Password = 'jlld eygl hksj flvg';
                                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                                $mail->Port = 587;
                                
                                $mail->setFrom('dilucayaka02@gmail.com', 'EducAid System');
                                $mail->addAddress($student_data['email'], $student_data['first_name'] . ' ' . $student_data['last_name']);
                                
                                $mail->isHTML(true);
                                $mail->Subject = '⚠️ EducAid Account Deactivated - Action Required';
                                
                                $student_name = htmlspecialchars($student_data['first_name'] . ' ' . $student_data['last_name']);
                                $reason_html = htmlspecialchars($archive_reason);
                                
                                $mail->Body = "
                                    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                                        <div style='background: #f8d7da; border-left: 4px solid #dc3545; padding: 20px; margin-bottom: 20px;'>
                                            <h2 style='color: #721c24; margin-top: 0;'>⚠️ Account Deactivated</h2>
                                        </div>
                                        
                                        <p>Dear <strong>{$student_name}</strong>,</p>
                                        
                                        <p>Your EducAid account (<strong>{$student_id}</strong>) has been automatically deactivated and requires administrator verification.</p>
                                        
                                        <div style='background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0;'>
                                            <p style='margin: 0;'><strong>Reason:</strong></p>
                                            <p style='margin: 5px 0 0 0;'>{$reason_html}</p>
                                        </div>
                                        
                                        <p><strong>What this means:</strong></p>
                                        <ul>
                                            <li>Your account has been temporarily disabled</li>
                                            <li>You cannot participate in the current distribution</li>
                                            <li>Your case requires administrator review</li>
                                        </ul>
                                        
                                        <p><strong>What you need to do:</strong></p>
                                        <ol>
                                            <li>Contact the General Trias CSWDO office</li>
                                            <li>Bring supporting documents (enrollment proof, grades, etc.)</li>
                                            <li>Explain your situation to the administrator</li>
                                            <li>Wait for your account to be reviewed and reactivated</li>
                                        </ol>
                                        
                                        <div style='background: #d1ecf1; border-left: 4px solid #0c5460; padding: 15px; margin: 20px 0;'>
                                            <p style='margin: 0;'><strong>📞 Contact Information:</strong></p>
                                            <p style='margin: 5px 0 0 0;'>General Trias CSWDO<br>
                                            Email: <a href='mailto:cswdo.generaltrias@gmail.com'>cswdo.generaltrias@gmail.com</a></p>
                                        </div>
                                        
                                        <p>If you believe this is an error, please contact the administrator immediately.</p>
                                        
                                        <hr style='border: none; border-top: 1px solid #ddd; margin: 30px 0;'>
                                        
                                        <p style='font-size: 12px; color: #666;'>
                                            This is an automated message from the EducAid System. Please do not reply to this email.
                                        </p>
                                    </div>
                                ";
                                
                                $mail->AltBody = "Dear {$student_name},\n\n"
                                    . "Your EducAid account ({$student_id}) has been automatically deactivated.\n\n"
                                    . "Reason: {$archive_reason}\n\n"
                                    . "Please contact the General Trias CSWDO office for account reactivation.\n\n"
                                    . "Email: cswdo.generaltrias@gmail.com";
                                
                                $mail->send();
                                error_log("✅ Archive email sent successfully to student: " . $student_data['email']);
                            } catch (Exception $e) {
                                error_log("❌ Failed to send archive email to student: " . $e->getMessage());
                                error_log("PHPMailer Error Info: " . $mail->ErrorInfo);
                            }
                        } else {
                            error_log("⚠️ Cannot send archive email - student email not found for ID: " . $student_id);
                        }
                        
                        // Send email notification to admin
                        try {
                            $mail_admin = new PHPMailer(true);
                            
                            $mail_admin->isSMTP();
                            $mail_admin->Host = 'smtp.gmail.com';
                            $mail_admin->SMTPAuth = true;
                            $mail_admin->Username = 'dilucayaka02@gmail.com';
                            $mail_admin->Password = 'jlld eygl hksj flvg';
                            $mail_admin->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                            $mail_admin->Port = 587;
                            
                            $mail_admin->setFrom('dilucayaka02@gmail.com', 'EducAid System');
                            $mail_admin->addAddress('cswdo.generaltrias@gmail.com', 'CSWDO Admin');
                            
                            $mail_admin->isHTML(true);
                            $mail_admin->Subject = '⚠️ Student Account Auto-Deactivated - ' . $student_id;
                            
                            $student_name = htmlspecialchars($student_data['first_name'] . ' ' . $student_data['last_name']);
                            $reason_html = htmlspecialchars($archive_reason);
                            $student_email = htmlspecialchars($student_data['email'] ?? 'No email');
                            
                            $mail_admin->Body = "
                                <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                                    <div style='background: #fff3cd; border-left: 4px solid #ffc107; padding: 20px; margin-bottom: 20px;'>
                                        <h2 style='color: #856404; margin-top: 0;'>⚠️ Student Account Auto-Deactivated</h2>
                                    </div>
                                    
                                    <p>A student account has been automatically deactivated due to year level advancement policy.</p>
                                    
                                    <div style='background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 20px 0;'>
                                        <p style='margin: 5px 0;'><strong>Student ID:</strong> {$student_id}</p>
                                        <p style='margin: 5px 0;'><strong>Name:</strong> {$student_name}</p>
                                        <p style='margin: 5px 0;'><strong>Email:</strong> {$student_email}</p>
                                    </div>
                                    
                                    <div style='background: #f8d7da; border-left: 4px solid #dc3545; padding: 15px; margin: 20px 0;'>
                                        <p style='margin: 0;'><strong>Deactivation Reason:</strong></p>
                                        <p style='margin: 5px 0 0 0;'>{$reason_html}</p>
                                    </div>
                                    
                                    <p><strong>Action Required:</strong></p>
                                    <ul>
                                        <li>Review the student's case</li>
                                        <li>Verify enrollment status and documents</li>
                                        <li>Determine if account should be reactivated</li>
                                        <li>Contact student if additional information is needed</li>
                                    </ul>
                                    
                                    <p>The student has been notified and instructed to contact your office.</p>
                                    
                                    <hr style='border: none; border-top: 1px solid #ddd; margin: 30px 0;'>
                                    
                                    <p style='font-size: 12px; color: #666;'>
                                        This is an automated notification from the EducAid System.
                                    </p>
                                </div>
                            ";
                            
                            $mail_admin->AltBody = "STUDENT ACCOUNT AUTO-DEACTIVATED\n\n"
                                . "Student ID: {$student_id}\n"
                                . "Name: {$student_name}\n"
                                . "Email: {$student_email}\n\n"
                                . "Reason: {$archive_reason}\n\n"
                                . "Please review and take appropriate action.";
                            
                            $mail_admin->send();
                            error_log("✅ Archive notification sent successfully to admin: cswdo.generaltrias@gmail.com");
                        } catch (Exception $e) {
                            error_log("❌ Failed to send archive email to admin: " . $e->getMessage());
                            error_log("PHPMailer Error Info: " . $mail_admin->ErrorInfo);
                        }
                        
                        echo json_encode([
                            'success' => true,
                            'archived' => true,
                            'message' => 'Your account has been deactivated. Please check your email for further instructions and contact the admin office for verification and reactivation.',
                            'logout_required' => true
                        ]);
                    } else {
                        echo json_encode([
                            'success' => false,
                            'message' => 'Failed to archive account. Please contact administrator.'
                        ]);
                    }
                    exit;
                }
            }
        }
        
        // Update student year level credentials
        // For migrated students, also update university_id, year_level_id, mothers_maiden_name, school_student_id, AND password
        if (!empty($university_id)) {
            error_log("YEAR LEVEL LOOKUP - Searching for: '{$year_level}'");
            // Get year_level_id from year level name
            $yl_query = pg_query_params($connection,
                "SELECT year_level_id FROM year_levels WHERE name = $1",
                [$year_level]
            );
            
            if (!$yl_query) {
                $db_error = pg_last_error($connection);
                error_log("Year level lookup failed for '{$year_level}': {$db_error}");
                echo json_encode(['success' => false, 'message' => 'Database error: Unable to lookup year level.']);
                exit;
            }
            
            $yl_row = pg_fetch_assoc($yl_query);
            $year_level_id = $yl_row ? intval($yl_row['year_level_id']) : null;
            error_log("YEAR LEVEL LOOKUP RESULT - year_level_id: " . ($year_level_id ?: 'NULL') . ", row data: " . json_encode($yl_row));
            
            if (!$year_level_id) {
                error_log("Year level ID not found for: {$year_level}");
                echo json_encode(['success' => false, 'message' => 'Invalid year level selected.']);
                exit;
            }
            
            // Hash the new password if provided
            $hashed_password = null;
            if (!empty($new_password)) {
                $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);
            }
            
            // Update query with password if migrated student
            if ($is_migrated_student && !empty($hashed_password)) {
                $update_query = "UPDATE students 
                                SET current_year_level = $1,
                                    is_graduating = $2,
                                    last_status_update = NOW(),
                                    status_academic_year = $3,
                                    university_id = $4,
                                    year_level_id = $5,
                                    mothers_maiden_name = $6,
                                    school_student_id = $7,
                                    password = $8
                                WHERE student_id = $9";
                
                $update_result = pg_query_params($connection, $update_query, [
                    $year_level,
                    $is_graduating ? 'true' : 'false',
                    $academic_year,
                    $university_id,
                    $year_level_id,
                    $mothers_maiden_name,
                    $school_student_id,
                    $hashed_password,
                    $student_id
                ]);
            } else {
                // Regular update without password change
                $update_query = "UPDATE students 
                                SET current_year_level = $1,
                                    is_graduating = $2,
                                    last_status_update = NOW(),
                                    status_academic_year = $3,
                                    university_id = $4,
                                    year_level_id = $5,
                                    mothers_maiden_name = $6,
                                    school_student_id = $7
                                WHERE student_id = $8";
                
                $update_result = pg_query_params($connection, $update_query, [
                    $year_level,
                    $is_graduating ? 'true' : 'false',
                    $academic_year,
                    $university_id,
                    $year_level_id,
                    $mothers_maiden_name,
                    $school_student_id,
                    $student_id
                ]);
            }
            
            if (!$update_result) {
                $db_error = pg_last_error($connection);
                error_log("Update query failed for student {$student_id}: {$db_error}");
                error_log("Query params: year_level={$year_level}, is_graduating={$is_graduating}, academic_year={$academic_year}, university_id={$university_id}, year_level_id={$year_level_id}");
                echo json_encode(['success' => false, 'message' => 'Database error: Failed to update student record. Error: ' . $db_error]);
                exit;
            }
            error_log("UPDATE SUCCESS - student_id: {$student_id}, rows affected: " . pg_affected_rows($update_result));
        } else {
            $update_query = "UPDATE students 
                            SET current_year_level = $1,
                                is_graduating = $2,
                                last_status_update = NOW(),
                                status_academic_year = $3
                            WHERE student_id = $4";
            
            $update_result = pg_query_params($connection, $update_query, [
                $year_level,
                $is_graduating ? 'true' : 'false',
                $academic_year,
                $student_id
            ]);
            
            if (!$update_result) {
                $db_error = pg_last_error($connection);
                error_log("Simple update query failed for student {$student_id}: {$db_error}");
                echo json_encode(['success' => false, 'message' => 'Database error: Failed to update. Error: ' . $db_error]);
                exit;
            }
        }
        
        if ($update_result) {
            error_log("UPDATE RESULT CHECK PASSED - Proceeding to history logging");
            // Log the change in student_status_history
            $history_query = "INSERT INTO student_status_history 
                             (student_id, year_level, is_graduating, academic_year, updated_at, update_source, notes)
                             VALUES ($1, $2, $3, $4, NOW(), 'student_self_update_modal', $5)";
            
            $note = "Student updated via upload documents page modal";
            if (!empty($current_info['current_year_level']) && $current_info['current_year_level'] === $year_level) {
                $note .= " - Same year level as previous year (possibly repeating)";
            }
            
            $history_result = pg_query_params($connection, $history_query, [
                $student_id,
                $year_level,
                $is_graduating ? 'true' : 'false',
                $academic_year,
                $note
            ]);
            if (!$history_result) {
                error_log("History insert failed for student {$student_id}: " . pg_last_error($connection));
            } else {
                error_log("History insert SUCCESS for student {$student_id}");
            }
            
            // If migrated student is completing their profile (university_id present), add audit log
            if (!empty($university_id) && !empty($student_check['admin_review_required']) && $student_check['admin_review_required']) {
                // Use NULL for user_id since student_id is a string, store actual ID in metadata
                $audit_query = "INSERT INTO audit_logs 
                               (user_id, user_type, username, event_type, event_category, 
                                action_description, status, ip_address, user_agent, 
                                request_method, affected_table, affected_record_id, 
                                new_values, metadata, created_at)
                               VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13, $14, NOW())";
                
                $new_values = json_encode([
                    'university_id' => $university_id,
                    'year_level_id' => $year_level_id,
                    'current_year_level' => $year_level,
                    'is_graduating' => $is_graduating,
                    'mothers_maiden_name' => $mothers_maiden_name,
                    'school_student_id' => $school_student_id
                ]);
                
                $metadata = json_encode([
                    'migration_completion' => true,
                    'academic_year' => $academic_year,
                    'previous_year_level' => $current_info['current_year_level'] ?? null,
                    'actual_student_id' => $student_id // Store string ID here
                ]);
                
                $audit_result = pg_query_params($connection, $audit_query, [
                    null, // user_id set to NULL for string-based student IDs
                    'student',
                    $student_id, // username = student_id
                    'profile_completion',
                    'migrated_student',
                    "Migrated student completed profile setup: Selected university, year level, and provided credentials",
                    'success',
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null,
                    'POST',
                    'students',
                    $student_id,
                    $new_values,
                    $metadata
                ]);
                
                if (!$audit_result) {
                    // Log audit failure but don't fail the whole operation
                    error_log("Audit log insert failed for student {$student_id}: " . pg_last_error($connection));
                }
            }
            
            error_log("AUDIT LOG SECTION COMPLETE - Checking graduation status: " . ($is_graduating ? 'YES' : 'NO'));
            // If student marked themselves as graduating, notify admin
            if ($is_graduating) {
                $notification_query = "INSERT INTO admin_notifications 
                                     (message, created_at, is_read)
                                     VALUES ($1, NOW(), FALSE)";
                
                $notification_message = "🎓 GRADUATING STUDENT: {$student_id} marked themselves as graduating ({$year_level} - {$academic_year})";
                pg_query_params($connection, $notification_query, [
                    $notification_message
                ]);
            }
            
            error_log("SENDING SUCCESS RESPONSE to student {$student_id}");
            echo json_encode([
                'success' => true,
                'message' => 'Year level updated successfully! You can now upload documents.'
            ]);
            // Mark idempotency last success
            $_SESSION['year_update_guard']['last_success'] = ['fp' => $fingerprint, 'ts' => time()];
            unset($_SESSION['year_update_guard']['in_progress']);
        } else {
            error_log("UPDATE RESULT FALSE - This should not happen");
            echo json_encode(['success' => false, 'message' => 'Failed to update year level. Database update failed.']);
        }
    } catch (Exception $e) {
        error_log('Year level update EXCEPTION for student ' . $student_id . ': ' . $e->getMessage());
        error_log('Stack trace: ' . $e->getTraceAsString());
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    } finally {
        // Always clear in_progress lock on exit
        if (isset($_SESSION['year_update_guard']['in_progress'])) {
            unset($_SESSION['year_update_guard']['in_progress']);
        }
    }
    error_log("UPDATE HANDLER COMPLETE - exiting");
    exit;
}

// Handle AJAX OCR processing before regular form handling
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_document'])) {
    // Suppress error display for AJAX requests (log errors instead)
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
    
    // Clean output buffer and set JSON header immediately
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json');

    try {
        if (!$can_upload) {
            echo json_encode(['success' => false, 'message' => 'Document uploads are currently disabled for your account.']);
            exit;
        }

        $doc_type_code = $_POST['document_type'] ?? '';

        if (!isset($document_types[$doc_type_code])) {
            echo json_encode(['success' => false, 'message' => 'Invalid document type provided.']);
            exit;
        }

        // SERVER-SIDE CONCURRENCY PROTECTION
        // Check if this document type is already being processed
        $lock_key = $student_id . '_' . $doc_type_code;
        $current_time = time();
        
        if (isset($_SESSION['processing_lock'][$lock_key])) {
            $lock_time = $_SESSION['processing_lock'][$lock_key];
            $lock_age = $current_time - $lock_time;
            
            // If lock is less than 30 seconds old, reject duplicate request
            if ($lock_age < 30) {
                error_log("DUPLICATE OCR REQUEST BLOCKED: Student $student_id, Document $doc_type_code (lock age: {$lock_age}s)");
                echo json_encode([
                    'success' => false, 
                    'message' => 'This document is already being processed. Please wait for completion.'
                ]);
                exit;
            } else {
                // Lock is stale (>30s), remove it and continue
                error_log("STALE LOCK REMOVED: Student $student_id, Document $doc_type_code (lock age: {$lock_age}s)");
                unset($_SESSION['processing_lock'][$lock_key]);
            }
        }
        
        // Set processing lock for this document
        $_SESSION['processing_lock'][$lock_key] = $current_time;
        error_log("PROCESSING LOCK SET: Student $student_id, Document $doc_type_code");

        if (!isset($_SESSION['temp_uploads'][$doc_type_code])) {
            unset($_SESSION['processing_lock'][$lock_key]); // Release lock
            echo json_encode(['success' => false, 'message' => 'No temporary file found. Please upload the document again.']);
            exit;
        }

        $tempData = $_SESSION['temp_uploads'][$doc_type_code];
        $tempPath = $tempData['path'] ?? null;

        if (!$tempPath || !file_exists($tempPath)) {
            unset($_SESSION['processing_lock'][$lock_key]); // Release lock
            echo json_encode(['success' => false, 'message' => 'Temporary file is missing or expired. Please re-upload the document.']);
            exit;
        }
        
        // Check if already processed (prevent duplicate processing)
        if (isset($tempData['ocr_processed_at']) && !empty($tempData['ocr_processed_at'])) {
            error_log("ALREADY PROCESSED: Student $student_id, Document $doc_type_code at {$tempData['ocr_processed_at']}");
            unset($_SESSION['processing_lock'][$lock_key]); // Release lock
            echo json_encode([
                'success' => true,
                'message' => 'Document was already processed.',
                'ocr_confidence' => $tempData['ocr_confidence'] ?? 0,
                'verification_score' => $tempData['verification_score'] ?? 0,
                'verification_status' => $tempData['verification_status'] ?? 'pending',
                'already_processed' => true
            ]);
            exit;
        }

        // Use NEW TSV-based OCR for Enrollment Forms (document type '00')
        if ($doc_type_code === '00') {
            try {
                $enrollmentOCR = new EnrollmentFormOCRService($connection);
                
                $studentData = [
                    'first_name' => $student['first_name'] ?? '',
                    'middle_name' => $student['middle_name'] ?? '',
                    'last_name' => $student['last_name'] ?? '',
                    'university_name' => $student['university_name'] ?? '',
                    'year_level' => $student['current_year_level'] ?? ''
                ];
                
                $ocrResult = $enrollmentOCR->processEnrollmentForm($tempPath, $studentData);
                
                if (!$ocrResult['success']) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'TSV OCR processing failed: ' . ($ocrResult['error'] ?? 'Unknown error')
                    ]);
                    exit;
                }
                
                $extracted = $ocrResult['data'];
                $overallConfidence = $ocrResult['overall_confidence'];
                $tsvQuality = $ocrResult['tsv_quality'];
                
                // Process course if found - simplified, no database lookup
                $courseData = null;
                if (!empty($extracted['course']) && !empty($extracted['course']['found'])) {
                    $courseData = [
                        'raw_course' => $extracted['course']['raw'] ?? null,
                        'normalized_course' => $extracted['course']['normalized'] ?? null
                    ];
                }
                
                // Store TSV OCR results in session
                $_SESSION['temp_uploads'][$doc_type_code]['ocr_confidence'] = $overallConfidence;
                $_SESSION['temp_uploads'][$doc_type_code]['verification_score'] = $ocrResult['verification_passed'] ? 100 : ($overallConfidence * 0.8);
                $_SESSION['temp_uploads'][$doc_type_code]['verification_status'] = $ocrResult['verification_passed'] ? 'passed' : 'manual_review';
                $_SESSION['temp_uploads'][$doc_type_code]['ocr_processed_at'] = date('Y-m-d H:i:s');
                $_SESSION['temp_uploads'][$doc_type_code]['tsv_quality'] = $tsvQuality;
                $_SESSION['temp_uploads'][$doc_type_code]['extracted_data'] = $extracted;
                $_SESSION['temp_uploads'][$doc_type_code]['course_data'] = $courseData;
                
                // SAVE .verify.json and .confidence.json for EAF (like other documents)
                $verifyData = [
                    'success' => $ocrResult['verification_passed'],
                    'ocr_confidence' => $overallConfidence,
                    'verification_score' => $_SESSION['temp_uploads'][$doc_type_code]['verification_score'],
                    'verification_status' => $_SESSION['temp_uploads'][$doc_type_code]['verification_status'],
                    'extracted_data' => $extracted,
                    'tsv_quality' => $tsvQuality,
                    'course_data' => $courseData,
                    'timestamp' => date('Y-m-d H:i:s')
                ];
                
                $confidenceData = [
                    'overall_confidence' => $overallConfidence,
                    'verification_passed' => $ocrResult['verification_passed'],
                    'tsv_quality' => $tsvQuality,
                    'fields' => [
                        'first_name' => $extracted['first_name'] ?? null,
                        'middle_name' => $extracted['middle_name'] ?? null,
                        'last_name' => $extracted['last_name'] ?? null,
                        'university' => $extracted['university'] ?? null,
                        'year_level' => $extracted['year_level'] ?? null,
                        // include full course block if available, else null
                        'course' => $extracted['course'] ?? null
                    ],
                    'timestamp' => date('Y-m-d H:i:s')
                ];
                
                // Save JSON files alongside the temp file
                $verifyJsonPath = $tempPath . '.verify.json';
                $confidenceJsonPath = $tempPath . '.confidence.json';
                
                file_put_contents($verifyJsonPath, json_encode($verifyData, JSON_PRETTY_PRINT));
                file_put_contents($confidenceJsonPath, json_encode($confidenceData, JSON_PRETTY_PRINT));
                
                error_log("EAF TSV: Saved .verify.json and .confidence.json for $tempPath");
                
                // Release processing lock
                unset($_SESSION['processing_lock'][$lock_key]);
                error_log("PROCESSING LOCK RELEASED (TSV SUCCESS): Student $student_id, Document $doc_type_code");
                
                echo json_encode([
                    'success' => true,
                    'message' => 'TSV OCR processing completed successfully.',
                    'ocr_confidence' => round($overallConfidence, 2),
                    'verification_score' => round($_SESSION['temp_uploads'][$doc_type_code]['verification_score'], 2),
                    'verification_status' => $ocrResult['verification_passed'] ? 'passed' : 'manual_review',
                    'tsv_quality' => [
                        'total_words' => $tsvQuality['total_words'],
                        'avg_confidence' => $tsvQuality['avg_confidence'],
                        'quality_score' => $tsvQuality['quality_score']
                    ],
                    'course_detected' => $courseData !== null,
                    'course_name' => $courseData['normalized_course'] ?? null
                ]);
                exit;
                
            } catch (Exception $e) {
                // Release processing lock on error
                unset($_SESSION['processing_lock'][$lock_key]);
                error_log("PROCESSING LOCK RELEASED (TSV ERROR): Student $student_id, Document $doc_type_code");
                error_log('TSV OCR Error for enrollment form: ' . $e->getMessage());
                echo json_encode([
                    'success' => false,
                    'message' => 'TSV OCR processing error: ' . $e->getMessage()
                ]);
                exit;
            }
        }
        
        // For other document types, use the old DocumentReuploadService
        $ocrResult = $reuploadService->processTempOcr(
            $student_id,
            $doc_type_code,
            $tempPath,
            [
                'student_id' => $student_id,
                'first_name' => $student['first_name'] ?? '',
                'last_name' => $student['last_name'] ?? '',
                'middle_name' => $student['middle_name'] ?? '',
                'university_id' => $student['university_id'] ?? null,
                'year_level_id' => $student['year_level_id'] ?? null,
                'university_name' => $student['university_name'] ?? '',
                'year_level_name' => $student['current_year_level'] ?? '',
                'barangay_name' => $student['barangay_name'] ?? ''
            ]
        );

        if ($ocrResult['success']) {
            $_SESSION['temp_uploads'][$doc_type_code]['ocr_confidence'] = $ocrResult['ocr_confidence'] ?? 0;
            $_SESSION['temp_uploads'][$doc_type_code]['verification_score'] = $ocrResult['verification_score'] ?? 0;
            $_SESSION['temp_uploads'][$doc_type_code]['verification_status'] = $ocrResult['verification_status'] ?? 'pending';
            $_SESSION['temp_uploads'][$doc_type_code]['ocr_processed_at'] = date('Y-m-d H:i:s');

            // Release processing lock on success
            unset($_SESSION['processing_lock'][$lock_key]);
            error_log("PROCESSING LOCK RELEASED (SUCCESS): Student $student_id, Document $doc_type_code");

            echo json_encode([
                'success' => true,
                'message' => 'OCR processing completed successfully.',
                'ocr_confidence' => round($ocrResult['ocr_confidence'] ?? 0, 2),
                'verification_score' => round($ocrResult['verification_score'] ?? 0, 2),
                'verification_status' => $ocrResult['verification_status'] ?? 'pending'
            ]);
        } else {
            // Release processing lock on failure
            unset($_SESSION['processing_lock'][$lock_key]);
            error_log("PROCESSING LOCK RELEASED (FAILURE): Student $student_id, Document $doc_type_code");
            
            echo json_encode([
                'success' => false,
                'message' => $ocrResult['message'] ?? 'OCR processing failed. Please try again.'
            ]);
        }
    } catch (Exception $e) {
        // Release processing lock on exception
        if (isset($lock_key)) {
            unset($_SESSION['processing_lock'][$lock_key]);
            error_log("PROCESSING LOCK RELEASED (EXCEPTION): Student $student_id, Document $doc_type_code");
        }
        error_log('AJAX OCR Error: ' . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'An unexpected error occurred: ' . $e->getMessage()
        ]);
    }

    exit;
}

// Handle AJAX file upload to session (preview stage)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_upload'])) {
    // CRITICAL: Start output buffering immediately to prevent any header issues
    while (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();
    
    // CRITICAL: Always return JSON for AJAX uploads, even if upload is disabled
    if (!$can_upload) {
        ob_end_clean(); // Clear any buffered output
        header('Content-Type: application/json');
        error_log("AJAX Upload BLOCKED - Student: $student_id, can_upload: false, status: $student_status, needs_upload: " . ($needs_upload ? 'true' : 'false'));
        echo json_encode([
            'success' => false, 
            'message' => 'Document uploads are currently disabled for your account. Status: ' . $student_status
        ]);
        exit;
    }
    
    // Suppress error display for AJAX requests
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
    
    // Clear output buffer and set JSON header
    ob_end_clean();
    header('Content-Type: application/json');
    
    // Log the upload attempt
    error_log("AJAX Upload Handler - Student: $student_id, POST data: " . json_encode($_POST));
    
    try {
        $doc_type_code = $_POST['document_type'] ?? '';
        
        if (!isset($document_types[$doc_type_code])) {
            error_log("AJAX Upload: Invalid document type: $doc_type_code");
            echo json_encode(['success' => false, 'message' => 'Invalid document type']);
            exit;
        }
        
        if (!isset($_FILES['document_file']) || $_FILES['document_file']['error'] !== UPLOAD_ERR_OK) {
            $error_code = $_FILES['document_file']['error'] ?? 'none';
            error_log("AJAX Upload: File upload error code: $error_code");
            echo json_encode(['success' => false, 'message' => 'File upload error. Error code: ' . $error_code]);
            exit;
        }
        
        $file = $_FILES['document_file'];
        
        error_log("AJAX Upload: Processing file - Name: {$file['name']}, Size: {$file['size']}, Type: {$file['type']}");
        
        // Use DocumentReuploadService to upload to TEMP folder (WITHOUT automatic OCR)
        $result = $reuploadService->uploadToTemp(
            $student_id,
            $doc_type_code,
            $file['tmp_name'],
            $file['name'],
            [
                'student_id' => $student_id,
                'first_name' => $student['first_name'] ?? '',
                'last_name' => $student['last_name'] ?? '',
                'university_id' => $student['university_id'] ?? null,
                'year_level_id' => $student['year_level_id'] ?? null
            ]
        );
        
        error_log("AJAX Upload: Service result - " . json_encode($result));
        
        if ($result['success']) {
            // Store temp file info in session for confirmation
            $_SESSION['temp_uploads'][$doc_type_code] = [
                'path' => $result['temp_path'],
                'original_name' => $file['name'],
                'extension' => pathinfo($file['name'], PATHINFO_EXTENSION),
                'size' => $file['size'],
                'uploaded_at' => time(),
                'ocr_confidence' => 0,
                'verification_score' => 0
            ];
            
            error_log("AJAX Upload: SUCCESS - File stored in session");
            
            echo json_encode([
                'success' => true,
                'message' => 'File uploaded successfully. Click "Process OCR" to analyze the document.',
                'data' => [
                    'filename' => $file['name'],
                    'size' => $file['size'],
                    'extension' => pathinfo($file['name'], PATHINFO_EXTENSION)
                ]
            ]);
        } else {
            error_log("AJAX Upload: FAILED - " . $result['message']);
            echo json_encode(['success' => false, 'message' => $result['message']]);
        }
    } catch (Exception $e) {
        error_log("AJAX Upload: EXCEPTION - " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Upload failed: ' . $e->getMessage()]);
    }
    exit;
}

// Handle file upload to session (preview stage)
$upload_result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_upload) {
    
    // Handle preview upload (temporary)
    if (isset($_POST['document_type']) && isset($_FILES['document_file']) && !isset($_POST['confirm_upload'])) {
        $doc_type_code = $_POST['document_type'];
        $file = $_FILES['document_file'];
        
        error_log("Preview upload - Student: $student_id, DocType: $doc_type_code, File: " . $file['name']);
        
        if (!isset($document_types[$doc_type_code])) {
            $upload_result = ['success' => false, 'message' => 'Invalid document type'];
        } elseif ($file['error'] !== UPLOAD_ERR_OK) {
            $upload_result = ['success' => false, 'message' => 'File upload error: ' . $file['error']];
        } else {
            // Use DocumentReuploadService to upload to TEMP folder (with OCR processing)
            $result = $reuploadService->uploadToTemp(
                $student_id,
                $doc_type_code,
                $file['tmp_name'],
                $file['name'],
                [
                    'student_id' => $student_id,
                    'first_name' => $student['first_name'] ?? '',
                    'last_name' => $student['last_name'] ?? '',
                    'university_id' => $student['university_id'] ?? null,
                    'year_level_id' => $student['year_level_id'] ?? null
                ]
            );
            
            if ($result['success']) {
                // Store temp file info in session for confirmation
                $_SESSION['temp_uploads'][$doc_type_code] = [
                    'path' => $result['temp_path'],
                    'original_name' => $file['name'],
                    'extension' => pathinfo($file['name'], PATHINFO_EXTENSION),
                    'size' => $file['size'],
                    'uploaded_at' => time(),
                    'ocr_confidence' => $result['ocr_confidence'] ?? 0,
                    'verification_score' => $result['verification_score'] ?? 0
                ];
                
                $upload_result = [
                    'success' => true,
                    'message' => 'File ready for preview. Click "Confirm & Submit" to finalize.',
                    'preview' => true,
                    'ocr_confidence' => $result['ocr_confidence'] ?? 0,
                    'verification_score' => $result['verification_score'] ?? 0
                ];
                
                error_log("Preview saved to TEMP: " . $result['temp_path']);
            } else {
                $upload_result = ['success' => false, 'message' => $result['message']];
            }
        }
    }
    
    // Handle final confirmation (permanent upload)
    elseif (isset($_POST['confirm_upload']) && isset($_POST['document_type'])) {
        $doc_type_code = $_POST['document_type'];
        
        error_log("Confirm upload - Student: $student_id, DocType: $doc_type_code");
        
        if (!isset($_SESSION['temp_uploads'][$doc_type_code])) {
            error_log("ERROR: No temp upload found in session for document type: $doc_type_code");
            error_log("Session temp_uploads: " . print_r($_SESSION['temp_uploads'], true));
            $upload_result = ['success' => false, 'message' => 'No file to confirm. Please upload first.'];
        } else {
            $temp_data = $_SESSION['temp_uploads'][$doc_type_code];
            
            error_log("Temp data found: " . print_r($temp_data, true));
            
            // ===== CRITICAL VALIDATION: Comprehensive Document Checks =====
            // ID Picture (04) exempt from ALL validation
            if ($doc_type_code !== '04') {
                $ocr_confidence = $temp_data['ocr_confidence'] ?? 0;
                $verification_status = $temp_data['verification_status'] ?? 'pending';
                $extracted_data = $temp_data['extracted_data'] ?? null;
                $ocr_text = file_exists($temp_data['path'] . '.ocr.txt') ? 
                            strtolower(file_get_contents($temp_data['path'] . '.ocr.txt')) : '';
                
                // Check 1: Minimum confidence threshold (40% for non-ID documents)
                if ($ocr_confidence < 40) {
                    $upload_result = [
                        'success' => false,
                        'message' => "❌ Document quality too low! OCR Confidence: {$ocr_confidence}%\n\nPlease upload a clearer image with:\n• Good lighting\n• All text visible\n• No blur or glare\n\nMinimum required: 40% confidence"
                    ];
                    error_log("UPLOAD BLOCKED: Low confidence ({$ocr_confidence}%) for document {$doc_type_code}");
                }
                
                // ===== DOCUMENT-SPECIFIC VALIDATION =====
                
                // EAF (00): First name, Last name, Course, University, Keywords
                if ($doc_type_code === '00' && !isset($upload_result)) {
                    $errors = [];
                    
                    // CRITICAL: Check verification_passed flag from OCR service
                    // If OCR service already rejected it, block immediately
                    $verification_passed = $temp_data['extracted_data']['verification_passed'] ?? 
                                          ($verification_status === 'passed');
                    
                    if (!$verification_passed) {
                        $errors[] = "• ❌ VALIDATION FAILED: This document did not pass automatic verification.";
                        error_log("UPLOAD BLOCKED: verification_passed = false for EAF");
                    }
                    
                    // Check First Name - STRICT: Must match OCR service thresholds (60%)
                    if ($extracted_data && isset($extracted_data['student_name'])) {
                        $first_name_found = $extracted_data['student_name']['first_name_found'] ?? false;
                        $first_name_similarity = $extracted_data['student_name']['first_name_similarity'] ?? 0;
                        
                        // STRICT: Require 60% similarity (matching EnrollmentFormOCRService)
                        // first_name_found is already calculated with 60% threshold in OCR service
                        if (!$first_name_found) {
                            $errors[] = "• ❌ FIRST NAME MISMATCH: Expected '" . ($student['first_name'] ?? 'Unknown') . "' but found different name (Similarity: {$first_name_similarity}%)";
                            error_log("UPLOAD BLOCKED: First name mismatch - Expected '" . ($student['first_name'] ?? 'Unknown') . "', Similarity: {$first_name_similarity}%");
                        }
                    } else {
                        // If no student_name data at all, it's a critical failure
                        $errors[] = "• ❌ FIRST NAME NOT FOUND: Expected '" . ($student['first_name'] ?? 'Unknown') . "' but document has no readable name";
                        error_log("UPLOAD BLOCKED: No student_name data in extracted_data");
                    }
                    
                    // Check Last Name - STRICT: Must match OCR service thresholds (70%)
                    if ($extracted_data && isset($extracted_data['student_name'])) {
                        $last_name_found = $extracted_data['student_name']['last_name_found'] ?? false;
                        $last_name_similarity = $extracted_data['student_name']['last_name_similarity'] ?? 0;
                        
                        // STRICT: Require 70% similarity (matching EnrollmentFormOCRService)
                        // last_name_found is already calculated with 70% threshold in OCR service
                        if (!$last_name_found) {
                            $errors[] = "• ❌ LAST NAME MISMATCH: Expected '" . ($student['last_name'] ?? 'Unknown') . "' but found different name (Similarity: {$last_name_similarity}%)";
                            error_log("UPLOAD BLOCKED: Last name mismatch - Expected '" . ($student['last_name'] ?? 'Unknown') . "', Similarity: {$last_name_similarity}%");
                        }
                    } else {
                        // If no student_name data at all, it's a critical failure
                        $errors[] = "• ❌ LAST NAME NOT FOUND: Expected '" . ($student['last_name'] ?? 'Unknown') . "' but document has no readable name";
                        error_log("UPLOAD BLOCKED: No student_name data in extracted_data");
                    }
                    
                    // Check Course
                    if ($temp_data['course_data'] ?? null) {
                        // Get student's current course from database
                        $student_course_query = pg_query_params($connection,
                            "SELECT c.course_name, c.program_duration_years 
                             FROM courses c 
                             WHERE c.course_id = $1",
                            [$student['course_id']]
                        );
                        
                        if ($student_course_query && pg_num_rows($student_course_query) > 0) {
                            $student_course = pg_fetch_assoc($student_course_query);
                            $registered_course = strtolower($student_course['course_name']);
                            $eaf_course = strtolower($temp_data['course_data']['normalized_course'] ?? '');
                            
                            $similarity = 0;
                            similar_text($registered_course, $eaf_course, $similarity);
                            
                            if ($similarity < 70) {
                                $errors[] = "• Course mismatch: Expected '{$student_course['course_name']}', found '" . ($temp_data['course_data']['raw_course'] ?? 'Not found') . "'";
                            }
                        }
                    }
                    
                    // Check University - use pre-calculated match from verify.json
                    if ($extracted_data && isset($extracted_data['university'])) {
                        // If university was found, check if it matched
                        $university_found = $extracted_data['university']['found'] ?? false;
                        $university_matched = $extracted_data['university']['matched'] ?? false;
                        
                        if ($university_found && !$university_matched) {
                            // University was found in document but doesn't match expected value
                            $errors[] = "• University mismatch: Expected '" . ($student['university_name'] ?? 'Unknown') . "', found '" . ($extracted_data['university']['raw'] ?? 'Not found') . "'";
                        } elseif (!$university_found) {
                            // University not found in document at all
                            $errors[] = "• University not found in document. Expected '" . ($student['university_name'] ?? 'Unknown') . "'";
                        }
                    }
                    
                    // Check Keywords (enrollment, form, assistance)
                    if (strpos($ocr_text, 'enrollment') === false && strpos($ocr_text, 'enrol') === false) {
                        $errors[] = "• Missing keyword: 'Enrollment'";
                    }
                    
                    if (!empty($errors)) {
                        $upload_result = [
                            'success' => false,
                            'message' => "❌ Enrollment Assistance Form validation failed!\n\n" . implode("\n", $errors) . "\n\nPlease upload the correct EAF document with matching information."
                        ];
                        error_log("UPLOAD BLOCKED (EAF): " . implode(", ", $errors));
                    }
                }
                
                // Grades (01): University, Student Name
                elseif ($doc_type_code === '01' && !isset($upload_result)) {
                    $errors = [];
                    
                    // Check Name (first or last name should appear)
                    $name_found = false;
                    if (stripos($ocr_text, strtolower($student['first_name'] ?? '')) !== false || 
                        stripos($ocr_text, strtolower($student['last_name'] ?? '')) !== false) {
                        $name_found = true;
                    }
                    
                    if (!$name_found) {
                        $errors[] = "• Student name not found in document";
                    }
                    
                    // Check University
                    $university_found = false;
                    $university_keywords = explode(' ', strtolower($student['university_name'] ?? ''));
                    foreach ($university_keywords as $keyword) {
                        if (strlen($keyword) > 3 && stripos($ocr_text, $keyword) !== false) {
                            $university_found = true;
                            break;
                        }
                    }
                    
                    if (!$university_found) {
                        $errors[] = "• University name not found in document (Expected: " . ($student['university_name'] ?? 'Unknown') . ")";
                    }
                    
                    // Check Keywords (grade, grades, academic)
                    if (stripos($ocr_text, 'grade') === false && stripos($ocr_text, 'academic') === false) {
                        $errors[] = "• Missing keyword: 'Grade' or 'Academic'";
                    }
                    
                    if (!empty($errors)) {
                        $upload_result = [
                            'success' => false,
                            'message' => "❌ Academic Grades validation failed!\n\n" . implode("\n", $errors) . "\n\nPlease upload your correct academic grades document."
                        ];
                        error_log("UPLOAD BLOCKED (Grades): " . implode(", ", $errors));
                    }
                }
                
                // Letter to Mayor (02): Municipality, Student Name, Mayor's Office Keywords
                elseif ($doc_type_code === '02' && !isset($upload_result)) {
                    $errors = [];
                    
                    // Check Student Name
                    $name_found = false;
                    if (stripos($ocr_text, strtolower($student['last_name'] ?? '')) !== false) {
                        $name_found = true;
                    }
                    
                    if (!$name_found) {
                        $errors[] = "• Your name not found in letter";
                    }
                    
                    // Check Municipality (General Trias)
                    if (stripos($ocr_text, 'general trias') === false && stripos($ocr_text, 'generaltrias') === false) {
                        $errors[] = "• Municipality 'General Trias' not found";
                    }
                    
                    // Check Mayor's Office Keywords
                    if (stripos($ocr_text, 'mayor') === false && stripos($ocr_text, 'municipal') === false) {
                        $errors[] = "• Missing keyword: 'Mayor' or 'Municipal'";
                    }
                    
                    if (!empty($errors)) {
                        $upload_result = [
                            'success' => false,
                            'message' => "❌ Letter to Mayor validation failed!\n\n" . implode("\n", $errors) . "\n\nPlease upload the correct letter addressed to the Mayor of General Trias."
                        ];
                        error_log("UPLOAD BLOCKED (Letter): " . implode(", ", $errors));
                    }
                }
                
                // Certificate of Indigency (03): Student Name, Municipality, Barangay, Keywords
                elseif ($doc_type_code === '03' && !isset($upload_result)) {
                    $errors = [];
                    
                    // Check Student Name
                    $name_found = false;
                    if (stripos($ocr_text, strtolower($student['last_name'] ?? '')) !== false) {
                        $name_found = true;
                    }
                    
                    if (!$name_found) {
                        $errors[] = "• Your name not found in certificate";
                    }
                    
                    // Check Municipality
                    if (stripos($ocr_text, 'general trias') === false && stripos($ocr_text, 'generaltrias') === false) {
                        $errors[] = "• Municipality 'General Trias' not found";
                    }
                    
                    // Check Barangay
                    $barangay_found = false;
                    if ($student['barangay_name'] ?? null) {
                        $barangay_keywords = explode(' ', strtolower($student['barangay_name']));
                        foreach ($barangay_keywords as $keyword) {
                            if (strlen($keyword) > 3 && stripos($ocr_text, $keyword) !== false) {
                                $barangay_found = true;
                                break;
                            }
                        }
                    }
                    
                    if (!$barangay_found && ($student['barangay_name'] ?? null)) {
                        $errors[] = "• Barangay '" . ($student['barangay_name'] ?? 'Unknown') . "' not found";
                    }
                    
                    // Check Keywords (indigency, indigent, certificate)
                    if (stripos($ocr_text, 'indigen') === false) {
                        $errors[] = "• Missing keyword: 'Indigency' or 'Indigent'";
                    }
                    
                    if (stripos($ocr_text, 'certificate') === false) {
                        $errors[] = "• Missing keyword: 'Certificate'";
                    }
                    
                    if (!empty($errors)) {
                        $upload_result = [
                            'success' => false,
                            'message' => "❌ Certificate of Indigency validation failed!\n\n" . implode("\n", $errors) . "\n\nPlease upload the correct Certificate of Indigency from your barangay."
                        ];
                        error_log("UPLOAD BLOCKED (Certificate): " . implode(", ", $errors));
                    }
                }
            }
            
            // If validation failed, stop here
            if (isset($upload_result) && !$upload_result['success']) {
                // Validation failed - show error message
                // Error message already set above
            } 
            // Check if temp file still exists
            elseif (!file_exists($temp_data['path'])) {
                error_log("ERROR: Temp file does not exist: " . $temp_data['path']);
                $upload_result = ['success' => false, 'message' => 'Temporary file has expired. Please upload again.'];
            } else {
                // Use DocumentReuploadService to move from TEMP to PERMANENT
                $result = $reuploadService->confirmUpload(
                    $student_id,
                    $doc_type_code,
                    $temp_data['path']
                );
                
                error_log("ConfirmUpload result: " . print_r($result, true));
                
                if ($result['success']) {
                    // Clear session temp data
                    unset($_SESSION['temp_uploads'][$doc_type_code]);
                    
                    $upload_result = ['success' => true, 'message' => 'Document submitted successfully and is now under review!'];
                    
                    // Refresh page to show new upload
                    header("Location: upload_document.php?success=1");
                    exit;
                } else {
                    $upload_result = ['success' => false, 'message' => $result['message'] ?? 'Upload failed'];
                    error_log("Permanent upload failed: " . $upload_result['message']);
                }
            }
        }
    }
    
    // Handle cancel preview
    elseif (isset($_POST['cancel_preview']) && isset($_POST['document_type'])) {
        // Check if this is an AJAX request
        $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
        
        $doc_type_code = $_POST['document_type'];
        
        if (isset($_SESSION['temp_uploads'][$doc_type_code])) {
            $temp_data = $_SESSION['temp_uploads'][$doc_type_code];
            
            // Use DocumentReuploadService to cancel preview
            $cancelResult = $reuploadService->cancelPreview($temp_data['path']);
            unset($_SESSION['temp_uploads'][$doc_type_code]);
            
            error_log("Cancel preview for $doc_type_code - Result: " . ($cancelResult['success'] ? 'SUCCESS' : 'FAILED'));
            
            if ($is_ajax) {
                // Return JSON for AJAX requests
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => 'Preview cancelled successfully.',
                    'document_type' => $doc_type_code
                ]);
                exit;
            } else {
                // Redirect for regular form submissions
                header("Location: upload_document.php?cancelled=" . $doc_type_code);
                exit;
            }
        } else {
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'No preview to cancel.']);
                exit;
            } else {
                $upload_result = ['success' => false, 'message' => 'No preview to cancel.'];
            }
        }
    }
    
    // Handle re-upload of existing document (delete existing and start fresh)
    elseif (isset($_POST['start_reupload']) && isset($_POST['document_type'])) {
        // Check if this is an AJAX request
        $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
        
        $doc_type_code = $_POST['document_type'];
        
        // First, get the file path before deleting from database
        $file_query = pg_query_params($connection,
            "SELECT file_path FROM documents WHERE student_id = $1 AND document_type_code = $2",
            [$student_id, $doc_type_code]
        );
        
        $file_to_delete = null;
        if ($file_query && pg_num_rows($file_query) > 0) {
            $file_row = pg_fetch_assoc($file_query);
            $file_to_delete = $file_row['file_path'];
        }
        
        // Delete existing document from database
        $delete_query = pg_query_params($connection,
            "DELETE FROM documents WHERE student_id = $1 AND document_type_code = $2",
            [$student_id, $doc_type_code]
        );
        
        if ($delete_query) {
            // Delete ALL files for this student and document type from permanent storage
            // Use FilePathConfig to get the correct permanent directory structure
            require_once __DIR__ . '/../../config/FilePathConfig.php';
            $pathConfig = FilePathConfig::getInstance();
            
            // Get document folder mapping
            $docFolderMap = [
                '04' => 'id_pictures',
                '00' => 'enrollment_forms',
                '01' => 'grades',
                '02' => 'letter_to_mayor',
                '03' => 'indigency'
            ];
            
            if (isset($docFolderMap[$doc_type_code])) {
                $docFolder = $docFolderMap[$doc_type_code];
                
                // Build student-specific directory path: assets/uploads/student/{doc_type}/{student_id}/
                $studentDir = $pathConfig->getStudentPath($docFolder) . DIRECTORY_SEPARATOR . $student_id;
                
                if (is_dir($studentDir)) {
                    error_log("Re-upload: Deleting ALL files from directory - $studentDir");
                    
                    // Delete ALL files in the student's directory for this document type
                    $allFiles = glob($studentDir . DIRECTORY_SEPARATOR . '*');
                    $deletedCount = 0;
                    foreach ($allFiles as $file) {
                        if (is_file($file)) {
                            if (@unlink($file)) {
                                $deletedCount++;
                                error_log("Re-upload: Deleted - " . basename($file));
                            } else {
                                error_log("Re-upload: Failed to delete - " . basename($file));
                            }
                        }
                    }
                    
                    error_log("Re-upload: Deleted $deletedCount file(s) for student $student_id, doc type $doc_type_code");
                    
                    // Remove the now-empty student directory
                    if (count(glob($studentDir . DIRECTORY_SEPARATOR . '*')) === 0) {
                        @rmdir($studentDir);
                        error_log("Re-upload: Removed empty student directory - $studentDir");
                    }
                } else {
                    error_log("Re-upload: Student directory not found - $studentDir");
                }
            }
            
            // Also delete from old file path (if stored in database) - for backwards compatibility
            if ($file_to_delete) {
                $server_root = dirname(__DIR__, 2);
                $file_path = $file_to_delete;
                if (strpos($file_path, '../../') === 0) {
                    $file_path = $server_root . '/' . substr($file_path, 6);
                }
                
                if (file_exists($file_path)) {
                    @unlink($file_path);
                    error_log("Re-upload: Deleted old database path file - $file_path");
                }
            }
            
            // Clear any temp uploads for this document type
            if (isset($_SESSION['temp_uploads'][$doc_type_code])) {
                $temp_data = $_SESSION['temp_uploads'][$doc_type_code];
                $reuploadService->cancelPreview($temp_data['path']);
                unset($_SESSION['temp_uploads'][$doc_type_code]);
            }
            
            if ($is_ajax) {
                // Return JSON for AJAX requests
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => 'Document deleted successfully. You can now upload a new document.',
                    'document_type' => $doc_type_code
                ]);
                exit;
            } else {
                // Redirect to refresh the page and show upload form
                header("Location: upload_document.php?reupload_started=" . $doc_type_code);
                exit;
            }
        } else {
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Failed to delete existing document. Please try again.']);
                exit;
            } else {
                $upload_result = ['success' => false, 'message' => 'Failed to delete existing document. Please try again.'];
            }
        }
    }
}

$page_title = 'Upload Documents';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> - EducAid</title>
    
  <!-- Critical CSS for FOUC Prevention -->
  <style>
    body {
      opacity: 0;
      transition: opacity 0.3s ease;
      background: #f5f5f5;
    }
    body.ready {
      opacity: 1;
    }
    body:not(.ready) .sidebar {
      visibility: hidden;
    }
  </style>    <!-- Bootstrap 5.3.3 + Icons -->
    <link href="../../assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="../../assets/css/bootstrap-icons.css" rel="stylesheet">
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="../../assets/css/student/sidebar.css">
    <link rel="stylesheet" href="../../assets/css/student/distribution_notifications.css">
    
    <style>
        
        .home-section {
            margin-left: 260px;
            padding: 2rem;
            padding-top: calc(var(--student-header-h, 56px) + 2rem);
            min-height: 100vh;
            background: #f8f9fa;
        }
        
        .sidebar.close ~ .home-section {
            margin-left: 78px;
        }
        
        @media (max-width: 768px) {
            .home-section {
                margin-left: 0;
                padding: 1rem;
                padding-top: calc(var(--student-header-h, 56px) + 1rem);
            }
        }
        
        .page-header {
            background: white;
            border-radius: 16px;
            padding: 1.5rem 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
        }
        
        .page-header h1 {
            font-size: 1.75rem;
            font-weight: 600;
            margin: 0 0 0.5rem 0;
            color: #212529;
        }
        
        .page-header p {
            margin: 0;
            color: #6c757d;
        }
        
        .document-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 1.5rem;
            height: 100%;
            transition: all 0.3s ease;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
        }
        
        .document-card:hover {
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        
        .document-card.uploaded {
            border-color: #bbf7d0;
            background: linear-gradient(to bottom, #f0fdf4, white);
        }
        
        .document-card.required:not(.uploaded) {
            /* Subtle amber background for required documents not yet uploaded */
            background: #fffbeb;
            border-color: #fde68a;
        }
        
        .document-card.required.uploaded {
            /* Green when required and uploaded */
            background: linear-gradient(to bottom, #f0fdf4, white);
            border-color: #bbf7d0;
        }
        
        .document-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        
        .document-icon {
            width: 56px;
            height: 56px;
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }
        
        .document-card.uploaded .document-icon {
            background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
            box-shadow: 0 4px 12px rgba(34, 197, 94, 0.3);
        }
        
        .document-title {
            flex-grow: 1;
        }
        
        .document-title h5 {
            margin: 0 0 0.25rem 0;
            font-size: 1.125rem;
            font-weight: 600;
        }
        
        .document-preview {
            max-width: 100%;
            height: 200px;
            object-fit: contain;
            border-radius: 8px;
            margin: 1rem 0;
            cursor: pointer;
            border: 2px solid #e9ecef;
            transition: all 0.2s ease;
        }
        
        .document-preview:hover {
            opacity: 0.9;
            transform: scale(1.02);
        }
        
        .upload-zone {
            border: 2px dashed #cbd5e1;
            border-radius: 12px;
            padding: 2rem;
            text-align: center;
            background: #f8fafc;
            cursor: pointer;
            transition: all 0.3s ease;
            margin: 1rem 0;
        }
        
        .upload-zone:hover {
            border-color: #0068da;
            background: #eef2ff;
        }
        
        .upload-zone.dragover {
            border-color: #0068da;
            background: #dbeafe;
            transform: scale(1.02);
        }
        
        .upload-zone i {
            font-size: 3rem;
            color: #cbd5e1;
            margin-bottom: 1rem;
        }
        
        .status-badge {
            padding: 0.375rem 0.75rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
        }
        
        .view-only-banner, .reupload-banner {
            padding: 1.25rem 1.5rem;
            border-radius: 16px;
            margin-bottom: 2rem;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
        }
        
        .view-only-banner {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
        }
        
        .view-only-banner.approved {
            background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
            color: white;
        }
        
        .reupload-banner {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
        }
        
        .banner-content {
            display: flex;
            align-items: start;
            gap: 1rem;
        }
        
        .banner-content i {
            font-size: 2rem;
            flex-shrink: 0;
            margin-top: 0.25rem;
        }
        
        .banner-content h5 {
            margin: 0 0 0.5rem 0;
            font-weight: 600;
        }
        
        .banner-content p {
            margin: 0;
            opacity: 0.95;
        }
        
        .confidence-badges {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-top: 0.75rem;
        }
        
        .confidence-badge {
            padding: 0.25rem 0.625rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            color: white;
        }
        
        .document-meta {
            color: #6c757d;
            font-size: 0.875rem;
            margin-top: 0.75rem;
        }
        
        .document-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-top: 1rem;
        }
        
        .pdf-preview {
            background: linear-gradient(135deg, #f3f4f6, #e5e7eb);
            border: 2px dashed #cbd5e1;
            border-radius: 8px;
            padding: 2rem;
            text-align: center;
            margin: 1rem 0;
        }
        
        .pdf-preview i {
            font-size: 4rem;
            color: #dc3545;
        }
        
        .preview-document {
            background: #fffbea;
            border: 2px solid #fbbf24;
            border-radius: 12px;
            padding: 1.5rem;
            margin: 1rem 0;
        }
        
        .preview-document .document-preview {
            border: 3px solid #fbbf24;
        }
        
        .preview-document .document-meta {
            background: white;
            padding: 0.75rem;
            border-radius: 8px;
            margin-top: 1rem;
        }
    </style>
</head>
<body>
    <!-- Student Topbar -->
    <style>
        /* Modal overlay stacking fix */
        .modal { z-index: 1065; }
        .modal-backdrop { z-index: 1060; }
        .sidebar-backdrop { z-index: 1040; }
    </style>
    <?php include __DIR__ . '/../../includes/student/student_topbar.php'; ?>
    
    <div id="wrapper" style="padding-top: var(--topbar-h, 60px);">
    <?php include __DIR__ . '/../../includes/student/student_sidebar.php'; ?>
    
    <!-- Main Header -->
    <?php include __DIR__ . '/../../includes/student/student_header.php'; ?>
    
    <section class="home-section" id="page-content-wrapper">
        <div class="container-fluid">
            <!-- Page Header -->
            <div class="page-header">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="flex-grow-1">
                        <h1><i class="bi bi-cloud-upload"></i> Upload Documents</h1>
                        <p>Manage your application documents</p>
                    </div>
                    <div class="text-center" id="realtime-indicator" style="display: none;">
                        <small class="text-success d-block">
                            <i class="bi bi-arrow-repeat" style="animation: spin 2s linear infinite;"></i>
                            <span>Auto-updating</span>
                        </small>
                        <small class="text-muted" style="font-size: 0.7rem;">Checks every 3s</small>
                    </div>
                </div>
            </div>
            
            <style>
                @keyframes spin {
                    from { transform: rotate(0deg); }
                    to { transform: rotate(360deg); }
                }
                
                @keyframes flashGreen {
                    0% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
                    50% { box-shadow: 0 0 20px 10px rgba(16, 185, 129, 0.4); }
                    100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
                }
                
                .document-card.updated {
                    animation: flashGreen 1s ease-out;
                }
            </style>
            
            <!-- Testing Mode Banner -->
            <?php if ($test_mode): ?>
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                <i class="bi bi-tools"></i> <strong>Testing Mode Active!</strong> Re-upload is enabled for testing OCR results. 
                Remove <code>?test_reupload=1</code> from URL to return to normal mode.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <!-- Debug Info (for testing) -->
            <?php if (isset($_GET['debug'])): ?>
            <div class="alert alert-info alert-dismissible fade show" role="alert">
                <strong>Debug Info:</strong><br>
                - student_status: <?= htmlspecialchars($student_status) ?><br>
                - needs_upload: <?= $needs_upload ? 'TRUE' : 'FALSE' ?><br>
                - can_upload: <?= $can_upload ? 'TRUE' : 'FALSE' ?><br>
                - documents_to_reupload: <?= !empty($documents_to_reupload) ? implode(', ', $documents_to_reupload) : 'NONE' ?><br>
                - is_new_registrant: <?= $is_new_registrant ? 'TRUE' : 'FALSE' ?><br>
                - test_mode: <?= $test_mode ? 'ENABLED' : 'DISABLED' ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <!-- Success Message -->
            <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle"></i> <strong>Success!</strong> Document submitted successfully and awaiting admin approval.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <!-- Cancelled Message -->
            <?php if (isset($_GET['cancelled'])): ?>
            <?php 
                $cancelled_doc_code = $_GET['cancelled'];
                $cancelled_doc_name = $document_types[$cancelled_doc_code]['name'] ?? 'Document';
            ?>
            <div class="alert alert-info alert-dismissible fade show" role="alert">
                <i class="bi bi-x-circle"></i> <strong>Preview Cancelled!</strong> 
                The temporary <?= htmlspecialchars($cancelled_doc_name) ?> file has been removed. 
                You can upload a new file below.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <!-- Re-upload Started Message -->
            <?php if (isset($_GET['reupload_started'])): ?>
            <?php 
                $reupload_doc_code = $_GET['reupload_started'];
                $reupload_doc_name = $document_types[$reupload_doc_code]['name'] ?? 'Document';
            ?>
            <div class="alert alert-info alert-dismissible fade show" role="alert">
                <i class="bi bi-arrow-repeat"></i> <strong>Re-upload Started!</strong> 
                The existing <?= htmlspecialchars($reupload_doc_name) ?> has been removed. 
                You can now upload a new file below.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <!-- Preview Success Message -->
            <?php if ($upload_result && $upload_result['success'] && isset($upload_result['preview'])): ?>
            <div class="alert alert-info alert-dismissible fade show" role="alert">
                <i class="bi bi-info-circle"></i> <strong>Preview Ready!</strong> <?= htmlspecialchars($upload_result['message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php elseif ($upload_result && $upload_result['success']): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle"></i> <strong>Success!</strong> <?= htmlspecialchars($upload_result['message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <!-- Error Message -->
            <?php if ($upload_result && !$upload_result['success']): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle"></i> <strong>Upload Failed!</strong> <?= htmlspecialchars($upload_result['message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <!-- View-Only Banner (New Registrants or Active Students or Students who received aid) -->
            <?php if ($is_new_registrant): ?>
            <div class="view-only-banner <?= ($student_status === 'active' || $student_status === 'given') ? 'approved' : '' ?>">
                <div class="banner-content">
                    <i class="bi bi-<?= ($student_status === 'active' || $student_status === 'given') ? 'check-circle' : 'info-circle' ?>"></i>
                    <div>
                        <?php if ($student_status === 'given'): ?>
                        <h5><i class="bi bi-lock-fill"></i> Aid Received - Read-Only Mode</h5>
                        <p>Your educational assistance has been distributed! Your status is now <strong>GIVEN</strong> and your documents have been locked for record-keeping purposes. You cannot modify or re-upload documents at this time. If you need assistance, please contact the admin.</p>
                        <?php elseif ($student_status === 'active'): ?>
                        <h5><i class="bi bi-lock-fill"></i> Documents Approved - Read-Only Mode</h5>
                        <p>Congratulations! Your application has been approved and your status is now <strong>ACTIVE</strong>. Your documents have been verified and locked for security. You cannot modify or re-upload documents at this time. If you need to make changes, please contact the admin.</p>
                        <?php else: ?>
                        <h5>View-Only Mode</h5>
                        <p>You registered through our online system and submitted all required documents during registration. Your documents are currently under review by our admin team. You cannot re-upload documents at this time.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Re-upload Banner (Existing Students and Migrated Students) -->
            <?php if ($can_upload && !$test_mode): ?>
            <div class="reupload-banner">
                <div class="banner-content">
                    <i class="bi bi-<?= $is_migrated ? 'upload' : 'arrow-repeat' ?>"></i>
                    <div>
                        <?php if ($is_migrated): ?>
                        <h5>Welcome, Migrated Student! - Document Upload Required</h5>
                        <p>As a migrated student, you need to upload your documents to complete your profile. Please upload all required documents below. Your uploads will be saved and sent to the admin for verification.</p>
                        <?php else: ?>
                        <h5>Document Re-upload Required</h5>
                        <p>Please upload the required documents below. Your uploads will be saved directly to permanent storage and sent to the admin for immediate review.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Document Cards -->
            <div class="row g-4">
                <?php foreach ($document_types as $type_code => $type_info): ?>
                <?php 
                    $has_document = isset($existing_documents[$type_code]);
                    $doc = $has_document ? $existing_documents[$type_code] : null;
                    $is_image = $has_document && preg_match('/\.(jpg|jpeg|png|gif)$/i', $doc['file_path']);
                    $is_pdf = $has_document && preg_match('/\.pdf$/i', $doc['file_path']);
                    
                    // Check if this document needs re-upload
                    $needs_reupload = $can_upload && in_array($type_code, $documents_to_reupload);
                    $is_view_only = !$needs_reupload;
                ?>
                <div class="col-lg-6">
                    <div class="document-card <?= $has_document ? 'uploaded' : '' ?> <?= $type_info['required'] ? 'required' : '' ?> <?= $needs_reupload ? 'border-warning' : '' ?>">
                        <div class="document-header">
                            <div class="document-icon">
                                <i class="bi bi-<?= $type_info['icon'] ?>"></i>
                            </div>
                            <div class="document-title">
                                <h5>
                                    <?= htmlspecialchars($type_info['name']) ?>
                                </h5>
                                <div>
                                    <?php if ($has_document): ?>
                                    <span class="status-badge bg-success text-white">
                                        <i class="bi bi-check-circle-fill"></i> Uploaded
                                    </span>
                                    <?php else: ?>
                                    <span class="status-badge bg-secondary text-white">
                                        <i class="bi bi-x-circle"></i> Not Uploaded
                                    </span>
                                    <?php endif; ?>
                                    
                                    <?php if ($needs_reupload): ?>
                                    <span class="badge bg-warning text-dark ms-2">
                                        <i class="bi bi-arrow-repeat"></i> Needs Re-upload
                                    </span>
                                    <?php elseif ($is_view_only): ?>
                                    <span class="badge bg-info text-white ms-2">
                                        <i class="bi bi-eye"></i> View Only
                                    </span>
                                    <?php endif; ?>
                                    
                                    <?php if ($type_info['required']): ?>
                                    <span class="badge bg-danger ms-2">Required</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <?php if (isset($rejection_reasons_map[$type_code])): ?>
                        <!-- Rejection Reason Alert -->
                        <div class="alert alert-danger mb-3 mt-3" role="alert">
                            <div class="d-flex align-items-start">
                                <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
                                <div class="flex-grow-1">
                                    <strong>Document Rejected</strong>
                                    <p class="mb-0 mt-1">
                                        <strong>Reason:</strong> <?= htmlspecialchars($rejection_reasons_map[$type_code]['reason']) ?>
                                    </p>
                                    <small class="text-muted">Please re-upload this document with the corrections.</small>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($has_document): ?>
                        <!-- Show existing document -->
                        <div class="existing-document">
                            <?php if ($is_image): ?>
                            <?php
                                // Convert absolute database path to web-accessible relative path (Railway-compatible)
                                $webDocPath = convertToWebPath($doc['file_path']);
                            ?>
                            <img src="<?= htmlspecialchars($webDocPath) ?>?v=<?= time() ?>" 
                                 class="document-preview"
                                 onclick="viewDocument('<?= addslashes($webDocPath) ?>', '<?= addslashes($type_info['name']) ?>')"
                                 alt="<?= htmlspecialchars($type_info['name']) ?>"
                                 onerror="console.error('Failed to load:', this.src);">
                            <?php elseif ($is_pdf): ?>
                            <div class="pdf-preview">
                                <i class="bi bi-file-pdf-fill"></i>
                                <p class="mb-0 mt-2"><strong>PDF Document</strong></p>
                            </div>
                            <?php endif; ?>
                            
                            <div class="document-meta">
                                <i class="bi bi-calendar3"></i> 
                                Uploaded: <?= date('M d, Y g:i A', strtotime($doc['upload_date'])) ?>
                            </div>
                            
                            <div class="document-actions">
                                <button class="btn btn-primary btn-sm" 
                                        onclick="viewDocument('<?= addslashes($webDocPath) ?>', '<?= addslashes($type_info['name']) ?>')">
                                    <i class="bi bi-eye"></i> View
                                </button>
                                <a href="<?= htmlspecialchars($webDocPath) ?>" 
                                   download 
                                   class="btn btn-outline-secondary btn-sm">
                                    <i class="bi bi-download"></i> Download
                                </a>
                                <?php if ($needs_reupload): ?>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="document_type" value="<?= $type_code ?>">
                                    <input type="hidden" name="start_reupload" value="1">
                                    <button type="submit" class="btn btn-warning btn-sm">
                                        <i class="bi bi-arrow-repeat"></i> Re-upload
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php else: ?>
                        <!-- Upload form (only for documents that need re-upload) -->
                        <?php if ($needs_reupload): ?>
                            <?php 
                            // Check if there's a preview file in session
                            $has_preview = isset($_SESSION['temp_uploads'][$type_code]);
                            $preview_data = $has_preview ? $_SESSION['temp_uploads'][$type_code] : null;
                            ?>
                            
                            <?php if ($has_preview): ?>
                            <!-- Preview Mode -->
                            <div class="alert alert-warning mb-3">
                                <i class="bi bi-exclamation-triangle"></i> 
                                <strong>Preview Mode:</strong> File ready for submission. Review and confirm below.
                            </div>
                            
                            <div class="preview-document">
                                <?php 
                                $preview_is_image = in_array($preview_data['extension'], ['jpg', 'jpeg', 'png', 'gif']);
                                $preview_is_pdf = $preview_data['extension'] === 'pdf';
                                
                                // Convert absolute temp path to web-accessible relative path (Railway-compatible)
                                $webPath = convertToWebPath($preview_data['path']);
                                
                                // Debug log
                                error_log("Preview path conversion: " . $preview_data['path'] . " → " . $webPath);
                                ?>
                                
                                <?php if ($preview_is_image): ?>
                                <img src="<?= htmlspecialchars($webPath) ?>?v=<?= time() ?>" 
                                     class="document-preview"
                                     alt="Preview"
                                     onerror="console.error('Failed to load image:', this.src); this.style.display='none'; this.nextElementSibling.style.display='block';">
                                <div class="pdf-preview" style="display: none;">
                                    <i class="bi bi-image text-danger"></i>
                                    <p class="mb-0 mt-2"><strong>Image Preview Unavailable</strong></p>
                                    <small class="text-muted">File uploaded successfully but preview failed to load</small>
                                    <div class="mt-2"><code class="small"><?= htmlspecialchars($webPath) ?></code></div>
                                </div>
                                <?php elseif ($preview_is_pdf): ?>
                                <div class="pdf-preview">
                                    <i class="bi bi-file-pdf-fill"></i>
                                    <p class="mb-0 mt-2"><strong>PDF Document Ready</strong></p>
                                    <small class="text-muted"><?= htmlspecialchars($preview_data['original_name']) ?></small>
                                </div>
                                <?php endif; ?>
                                
                                <div class="document-meta">
                                    <i class="bi bi-file-earmark"></i> <?= htmlspecialchars($preview_data['original_name']) ?>
                                    <span class="ms-2">
                                        <i class="bi bi-hdd"></i> <?= number_format($preview_data['size'] / 1024, 2) ?> KB
                                    </span>
                                    <?php if (isset($preview_data['uploaded_at'])): ?>
                                    <span class="ms-2">
                                        <i class="bi bi-clock"></i> <?= is_numeric($preview_data['uploaded_at']) ? date('g:i A', $preview_data['uploaded_at']) : $preview_data['uploaded_at'] ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Confidence Badges (shown after OCR processing) -->
                                <?php if (isset($preview_data['ocr_processed_at'])): ?>
                                <div class="confidence-badges">
                                    <?php
                                    $confidence = $preview_data['ocr_confidence'] ?? 0;
                                    $status = $preview_data['verification_status'] ?? 'pending';
                                    
                                    if ($confidence >= 75) {
                                        $badge_class = 'bg-success';
                                        $badge_icon = 'check-circle-fill';
                                        $badge_text = 'High Confidence';
                                    } elseif ($confidence >= 50) {
                                        $badge_class = 'bg-warning';
                                        $badge_icon = 'exclamation-triangle-fill';
                                        $badge_text = 'Manual Review';
                                    } else {
                                        $badge_class = 'bg-danger';
                                        $badge_icon = 'x-circle-fill';
                                        $badge_text = 'Low Confidence';
                                    }
                                    ?>
                                    <span class="confidence-badge <?= $badge_class ?>">
                                        <i class="bi bi-<?= $badge_icon ?>"></i>
                                        OCR: <?= round($confidence, 1) ?>%
                                    </span>
                                    <span class="confidence-badge <?= $badge_class ?>">
                                        <?= $badge_text ?>
                                    </span>
                                </div>
                                <?php endif; ?>
                                
                                <!-- Validation Warnings (for non-ID documents) -->
                                <?php if (isset($preview_data['ocr_processed_at']) && $type_code !== '04'): ?>
                                    <?php 
                                    $warnings = [];
                                    $confidence = $preview_data['ocr_confidence'] ?? 0;
                                    $extracted_data = $preview_data['extracted_data'] ?? null;
                                    $verification_status = $preview_data['verification_status'] ?? 'pending';
                                    
                                    // Check confidence threshold
                                    if ($confidence < 40) {
                                        $warnings[] = "⚠️ <strong>Very Low Quality:</strong> OCR confidence is {$confidence}%. Upload will be rejected. Please upload a clearer image.";
                                    } elseif ($confidence < 60) {
                                        $warnings[] = "⚠️ <strong>Low Quality:</strong> OCR confidence is {$confidence}%. Consider re-uploading a clearer image for faster approval.";
                                    }
                                    
                                    // CRITICAL: Check EAF validation results (from EnrollmentFormOCRService)
                                    if ($type_code === '00' && $extracted_data && is_array($extracted_data)) {
                                        // Check verification_passed flag
                                        $verification_passed = $extracted_data['verification_passed'] ?? 
                                                              ($verification_status === 'passed');
                                        
                                        if (!$verification_passed) {
                                            // Show specific validation failures
                                            if (isset($extracted_data['student_name'])) {
                                                $first_name_found = $extracted_data['student_name']['first_name_found'] ?? false;
                                                $last_name_found = $extracted_data['student_name']['last_name_found'] ?? false;
                                                $first_name_similarity = $extracted_data['student_name']['first_name_similarity'] ?? 0;
                                                $last_name_similarity = $extracted_data['student_name']['last_name_similarity'] ?? 0;
                                                
                                                if (!$first_name_found) {
                                                    $warnings[] = "❌ <strong>FIRST NAME MISMATCH:</strong> Expected '" . ($student['first_name'] ?? 'Unknown') . "' but found different name (Match: " . round($first_name_similarity, 1) . "%)";
                                                }
                                                
                                                if (!$last_name_found) {
                                                    $warnings[] = "❌ <strong>LAST NAME MISMATCH:</strong> Expected '{$student['last_name']}' but found different name (Match: " . round($last_name_similarity, 1) . "%)";
                                                }
                                            }
                                            
                                            // Check document type validation
                                            if (isset($extracted_data['document_type'])) {
                                                $is_enrollment_form = $extracted_data['document_type']['is_enrollment_form'] ?? false;
                                                if (!$is_enrollment_form) {
                                                    $warnings[] = "❌ <strong>WRONG DOCUMENT TYPE:</strong> This does not appear to be an Enrollment Assistance Form";
                                                }
                                            }
                                            
                                            // General rejection message
                                            if (empty($warnings)) {
                                                $warnings[] = "❌ <strong>VALIDATION FAILED:</strong> This document does not match your registration information. Please upload the correct Enrollment Assistance Form.";
                                            }
                                        }
                                    }
                                    // Letter to Mayor (02) validation warnings
                                    elseif ($type_code === '02') {
                                        // Read verify.json to check for validation failures
                                        $verifyJsonPath = $preview_data['path'] . '.verify.json';
                                        if (file_exists($verifyJsonPath)) {
                                            $verifyData = json_decode(file_get_contents($verifyJsonPath), true);
                                            
                                            // Check for cross-document confusion
                                            if (isset($verifyData['wrong_document_type']) && $verifyData['wrong_document_type']) {
                                                $warnings[] = "❌ <strong>WRONG DOCUMENT TYPE:</strong> This appears to be a \"" . 
                                                             ($verifyData['detected_document_type'] ?? 'different document') . 
                                                             "\", not a \"Letter to Mayor\". Please upload it in the correct document field.";
                                            }
                                            
                                            // Check individual field validations
                                            if (isset($verifyData['first_name']) && !$verifyData['first_name']) {
                                                $warnings[] = "❌ <strong>NAME MISMATCH:</strong> Your first name '" . ($student['first_name'] ?? 'Unknown') . "' was not found in the letter";
                                            }
                                            
                                            if (isset($verifyData['last_name']) && !$verifyData['last_name']) {
                                                $warnings[] = "❌ <strong>NAME MISMATCH:</strong> Your last name '{$student['last_name']}' was not found in the letter";
                                            }
                                            
                                            if (isset($verifyData['mayor_header']) && !$verifyData['mayor_header']) {
                                                $warnings[] = "⚠️ <strong>Missing Mayor's Office Header:</strong> Document should be addressed to the Mayor";
                                            }
                                            
                                            if (isset($verifyData['barangay']) && !$verifyData['barangay']) {
                                                $warnings[] = "⚠️ <strong>Barangay Not Found:</strong> Expected '{$student['barangay_name']}'";
                                            }
                                        }
                                    }
                                    // Certificate of Indigency (03) validation warnings
                                    elseif ($type_code === '03') {
                                        // Read verify.json to check for validation failures
                                        $verifyJsonPath = $preview_data['path'] . '.verify.json';
                                        if (file_exists($verifyJsonPath)) {
                                            $verifyData = json_decode(file_get_contents($verifyJsonPath), true);
                                            
                                            // Check for cross-document confusion
                                            if (isset($verifyData['wrong_document_type']) && $verifyData['wrong_document_type']) {
                                                $warnings[] = "❌ <strong>WRONG DOCUMENT TYPE:</strong> This appears to be a \"" . 
                                                             ($verifyData['detected_document_type'] ?? 'different document') . 
                                                             "\", not a \"Certificate of Indigency\". Please upload it in the correct document field.";
                                            }
                                            
                                            // Check for missing critical keywords
                                            if (isset($verifyData['missing_keywords']) && $verifyData['missing_keywords']) {
                                                $warnings[] = "❌ <strong>MISSING KEYWORD:</strong> Document does not contain \"indigency\" or \"indigent\" - not a valid Certificate of Indigency";
                                            }
                                            
                                            // Check individual field validations
                                            if (isset($verifyData['certificate_title']) && !$verifyData['certificate_title']) {
                                                $warnings[] = "❌ <strong>Missing Certificate Title:</strong> Document should have \"Certificate of Indigency\" title";
                                            }
                                            
                                            if (isset($verifyData['first_name']) && !$verifyData['first_name']) {
                                                $warnings[] = "❌ <strong>NAME MISMATCH:</strong> Your first name '" . ($student['first_name'] ?? 'Unknown') . "' was not found in the certificate";
                                            }
                                            
                                            if (isset($verifyData['last_name']) && !$verifyData['last_name']) {
                                                $warnings[] = "❌ <strong>NAME MISMATCH:</strong> Your last name '{$student['last_name']}' was not found in the certificate";
                                            }
                                            
                                            if (isset($verifyData['barangay']) && !$verifyData['barangay']) {
                                                $warnings[] = "⚠️ <strong>Barangay Not Found:</strong> Expected '{$student['barangay_name']}'";
                                            }
                                        }
                                    }
                                    // Legacy validation for non-EAF documents or old data structure
                                    elseif (isset($preview_data['extracted_data']['last_name'])) {
                                        $student_last_name = strtolower(trim($student['last_name']));
                                        $ocr_last_name = strtolower(trim($preview_data['extracted_data']['last_name']['normalized'] ?? $preview_data['extracted_data']['last_name']['raw'] ?? ''));
                                        $similarity = 0;
                                        similar_text($student_last_name, $ocr_last_name, $similarity);
                                        
                                        if ($similarity < 60) {
                                            $warnings[] = "⚠️ <strong>Name Mismatch:</strong> Your name ({$student['last_name']}) doesn't match the document. Upload will be rejected.";
                                        }
                                    }
                                    
                                    if (!empty($warnings)): ?>
                                    <div class="alert alert-danger mt-3">
                                        <?php foreach ($warnings as $warning): ?>
                                        <div class="mb-2"><?= $warning ?></div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                                
                                <div class="document-actions mt-3">
                                    <!-- Process Document Button (shown before OCR) -->
                                    <?php if (!isset($preview_data['ocr_processed_at'])): ?>
                                    <button type="button" 
                                            class="btn btn-primary btn-sm"
                                            id="process-btn-<?= $type_code ?>"
                                            onclick="processDocument('<?= $type_code ?>')">
                                        <i class="bi bi-cpu"></i> Process Document
                                    </button>
                                    <?php else: ?>
                                    <!-- Confirm & Submit Button (shown after OCR) -->
                                    <?php
                                    // CRITICAL: Check if document PASSED OCR validation
                                    $will_pass_validation = true;
                                    $validation_message = '';
                                    
                                    if ($type_code !== '04') { // All documents except ID Picture
                                        $confidence = $preview_data['ocr_confidence'] ?? 0;
                                        $verification_status = $preview_data['verification_status'] ?? 'pending';
                                        $extracted_data = $preview_data['extracted_data'] ?? null;
                                        
                                        // Check 1: Minimum confidence threshold
                                        if ($confidence < 40) {
                                            $will_pass_validation = false;
                                            $validation_message = '❌ Document quality too low (minimum 40% required)';
                                        }
                                        
                                        // Check 2: For EAF (00), check if OCR service validation passed
                                        if ($type_code === '00') {
                                            // Check verification_passed flag from EnrollmentFormOCRService
                                            $verification_passed = false;
                                            
                                            // Check if extracted_data has verification result
                                            if ($extracted_data && is_array($extracted_data)) {
                                                // EnrollmentFormOCRService returns verification_passed at root level
                                                $verification_passed = $extracted_data['verification_passed'] ?? 
                                                                      ($verification_status === 'passed');
                                            }
                                            
                                            if (!$verification_passed) {
                                                $will_pass_validation = false;
                                                $validation_message = '❌ Document validation failed - Name or details do not match your registration';
                                                
                                                // Get specific failure reasons from extracted_data
                                                if ($extracted_data && isset($extracted_data['student_name'])) {
                                                    $first_name_found = $extracted_data['student_name']['first_name_found'] ?? false;
                                                    $last_name_found = $extracted_data['student_name']['last_name_found'] ?? false;
                                                    
                                                    if (!$first_name_found) {
                                                        $validation_message .= " (First name mismatch)";
                                                    }
                                                    if (!$last_name_found) {
                                                        $validation_message .= " (Last name mismatch)";
                                                    }
                                                }
                                            }
                                        }
                                        
                                        // Check 2b: For Letter to Mayor (02), check validation
                                        if ($type_code === '02') {
                                            $verifyJsonPath = $preview_data['path'] . '.verify.json';
                                            if (file_exists($verifyJsonPath)) {
                                                $verifyData = json_decode(file_get_contents($verifyJsonPath), true);
                                                $verification_passed = $verifyData['verification_passed'] ?? 
                                                                      ($verification_status === 'passed');
                                                
                                                if (!$verification_passed) {
                                                    $will_pass_validation = false;
                                                    
                                                    // Check for wrong document type
                                                    if (isset($verifyData['wrong_document_type']) && $verifyData['wrong_document_type']) {
                                                        $validation_message = '❌ Wrong document type - This is not a Letter to Mayor';
                                                    } else {
                                                        $validation_message = '❌ Document validation failed - Required information not found';
                                                    }
                                                }
                                            }
                                        }
                                        
                                        // Check 2c: For Certificate of Indigency (03), check validation
                                        if ($type_code === '03') {
                                            $verifyJsonPath = $preview_data['path'] . '.verify.json';
                                            if (file_exists($verifyJsonPath)) {
                                                $verifyData = json_decode(file_get_contents($verifyJsonPath), true);
                                                $verification_passed = $verifyData['verification_passed'] ?? 
                                                                      ($verification_status === 'passed');
                                                
                                                if (!$verification_passed) {
                                                    $will_pass_validation = false;
                                                    
                                                    // Check for wrong document type
                                                    if (isset($verifyData['wrong_document_type']) && $verifyData['wrong_document_type']) {
                                                        $validation_message = '❌ Wrong document type - This is not a Certificate of Indigency';
                                                    } elseif (isset($verifyData['missing_keywords']) && $verifyData['missing_keywords']) {
                                                        $validation_message = '❌ Missing "indigency" keyword - Not a valid certificate';
                                                    } else {
                                                        $validation_message = '❌ Document validation failed - Required information not found';
                                                    }
                                                }
                                            }
                                        }
                                        
                                        // Check 3: Verification status should not be 'failed'
                                        if ($verification_status === 'failed') {
                                            $will_pass_validation = false;
                                            $validation_message = '❌ Document verification failed';
                                        }
                                    }
                                    ?>
                                    
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="document_type" value="<?= $type_code ?>">
                                        <input type="hidden" name="confirm_upload" value="1">
                                        <button type="submit" 
                                                class="btn btn-success btn-sm"
                                                id="confirm-btn-<?= $type_code ?>"
                                                <?= !$will_pass_validation ? 'disabled title="' . htmlspecialchars($validation_message) . '"' : '' ?>>
                                            <i class="bi bi-check-circle"></i> Confirm & Submit
                                        </button>
                                    </form>
                                    <?php if (!$will_pass_validation): ?>
                                    <div class="d-block mt-2">
                                        <small class="text-danger">
                                            <i class="bi bi-x-circle"></i> <?= htmlspecialchars($validation_message) ?>
                                        </small>
                                    </div>
                                    <?php endif; ?>
                                    <?php endif; ?>
                                    
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="document_type" value="<?= $type_code ?>">
                                        <input type="hidden" name="cancel_preview" value="1">
                                        <button type="submit" class="btn btn-outline-danger btn-sm">
                                            <i class="bi bi-x-circle"></i> Cancel & Replace
                                        </button>
                                    </form>
                                </div>
                            </div>
                            <?php else: ?>
                            <!-- Upload Zone -->
                            <div class="upload-zone" id="upload-zone-<?= $type_code ?>" onclick="document.getElementById('file-<?= $type_code ?>').click()">
                                <i class="bi bi-cloud-upload"></i>
                                <p class="mb-2 mt-2"><strong>Click to upload or drag and drop</strong></p>
                                <p class="text-muted small mb-0">
                                    Accepted: <?= str_replace(['image/', 'application/'], '', $type_info['accept']) ?>
                                </p>
                                <!-- AJAX-only file input (removed form submit to prevent reload duplication) -->
                                <input type="file" 
                                       name="document_file" 
                                       id="file-<?= $type_code ?>"
                                       data-doc-type="<?= $type_code ?>"
                                       accept="<?= $type_info['accept'] ?>"
                                       style="display: none;">
                            </div>
                            <?php endif; ?>
                        <?php else: ?>
                        <div class="alert alert-light">
                            <i class="bi bi-info-circle"></i> No document uploaded yet. <?= $is_view_only ? '(View-only mode)' : '' ?>
                        </div>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    
    </div><!-- #wrapper -->
    
    <!-- Priority Notification Modal (for rejected documents) -->
    <?php include __DIR__ . '/../../includes/student/priority_notification_modal.php'; ?>
    
    <!-- Document Viewer Modal -->
    <div class="modal fade" id="documentViewerModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="documentViewerTitle">Document</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center" style="background: #000;">
                    <img id="documentViewerImage" src="" style="max-width: 100%; max-height: 80vh; display: none;">
                    <iframe id="documentViewerPdf" src="" style="width: 100%; height: 80vh; display: none; border: none;"></iframe>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Year Level Update Modal -->
    <div class="modal fade" id="yearLevelUpdateModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
                <!-- Enhanced Header with Gradient -->
                <div class="modal-header border-0 text-white position-relative" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 28px 32px;">
                    <div class="position-relative z-1 w-100">
                        <div class="d-flex align-items-center mb-2">
                            <div class="icon-wrapper me-3" style="width: 48px; height: 48px; background: rgba(255,255,255,0.2); border-radius: 12px; display: flex; align-items: center; justify-content: center; backdrop-filter: blur(10px);">
                                <i class="bi bi-calendar-check" style="font-size: 24px;"></i>
                            </div>
                            <div>
                                <h5 class="modal-title mb-0" style="font-size: 1.4rem; font-weight: 700; text-shadow: 0 2px 4px rgba(0,0,0,0.2);">
                                    <?php if ($needs_university_selection): ?>
                                        Complete Your Profile
                                    <?php elseif ($current_academic_year): ?>
                                        Update Year Level
                                    <?php else: ?>
                                        Update Your Year Level
                                    <?php endif; ?>
                                </h5>
                                <?php if ($needs_university_selection && !empty($student['status_academic_year'])): ?>
                                    <p class="mb-0 mt-1" style="font-size: 0.9rem; opacity: 0.95;">You were registered for Academic Year <?= htmlspecialchars($student['status_academic_year']) ?></p>
                                <?php elseif ($current_academic_year): ?>
                                    <p class="mb-0 mt-1" style="font-size: 0.9rem; opacity: 0.95;">Academic Year <?= htmlspecialchars($current_academic_year) ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <!-- Decorative Elements -->
                    <div class="position-absolute" style="top: -50%; right: -10%; width: 200%; height: 200%; background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%); pointer-events: none;"></div>
                </div>
                
                <div class="modal-body" style="padding: 32px;">
                    <!-- Enhanced Info Alert -->
                    <div class="alert border-0 mb-4" style="background: linear-gradient(135deg, #e0f2fe 0%, #dbeafe 100%); border-left: 4px solid #3b82f6 !important; border-radius: 12px; padding: 20px;">
                        <div class="d-flex align-items-start">
                            <div class="flex-shrink-0 me-3">
                                <div style="width: 40px; height: 40px; background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); border-radius: 10px; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);">
                                    <i class="bi bi-info-circle" style="font-size: 20px; color: white;"></i>
                                </div>
                            </div>
                            <div class="flex-grow-1">
                                <strong style="color: #1e40af; font-size: 0.95rem;"><?= htmlspecialchars($year_level_update_message) ?></strong>
                            </div>
                        </div>
                    </div>
                    
                    <?php if (!empty($student['current_year_level']) && !empty($student['status_academic_year'])): ?>
                    <!-- Enhanced Previous Record Card -->
                    <div class="card border-0 mb-4" style="background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.04);">
                        <div class="card-body" style="padding: 20px;">
                            <div class="d-flex align-items-center mb-2">
                                <i class="bi bi-clock-history me-2" style="color: #6b7280; font-size: 18px;"></i>
                                <small class="text-muted fw-semibold text-uppercase" style="font-size: 0.75rem; letter-spacing: 0.5px;">Your Previous Record</small>
                            </div>
                            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                                <div>
                                    <strong style="font-size: 1.1rem; color: #1f2937;"><?= htmlspecialchars($student['current_year_level']) ?></strong> 
                                    <span class="text-muted" style="font-size: 0.9rem;">• A.Y. <?= htmlspecialchars($student['status_academic_year']) ?></span>
                                </div>
                                <?php if ($student['is_graduating'] === 't'): ?>
                                    <span class="badge" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); padding: 6px 12px; border-radius: 8px; font-weight: 600;">
                                        <i class="bi bi-trophy me-1"></i> Was Graduating
                                    </span>
                                <?php elseif ($student['is_graduating'] === 'f'): ?>
                                    <span class="badge" style="background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); padding: 6px 12px; border-radius: 8px; font-weight: 600;">
                                        <i class="bi bi-arrow-repeat me-1"></i> Was Continuing
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <form id="yearLevelUpdateForm">
                        <?php if ($needs_university_selection): ?>
                        <!-- University Selection for Migrated Students -->
                        <div class="mb-4">
                            <label class="form-label fw-bold mb-3" style="color: #1f2937; font-size: 1rem;">
                                <i class="bi bi-building me-2" style="color: #667eea;"></i> University <span class="text-danger">*</span>
                            </label>
                            <select class="form-select form-select-lg" name="university_id" required style="border-radius: 12px; border: 2px solid #e5e7eb; padding: 14px 18px; font-size: 1rem; transition: all 0.2s;">
                                <option value="">-- Select Your University --</option>
                                <?php
                                $universities_query = pg_query($connection, "SELECT university_id, name FROM universities ORDER BY name");
                                while ($univ = pg_fetch_assoc($universities_query)):
                                ?>
                                <option value="<?= $univ['university_id'] ?>"><?= htmlspecialchars($univ['name']) ?></option>
                                <?php endwhile; ?>
                            </select>
                            <div class="mt-2" style="background: #e0f2fe; border-left: 3px solid #3b82f6; padding: 12px 16px; border-radius: 8px;">
                                <div class="d-flex align-items-start">
                                    <i class="bi bi-info-circle me-2 flex-shrink-0" style="color: #1e40af; font-size: 18px; margin-top: 2px;"></i>
                                    <small style="color: #1e3a8a; line-height: 1.5;">
                                        Please select the university where you are currently enrolled
                                    </small>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Mother's Maiden Name -->
                        <div class="mb-4">
                            <label class="form-label fw-bold mb-3" style="color: #1f2937; font-size: 1rem;">
                                <i class="bi bi-person-heart me-2" style="color: #667eea;"></i> Mother's Maiden Name <span class="text-danger">*</span>
                            </label>
                            <input type="text" class="form-control form-control-lg" name="mothers_maiden_name" 
                                   value="<?= htmlspecialchars($student['mothers_maiden_name'] ?? '') ?>" 
                                   required 
                                   placeholder="Enter mother's surname before marriage"
                                   style="border-radius: 12px; border: 2px solid #e5e7eb; padding: 14px 18px; font-size: 1rem; transition: all 0.2s;">
                            <div class="mt-2" style="background: #f3e8ff; border-left: 3px solid #9333ea; padding: 12px 16px; border-radius: 8px;">
                                <div class="d-flex align-items-start">
                                    <i class="bi bi-info-circle me-2 flex-shrink-0" style="color: #7e22ce; font-size: 18px; margin-top: 2px;"></i>
                                    <small style="color: #581c87; line-height: 1.5;">
                                        This helps prevent duplicate household registrations within your barangay
                                    </small>
                                </div>
                            </div>
                        </div>
                        
                        <!-- School Student ID -->
                        <div class="mb-4">
                            <label class="form-label fw-bold mb-3" style="color: #1f2937; font-size: 1rem;">
                                <i class="bi bi-card-text me-2" style="color: #667eea;"></i> School Student ID <span class="text-danger">*</span>
                            </label>
                            <input type="text" class="form-control form-control-lg" name="school_student_id" 
                                   value="<?= htmlspecialchars($student['school_student_id'] ?? '') ?>" 
                                   required 
                                   placeholder="Enter your school-issued student ID number"
                                   style="border-radius: 12px; border: 2px solid #e5e7eb; padding: 14px 18px; font-size: 1rem; transition: all 0.2s;">
                            <div class="mt-2" style="background: #fef3c7; border-left: 3px solid #f59e0b; padding: 12px 16px; border-radius: 8px;">
                                <div class="d-flex align-items-start">
                                    <i class="bi bi-info-circle me-2 flex-shrink-0" style="color: #d97706; font-size: 18px; margin-top: 2px;"></i>
                                    <small style="color: #78350f; line-height: 1.5;">
                                        This is the student ID number issued by your university (e.g., 2020-12345)
                                    </small>
                                </div>
                            </div>
                        </div>
                        
                        <!-- New Password (Migrated Students Only - Optional) -->
                        <div class="mb-4">
                            <label class="form-label fw-bold mb-3" style="color: #1f2937; font-size: 1rem;">
                                <i class="bi bi-shield-lock me-2" style="color: #667eea;"></i> New Password <span class="text-muted">(Optional)</span>
                            </label>
                            <div class="position-relative">
                                <input type="password" class="form-control form-control-lg" id="newPassword" name="new_password" 
                                       minlength="8"
                                       placeholder="Leave blank to keep current password"
                                       style="border-radius: 12px; border: 2px solid #e5e7eb; padding: 14px 48px 14px 18px; font-size: 1rem; transition: all 0.2s;">
                                <button type="button" class="btn btn-link position-absolute" id="toggleNewPassword" 
                                        style="right: 8px; top: 50%; transform: translateY(-50%); padding: 4px 8px; color: #6b7280;">
                                    <i class="bi bi-eye" id="newPasswordIcon"></i>
                                </button>
                            </div>
                            <div class="mt-2" style="background: #e0f2fe; border-left: 3px solid #3b82f6; padding: 12px 16px; border-radius: 8px;">
                                <div class="d-flex align-items-start">
                                    <i class="bi bi-info-circle me-2 flex-shrink-0" style="color: #1e40af; font-size: 18px; margin-top: 2px;"></i>
                                    <small style="color: #1e3a8a; line-height: 1.5;">
                                        <strong>Optional:</strong> If you want to change your password, enter a new one (minimum 8 characters). Otherwise, leave it blank to continue with your current password.
                                    </small>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Confirm Password -->
                        <div class="mb-4">
                            <label class="form-label fw-bold mb-3" style="color: #1f2937; font-size: 1rem;">
                                <i class="bi bi-shield-check me-2" style="color: #667eea;"></i> Confirm Password <span class="text-muted">(If changing)</span>
                            </label>
                            <div class="position-relative">
                                <input type="password" class="form-control form-control-lg" id="confirmPassword" name="confirm_password" 
                                       minlength="8"
                                       placeholder="Re-enter your new password"
                                       style="border-radius: 12px; border: 2px solid #e5e7eb; padding: 14px 48px 14px 18px; font-size: 1rem; transition: all 0.2s;">
                                <button type="button" class="btn btn-link position-absolute" id="toggleConfirmPassword" 
                                        style="right: 8px; top: 50%; transform: translateY(-50%); padding: 4px 8px; color: #6b7280;">
                                    <i class="bi bi-eye" id="confirmPasswordIcon"></i>
                                </button>
                            </div>
                            <div class="mt-2" id="passwordMatchMessage" style="display: none;">
                                <!-- Password match indicator will appear here -->
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Enhanced Year Level Select -->
                        <div class="mb-4">
                            <label class="form-label fw-bold mb-3" style="color: #1f2937; font-size: 1rem;">
                                <i class="bi bi-mortarboard me-2" style="color: #667eea;"></i> Current Year Level <span class="text-danger">*</span>
                            </label>
                            <select class="form-select form-select-lg" name="year_level" required style="border-radius: 12px; border: 2px solid #e5e7eb; padding: 14px 18px; font-size: 1rem; transition: all 0.2s;">
                                <option value="">-- Select Your Current Year Level --</option>
                                <option value="1st Year" <?= ($student['current_year_level'] ?? '') === '1st Year' ? 'selected' : '' ?>>1st Year</option>
                                <option value="2nd Year" <?= ($student['current_year_level'] ?? '') === '2nd Year' ? 'selected' : '' ?>>2nd Year</option>
                                <option value="3rd Year" <?= ($student['current_year_level'] ?? '') === '3rd Year' ? 'selected' : '' ?>>3rd Year</option>
                                <option value="4th Year" <?= ($student['current_year_level'] ?? '') === '4th Year' ? 'selected' : '' ?>>4th Year</option>
                                <option value="5th Year" <?= ($student['current_year_level'] ?? '') === '5th Year' ? 'selected' : '' ?>>5th Year</option>
                            </select>
                            
                            <!-- Enhanced Help Text -->
                            <div class="mt-3" style="background: #fef3c7; border-left: 3px solid #f59e0b; padding: 12px 16px; border-radius: 8px;">
                                <div class="d-flex align-items-start">
                                    <i class="bi bi-arrow-up-circle me-2 flex-shrink-0" style="color: #d97706; font-size: 18px; margin-top: 2px;"></i>
                                    <small style="color: #78350f; line-height: 1.5;">
                                        <strong>Reminder:</strong> Students must advance to the next year level each academic year
                                    </small>
                                </div>
                            </div>
                            
                            <div class="mt-2" style="background: #fee2e2; border-left: 3px solid #ef4444; padding: 12px 16px; border-radius: 8px;">
                                <div class="d-flex align-items-start">
                                    <i class="bi bi-exclamation-triangle me-2 flex-shrink-0" style="color: #dc2626; font-size: 18px; margin-top: 2px;"></i>
                                    <small style="color: #7f1d1d; line-height: 1.5;">
                                        <strong>Important:</strong> If you are repeating a year or went down a level, your account will be deactivated for admin verification
                                    </small>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Enhanced Graduation Status -->
                        <div class="mb-4">
                            <label class="form-label fw-bold mb-3" style="color: #1f2937; font-size: 1rem;">
                                <i class="bi bi-calendar-check me-2" style="color: #667eea;"></i> Graduation Status <span class="text-danger">*</span>
                            </label>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <input type="radio" class="btn-check" name="is_graduating" id="not_graduating" value="0" <?= (isset($student['is_graduating']) && $student['is_graduating'] === 'f') ? 'checked' : '' ?>>
                                    <label class="btn btn-outline-primary w-100 h-100 d-flex flex-column align-items-center justify-content-center p-4" for="not_graduating" style="border-radius: 12px; border-width: 2px; transition: all 0.3s; min-height: 180px;">
                                        <div class="icon-circle mb-3" style="width: 70px; height: 70px; background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center; transition: all 0.3s;">
                                            <i class="bi bi-arrow-repeat" style="font-size: 32px; color: #3b82f6;"></i>
                                        </div>
                                        <strong class="mb-2" style="font-size: 1.1rem;">Still Continuing</strong>
                                        <p class="mb-0 small text-muted" style="line-height: 1.4;">I will continue my studies next academic year</p>
                                    </label>
                                </div>
                                <div class="col-md-6">
                                    <input type="radio" class="btn-check" name="is_graduating" id="graduating" value="1" <?= (isset($student['is_graduating']) && $student['is_graduating'] === 't') ? 'checked' : '' ?>>
                                    <label class="btn btn-outline-success w-100 h-100 d-flex flex-column align-items-center justify-content-center p-4" for="graduating" style="border-radius: 12px; border-width: 2px; transition: all 0.3s; min-height: 180px;">
                                        <div class="icon-circle mb-3" style="width: 70px; height: 70px; background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center; transition: all 0.3s;">
                                            <i class="bi bi-trophy" style="font-size: 32px; color: #10b981;"></i>
                                        </div>
                                        <strong class="mb-2" style="font-size: 1.1rem;">Graduating</strong>
                                        <p class="mb-0 small text-muted" style="line-height: 1.4;">This is my final year of studies</p>
                                    </label>
                                </div>
                            </div>
                        </div>
                        
                        <style>
                            /* Enhanced select and input styling */
                            .form-select:focus,
                            .form-control:focus {
                                border-color: #667eea !important;
                                box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1) !important;
                            }
                            
                            /* Enhanced radio button styling */
                            .btn-check:checked + label {
                                transform: translateY(-2px);
                                box-shadow: 0 8px 20px rgba(0,0,0,0.12) !important;
                            }
                            
                            .btn-check:checked + label.btn-outline-primary {
                                background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%) !important;
                                border-color: #2563eb !important;
                                color: white !important;
                            }
                            
                            .btn-check:checked + label.btn-outline-primary .icon-circle {
                                background: rgba(255,255,255,0.2) !important;
                            }
                            
                            .btn-check:checked + label.btn-outline-primary .icon-circle i {
                                color: white !important;
                            }
                            
                            .btn-check:checked + label.btn-outline-primary .text-muted {
                                color: rgba(255,255,255,0.9) !important;
                            }
                            
                            .btn-check:checked + label.btn-outline-success {
                                background: linear-gradient(135deg, #10b981 0%, #059669 100%) !important;
                                border-color: #059669 !important;
                                color: white !important;
                            }
                            
                            .btn-check:checked + label.btn-outline-success .icon-circle {
                                background: rgba(255,255,255,0.2) !important;
                            }
                            
                            .btn-check:checked + label.btn-outline-success .icon-circle i {
                                color: white !important;
                            }
                            
                            .btn-check:checked + label.btn-outline-success .text-muted {
                                color: rgba(255,255,255,0.9) !important;
                            }
                            
                            .btn-outline-primary:hover,
                            .btn-outline-success:hover {
                                transform: translateY(-2px);
                                box-shadow: 0 4px 12px rgba(0,0,0,0.08);
                            }
                        </style>
                        
                        <input type="hidden" name="academic_year" value="<?= htmlspecialchars($current_academic_year ?? '') ?>">
                        <input type="hidden" name="update_year_level" value="1">
                        
                        <div class="d-grid mt-4">
                            <button type="submit" class="btn btn-lg text-white" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border: none; border-radius: 12px; padding: 16px; font-weight: 600; font-size: 1.05rem; transition: all 0.3s; box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);">
                                <i class="bi bi-check-circle me-2"></i> Update & Continue
                            </button>
                            <div id="yearUpdateStatus" class="mt-2 small text-muted" role="status" aria-live="polite"></div>
                        </div>
                        
                        <style>
                            button[type="submit"]:hover {
                                transform: translateY(-2px);
                                box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4) !important;
                            }
                            
                            button[type="submit"]:active {
                                transform: translateY(0);
                            }
                        </style>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <script src="../../assets/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/js/student/sidebar.js"></script>
    
    <!-- Real-Time Distribution Monitor -->
    <script src="../../assets/js/student/distribution_monitor.js"></script>
    
    <script>
        // Mark body as ready after scripts load
        document.body.classList.add('js-ready');
        
        // Store return URL if this is a forced update
        <?php if ($force_update && isset($_SESSION['return_after_year_update'])): ?>
        sessionStorage.setItem('return_after_year_update', <?= json_encode($_SESSION['return_after_year_update']) ?>);
        <?php endif; ?>
        
        // Show year level update modal on page load if needed (for year level updates OR migrated student university selection)
        <?php if ($needs_year_level_update || $needs_university_selection): ?>
        document.addEventListener('DOMContentLoaded', function() {
            const yearLevelModal = new bootstrap.Modal(document.getElementById('yearLevelUpdateModal'));
            yearLevelModal.show();
        });
        <?php endif; ?>
        
        // Password validation for migrated students
        document.addEventListener('DOMContentLoaded', function() {
            const newPasswordInput = document.getElementById('newPassword');
            const confirmPasswordInput = document.getElementById('confirmPassword');
            const passwordMatchMessage = document.getElementById('passwordMatchMessage');
            const toggleNewPasswordBtn = document.getElementById('toggleNewPassword');
            const toggleConfirmPasswordBtn = document.getElementById('toggleConfirmPassword');
            const newPasswordIcon = document.getElementById('newPasswordIcon');
            const confirmPasswordIcon = document.getElementById('confirmPasswordIcon');
            
            // Toggle password visibility for new password
            if (toggleNewPasswordBtn) {
                toggleNewPasswordBtn.addEventListener('click', function() {
                    const type = newPasswordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                    newPasswordInput.setAttribute('type', type);
                    newPasswordIcon.classList.toggle('bi-eye');
                    newPasswordIcon.classList.toggle('bi-eye-slash');
                });
            }
            
            // Toggle password visibility for confirm password
            if (toggleConfirmPasswordBtn) {
                toggleConfirmPasswordBtn.addEventListener('click', function() {
                    const type = confirmPasswordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                    confirmPasswordInput.setAttribute('type', type);
                    confirmPasswordIcon.classList.toggle('bi-eye');
                    confirmPasswordIcon.classList.toggle('bi-eye-slash');
                });
            }
            
            // Real-time password match validation
            function checkPasswordMatch() {
                if (!confirmPasswordInput || !newPasswordInput) return;
                
                const newPassword = newPasswordInput.value;
                const confirmPassword = confirmPasswordInput.value;
                
                if (confirmPassword.length === 0) {
                    passwordMatchMessage.style.display = 'none';
                    return;
                }
                
                passwordMatchMessage.style.display = 'block';
                
                if (newPassword === confirmPassword) {
                    passwordMatchMessage.innerHTML = `
                        <div style="background: #d1fae5; border-left: 3px solid #10b981; padding: 12px 16px; border-radius: 8px;">
                            <div class="d-flex align-items-start">
                                <i class="bi bi-check-circle-fill me-2 flex-shrink-0" style="color: #059669; font-size: 18px; margin-top: 2px;"></i>
                                <small style="color: #065f46; line-height: 1.5;">
                                    Passwords match!
                                </small>
                            </div>
                        </div>
                    `;
                    confirmPasswordInput.style.borderColor = '#10b981';
                } else {
                    passwordMatchMessage.innerHTML = `
                        <div style="background: #fee2e2; border-left: 3px solid #ef4444; padding: 12px 16px; border-radius: 8px;">
                            <div class="d-flex align-items-start">
                                <i class="bi bi-x-circle-fill me-2 flex-shrink-0" style="color: #dc2626; font-size: 18px; margin-top: 2px;"></i>
                                <small style="color: #991b1b; line-height: 1.5;">
                                    Passwords do not match
                                </small>
                            </div>
                        </div>
                    `;
                    confirmPasswordInput.style.borderColor = '#ef4444';
                }
            }
            
            if (confirmPasswordInput) {
                confirmPasswordInput.addEventListener('input', checkPasswordMatch);
                confirmPasswordInput.addEventListener('blur', checkPasswordMatch);
            }
            if (newPasswordInput) {
                newPasswordInput.addEventListener('input', checkPasswordMatch);
            }
        });
        
        // Handle year level update form submission
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('yearLevelUpdateForm');
            if (form) {
                if (form.dataset.listenerAttached === '1') { return; }
                form.dataset.listenerAttached = '1';
                form.addEventListener('submit', async function(e) {
                    e.preventDefault();
                    const statusEl = document.getElementById('yearUpdateStatus');
                    if (statusEl) {
                        statusEl.className = 'mt-2 small text-muted';
                        statusEl.textContent = 'Submitting, please wait…';
                    }
                    
                    // Validate passwords match for migrated students (only if password is being changed)
                    const newPasswordInput = document.getElementById('newPassword');
                    const confirmPasswordInput = document.getElementById('confirmPassword');
                    
                    if (newPasswordInput && confirmPasswordInput) {
                        const newPwd = newPasswordInput.value.trim();
                        const confirmPwd = confirmPasswordInput.value.trim();
                        
                        // Only validate if user is trying to change password
                        if (newPwd || confirmPwd) {
                            if (newPwd !== confirmPwd) {
                                alert('Passwords do not match. Please check and try again.');
                                if (statusEl) { statusEl.textContent = ''; }
                                return;
                            }
                            
                            if (newPwd.length < 8) {
                                alert('Password must be at least 8 characters long.');
                                if (statusEl) { statusEl.textContent = ''; }
                                return;
                            }
                        }
                    }
                    
                    const formData = new FormData(form);
                    const submitBtn = form.querySelector('button[type="submit"]');
                    const originalBtnText = submitBtn.innerHTML;
                    
                    // Disable button and show loading
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Updating...';
                    
                    try {
                        const response = await fetch('upload_document.php', {
                            method: 'POST',
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            body: formData
                        });
                        
                        // Try to parse JSON regardless of status (server ensures JSON)
                        const text = await response.text();
                        console.log('Raw response:', text);
                        
                        let result;
                        try {
                            result = JSON.parse(text);
                        } catch (jsonError) {
                            console.error('JSON parse error:', jsonError);
                            console.error('Response text:', text);
                            throw new Error('Server returned invalid response.');
                        }
                        // If HTTP not OK but JSON provided, surface message gracefully
                        if (!response.ok && result && typeof result === 'object') {
                            throw new Error(result.message || `Server returned ${response.status}`);
                        }
                        
                        if (result.success) {
                            if (statusEl) {
                                statusEl.className = 'mt-2 small text-success';
                                statusEl.textContent = 'Updated successfully.';
                            }
                            if (result.archived && result.logout_required) {
                                // Account was archived - show message and logout
                                alert(result.message);
                                window.location.href = '../../unified_login.php?archived=1';
                            } else {
                                // Normal success - Show success message
                                const alertDiv = document.createElement('div');
                                alertDiv.className = 'alert alert-success alert-dismissible fade show';
                                alertDiv.innerHTML = `
                                    <i class="bi bi-check-circle me-2"></i>
                                    ${result.message}
                                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                `;
                                
                                // Close modal
                                const modal = bootstrap.Modal.getInstance(document.getElementById('yearLevelUpdateModal'));
                                modal.hide();
                                
                                // Check if there's a return URL from forced update
                                const urlParams = new URLSearchParams(window.location.search);
                                const returnUrl = sessionStorage.getItem('return_after_year_update');
                                
                                if (returnUrl && urlParams.has('force_update')) {
                                    // Redirect back to page they were trying to access
                                    sessionStorage.removeItem('return_after_year_update');
                                    window.location.href = returnUrl;
                                } else {
                                    // Insert alert at top of page
                                    document.querySelector('.container-fluid').insertBefore(alertDiv, document.querySelector('.container-fluid').firstChild);
                                    
                                    // Reload page after 1 second to reflect changes
                                    setTimeout(() => {
                                        window.location.reload();
                                    }, 1000);
                                }
                            }
                        } else if (result.requires_admin && result.confirm_required) {
                            // Student is repeating/going down - requires admin verification
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = originalBtnText;
                            if (statusEl) { statusEl.textContent = ''; }
                            
                            const confirmMsg = result.message + '\n\nClick OK to confirm and deactivate your account. You will need to contact admin to reactivate.';
                            
                            if (confirm(confirmMsg)) {
                                // User confirmed - add archive flag and resubmit
                                formData.append('confirm_archive', '1');
                                
                                // Resubmit with archive confirmation
                                submitBtn.disabled = true;
                                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Archiving...';
                                
                                const archiveResponse = await fetch('upload_document.php', {
                                    method: 'POST',
                                    body: formData
                                });
                                
                                const archiveResult = await archiveResponse.json();
                                
                                if (archiveResult.success && archiveResult.archived) {
                                    alert(archiveResult.message);
                                    window.location.href = '../../unified_login.php?archived=1';
                                } else {
                                    alert(archiveResult.message || 'Failed to deactivate account. Please contact administrator.');
                                    submitBtn.disabled = false;
                                    submitBtn.innerHTML = originalBtnText;
                                    if (statusEl) { statusEl.textContent = ''; }
                                }
                            }
                        } else {
                            // Show error message
                            alert(result.message || 'Failed to update year level. Please try again.');
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = originalBtnText;
                            if (statusEl) {
                                statusEl.className = 'mt-2 small text-danger';
                                statusEl.innerHTML = `${(result.message || 'An error occurred.')} <a href="#" id="retryYearUpdate">Try again</a>`;
                            }
                        }
                    } catch (error) {
                        console.error('Error updating year level:', error);
                        alert('An error occurred. Please try again.');
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = originalBtnText;
                        if (statusEl) {
                            statusEl.className = 'mt-2 small text-danger';
                            statusEl.innerHTML = `Request failed. <a href="#" id="retryYearUpdate">Try again</a>`;
                        }
                        // Attach a one-time retry handler
                        document.addEventListener('click', function onRetry(e){
                            const target = e.target;
                            if (target && target.id === 'retryYearUpdate') {
                                e.preventDefault();
                                document.removeEventListener('click', onRetry);
                                submitBtn.click();
                            }
                        });
                    }
                });
            }
        });
        
        // Helper function to strip HTML tags from text (for alert messages)
        function stripHtml(html) {
            const tmp = document.createElement('DIV');
            tmp.innerHTML = html;
            return tmp.textContent || tmp.innerText || '';
        }
        
        // AJAX Upload Function (prevents page refresh)
        async function handleFileUpload(typeCode, file) {
            const formData = new FormData();
            formData.append('ajax_upload', '1');
            formData.append('document_type', typeCode);
            formData.append('document_file', file);
            
            try {
                const response = await fetch('upload_document.php', {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formData
                });
                
                // Check if response is JSON
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    const text = await response.text();
                    console.error('Server returned non-JSON response:', text.substring(0, 500));
                    console.error('Full response length:', text.length);
                    throw new Error('Server error: Expected JSON response but got ' + contentType);
                }
                
                const data = await response.json();
                
                if (data.success) {
                    // Small delay to ensure response is fully processed before reload
                    await new Promise(resolve => setTimeout(resolve, 100));
                    
                    // Reload the page to show the preview (remove any URL parameters)
                    window.location.href = 'upload_document.php';
                } else {
                    alert('Upload failed: ' + stripHtml(data.message));
                }
            } catch (error) {
                console.error('Upload error:', error);
                
                // If it's a "Failed to fetch" error but the file might have uploaded,
                // reload the page after a delay to check
                if (error.message && error.message.includes('Failed to fetch')) {
                    console.log('Network error detected, reloading page to check upload status...');
                    setTimeout(() => {
                        window.location.href = 'upload_document.php';
                    }, 500);
                } else {
                    alert('Upload failed: ' + stripHtml(error.message || 'Please try again.'));
                }
            }
        }
        
        // Global lock to prevent concurrent OCR processing
        // Use sessionStorage to persist across page reloads
        let isProcessing = sessionStorage.getItem('isProcessing') === 'true';
        let currentProcessingType = sessionStorage.getItem('currentProcessingType');
        
        // Clear stale processing locks on page load (older than 2 minutes)
        const processingTimestamp = sessionStorage.getItem('processingTimestamp');
        if (processingTimestamp) {
            const elapsedTime = Date.now() - parseInt(processingTimestamp);
            if (elapsedTime > 120000) { // 2 minutes
                console.log('⚠️ Clearing stale processing lock (elapsed: ' + (elapsedTime / 1000) + 's)');
                sessionStorage.removeItem('isProcessing');
                sessionStorage.removeItem('currentProcessingType');
                sessionStorage.removeItem('processingTimestamp');
                isProcessing = false;
                currentProcessingType = null;
            }
        }
        
        // Process Document OCR with concurrency protection
        async function processDocument(typeCode) {
            // IMMEDIATELY disable button on click to prevent double-clicks
            const processBtn = document.getElementById('process-btn-' + typeCode);
            
            if (!processBtn) {
                console.error('Process button not found for type:', typeCode);
                return;
            }
            
            // Check if already disabled (race condition protection)
            if (processBtn.disabled) {
                console.warn('Button already disabled, ignoring click');
                return;
            }
            
            // Prevent concurrent processing
            if (isProcessing) {
                alert('⏳ A file is still being processed. Please wait until it completes.');
                console.warn('Concurrent processing blocked - currently processing:', currentProcessingType);
                return;
            }
            
            console.log('processDocument() called with typeCode:', typeCode);
            
            const originalHTML = processBtn.innerHTML;
            
            // IMMEDIATELY disable this button and all others
            processBtn.disabled = true;
            disableAllProcessButtons();
            
            // Set global processing lock AND persist to sessionStorage
            isProcessing = true;
            currentProcessingType = typeCode;
            sessionStorage.setItem('isProcessing', 'true');
            sessionStorage.setItem('currentProcessingType', typeCode);
            sessionStorage.setItem('processingTimestamp', Date.now().toString());
            
            // Show loading state on current button
            processBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Processing OCR...';
            
            const formData = new FormData();
            formData.append('process_document', '1');
            formData.append('document_type', typeCode);
            
            try {
                const response = await fetch('upload_document.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    // Clear processing lock before reload
                    sessionStorage.removeItem('isProcessing');
                    sessionStorage.removeItem('currentProcessingType');
                    sessionStorage.removeItem('processingTimestamp');
                    
                    // Show success message and reload to show confidence badges
                    console.log('✅ OCR processing successful:', data);
                    alert('✅ Document processed successfully!\n\nOCR Confidence: ' + (data.ocr_confidence || 0) + '%\nVerification Score: ' + (data.verification_score || 0) + '%');
                    window.location.reload();
                } else {
                    console.error('Processing failed:', data.message);
                    alert('❌ Processing failed: ' + stripHtml(data.message));
                    
                    // Clear processing lock and re-enable buttons
                    isProcessing = false;
                    currentProcessingType = null;
                    sessionStorage.removeItem('isProcessing');
                    sessionStorage.removeItem('currentProcessingType');
                    sessionStorage.removeItem('processingTimestamp');
                    
                    enableAllProcessButtons();
                    processBtn.innerHTML = originalHTML;
                }
            } catch (error) {
                console.error('Processing error:', error);
                alert('❌ Processing failed: ' + stripHtml(error.message || 'Please try again.'));
                
                // Clear processing lock and re-enable buttons
                isProcessing = false;
                currentProcessingType = null;
                sessionStorage.removeItem('isProcessing');
                sessionStorage.removeItem('currentProcessingType');
                sessionStorage.removeItem('processingTimestamp');
                
                enableAllProcessButtons();
                processBtn.innerHTML = originalHTML;
            }
        }
        
        // Disable all Process Document buttons
        function disableAllProcessButtons() {
            document.querySelectorAll('[id^="process-btn-"]').forEach(btn => {
                btn.disabled = true;
                btn.style.opacity = '0.6';
                btn.style.cursor = 'not-allowed';
            });
        }
        
        // Re-enable all Process Document buttons
        function enableAllProcessButtons() {
            document.querySelectorAll('[id^="process-btn-"]').forEach(btn => {
                btn.disabled = false;
                btn.style.opacity = '1';
                btn.style.cursor = 'pointer';
            });
        }
        
        console.log('✅ processDocument() function loaded successfully with concurrency protection');
        
        function viewDocument(filePath, title) {
            const modal = new bootstrap.Modal(document.getElementById('documentViewerModal'));
            const img = document.getElementById('documentViewerImage');
            const pdf = document.getElementById('documentViewerPdf');
            const titleEl = document.getElementById('documentViewerTitle');
            
            titleEl.textContent = title;
            
            // Reset
            img.style.display = 'none';
            pdf.style.display = 'none';
            img.src = '';
            pdf.src = '';
            
            if (filePath.match(/\.(jpg|jpeg|png|gif)$/i)) {
                img.src = filePath;
                img.style.display = 'block';
            } else if (filePath.match(/\.pdf$/i)) {
                pdf.src = filePath;
                pdf.style.display = 'block';
            }
            
            modal.show();
        }
        
        function showUploadForm(typeCode) {
            document.getElementById('file-' + typeCode).click();
        }
        
        // Attach AJAX upload handlers to file inputs
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('input[type="file"][name="document_file"]').forEach(input => {
                input.addEventListener('change', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    if (this.files.length > 0) {
                        const typeCode = this.getAttribute('data-doc-type');
                        if (!typeCode) {
                            console.error('No data-doc-type attribute found on file input');
                            return;
                        }
                        console.log('File selected for document type:', typeCode);
                        handleFileUpload(typeCode, this.files[0]);
                        
                        // Reset file input to allow re-selecting same file
                        this.value = '';
                    }
                });
            });
            
            // Intercept cancel_preview form submissions and convert to AJAX
            document.querySelectorAll('form').forEach(form => {
                const cancelInput = form.querySelector('input[name="cancel_preview"]');
                const reuploadInput = form.querySelector('input[name="start_reupload"]');
                
                if (cancelInput) {
                    form.addEventListener('submit', async function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        
                        const docType = form.querySelector('input[name="document_type"]').value;
                        const button = form.querySelector('button[type="submit"]');
                        const originalHTML = button.innerHTML;
                        
                        button.disabled = true;
                        button.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Cancelling...';
                        
                        try {
                            const formData = new FormData();
                            formData.append('cancel_preview', '1');
                            formData.append('document_type', docType);
                            
                            const response = await fetch('upload_document.php', {
                                method: 'POST',
                                headers: {
                                    'X-Requested-With': 'XMLHttpRequest'
                                },
                                body: formData
                            });
                            
                            const contentType = response.headers.get('content-type');
                            if (!contentType || !contentType.includes('application/json')) {
                                const text = await response.text();
                                console.error('Cancel preview returned non-JSON:', text.substring(0, 500));
                                throw new Error('Server error: Expected JSON response');
                            }
                            
                            const data = await response.json();
                            
                            if (data.success) {
                                // Reload page to show upload form
                                window.location.href = 'upload_document.php';
                            } else {
                                alert('Cancel failed: ' + stripHtml(data.message));
                                button.disabled = false;
                                button.innerHTML = originalHTML;
                            }
                        } catch (error) {
                            console.error('Cancel error:', error);
                            alert('Cancel failed. Please try refreshing the page.');
                            button.disabled = false;
                            button.innerHTML = originalHTML;
                        }
                    });
                }
                
                if (reuploadInput) {
                    form.addEventListener('submit', async function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        
                        const docType = form.querySelector('input[name="document_type"]').value;
                        const button = form.querySelector('button[type="submit"]');
                        const originalHTML = button.innerHTML;
                        
                        button.disabled = true;
                        button.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Deleting...';
                        
                        try {
                            const formData = new FormData();
                            formData.append('start_reupload', '1');
                            formData.append('document_type', docType);
                            
                            const response = await fetch('upload_document.php', {
                                method: 'POST',
                                headers: {
                                    'X-Requested-With': 'XMLHttpRequest'
                                },
                                body: formData
                            });
                            
                            const contentType = response.headers.get('content-type');
                            if (!contentType || !contentType.includes('application/json')) {
                                const text = await response.text();
                                console.error('Reupload returned non-JSON:', text.substring(0, 500));
                                throw new Error('Server error: Expected JSON response');
                            }
                            
                            const data = await response.json();
                            
                            if (data.success) {
                                // Reload page to show upload form
                                window.location.href = 'upload_document.php';
                            } else {
                                alert('Delete failed: ' + stripHtml(data.message));
                                button.disabled = false;
                                button.innerHTML = originalHTML;
                            }
                        } catch (error) {
                            console.error('Reupload error:', error);
                            alert('Delete failed. Please try refreshing the page.');
                            button.disabled = false;
                            button.innerHTML = originalHTML;
                        }
                    });
                }
            });
        });
        
        // Drag and drop support
        document.querySelectorAll('.upload-zone').forEach(zone => {
            zone.addEventListener('dragover', (e) => {
                e.preventDefault();
                e.stopPropagation();
                zone.classList.add('dragover');
            });
            
            zone.addEventListener('dragleave', (e) => {
                e.preventDefault();
                e.stopPropagation();
                zone.classList.remove('dragover');
            });
            
            zone.addEventListener('drop', (e) => {
                e.preventDefault();
                e.stopPropagation();
                zone.classList.remove('dragover');
                
                const zoneId = zone.id.replace('upload-zone-', '');
                
                if (e.dataTransfer.files.length > 0) {
                    handleFileUpload(zoneId, e.dataTransfer.files[0]);
                }
            });
        });
        
        // Real-time status update checker
        let lastStatusCheck = '';
        let isCheckingStatus = false;
        
        // Show approval notification
        function showApprovalNotification() {
            // Create toast notification
            const toast = document.createElement('div');
            toast.className = 'position-fixed top-0 end-0 p-3';
            toast.style.zIndex = '9999';
            toast.innerHTML = `
                <div class="toast show align-items-center text-white bg-success border-0" role="alert">
                    <div class="d-flex">
                        <div class="toast-body">
                            <i class="bi bi-check-circle-fill me-2"></i>
                            <strong>Congratulations!</strong> Your application has been approved! 🎉
                        </div>
                        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                    </div>
                </div>
            `;
            document.body.appendChild(toast);
            
            // Play a subtle success sound if available
            try {
                const audio = new Audio('data:audio/wav;base64,UklGRnoGAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQoGAACBhYqFbF1fdJivrJBhNjVgodDbq2EcBj+a2/LDciUFLIHO8tiJNwgZaLvt559NEAxQp+PwtmMcBjiR1/LMeSwFJHfH8N2QQAoUXrTp66hVFApGn+DyvmwhBTGH0fPTgjMGHm7A7+OZUR0KR5vi8bllHAU2jdXzzH0pBSh+zPLaizsIGGS56+mjUBcLTKXh8bllHAY0idXz0H8qBSd+y/Lbiz4KGGi56eqiTxYKSp/h8bpmHQU3jdTz0H4rBSZ9zPPai0AKGWS46OmmUhgKTKPh8bllHAY0idTzz38rBSZ+zPPajEEKGGe56emnUxcLTKLh8bllHAU2jdTz0H8rBSd+y/PajEAKGWa56eqmUhcLTKPh8rplHAU2jdTzz34rBSd+y/PajEELGWW46OqmUhgLTKPh8rpkHAY3jdT00H8rBSZ9zPLajEEKGGa56eqmURgLTKLg8rplHQU3jdTzzn8rBSZ9y/PajD8KF2S56+mnUhcKS6Lg8rpkHAY3jdXy0H4rBSV9y/PajEELGGW46OqnUhcLTKPh8rpkHAY3jdTy0H8rBSZ+y/PajD8JGGa66OmnUhgLTKPh8rpkHAY3jdXyz34qBSZ9y/PajEEKGGW46OqnUxgLTKLh8rpkHAY3jdTy0H8rBSZ+y/PajEAKGGW46OqmUhcLTKPh8rpkHAY3jdTz0H8rBSZ9y/PajEELGWa56eqnUhcLTKLh8rpkHAY3jdTyz34qBSZ9y/PajD8KF2S56+mnUhgLTKPh8rpkHAY2jdTy0H8qBSZ9y/PajEEKGGa56OqnUhcLTKLh8rpkHAY3jdXyz38qBSZ9y/PajD8KF2W56+mnUhgLTKPh8rpkHAY3jdTy0H8qBSZ+y/PajEEKGGa56OqmUhcLTKLh8rpkHAY3jdXy0H8qBSZ+y/PajD8KF2W56+mnUhcKS6Lg8rplHAU2jdTzz34rBSZ9y/PajEEKGGa56OqmUhcKTKPh8rpkHAY3jdXy0H8qBSZ9y/PajD8JGGa56+mnUhcLTKPh8rpkHAY3jdTz0H8rBSZ+y/PajEEKGGa46OqmUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajEELGWa56OqnUhcLTKPh8rpkHAY3jdXy0H8qBSZ9y/PbjEEKGGW56eqnUxgLTKLh8rpkHAY3jdTy0H4rBSZ9y/PajEEKGGa56OqmUhcLTKLh8rpkHAY2jdTz0H8rBSZ+y/PajEEKGGa46OqmUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajEEKGGa56OqnUhcKTKLh8rpkHAY3jdXy0H4rBSZ9y/PajEEKGGa56OqmUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajEEKGGa56OqnUhcLTKLh8rpkHAY3jdXy0H4rBSZ9y/PajD8KF2S56+mmUhgLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajEEKGGa56OqmUhcLTKPh8rpkHAY3jdXy0H4rBSZ9y/PajD8KF2S56+mnUhcKTKPh8rpkHAY2jdTy0H8qBSZ9y/PajEEKGGa56OqmUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KF2W56+mnUhcLTKPh8rpkHAY3jdXy0H4rBSZ9y/PajD8KF2W56+mnUhcKS6Lg8rplHAU2jdTzz34rBSZ9y/PajEEKGGa56OqmUhcLTKPh8rpkHAY3jdXy0H4rBSZ9y/PajD8KF2W56+mnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajEEKGGa56OqnUhcLTKPh8rpkHAY3jdXy0H4rBSZ9y/PajD8KF2W56+mnUhgLTKPh8rpkHAY2jdTz0H8rBSZ9y/PajEEKGGa46OqmUhcKTKPh8rpkHAY3jdXy0H4rBSZ9y/PajD8KF2W56+mnUhgLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KF2W56+mnUhgLTKPh8rpkHAY3jdTy0H4rBSZ9y/PajEEKGGa56OqmUhcKTKLh8rpkHAY3jdXy0H4rBSZ9y/PajD8KF2W56+mnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajEEKGGa56OqmUhcLTKPh8rpkHAY3jdTy0H4rBSZ9y/PajD8KF2W56+mnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KF2W56+mnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KF2W56+mnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KGGa56OqnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KGGa56OqnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KGGa56OqnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KGGa56OqnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KGGa56OqnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KGGa56OqnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KGGa56OqnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KGGa56OqnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KGGa56OqnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KGGa56OqnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KGGa56OqnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KGGa56OqnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KGGa56OqnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KGGa56OqnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KGGa56OqnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KGGa56OqnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KGGa56OqnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KGGa56OqnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KGGa56OqnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KGGa56OqnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KGGa56OqnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KGGa56OqnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KGGa56OqnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KGGa56OqnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KGGa56OqnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KGGa56OqnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KGGa56OqnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KGGa56OqnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8=');
                audio.volume = 0.3;
                audio.play().catch(() => {}); // Ignore if audio fails
            } catch (e) {}
            
            // Auto-remove after 5 seconds
            setTimeout(() => {
                toast.remove();
            }, 5000);
        }
        
        async function checkDocumentStatus() {
            // Skip if already checking
            if (isCheckingStatus) return;
            
            isCheckingStatus = true;
            
            try {
                const response = await fetch(window.location.href, {
                    method: 'GET',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });
                
                const html = await response.text();
                
                // Only update if content has changed
                if (html !== lastStatusCheck) {
                    // Parse response to extract document cards
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');
                    const newDocumentCards = doc.querySelectorAll('.document-card');
                    const currentDocumentCards = document.querySelectorAll('.document-card');
                    
                    // Update each document card if content changed
                    newDocumentCards.forEach((newCard, index) => {
                        if (currentDocumentCards[index]) {
                            const currentCardHTML = currentDocumentCards[index].innerHTML;
                            const newCardHTML = newCard.innerHTML;
                            
                            if (currentCardHTML !== newCardHTML) {
                                // Smooth update with fade effect
                                currentDocumentCards[index].style.opacity = '0.5';
                                setTimeout(() => {
                                    currentDocumentCards[index].innerHTML = newCardHTML;
                                    currentDocumentCards[index].style.opacity = '1';
                                    
                                    // Add flash animation
                                    currentDocumentCards[index].classList.add('updated');
                                    setTimeout(() => {
                                        currentDocumentCards[index].classList.remove('updated');
                                    }, 1000);
                                }, 200);
                                
                                console.log('✅ Document card updated:', index);
                            }
                        }
                    });
                    
                    // Also check if banner status changed (applicant -> active)
                    const newBanner = doc.querySelector('.view-only-banner, .reupload-banner');
                    const currentBanner = document.querySelector('.view-only-banner, .reupload-banner');
                    
                    if (newBanner && currentBanner) {
                        const newBannerHTML = newBanner.outerHTML;
                        const currentBannerHTML = currentBanner.outerHTML;
                        
                        if (newBannerHTML !== currentBannerHTML) {
                            // Check if status changed to active (approved)
                            const wasApproved = newBanner.classList.contains('approved') && !currentBanner.classList.contains('approved');
                            
                            currentBanner.outerHTML = newBannerHTML;
                            console.log('🎉 Banner status updated');
                            
                            // Show celebration notification if approved
                            if (wasApproved) {
                                showApprovalNotification();
                            }
                        }
                    }
                    
                    lastStatusCheck = html;
                }
            } catch (error) {
                console.error('Status check failed:', error);
            } finally {
                isCheckingStatus = false;
            }
        }
        
        // Start real-time status checking for all students
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Real-time status updates enabled for all students');
            
            const indicator = document.getElementById('realtime-indicator');
            
            // Show the auto-update indicator
            if (indicator) {
                indicator.style.display = 'block';
            }
            
            // Check after 3 seconds on load
            setTimeout(checkDocumentStatus, 3000);
            // Then check every 3 seconds
            setInterval(checkDocumentStatus, 3000);
        });
    </script>
    
        <!-- Modal Stacking Fixes for Migrated Profile & Year Advancement -->
        <style>
            .modal { z-index: 205000 !important; }
            .modal-backdrop { z-index: 204999 !important; background-color: rgba(0,0,0,0.55); }
            /* Neutralize stacking-context creators while a modal is open */
            body.modal-open #wrapper,
            body.modal-open .home-section,
            body.modal-open .content-wrapper,
            body.modal-open .student-container,
            body.modal-open [data-stacking-context] {
                transform: none !important;
                filter: none !important;
                perspective: none !important;
                will-change: auto !important;
            }
        </style>

        <script>
            // Ensure all Bootstrap modals are portaled to body to escape local stacking contexts
            document.addEventListener('DOMContentLoaded', function() {
                function portalizeModals(root=document) {
                    root.querySelectorAll('.modal').forEach(function(modal) {
                        if (modal.dataset.portalized === '1') return;
                        modal.addEventListener('show.bs.modal', function() {
                            if (modal.parentElement !== document.body) {
                                document.body.appendChild(modal);
                            }
                        });
                        modal.dataset.portalized = '1';
                    });
                }

                // Initial pass
                portalizeModals();

                // Watch for dynamically-inserted modals
                const mo = new MutationObserver(function(muts) {
                    muts.forEach(function(m) {
                        m.addedNodes && m.addedNodes.forEach(function(node){
                            if (node.nodeType === 1) {
                                if (node.matches && node.matches('.modal')) {
                                    portalizeModals(node.parentElement || document);
                                } else if (node.querySelectorAll) {
                                    portalizeModals(node);
                                }
                            }
                        });
                    });
                });
                mo.observe(document.documentElement, { childList: true, subtree: true });
            });
        </script>

    <!-- Anti-FOUC Script -->
    <script>
        (function() {
            document.body.classList.add('ready');
            window.addEventListener('pageshow', function(event) {
                if (event.persisted) {
                    document.body.classList.add('ready');
                }
            });
        })();
    </script>
</body>
</html>