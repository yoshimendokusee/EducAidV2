<?php
// Suppress all output and errors for AJAX requests
if (isset($_POST['processIdPictureOcr']) || isset($_POST['processGradesOcr']) || 
    isset($_POST['processOcr']) || isset($_POST['processLetterOcr']) || isset($_POST['processCertificateOcr']) ||
    (isset($_POST['action']) && in_array($_POST['action'], ['check_full_duplicate', 'check_email_duplicate', 'check_mobile_duplicate', 'check_name_duplicate', 'check_household_lastname', 'check_household_duplicate', 'update_block_contact']))) {
    @ini_set('display_errors', '0');
    // DON'T disable error_reporting for household checks - we need error_log() to work
    // error_reporting(0);
    if (!ob_get_level()) ob_start();
}

// Move all AJAX processing to the very top to avoid headers already sent error
require_once __DIR__ . '/../../config/database.php';
// Include reCAPTCHA v3 configuration (site key + secret key constants)
require_once __DIR__ . '/../../config/recaptcha_config.php';
// Include FilePathConfig for centralized path management
require_once __DIR__ . '/../../config/FilePathConfig.php';
// Include AuditLogger for audit trail
require_once __DIR__ . '/../../services/AuditLogger.php';

// Make connection variable available in this scope
global $connection;

// Initialize audit logger
$auditLogger = new AuditLogger($connection);

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
}

// ============================================================
// BYPASS TOKEN VALIDATION & DATA PRE-FILL
// ============================================================
// Check if user is accessing via bypass token (admin override)
if (isset($_GET['bypass_token']) && !empty($_GET['bypass_token'])) {
    $bypassToken = trim($_GET['bypass_token']);
    
    // Validate the bypass token and check if student already registered
    $tokenQuery = "SELECT 
        hba.attempt_id,
        hba.attempted_first_name,
        hba.attempted_last_name,
        hba.attempted_email,
        hba.attempted_mobile,
        hba.mothers_maiden_name_entered,
        hba.barangay_entered,
        hba.bypass_token_used,
        hba.bypass_token_used_at,
        hba.bypass_token_expires_at,
        hba.admin_override,
        b.barangay_id,
        s.student_id as registered_student_id,
        s.first_name as registered_first_name,
        s.last_name as registered_last_name
    FROM household_block_attempts hba
    LEFT JOIN barangays b ON LOWER(b.name) = LOWER(hba.barangay_entered)
    LEFT JOIN students s ON LOWER(s.first_name) = LOWER(hba.attempted_first_name) 
        AND LOWER(s.last_name) = LOWER(hba.attempted_last_name)
        AND LOWER(s.email) = LOWER(hba.attempted_email)
    WHERE hba.bypass_token = $1
    LIMIT 1";
    
    $tokenResult = pg_query_params($connection, $tokenQuery, [$bypassToken]);
    
    if ($tokenResult && pg_num_rows($tokenResult) > 0) {
        $tokenData = pg_fetch_assoc($tokenResult);
        
        // Check if token is valid
        $tokenExpired = strtotime($tokenData['bypass_token_expires_at']) < time();
        $tokenUsed = $tokenData['bypass_token_used'] === 't';
        $tokenOverridden = $tokenData['admin_override'] === 't';
        $studentAlreadyRegistered = !empty($tokenData['registered_student_id']);
        
        if ($tokenUsed || $studentAlreadyRegistered) {
            // Token already used OR student already registered successfully
            $usedAtFormatted = $tokenData['bypass_token_used_at'] 
                ? date('F j, Y \a\t g:i A', strtotime($tokenData['bypass_token_used_at']))
                : 'Unknown';
            
            if ($studentAlreadyRegistered) {
                $_SESSION['bypass_error'] = 'Registration already completed! You have successfully registered as: ' 
                    . $tokenData['registered_first_name'] . ' ' . $tokenData['registered_last_name'] 
                    . ' (ID: ' . $tokenData['registered_student_id'] . '). '
                    . 'You can now login using your credentials. This bypass link can no longer be used.';
            } else {
                $_SESSION['bypass_error'] = 'This bypass link has already been used on ' . $usedAtFormatted 
                    . ' and cannot be used again. If you need assistance, please contact the administrator.';
            }
            
            error_log("⚠️ Bypass token reuse attempt - Token: " . substr($bypassToken, 0, 8) . "..., Used: " . ($tokenUsed ? 'Yes' : 'No') . ", Student Registered: " . ($studentAlreadyRegistered ? 'Yes (ID: ' . $tokenData['registered_student_id'] . ')' : 'No'));
            
            // Log audit trail: Attempted to reuse bypass token
            $auditLogger->logEvent(
                'household_bypass_reuse_attempt',
                'household_management',
                "Attempted to reuse bypass token for: {$tokenData['attempted_first_name']} {$tokenData['attempted_last_name']}",
                [
                    'user_id' => $tokenData['registered_student_id'],
                    'user_type' => 'student',
                    'username' => $tokenData['attempted_email'] ?? 'N/A',
                    'status' => 'warning',
                    'affected_table' => 'household_block_attempts',
                    'affected_record_id' => $tokenData['attempt_id'],
                    'metadata' => [
                        'attempt_id' => $tokenData['attempt_id'],
                        'student_name' => $tokenData['attempted_first_name'] . ' ' . $tokenData['attempted_last_name'],
                        'token_used' => $tokenUsed,
                        'student_already_registered' => $studentAlreadyRegistered,
                        'registered_student_id' => $tokenData['registered_student_id'],
                        'bypass_token' => substr($bypassToken, 0, 8) . '...'
                    ]
                ]
            );
            
            header("Location: student_register.php");
            exit;
        } elseif ($tokenExpired) {
            // Token expired
            $_SESSION['bypass_error'] = 'This bypass link has expired. Please contact the administrator for a new one.';
            header("Location: student_register.php");
            exit;
        } elseif (!$tokenOverridden) {
            // Token not approved
            $_SESSION['bypass_error'] = 'This bypass link is not valid.';
            header("Location: student_register.php");
            exit;
        } else {
            // Token is valid! Set bypass flag and pre-fill data
            $_SESSION['household_bypass_active'] = true;
            $_SESSION['household_bypass_token'] = $bypassToken;
            $_SESSION['household_bypass_attempt_id'] = $tokenData['attempt_id'];
            
            // Pre-fill student data from blocked attempt
            // Mark it as "fresh" so it only displays once
            $_SESSION['prefill_data'] = [
                'first_name' => $tokenData['attempted_first_name'],
                'last_name' => $tokenData['attempted_last_name'],
                'email' => $tokenData['attempted_email'],
                'mobile' => $tokenData['attempted_mobile'],
                'mothers_maiden_name' => $tokenData['mothers_maiden_name_entered'],
                'barangay_id' => $tokenData['barangay_id'],
                'barangay_name' => $tokenData['barangay_entered']
            ];
            $_SESSION['prefill_fresh'] = true; // Flag to indicate this is a fresh prefill
            
            $_SESSION['bypass_success'] = 'Your registration has been approved! You may now continue with your registration.';
            
            error_log("✅ Bypass token validated for: " . $tokenData['attempted_first_name'] . " " . $tokenData['attempted_last_name']);
            
            // Log audit trail: Student accessed bypass link
            $auditLogger->logEvent(
                'household_bypass_accessed',
                'household_management',
                "Student accessed bypass link: {$tokenData['attempted_first_name']} {$tokenData['attempted_last_name']}",
                [
                    'user_id' => null,
                    'user_type' => 'student',
                    'username' => $tokenData['attempted_email'] ?? 'N/A',
                    'status' => 'success',
                    'affected_table' => 'household_block_attempts',
                    'affected_record_id' => $tokenData['attempt_id'],
                    'metadata' => [
                        'attempt_id' => $tokenData['attempt_id'],
                        'student_name' => $tokenData['attempted_first_name'] . ' ' . $tokenData['attempted_last_name'],
                        'student_email' => $tokenData['attempted_email'],
                        'bypass_token' => substr($bypassToken, 0, 8) . '...' // Log partial token for security
                    ]
                ]
            );
            
            // Redirect to clean URL (remove token from address bar)
            header("Location: student_register.php");
            exit;
        }
    } else {
        // Token not found
        $_SESSION['bypass_error'] = 'Invalid bypass link. Please check the link or contact the administrator.';
        header("Location: student_register.php");
        exit;
    }
} else {
    // NO BYPASS TOKEN: This is normal registration access
    // Clear any existing bypass flags to prevent leakage from previous sessions
    // BUT ONLY FOR GET REQUESTS (actual page loads), NOT for AJAX POST requests
    // AND NOT if bypass was just activated (check for bypass_success message)
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && 
        isset($_SESSION['household_bypass_active']) && 
        !isset($_SESSION['bypass_success'])) {
        // Only clear if this is NOT the redirect after successful token validation
        unset($_SESSION['household_bypass_active']);
        unset($_SESSION['household_bypass_token']);
        unset($_SESSION['household_bypass_attempt_id']);
        error_log("🧹 Cleared old bypass flags for normal registration access (GET request)");
    }
}
// ============================================================

// Database connection is validated in database.php (will die if connection fails)
// The $connection variable is available globally after include

$municipality_id = $_SESSION['active_municipality_id'] ?? 1;
$municipality_logo = null;
$municipality_name = 'General Trias';

if (isset($connection)) {
    $muni_result = pg_query_params(
        $connection,
        "SELECT municipality_id, name, COALESCE(custom_logo_image, preset_logo_image) AS active_logo
         FROM municipalities
         WHERE municipality_id = $1
         LIMIT 1",
        [$municipality_id]
    );

    if ($muni_result && pg_num_rows($muni_result) > 0) {
        $muni_data = pg_fetch_assoc($muni_result);
        $municipality_id = (int)$muni_data['municipality_id'];
        $municipality_name = $muni_data['name'] ?: $municipality_name;
        
        // Store municipality name in session for OCR validation
        $_SESSION['active_municipality_name'] = $municipality_name;
        
        if (!empty($muni_data['active_logo'])) {
            $raw_logo = trim($muni_data['active_logo']);
            if (
                preg_match('#^data:image/[^;]+;base64,#i', $raw_logo) ||
                preg_match('#^(?:https?:)?//#i', $raw_logo)
            ) {
                $municipality_logo = $raw_logo;
            } else {
                $normalized = str_replace('\\', '/', $raw_logo);
                $normalized = preg_replace('#(?<!:)/{2,}#', '/', $normalized);

                if (strpos($normalized, '../') === 0) {
                    $municipality_logo = $normalized;
                } else {
                    $segments = array_map('rawurlencode', explode('/', ltrim($normalized, '/')));
                    $municipality_logo = '../../' . implode('/', $segments);
                }
            }
        }
        pg_free_result($muni_result);
    } elseif ($muni_result) {
        pg_free_result($muni_result);
    }
}

$_SESSION['active_municipality_id'] = $municipality_id;

// Check if this is an AJAX request (OCR, OTP processing, cleanup, or duplicate check)
// Define this early so it can be used in page load tracking
$isAjaxRequest = isset($_POST['sendOtp']) || isset($_POST['verifyOtp']) ||
                 isset($_POST['processOcr']) || isset($_POST['processIdPictureOcr']) || isset($_POST['processLetterOcr']) ||
                 isset($_POST['processCertificateOcr']) || isset($_POST['processGradesOcr']) ||
                 isset($_POST['cleanup_temp']) || isset($_POST['check_existing']) || isset($_POST['test_db']) ||
                 isset($_POST['check_school_student_id']) || isset($_POST['cleanup_session_files']) || isset($_POST['clear_registration_session']) || isset($_POST['cleanup_temp_files']) ||
                 (isset($_POST['action']) && in_array($_POST['action'], ['check_full_duplicate', 'check_email_duplicate', 'check_mobile_duplicate', 'check_name_duplicate', 'check_household_lastname', 'check_household_duplicate', 'update_block_contact']));

// ============================================================
// HELPER FUNCTIONS - DEFINED EARLY FOR PAGE LOAD CLEANUP
// ============================================================

// Helper function to cleanup old document files when reuploading
if (!function_exists('cleanup_old_document_files')) {
    /**
     * Delete old session-based temp files matching a pattern
     * @param string $uploadDir Directory to search
     * @param string $sessionPrefix Session-based file prefix
     * @param string $filePattern Pattern to match (e.g., '_EAF', '_idpic')
     * @return int Number of files deleted
     */
    function cleanup_old_document_files(string $uploadDir, string $sessionPrefix, string $filePattern): int {
        $deletedCount = 0;
        
        // Find all files matching the pattern
        $pattern = $uploadDir . DIRECTORY_SEPARATOR . $sessionPrefix . $filePattern . '*';
        $files = glob($pattern);
        
        if ($files === false) {
            return 0;
        }
        
        foreach ($files as $file) {
            if (is_file($file)) {
                if (@unlink($file)) {
                    $deletedCount++;
                    error_log("Deleted old file: " . basename($file));
                }
            }
        }
        
        return $deletedCount;
    }
}

// Helper to delete ALL session-based temp files for this registration session
if (!function_exists('cleanup_all_session_temp_files')) {
    /**
     * Delete ALL temporary files for the current registration session
     * Used when page is refreshed to prevent keeping outdated documents
     * @return int Number of files deleted
     */
    function cleanup_all_session_temp_files(): int {
        $deletedCount = 0;
        $sessionPrefix = $_SESSION['file_prefix'] ?? '';
        
        if (empty($sessionPrefix)) {
            error_log("⚠️ Cannot cleanup - no session prefix found");
            return 0;
        }
        
        // Initialize FilePathConfig
        require_once __DIR__ . '/../../config/FilePathConfig.php';
        $pathConfig = FilePathConfig::getInstance();
        
        // Define all temp directories
        $tempDirs = [
            'enrollment_forms' => $pathConfig->getTempPath('enrollment_forms'),
            'id_pictures' => $pathConfig->getTempPath('id_pictures'),
            'letter_to_mayor' => $pathConfig->getTempPath('letter_to_mayor'),
            'indigency' => $pathConfig->getTempPath('indigency'),
            'grades' => $pathConfig->getTempPath('grades')
        ];
        
        // Delete files in each directory
        foreach ($tempDirs as $dirName => $dirPath) {
            if (!is_dir($dirPath)) {
                continue;
            }
            
            // Find all files with this session prefix
            $pattern = $dirPath . DIRECTORY_SEPARATOR . $sessionPrefix . '*';
            $files = glob($pattern);
            
            if ($files === false) {
                continue;
            }
            
            foreach ($files as $file) {
                if (is_file($file)) {
                    if (@unlink($file)) {
                        $deletedCount++;
                        error_log("🗑️ Deleted: " . basename($file) . " from $dirName");
                    }
                }
            }
        }
        
        // Clear uploaded files tracking in session
        $_SESSION['uploaded_files'] = [
            'enrollment_form' => null,
            'id_picture' => null,
            'letter' => null,
            'certificate' => null,
            'grades' => null
        ];
        
        error_log("✅ Cleanup complete: Deleted $deletedCount temp files");
        return $deletedCount;
    }
}

// ============================================================

// Initialize registration session tracking
if (!isset($_SESSION['registration_session_id'])) {
    $_SESSION['registration_session_id'] = uniqid('reg_', true);
    $_SESSION['registration_start_time'] = time();
    $_SESSION['page_load_count'] = 0; // Track page loads
}

// Track page loads to detect refreshes
// Increment on every GET request (page load/refresh)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !$isAjaxRequest) {
    $_SESSION['page_load_count'] = ($_SESSION['page_load_count'] ?? 0) + 1;
    
    // If this is a refresh (2nd+ page load) and NOT from a POST redirect,
    // cleanup temp files from the current session
    if ($_SESSION['page_load_count'] > 1 && !isset($_SESSION['registration_completed'])) {
        // Set flag to trigger JavaScript cleanup on page load
        $_SESSION['trigger_cleanup_on_load'] = true;
        
        // Also perform immediate server-side cleanup
        error_log('🔄 Page refresh detected (load #' . $_SESSION['page_load_count'] . ') - cleaning temp files');
        $deletedCount = cleanup_all_session_temp_files();
        error_log('🗑️ Deleted ' . $deletedCount . ' temp files on refresh');
    }
}

// Create session-specific prefix for file naming (prevents conflicts)
if (!isset($_SESSION['file_prefix'])) {
    // Format: StudentName_SessionID_timestamp
    $firstName = $_POST['first_name'] ?? $_SESSION['temp_first_name'] ?? 'Student';
    $lastName = $_POST['last_name'] ?? $_SESSION['temp_last_name'] ?? uniqid();
    $_SESSION['file_prefix'] = $lastName . '_' . $firstName . '_' . substr($_SESSION['registration_session_id'], 4, 8);
    // Debug: log generated file prefix for this session
    error_log('REG DEBUG: Initialized file_prefix => ' . $_SESSION['file_prefix']);
}

// Track uploaded files for this session
if (!isset($_SESSION['uploaded_files'])) {
    $_SESSION['uploaded_files'] = [
        'enrollment_form' => null,
        'id_picture' => null,
        'letter' => null,
        'certificate' => null,
        'grades' => null
    ];
}

// --- AJAX: Cleanup temp files (called on page load or explicit cleanup) ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['cleanup_session_files'])) {
    while (ob_get_level() > 0) @ob_end_clean();
    header('Content-Type: application/json');
    
    try {
        $cleanupCount = 0;
        // Support both Railway volume and local paths
        $isRailway = file_exists('/mnt/assets/uploads/');
        $tempDirs = $isRailway ? [
            '/mnt/assets/uploads/temp/EAF/',
            '/mnt/assets/uploads/temp/ID/',
            '/mnt/assets/uploads/temp/Letter/',
            '/mnt/assets/uploads/temp/Indigency/',
            '/mnt/assets/uploads/temp/Grades/'
        ] : [
            __DIR__ . '/../../assets/uploads/temp/enrollment_forms/',
            __DIR__ . '/../../assets/uploads/temp/id_pictures/',
            __DIR__ . '/../../assets/uploads/temp/letter_to_mayor/',
            __DIR__ . '/../../assets/uploads/temp/indigency/',
            __DIR__ . '/../../assets/uploads/temp/grades/'
        ];
        
        // Get current session info
        $currentSessionPrefix = $_SESSION['file_prefix'] ?? '';
        $sessionStartTime = $_SESSION['registration_start_time'] ?? time();
        
        // IMPORTANT: Only delete files from ACTIVE session (current page refresh)
        // Do NOT delete files from completed registrations waiting for admin review!
        
        foreach ($tempDirs as $dir) {
            if (!is_dir($dir)) continue;
            
            $files = glob($dir . '*');
            foreach ($files as $file) {
                if (!is_file($file)) continue;
                
                $fileName = basename($file);
                $fileAge = time() - filemtime($file);
                
                // Only delete files from the CURRENT active session (user refreshed during registration)
                // Files from completed registrations should stay until admin approval/rejection
                // Orphaned files (older than 24 hours) will be cleaned by a separate maintenance task
                
                $isCurrentSessionFile = !empty($currentSessionPrefix) && 
                                       strpos($fileName, $currentSessionPrefix) !== false;
                
                // ONLY delete if it's from current active session AND session is less than 30 minutes old
                // This ensures we don't delete completed registration files waiting for admin review
                $isActiveSession = (time() - $sessionStartTime) < 1800;
                
                if ($isCurrentSessionFile && $isActiveSession) {
                    @unlink($file);
                    $cleanupCount++;
                }
            }
        }
        
        // Reset session upload tracking (user will re-upload)
        $_SESSION['uploaded_files'] = [
            'enrollment_form' => null,
            'id_picture' => null,
            'letter' => null,
            'certificate' => null,
            'grades' => null
        ];
        
        echo json_encode([
            'status' => 'success',
            'message' => 'Cleanup completed',
            'files_deleted' => $cleanupCount,
            'session_prefix' => $currentSessionPrefix
        ]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Cleanup failed: ' . $e->getMessage()]);
    }
    exit;
}

// --- AJAX: Clear registration session completely (Start Fresh button) ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['clear_registration_session'])) {
    while (ob_get_level() > 0) @ob_end_clean();
    header('Content-Type: application/json');
    
    try {
        // Clear all registration-related session variables
        unset($_SESSION['registration_session_id']);
        unset($_SESSION['registration_start_time']);
        unset($_SESSION['page_load_count']);
        unset($_SESSION['file_prefix']);
        unset($_SESSION['uploaded_files']);
        unset($_SESSION['temp_first_name']);
        unset($_SESSION['temp_last_name']);
        unset($_SESSION['otp_verified']);
        unset($_SESSION['otp']);
        unset($_SESSION['otp_email']);
        unset($_SESSION['otp_timestamp']);
        unset($_SESSION['household_bypass_active']);
        unset($_SESSION['household_bypass_token']);
        unset($_SESSION['household_bypass_attempt_id']);
        unset($_SESSION['prefill_data']);
        unset($_SESSION['trigger_cleanup_on_load']);
        unset($_SESSION['registration_completed']);
        
        echo json_encode([
            'status' => 'success',
            'message' => 'Registration session cleared successfully'
        ]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Failed to clear session: ' . $e->getMessage()]);
    }
    exit;
}

// --- AJAX: Track file upload (called after successful upload) ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['track_uploaded_file'])) {
    while (ob_get_level() > 0) @ob_end_clean();
    header('Content-Type: application/json');
    
    $fileType = $_POST['file_type'] ?? '';
    $fileName = $_POST['file_name'] ?? '';
    
    if (isset($_SESSION['uploaded_files'][$fileType])) {
        $_SESSION['uploaded_files'][$fileType] = $fileName;
        echo json_encode(['status' => 'success', 'message' => 'File tracked']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid file type']);
    }
    exit;
}

// --- AJAX: Check if session has uploaded files ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['check_uploaded_files'])) {
    while (ob_get_level() > 0) @ob_end_clean();
    header('Content-Type: application/json');
    
    $hasFiles = false;
    foreach ($_SESSION['uploaded_files'] as $file) {
        if ($file !== null) {
            $hasFiles = true;
            break;
        }
    }
    
    echo json_encode([
        'status' => 'success',
        'has_files' => $hasFiles,
        'uploaded_files' => $_SESSION['uploaded_files']
    ]);
    exit;
}

// Debug: Test if POST requests work at all
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['debug_test'])) {
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    header('Content-Type: application/json');
    echo json_encode(['status' => 'success', 'message' => 'POST debug successful', 'data' => $_POST]);
    exit;
}

// --- AJAX: Cleanup all temp files for current session (used when user confirms page refresh) ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['cleanup_temp_files'])) {
    // Ensure no stray output
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    header('Content-Type: application/json');

    try {
        $deleted = 0;
        if (function_exists('cleanup_all_session_temp_files')) {
            $deleted = cleanup_all_session_temp_files();
        }

        echo json_encode([
            'status' => 'success',
            'deleted_files' => $deleted
        ]);
    } catch (Throwable $e) {
        error_log('Temp cleanup error: ' . $e->getMessage());
        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to cleanup temporary files.'
        ]);
    }

    exit;
}

// --- HELPER FUNCTIONS ALREADY DEFINED AT TOP OF FILE ---
// The cleanup_old_document_files() and cleanup_all_session_temp_files() functions
// are now defined near the top of the file (after database connection)
// to ensure they're available for page load cleanup logic.

// --- MOVE OCR PROCESSING HERE BEFORE ANY HTML OUTPUT ---
// Document OCR Processing
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['processEnrollmentOcr'])) {
    // Clear any output buffers to prevent headers already sent error
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    
    // Suppress all errors/warnings to prevent HTML output in JSON response
    @ini_set('display_errors', '0');
    @error_reporting(0);
    
    // Set JSON header immediately
    header('Content-Type: application/json');
    
    // Load the new TSV-based OCR services
    require_once(__DIR__ . '/../../services/EnrollmentFormOCRService.php');
    
    if (!isset($_FILES['enrollment_form']) || $_FILES['enrollment_form']['error'] !== UPLOAD_ERR_OK) {
        $errorMsg = 'No file uploaded or upload error.';
        if (isset($_FILES['enrollment_form']['error'])) {
            $errorMsg .= ' Upload error code: ' . $_FILES['enrollment_form']['error'];
        }
        echo json_encode(['status' => 'error', 'message' => $errorMsg]);
        exit;
    }

    // Initialize FilePathConfig for path management
    $pathConfig = FilePathConfig::getInstance();
    $uploadDir = $pathConfig->getTempPath('enrollment_forms');
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    // DELETE OLD FILES: Remove previous upload and OCR results when new file is uploaded
    // This prevents bypassing validation by keeping old correct documents
    $sessionPrefix = $_SESSION['file_prefix'] ?? 'session';
    cleanup_old_document_files($uploadDir, $sessionPrefix, '_EAF');
    
    // Use session prefix for file naming to prevent conflicts
    $sessionPrefix = $_SESSION['file_prefix'] ?? 'session';
    
    $uploadedFile = $_FILES['enrollment_form'];
    $fileName = basename($uploadedFile['name']);
    $fileExtension = pathinfo($fileName, PATHINFO_EXTENSION);
    
    // Create session-based filename: sessionPrefix_EAF.extension
    $sessionFileName = $sessionPrefix . '_EAF.' . $fileExtension;
    $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $sessionFileName;

    // FILENAME VALIDATION DISABLED: Allow any filename format
    // Students can now upload files with any name - system will rename automatically
    /*
    // Validate filename format: Lastname_Firstname_EAF
    $formFirstName = trim($_POST['first_name'] ?? '');
    $formLastName = trim($_POST['last_name'] ?? '');
    $formMiddleName = trim($_POST['middle_name'] ?? '');

    if (empty($formFirstName) || empty($formLastName)) {
        echo json_encode(['status' => 'error', 'message' => 'First name and last name are required for filename validation.']);
        exit;
    }

    // Remove file extension and validate format
    $nameWithoutExt = pathinfo($fileName, PATHINFO_FILENAME);
    $expectedFormat = $formLastName . '_' . $formFirstName . '_EAF';

    if (strcasecmp($nameWithoutExt, $expectedFormat) !== 0) {
        echo json_encode([
            'status' => 'error', 
            'message' => "Filename must follow format: {$formLastName}_{$formFirstName}_EAF.{file_extension}"
        ]);
        exit;
    }
    */

    if (!move_uploaded_file($uploadedFile['tmp_name'], $targetPath)) {
        echo json_encode(['status' => 'error', 'message' => 'Failed to save uploaded file.']);
        exit;
    }

    // Get form data from POST (these were previously defined in commented validation section)
    $formFirstName = trim($_POST['first_name'] ?? '');
    $formLastName = trim($_POST['last_name'] ?? '');
    $formMiddleName = trim($_POST['middle_name'] ?? '');

    // Get form data for comparison
    $formData = [
        'first_name' => $formFirstName,
        'middle_name' => $formMiddleName,
        'last_name' => $formLastName,
        'extension_name' => trim($_POST['extension_name'] ?? ''),
        'university_id' => intval($_POST['university_id'] ?? 0),
        'year_level_id' => intval($_POST['year_level_id'] ?? 0)
    ];

    // Get university and year level names for comparison
    $universityName = '';
    $yearLevelName = '';

    if ($formData['university_id'] > 0) {
        $uniResult = pg_query_params($connection, "SELECT name FROM universities WHERE university_id = $1", [$formData['university_id']]);
        if ($uniRow = pg_fetch_assoc($uniResult)) {
            $universityName = $uniRow['name'];
        }
    }

    if ($formData['year_level_id'] > 0) {
        $yearResult = pg_query_params($connection, "SELECT name, code FROM year_levels WHERE year_level_id = $1", [$formData['year_level_id']]);
        if ($yearRow = pg_fetch_assoc($yearResult)) {
            $yearLevelName = $yearRow['name'];
        }
    }

    // Prepare student data for OCR service
    $studentData = [
        'first_name' => $formFirstName,
        'middle_name' => $formMiddleName,
        'last_name' => $formLastName,
        'university_name' => $universityName,
        'year_level' => $yearLevelName
    ];

    // Process enrollment form using NEW TSV-based OCR service
    try {
        $enrollmentOCR = new EnrollmentFormOCRService($connection);
        $ocrResult = $enrollmentOCR->processEnrollmentForm($targetPath, $studentData);

        if (!$ocrResult['success']) {
            echo json_encode([
                'status' => 'error',
                'message' => 'OCR processing failed: ' . ($ocrResult['error'] ?? 'Unknown error'),
                'suggestions' => [
                    '1. Make sure the image is clear and high resolution',
                    '2. Ensure good lighting when taking photos',
                    '3. Try straightening the document in the image',
                    '4. Use JPG or PNG format for best results'
                ]
            ]);
            exit;
        }

        $extracted = $ocrResult['data'];
        $overallConfidence = $ocrResult['overall_confidence'];
        $tsvQuality = $ocrResult['tsv_quality'];

        // Course extraction removed - students will input their course manually
        // No automatic course population to avoid conflicts with student input
        $courseData = null;
        $normalizedCourse = null;

        // Build verification response
        $verification = [
            // Name verification
            'first_name_match' => $extracted['student_name']['first_name_found'],
            'middle_name_match' => $extracted['student_name']['middle_name_found'],
            'last_name_match' => $extracted['student_name']['last_name_found'],
            
            // Year level verification
            'year_level_match' => $extracted['year_level']['found'],
            
            // University verification
            'university_match' => $extracted['university']['matched'],
            
            // Document type verification
            'document_keywords_found' => $extracted['document_type']['is_enrollment_form'],
            
            // Confidence scores
            'confidence_scores' => [
                'first_name' => $extracted['student_name']['first_name_similarity'] ?? 0,
                'middle_name' => $extracted['student_name']['middle_name_similarity'] ?? 0,
                'last_name' => $extracted['student_name']['last_name_similarity'] ?? 0,
                'year_level' => $extracted['year_level']['confidence'],
                'university' => $extracted['university']['confidence'],
                'document_keywords' => $extracted['document_type']['confidence']
                // Course confidence removed - students input course manually
            ],
            
            // Found text snippets
            'found_text_snippets' => [
                'year_level' => $extracted['year_level']['raw'] ?? 'Not found',
                'university' => $universityName,
                'document_type' => $extracted['document_type']['is_enrollment_form'] ? 'Enrollment Form' : 'Unknown',
                // Course removed - students input course manually
                'academic_year' => $extracted['academic_year']['raw'] ?? 'Not found',
                'student_id' => $extracted['student_id']['raw'] ?? 'Not found'
            ],
            
            // Course information removed - students input manually
            // 'course_data' => $courseData,
            
            // TSV quality metrics
            'tsv_quality' => $tsvQuality,
            
            // Overall results
            'overall_success' => $ocrResult['verification_passed'],
            
            'summary' => [
                'passed_checks' => ($extracted['student_name']['first_name_found'] ? 1 : 0) +
                                   ($extracted['student_name']['middle_name_found'] ? 1 : 0) +
                                   ($extracted['student_name']['last_name_found'] ? 1 : 0) +
                                   ($extracted['year_level']['found'] ? 1 : 0) +
                                   ($extracted['university']['matched'] ? 1 : 0) +
                                   ($extracted['document_type']['is_enrollment_form'] ? 1 : 0),
                'total_checks' => 6,
                'average_confidence' => $overallConfidence,
                'tsv_word_count' => $tsvQuality['total_words'],
                'tsv_avg_confidence' => $tsvQuality['avg_confidence'],
                'tsv_quality_score' => $tsvQuality['quality_score'],
                'recommendation' => $ocrResult['verification_passed'] ? 
                    'Document validation successful' : 
                    'Please ensure the document clearly shows your name, university, year level, and appears to be an official enrollment form'
            ]
        ];

        // Save OCR data for later use during registration (with session prefix to prevent conflicts)
        $sessionPrefix = $_SESSION['file_prefix'] ?? 'session';
        $confidenceFile = $uploadDir . DIRECTORY_SEPARATOR . $sessionPrefix . '_enrollment.confidence.json';
        $confidenceData = [
            'overall_confidence' => $overallConfidence,
            'detailed_scores' => $verification['confidence_scores'],
            // Course data removed - students input course manually
            'extracted_academic_year' => $extracted['academic_year']['raw'] ?? null,
            'extracted_student_id' => $extracted['student_id']['raw'] ?? null,
            'timestamp' => time()
        ];
        @file_put_contents($confidenceFile, json_encode($confidenceData));

        // Save full verification data directly to the target file path
        $verifyFile = $targetPath . '.verify.json';
        @file_put_contents($verifyFile, json_encode([
            'verification' => $verification,
            'extracted_data' => $extracted,
            'tsv_quality' => $tsvQuality
        ], JSON_PRETTY_PRINT));

        // Track uploaded file in session
        $_SESSION['uploaded_files']['enrollment_form'] = $sessionFileName;

        echo json_encode(['status' => 'success', 'verification' => $verification]);
        exit;
        
    } catch (Exception $e) {
        error_log("Enrollment OCR Exception: " . $e->getMessage());
        echo json_encode([
            'status' => 'error',
            'message' => 'An error occurred during OCR processing. Please try again.',
            'debug' => $e->getMessage()
        ]);
        exit;
    }
}

// Start output buffering to prevent any early HTML from leaking into JSON responses
if (ob_get_level() === 0) {
    ob_start();
}

// Small helper to emit clean JSON and terminate early
if (!function_exists('json_response')) {
    function json_response(array $payload, int $statusCode = 200): void {
        // Suppress errors to prevent HTML output
        @ini_set('display_errors', '0');
        @error_reporting(0);
        
        // Clear any previously buffered output (e.g., DOCTYPE/HTML) so JSON stays valid
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        http_response_code($statusCode);
        header_remove('X-Powered-By');
        header('Content-Type: application/json; charset=utf-8');
        
        // Encode with error handling
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        
        // Check for JSON encoding errors
        if ($json === false) {
            $error = json_last_error_msg();
            error_log("JSON encoding error: " . $error);
            // Fallback error response
            echo json_encode([
                'status' => 'error',
                'message' => 'Failed to encode response',
                'json_error' => $error
            ]);
        } else {
            echo $json;
        }
        
        flush();
        exit;
    }
}

// Lightweight reusable reCAPTCHA v3 verifier (avoid duplicating logic per branch)
if (!function_exists('verify_recaptcha_v3')) {
    /**
     * Verify a reCAPTCHA v3 token.
     * @return array{ok:bool,score:float,reason?:string}
     */
    function verify_recaptcha_v3(string $token = null, string $expectedAction = '', float $minScore = 0.5): array {
        // CHECK FOR OCR BYPASS MODE - Also bypass CAPTCHA verification
        if (file_exists(__DIR__ . '/../../config/ocr_bypass_config.php')) {
            require_once __DIR__ . '/../../config/ocr_bypass_config.php';
            if (defined('OCR_BYPASS_ENABLED') && OCR_BYPASS_ENABLED === true) {
                error_log("⚠️ CAPTCHA BYPASS ACTIVE - Auto-passing reCAPTCHA verification");
                return ['ok'=>true, 'score'=>0.9, 'reason'=>'bypass_mode'];
            }
        }
        
        if (!defined('RECAPTCHA_SECRET_KEY')) {
            return ['ok'=>false,'score'=>0.0,'reason'=>'missing_keys'];
        }
        $token = $token ?? '';
        if ($token === '') { return ['ok'=>false,'score'=>0.0,'reason'=>'missing_token']; }
        $payload = http_build_query([
            'secret' => RECAPTCHA_SECRET_KEY,
            'response' => $token,
            'remoteip' => $_SERVER['REMOTE_ADDR'] ?? ''
        ]);
        $ctx = stream_context_create(['http'=>[
            'method'=>'POST',
            'header'=>'Content-type: application/x-www-form-urlencoded',
            'content'=>$payload,
            'timeout'=>4
        ]]);
        $raw = @file_get_contents(RECAPTCHA_VERIFY_URL, false, $ctx);
        if ($raw === false) { 
            $result = ['ok'=>false,'score'=>0.0,'reason'=>'no_response'];
        } else {
            $json = @json_decode($raw, true);
            if (!is_array($json) || empty($json['success'])) { 
                $result = ['ok'=>false,'score'=>0.0,'reason'=>'api_fail'];
            } else {
                $score = (float)($json['score'] ?? 0);
                $action = $json['action'] ?? '';
                if ($expectedAction !== '' && $action !== $expectedAction) {
                    $result = ['ok'=>false,'score'=>$score,'reason'=>'action_mismatch'];
                } elseif ($score < $minScore) {
                    $result = ['ok'=>false,'score'=>$score,'reason'=>'low_score'];
                } else {
                    $result = ['ok'=>true,'score'=>$score];
                }
            }
        }
        // Append audit log entry (JSON lines) for observability
        try {
            if ($expectedAction !== '') {
                $logFile = __DIR__ . '/../../data/security_verifications.log';
                $entry = [
                    'ts' => date('c'),
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                    'ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? '',0,160),
                    'action' => $expectedAction,
                    'result' => $result['ok'] ? 'ok' : 'fail',
                    'score' => $result['score'] ?? 0,
                    'reason' => $result['ok'] ? null : ($result['reason'] ?? null),
                    'session' => substr(session_id(),0,16)
                ];
                @file_put_contents($logFile, json_encode($entry, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
            }
        } catch (Throwable $e) { /* ignore logging errors */ }
        return $result;
    }
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/../../phpmailer/vendor/autoload.php';

// SEO Configuration
require_once __DIR__ . '/../../includes/seo_helpers.php';
$seoData = getSEOData('register');
$pageTitle = $seoData['title'];
$pageDescription = $seoData['description'];
$pageKeywords = $seoData['keywords'];
$pageImage = 'https://www.educ-aid.site' . $seoData['image'];
$pageUrl = 'https://www.educ-aid.site/modules/student/student_register.php';
$pageType = $seoData['type'];

// Only output HTML for non-AJAX requests
if (!$isAjaxRequest) {
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . '/../../includes/seo_head.php'; ?>
    
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
    <link href="../../assets/css/bootstrap-icons.css" rel="stylesheet" />
    <link href="../../assets/css/universal.css" rel="stylesheet" />
    <link href="../../assets/css/bootstrap.min.css" rel="stylesheet" />
    <link href="../../assets/css/website/landing_page.css" rel="stylesheet" />
    <link rel="stylesheet" href="../../assets/css/registration.css" />
    <style>
        :root {
            --thm-primary: #0051f8;
            --thm-green: #18a54a;
        }

        body.registration-page {
            padding-top: var(--navbar-height);
            overflow-x: hidden;
            font-family: "Manrope", sans-serif;
        }

        body.registration-page nav.navbar.fixed-header {
            isolation: isolate;
            contain: layout style;
        }

        body.registration-page .navbar .btn-outline-primary {
            border: 2px solid var(--thm-primary) !important;
            color: var(--thm-primary) !important;
            background: #fff !important;
            font-weight: 500;
            padding: 0.5rem 1rem;
            border-radius: 0.375rem;
            transition: all 0.2s ease;
        }

        body.registration-page .navbar .btn-outline-primary:hover {
            background: var(--thm-primary) !important;
            color: #fff !important;
            border-color: var(--thm-primary) !important;
            transform: translateY(-1px);
        }

        body.registration-page .navbar .btn-primary {
            background: var(--thm-primary) !important;
            color: #fff !important;
            border: 2px solid var(--thm-primary) !important;
            font-weight: 500;
            padding: 0.5rem 1rem;
            border-radius: 0.375rem;
            transition: all 0.2s ease;
        }

        body.registration-page .navbar .btn-primary:hover {
            background: var(--thm-green) !important;
            border-color: var(--thm-green) !important;
            color: #fff !important;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(24, 165, 74, 0.3);
        }

        body.registration-page .navbar {
            font-family: "Manrope", sans-serif;
        }

        body.registration-page .navbar-brand {
            font-weight: 700;
        }

        body.registration-page .nav-link {
            font-weight: 500;
        }

        .step-panel.d-none { display: none !important; }
        
        /* Simple Step Progress Indicator */
        .step-progress-text {
            text-align: center;
            padding: 0.5rem 0 1rem 0;
            margin-bottom: 1rem;
            border-bottom: 2px solid #e9ecef;
        }
        
        .step-progress-text .step-counter {
            font-size: 0.9rem;
            color: #6c757d;
            font-weight: 500;
            letter-spacing: 0.5px;
            margin-bottom: 0.5rem;
        }
        
        .step-progress-text .step-counter .current-step {
            color: #007bff;
            font-weight: 700;
            font-size: 1.1rem;
        }
        
        .step-progress-text .step-title {
            font-size: 1.25rem;
            color: #212529;
            font-weight: 600;
            margin-top: 0.25rem;
        }
        
        .step-progress-text .step-title i {
            color: #007bff;
            margin-right: 0.5rem;
        }
        
        /* Progress bar */
        .step-progress-bar-simple {
            width: 100%;
            height: 4px;
            background: #e9ecef;
            border-radius: 2px;
            overflow: hidden;
            margin-top: 0.75rem;
        }
        
        .step-progress-bar-simple .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #007bff, #0056b3);
            transition: width 0.4s ease;
            border-radius: 2px;
        }
        .notifier {
            position: fixed; top: 12px; left: 50%; transform: translateX(-50%);
            max-width: 640px; width: calc(100% - 32px);
            padding: 14px 24px; background-color: #f8d7da; color: #721c24;
            border-radius: 8px; display: none; box-shadow: 0 6px 16px -4px rgba(0,0,0,0.25), 0 2px 6px rgba(0,0,0,0.18);
            z-index: 5000; /* Above nav/topbar and modals backdrop (Bootstrap modal backdrop is 1040, modal 1050) */
            font-weight: 500; letter-spacing: .25px; backdrop-filter: blur(6px);
            animation: notifierSlide .35s ease-out;
        }
        @keyframes notifierSlide { from { opacity: 0; transform: translate(-50%, -10px);} to { opacity: 1; transform: translate(-50%, 0);} }
        .notifier.success { background-color: #d4edda; color: #155724; }
        .notifier.error { background-color: #f8d7da; color: #721c24; }
        .notifier.warning { background-color: #fff3cd; color: #856404; }
        .notifier.success { background-color: #d4edda; color: #155724; }
        .verified-email { background-color: #e9f7e9; color: #28a745; }
        
        /* Enhanced required asterisk styling */
        .form-label .text-danger {
            font-weight: bold;
            font-size: 1.1em;
        }
        
        .form-label .text-muted {
            font-size: 0.85em;
            font-style: italic;
        }
        
        /* Name duplicate and household warnings */
        #nameDuplicateWarning {
            font-size: 0.9rem;
            padding: 0.75rem 1rem;
            border-left: 4px solid #dc3545;
        }
        
        #householdWarning {
            font-size: 0.9rem;
            padding: 0.75rem 1rem;
            border-left: 4px solid #ffc107;
        }
        
        #nameDuplicateWarning i,
        #householdWarning i {
            font-size: 1.1rem;
        }
        
        /* Tablet Responsive Optimization (768px - 991px) */
        @media (min-width: 768px) and (max-width: 991.98px) {
            body.registration-page {
                font-size: 1rem;
            }
            
            .step-progress-text .step-counter {
                font-size: 1rem;
            }
            
            .step-progress-text .step-title {
                font-size: 1.4rem;
            }
            
            .form-label {
                font-size: 1rem;
            }
            
            .form-control,
            .form-select {
                padding: 0.85rem;
                font-size: 1rem;
            }
            
            .btn {
                padding: 0.85rem 1.5rem;
                font-size: 1.05rem;
            }
            
            .card {
                padding: 2rem 1.75rem;
            }
            
            h2 {
                font-size: 1.75rem;
            }
            
            h3 {
                font-size: 1.35rem;
            }
            
            h4 {
                font-size: 1.2rem;
            }
            
            .container {
                max-width: 720px;
            }
            
            #nameDuplicateWarning,
            #householdWarning {
                font-size: 0.95rem;
                padding: 0.85rem 1.25rem;
            }
        }
        
        /* Mobile Responsive (below 768px) */
        @media (max-width: 767.98px) {
            .step-progress-text .step-title {
                font-size: 1.1rem;
            }
            
            .form-control,
            .form-select {
                font-size: 0.95rem;
            }
            
            .btn {
                font-size: 0.95rem;
            }
        }
        </style>
        <!-- reCAPTCHA v3 -->
        <script src="https://www.google.com/recaptcha/api.js?render=<?php echo RECAPTCHA_SITE_KEY; ?>" async defer></script>
        <script>window.RECAPTCHA_SITE_KEY = '<?php echo RECAPTCHA_SITE_KEY; ?>';</script>
        <script>
            // Acquire token as early as possible and refresh periodically (v3 tokens expire quickly)
            let recaptchaToken = '';
            function executeRecaptcha(){
                if (typeof grecaptcha === 'undefined' || !grecaptcha.execute) {
                    console.log('grecaptcha not ready yet, will retry...');
                    return;
                }
                grecaptcha.execute('<?php echo RECAPTCHA_SITE_KEY; ?>', {action: 'register'}).then(function(token){
                    recaptchaToken = token;
                    const hidden = document.getElementById('g-recaptcha-response');
                    if (hidden) hidden.value = token;
                }).catch(function(error) {
                    console.error('reCAPTCHA execution error:', error);
                });
            }
            
            // Wait for both DOM and grecaptcha to be ready
            function initRecaptcha() {
                if (typeof grecaptcha !== 'undefined' && grecaptcha.ready) {
                    grecaptcha.ready(function(){
                        executeRecaptcha();
                        // refresh token every 90 seconds
                        setInterval(executeRecaptcha, 90000);
                    });
                } else {
                    // Retry if grecaptcha not loaded yet
                    setTimeout(initRecaptcha, 100);
                }
            }
            
            window.addEventListener('DOMContentLoaded', initRecaptcha);
        </script>
        <script>
            // Warn user that refreshing will delete uploaded files and restart the process
            (function() {
                let refreshConfirmedOnce = false;

                function cleanupTempFilesBeforeReload(callback) {
                    try {
                        const xhr = new XMLHttpRequest();
                        xhr.open('POST', 'student_register.php', true);
                        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                        xhr.onreadystatechange = function() {
                            if (xhr.readyState === 4) {
                                // We don't block reload on errors; just continue
                                if (typeof callback === 'function') {
                                    callback();
                                }
                            }
                        };
                        xhr.send('cleanup_temp_files=1');
                    } catch (e) {
                        if (typeof callback === 'function') {
                            callback();
                        }
                    }
                }

                window.addEventListener('beforeunload', function(e) {
                    // Skip warning if we've already confirmed a refresh in this flow
                    if (refreshConfirmedOnce) {
                        return;
                    }

                    const message = 'Refreshing will delete all uploaded files and you will have to start all over again.';

                    // Modern browsers show their own message but require us to set returnValue
                    e.preventDefault();
                    e.returnValue = message;
                    return message;
                });

                // Intercept F5 / Ctrl+R and browser reload button via visibilitychange as best-effort
                window.addEventListener('keydown', function(e) {
                    if (e.key === 'F5' || (e.key === 'r' && (e.ctrlKey || e.metaKey))) {
                        e.preventDefault();
                        if (confirm('Refreshing will delete all uploaded files and you will have to start all over again. Do you want to continue?')) {
                            refreshConfirmedOnce = true;
                            cleanupTempFilesBeforeReload(function() {
                                window.location.reload();
                            });
                        }
                    }
                });
            })();
        </script>
        
        <!-- Navigation functions: moved from bottom so full implementations load BEFORE form HTML -->
        <script>
        console.log('🚀 Defining navigation functions early (single source of truth)...');
        // Ensure global step state exists
        if (typeof currentStep === 'undefined') { window.currentStep = 1; }

        // Helper: safe validation invocation (returns unified object)
        function runStepValidation() {
            if (typeof validateCurrentStepFields === 'function') {
                try {
                    const result = validateCurrentStepFields();
                    // Backward compatibility: old version returned boolean
                    if (typeof result === 'boolean') {
                        return { isValid: result, message: result ? '' : 'Please fill required fields.', fields: [] };
                    }
                    // New contract: object with isValid/message/fields
                    if (result && typeof result.isValid === 'boolean') {
                        return result;
                    }
                    return { isValid: false, message: 'Validation failed (unexpected result).', fields: [] };
                } catch (e) {
                    console.error('Validation threw error:', e);
                    return { isValid: false, message: 'Validation error. Check console.', fields: [] };
                }
            }
            // Fallback lightweight validation BEFORE full script finishes loading
            const panel = document.getElementById(`step-${currentStep}`);
            if (!panel) {
                return { isValid: false, message: 'Step container not ready yet.', fields: [] };
            }
            const required = panel.querySelectorAll('input[required], select[required], textarea[required]');
            const missing = [];
            required.forEach(el => {
                // Skip elements that are disabled or hidden (in case of transitional states)
                if (el.disabled || el.closest('.d-none')) return;
                const val = (el.type === 'checkbox') ? (el.checked ? 'checked' : '') : el.value.trim();
                if (!val) missing.push(el);
            });
            if (missing.length) {
                return { isValid: false, message: 'Please fill required fields.', fields: missing };
            }
            // Allow advance with basic check; detailed validation will apply from next step onwards once loaded
            console.log('⚠️ Using fallback validation (full validation not loaded yet).');
            return { isValid: true, message: '', fields: [] };
        }

        window.nextStep = async function() {
            console.log('🔧 nextStep invoked at step', currentStep);
            if (currentStep >= 10) return;

            // Step-specific async checks
            if (currentStep === 1 && typeof checkNameBeforeProceeding === 'function') {
                const dup = await checkNameBeforeProceeding();
                if (dup) return; // Duplicate name blocks progression
            }
            if (currentStep === 2 && typeof checkHouseholdBeforeProceeding === 'function') {
                const householdBlocked = await checkHouseholdBeforeProceeding();
                if (householdBlocked) return; // Household duplicate blocks progression
            }
            if (currentStep === 3 && typeof checkForDuplicateRegistration === 'function') {
                const regDup = await checkForDuplicateRegistration();
                if (regDup) return; // Duplicate registration blocks progression
            }

            // Core validation
            const validation = runStepValidation();
            if (!validation.isValid) {
                if (typeof showValidationError === 'function') {
                    showValidationError(validation.message, validation.fields);
                } else if (typeof showNotifier === 'function') {
                    showNotifier(validation.message, 'error');
                }
                console.warn('❌ Step validation failed. Preventing advance.', validation);
                return;
            }

            // Transition panels
            const currentPanel = document.getElementById(`step-${currentStep}`);
            if (currentPanel) currentPanel.classList.add('d-none');
            currentStep++;
            const nextPanel = document.getElementById(`step-${currentStep}`);
            if (nextPanel) nextPanel.classList.remove('d-none');

            if (typeof updateStepIndicators === 'function') updateStepIndicators();
            if (typeof clearValidationErrors === 'function') clearValidationErrors();
            console.log('✅ Advanced to step', currentStep);
        };

        window.prevStep = function() {
            console.log('🔧 prevStep invoked at step', currentStep);
            if (currentStep <= 1) return;
            const currentPanel = document.getElementById(`step-${currentStep}`);
            if (currentPanel) currentPanel.classList.add('d-none');
            currentStep--;
            const prevPanel = document.getElementById(`step-${currentStep}`);
            if (prevPanel) prevPanel.classList.remove('d-none');
            if (typeof updateStepIndicators === 'function') updateStepIndicators();
            console.log('✅ Returned to step', currentStep);
        };

        window.showStep = function(stepNum) {
            console.log('🔧 showStep called for', stepNum);
            document.querySelectorAll('.step-panel').forEach(p => p.classList.add('d-none'));
            const targetPanel = document.getElementById(`step-${stepNum}`);
            if (targetPanel) {
                targetPanel.classList.remove('d-none');
                currentStep = stepNum;
                if (typeof updateStepIndicators === 'function') updateStepIndicators();
                
                // ============================================================
                // OCR BYPASS MODE - Enable submit button on step 10
                // ============================================================
                <?php if (OCR_BYPASS_ENABLED): ?>
                if (stepNum === 10) {
                    const submitBtn = document.getElementById('submitButton');
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.classList.remove('btn-success');
                        submitBtn.classList.add('btn-warning');
                        submitBtn.innerHTML = '<i class="bi bi-exclamation-triangle me-2"></i>Submit (Bypass Mode)';
                        console.log('⚠️ BYPASS MODE: Submit button enabled on step 10');
                    }
                }
                <?php endif; ?>
                // ============================================================
            }
        };

        window.updateStepIndicators = function() {
            const totalSteps = 10;
            
            // Update step counter number
            const currentStepNumber = document.getElementById('currentStepNumber');
            if (currentStepNumber) {
                currentStepNumber.textContent = currentStep;
            }
            
            // Update all .current-step elements
            document.querySelectorAll('.current-step').forEach(el => {
                el.textContent = currentStep;
            });
            
            // Update textual step info labels (if present)
            document.querySelectorAll('.step-info').forEach(el => {
                el.textContent = `Step ${currentStep} of ${totalSteps}`;
            });

            // New style indicators (.step-indicator)
            document.querySelectorAll('.step-indicator').forEach((el, idx) => {
                const stepNum = idx + 1;
                el.classList.toggle('active', stepNum === currentStep);
                el.classList.toggle('completed', stepNum < currentStep);
            });

            // Legacy style indicators (.step)
            document.querySelectorAll('.step').forEach((el, idx) => {
                const stepNum = idx + 1;
                if (stepNum < currentStep) {
                    el.classList.add('completed');
                    el.classList.remove('active');
                } else if (stepNum === currentStep) {
                    el.classList.add('active');
                    el.classList.remove('completed');
                } else {
                    el.classList.remove('active', 'completed');
                }
            });

            // Progress bar fill
            const progressPercent = ((currentStep - 1) / (totalSteps - 1)) * 100;
            const progressFill = document.querySelector('.progress-fill');
            if (progressFill) progressFill.style.width = progressPercent + '%';
            console.log(`📊 Indicators updated ${currentStep}/${totalSteps}`);
        };

        console.log('✅ Navigation functions initialized (validation enforced)');
        </script>
</head>
<body class="registration-page has-header-offset">
    <?php include __DIR__ . '/../../includes/website/topbar.php'; ?>

    <?php 
    $custom_brand_config = [
        'href' => '../../website/landingpage.php',
        'name' => 'EducAid • ' . $municipality_name,
        'hide_educaid_logo' => true,
        'show_municipality' => true,
        'municipality_logo' => $municipality_logo,
        'municipality_name' => $municipality_name
    ];
    $custom_nav_links = [];
    $prepend_navbar_actions = [
        [
            'href' => '../../website/landingpage.php',
            'label' => 'Back to Home',
            'icon' => 'bi-house',
            'class' => 'btn btn-outline-secondary btn-sm'
        ]
    ];
    $simple_nav_style = true;
    include __DIR__ . '/../../includes/website/navbar.php'; 
    // Hidden input to store the reCAPTCHA v3 token for final submission (non-AJAX form posts)
    ?>
    <input type="hidden" id="g-recaptcha-response" name="g-recaptcha-response" form="finalRegistrationForm" />
    <?php
    ?>

<?php
} // End of HTML output for non-AJAX requests

// --- Check Full Duplicate (First Name + Last Name + University + School Student ID) ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action']) && $_POST['action'] === 'check_full_duplicate') {
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $universityId = intval($_POST['university_id'] ?? 0);
    $schoolStudentId = trim($_POST['school_student_id'] ?? '');
    
    error_log("Duplicate check - FirstName: $firstName, LastName: $lastName, UnivID: $universityId, SchoolID: $schoolStudentId");
    
    if (empty($firstName) || empty($lastName) || empty($schoolStudentId) || $universityId <= 0) {
        error_log("Duplicate check - Missing required fields");
        json_response(['is_duplicate' => false]);
    }
    
    try {
        // First check: Check school_student_ids table (approved students only)
        $query1 = "SELECT 
                    ssi.student_id,
                    ssi.first_name,
                    ssi.last_name,
                    ssi.school_student_id,
                    ssi.university_name,
                    ssi.status,
                    s.email,
                    s.status as student_status
                FROM school_student_ids ssi
                INNER JOIN students s ON ssi.student_id = s.student_id
                WHERE LOWER(ssi.first_name) = LOWER($1)
                  AND LOWER(ssi.last_name) = LOWER($2)
                  AND ssi.university_id = $3
                  AND ssi.school_student_id = $4
                  AND ssi.status = 'active'
                LIMIT 1";
        
        $result1 = pg_query_params($connection, $query1, [$firstName, $lastName, $universityId, $schoolStudentId]);
        
        if ($result1 && pg_num_rows($result1) > 0) {
            $duplicate = pg_fetch_assoc($result1);
            error_log("Duplicate found in school_student_ids: " . json_encode($duplicate));
            json_response([
                'is_duplicate' => true,
                'student_name' => trim($duplicate['first_name'] . ' ' . $duplicate['last_name']),
                'student_email' => $duplicate['email'] ?? '',
                'student_status' => ucfirst($duplicate['student_status'] ?? 'approved'),
                'university_name' => $duplicate['university_name'] ?? '',
                'school_student_id' => $duplicate['school_student_id']
            ]);
        }
        
        // Second check: Check students table directly (for pending registrations)
        $query2 = "SELECT 
                    s.student_id,
                    s.first_name,
                    s.last_name,
                    s.email,
                    s.status,
                    s.school_student_id,
                    u.name as university_name
                FROM students s
                LEFT JOIN universities u ON s.university_id = u.university_id
                WHERE LOWER(s.first_name) = LOWER($1)
                  AND LOWER(s.last_name) = LOWER($2)
                  AND s.university_id = $3
                  AND s.school_student_id = $4
                LIMIT 1";
        
        $result2 = pg_query_params($connection, $query2, [$firstName, $lastName, $universityId, $schoolStudentId]);
        
        if ($result2 && pg_num_rows($result2) > 0) {
            $duplicate = pg_fetch_assoc($result2);
            error_log("Duplicate found in students table: " . json_encode($duplicate));
            json_response([
                'is_duplicate' => true,
                'student_name' => trim($duplicate['first_name'] . ' ' . $duplicate['last_name']),
                'student_email' => $duplicate['email'] ?? '',
                'student_status' => ucfirst($duplicate['status']),
                'university_name' => $duplicate['university_name'] ?? '',
                'school_student_id' => $duplicate['school_student_id']
            ]);
        }
        
        error_log("No duplicate found");
        json_response(['is_duplicate' => false]);
        
    } catch (Exception $e) {
        error_log("Error checking full duplicate: " . $e->getMessage());
        json_response(['is_duplicate' => false]);
    }
}

// --- Check Email Duplicate ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action']) && $_POST['action'] === 'check_email_duplicate') {
    $email = trim($_POST['email'] ?? '');
    
    if (empty($email)) {
        json_response(['exists' => false]);
    }
    
    try {
        // Check if email is used by an admin
        $adminCheck = pg_query_params($connection, "SELECT 1 FROM admins WHERE email = $1", [$email]);
        if (pg_num_rows($adminCheck) > 0) {
            json_response(['exists' => true, 'message' => 'This email is already in use.']);
        }
        
        $query = "SELECT COUNT(*) as count FROM students WHERE email = $1 AND is_archived = FALSE";
        $result = pg_query_params($connection, $query, [$email]);
        
        if ($result) {
            $row = pg_fetch_assoc($result);
            json_response(['exists' => intval($row['count']) > 0]);
        } else {
            json_response(['exists' => false]);
        }
    } catch (Exception $e) {
        error_log("Error checking email duplicate: " . $e->getMessage());
        json_response(['exists' => false]);
    }
}

// --- Check Mobile Duplicate ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action']) && $_POST['action'] === 'check_mobile_duplicate') {
    $mobile = trim($_POST['mobile'] ?? '');
    
    if (empty($mobile)) {
        json_response(['exists' => false]);
    }
    
    try {
        $query = "SELECT COUNT(*) as count FROM students WHERE mobile = $1 AND is_archived = FALSE";
        $result = pg_query_params($connection, $query, [$mobile]);
        
        if ($result) {
            $row = pg_fetch_assoc($result);
            json_response(['exists' => intval($row['count']) > 0]);
        } else {
            json_response(['exists' => false]);
        }
    } catch (Exception $e) {
        error_log("Error checking mobile duplicate: " . $e->getMessage());
        json_response(['exists' => false]);
    }
}

// --- Check Name Duplicate (First + Last Name) ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action']) && $_POST['action'] === 'check_name_duplicate') {
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    
    if (empty($firstName) || empty($lastName)) {
        json_response(['exists' => false, 'count' => 0]);
    }
    
    try {
        $query = "SELECT COUNT(*) as count
                  FROM students 
                  WHERE LOWER(first_name) = LOWER($1) 
                    AND LOWER(last_name) = LOWER($2) 
                    AND is_archived = FALSE";
        $result = pg_query_params($connection, $query, [$firstName, $lastName]);
        
        if ($result) {
            $row = pg_fetch_assoc($result);
            $count = intval($row['count']);
            json_response([
                'exists' => $count > 0,
                'count' => $count,
                'message' => $count > 0 ? "A student with the name \"$firstName $lastName\" is already registered." : ''
            ]);
        } else {
            json_response(['exists' => false, 'count' => 0]);
        }
    } catch (Exception $e) {
        error_log("Error checking name duplicate: " . $e->getMessage());
        json_response(['exists' => false, 'count' => 0]);
    }
}

// --- Check Last Name Only (Household Warning) ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action']) && $_POST['action'] === 'check_household_lastname') {
    $lastName = trim($_POST['last_name'] ?? '');
    
    if (empty($lastName)) {
        json_response(['exists' => false, 'count' => 0]);
    }
    
    try {
        $query = "SELECT COUNT(*) as count
                  FROM students 
                  WHERE LOWER(last_name) = LOWER($1) 
                    AND is_archived = FALSE";
        $result = pg_query_params($connection, $query, [$lastName]);
        
        if ($result) {
            $row = pg_fetch_assoc($result);
            $count = intval($row['count']);
            json_response([
                'exists' => $count > 0,
                'count' => $count,
                'message' => $count > 0 ? "Found $count student(s) with last name \"$lastName\". Note: Only one member per household can receive assistance." : ''
            ]);
        } else {
            json_response(['exists' => false, 'count' => 0]);
        }
    } catch (Exception $e) {
        error_log("Error checking household lastname: " . $e->getMessage());
        json_response(['exists' => false, 'count' => 0]);
    }
}

// ============================================================
// NEW: Check Household Duplicate (Surname + Maiden Name + Barangay)
// ============================================================
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action']) && $_POST['action'] === 'check_household_duplicate') {
    // Check if bypass is active (admin approved override)
    if (isset($_SESSION['household_bypass_active']) && $_SESSION['household_bypass_active'] === true) {
        error_log("✅ BYPASS ACTIVE: Household check skipped - Admin override for attempt ID: " . ($_SESSION['household_bypass_attempt_id'] ?? 'unknown'));
        json_response(['status' => 'bypassed', 'message' => 'Household check bypassed (admin approved override)']);
    }
    
    $lastName = trim($_POST['last_name'] ?? '');
    $mothersMaidenName = trim($_POST['mothers_maiden_name'] ?? '');
    $barangayId = intval($_POST['barangay_id'] ?? 0);
    
    error_log("🏠 Household check - Last: $lastName, Maiden: $mothersMaidenName, Barangay ID: $barangayId");
    
    if (empty($lastName) || empty($mothersMaidenName) || $barangayId === 0) {
        json_response(['status' => 'success']); // Missing fields = allow (shouldn't happen with frontend validation)
    }
    
    try {
        // First, get the barangay name from ID
        $barangayQuery = "SELECT name FROM barangays WHERE barangay_id = $1";
        $barangayResult = pg_query_params($connection, $barangayQuery, [$barangayId]);
        
        if (!$barangayResult || pg_num_rows($barangayResult) === 0) {
            error_log("❌ Invalid barangay ID: $barangayId");
            json_response(['status' => 'success', 'error' => 'Invalid barangay']); // Allow on error
        }
        
        $barangayRow = pg_fetch_assoc($barangayResult);
        $barangayName = $barangayRow['name'];
        
        error_log("🔍 Checking in barangay: $barangayName");
        
        // Check for EXACT match first (hard block)
        $exactQuery = "SELECT student_id, first_name, last_name
                       FROM students 
                       WHERE LOWER(last_name) = LOWER($1) 
                         AND LOWER(mothers_maiden_name) = LOWER($2)
                         AND barangay_id = $3
                         AND is_archived = FALSE
                       LIMIT 1";
        
        $exactResult = pg_query_params($connection, $exactQuery, [$lastName, $mothersMaidenName, $barangayId]);
        
        if ($exactResult && pg_num_rows($exactResult) > 0) {
            $row = pg_fetch_assoc($exactResult);
            
            error_log("🚫 EXACT MATCH FOUND - BLOCKING: " . $row['student_id']);
            
            // Get attempted student's information from POST (email/mobile may not be available yet)
            $attemptedFirstName = trim($_POST['first_name'] ?? 'Unknown');
            $attemptedEmail = trim($_POST['email'] ?? '');
            $attemptedMobile = trim($_POST['mobile'] ?? '');
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            
            // Use NULL for empty email/mobile since they're on later steps
            $attemptedEmail = !empty($attemptedEmail) ? $attemptedEmail : null;
            $attemptedMobile = !empty($attemptedMobile) ? $attemptedMobile : null;
            
            // Log to household_block_attempts table with available data
            $logQuery = "INSERT INTO household_block_attempts 
                         (attempted_first_name, attempted_last_name, attempted_email, attempted_mobile,
                          mothers_maiden_name_entered, barangay_entered, blocked_by_student_id,
                          ip_address, user_agent, match_type, similarity_score, blocked_at)
                         VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, NOW())";
            
            pg_query_params($connection, $logQuery, [
                $attemptedFirstName,
                $lastName,
                $attemptedEmail,      // NULL if not provided yet
                $attemptedMobile,     // NULL if not provided yet
                $mothersMaidenName,
                $barangayName,
                $row['student_id'],   // The existing student who caused the block
                $ipAddress,
                $userAgent,
                'exact',              // match_type
                1.00                  // similarity_score (exact match = 100%)
            ]);
            
            // Get the attempt_id of the just-inserted record
            $attemptIdQuery = "SELECT currval(pg_get_serial_sequence('household_block_attempts', 'attempt_id')) as attempt_id";
            $attemptIdResult = pg_query($connection, $attemptIdQuery);
            $attemptId = pg_fetch_result($attemptIdResult, 0, 'attempt_id');
            
            json_response([
                'status' => 'blocked',
                'student_id' => $row['student_id'],
                'student_name' => $row['first_name'] . ' ' . $row['last_name'],
                'message' => 'A student from your household is already registered',
                'attempt_id' => $attemptId
            ]);
        }
        
        // Check for FUZZY match (typos) using pg_trgm similarity
        $fuzzyQuery = "SELECT student_id, first_name, last_name, mothers_maiden_name,
                              similarity(mothers_maiden_name, $2) as match_score
                       FROM students 
                       WHERE LOWER(last_name) = LOWER($1)
                         AND barangay_id = $3
                         AND similarity(mothers_maiden_name, $2) > 0.70
                         AND LOWER(mothers_maiden_name) != LOWER($2)
                         AND is_archived = FALSE
                       ORDER BY match_score DESC
                       LIMIT 1";
        
        $fuzzyResult = pg_query_params($connection, $fuzzyQuery, [$lastName, $mothersMaidenName, $barangayId]);
        
        if ($fuzzyResult && pg_num_rows($fuzzyResult) > 0) {
            $row = pg_fetch_assoc($fuzzyResult);
            $similarityPercentage = round(floatval($row['match_score']) * 100, 0);
            
            error_log("⚠️ FUZZY MATCH FOUND - WARNING: " . $row['student_id'] . " (Similarity: $similarityPercentage%)");
            
            json_response([
                'status' => 'warning',
                'student_id' => $row['student_id'],
                'student_name' => $row['first_name'] . ' ' . $row['last_name'],
                'similarity_percentage' => $similarityPercentage . '%',
                'message' => "Similar household found (Mother's maiden: {$row['mothers_maiden_name']})"
            ]);
        }
        
        // No match found - allow registration
        error_log("✅ No household match - clear to proceed");
        json_response(['status' => 'success']);
        
    } catch (Exception $e) {
        error_log("❌ Error checking household duplicate: " . $e->getMessage());
        json_response(['status' => 'success', 'error' => 'Database error']); // Fail-open on error
    }
}

// --- Update Blocked Attempt Contact Information ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action']) && $_POST['action'] === 'update_block_contact') {
    $attemptId = intval($_POST['attempt_id'] ?? 0);
    $email = trim($_POST['email'] ?? '');
    $mobile = trim($_POST['mobile'] ?? '');
    $reason = trim($_POST['reason'] ?? '');
    
    if ($attemptId === 0) {
        json_response(['success' => false, 'message' => 'Invalid attempt ID']);
    }
    
    // Convert empty strings to NULL
    $email = !empty($email) ? $email : null;
    $mobile = !empty($mobile) ? $mobile : null;
    $reason = !empty($reason) ? $reason : null;
    
    // Update the household_block_attempts record with contact information
    $updateQuery = "UPDATE household_block_attempts 
                    SET attempted_email = COALESCE($1, attempted_email),
                        attempted_mobile = COALESCE($2, attempted_mobile),
                        override_reason = COALESCE($3, override_reason)
                    WHERE attempt_id = $4";
    
    $result = pg_query_params($connection, $updateQuery, [
        $email,
        $mobile,
        $reason,
        $attemptId
    ]);
    
    if ($result) {
        error_log("✅ Updated contact info for attempt ID: $attemptId");
        json_response([
            'success' => true,
            'message' => 'Contact information updated successfully'
        ]);
    } else {
        error_log("❌ Failed to update contact info for attempt ID: $attemptId - " . pg_last_error($connection));
        json_response([
            'success' => false,
            'message' => 'Database error: ' . pg_last_error($connection)
        ]);
    }
}

// --- Check School Student ID Duplicate ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['check_school_student_id'])) {
    $schoolStudentId = trim($_POST['school_student_id'] ?? '');
    $universityId = intval($_POST['university_id'] ?? 0);
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $birthDate = trim($_POST['bdate'] ?? '');
    
    if (empty($schoolStudentId)) {
        json_response(['status' => 'error', 'message' => 'School student ID number required']);
    }
    
    if ($universityId <= 0) {
        json_response(['status' => 'error', 'message' => 'Please select your university first']);
    }
    
    try {
        // Use the database function to check for duplicates
        $query = "SELECT * FROM check_duplicate_school_student_id($1, $2)";
        $result = pg_query_params($connection, $query, [$universityId, $schoolStudentId]);
        
        if (!$result) {
            json_response(['status' => 'error', 'message' => 'Database error occurred']);
        }
        
        $checkResult = pg_fetch_assoc($result);
        
        if ($checkResult && $checkResult['is_duplicate'] === 't') {
            // School student ID already exists - perform additional identity checks
            $identityMatch = false;
            $matchDetails = [];
            
            // Check if name and birthdate match (same person trying to register again)
            if (!empty($firstName) && !empty($lastName) && !empty($birthDate)) {
                $nameCheckQuery = "SELECT bdate FROM students WHERE student_id = $1";
                $nameCheckResult = pg_query_params($connection, $nameCheckQuery, [$checkResult['system_student_id']]);
                
                if ($nameCheckRow = pg_fetch_assoc($nameCheckResult)) {
                    $bdateMatch = ($nameCheckRow['bdate'] === $birthDate);
                    
                    // Parse name from student_name field
                    $existingNameParts = explode(' ', $checkResult['student_name']);
                    $existingFirstName = $existingNameParts[0] ?? '';
                    $existingLastName = end($existingNameParts);
                    
                    $nameMatch = (
                        strcasecmp($existingFirstName, $firstName) === 0 &&
                        strcasecmp($existingLastName, $lastName) === 0
                    );
                    
                    if ($nameMatch && $bdateMatch) {
                        $identityMatch = true;
                        $matchDetails = [
                            'name_match' => true,
                            'bdate_match' => true,
                            'message' => 'This appears to be your existing account.'
                        ];
                    }
                }
            }
            
            json_response([
                'status' => 'duplicate',
                'message' => "This school student ID number is already registered in our system.",
                'details' => [
                    'system_student_id' => $checkResult['system_student_id'],
                    'name' => $checkResult['student_name'],
                    'status' => $checkResult['student_status'],
                    'email_hint' => substr($checkResult['student_email'], 0, 3) . '***@' . explode('@', $checkResult['student_email'])[1],
                    'mobile_hint' => substr($checkResult['student_mobile'], 0, 4) . '***' . substr($checkResult['student_mobile'], -2),
                    'registered_at' => $checkResult['registered_at'],
                    'identity_match' => $identityMatch,
                    'match_details' => $matchDetails,
                    'can_reapply' => in_array($checkResult['student_status'], ['rejected', 'disqualified'])
                ]
            ]);
        } else {
            json_response(['status' => 'available', 'message' => 'School student ID is available']);
        }
        
    } catch (Exception $e) {
        error_log("Check school student ID error: " . $e->getMessage());
        json_response(['status' => 'error', 'message' => 'Could not verify school student ID']);
    }
}

// --- Distribution Control & Slot check ---
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    // Initialize default values
    $slotsLeft = 0;
    $noSlotsAvailable = false;
    $slotsFull = false;
    
    // Debug: Log connection status
    error_log("SLOT CHECK: Connection status - isset: " . (isset($connection) ? 'true' : 'false') . ", value: " . ($connection ? 'valid' : 'invalid'));
    error_log("SLOT CHECK: Municipality ID: " . $municipality_id);
    
    // Check if there's an active slot for this municipality (only if connection is available)
    if (isset($connection) && $connection) {
        error_log("SLOT CHECK: Querying for active slots...");
        $slotRes = pg_query_params($connection, "SELECT * FROM signup_slots WHERE is_active = TRUE AND municipality_id = $1 ORDER BY created_at DESC LIMIT 1", [$municipality_id]);
        $slotInfo = pg_fetch_assoc($slotRes);
        
        error_log("SLOT CHECK: Slot info - " . ($slotInfo ? json_encode($slotInfo) : 'NULL'));
    
        if ($slotInfo) {
            // There is an active slot, check if it's full
            $countRes = pg_query_params($connection, "
                SELECT COUNT(*) AS total FROM students
                WHERE slot_id = $1
            ", [$slotInfo['slot_id']]);
            
            $countRow = pg_fetch_assoc($countRes);
            $currentCount = (int)$countRow['total'];
            $maxSlots = (int)$slotInfo['slot_count'];  // Fixed: Use 'slot_count' not 'max_slots'
            $slotsLeft = max(0, $maxSlots - $currentCount);
            
            error_log("SLOT CHECK: Current count: $currentCount, Max slots: $maxSlots, Slots left: $slotsLeft");
            
            if ($currentCount >= $maxSlots) {
                $slotsFull = true;
            }
        } else {
            $noSlotsAvailable = true;
        }
    } else {
        // No database connection - treat as no slots available
        $noSlotsAvailable = true;
    }
    
    if ($noSlotsAvailable || $slotsFull) {
        // Determine the appropriate message and styling
        if ($slotsFull) {
            $title = "EducAid – Registration Closed";
            $headerText = "Slots are full.";
            $messageText = "Please wait for the next announcement before registering again.";
            $iconColor = "text-danger";
        } else {
            $title = "EducAid – Registration Not Available";
            $headerText = "Registration is currently closed.";
            $messageText = "Please wait for the next opening of slots.";
            $iconColor = "text-warning";
        }
        
        echo <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8" />
            <meta name="viewport" content="width=device-width, initial-scale=1.0" />
            <title>$title</title>
            <link href="../../assets/css/bootstrap.min.css" rel="stylesheet" />
            <style>
                body {
                    background: linear-gradient(135deg, #1e3c72 0%, #2a5298 50%, #4b79a1 100%);
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    padding: 1.5rem;
                    color: #0b1c33;
                }
                .alert-container {
                    display: flex;
                    flex-direction: column;
                    align-items: center;
                    justify-content: center;
                    max-width: 520px;
                    width: 100%;
                    margin: 0 auto;
                    background: rgba(255, 255, 255, 0.95);
                    padding: 2.5rem 2rem;
                    border-radius: 1.5rem;
                    box-shadow: 0 20px 45px rgba(18, 38, 66, 0.35);
                    text-align: center;
                }
                .spinner {
                    width: 3rem;
                    height: 3rem;
                    margin-bottom: 1rem;
                    animation: spin 1s linear infinite;
                }
                @keyframes spin {
                    from { transform: rotate(0deg); }
                    to { transform: rotate(360deg); }
                }
            </style>
        </head>
        <body>
            <div class="container alert-container">
                <div class="text-center">
                    <svg class="spinner $iconColor" viewBox="0 0 100 100" preserveAspectRatio="xMidYMid">
                        <circle cx="50" cy="50" fill="none" stroke="currentColor" stroke-width="10" r="35" stroke-dasharray="164.93361431346415 56.97787143782138">
                            <animateTransform attributeName="transform" type="rotate" repeatCount="indefinite" dur="1s" values="0 50 50;360 50 50" keyTimes="0;1"/>
                        </circle>
                    </svg>
                    <h4 class="$iconColor">$headerText</h4>
                    <p>$messageText</p>
                    <a href="../../unified_login.php" class="btn btn-outline-primary mt-3">Back to Login</a>
                </div>
            </div>
        </body>
        </html>
        HTML;
        exit;
    }
}

// --- Check existing account ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['check_existing'])) {
    $email = isset($_POST['email']) ? filter_var($_POST['email'], FILTER_SANITIZE_EMAIL) : '';
    $mobile = isset($_POST['mobile']) ? preg_replace('/[^0-9+]/', '', $_POST['mobile']) : '';
    
    if (empty($email) && empty($mobile)) {
        json_response(['status' => 'error', 'message' => 'Email or mobile number required']);
    }
    
    try {
        // Check for existing accounts with same email or mobile
        $conditions = [];
        $params = [];
        $paramIndex = 1;
        
        if (!empty($email)) {
            $conditions[] = "email = $" . $paramIndex++;
            $params[] = $email;
        }
        
        if (!empty($mobile)) {
            $conditions[] = "mobile = $" . $paramIndex++;
            $params[] = $mobile;
        }
        
        if (empty($conditions)) {
            json_response(['status' => 'success', 'message' => 'No conflicts found']);
        }
        
        $query = "SELECT email, mobile, status, first_name, last_name 
                  FROM students 
                  WHERE " . implode(' OR ', $conditions) . " 
                  LIMIT 1";
        
        $result = pg_query_params($connection, $query, $params);
        
        if (!$result) {
            json_response(['status' => 'error', 'message' => 'Database error occurred']);
        }
        
        $existing = pg_fetch_assoc($result);
        
        if ($existing) {
            $conflictType = '';
            if (!empty($email) && $existing['email'] === $email) {
                $conflictType = 'email';
            } elseif (!empty($mobile) && $existing['mobile'] === $mobile) {
                $conflictType = 'phone number';
            }
            
            $canReapply = in_array($existing['status'], ['rejected', 'disqualified']);
            
            json_response([
                'status' => 'exists',
                'message' => "An account already exists with this {$conflictType}.",
                'type' => $conflictType,
                'account_status' => $existing['status'],
                'name' => $existing['first_name'] . ' ' . $existing['last_name'],
                'can_reapply' => $canReapply
            ]);
        } else {
            json_response(['status' => 'success', 'message' => 'No conflicts found']);
        }
        
    } catch (Exception $e) {
        error_log("Check existing account error: " . $e->getMessage());
        json_response(['status' => 'error', 'message' => 'Could not verify account status']);
    }
}

// --- OTP send ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['sendOtp'])) {
    // Temporarily bypass reCAPTCHA for testing - uncomment for production
    /*
    $captcha = verify_recaptcha_v3($_POST['g-recaptcha-response'] ?? '', 'send_otp');
    if (!$captcha['ok']) {
        json_response(['status'=>'error','message'=>'Security verification failed (captcha).']);
    }
    */
    $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_response(['status' => 'error', 'message' => 'Invalid email format.']);
    }

    $checkEmail = pg_query_params($connection, "SELECT 1 FROM students WHERE email = $1", [$email]);
    if (pg_num_rows($checkEmail) > 0) {
        json_response(['status' => 'error', 'message' => 'This email is already registered. Please use a different email or login.']);
    }

    $otp = rand(100000, 999999);

    $_SESSION['otp'] = $otp;
    $_SESSION['otp_email'] = $email;
    $_SESSION['otp_timestamp'] = time();
    $_SESSION['otp_verified'] = false; // ✅ Reset verification status when new OTP is sent
    // Immediately flush session changes so subsequent requests (e.g., OTP verify) can see them
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
        // Do not immediately reopen the session here to avoid re-locking.
        // This branch will respond via json_response after mailing, and does not need further session writes.
    }

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        // SMTP settings from environment
        $mail->Host       = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = getenv('SMTP_USERNAME') ?: 'example@email.test';
        $mail->Password   = getenv('SMTP_PASSWORD') ?: ''; // Ensure this is set in .env on production
        $encryption       = getenv('SMTP_ENCRYPTION') ?: 'tls';
        
        // Debug logging for SMTP configuration
        error_log("SMTP Configuration - Host: " . $mail->Host . ", Username: " . $mail->Username . ", Port: " . (getenv('SMTP_PORT') ?: 587));
        
        if (strtolower($encryption) === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }
        $mail->Port       = (int)(getenv('SMTP_PORT') ?: 587);
        
        // Enable SMTP debug output for troubleshooting
        $mail->SMTPDebug = 2; // Enable verbose debug output
        $mail->Debugoutput = function($str, $level) {
            error_log("PHPMailer Debug: $str");
        };

        $fromEmail = getenv('SMTP_FROM_EMAIL') ?: ($mail->Username ?: 'no-reply@educaid.local');
        $fromName  = getenv('SMTP_FROM_NAME')  ?: 'EducAid';
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($email);

    $mail->isHTML(true);
    $mail->Subject = 'Your EducAid OTP Code';
    $mail->Body    = "Your One-Time Password (OTP) for EducAid registration is: <strong>$otp</strong><br><br>This OTP is valid for 5 minutes.";
    $mail->AltBody = "Your One-Time Password (OTP) for EducAid registration is: $otp. This OTP is valid for 5 minutes.";

        $mail->send();
        json_response(['status' => 'success', 'message' => 'OTP sent to your email. Please check your inbox and spam folder.']);
    } catch (Exception $e) {
        error_log("PHPMailer Exception: " . $e->getMessage());
        error_log("PHPMailer Error Info: {$mail->ErrorInfo}");
        
        // Check if SMTP credentials are configured
        $smtpUsername = getenv('SMTP_USERNAME');
        $smtpPassword = getenv('SMTP_PASSWORD');
        
        if (empty($smtpUsername) || empty($smtpPassword) || $smtpUsername === 'example@email.test') {
            json_response([
                'status' => 'error', 
                'message' => 'Email service not configured. Please contact administrator.',
                'debug' => 'SMTP credentials not set up properly.'
            ]);
        } else {
            json_response([
                'status' => 'error', 
                'message' => 'Failed to send email. Please check your email address and try again.',
                'debug' => 'SMTP Error: ' . $e->getMessage()
            ]);
        }
    }
}

// --- OTP verify ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['verifyOtp'])) {
    // Temporarily bypass reCAPTCHA for testing - uncomment for production
    /*
    $captcha = verify_recaptcha_v3($_POST['g-recaptcha-response'] ?? '', 'verify_otp');
    if (!$captcha['ok']) {
        json_response(['status'=>'error','message'=>'Security verification failed (captcha).']);
    }
    */
    $enteredOtp = filter_var($_POST['otp'], FILTER_SANITIZE_NUMBER_INT);
    $email_for_otp = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);

    // Check if OTP is already verified
    if (isset($_SESSION['otp_verified']) && $_SESSION['otp_verified'] === true) {
        error_log("OTP already verified for this session. Session ID: " . session_id());
        json_response(['status' => 'success', 'message' => 'OTP already verified. You may proceed to the next step.']);
    }

    if (!isset($_SESSION['otp']) || !isset($_SESSION['otp_email']) || !isset($_SESSION['otp_timestamp'])) {
        error_log("OTP verification error: session data missing (otp, otp_email, otp_timestamp). Session ID: " . session_id());
        json_response(['status' => 'error', 'message' => 'No OTP sent or session expired. Please request a new OTP.']);
    }

    // Normalize email for robust comparison (trim + lowercase)
    $sessionEmail = strtolower(trim($_SESSION['otp_email']));
    $submittedEmail = strtolower(trim($email_for_otp));
    if ($sessionEmail !== $submittedEmail) {
        error_log("OTP email mismatch: session={$sessionEmail} submitted={$submittedEmail}");
        json_response(['status' => 'error', 'message' => 'Email mismatch for OTP. Please ensure you are verifying the correct email.']);
    }

    if ((time() - $_SESSION['otp_timestamp']) > 300) {
        unset($_SESSION['otp'], $_SESSION['otp_email'], $_SESSION['otp_timestamp']);
        json_response(['status' => 'error', 'message' => 'OTP has expired. Please request a new OTP.']);
    }

    if ((int)$enteredOtp === (int)$_SESSION['otp']) {
        $_SESSION['otp_verified'] = true;
        error_log("OTP verified successfully for email: " . $_SESSION['otp_email'] . ", session set to true");
        unset($_SESSION['otp'], $_SESSION['otp_email'], $_SESSION['otp_timestamp']);
        // Flush session updates before returning JSON to avoid stale reads from rapid follow-ups
        if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
        json_response(['status' => 'success', 'message' => 'OTP verified successfully!']);
    } else {
        $_SESSION['otp_verified'] = false;
        // Flush session state update to reduce risk of duplicate/conflicting attempts
        if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
        error_log("OTP verification failed - entered: " . $enteredOtp . ", expected: " . $_SESSION['otp']);
        json_response(['status' => 'error', 'message' => 'Invalid OTP. Please try again.']);
    }
}

// --- ID Picture OCR Processing ---
// --- ID Picture OCR Processing ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['processIdPictureOcr'])) {
    // Clear any output buffers to prevent headers already sent error
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    
    // Suppress all errors/warnings to prevent HTML output in JSON response
    @ini_set('display_errors', '0');
    @error_reporting(0);
    
    // Set JSON header immediately
    header('Content-Type: application/json');
    
    // Verify CAPTCHA (skip validation for development/testing)
    $captchaToken = $_POST['g-recaptcha-response'] ?? '';
    if ($captchaToken !== 'test') {
        $captcha = verify_recaptcha_v3($captchaToken, 'process_id_picture_ocr');
        if (!$captcha['ok']) { 
            echo json_encode([
                'status'=>'error',
                'message'=>'Security verification failed (captcha).',
                'debug' => ['captcha_token' => $captchaToken, 'captcha_result' => $captcha]
            ]);
            exit;
        }
    }
    // If token is 'test', skip CAPTCHA validation for development
    
    if (!isset($_FILES['id_picture']) || $_FILES['id_picture']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'message' => 'No ID picture uploaded or upload error.']);
        exit;
    }

    // Initialize FilePathConfig for path management
    $pathConfig = FilePathConfig::getInstance();
    $uploadDir = $pathConfig->getTempPath('id_pictures');
    if (!file_exists($uploadDir)) { mkdir($uploadDir, 0777, true); }

    // DELETE OLD FILES: Remove previous upload and OCR results when new file is uploaded
    $sessionPrefix = $_SESSION['file_prefix'] ?? 'session';
    cleanup_old_document_files($uploadDir, $sessionPrefix, '_idpic');
    
    // Session-based file naming to prevent conflicts
    $fileExt = strtolower(pathinfo($_FILES['id_picture']['name'], PATHINFO_EXTENSION));
    
    // REJECT PDF uploads - only accept image files
    if (!in_array($fileExt, ['jpg', 'jpeg', 'png'])) {
        echo json_encode([
            'status' => 'error', 
            'message' => 'Invalid file format. Only JPG and PNG images are accepted.',
            'suggestions' => [
                'Take a photo of your ID with your phone camera',
                'Convert your file to JPG or PNG format',
                'Ensure the image is clear and readable'
            ]
        ]);
        exit;
    }
    
    $fileName = $sessionPrefix . '_idpic.' . $fileExt;
    $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $fileName;

    if (!move_uploaded_file($_FILES['id_picture']['tmp_name'], $targetPath)) {
        echo json_encode(['status' => 'error', 'message' => 'Failed to save uploaded file.']);
        exit;
    }

    // Get form data for comparison
    $formData = [
        'first_name' => trim($_POST['first_name'] ?? ''),
        'middle_name' => trim($_POST['middle_name'] ?? ''),
        'last_name' => trim($_POST['last_name'] ?? ''),
        'extension_name' => trim($_POST['extension_name'] ?? ''),
        'university_id' => intval($_POST['university_id'] ?? 0)
    ];

    // Get university name
    $universityName = '';
    if ($formData['university_id'] > 0) {
        $uniResult = pg_query_params($connection, "SELECT name FROM universities WHERE university_id = $1", [$formData['university_id']]);
        if ($uniRow = pg_fetch_assoc($uniResult)) {
            $universityName = $uniRow['name'];
        }
    }

    // ========================================
    // IMAGE PREPROCESSING FOR BETTER OCR
    // ========================================
    function preprocessIdImage($inputPath, $outputPath) {
        // Check if ImageMagick is available (best option)
        // On Linux, ImageMagick commands are 'convert' not 'magick'
        $hasImageMagick = @shell_exec('convert -version 2>&1');
        
        if ($hasImageMagick && stripos($hasImageMagick, 'imagemagick') !== false) {
            // ImageMagick preprocessing - handles scratches, glare, distortion
            $cmd = "convert " . escapeshellarg($inputPath) . " " .
                   "-colorspace Gray " .                    // Convert to grayscale
                   "-contrast-stretch 2%x1% " .             // Enhance contrast
                   "-sharpen 0x1 " .                        // Sharpen text
                   "-threshold 50% " .                      // Binarize (black/white)
                   "-morphology Dilate Rectangle:1x1 " .   // Fill small gaps from scratches
                   "-despeckle " .                          // Remove noise/scratches
                   "-deskew 40% " .                         // Auto-straighten tilted images
                   "-trim +repage " .                       // Remove borders
                   escapeshellarg($outputPath) . " 2>&1";
            
            @shell_exec($cmd);
            return file_exists($outputPath);
            
        } else {
            // Fallback: PHP GD preprocessing (good, but less powerful)
            $img = null;
            $ext = strtolower(pathinfo($inputPath, PATHINFO_EXTENSION));
            
            if ($ext === 'jpg' || $ext === 'jpeg') {
                $img = @imagecreatefromjpeg($inputPath);
            } elseif ($ext === 'png') {
                $img = @imagecreatefrompng($inputPath);
            }
            
            if (!$img) return false;
            
            // Increase resolution if too small
            $width = imagesx($img);
            $height = imagesy($img);
            if ($width < 1200) {
                $scale = 1200 / $width;
                $newWidth = 1200;
                $newHeight = (int)($height * $scale);
                $resized = imagescale($img, $newWidth, $newHeight, IMG_BICUBIC);
                if ($resized) {
                    imagedestroy($img);
                    $img = $resized;
                }
            }
            
            // Convert to grayscale
            imagefilter($img, IMG_FILTER_GRAYSCALE);
            
            // Increase contrast (helps with faded text)
            imagefilter($img, IMG_FILTER_CONTRAST, -40);
            
            // Sharpen (helps with blurry images)
            imagefilter($img, IMG_FILTER_MEAN_REMOVAL);
            
            // Brighten slightly (helps with shadows)
            imagefilter($img, IMG_FILTER_BRIGHTNESS, 20);
            
            // Save preprocessed image
            imagejpeg($img, $outputPath, 95);
            imagedestroy($img);
            return true;
        }
    }

    // OCR Processing - Images only (PDF rejection handled earlier)
    // Enhanced Image OCR with preprocessing and better settings
    
    // STEP 1: Preprocess image to handle scratches, glare, and distortion
    $preprocessedPath = $targetPath . '.preprocessed.jpg';
    $usePreprocessed = preprocessIdImage($targetPath, $preprocessedPath);
    
    if ($usePreprocessed && file_exists($preprocessedPath)) {
        $ocrInputPath = $preprocessedPath;
        error_log("ID OCR: Using preprocessed image for better accuracy");
    } else {
        $ocrInputPath = $targetPath;
        error_log("ID OCR: Using original image (preprocessing unavailable)");
    }
    
    // STEP 2: Try multiple OCR engines and modes for best results
    $ocrResults = [];
    
    // Mode 1: LSTM only (--oem 1) - Good for natural text
    $cmd1 = "tesseract " . escapeshellarg($ocrInputPath) . " stdout --psm 6 --oem 1 -l eng 2>nul";
    $ocrResults['lstm_psm6'] = @shell_exec($cmd1);
    
    // Mode 2: Legacy only (--oem 0) - Better for cards/structured text
    $cmd2 = "tesseract " . escapeshellarg($ocrInputPath) . " stdout --psm 6 --oem 0 -l eng 2>nul";
    $ocrResults['legacy_psm6'] = @shell_exec($cmd2);
        
        // Mode 3: Combined LSTM + Legacy (--oem 2) - Best of both worlds
        $cmd3 = "tesseract " . escapeshellarg($ocrInputPath) . " stdout --psm 6 --oem 2 -l eng 2>nul";
        $ocrResults['combined_psm6'] = @shell_exec($cmd3);
        
        // Mode 4: Sparse text mode (--psm 11) - Good for scattered info on cards
        $cmd4 = "tesseract " . escapeshellarg($ocrInputPath) . " stdout --psm 11 --oem 2 -l eng 2>nul";
        $ocrResults['sparse_psm11'] = @shell_exec($cmd4);
        
        // Mode 5: Single block mode (--psm 7) - Good for ID numbers
        $cmd5 = "tesseract " . escapeshellarg($ocrInputPath) . " stdout --psm 7 --oem 2 -l eng 2>nul";
        $ocrResults['single_psm7'] = @shell_exec($cmd5);
        
        // Select best result (longest non-empty output usually has most text)
        $ocrText = '';
        $selectedMode = 'none';
        foreach ($ocrResults as $mode => $result) {
            if (strlen(trim($result)) > strlen(trim($ocrText))) {
                $ocrText = $result;
                $selectedMode = $mode;
            }
        }
        
        error_log("ID Picture OCR - LSTM PSM6 length: " . strlen(trim($ocrResults['lstm_psm6'])));
        error_log("ID Picture OCR - Legacy PSM6 length: " . strlen(trim($ocrResults['legacy_psm6'])));
        error_log("ID Picture OCR - Combined PSM6 length: " . strlen(trim($ocrResults['combined_psm6'])));
        error_log("ID Picture OCR - Sparse PSM11 length: " . strlen(trim($ocrResults['sparse_psm11'])));
        error_log("ID Picture OCR - Single PSM7 length: " . strlen(trim($ocrResults['single_psm7'])));
        error_log("ID Picture OCR - Selected mode: $selectedMode, length: " . strlen(trim($ocrText)));
        
        // STEP 3: Get confidence data using TSV output
        $tsvPath = str_replace('.jpg', '', $ocrInputPath);
        $tsvCmd = "tesseract " . escapeshellarg($ocrInputPath) . " " . 
                  escapeshellarg($tsvPath) . " --psm 6 --oem 2 -l eng tsv 2>nul";
        @shell_exec($tsvCmd);
        
        $tsvFile = $tsvPath . '.tsv';
        $lowConfidenceWords = [];
        
        if (file_exists($tsvFile)) {
            // Parse TSV for confidence scores
            $lines = file($tsvFile, FILE_IGNORE_NEW_LINES);
            if (count($lines) > 1) {
                array_shift($lines); // Remove header
                
                foreach ($lines as $line) {
                    $parts = explode("\t", $line);
                    if (count($parts) >= 12) {
                        $conf = floatval($parts[10]); // Confidence column
                        $text = trim($parts[11]);     // Text column
                        
                        if (!empty($text) && $conf < 60) {
                            $lowConfidenceWords[] = "$text ($conf%)";
                        }
                    }
                }
            }
            
            // Clean up TSV file
            @unlink($tsvFile);
        }
        
        if (!empty($lowConfidenceWords)) {
            error_log("ID OCR: Low confidence words (scratches/damage?): " . implode(', ', array_slice($lowConfidenceWords, 0, 10)));
        }
        
        // Clean up preprocessed image
        if ($usePreprocessed && file_exists($preprocessedPath)) {
            @unlink($preprocessedPath);
        }
        
        if (empty(trim($ocrText))) {
            echo json_encode([
                'status' => 'error', 
                'message' => 'OCR failed to extract text. Please try:<br>
                             • Take photo in better lighting (avoid shadows)<br>
                             • Place ID on dark, non-reflective surface<br>
                             • Ensure photo is straight and focused<br>
                             • Clean ID surface if possible',
                'suggestions' => [
                    'Use natural light or bright indoor lighting',
                    'Avoid glare from laminated surface',
                    'Take photo straight-on (not tilted)',
                    'Make sure text is sharp and in focus'
                ]
            ]);
            exit;
        }

    // Save OCR text
    file_put_contents($targetPath . '.ocr.txt', $ocrText);
    
    // Debug: Log OCR results
    error_log("ID Picture OCR Results:");
    error_log("Expected First Name: " . $formData['first_name']);
    error_log("Expected Last Name: " . $formData['last_name']);
    error_log("Expected University: " . $universityName);
    error_log("OCR Text (first 500 chars): " . substr($ocrText, 0, 500));

    $ocrTextLower = strtolower($ocrText);

    // ========================================
    // ENHANCED SIMILARITY WITH FUZZY MATCHING
    // ========================================
    // Handles common OCR misreads (O→0, I→1, etc.) for damaged/scratched IDs
    function calculateIDSimilarity($needle, $haystack) {
        $needle = strtolower(trim($needle));
        $haystack = strtolower($haystack);
        
        // Exact substring match gets 100%
        if (stripos($haystack, $needle) !== false) {
            return 100;
        }
        
        // Apply fuzzy transformations for common OCR errors
        $fuzzyNeedle = applyFuzzyTransforms($needle);
        foreach ($fuzzyNeedle as $variant) {
            if (stripos($haystack, $variant) !== false) {
                return 95; // Fuzzy match, slightly lower than exact
            }
        }
        
        // Word-based matching - split both into words and find best match
        $needleWords = preg_split('/\s+/', $needle);
        $haystackWords = preg_split('/\s+/', $haystack);
        
        $maxWordSimilarity = 0;
        foreach ($needleWords as $needleWord) {
            if (strlen($needleWord) < 2) continue;
            
            foreach ($haystackWords as $haystackWord) {
                if (strlen($haystackWord) < 2) continue;
                
                // Check exact word match
                if ($needleWord === $haystackWord) {
                    return 100;
                }
                
                // Check fuzzy word variants
                $fuzzyWords = applyFuzzyTransforms($needleWord);
                foreach ($fuzzyWords as $fuzzy) {
                    if ($fuzzy === $haystackWord) {
                        return 95;
                    }
                }
                
                // Calculate Levenshtein distance
                $lev = levenshtein($needleWord, $haystackWord);
                $maxLen = max(strlen($needleWord), strlen($haystackWord));
                $wordSim = (1 - ($lev / $maxLen)) * 100;
                
                $maxWordSimilarity = max($maxWordSimilarity, $wordSim);
            }
        }
        
        // Also calculate overall similar_text as fallback
        similar_text($needle, $haystack, $percent);
        
        // Return the higher of word-based or overall similarity
        return round(max($maxWordSimilarity, $percent), 2);
    }
    
    // Apply common OCR error transformations
    function applyFuzzyTransforms($text) {
        $variants = [$text];
        
        // Common OCR substitutions (especially for scratched/damaged text)
        $substitutions = [
            '0' => 'O', 'O' => '0',
            '1' => 'I', 'I' => '1', 'l' => '1', '1' => 'l',
            '5' => 'S', 'S' => '5',
            '8' => 'B', 'B' => '8',
            '2' => 'Z', 'Z' => '2',
            '6' => 'G', 'G' => '6',
            'rn' => 'm', 'm' => 'rn',
            'vv' => 'w', 'w' => 'vv',
            'cl' => 'd', 'd' => 'cl'
        ];
        
        // Generate variants by applying substitutions
        foreach ($substitutions as $from => $to) {
            if (stripos($text, $from) !== false) {
                $variants[] = str_ireplace($from, $to, $text);
            }
        }
        
        return array_unique($variants);
    }

    // Validation Checks (6 checks total)
    $checks = [];
    
    // 1. First Name Match (STRICTER: 60% threshold, down from 80%)
    $firstNameSimilarity = calculateIDSimilarity($formData['first_name'], $ocrText);
    $checks['first_name_match'] = [
        'passed' => $firstNameSimilarity >= 60,
        'similarity' => $firstNameSimilarity,
        'threshold' => 60,
        'expected' => $formData['first_name'],
        'found_in_ocr' => $firstNameSimilarity >= 60
    ];

    // 2. Middle Name Match (70% threshold, auto-pass if empty)
    if (empty($formData['middle_name'])) {
        $checks['middle_name_match'] = [
            'passed' => true,
            'auto_passed' => true,
            'reason' => 'No middle name provided'
        ];
    } else {
        $middleNameSimilarity = calculateIDSimilarity($formData['middle_name'], $ocrText);
        $checks['middle_name_match'] = [
            'passed' => $middleNameSimilarity >= 70,
            'similarity' => $middleNameSimilarity,
            'threshold' => 70,
            'expected' => $formData['middle_name'],
            'found_in_ocr' => $middleNameSimilarity >= 70
        ];
    }

    // 3. Last Name Match (STRICTER: 70% threshold, down from 80%)
    $lastNameSimilarity = calculateIDSimilarity($formData['last_name'], $ocrText);
    $checks['last_name_match'] = [
        'passed' => $lastNameSimilarity >= 70,
        'similarity' => $lastNameSimilarity,
        'threshold' => 70,
        'expected' => $formData['last_name'],
        'found_in_ocr' => $lastNameSimilarity >= 70
    ];

    // 4. University Match (60% threshold, word-based)
    $universityWords = explode(' ', strtolower($universityName));
    $matchedWords = 0;
    foreach ($universityWords as $word) {
        if (strlen($word) > 3 && stripos($ocrTextLower, $word) !== false) {
            $matchedWords++;
        }
    }
    $universitySimilarity = count($universityWords) > 0 ? ($matchedWords / count($universityWords)) * 100 : 0;
    $checks['university_match'] = [
        'passed' => $universitySimilarity >= 60,
        'similarity' => round($universitySimilarity, 2),
        'threshold' => 60,
        'expected' => $universityName,
        'matched_words' => $matchedWords . '/' . count($universityWords),
        'found_in_ocr' => $universitySimilarity >= 60
    ];

    // 5. Document Keywords (2+ required from keywords list)
    $documentKeywords = [
        'student', 'id', 'identification', 'card', 'number',
        'university', 'college', 'school', 'valid', 'until',
        'expires', 'issued'
    ];
    
    $keywordsFound = 0;
    $foundKeywords = [];
    foreach ($documentKeywords as $keyword) {
        if (stripos($ocrTextLower, $keyword) !== false) {
            $keywordsFound++;
            $foundKeywords[] = $keyword;
        }
    }
    
    $checks['document_keywords_found'] = [
        'passed' => $keywordsFound >= 2,
        'found_count' => $keywordsFound,
        'required_count' => 2,
        'total_keywords' => count($documentKeywords),
        'found_keywords' => $foundKeywords
    ];

    // 6. School Student ID Number Match (NEW)
    $schoolStudentId = trim($_POST['school_student_id'] ?? '');
    if (!empty($schoolStudentId)) {
        // Clean both the expected ID and OCR text for better matching
        $cleanSchoolId = preg_replace('/[^A-Z0-9]/i', '', $schoolStudentId);
        $cleanOcrText = preg_replace('/[^A-Z0-9]/i', '', $ocrText);
        
        // Check if school student ID appears in OCR text
        $idFound = stripos($cleanOcrText, $cleanSchoolId) !== false;
        
        // Also check with original formatting (dashes, spaces, etc.)
        if (!$idFound) {
            $idFound = stripos($ocrText, $schoolStudentId) !== false;
        }
        
        // Calculate similarity for partial matches
        $idSimilarity = 0;
        if (!$idFound) {
            // Extract potential ID numbers from OCR text
            preg_match_all('/\b[\d\-]+\b/', $ocrText, $matches);
            foreach ($matches[0] as $potentialId) {
                $cleanPotentialId = preg_replace('/[^A-Z0-9]/i', '', $potentialId);
                if (strlen($cleanPotentialId) >= 4) {
                    similar_text($cleanSchoolId, $cleanPotentialId, $percent);
                    $idSimilarity = max($idSimilarity, $percent);
                }
            }
        }
        
        $checks['school_student_id_match'] = [
            'passed' => $idFound || $idSimilarity >= 70,
            'similarity' => $idFound ? 100 : round($idSimilarity, 2),
            'threshold' => 70,
            'expected' => $schoolStudentId,
            'found_in_ocr' => $idFound,
            'note' => $idFound ? 'Exact match found' : ($idSimilarity >= 70 ? 'Partial match found' : 'Not found - please verify')
        ];
        
        error_log("School Student ID Check: " . ($idFound ? 'FOUND' : 'NOT FOUND') . " (Similarity: " . ($idFound ? 100 : round($idSimilarity, 2)) . "%)");
    } else {
        // If no school student ID provided, mark as auto-pass (backward compatibility)
        $checks['school_student_id_match'] = [
            'passed' => true,
            'auto_passed' => true,
            'reason' => 'No school student ID provided'
        ];
    }

    // Calculate overall results
    $passedChecks = array_filter($checks, function($check) {
        return $check['passed'];
    });
    $passedCount = count($passedChecks);
    
    // Simple average confidence from similarity scores
    $schoolIdSimilarity = isset($checks['school_student_id_match']['similarity']) ? $checks['school_student_id_match']['similarity'] : 100;
    $totalSimilarity = ($firstNameSimilarity + 
                       (isset($middleNameSimilarity) ? $middleNameSimilarity : 70) + 
                       $lastNameSimilarity + 
                       $universitySimilarity +
                       $schoolIdSimilarity) / 5;
    $avgConfidence = round($totalSimilarity, 2);

    // ========================================
    // IMPROVED VALIDATION LOGIC FOR DAMAGED IDs
    // ========================================
    // More lenient criteria since physical damage (scratches, fading) is common
    
    // Count critical field matches (name + ID)
    $criticalFieldsFound = 0;
    if ($checks['first_name_match']['passed']) $criticalFieldsFound++;
    if ($checks['last_name_match']['passed']) $criticalFieldsFound++;
    if (isset($checks['school_student_id_match']) && $checks['school_student_id_match']['passed']) {
        $criticalFieldsFound++;
    }
    
    // Determine overall success with multiple criteria
    $overallSuccess = (
        $passedCount >= 4 ||                              // Standard: 4+ checks pass
        ($passedCount >= 3 && $avgConfidence >= 70) ||    // Lenient: 3 checks + 70% confidence (was 80%)
        ($passedCount >= 2 && $avgConfidence >= 85) ||    // Strong partial: 2 checks with very high confidence
        ($criticalFieldsFound >= 3)                       // Critical fields: All 3 key fields found
    );
    
    // Generate recommendation message
    if ($overallSuccess) {
        $recommendation = 'Approve';
    } else {
        $missingFields = [];
        if (!$checks['first_name_match']['passed']) $missingFields[] = 'First Name';
        if (!$checks['last_name_match']['passed']) $missingFields[] = 'Last Name';
        if (!$checks['university_match']['passed']) $missingFields[] = 'University';
        
        $recommendation = 'Review - ' . (count($missingFields) > 0 ? 
            'Missing: ' . implode(', ', $missingFields) . '. ' : '') . 
            'Please verify information matches your student ID. If your ID is damaged/scratched, you may proceed but admin will verify manually.';
    }

    $verification = [
        'checks' => $checks,
        'summary' => [
            'passed_checks' => $passedCount,
            'total_checks' => 6,
            'critical_fields_found' => $criticalFieldsFound,
            'average_confidence' => $avgConfidence,
            'recommendation' => $recommendation,
            'damaged_id_note' => $passedCount < 4 ? 'If your ID has scratches or damage, the system may have difficulty reading it. Your application will be manually reviewed.' : ''
        ]
    ];

    // Save verification results
    file_put_contents($targetPath . '.verify.json', json_encode($verification, JSON_PRETTY_PRINT));

    // Also save a session-based confidence JSON for ID Picture
    // Mirrors other documents' confidence files and aligns to .confidence.json naming
    try {
        $sessionPrefix = $_SESSION['file_prefix'] ?? 'session';
        $confidenceFile = $uploadDir . DIRECTORY_SEPARATOR . $sessionPrefix . '_idpic.confidence.json';
        $confidenceData = [
            'overall_confidence' => $avgConfidence,
            'detailed_scores' => [
                'first_name' => isset($firstNameSimilarity) ? round($firstNameSimilarity, 2) : null,
                'middle_name' => isset($middleNameSimilarity) ? round($middleNameSimilarity, 2) : null,
                'last_name' => isset($lastNameSimilarity) ? round($lastNameSimilarity, 2) : null,
                'university' => isset($universitySimilarity) ? round($universitySimilarity, 2) : null,
                'school_student_id' => isset($checks['school_student_id_match']['similarity']) ? round($checks['school_student_id_match']['similarity'], 2) : null
            ],
            'timestamp' => time()
        ];
        @file_put_contents($confidenceFile, json_encode($confidenceData));
    } catch (\Throwable $e) {
        // Non-fatal; log and continue
        error_log('ID Picture confidence save error: ' . $e->getMessage());
    }

    // ========================================
    // OPTIONAL: Save OCR Debug Results (DISABLED for production to reduce file clutter)
    // To enable debug file generation, uncomment this section
    // ========================================
    /*
    $tempResultsDir = __DIR__ . '/../../assets/uploads/temp/id_pictures/';
    if (!is_dir($tempResultsDir)) {
        mkdir($tempResultsDir, 0755, true);
    }
    
    // Generate unique filename with session prefix to prevent conflicts
    $timestamp = date('Y-m-d_H-i-s');
    $sessionPrefix = $_SESSION['file_prefix'] ?? 'session';
    $resultFileBaseName = $sessionPrefix . '_idpic_' . $timestamp;
    
    // 1. Save raw OCR text
    $ocrTextFile = $tempResultsDir . $resultFileBaseName . '_ocr_text.txt';
    file_put_contents($ocrTextFile, $ocrText);
    
    // 2. Save verification results as JSON
    $verificationFile = $tempResultsDir . $resultFileBaseName . '_verification.json';
    $verificationData = [
        'timestamp' => $timestamp,
        'date_time' => date('F j, Y, g:i a'),
        'student_id' => $_POST['student_id'] ?? 'N/A',
        'school_student_id' => $schoolStudentId,
        'personal_info' => [
            'first_name' => $firstName,
            'middle_name' => $middleName ?? '',
            'last_name' => $lastName,
            'university' => $universityName
        ],
        'uploaded_file' => basename($targetPath),
        'ocr_extraction' => [
            'success' => true,
            'method' => 'tesseract_multi_mode',
            'text_length' => strlen($ocrText),
            'extracted_text' => $ocrText
        ],
        'validation_checks' => $checks,
        'confidence_scores' => [
            'first_name' => $firstNameSimilarity,
            'middle_name' => isset($middleNameSimilarity) ? $middleNameSimilarity : 0,
            'last_name' => $lastNameSimilarity,
            'university' => $universitySimilarity,
            'keywords' => count($foundKeywords),
            'school_student_id' => $schoolIdSimilarity,
            'average' => $avgConfidence
        ],
        'overall_result' => [
            'passed_checks' => $passedCount,
            'total_checks' => 6,
            'recommendation' => $recommendation,
            'status' => $overallSuccess ? 'APPROVED' : 'NEEDS REVIEW'
        ]
    ];
    
    file_put_contents($verificationFile, json_encode($verificationData, JSON_PRETTY_PRINT));
    
    // 3. Save a human-readable report
    $reportFile = $tempResultsDir . $resultFileBaseName . '_report.txt';
    $reportContent = "=====================================\n";
    $reportContent .= "ID PICTURE OCR VERIFICATION REPORT\n";
    $reportContent .= "=====================================\n\n";
    $reportContent .= "Generated: " . date('F j, Y, g:i a') . "\n";
    $reportContent .= "Student ID: " . ($_POST['student_id'] ?? 'N/A') . "\n";
    $reportContent .= "School Student ID: " . ($schoolStudentId ?: 'Not provided') . "\n";
    $reportContent .= "Uploaded File: " . basename($targetPath) . "\n\n";
    
    $reportContent .= "-------------------------------------\n";
    $reportContent .= "PERSONAL INFORMATION (From Form):\n";
    $reportContent .= "-------------------------------------\n\n";
    $reportContent .= "First Name:  " . $firstName . "\n";
    $reportContent .= "Middle Name: " . ($middleName ?? '') . "\n";
    $reportContent .= "Last Name:   " . $lastName . "\n";
    $reportContent .= "University:  " . $universityName . "\n\n";
    
    $reportContent .= "-------------------------------------\n";
    $reportContent .= "VALIDATION CHECKS:\n";
    $reportContent .= "-------------------------------------\n\n";
    
    foreach ($checks as $checkName => $checkData) {
        $status = $checkData['passed'] ? '✓ PASS' : '✗ FAIL';
        $confidence = '';
        if (isset($checkData['similarity'])) {
            $confidence = ' (' . round($checkData['similarity'], 1) . '%)';
        } elseif (isset($checkData['confidence'])) {
            $confidence = ' (' . round($checkData['confidence'], 1) . '%)';
        }
        $checkLabel = ucwords(str_replace('_', ' ', $checkName));
        $reportContent .= str_pad($checkLabel, 35) . ": " . $status . $confidence . "\n";
        
        if (isset($checkData['note'])) {
            $reportContent .= str_repeat(' ', 37) . "Note: " . $checkData['note'] . "\n";
        }
    }
    
    $reportContent .= "\n-------------------------------------\n";
    $reportContent .= "CONFIDENCE SCORES:\n";
    $reportContent .= "-------------------------------------\n\n";
    $reportContent .= "First Name:          " . round($firstNameSimilarity, 1) . "%\n";
    $reportContent .= "Middle Name:         " . (isset($middleNameSimilarity) ? round($middleNameSimilarity, 1) : 'N/A') . "%\n";
    $reportContent .= "Last Name:           " . round($lastNameSimilarity, 1) . "%\n";
    $reportContent .= "University:          " . round($universitySimilarity, 1) . "%\n";
    $reportContent .= "Keywords Found:      " . count($foundKeywords) . " / " . count($documentKeywords) . "\n";
    $reportContent .= "School Student ID:   " . round($schoolIdSimilarity, 1) . "%\n";
    $reportContent .= "-------------------------------------\n";
    $reportContent .= "AVERAGE:             " . round($avgConfidence, 1) . "%\n\n";
    
    $reportContent .= "-------------------------------------\n";
    $reportContent .= "OVERALL RESULT:\n";
    $reportContent .= "-------------------------------------\n\n";
    $reportContent .= "Passed Checks: " . $passedCount . " / 6\n";
    $reportContent .= "Status: " . ($overallSuccess ? '✓ APPROVED' : '! NEEDS REVIEW') . "\n";
    $reportContent .= "Recommendation: " . $recommendation . "\n\n";
    
    $reportContent .= "-------------------------------------\n";
    $reportContent .= "EXTRACTED OCR TEXT:\n";
    $reportContent .= "-------------------------------------\n\n";
    $reportContent .= $ocrText . "\n\n";
    
    $reportContent .= "=====================================\n";
    $reportContent .= "END OF REPORT\n";
    $reportContent .= "=====================================\n";
    
    file_put_contents($reportFile, $reportContent);
    
    // 4. Copy the uploaded image to results folder for reference
    $imageExt = pathinfo($targetPath, PATHINFO_EXTENSION);
    $imageResultFile = $tempResultsDir . $resultFileBaseName . '_image.' . $imageExt;
    copy($targetPath, $imageResultFile);
    
    // Log the save operation
    error_log("=== ID Picture OCR Results Saved to temp/id_pictures/ ===");
    error_log("  - OCR Text: " . basename($ocrTextFile));
    error_log("  - Verification JSON: " . basename($verificationFile));
    error_log("  - Human Report: " . basename($reportFile));
    error_log("  - Image Copy: " . basename($imageResultFile));
    */

    // Debug: Log verification results (always enabled for error logs)
    error_log("ID Picture Verification Results:");
    error_log("First Name Match: " . ($checks['first_name_match']['passed'] ? 'PASS' : 'FAIL') . 
              " (" . ($checks['first_name_match']['similarity'] ?? 'N/A') . "%)");
    error_log("Last Name Match: " . ($checks['last_name_match']['passed'] ? 'PASS' : 'FAIL') . 
              " (" . ($checks['last_name_match']['similarity'] ?? 'N/A') . "%)");
    error_log("University Match: " . ($checks['university_match']['passed'] ? 'PASS' : 'FAIL') . 
              " (" . ($checks['university_match']['similarity'] ?? 'N/A') . "%)");
    error_log("School Student ID Match: " . ($checks['school_student_id_match']['passed'] ? 'PASS' : 'FAIL') . 
              " (" . ($checks['school_student_id_match']['similarity'] ?? 'N/A') . "%)");
    error_log("Overall: " . $passedCount . "/6 checks passed - " . $recommendation);

    echo json_encode([
        'status' => 'success',
        'message' => 'ID Picture processed successfully',
        'verification' => $verification,
        'file_path' => $targetPath,
        'debug' => [
            'ocr_text_length' => strlen($ocrText),
            'ocr_preview' => substr($ocrText, 0, 200)
        ]
    ]);
    exit;
}

// --- Letter to Mayor OCR Processing ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['processLetterOcr'])) {
    // Suppress errors early (json_response also does this, but do it here too for require_once safety)
    @ini_set('display_errors', '0');
    @error_reporting(0);
    
    $captcha = verify_recaptcha_v3($_POST['g-recaptcha-response'] ?? '', 'process_letter_ocr');
    if (!$captcha['ok']) { 
        // Provide more detailed error message
        $reason = $captcha['reason'] ?? 'unknown';
        $score = $captcha['score'] ?? 0;
        json_response([
            'status'=>'error',
            'message'=>'Security verification failed (captcha).',
            'debug' => 'Reason: ' . $reason . ', Score: ' . $score
        ]); 
    }
    
    // CHECK FOR OCR BYPASS MODE - Skip Letter to Mayor validation
    if (file_exists(__DIR__ . '/../../config/ocr_bypass_config.php')) {
        require_once __DIR__ . '/../../config/ocr_bypass_config.php';
        if (defined('OCR_BYPASS_ENABLED') && OCR_BYPASS_ENABLED === true) {
            error_log("⚠️ LETTER TO MAYOR BYPASS ACTIVE - Auto-passing validation");
            
            if (!isset($_FILES['letter_to_mayor']) || $_FILES['letter_to_mayor']['error'] !== UPLOAD_ERR_OK) {
                json_response(['status' => 'error', 'message' => 'No letter file uploaded or upload error.']);
            }
            
            // Initialize FilePathConfig and save file
            $pathConfig = FilePathConfig::getInstance();
            $uploadDir = $pathConfig->getTempPath('letter_to_mayor');
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            $sessionPrefix = $_SESSION['file_prefix'] ?? 'session';
            cleanup_old_document_files($uploadDir, $sessionPrefix, '_Letter*');
            
            $fileExt = strtolower(pathinfo($_FILES['letter_to_mayor']['name'], PATHINFO_EXTENSION));
            $fileName = $sessionPrefix . '_Letter to mayor.' . $fileExt;
            $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $fileName;
            
            if (!move_uploaded_file($_FILES['letter_to_mayor']['tmp_name'], $targetPath)) {
                json_response(['status' => 'error', 'message' => 'Failed to save uploaded letter file.']);
            }
            
            // Create mock verification data
            $verification = [
                'first_name' => true,
                'last_name' => true,
                'barangay' => true,
                'mayor_header' => true,
                'municipality' => true,
                'confidence_scores' => [
                    'first_name' => OCR_BYPASS_CONFIDENCE,
                    'last_name' => OCR_BYPASS_CONFIDENCE,
                    'barangay' => OCR_BYPASS_CONFIDENCE,
                    'mayor_header' => OCR_BYPASS_CONFIDENCE,
                    'municipality' => OCR_BYPASS_CONFIDENCE
                ],
                'found_text_snippets' => [
                    'first_name' => 'Bypass Mode',
                    'last_name' => 'Bypass Mode',
                    'barangay' => 'Bypass Mode',
                    'mayor_header' => 'Bypass Mode',
                    'municipality' => 'Bypass Mode'
                ],
                'overall_success' => true,
                'summary' => [
                    'passed_checks' => 5,
                    'total_checks' => 5,
                    'average_confidence' => OCR_BYPASS_CONFIDENCE,
                    'recommendation' => 'Document validation successful (BYPASS MODE)',
                    'required_municipality' => $_SESSION['active_municipality_name'] ?? 'General Trias'
                ],
                'bypass_mode' => true,
                'bypass_reason' => OCR_BYPASS_REASON
            ];
            
            // Save mock confidence and verification files
            $confidenceFile = $uploadDir . DIRECTORY_SEPARATOR . $sessionPrefix . '_letter.confidence.json';
            $confidenceData = [
                'overall_confidence' => OCR_BYPASS_CONFIDENCE,
                'detailed_scores' => $verification['confidence_scores'],
                'timestamp' => time(),
                'bypass_mode' => true
            ];
            file_put_contents($confidenceFile, json_encode($confidenceData));
            
            $verifyFile = $targetPath . '.verify.json';
            @file_put_contents($verifyFile, json_encode($verification, JSON_PRETTY_PRINT));
            
            $_SESSION['uploaded_files']['letter'] = basename($targetPath);
            
            json_response(['status' => 'success', 'verification' => $verification]);
            exit;
        }
    }
    
    if (!isset($_FILES['letter_to_mayor']) || $_FILES['letter_to_mayor']['error'] !== UPLOAD_ERR_OK) {
        json_response(['status' => 'error', 'message' => 'No letter file uploaded or upload error.']);
    }

    // Initialize FilePathConfig for path management
    $pathConfig = FilePathConfig::getInstance();
    $uploadDir = $pathConfig->getTempPath('letter_to_mayor');
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    // DELETE OLD FILES: Remove previous upload and OCR results when new file is uploaded
    $sessionPrefix = $_SESSION['file_prefix'] ?? 'session';
    cleanup_old_document_files($uploadDir, $sessionPrefix, '_Letter*');
    
    // Use session-based file naming
    $fileExt = strtolower(pathinfo($_FILES['letter_to_mayor']['name'], PATHINFO_EXTENSION));
    
    // REJECT PDF uploads - only accept image files
    if (!in_array($fileExt, ['jpg', 'jpeg', 'png'])) {
        json_response([
            'status' => 'error', 
            'message' => 'Invalid file format. Only JPG and PNG images are accepted.',
            'suggestions' => [
                'Take a photo of your letter with your phone camera',
                'Convert your file to JPG or PNG format',
                'Ensure the image is clear and readable'
            ]
        ]);
    }
    
    $fileName = $sessionPrefix . '_Letter to mayor.' . $fileExt;
    $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $fileName;

    if (!move_uploaded_file($_FILES['letter_to_mayor']['tmp_name'], $targetPath)) {
        json_response(['status' => 'error', 'message' => 'Failed to save uploaded letter file.']);
    }

    // Get form data for comparison
    $formData = [
        'first_name' => trim($_POST['first_name'] ?? ''),
        'last_name' => trim($_POST['last_name'] ?? ''),
        'barangay_id' => intval($_POST['barangay_id'] ?? 0)
    ];

    // Get barangay name for comparison
    $barangayName = '';
    if ($formData['barangay_id'] > 0) {
        $barangayResult = pg_query_params($connection, "SELECT name FROM barangays WHERE barangay_id = $1", [$formData['barangay_id']]);
        if ($barangayRow = pg_fetch_assoc($barangayResult)) {
            $barangayName = $barangayRow['name'];
        }
    }

    // Perform OCR using Tesseract (PDF rejection handled earlier)
    $outputBase = $uploadDir . DIRECTORY_SEPARATOR . 'letter_ocr_' . pathinfo($fileName, PATHINFO_FILENAME);
    
    // Standard Tesseract processing for images
    $command = "tesseract " . escapeshellarg($targetPath) . " " . escapeshellarg($outputBase) . 
               " --oem 1 --psm 6 -l eng 2>&1";
    
    $tesseractOutput = shell_exec($command);
    $outputFile = $outputBase . ".txt";
    
    if (!file_exists($outputFile)) {
        json_response([
            'status' => 'error', 
            'message' => 'OCR processing failed. Please ensure the document is clear and readable.',
            'debug_info' => $tesseractOutput,
            'suggestions' => [
                'Make sure the image is clear and high resolution',
                'Ensure good lighting when taking photos',
                'Try straightening the document in the image',
                'Use JPG or PNG format for best results'
            ]
        ]);
    }
    
    $ocrText = file_get_contents($outputFile);
    
    // Clean up temporary OCR files
    if (file_exists($outputFile)) {
        unlink($outputFile);
    }
    
    if (empty(trim($ocrText))) {
        json_response([
            'status' => 'error', 
            'message' => 'No text could be extracted from the document. Please ensure the image is clear and contains readable text.'
        ]);
    }

    // Improved verification checks for letter to mayor with fuzzy matching
    $verification = [
        'first_name' => false,
        'last_name' => false,
        'barangay' => false,
        'mayor_header' => false,
        'municipality' => false,
        'confidence_scores' => [],
        'found_text_snippets' => []
    ];
    
    // Normalize OCR text for better matching
    $ocrTextNormalized = strtolower(preg_replace('/[^\w\s]/', ' ', $ocrText));
    $ocrWords = array_filter(explode(' ', $ocrTextNormalized));
    
    // Function to calculate similarity score
    function calculateSimilarity($needle, $haystack) {
        $needle = strtolower(trim($needle));
        $haystack = strtolower(trim($haystack));
        
        // Exact match
        if (stripos($haystack, $needle) !== false) {
            return 100;
        }
        
        // Check for partial matches with high similarity
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
    }
    
    // Check first name with improved matching (STRICTER: 60% threshold)
    if (!empty($formData['first_name'])) {
        $similarity = calculateSimilarity($formData['first_name'], $ocrTextNormalized);
        $verification['confidence_scores']['first_name'] = $similarity;
        
        if ($similarity >= 60) {
            $verification['first_name'] = true;
            // Find and store the matched text snippet
            $pattern = '/\b\w*' . preg_quote(substr($formData['first_name'], 0, 3), '/') . '\w*\b/i';
            if (preg_match($pattern, $ocrText, $matches)) {
                $verification['found_text_snippets']['first_name'] = (string)($matches[0] ?? '');
            }
        }
    }
    
    // Check last name with improved matching (STRICTER: 70% threshold)
    if (!empty($formData['last_name'])) {
        $similarity = calculateSimilarity($formData['last_name'], $ocrTextNormalized);
        $verification['confidence_scores']['last_name'] = $similarity;
        
        if ($similarity >= 70) {
            $verification['last_name'] = true;
            // Find and store the matched text snippet
            $pattern = '/\b\w*' . preg_quote(substr($formData['last_name'], 0, 3), '/') . '\w*\b/i';
            if (preg_match($pattern, $ocrText, $matches)) {
                $verification['found_text_snippets']['last_name'] = (string)($matches[0] ?? '');
            }
        }
    }
    
    // Check barangay name with improved matching
    if (!empty($barangayName)) {
        $similarity = calculateSimilarity($barangayName, $ocrTextNormalized);
        $verification['confidence_scores']['barangay'] = $similarity;
        
        if ($similarity >= 70) { // Slightly lower threshold for barangay names
            $verification['barangay'] = true;
            // Find and store the matched text snippet
            $pattern = '/\b\w*' . preg_quote(substr($barangayName, 0, 4), '/') . '\w*\b/i';
            if (preg_match($pattern, $ocrText, $matches)) {
                $verification['found_text_snippets']['barangay'] = (string)($matches[0] ?? '');
            }
        }
    }

    // Check for "Office of the Mayor" header with improved pattern matching
    $mayorHeaders = [
        'office of the mayor',
        'mayor\'s office', 
        'office mayor',
        'municipal mayor',
        'city mayor',
        'mayor office',
        'office of mayor',
        'municipal government',
        'city government',
        'local government unit',
        'lgu'
    ];
    
    $mayorHeaderFound = false;
    $mayorConfidence = 0;
    $foundMayorText = '';
    
    foreach ($mayorHeaders as $header) {
        $similarity = calculateSimilarity($header, $ocrTextNormalized);
        if ($similarity > $mayorConfidence) {
            $mayorConfidence = $similarity;
        }
        
        if ($similarity >= 70) {
            $mayorHeaderFound = true;
            // Try to find the actual text snippet
            $pattern = '/[^\n]*' . preg_quote(explode(' ', $header)[0], '/') . '[^\n]*/i';
            if (preg_match($pattern, $ocrText, $matches)) {
                $foundMayorText = trim($matches[0]);
            }
            break;
        }
    }
    
    $verification['mayor_header'] = $mayorHeaderFound;
    $verification['confidence_scores']['mayor_header'] = $mayorConfidence;
    if (!empty($foundMayorText)) {
        $verification['found_text_snippets']['mayor_header'] = $foundMayorText;
    }

    // Get active municipality name from session
    $activeMunicipality = $_SESSION['active_municipality_name'] ?? 'General Trias';
    
    // Check for municipality (dynamic based on session)
    $municipalityVariants = [
        strtolower($activeMunicipality),
        strtolower(str_replace(' ', '', $activeMunicipality)), // Remove spaces
        'municipality of ' . strtolower($activeMunicipality),
        'city of ' . strtolower($activeMunicipality),
        strtolower($activeMunicipality) . ' cavite'
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
        $similarity = calculateSimilarity($variant, $ocrTextNormalized);
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
    $verification['confidence_scores']['municipality'] = $municipalityConfidence;
    if (!empty($foundMunicipalityText)) {
        $verification['found_text_snippets']['municipality'] = $foundMunicipalityText;
    }

    // Calculate overall success with improved scoring
    $requiredLetterChecks = ['first_name', 'last_name', 'barangay', 'mayor_header', 'municipality'];
    $passedLetterChecks = 0;
    $totalConfidence = 0;
    
    foreach ($requiredLetterChecks as $check) {
        if ($verification[$check]) {
            $passedLetterChecks++;
        }
        // Add confidence score to total (default 0 if not set)
        $totalConfidence += isset($verification['confidence_scores'][$check]) ? 
            $verification['confidence_scores'][$check] : 0;
    }
    
    $averageConfidence = $totalConfidence / 5;
    
    // STRICTER SUCCESS CRITERIA: ALL 5 checks must pass
    // This prevents wrong documents (like indigency certificates) from being accepted
    $verification['overall_success'] = ($passedLetterChecks >= 5 && $averageConfidence >= 70);
    
    $verification['summary'] = [
        'passed_checks' => $passedLetterChecks,
        'total_checks' => 5,
        'average_confidence' => round($averageConfidence, 1),
        'recommendation' => $verification['overall_success'] ? 
            'Document validation successful' : 
            'This document must contain: your name, barangay, "Office of the Mayor" header, and "' . $activeMunicipality . '" municipality. Please upload the correct Letter to Mayor document.',
        'required_municipality' => $activeMunicipality
    ];
    
    // Include OCR text for debugging (truncated for security)
    $verification['ocr_text_preview'] = substr($ocrText, 0, 500) . (strlen($ocrText) > 500 ? '...' : '');
    
    // Save OCR confidence score to temp file for later use during registration
    // Use session-based filename to prevent conflicts between multiple users
    $sessionPrefix = $_SESSION['file_prefix'] ?? 'session';
    $confidenceFile = $uploadDir . DIRECTORY_SEPARATOR . $sessionPrefix . '_letter.confidence.json';
    $confidenceData = [
        'overall_confidence' => $averageConfidence,
        'detailed_scores' => $verification['confidence_scores'],
        'timestamp' => time()
    ];
    file_put_contents($confidenceFile, json_encode($confidenceData));
    
    // Save full verification data to .verify.json for admin validation view
    $verifyFile = $targetPath . '.verify.json';
    @file_put_contents($verifyFile, json_encode($verification, JSON_PRETTY_PRINT));
    
    // Save OCR text to .ocr.txt for reference
    $ocrFile = $targetPath . '.ocr.txt';
    @file_put_contents($ocrFile, $ocrText);
    
    // Track uploaded file
    $_SESSION['uploaded_files']['letter'] = basename($targetPath);
    
    // CROSS-DOCUMENT DETECTION: Check if user uploaded wrong document type
    // This prevents uploading Certificate, Grades, or other documents as Letter to Mayor
    $crossDocumentCheck = performCrossDocumentCheck($ocrText, '02'); // '02' = Letter to Mayor
    
    if ($crossDocumentCheck['wrong_document_detected']) {
        $verification['cross_document_warning'] = [
            'detected' => true,
            'expected_type' => 'Letter to Mayor',
            'detected_type' => $crossDocumentCheck['detected_type'],
            'message' => $crossDocumentCheck['message'],
            'confidence' => $crossDocumentCheck['confidence']
        ];
        
        // Override overall_success if wrong document detected
        $verification['overall_success'] = false;
        $verification['summary']['recommendation'] = $crossDocumentCheck['message'];
        
        error_log("CROSS-DOCUMENT WARNING: User uploaded " . $crossDocumentCheck['detected_type'] . " instead of Letter to Mayor");
    }
    
    // Note: Letter file is kept in temp directory for final registration step
    // It will be cleaned up during registration completion
    
    json_response(['status' => 'success', 'verification' => $verification]);
}

// --- Certificate of Indigency OCR Processing ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['processCertificateOcr'])) {
    // Suppress errors early
    @ini_set('display_errors', '0');
    @error_reporting(0);
    
    $captcha = verify_recaptcha_v3($_POST['g-recaptcha-response'] ?? '', 'process_certificate_ocr');
    if (!$captcha['ok']) { json_response(['status'=>'error','message'=>'Security verification failed (captcha).']); }
    
    // CHECK FOR OCR BYPASS MODE - Skip Certificate of Indigency validation
    if (file_exists(__DIR__ . '/../../config/ocr_bypass_config.php')) {
        require_once __DIR__ . '/../../config/ocr_bypass_config.php';
        if (defined('OCR_BYPASS_ENABLED') && OCR_BYPASS_ENABLED === true) {
            error_log("⚠️ CERTIFICATE OF INDIGENCY BYPASS ACTIVE - Auto-passing validation");
            
            if (!isset($_FILES['certificate_of_indigency']) || $_FILES['certificate_of_indigency']['error'] !== UPLOAD_ERR_OK) {
                json_response(['status' => 'error', 'message' => 'No certificate file uploaded or upload error.']);
            }
            
            // Initialize FilePathConfig and save file
            $pathConfig = FilePathConfig::getInstance();
            $uploadDir = $pathConfig->getTempPath('indigency');
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            $sessionPrefix = $_SESSION['file_prefix'] ?? 'session';
            cleanup_old_document_files($uploadDir, $sessionPrefix, '_Indigency');
            
            $fileExt = strtolower(pathinfo($_FILES['certificate_of_indigency']['name'], PATHINFO_EXTENSION));
            $fileName = $sessionPrefix . '_Indigency.' . $fileExt;
            $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $fileName;
            
            if (!move_uploaded_file($_FILES['certificate_of_indigency']['tmp_name'], $targetPath)) {
                json_response(['status' => 'error', 'message' => 'Failed to save uploaded certificate file.']);
            }
            
            // Create mock verification data
            $verification = [
                'first_name' => true,
                'last_name' => true,
                'barangay' => true,
                'indigency_title' => true,
                'municipality' => true,
                'confidence_scores' => [
                    'first_name' => OCR_BYPASS_CONFIDENCE,
                    'last_name' => OCR_BYPASS_CONFIDENCE,
                    'barangay' => OCR_BYPASS_CONFIDENCE,
                    'indigency_title' => OCR_BYPASS_CONFIDENCE,
                    'municipality' => OCR_BYPASS_CONFIDENCE
                ],
                'found_text_snippets' => [
                    'first_name' => 'Bypass Mode',
                    'last_name' => 'Bypass Mode',
                    'barangay' => 'Bypass Mode',
                    'indigency_title' => 'Bypass Mode',
                    'municipality' => 'Bypass Mode'
                ],
                'overall_success' => true,
                'summary' => [
                    'passed_checks' => 5,
                    'total_checks' => 5,
                    'average_confidence' => OCR_BYPASS_CONFIDENCE,
                    'recommendation' => 'Document validation successful (BYPASS MODE)',
                    'required_municipality' => $_SESSION['active_municipality_name'] ?? 'General Trias'
                ],
                'bypass_mode' => true,
                'bypass_reason' => OCR_BYPASS_REASON
            ];
            
            // Save mock confidence and verification files
            $confidenceFile = $uploadDir . DIRECTORY_SEPARATOR . $sessionPrefix . '_certificate.confidence.json';
            $confidenceData = [
                'overall_confidence' => OCR_BYPASS_CONFIDENCE,
                'detailed_scores' => $verification['confidence_scores'],
                'timestamp' => time(),
                'bypass_mode' => true
            ];
            file_put_contents($confidenceFile, json_encode($confidenceData));
            
            $verifyFile = $targetPath . '.verify.json';
            @file_put_contents($verifyFile, json_encode($verification, JSON_PRETTY_PRINT));
            
            $_SESSION['uploaded_files']['certificate'] = basename($targetPath);
            
            json_response(['status' => 'success', 'verification' => $verification]);
            exit;
        }
    }
    
    if (!isset($_FILES['certificate_of_indigency']) || $_FILES['certificate_of_indigency']['error'] !== UPLOAD_ERR_OK) {
        json_response(['status' => 'error', 'message' => 'No certificate file uploaded or upload error.']);
    }

    // Initialize FilePathConfig for path management
    $pathConfig = FilePathConfig::getInstance();
    $uploadDir = $pathConfig->getTempPath('indigency');
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    // DELETE OLD FILES: Remove previous upload and OCR results when new file is uploaded
    $sessionPrefix = $_SESSION['file_prefix'] ?? 'session';
    cleanup_old_document_files($uploadDir, $sessionPrefix, '_Indigency');
    
    // Use session-based file naming
    $fileExt = strtolower(pathinfo($_FILES['certificate_of_indigency']['name'], PATHINFO_EXTENSION));
    
    // REJECT PDF uploads - only accept image files
    if (!in_array($fileExt, ['jpg', 'jpeg', 'png'])) {
        json_response([
            'status' => 'error', 
            'message' => 'Invalid file format. Only JPG and PNG images are accepted.',
            'suggestions' => [
                'Take a photo of your certificate with your phone camera',
                'Convert your file to JPG or PNG format',
                'Ensure the image is clear and readable'
            ]
        ]);
    }
    
    $fileName = $sessionPrefix . '_Indigency.' . $fileExt;
    $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $fileName;

    if (!move_uploaded_file($_FILES['certificate_of_indigency']['tmp_name'], $targetPath)) {
        json_response(['status' => 'error', 'message' => 'Failed to save uploaded certificate file.']);
    }

    // Get form data for comparison
    $formData = [
        'first_name' => trim($_POST['first_name'] ?? ''),
        'last_name' => trim($_POST['last_name'] ?? ''),
        'barangay_id' => intval($_POST['barangay_id'] ?? 0)
    ];

    // Get barangay name for comparison
    $barangayName = '';
    if ($formData['barangay_id'] > 0) {
        $barangayResult = pg_query_params($connection, "SELECT name FROM barangays WHERE barangay_id = $1", [$formData['barangay_id']]);
        if ($barangayRow = pg_fetch_assoc($barangayResult)) {
            $barangayName = $barangayRow['name'];
        }
    }

    // Perform OCR using Tesseract (PDF rejection handled earlier)
    $outputBase = $uploadDir . DIRECTORY_SEPARATOR . 'certificate_ocr_' . pathinfo($fileName, PATHINFO_FILENAME);
    
    // Standard Tesseract processing for images
    $command = "tesseract " . escapeshellarg($targetPath) . " " . escapeshellarg($outputBase) . 
               " --oem 1 --psm 6 -l eng 2>&1";
    
    $tesseractOutput = shell_exec($command);
    $outputFile = $outputBase . ".txt";
    
    if (!file_exists($outputFile)) {
        json_response([
            'status' => 'error', 
            'message' => 'OCR processing failed. Please ensure the document is clear and readable.',
            'debug_info' => $tesseractOutput,
            'suggestions' => [
                'Make sure the image is clear and high resolution',
                'Ensure good lighting when taking photos',
                'Try straightening the document in the image',
                'Use JPG or PNG format for best results'
            ]
        ]);
    }
    
    $ocrText = file_get_contents($outputFile);
    
    // Clean up temporary OCR files
    if (file_exists($outputFile)) {
        unlink($outputFile);
    }
    
    if (empty(trim($ocrText))) {
        json_response([
            'status' => 'error', 
            'message' => 'No text could be extracted from the document. Please ensure the image is clear and contains readable text.'
        ]);
    }

    // Improved verification checks for certificate of indigency with fuzzy matching
    $verification = [
        'certificate_title' => false,
        'first_name' => false,
        'last_name' => false,
        'barangay' => false,
        'municipality' => false,
        'confidence_scores' => [],
        'found_text_snippets' => []
    ];
    
    // Normalize OCR text for better matching
    $ocrTextNormalized = strtolower(preg_replace('/[^\w\s]/', ' ', $ocrText));
    
    // Function to calculate similarity score for certificate
    function calculateCertificateSimilarity($needle, $haystack) {
        $needle = strtolower(trim($needle));
        $haystack = strtolower(trim($haystack));
        
        // Exact match
        if (stripos($haystack, $needle) !== false) {
            return 100;
        }
        
        // Check for partial matches with high similarity
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
    }
    
    // Check for "Certificate of Indigency" title variations
    $certificateTitles = [
        'certificate of indigency',
        'indigency certificate',
        'certificate indigency',
        'katunayan ng kahirapan',
        'indigent certificate',
        'poverty certificate'
    ];
    
    $titleFound = false;
    $titleConfidence = 0;
    $foundTitleText = '';
    
    foreach ($certificateTitles as $title) {
        $similarity = calculateCertificateSimilarity($title, $ocrTextNormalized);
        if ($similarity > $titleConfidence) {
            $titleConfidence = $similarity;
        }
        
        if ($similarity >= 70) {
            $titleFound = true;
            // Try to find the actual text snippet
            $pattern = '/[^\n]*' . preg_quote(explode(' ', $title)[0], '/') . '[^\n]*/i';
            if (preg_match($pattern, $ocrText, $matches)) {
                $foundTitleText = trim($matches[0]);
            }
            break;
        }
    }
    
    $verification['certificate_title'] = $titleFound;
    $verification['confidence_scores']['certificate_title'] = $titleConfidence;
    if (!empty($foundTitleText)) {
        $verification['found_text_snippets']['certificate_title'] = $foundTitleText;
    }
    
    // Check first name with improved matching (STRICTER: 60% threshold)
    if (!empty($formData['first_name'])) {
        $similarity = calculateCertificateSimilarity($formData['first_name'], $ocrTextNormalized);
        $verification['confidence_scores']['first_name'] = $similarity;
        
        if ($similarity >= 60) {
            $verification['first_name'] = true;
            // Find and store the matched text snippet
            $pattern = '/\b\w*' . preg_quote(substr($formData['first_name'], 0, 3), '/') . '\w*\b/i';
            if (preg_match($pattern, $ocrText, $matches)) {
                $verification['found_text_snippets']['first_name'] = (string)($matches[0] ?? '');
            }
        }
    }
    
    // Check last name with improved matching (STRICTER: 70% threshold)
    if (!empty($formData['last_name'])) {
        $similarity = calculateCertificateSimilarity($formData['last_name'], $ocrTextNormalized);
        $verification['confidence_scores']['last_name'] = $similarity;
        
        if ($similarity >= 70) {
            $verification['last_name'] = true;
            // Find and store the matched text snippet
            $pattern = '/\b\w*' . preg_quote(substr($formData['last_name'], 0, 3), '/') . '\w*\b/i';
            if (preg_match($pattern, $ocrText, $matches)) {
                $verification['found_text_snippets']['last_name'] = (string)($matches[0] ?? '');
            }
        }
    }
    
    // Check barangay name with improved matching
    if (!empty($barangayName)) {
        $similarity = calculateCertificateSimilarity($barangayName, $ocrTextNormalized);
        $verification['confidence_scores']['barangay'] = $similarity;
        
        if ($similarity >= 70) { // Slightly lower threshold for barangay names
            $verification['barangay'] = true;
            // Find and store the matched text snippet
            $pattern = '/\b\w*' . preg_quote(substr($barangayName, 0, 4), '/') . '\w*\b/i';
            if (preg_match($pattern, $ocrText, $matches)) {
                $verification['found_text_snippets']['barangay'] = (string)($matches[0] ?? '');
            }
        }
    }
    
    // Get active municipality name from session
    $activeMunicipality = $_SESSION['active_municipality_name'] ?? 'General Trias';
    
    // Check for municipality (dynamic based on session)
    $municipalityVariations = [
        strtolower($activeMunicipality),
        strtolower(str_replace(' ', '', $activeMunicipality)), // Remove spaces
        'municipality of ' . strtolower($activeMunicipality),
        'city of ' . strtolower($activeMunicipality)
    ];
    
    // Add common abbreviations if municipality is "General Trias"
    if (stripos($activeMunicipality, 'general trias') !== false) {
        $municipalityVariations[] = 'gen trias';
        $municipalityVariations[] = 'gen. trias';
        $municipalityVariations[] = 'gentrias';
    }
    
    $municipalityFound = false;
    $municipalityConfidence = 0;
    $foundMunicipalityText = '';
    
    foreach ($municipalityVariations as $variation) {
        $similarity = calculateCertificateSimilarity($variation, $ocrTextNormalized);
        if ($similarity > $municipalityConfidence) {
            $municipalityConfidence = $similarity;
        }
        
        if ($similarity >= 70) {
            $municipalityFound = true;
            // Try to find the actual text snippet
            $pattern = '/[^\n]*' . preg_quote(explode(' ', $variation)[0], '/') . '[^\n]*/i';
            if (preg_match($pattern, $ocrText, $matches)) {
                $foundMunicipalityText = trim($matches[0]);
            }
            break;
        }
    }
    
    $verification['municipality'] = $municipalityFound;
    $verification['confidence_scores']['municipality'] = $municipalityConfidence;
    if (!empty($foundMunicipalityText)) {
        $verification['found_text_snippets']['municipality'] = $foundMunicipalityText;
    }
    
    // Calculate overall success with improved scoring
    $requiredCertificateChecks = ['certificate_title', 'first_name', 'last_name', 'barangay', 'municipality'];
    $passedCertificateChecks = 0;
    $totalConfidence = 0;
    
    foreach ($requiredCertificateChecks as $check) {
        if ($verification[$check]) {
            $passedCertificateChecks++;
        }
        // Add confidence score to total (default 0 if not set)
        $totalConfidence += isset($verification['confidence_scores'][$check]) ? 
            $verification['confidence_scores'][$check] : 0;
    }
    
    $averageConfidence = $totalConfidence / 5;
    
    // STRICTER SUCCESS CRITERIA: ALL 5 checks must pass
    // This prevents wrong documents from being accepted
    $verification['overall_success'] = ($passedCertificateChecks >= 5 && $averageConfidence >= 70);
    
    $verification['summary'] = [
        'passed_checks' => $passedCertificateChecks,
        'total_checks' => 5,
        'average_confidence' => round($averageConfidence, 1),
        'recommendation' => $verification['overall_success'] ? 
            'Certificate validation successful' : 
            'This document must contain: your name, barangay, "Certificate of Indigency" title, and "' . $activeMunicipality . '" municipality. Please upload the correct Certificate of Indigency.',
        'required_municipality' => $activeMunicipality
    ];
    
    // Include OCR text for debugging (truncated for security)
    $verification['ocr_text_preview'] = substr($ocrText, 0, 500) . (strlen($ocrText) > 500 ? '...' : '');
    
    // Save OCR confidence score to temp file for later use during registration
    // Use session-based filename to prevent conflicts between multiple users
    $sessionPrefix = $_SESSION['file_prefix'] ?? 'session';
    $confidenceFile = $uploadDir . DIRECTORY_SEPARATOR . $sessionPrefix . '_certificate.confidence.json';
    $confidenceData = [
        'overall_confidence' => $averageConfidence,
        'detailed_scores' => $verification['confidence_scores'],
        'timestamp' => time()
    ];
    file_put_contents($confidenceFile, json_encode($confidenceData));
    
    // Save full verification data to .verify.json for admin validation view
    $verifyFile = $targetPath . '.verify.json';
    @file_put_contents($verifyFile, json_encode($verification, JSON_PRETTY_PRINT));
    
    // Save OCR text to .ocr.txt for reference
    $ocrFile = $targetPath . '.ocr.txt';
    @file_put_contents($ocrFile, $ocrText);
    
    // Track uploaded file
    $_SESSION['uploaded_files']['certificate'] = basename($targetPath);
    
    // CROSS-DOCUMENT DETECTION: Check if user uploaded wrong document type
    // This prevents uploading Letter, Grades, or other documents as Certificate of Indigency
    $crossDocumentCheck = performCrossDocumentCheck($ocrText, '03'); // '03' = Certificate of Indigency
    
    if ($crossDocumentCheck['wrong_document_detected']) {
        $verification['cross_document_warning'] = [
            'detected' => true,
            'expected_type' => 'Certificate of Indigency',
            'detected_type' => $crossDocumentCheck['detected_type'],
            'message' => $crossDocumentCheck['message'],
            'confidence' => $crossDocumentCheck['confidence']
        ];
        
        // Override overall_success if wrong document detected
        $verification['overall_success'] = false;
        $verification['summary']['recommendation'] = $crossDocumentCheck['message'];
        
        error_log("CROSS-DOCUMENT WARNING: User uploaded " . $crossDocumentCheck['detected_type'] . " instead of Certificate of Indigency");
    }
    
    // Note: Certificate file is kept in temp directory for final registration step
    // It will be cleaned up during registration completion
    
    json_response(['status' => 'success', 'verification' => $verification]);
}

// --- Enhanced Grades OCR Processing with Strict Validation ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['processGradesOcr'])) {
    // Suppress errors early
    @ini_set('display_errors', '0');
    @error_reporting(0);
    
    try {
        $captcha = verify_recaptcha_v3($_POST['g-recaptcha-response'] ?? '', 'process_grades_ocr');
        if (!$captcha['ok']) {
            json_response(['status'=>'error','message'=>'Security verification failed (captcha).']);
        }

        if (!isset($_FILES['grades_document']) || $_FILES['grades_document']['error'] !== UPLOAD_ERR_OK) {
            json_response(['status' => 'error', 'message' => 'No grades document uploaded or upload error.']);
        }

        // Initialize FilePathConfig for path management
        $pathConfig = FilePathConfig::getInstance();
        $uploadDir = $pathConfig->getTempPath('grades');
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        // DELETE OLD FILES: Remove previous upload and OCR results when new file is uploaded
        $sessionPrefix = $_SESSION['file_prefix'] ?? 'session';
        cleanup_old_document_files($uploadDir, $sessionPrefix, '_Grades');
        
        // Use session-based file naming
        $fileExt = strtolower(pathinfo($_FILES['grades_document']['name'], PATHINFO_EXTENSION));
        
        // REJECT PDF uploads - only accept image files
        if (!in_array($fileExt, ['jpg', 'jpeg', 'png'])) {
            json_response([
                'status' => 'error', 
                'message' => 'Invalid file format. Only JPG and PNG images are accepted.',
                'suggestions' => [
                    'Take a photo of your grades with your phone camera',
                    'Convert your file to JPG or PNG format',
                    'Ensure the image is clear and readable'
                ]
            ]);
        }
        
        $fileName = $sessionPrefix . '_Grades.' . $fileExt;
        $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $fileName;

        if (!move_uploaded_file($_FILES['grades_document']['tmp_name'], $targetPath)) {
            json_response(['status' => 'error', 'message' => 'Failed to save uploaded grades file.']);
        }

        // Extract text from document (PDF rejection handled earlier)
        $ocrText = '';
        
        // Enhanced image processing via OCR service
        try {
            // Include the Safe version (no Imagick dependency, uses GD only)
            require_once __DIR__ . '/../../services/OCRProcessingService_Safe.php';
            
            $ocrProcessor = new OCRProcessingServiceSafe([
                'tesseract_path' => 'tesseract',
                'temp_dir' => $uploadDir, // Use grades temp dir directly
                'max_file_size' => 10 * 1024 * 1024,
            ]);
            
            // Process the document
            $ocrResult = $ocrProcessor->processGradeDocument($targetPath);
            
            error_log("Grades OCR Result: " . json_encode($ocrResult));
            
            if ($ocrResult['success'] && !empty($ocrResult['subjects'])) {
                // Build OCR text from extracted subjects for backward compatibility
                $ocrText = "Grade Document Analysis:\n";
                foreach ($ocrResult['subjects'] as $subject) {
                    $ocrText .= "{$subject['name']}: {$subject['rawGrade']}\n";
                }
                
                // Add raw text if needed for other validations
                $ocrText .= "\nRaw Document Content:\n";
                
                // Fall back to basic Tesseract for full text extraction AND generate TSV
                $outputBase = $uploadDir . 'ocr_output_' . uniqid();
                $outputFile = $outputBase . '.txt';
                    
                    // Generate text output
                    $cmd = "tesseract " . escapeshellarg($targetPath) . " " . 
                              escapeshellarg($outputBase) . " --oem 1 --psm 6 -l eng 2>&1";
                    
                    $tesseractOutput = shell_exec($cmd);
                    if (file_exists($outputFile)) {
                        $rawText = file_get_contents($outputFile);
                        $ocrText .= $rawText;
                        @unlink($outputFile);
                    } else {
                        error_log("Tesseract command failed: $cmd");
                        error_log("Tesseract output: $tesseractOutput");
                    }
                    
                    // Generate TSV data (structured OCR data with confidence scores and coordinates)
                    $tsvOutputBase = $uploadDir . pathinfo($fileName, PATHINFO_FILENAME);
                    $tsvCmd = "tesseract " . escapeshellarg($targetPath) . " " . 
                             escapeshellarg($tsvOutputBase) . " -l eng --oem 1 --psm 6 tsv 2>&1";
                    @shell_exec($tsvCmd);
                    
                    // TSV file should be created as filename.tsv
                    $generatedTsvFile = $tsvOutputBase . '.tsv';
                    $permanentTsvFile = $targetPath . '.tsv';
                    if (file_exists($generatedTsvFile)) {
                        @rename($generatedTsvFile, $permanentTsvFile);
                    }
                } else {
                    // Enhanced OCR failed, try basic Tesseract
                    $outputBase = $uploadDir . 'ocr_output_' . uniqid();
                    $outputFile = $outputBase . '.txt';
                    
                    // Try multiple PSM modes for better results
                    $psmModes = [6, 4, 7, 8, 3]; // Different page segmentation modes
                    $success = false;
                    $successPsm = 6;
                    
                    foreach ($psmModes as $psm) {
                        $cmd = "tesseract " . escapeshellarg($targetPath) . " " . 
                                  escapeshellarg($outputBase) . " --oem 1 --psm $psm -l eng 2>&1";
                        
                        $tesseractOutput = shell_exec($cmd);
                        
                        if (file_exists($outputFile)) {
                            $testText = file_get_contents($outputFile);
                            if (!empty(trim($testText)) && strlen(trim($testText)) > 10) {
                                $ocrText = $testText;
                                $success = true;
                                $successPsm = $psm;
                                break;
                            }
                        }
                    }
                    
                    if (!$success) {
                        // Log detailed error information
                        error_log("OCR processing failed for file: $targetPath");
                        error_log("Last Tesseract output: $tesseractOutput");
                        error_log("File size: " . filesize($targetPath));
                        error_log("File extension: $fileExtension");
                        
                        json_response([
                            'status' => 'error',
                            'message' => 'OCR processing failed. Please try the following:',
                            'suggestions' => [
                                'Ensure the image is clear and well-lit',
                                'Use higher resolution (at least 300 DPI)',
                                'Make sure text is horizontal (not rotated)',
                                'Try converting to PNG format',
                                'Check if the text is large enough to read'
                            ],
                            'debug_info' => [
                                'file_size' => filesize($targetPath),
                                'tesseract_output' => $tesseractOutput,
                                'enhanced_ocr_error' => $ocrResult['error'] ?? 'No enhanced OCR error'
                            ]
                        ]);
                    }
                    
                    @unlink($outputFile);
                    
                    // Generate TSV data with the successful PSM mode
                    $tsvOutputBase = $uploadDir . pathinfo($fileName, PATHINFO_FILENAME);
                    $tsvCmd = "tesseract " . escapeshellarg($targetPath) . " " . 
                             escapeshellarg($tsvOutputBase) . " -l eng --oem 1 --psm $successPsm tsv 2>&1";
                    @shell_exec($tsvCmd);
                    
                    // Move TSV file to permanent location
                    $generatedTsvFile = $tsvOutputBase . '.tsv';
                    $permanentTsvFile = $targetPath . '.tsv';
                    if (file_exists($generatedTsvFile)) {
                        @rename($generatedTsvFile, $permanentTsvFile);
                    }
                }
                
            } catch (Exception $e) {
                error_log("OCR Service Error: " . $e->getMessage());
                
                // Final fallback to basic Tesseract
                $outputBase = $uploadDir . 'ocr_output_' . uniqid();
                $outputFile = $outputBase . '.txt';
                $cmd = "tesseract " . escapeshellarg($targetPath) . " " . 
                          escapeshellarg($outputBase) . " --oem 1 --psm 6 -l eng 2>&1";
                
                $tesseractOutput = shell_exec($cmd);
                
                if (!file_exists($outputFile) || empty(trim(file_get_contents($outputFile)))) {
                    json_response([
                        'status' => 'error',
                        'message' => 'OCR processing encountered an error.',
                        'suggestions' => [
                            'Try uploading the image again',
                            'Ensure the file is not corrupted',
                            'Use PNG or JPG format'
                        ],
                        'debug_info' => [
                            'exception' => $e->getMessage(),
                            'tesseract_output' => $tesseractOutput
                        ]
                    ]);
                }
                
                $ocrText = file_get_contents($outputFile);
                @unlink($outputFile);
                
                // Generate TSV data for fallback
                $tsvOutputBase = $uploadDir . pathinfo($fileName, PATHINFO_FILENAME);
                $tsvCmd = "tesseract " . escapeshellarg($targetPath) . " " . 
                         escapeshellarg($tsvOutputBase) . " -l eng --oem 1 --psm 6 tsv 2>&1";
                @shell_exec($tsvCmd);
                
                // Move TSV file to permanent location
                $generatedTsvFile = $tsvOutputBase . '.tsv';
                $permanentTsvFile = $targetPath . '.tsv';
                if (file_exists($generatedTsvFile)) {
                    @rename($generatedTsvFile, $permanentTsvFile);
                }
            }

        if (empty(trim($ocrText))) {
            json_response([
                'status' => 'error', 
                'message' => 'No text could be extracted from the document.',
                'suggestions' => [
                    'Ensure the image has good contrast and lighting',
                    'Make sure the text is horizontal (not rotated)',
                    'Try using a higher resolution image (300+ DPI)',
                    'Use PNG or JPG format',
                    'Ensure the document fills most of the image frame'
                ],
                'debug_info' => [
                    'file_size' => filesize($targetPath),
                    'file_extension' => $fileExtension,
                    'text_length' => strlen($ocrText ?? ''),
                    'ocr_text_sample' => substr($ocrText ?? '', 0, 100)
                ]
            ]);
        }

        // Get form data for validation
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        $yearLevelId = intval($_POST['year_level_id'] ?? 0);
        $universityId = intval($_POST['university_id'] ?? 0);

        // Get declared year level name
        $declaredYearName = '';
        if ($yearLevelId > 0) {
            $yrRes = pg_query_params($connection, "SELECT name FROM year_levels WHERE year_level_id = $1", [$yearLevelId]);
            if ($yrRow = pg_fetch_assoc($yrRes)) {
                $declaredYearName = $yrRow['name'];
            }
        }

        // Get university name for verification
        $declaredUniversityName = '';
        if ($universityId > 0) {
            $uniRes = pg_query_params($connection, "SELECT name FROM universities WHERE university_id = $1", [$universityId]);
            if ($uniRow = pg_fetch_assoc($uniRes)) {
                $declaredUniversityName = $uniRow['name'];
            }
        }

        // Get admin-specified semester and school year from config
        $adminSemester = '';
        $adminSchoolYear = '';
        
        $configRes = pg_query($connection, "SELECT key, value FROM config WHERE key IN ('valid_semester', 'valid_school_year')");
        while ($configRow = pg_fetch_assoc($configRes)) {
            if ($configRow['key'] === 'valid_semester') {
                $adminSemester = $configRow['value'];
            } elseif ($configRow['key'] === 'valid_school_year') {
                $adminSchoolYear = $configRow['value'];
            }
        }

        // Normalize OCR text
        $ocrTextNormalized = strtolower($ocrText);

        // === 1. DECLARED YEAR VALIDATION ===
        $yearValidationResult = validateDeclaredYear($ocrText, $declaredYearName, $adminSemester);
        $yearLevelMatch = $yearValidationResult['match'];
        $yearLevelSection = $yearValidationResult['section'];
        $yearLevelConfidence = $yearValidationResult['confidence'];
        
        // If year validation failed, don't extract grades from entire document
        if (!$yearLevelMatch) {
            error_log("Year validation failed for '$declaredYearName' - setting empty grade section");
            error_log("Year validation error: " . ($yearValidationResult['error'] ?? 'Unknown error'));
            $yearLevelSection = ''; // Empty section = no grades extracted
        }

        // === 2. ADMIN SEMESTER VALIDATION ===
        $semesterValidationResult = validateAdminSemester($ocrText, $adminSemester);
        $semesterMatch = $semesterValidationResult['match'];
        $semesterConfidence = $semesterValidationResult['confidence'];
        $foundSemesterText = $semesterValidationResult['found_text'];

        // === 3. ADMIN SCHOOL YEAR VALIDATION === (TEMPORARILY DISABLED FOR TESTING)
        // $schoolYearValidationResult = validateAdminSchoolYear($ocrText, $adminSchoolYear);
        // Temporarily set to always pass for testing
        $schoolYearMatch = true;
        $schoolYearConfidence = 100;
        $foundSchoolYearText = 'Temporarily disabled for testing';

    // === 4. GRADE THRESHOLD CHECK ===
    // Legacy grade validation (kept for backward compatibility)
    $debugGrades = isset($_POST['debug']) && $_POST['debug'] === '1';
    
    // Construct TSV file path for accurate grade parsing
    $tsvFilePath = $targetPath . '.tsv';
    if (!file_exists($tsvFilePath)) {
        // Try without extension prefix
        $pathInfo = pathinfo($targetPath);
        $tsvFilePath = $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '.tsv';
    }
    
    // Prepare student data for security validation
    $studentValidationData = [
        'first_name' => $firstName,
        'last_name' => $lastName,
        'university_name' => $declaredUniversityName
    ];
    
    // Try to get course from enrollment form confidence JSON (optional)
    $sessionPrefix = $_SESSION['file_prefix'] ?? 'session';
    $pathConfig = FilePathConfig::getInstance();
    $tempEnrollmentDir = $pathConfig->getTempPath('enrollment_forms');
    $enrollmentConfidenceFile = $tempEnrollmentDir . DIRECTORY_SEPARATOR . $sessionPrefix . '_enrollment.confidence.json';
    
    if (file_exists($enrollmentConfidenceFile)) {
        $enrollmentData = json_decode(file_get_contents($enrollmentConfidenceFile), true);
        if (isset($enrollmentData['course_info']['normalized_course'])) {
            $studentValidationData['course_name'] = $enrollmentData['course_info']['normalized_course'];
            error_log("GRADES: Retrieved course for validation: " . $studentValidationData['course_name']);
        } elseif (isset($enrollmentData['course_info']['raw_course'])) {
            $studentValidationData['course_name'] = $enrollmentData['course_info']['raw_course'];
            error_log("GRADES: Retrieved raw course for validation: " . $studentValidationData['course_name']);
        }
    }
    
    // Pass TSV file path for structured grade extraction (much more accurate than regex)
    // ALSO pass student data for security validation (prevent fake transcripts)
    require_once __DIR__ . '/grade_validation_functions.php';
    $gradeValidationResult = validateGradeThreshold(
        $yearLevelSection, 
        $declaredYearName, 
        $debugGrades, 
        $adminSemester,
        file_exists($tsvFilePath) ? $tsvFilePath : null,  // Pass TSV path if exists
        $studentValidationData  // Pass student data for security validation
    );
    
    // SECURITY CHECK: Reject fraudulent transcripts
    if (isset($gradeValidationResult['security_failure'])) {
        error_log("SECURITY ALERT: Grades upload rejected - " . ($gradeValidationResult['error'] ?? 'Unknown security issue'));
        error_log("Student: $firstName $lastName");
        error_log("Expected University: $declaredUniversityName");
        error_log("Security failure type: " . $gradeValidationResult['security_failure']);
        
        json_response([
            'status' => 'error',
            'message' => $gradeValidationResult['error'],
            'security_alert' => true,
            'suggestions' => [
                'Ensure you are uploading YOUR OWN transcript',
                'Verify the university name matches your registered school',
                'Check that your name appears clearly in the document',
                'Contact support if you believe this is an error'
            ]
        ]);
    }
    
        $legacyAllGradesPassing = $gradeValidationResult['all_passing'];
        $legacyValidGrades = $gradeValidationResult['grades'];
        $legacyFailingGrades = $gradeValidationResult['failing_grades'];
        
        // === 4B. ENHANCED PER-SUBJECT GRADE VALIDATION ===
        // Get university code for per-subject validation
        $universityCode = '';
        if ($universityId) {
            $uniCodeRes = pg_query_params($connection, "SELECT code FROM universities WHERE university_id = $1", [$universityId]);
            if ($uniCodeRow = pg_fetch_assoc($uniCodeRes)) {
                $universityCode = $uniCodeRow['code'];
            }
        }
        
        // Perform enhanced per-subject grade validation if university code available
        $enhancedGradeResult = null;
        $allGradesPassing = $legacyAllGradesPassing; // Default to legacy
        $validGrades = $legacyValidGrades;
        $failingGrades = $legacyFailingGrades;
        
        if (!empty($universityCode)) {
            // Convert legacy grades to enhanced format for validation
            $subjectsForValidation = array_map(function($grade) {
                $s = [
                    'name' => $grade['subject'],
                    'rawGrade' => $grade['grade'],
                    'confidence' => 95 // High confidence for OCR-extracted grades
                ];
                if (isset($grade['prelim'])) $s['prelim'] = $grade['prelim'];
                if (isset($grade['midterm'])) $s['midterm'] = $grade['midterm'];
                if (isset($grade['final'])) $s['final'] = $grade['final'];
                // Keep legacy 'grade' key for backwards compatibility
                $s['grade'] = $grade['grade'] ?? ($s['final'] ?? $s['midterm'] ?? $s['prelim'] ?? null);
                return $s;
            }, $legacyValidGrades);
            
            if (!empty($subjectsForValidation)) {
                $enhancedGradeResult = validatePerSubjectGrades($universityCode, null, $subjectsForValidation);
                
                if ($enhancedGradeResult['success']) {
                    // Use enhanced validation result
                    $allGradesPassing = $enhancedGradeResult['eligible'];
                    $failingGrades = array_map(function($failedSubject) {
                        // Parse failed subject string "Subject Name: Grade"
                        $parts = explode(':', $failedSubject, 2);
                        return [
                            'subject' => trim($parts[0] ?? ''),
                            'grade' => trim($parts[1] ?? '')
                        ];
                    }, $enhancedGradeResult['failed_subjects']);
                }
            }
        }

        // === 5. UNIVERSITY VERIFICATION ===
        $universityValidationResult = validateUniversity($ocrText, $declaredUniversityName);
        $universityMatch = $universityValidationResult['matched'];
        $universityConfidence = $universityValidationResult['confidence'];
        $foundUniversityText = $universityValidationResult['found_text'];

        // === 6. NAME VERIFICATION ===
        $nameValidationResult = validateStudentName($ocrText, $firstName, $lastName, true); // Get detailed results
        $nameMatch = $nameValidationResult['match'];
        $firstNameMatch = $nameValidationResult['first_name_match'];
        $lastNameMatch = $nameValidationResult['last_name_match'];
        $firstNameConfidence = $nameValidationResult['confidence_scores']['first_name'];
        $lastNameConfidence = $nameValidationResult['confidence_scores']['last_name'];
        $firstNameSnippet = $nameValidationResult['found_text_snippets']['first_name'];
        $lastNameSnippet = $nameValidationResult['found_text_snippets']['last_name'];

        // === 7. SCHOOL STUDENT ID VERIFICATION ===
        $schoolStudentId = trim($_POST['school_student_id'] ?? '');
        $schoolIdValidationResult = validateSchoolStudentId($ocrText, $schoolStudentId);
        $schoolIdMatch = $schoolIdValidationResult['match'];
        $schoolIdConfidence = $schoolIdValidationResult['confidence'];
        $foundSchoolIdText = $schoolIdValidationResult['found_text'];

        // === 8. ELIGIBILITY DECISION ===
        $isEligible = ($yearLevelMatch && $semesterMatch && $schoolYearMatch && $allGradesPassing && $universityMatch && $nameMatch && $schoolIdMatch);

        // Build verification response
        $verification = [
            'year_level_match' => $yearLevelMatch,
            'semester_match' => $semesterMatch,
            'school_year_match' => $schoolYearMatch,
            'university_match' => $universityMatch,
            'name_match' => $nameMatch,
            'first_name_match' => $firstNameMatch,
            'last_name_match' => $lastNameMatch,
            'school_student_id_match' => $schoolIdMatch,
            'all_grades_passing' => $allGradesPassing,
            'is_eligible' => $isEligible,
            'grades' => $validGrades,
            'failing_grades' => $failingGrades,
            'enhanced_grade_validation' => $enhancedGradeResult,
            'course_validation' => $gradeValidationResult['course_validation'] ?? null,  // Include course check
            'university_code' => $universityCode,
            'validation_method' => !empty($universityCode) && $enhancedGradeResult && $enhancedGradeResult['success'] ? 'enhanced_per_subject' : 'legacy_threshold',
            'confidence_scores' => [
                'year_level' => $yearLevelConfidence,
                'semester' => $semesterConfidence,
                'school_year' => $schoolYearConfidence,
                'university' => $universityConfidence,
                'first_name' => $firstNameConfidence,
                'last_name' => $lastNameConfidence,
                'name' => $nameMatch ? 95 : 0,
                'school_student_id' => $schoolIdConfidence,
                'grades' => !empty($validGrades) ? 90 : 0
            ],
            'found_text_snippets' => [
                'year_level' => $declaredYearName,
                'semester' => $foundSemesterText,
                'school_year' => $foundSchoolYearText,
                'university' => $foundUniversityText,
                'first_name' => $firstNameSnippet,
                'last_name' => $lastNameSnippet,
                'school_student_id' => $foundSchoolIdText
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
                    ($schoolIdMatch ? 1 : 0) + 
                    ($allGradesPassing ? 1 : 0),
                'total_checks' => 6, // Updated to 6 (added school student ID)
                'eligibility_status' => $isEligible ? 'ELIGIBLE' : 'INELIGIBLE',
                'recommendation' => $isEligible ? 
                    'All validations passed - Student is eligible' : 
                    'Validation failed - Student is not eligible'
            ]
        ];

        // Calculate average confidence
        $confValues = array_values($verification['confidence_scores']);
        $verification['summary']['average_confidence'] = !empty($confValues) ? 
            round(array_sum($confValues) / count($confValues), 1) : 0;

        // Save OCR text to .ocr.txt file (in correct directory: uploadDir)
        $ocrTextFile = $targetPath . '.ocr.txt';
        @file_put_contents($ocrTextFile, $ocrText);

        // Save full verification results to .verify.json file (in correct directory: uploadDir)
        $verifyJsonFile = $targetPath . '.verify.json';
        @file_put_contents($verifyJsonFile, json_encode($verification, JSON_PRETTY_PRINT));

        // Save verification results for confidence calculation
        // Use session-based filename to prevent conflicts between multiple users
        $sessionPrefix = $_SESSION['file_prefix'] ?? 'session';
        $confidenceFile = $uploadDir . DIRECTORY_SEPARATOR . $sessionPrefix . '_grades.confidence.json';
        @file_put_contents($confidenceFile, json_encode([
            'overall_confidence' => $verification['summary']['average_confidence'],
            'ocr_confidence' => $verification['summary']['average_confidence'],
            'detailed_scores' => $verification['confidence_scores'],
            'extracted_grades' => $validGrades,
            'average_grade' => $averageGrade,
            'passing_status' => $allGradesPassing,
            'eligibility_status' => $verification['summary']['eligibility_status'],
            'timestamp' => time()
        ]));
        
        // Track uploaded file
        $_SESSION['uploaded_files']['grades'] = basename($targetPath);

        json_response(['status' => 'success', 'verification' => $verification]);

    } catch (Throwable $e) {
        error_log("Grades OCR Error: " . $e->getMessage());
        error_log("Grades OCR Stack Trace: " . $e->getTraceAsString());
        json_response([
            'status' => 'error',
            'message' => 'An error occurred during processing: ' . $e->getMessage(),
            'suggestions' => [
                'Try uploading a clearer image',
                'Ensure grades are clearly visible',
                'Check file format and try again'
            ]
        ]);
    }
}

// === GRADE VALIDATION HELPER FUNCTIONS ===

function validateDeclaredYear($ocrText, $declaredYearName, $adminSemester = '') {
    $ocrTextLower = strtolower($ocrText);
    $declaredYearLower = strtolower($declaredYearName);
    
    // Year level variations mapping - expanded for better OCR matching
    $yearVariations = [
        '1' => ['1st year', 'first year', '1st yr', 'year 1', 'yr 1', 'freshman', 'grade 1', 'first yr', 'lst year', '1 st year', 'firstyear', 'year i', 'year one'],
        '2' => ['2nd year', 'second year', '2nd yr', 'year 2', 'yr 2', 'sophomore', 'grade 2', 'second yr', 'znd year', '2 nd year', 'secondyear', 'year ii', 'year two'], 
        '3' => ['3rd year', 'third year', '3rd yr', 'year 3', 'yr 3', 'junior', 'grade 3', 'third yr', '3 rd year', 'thirdyear', 'year iii', 'year three'],
        '4' => ['4th year', 'fourth year', '4th yr', 'year 4', 'yr 4', 'senior', 'grade 4', 'fourth yr', '4 th year', 'fourthyear', 'year iv', 'year four']
    ];
    
    // Semester variations for filtering
    $semesterVariations = [
        'first semester' => ['1st semester', 'first semester', '1st sem', 'semester 1', 'sem 1'],
        'second semester' => ['2nd semester', 'second semester', '2nd sem', 'semester 2', 'sem 2'],
        'summer' => ['summer', 'summer semester', 'summer class', 'midyear'],
        'third semester' => ['3rd semester', 'third semester', '3rd sem', 'semester 3', 'sem 3']
    ];
    
    // Detect which year was declared
    $declaredYearNum = '';
    foreach ($yearVariations as $num => $variations) {
        foreach ($variations as $variation) {
            if (stripos($declaredYearLower, $variation) !== false) {
                $declaredYearNum = $num;
                break 2;
            }
        }
    }
    
    if (empty($declaredYearNum)) {
        error_log("Could not determine year number from declared year name: '$declaredYearName'");
        return ['match' => false, 'section' => $ocrText, 'confidence' => 0, 'error' => 'Could not parse declared year name'];
    }
    
    // STEP 1: Extract grades ONLY from the declared year section
    $targetVariations = $yearVariations[$declaredYearNum];
    $yearSectionStart = false;
    $yearSectionEnd = false;
    $matchedVariation = '';
    
    // Find start position of declared year
    foreach ($targetVariations as $variation) {
        $pos = stripos($ocrTextLower, $variation);
        if ($pos !== false) {
            $yearSectionStart = $pos;
            $matchedVariation = $variation;
            error_log("Found year marker '$variation' at position $pos for declared year '$declaredYearName'");
            break;
        }
    }
    
    if ($yearSectionStart === false) {
        // Year marker not found - log what we searched for
        error_log("WARNING: Year marker not found for '$declaredYearName' (year $declaredYearNum)");
        error_log("Searched for variations: " . implode(', ', $targetVariations));
        error_log("OCR text preview (first 500 chars): " . substr($ocrText, 0, 500));
        return ['match' => false, 'section' => $ocrText, 'confidence' => 0, 'error' => 'Year marker not found'];
    }
    
    // Find end position (next year level or end of document)
    $yearSectionEnd = strlen($ocrText);
    foreach ($yearVariations as $otherNum => $otherVariations) {
        if ($otherNum == $declaredYearNum) continue;
        
        foreach ($otherVariations as $otherVariation) {
            $otherPos = stripos($ocrTextLower, $otherVariation, $yearSectionStart + 1);
            if ($otherPos !== false && $otherPos < $yearSectionEnd) {
                $yearSectionEnd = $otherPos;
            }
        }
    }
    
    // Extract the year section
    $yearSection = substr($ocrText, $yearSectionStart, $yearSectionEnd - $yearSectionStart);
    
    // STEP 2: Further filter by admin semester if specified
    if (!empty($adminSemester)) {
        $adminSemesterLower = strtolower($adminSemester);
        
        // Find matching semester variations
        $targetSemesterVariations = [];
        foreach ($semesterVariations as $standard => $variations) {
            if (stripos($adminSemesterLower, $standard) !== false || in_array($adminSemesterLower, $variations)) {
                $targetSemesterVariations = $variations;
                break;
            }
        }
        
        if (!empty($targetSemesterVariations)) {
            $yearSectionLower = strtolower($yearSection);
            $semesterFound = false;
            $semesterStart = false;
            $semesterEnd = strlen($yearSection);
            
            // Find the admin semester within the year section
            foreach ($targetSemesterVariations as $semVar) {
                $semPos = stripos($yearSectionLower, $semVar);
                if ($semPos !== false) {
                    $semesterStart = $semPos;
                    $semesterFound = true;
                    break;
                }
            }
            
            if ($semesterFound) {
                // Find end of this semester section (next semester marker or end)
                foreach ($semesterVariations as $otherSemesterVars) {
                    // Skip the current semester variations
                    if ($otherSemesterVars === $targetSemesterVariations) continue;
                    
                    foreach ($otherSemesterVars as $otherSemVar) {
                        $otherSemPos = stripos($yearSectionLower, $otherSemVar, $semesterStart + 1);
                        if ($otherSemPos !== false && $otherSemPos < $semesterEnd) {
                            $semesterEnd = $otherSemPos;
                        }
                    }
                }
                
                // Extract ONLY the specific semester section
                $yearSection = substr($yearSection, $semesterStart, $semesterEnd - $semesterStart);
            }
        }
    }
    
    return [
        'match' => true,
        'section' => $yearSection,
        'confidence' => 95,
        'matched_variation' => $matchedVariation,
        'declared_year' => $declaredYearNum,
        'filtered_by_semester' => !empty($adminSemester)
    ];
}

/**
 * Enhanced grade extraction: returns prelim/midterm/final when present.
 * If only a single canonical grade is present, it is placed in 'final'.
 * $debug toggles inclusion of a debug array showing extraction decisions.
 */
function normalize_and_extract_grade_student(string $line): ?string {
    $s = trim($line);
    if ($s === '') return null;
    $s = str_replace(',', '.', $s);
    $s = preg_replace_callback('/(?<=\d)O(?=\d)|(?<=\b)O(?=\.)|(?<=\.)O(?=\d)/i', function($m){ return '0'; }, $s);
    preg_match_all('/(?:\d+\.\d+|\.\d+|\d+)/', $s, $m);
    if (empty($m[0])) return null;
    $tokens = $m[0];
    $chosen = null;
    for ($i = count($tokens)-1; $i >= 0; $i--) {
        if (strpos($tokens[$i], '.') !== false) { $chosen = $tokens[$i]; break; }
    }
    if ($chosen === null) $chosen = end($tokens);
    if (strpos($chosen, '.') === 0) $chosen = '0' . $chosen;
    $chosen = preg_replace('/[^0-9\.]/', '', $chosen);
    $chosen = preg_replace('/\.(?=.*\.)/', '', $chosen);
    if ($chosen === '') return null;
    
    // VALIDATE: Reject invalid grades (> 6.0)
    $gradeValue = floatval($chosen);
    if ($gradeValue <= 0.0 || $gradeValue > 6.0) {
        return null; // Reject invalid grades like 8.00, 21.00, etc.
    }
    
    return number_format($gradeValue, 2, '.', '');
}

function validateGradeThreshold($yearSection, $declaredYearName, $debug = false, $declaredTerm = '') {
    $validGrades = [];
    $failingGrades = [];
    $allPassing = true;
    $grade_debug = [];
    
    // Normalize the declared term to identify which column to extract
    $declaredTermLower = strtolower(trim($declaredTerm));
    $isPrelim = (stripos($declaredTermLower, 'prelim') !== false || stripos($declaredTermLower, '1st') !== false);
    $isMidterm = (stripos($declaredTermLower, 'midterm') !== false || stripos($declaredTermLower, 'mid') !== false || stripos($declaredTermLower, '2nd') !== false);
    $isFinal = (stripos($declaredTermLower, 'final') !== false || stripos($declaredTermLower, '3rd') !== false);
    
    // For LPU and similar schools: if a specific term is declared, only extract ONE grade per subject
    $singleTermMode = ($isPrelim || $isMidterm || $isFinal);

    $lines = preg_split('/\r?\n/', $yearSection);
    $lastNonEmpty = '';
    
    // Track seen subjects to avoid duplicates
    $seenSubjects = [];

    foreach ($lines as $ln) {
        $lnTrim = trim($ln);
        if ($lnTrim === '') continue;

        // Skip summary lines but be less aggressive
        if (preg_match('/\b(total\s+units|total\s+credit|gwa|general\s+weighted\s+average|passing\s+percentage)\b/i', $lnTrim)) {
            if ($debug) $grade_debug[] = ['skipped' => $lnTrim];
            continue;
        }
        
        // Skip lines that are just column headers
        if (preg_match('/^\s*(code|title|grade|compl|units|credit|semester)\s*$/i', $lnTrim)) {
            if ($debug) $grade_debug[] = ['skipped_header' => $lnTrim];
            continue;
        }

        // Extract ALL numeric tokens from the line (for prelim/midterm/final)
        preg_match_all('/(?:\d+\.\d+|\.\d+|\d+)/', str_replace(',', '.', $lnTrim), $tokenMatches);
        $allTokens = $tokenMatches[0] ?? [];

        // First pass: collect all valid numeric tokens
        // Valid Philippine grades: 1.00 to 5.00 (most schools) or 0.00 to 4.00 (honors scale)
        // Valid units: 1-6 (whole numbers)
        $validTokens = [];
        foreach ($allTokens as $token) {
            $val = floatval($token);
            // Accept only values <= 6.0 (grades up to 5.0 + units up to 6)
            // Reject anything > 6.0 (invalid grades like 8.00, 21.00, years like 2024, etc.)
            if ($val > 0.0 && $val <= 6.0) {
                $validTokens[] = $token;
            }
        }
        
        // Second pass: Remove units and credit_units columns (whole numbers 1-6 at the end)
        $grades = [];
        if (!empty($validTokens)) {
            // Count how many trailing whole numbers (1-6) exist
            $unitsCount = 0;
            for ($i = count($validTokens) - 1; $i >= 0; $i--) {
                $val = floatval($validTokens[$i]);
                $isWholeNumber = abs($val - round($val)) < 0.01;
                $isUnitRange = ($val >= 1.0 && $val <= 6.0);
                
                if ($isWholeNumber && $isUnitRange) {
                    $unitsCount++;
                } else {
                    break; // Stop when we hit a decimal grade
                }
            }
            
            // Remove trailing units columns (typically 1-2: units and credit_units)
            $tokensToKeep = count($validTokens) - $unitsCount;
            for ($i = 0; $i < $tokensToKeep; $i++) {
                $grades[] = $validTokens[$i];
            }
        }
        
        $tokens = array_values($grades);

        // Map tokens to prelim/midterm/final (units already filtered)
        $prelim = $mid = $final = null;
        
        // ENHANCED: Handle LPU-style side-by-side semester columns
        // If admin semester is specified and there are 2 grades, pick the correct one
        if (count($tokens) == 2 && !empty($declaredTerm)) {
            // Two grades found = likely First Semester | Second Semester columns
            // Determine which semester the admin opened
            $isFirstSemester = (stripos($declaredTermLower, 'first') !== false || stripos($declaredTermLower, '1st') !== false);
            $isSecondSemester = (stripos($declaredTermLower, 'second') !== false || stripos($declaredTermLower, '2nd') !== false);
            
            if ($isFirstSemester) {
                // Extract LEFT column (First Semester) - index 0
                $singleGrade = number_format(floatval($tokens[0]), 2, '.', '');
                $final = $singleGrade;
                
                if ($debug) {
                    $grade_debug[] = [
                        'lpu_column_mode' => true,
                        'semester' => 'First Semester (left column)',
                        'extracted_grade' => $singleGrade,
                        'skipped_grade' => $tokens[1]
                    ];
                }
            } elseif ($isSecondSemester) {
                // Extract RIGHT column (Second Semester) - index 1
                $singleGrade = number_format(floatval($tokens[1]), 2, '.', '');
                $final = $singleGrade;
                
                if ($debug) {
                    $grade_debug[] = [
                        'lpu_column_mode' => true,
                        'semester' => 'Second Semester (right column)',
                        'extracted_grade' => $singleGrade,
                        'skipped_grade' => $tokens[0]
                    ];
                }
            } else {
                // Unknown semester - take first as default
                $singleGrade = number_format(floatval($tokens[0]), 2, '.', '');
                $final = $singleGrade;
            }
        } elseif (count($tokens) > 0) {
            // Single grade or no semester filtering - extract first token
            $singleGrade = number_format(floatval($tokens[0]), 2, '.', '');
            $final = $singleGrade;
            
            if ($debug) {
                $grade_debug[] = [
                    'single_grade_mode' => true,
                    'declared_term' => $declaredTerm,
                    'extracted_grade' => $singleGrade,
                    'token_count' => count($tokens)
                ];
            }
        }

        // Derive subject by intelligently removing grade/unit numbers
        $subjectCandidate = $lnTrim;
        
        // Strategy: Keep the beginning part (subject code + name), remove numbers from the end
        // Remove separator characters first
        $subjectCandidate = preg_replace('/[:\|]{1,}/', ' ', $subjectCandidate);
        
        // Enhanced approach: Remove grade/unit tokens more intelligently
        $lineLength = strlen($subjectCandidate);
        
        // If we have grade tokens, try to identify subject vs. grade section
        if (!empty($validTokens)) {
            // Find the position of the FIRST grade token (likely where subject ends)
            $firstGradePos = false;
            foreach ($validTokens as $token) {
                $pos = strpos($subjectCandidate, $token);
                if ($pos !== false) {
                    // Skip if it's in the first 30% (likely part of subject code like "GNED 09")
                    if ($pos > ($lineLength * 0.3)) {
                        if ($firstGradePos === false || $pos < $firstGradePos) {
                            $firstGradePos = $pos;
                        }
                    }
                }
            }
            
            // If we found where grades start, truncate there
            if ($firstGradePos !== false) {
                $subjectCandidate = substr($subjectCandidate, 0, $firstGradePos);
            } else {
                // Fallback: remove each token individually
                foreach ($validTokens as $token) {
                    $pos = strpos($subjectCandidate, $token);
                    if ($pos !== false && $pos > ($lineLength * 0.3)) {
                        $subjectCandidate = preg_replace('/\b' . preg_quote($token, '/') . '\b/', ' ', $subjectCandidate, 1);
                    }
                }
            }
        }
        
        // Also remove common separators like multiple dashes
        $subjectCandidate = preg_replace('/\s*-+\s*/', ' ', $subjectCandidate);
        $subjectCandidate = trim(preg_replace('/\s+/', ' ', $subjectCandidate));
        $cleanSubject = cleanSubjectName($subjectCandidate);

        if (empty($cleanSubject)) {
            $cleanSubject = cleanSubjectName($lastNonEmpty);
        }

        if (empty($cleanSubject)) {
            if ($debug) $grade_debug[] = ['no_subject' => $lnTrim, 'tokens' => $tokens];
            $lastNonEmpty = $lnTrim;
            continue;
        }

        // Build grade object - ONLY include the single grade value
        $gradeObj = ['subject' => $cleanSubject];
        
        // For single grade format: only store the grade value, no prelim/midterm/final columns
        if ($final !== null) {
            $gradeObj['grade'] = $final;
        }

        // Determine canonical grade for legacy checks
        $canonical = $gradeObj['grade'] ?? null;
        if ($canonical !== null) {
            // Check for duplicates before adding
            $isDuplicate = false;
            $subjectKey = strtolower(trim($cleanSubject));
            if (isset($seenSubjects[$subjectKey])) {
                $isDuplicate = true;
            } else {
                $seenSubjects[$subjectKey] = true;
                $validGrades[] = $gradeObj;
                $gradeVal = floatval($canonical);
                if ($gradeVal > 3.00) { $failingGrades[] = ['subject'=>$cleanSubject,'grade'=>$canonical]; $allPassing = false; }
                if ($debug) $grade_debug[] = ['accepted' => $gradeObj, 'raw_line' => $lnTrim];
            }
            
            if ($isDuplicate && $debug) {
                $grade_debug[] = ['duplicate_skipped' => $gradeObj, 'raw_line' => $lnTrim];
            }
        } else {
            if ($debug) $grade_debug[] = ['no_grade_found' => $lnTrim];
        }

        $lastNonEmpty = $lnTrim;
    }

    // Second pass for grade-only lines (more lenient for missing subjects)
    $lastNonEmpty = '';
    foreach ($lines as $ln) {
        $lnTrim = trim($ln);
        if ($lnTrim === '') continue;
        
        // Check if line is ONLY a grade (no subject text)
        $onlyGrade = normalize_and_extract_grade_student($lnTrim);
        $isGradeOnly = ($onlyGrade !== null && preg_match('/^[\d\.\s\-]+$/', $lnTrim));
        
        if ($isGradeOnly && !empty($lastNonEmpty)) {
            // Previous line might be the subject
            $cleanSubject = cleanSubjectName($lastNonEmpty);
            if ($cleanSubject && strlen($cleanSubject) >= 3) {
                $subjectKey = strtolower(trim($cleanSubject));
                
                // Only add if not already extracted
                if (!isset($seenSubjects[$subjectKey])) {
                    $gradeData = ['subject'=>$cleanSubject,'grade'=>number_format(floatval($onlyGrade),2,'.','')];
                    $seenSubjects[$subjectKey] = true;
                    $validGrades[] = $gradeData;
                    
                    if (floatval($onlyGrade) > 3.00) { 
                        $failingGrades[] = $gradeData; 
                        $allPassing = false; 
                    }
                    
                    if ($debug) {
                        $grade_debug[] = [
                            'paired' => $gradeData,
                            'subject_line' => $lastNonEmpty,
                            'grade_line' => $lnTrim,
                            'method' => 'second_pass'
                        ];
                    }
                }
            }
        }
        
        // Update last non-empty line (potential subject line)
        if (!$isGradeOnly && strlen($lnTrim) > 2) {
            $lastNonEmpty = $lnTrim;
        }
    }

    $result = [
        'all_passing' => $allPassing,
        'grades' => $validGrades,
        'failing_grades' => $failingGrades,
        'grade_count' => count($validGrades)
    ];
    if ($debug) $result['debug'] = $grade_debug;
    return $result;
}

function validatePerSubjectGrades($universityKey, $uploadedFile = null, $subjects = null) {
    global $connection;
    
    try {
        // Include required services
        require_once __DIR__ . '/../../services/GradeValidationService.php';
        require_once __DIR__ . '/../../services/OCRProcessingService.php';
        
        // Convert existing pg_connect resource to PDO for GradeValidationService
        // Parse DATABASE_PUBLIC_URL from config/database.php connection
        $databaseUrl = getenv('DATABASE_PUBLIC_URL');
        if ($databaseUrl) {
            // Parse postgresql://user:pass@host:port/dbname
            $parts = parse_url($databaseUrl);
            $dbHost = $parts['host'] ?? 'localhost';
            $dbPort = $parts['port'] ?? 5432;
            $dbName = ltrim($parts['path'] ?? 'railway', '/');
            $dbUser = $parts['user'] ?? 'postgres';
            $dbPass = $parts['pass'] ?? '';
            
            try {
                $pdoConnection = new PDO("pgsql:host=$dbHost;port=$dbPort;dbname=$dbName", $dbUser, $dbPass);
                $pdoConnection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            } catch (Exception $e) {
                error_log("PDO Connection error: " . $e->getMessage());
                throw new Exception("Database connection failed for grade validation");
            }
        } else {
            throw new Exception("DATABASE_PUBLIC_URL not found");
        }
        
        $gradeValidator = new GradeValidationService($pdoConnection);
        $ocrProcessor = new OCRProcessingService([
            'tesseract_path' => 'tesseract',
            'temp_dir' => realpath(__DIR__ . '/../../temp_debug'),
            'max_file_size' => 10 * 1024 * 1024,
        ]);
        
        $extractedSubjects = [];
        
        // Process file upload or use provided subjects
        if ($uploadedFile && is_uploaded_file($uploadedFile['tmp_name'])) {
            // Create temp directory if needed
            $tempDir = __DIR__ . '/../../temp';
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }
            
            // Move uploaded file to temp location
            $tempFilePath = $tempDir . '/' . uniqid() . '_' . basename($uploadedFile['name']);
            if (move_uploaded_file($uploadedFile['tmp_name'], $tempFilePath)) {
                // Process document with OCR
                $ocrResult = $ocrProcessor->processGradeDocument($tempFilePath);
                
                if ($ocrResult['success']) {
                    $extractedSubjects = $ocrResult['subjects'];
                }
                
                // Clean up temp file
                unlink($tempFilePath);
            }
        } elseif ($subjects && is_array($subjects)) {
            // Use provided subjects data
            $extractedSubjects = $subjects;
        } else {
            throw new Exception('No valid grade data provided');
        }
        
        // Validate subjects against university grading policy
        $validationResult = $gradeValidator->validateApplicant($universityKey, $extractedSubjects);
        
        return [
            'success' => true,
            'eligible' => $validationResult['eligible'],
            'failed_subjects' => $validationResult['failedSubjects'],
            'total_subjects' => $validationResult['totalSubjects'],
            'passed_subjects' => $validationResult['passedSubjects'],
            'extracted_subjects' => $extractedSubjects,
            'university_key' => $universityKey
        ];
        
    } catch (Exception $e) {
        error_log("Per-subject grade validation error: " . $e->getMessage());
        return [
            'success' => false,
            'eligible' => false,
            'error' => $e->getMessage(),
            'failed_subjects' => [],
            'total_subjects' => 0,
            'passed_subjects' => 0,
            'extracted_subjects' => []
        ];
    }
}

function validateUniversity($ocrText, $declaredUniversityName) {
    if (empty($declaredUniversityName)) {
        return ['matched' => false, 'confidence' => 0, 'found_text' => ''];
    }
    
    $ocrTextLower = strtolower($ocrText);
    $universityLower = strtolower($declaredUniversityName);
    
    // Direct match
    if (stripos($ocrTextLower, $universityLower) !== false) {
        return ['matched' => true, 'confidence' => 100, 'found_text' => $declaredUniversityName];
    }
    
    // Word-by-word matching for partial matches
    $universityWords = array_filter(explode(' ', preg_replace('/[^a-zA-Z0-9\s]/', ' ', $universityLower)));
    $significantWords = array_filter($universityWords, function($word) {
        return strlen($word) > 3; // Only consider words longer than 3 characters
    });
    
    if (empty($significantWords)) {
        return ['matched' => false, 'confidence' => 0, 'found_text' => ''];
    }
    
    $matchedWords = 0;
    $foundText = '';
    
    foreach ($significantWords as $word) {
        if (stripos($ocrTextLower, $word) !== false) {
            $matchedWords++;
            $foundText .= $word . ' ';
        }
    }
    
    // Require at least 70% of significant words to match
    $matchPercentage = ($matchedWords / count($significantWords)) * 100;
    
    if ($matchPercentage >= 70) {
        return [
            'matched' => true, 
            'confidence' => round($matchPercentage), 
            'found_text' => trim($foundText)
        ];
    }
    
    return ['matched' => false, 'confidence' => round($matchPercentage), 'found_text' => trim($foundText)];
}

function validateStudentName($ocrText, $firstName, $lastName, $returnDetails = false) {
    $ocrTextLower = strtolower($ocrText);
    $firstNameLower = strtolower(trim($firstName));
    $lastNameLower = strtolower(trim($lastName));

    // Check for exact matches (case-insensitive)
    $firstNameMatch = stripos($ocrTextLower, $firstNameLower) !== false;
    $lastNameMatch = stripos($ocrTextLower, $lastNameLower) !== false;
    
    // Calculate confidence scores (95% for match, 0% for no match)
    $firstNameConfidence = $firstNameMatch ? 95 : 0;
    $lastNameConfidence = $lastNameMatch ? 95 : 0;
    
    // If detailed results requested
    if ($returnDetails) {
        // Find matched text snippets
        $firstNameSnippet = '';
        $lastNameSnippet = '';
        
        if ($firstNameMatch) {
            $pos = stripos($ocrTextLower, $firstNameLower);
            $contextStart = max(0, $pos - 10);
            $contextLength = strlen($firstNameLower) + 20;
            $firstNameSnippet = substr($ocrText, $contextStart, $contextLength);
        }
        
        if ($lastNameMatch) {
            $pos = stripos($ocrTextLower, $lastNameLower);
            $contextStart = max(0, $pos - 10);
            $contextLength = strlen($lastNameLower) + 20;
            $lastNameSnippet = substr($ocrText, $contextStart, $contextLength);
        }
        
        return [
            'match' => ($firstNameMatch && $lastNameMatch),
            'first_name_match' => $firstNameMatch,
            'last_name_match' => $lastNameMatch,
            'confidence_scores' => [
                'first_name' => $firstNameConfidence,
                'last_name' => $lastNameConfidence,
                'average' => ($firstNameConfidence + $lastNameConfidence) / 2
            ],
            'found_text_snippets' => [
                'first_name' => $firstNameSnippet,
                'last_name' => $lastNameSnippet
            ]
        ];
    }
    
    // Simple boolean return for backward compatibility
    return ($firstNameMatch && $lastNameMatch);
}

function validateSchoolStudentId($ocrText, $schoolStudentId) {
    if (empty($schoolStudentId)) {
        // If no school student ID provided, auto-pass (backward compatibility)
        return [
            'match' => true,
            'confidence' => 100,
            'found_text' => 'No school student ID required',
            'auto_passed' => true
        ];
    }
    
    // Debug logging
    error_log("=== validateSchoolStudentId Debug ===");
    error_log("Expected School Student ID: " . $schoolStudentId);
    error_log("OCR Text Length: " . strlen($ocrText));
    error_log("Full OCR Text: " . $ocrText); // Log FULL text to see what OCR extracted
    
    // Clean both the expected ID and OCR text for better matching
    $cleanSchoolId = preg_replace('/[^A-Z0-9]/i', '', $schoolStudentId);
    $cleanOcrText = preg_replace('/[^A-Z0-9]/i', '', $ocrText);
    
    error_log("Cleaned Expected ID: " . $cleanSchoolId);
    error_log("Cleaned OCR Text (first 1000 chars): " . substr($cleanOcrText, 0, 1000));
    error_log("Cleaned OCR contains ID? " . (stripos($cleanOcrText, $cleanSchoolId) !== false ? 'YES' : 'NO'));
    
    // Method 1: Check if cleaned school student ID appears in cleaned OCR text (exact match)
    if (stripos($cleanOcrText, $cleanSchoolId) !== false) {
        return [
            'match' => true,
            'confidence' => 100,
            'found_text' => $schoolStudentId,
            'match_type' => 'exact_match_cleaned'
        ];
    }
    
    // Method 2: Check with original formatting (dashes, spaces, etc.)
    if (stripos($ocrText, $schoolStudentId) !== false) {
        return [
            'match' => true,
            'confidence' => 100,
            'found_text' => $schoolStudentId,
            'match_type' => 'exact_match_original'
        ];
    }
    
    // Method 3: Check for common format variations
    $idVariations = [
        $schoolStudentId,
        str_replace('-', '', $schoolStudentId),
        str_replace(' ', '', $schoolStudentId),
        str_replace('-', ' ', $schoolStudentId),
        strtoupper($schoolStudentId),
        strtolower($schoolStudentId)
    ];
    
    foreach ($idVariations as $variation) {
        if (stripos($ocrText, $variation) !== false) {
            return [
                'match' => true,
                'confidence' => 95,
                'found_text' => $variation,
                'match_type' => 'format_variation'
            ];
        }
    }
    
    // Method 4: Extract potential ID numbers from OCR text and calculate similarity
    preg_match_all('/\b[\w\d\-]+\b/', $ocrText, $matches);
    $maxSimilarity = 0;
    $bestMatch = '';
    
    foreach ($matches[0] as $potentialId) {
        // Only check strings that have at least 4 characters
        if (strlen($potentialId) >= 4) {
            $cleanPotentialId = preg_replace('/[^A-Z0-9]/i', '', $potentialId);
            
            // Calculate similarity using similar_text
            similar_text($cleanSchoolId, $cleanPotentialId, $percent);
            
            if ($percent > $maxSimilarity) {
                $maxSimilarity = $percent;
                $bestMatch = $potentialId;
            }
            
            // Also check Levenshtein distance for better matching
            $lev = levenshtein(strtolower($cleanSchoolId), strtolower($cleanPotentialId));
            $maxLen = max(strlen($cleanSchoolId), strlen($cleanPotentialId));
            $levSimilarity = (1 - ($lev / $maxLen)) * 100;
            
            if ($levSimilarity > $maxSimilarity) {
                $maxSimilarity = $levSimilarity;
                $bestMatch = $potentialId;
            }
        }
    }
    
    // Accept if similarity is 70% or higher
    if ($maxSimilarity >= 70) {
        return [
            'match' => true,
            'confidence' => round($maxSimilarity, 2),
            'found_text' => $bestMatch,
            'match_type' => 'partial_match',
            'note' => 'Partial match found - please verify'
        ];
    }
    
    // No match found
    return [
        'match' => false,
        'confidence' => 0,
        'found_text' => '',
        'match_type' => 'no_match',
        'note' => 'School student ID not found in document'
    ];
}

function validateAdminSemester($ocrText, $adminSemester) {
    if (empty($adminSemester)) {
        return ['match' => true, 'confidence' => 100, 'found_text' => 'No semester requirement set'];
    }
    
    $ocrTextLower = strtolower($ocrText);
    $adminSemesterLower = strtolower($adminSemester);
    
    // Common semester variations
    $semesterVariations = [
        'first semester' => ['1st semester', 'first semester', '1st sem', 'semester 1', 'sem 1'],
        'second semester' => ['2nd semester', 'second semester', '2nd sem', 'semester 2', 'sem 2'],
        'summer' => ['summer', 'summer semester', 'summer class', 'midyear'],
        'third semester' => ['3rd semester', 'third semester', '3rd sem', 'semester 3', 'sem 3']
    ];
    
    // Find matching variations for admin semester
    $targetVariations = [];
    foreach ($semesterVariations as $standard => $variations) {
        if (stripos($adminSemesterLower, $standard) !== false || in_array($adminSemesterLower, $variations)) {
            $targetVariations = $variations;
            break;
        }
    }
    
    // If no variations found, use direct match
    if (empty($targetVariations)) {
        $targetVariations = [$adminSemesterLower];
    }
    
    // Check for matches in OCR text
    $foundText = '';
    foreach ($targetVariations as $variation) {
        if (stripos($ocrTextLower, $variation) !== false) {
            $foundText = $variation;
            return ['match' => true, 'confidence' => 95, 'found_text' => $foundText];
        }
    }
    
    return ['match' => false, 'confidence' => 0, 'found_text' => ''];
}

function validateAdminSchoolYear($ocrText, $adminSchoolYear) {
    if (empty($adminSchoolYear)) {
        return ['match' => true, 'confidence' => 100, 'found_text' => 'No school year requirement set'];
    }
    
    $ocrTextLower = strtolower($ocrText);
    
    // Extract year range from admin setting (e.g., "2023-2025", "2024–2025")
    if (preg_match('/(\d{4})\s*[-–]\s*(\d{4})/', $adminSchoolYear, $matches)) {
        $startYear = $matches[1];
        $endYear = $matches[2];
        
        // Look for various formats of the school year in OCR text
        $yearPatterns = [
            $startYear . '-' . $endYear,
            $startYear . '–' . $endYear,
            $startYear . ' - ' . $endYear,
            $startYear . ' – ' . $endYear,
            $startYear . '/' . $endYear,
            $startYear . ' / ' . $endYear,
            'sy ' . $startYear . '-' . $endYear,
            'school year ' . $startYear . '-' . $endYear,
            'a.y. ' . $startYear . '-' . $endYear,
            'academic year ' . $startYear . '-' . $endYear
        ];
        
        foreach ($yearPatterns as $pattern) {
            if (stripos($ocrTextLower, strtolower($pattern)) !== false) {
                return ['match' => true, 'confidence' => 95, 'found_text' => $pattern];
            }
        }
        
        return ['match' => false, 'confidence' => 0, 'found_text' => ''];
    }
    
    // If admin school year is not in range format, do direct match
    if (stripos($ocrTextLower, strtolower($adminSchoolYear)) !== false) {
        return ['match' => true, 'confidence' => 90, 'found_text' => $adminSchoolYear];
    }
    
    return ['match' => false, 'confidence' => 0, 'found_text' => ''];
}

/**
 * Cross-Document Detection: Check if uploaded document matches expected type
 * Prevents users from uploading wrong documents (e.g., Certificate instead of Letter)
 * 
 * @param string $ocrText - Full OCR text from document
 * @param string $expectedDocType - Expected document type code ('02'=Letter, '03'=Certificate, '01'=Grades)
 * @return array - Detection results with warning if wrong document detected
 */
function performCrossDocumentCheck($ocrText, $expectedDocType) {
    // Document type signatures (keywords that identify each document type)
    $documentSignatures = [
        '01' => ['subject', 'final grade', 'prelim', 'midterm', 'finals', 'general average', 'units', 'remarks'],
        '02' => ['dear mayor', 'honorable mayor', 'mayor ferrer', 'request', 'assistance', 'respectfully', 'sincerely', 'gratitude', 'scholarship'],
        '03' => ['certificate', 'indigency', 'certify', 'resident', 'barangay', 'katunayan', 'kahirapan']
    ];
    
    $documentNames = [
        '01' => 'Grades Document',
        '02' => 'Letter to Mayor',
        '03' => 'Certificate of Indigency'
    ];
    
    // Normalize OCR text for keyword matching
    $quickText = strtolower($ocrText);
    
    // Check against other document types
    $detectedType = null;
    $maxMatchCount = 0;
    $detectedDocCode = null;
    
    foreach ($documentSignatures as $docCode => $keywords) {
        // Skip checking against the expected document type
        if ($docCode === $expectedDocType) {
            continue;
        }
        
        // Count keyword matches
        $matchCount = 0;
        foreach ($keywords as $keyword) {
            if (stripos($quickText, $keyword) !== false) {
                $matchCount++;
            }
        }
        
        // Special handling for Letter vs Grades detection (HIGH FALSE POSITIVE RISK)
        // Letters often mention "grades", "GPA", "semester" when discussing academic performance
        if (($expectedDocType === '02' && $docCode === '01')) {
            // Check if document has letter-specific keywords
            $hasLetterKeywords = stripos($quickText, 'dear mayor') !== false || 
                                stripos($quickText, 'honorable') !== false ||
                                stripos($quickText, 'respectfully') !== false ||
                                stripos($quickText, 'sincerely') !== false ||
                                stripos($quickText, 'scholarship') !== false;
            
            // If it has letter keywords, don't flag as grades
            if ($hasLetterKeywords) {
                $isStrongMatch = false;
                error_log("CROSS-DOCUMENT: Letter has grade keywords but also has letter structure - PASS");
            } else {
                // Only flag if 6+ structural grade keywords
                $isStrongMatch = ($matchCount >= 6);
            }
        } else {
            // For other document type checks, use 3+ keyword threshold
            $isStrongMatch = ($matchCount >= 3);
        }
        
        if ($isStrongMatch && $matchCount > $maxMatchCount) {
            $maxMatchCount = $matchCount;
            $detectedType = $documentNames[$docCode];
            $detectedDocCode = $docCode;
        }
    }
    
    // Return result
    if ($detectedType !== null) {
        return [
            'wrong_document_detected' => true,
            'detected_type' => $detectedType,
            'expected_type' => $documentNames[$expectedDocType],
            'confidence' => $maxMatchCount,
            'message' => "This appears to be a {$detectedType}, not a {$documentNames[$expectedDocType]}. Please upload the correct document type."
        ];
    }
    
    return [
        'wrong_document_detected' => false,
        'detected_type' => null,
        'message' => ''
    ];
}

function cleanSubjectName($rawSubject) {
    // Remove subject codes and keep only the descriptive subject name
    $subject = $rawSubject;
    
    // Remove subject codes at the beginning (GNED09, IENG100A, IENG125A, etc.)
    // Pattern: Letters (2-8) followed by optional numbers and optional letter at START
    $subject = preg_replace('/^\b[A-Z]{2,8}\d+[A-Z]?\b\s*/i', '', $subject);
    
    // Also handle codes with spaces like "GNED 09" or "IENG 100A"
    $subject = preg_replace('/^\b[A-Z]{2,8}\s+\d+[A-Z]?\b\s*/i', '', $subject);
    
    // Remove patterns like A24-25, B22-23, etc. (letter + year range)
    $subject = preg_replace('/\b[A-Z]\d{2}-\d{2}\b/i', '', $subject);
    
    // Remove patterns like 1.25 B22-23 (grade + space + code)
    $subject = preg_replace('/\d+\.\d+\s+[A-Z]\d{2}-\d{2}/i', '', $subject);
    
    // Remove codes at the END (after the subject name)
    $subject = preg_replace('/\s+\b[A-Z]{2,}[0-9]+[A-Z]?\b/', '', $subject);
    
    // Remove patterns like 22-23, A22-23 at the beginning or end (likely years)
    $subject = preg_replace('/^[A-Z]?\d{2}-\d{2}\s*/', '', $subject);
    $subject = preg_replace('/\s*[A-Z]?\d{2}-\d{2}$/', '', $subject);
    
    // Remove extra numbers at the beginning (like "4 and Habits Practice")
    $subject = preg_replace('/^\d+\s+(?=and\s)/i', '', $subject);
    
    // Remove standalone single letters or numbers
    $subject = preg_replace('/\s+\b[A-Z0-9]\b\s+/', ' ', $subject);
    
    // Remove "code" or "title" labels that might appear
    $subject = preg_replace('/^(code|title)[\s:]+/i', '', $subject);
    
    // Clean up extra spaces and return
    $subject = preg_replace('/\s+/', ' ', trim($subject));
    
    // If the subject is too short after cleaning, return original
    if (strlen($subject) < 3) {
        return trim($rawSubject);
    }
    
    return $subject;
}

// Helper function to rename session-based confidence files to student ID-based naming
function renameConfidenceFile($docType, $sessionPrefix, $newFilePath, $tempDir) {
    // Map document types to confidence file naming patterns
    $confidencePatterns = [
        'id_picture' => '_idpic.confidence.json',  // Used for ID pictures (session-based)
        'eaf' => '_enrollment.confidence.json',
        'letter_to_mayor' => '_letter.confidence.json',
        'indigency' => '_certificate.confidence.json',
        'grades' => '_grades.confidence.json'
    ];
    
    if (!isset($confidencePatterns[$docType])) {
        error_log("Unknown document type for confidence file: $docType");
        return false;
    }
    
    // Build old confidence file path (session-based)
    $oldConfidenceFile = $tempDir . DIRECTORY_SEPARATOR . $sessionPrefix . $confidencePatterns[$docType];
    
    // Build new confidence file path (student ID-based)
    // Strip extension from newFilePath before adding .confidence.json
    $pathWithoutExt = preg_replace('/\.(jpg|jpeg|png)$/i', '', $newFilePath);
    $newConfidenceFile = $pathWithoutExt . '.confidence.json';
    
    error_log("Renaming confidence file for $docType:");
    error_log("  From: $oldConfidenceFile");
    error_log("  To: $newConfidenceFile");
    error_log("  Exists: " . (file_exists($oldConfidenceFile) ? 'YES' : 'NO'));
    
    // If old confidence file exists, rename it
    if (file_exists($oldConfidenceFile)) {
        if (@copy($oldConfidenceFile, $newConfidenceFile)) {
            @unlink($oldConfidenceFile);
            error_log("✓ Confidence file renamed successfully");
            return true;
        } else {
            error_log("✗ Failed to rename confidence file");
            return false;
        }
    } else {
        error_log("⚠ Confidence file not found (may not exist for this document type)");
        return false;
    }
}

// Helper function to update verification JSON file with new student ID-based paths
function updateVerificationJsonPaths($oldVerifyJsonPath, $newVerifyJsonPath, $oldMainFilePath, $newMainFilePath) {
    if (!file_exists($oldVerifyJsonPath)) {
        error_log("updateVerificationJsonPaths: Old verify.json not found: $oldVerifyJsonPath");
        return false;
    }
    
    error_log("updateVerificationJsonPaths: Processing $oldVerifyJsonPath -> $newVerifyJsonPath");
    
    // Read the verification JSON
    $jsonContent = file_get_contents($oldVerifyJsonPath);
    if (!$jsonContent) {
        error_log("updateVerificationJsonPaths: Failed to read file content");
        return false;
    }
    
    $verifyData = json_decode($jsonContent, true);
    if (!$verifyData) {
        // If JSON is invalid, just copy as-is
        error_log("updateVerificationJsonPaths: JSON decode failed, copying as-is");
        $copyResult = @copy($oldVerifyJsonPath, $newVerifyJsonPath);
        error_log("updateVerificationJsonPaths: Copy result: " . ($copyResult ? 'SUCCESS' : 'FAILED'));
        return $copyResult;
    }
    
    // Update file path references in the verification data
    $oldBasename = basename($oldMainFilePath);
    $newBasename = basename($newMainFilePath);
    
    error_log("updateVerificationJsonPaths: Replacing '$oldBasename' with '$newBasename'");
    
    // Convert the entire verification data structure to a JSON string
    $jsonString = json_encode($verifyData);
    
    // Replace all occurrences of the old filename with the new one
    $jsonString = str_replace($oldBasename, $newBasename, $jsonString);
    
    // Also replace the full paths if they exist
    $oldFullPath = str_replace('\\', '/', $oldMainFilePath);
    $newFullPath = str_replace('\\', '/', $newMainFilePath);
    $jsonString = str_replace($oldFullPath, $newFullPath, $jsonString);
    
    // Decode back to array
    $verifyData = json_decode($jsonString, true);
    
    // Save the updated verification JSON
    $success = file_put_contents($newVerifyJsonPath, json_encode($verifyData, JSON_PRETTY_PRINT));
    
    if ($success) {
        error_log("updateVerificationJsonPaths: SUCCESS - Created $newVerifyJsonPath");
        return true;
    } else {
        error_log("updateVerificationJsonPaths: FAILED - Could not write to $newVerifyJsonPath");
        return false;
    }
}

// --- Final registration submission ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['register'])) {
    // Include UnifiedFileService for unified document management
    require_once __DIR__ . '/../../services/UnifiedFileService.php';
    // Include BlacklistService for checking blacklisted emails/mobiles
    require_once __DIR__ . '/../../services/BlacklistService.php';
    
    // Helper function to properly capitalize names (Title Case with special name handling)
    function capitalizeProperName($name) {
        if (empty($name)) return $name;
        
        // Trim and convert to lowercase first
        $name = strtolower(trim($name));
        
        // Capitalize first letter of each word
        $name = ucwords($name);
        
        // Handle special cases for compound names
        // McDonald, O'Brien, D'Angelo, MacArthur, etc.
        $name = preg_replace_callback(
            "/(Mc|Mac|O'|D')([a-z])/",
            function($matches) {
                return $matches[1] . strtoupper($matches[2]);
            },
            $name
        );
        
        return $name;
    }
    
    // Basic input validation with proper name capitalization
    $firstname = capitalizeProperName($_POST['first_name'] ?? '');
    $middlename = capitalizeProperName($_POST['middle_name'] ?? '');
    $lastname = capitalizeProperName($_POST['last_name'] ?? '');
    $extension_name = trim($_POST['extension_name'] ?? ''); // Extensions like Jr., Sr. are already uppercase in select
    
    // NEW: Mother's maiden name for household prevention - convert empty string to NULL for database
    $mothers_maiden_name_raw = capitalizeProperName($_POST['mothers_maiden_name'] ?? '');
    $mothers_maiden_name = !empty($mothers_maiden_name_raw) ? $mothers_maiden_name_raw : null;
    
    $admin_review_required = (intval($_POST['admin_review_required'] ?? 0) === 1); // NEW: Flag for admin review
    $email = trim($_POST['email'] ?? '');
    $mobile = trim($_POST['mobile'] ?? '');
    $bdate = trim($_POST['bdate'] ?? '');
    $sex = trim($_POST['sex'] ?? '');
    $barangay = intval($_POST['barangay_id'] ?? 0);
    $university = intval($_POST['university_id'] ?? 0);
    $year_level = intval($_POST['year_level_id'] ?? 0);
    $course = trim($_POST['course'] ?? '');
    $course_verified = (intval($_POST['course_verified'] ?? 0) === 1);
    $password = trim($_POST['password'] ?? '');
    
    // ============================================================
    // STRICT PASSWORD VALIDATION - ENFORCE SECURITY REQUIREMENTS
    // ============================================================
    if (strlen($password) < 12) {
        json_response([
            'status' => 'error',
            'message' => '❌ Password must be at least 12 characters long.'
        ]);
    }
    // Server-side complexity checks disabled; client-side JS enforces remaining rules
    // ============================================================
    
    $hashed = password_hash($password, PASSWORD_DEFAULT);

    // Get year level name for new current_year_level column
    $yearLevelName = '';
    if ($year_level > 0) {
        $yearResult = pg_query_params($connection, "SELECT name FROM year_levels WHERE year_level_id = $1", [$year_level]);
        if ($yearRow = pg_fetch_assoc($yearResult)) {
            $yearLevelName = $yearRow['name'];
        }
    }

    // Use academic year from the slot the student registered under
    // This ensures the student's status is tracked to the correct enrollment period
    $currentAcademicYear = !empty($slotAcademicYear) ? $slotAcademicYear : null;
    
    // If slot doesn't have academic year (shouldn't happen), fall back to current academic year
    if ($currentAcademicYear === null || $currentAcademicYear === '') {
        $academicYearResult = pg_query($connection, "SELECT year_code FROM academic_years WHERE is_current = TRUE LIMIT 1");
        if ($academicYearRow = pg_fetch_assoc($academicYearResult)) {
            $currentAcademicYear = $academicYearRow['year_code'];
        }
    }
    
    // Ensure we have a valid academic year or use NULL (not empty string)
    if ($currentAcademicYear === '') {
        $currentAcademicYear = null;
    }

    // CRITICAL: Validate terms and conditions acceptance
    if (!isset($_POST['agree_terms']) || $_POST['agree_terms'] !== 'on') {
        json_response([
            'status' => 'error',
            'message' => 'You must accept the Terms and Conditions to proceed with registration.'
        ]);
    }

    // Server-side required field validation disabled; client-side handles this
    
    // Recommend providing mother's maiden name for household duplicate prevention
    if ($mothers_maiden_name === null || trim($mothers_maiden_name) === '') {
        // Allow registration but flag for admin review
        $admin_review_required = true;
    }

    // Server-side email/mobile/age validation disabled; enforced in client-side JS

    // Blacklist checks disabled per client-side validation policy

    // Check if email or mobile belongs to an archived student
    $archivedCheck = pg_query_params(
        $connection,
        "SELECT student_id, first_name, last_name, archival_type, is_archived 
         FROM students 
         WHERE (email = $1 OR mobile = $2) 
         AND is_archived = TRUE 
         LIMIT 1",
        [$email, $mobile]
    );

    // Archived account checks disabled

    // CRITICAL DUPLICATE CHECKS - Re-validate at final submission to prevent race conditions
    // These checks happen again even though they're checked earlier in the flow
    
    // Duplicate checks (email/mobile/admin) disabled

    // NEW: FINAL household duplicate check (prevent race conditions)
    // Only check if mothers_maiden_name is provided
    if ($mothers_maiden_name !== null && trim($mothers_maiden_name) !== '') {
        // Check for exact match: Surname + Mother's Maiden Name + Barangay
        $householdCheck = pg_query_params(
            $connection,
            "SELECT student_id, first_name, last_name 
             FROM students 
             WHERE LOWER(last_name) = LOWER($1)
               AND LOWER(mothers_maiden_name) = LOWER($2)
               AND barangay_id = $3
               AND is_archived = FALSE
             LIMIT 1",
            [$lastname, $mothers_maiden_name, $barangay]
        );
        
        if (pg_num_rows($householdCheck) > 0) {
            $householdRow = pg_fetch_assoc($householdCheck);
            
            // Log the block attempt to household_block_attempts table
            $logQuery = "INSERT INTO household_block_attempts (
                attempted_first_name, attempted_last_name, attempted_email, attempted_mobile,
                mothers_maiden_name_entered, barangay_entered, blocked_by_student_id,
                match_type, ip_address
            ) VALUES ($1, $2, $3, $4, $5, (SELECT name FROM barangays WHERE barangay_id = $6), $7, 'exact', $8)";
            
            pg_query_params($connection, $logQuery, [
                $firstname,
                $lastname,
                $email,
                $mobile,
                $mothers_maiden_name,
                $barangay,
                $householdRow['student_id'],
                $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
            
            json_response([
                'status' => 'error',
                'message' => 'A student from your household (' . $householdRow['first_name'] . ' ' . $householdRow['last_name'] . ') is already registered. Only one student per household can receive assistance.'
            ]);
        }
    }

    // Generate system student ID: <MUNICIPALITY>-<YEAR>-<YEARLEVEL>-<SEQUENCE>
    require_once __DIR__ . '/../../includes/util/student_id.php';
    $student_id = generateSystemStudentId($connection, $year_level, $municipality_id, intval(date('Y')));
    if (!$student_id) {
        // Fallback to avoid blocking registration: generate RANDOM6 with uniqueness check
        $ylCode = (string)intval($year_level);
        $muniPrefix = 'MUNI' . intval($municipality_id);
        $mr = @pg_query_params($connection, "SELECT COALESCE(NULLIF(slug,''), name) AS tag FROM municipalities WHERE municipality_id = $1", [$municipality_id]);
        if ($mr && pg_num_rows($mr) > 0) { $mrow = pg_fetch_assoc($mr); $muniPrefix = preg_replace('/[^A-Z0-9]/', '', strtoupper((string)($mrow['tag'] ?? $muniPrefix))); }
        $base = $muniPrefix . '-' . date('Y') . '-' . $ylCode . '-';
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ0123456789';
        for ($attempt=0;$attempt<25;$attempt++) {
            $rand = '';
            for ($i=0;$i<6;$i++) { $rand .= $chars[random_int(0, strlen($chars)-1)]; }
            $candidate = $base . $rand;
            $chk = @pg_query_params($connection, "SELECT 1 FROM students WHERE student_id = $1 LIMIT 1", [$candidate]);
            if ($chk && pg_num_rows($chk) === 0) { $student_id = $candidate; break; }
        }
        if (!$student_id) {
            $student_id = $base . substr(strtoupper(bin2hex(random_bytes(4))), 0, 8);
        }
    }

    // Get current active slot with academic year and semester for tracking
    $activeSlotQuery = pg_query_params($connection, "SELECT slot_id, academic_year, semester FROM signup_slots WHERE is_active = TRUE AND municipality_id = $1 ORDER BY created_at DESC LIMIT 1", [$municipality_id]);
    $activeSlot = pg_fetch_assoc($activeSlotQuery);
    $slot_id = $activeSlot ? $activeSlot['slot_id'] : null;
    $slotAcademicYear = $activeSlot ? $activeSlot['academic_year'] : null;
    $slotSemester = $activeSlot ? $activeSlot['semester'] : null;
    
    // CRITICAL: Validate that slot has academic year configured
    // This prevents database errors from empty academic year values
    // Slot academic year enforcement disabled; proceed with NULLs if needed

    // Get school student ID from form
    $school_student_id = trim($_POST['school_student_id'] ?? '');
    
    // Server-side school student ID required check disabled (client enforces)
    
    // Check for duplicate school student ID one final time
    $dupCheckQuery = "SELECT * FROM check_duplicate_school_student_id($1, $2)";
    $dupCheckResult = pg_query_params($connection, $dupCheckQuery, [$university, $school_student_id]);
    
    // Duplicate school student ID check disabled

    $insertQuery = "INSERT INTO students (
        student_id, municipality_id, first_name, middle_name, last_name, extension_name, 
        email, mobile, password, sex, status, payroll_no, application_date, bdate, 
        barangay_id, university_id, year_level_id, slot_id, school_student_id, course, course_verified,
        household_verified, household_primary, household_group_id, archival_type,
        current_year_level, is_graduating, last_status_update, status_academic_year,
        first_registered_academic_year, current_academic_year, mothers_maiden_name, admin_review_required
    )
    VALUES (
        $1, $2, $3, $4, $5, $6, $7, $8, $9, $10, 
        'under_registration', 0, NOW(), $11, $12, $13, $14, $15, $16, $17, $18,
        FALSE, FALSE, NULL, NULL,
        $19, FALSE, NOW(), $20, $21, $22, $23, $24
    ) RETURNING student_id";

    $result = pg_query_params($connection, $insertQuery, [
        $student_id,
        $municipality_id,
        $firstname,
        $middlename,
        $lastname,
        $extension_name,
        $email,
        $mobile,
        $hashed,
        $sex,
        $bdate,
        $barangay,
        $university,
        $year_level,
        $slot_id,
        $school_student_id,  // School/University-issued ID number
        $course,             // Course/Program (manually entered by student)
        $course_verified ? 'true' : 'false',  // Convert boolean to PostgreSQL boolean string
        $yearLevelName,      // New: current_year_level (e.g., "2nd Year College")
        $currentAcademicYear, // New: status_academic_year (from slot, e.g., "2025-2026")
        $currentAcademicYear, // New: first_registered_academic_year (initial enrollment year)
        $currentAcademicYear, // New: current_academic_year (tracks which year they're currently in)
        $mothers_maiden_name, // NEW: Mother's maiden name for household duplicate prevention
        $admin_review_required ? 'true' : 'false' // NEW: Admin review flag for edge cases
    ]);


    if ($result) {
        $student_id_row = pg_fetch_assoc($result);
        $student_id = $student_id_row['student_id'];

        // Initialize default notification preferences for the student
        $notif_pref_query = "INSERT INTO student_notification_preferences (student_id) VALUES ($1) ON CONFLICT (student_id) DO NOTHING";
        pg_query_params($connection, $notif_pref_query, [$student_id]);

        // Note: school_student_ids table will be populated when admin approves the application
        // This prevents fake/spam registrations from polluting the duplicate check system

        // Initialize UnifiedFileService
        $fileService = new UnifiedFileService($connection);
        
        // Create standardized name for file naming (lastname_firstname)
        $cleanLastname = preg_replace('/[^a-zA-Z0-9]/', '', $lastname);
        $cleanFirstname = preg_replace('/[^a-zA-Z0-9]/', '', $firstname);
        $namePrefix = strtolower($cleanLastname . '_' . $cleanFirstname);

        // === INITIALIZE PATH CONFIG FOR FILE HANDLING ===
        $pathConfig = FilePathConfig::getInstance();
        
        // Get temp directories using FilePathConfig (handles Railway/localhost automatically)
        $tempIDPictureDir = $pathConfig->getTempPath('id_pictures');
        $tempEnrollmentDir = $pathConfig->getTempPath('enrollment_forms');
        $tempLetterDir = $pathConfig->getTempPath('letter_to_mayor');
        $tempIndigencyDir = $pathConfig->getTempPath('indigency');
        $tempGradesDir = $pathConfig->getTempPath('grades');

        // === SAVE ID PICTURE USING UnifiedFileService ===
        $sessionPrefix = $_SESSION['file_prefix'] ?? 'session';
        
        // Look for session-based ID picture file
        $idPicturePattern = $tempIDPictureDir . DIRECTORY_SEPARATOR . $sessionPrefix . '_idpic.*';
        $idTempFiles = glob($idPicturePattern);
        
        // Filter to get only actual image files (PDF not supported)
        $idTempFiles = array_filter($idTempFiles, function($file) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            return in_array($ext, ['jpg', 'jpeg', 'png']) && is_file($file);
        });
        
        error_log("Processing ID Picture for student: $student_id (Session: $sessionPrefix)");
        error_log("ID Picture pattern: $idPicturePattern");
        error_log("ID Picture files found: " . count($idTempFiles));
        
        if (!empty($idTempFiles)) {
            foreach ($idTempFiles as $idTempFile) {
                $idExtension = pathinfo($idTempFile, PATHINFO_EXTENSION);
                $idNewFilename = $student_id . '_id_' . time() . '.' . $idExtension;
                $idTempPath = $tempIDPictureDir . DIRECTORY_SEPARATOR . $idNewFilename;
                
                // Copy file with new student ID-based name
                if (@copy($idTempFile, $idTempPath)) {
                    // Copy and UPDATE verification JSON with new paths
                    if (file_exists($idTempFile . '.verify.json')) {
                        updateVerificationJsonPaths(
                            $idTempFile . '.verify.json',
                            $idTempPath . '.verify.json',
                            $idTempFile,
                            $idTempPath
                        );
                        @unlink($idTempFile . '.verify.json'); // Delete old file
                    }
                    // Copy associated OCR files to new filename (.ocr.txt, .tsv)
                    if (file_exists($idTempFile . '.ocr.txt')) {
                        @copy($idTempFile . '.ocr.txt', $idTempPath . '.ocr.txt');
                        @unlink($idTempFile . '.ocr.txt'); // Delete old file
                    }
                    if (file_exists($idTempFile . '.tsv')) {
                        @copy($idTempFile . '.tsv', $idTempPath . '.tsv');
                        @unlink($idTempFile . '.tsv'); // Delete old file
                    }
                    
                    // Rename confidence file (session-based → student ID-based)
                    renameConfidenceFile('id_picture', $sessionPrefix, $idTempPath, $tempIDPictureDir);
                    
                    // Get OCR data from .verify.json file (not *_confidence.json)
                    $idVerifyFile = $idTempPath . '.verify.json';
                    $idOcrData = ['ocr_confidence' => 0, 'verification_status' => 'pending'];
                    
                    if (file_exists($idVerifyFile)) {
                        $verifyData = json_decode(file_get_contents($idVerifyFile), true);
                        if ($verifyData) {
                            // Extract OCR confidence from TSV quality data
                            $ocrConf = $verifyData['tsv_quality']['avg_confidence'] ?? 0;
                            $verifScore = $verifyData['verification']['summary']['average_confidence'] ?? 0;
                            
                            $idOcrData = [
                                'ocr_confidence' => $ocrConf,
                                'verification_score' => $verifScore,
                                'verification_status' => $verifScore >= 70 ? 'passed' : 'manual_review',
                                'verification_details' => $verifyData // Store full verification results including checks
                            ];
                        }
                    }
                    
                    // Save using UnifiedFileService
                    $saveResult = $fileService->saveDocument($student_id, 'id_picture', $idTempPath, $idOcrData);
                    
                    if ($saveResult['success']) {
                        error_log("UnifiedFileService: Saved ID Picture - " . $saveResult['document_id']);
                    } else {
                        error_log("UnifiedFileService: Failed to save ID Picture - " . ($saveResult['error'] ?? 'Unknown error'));
                    }
                    
                    // Clean up original temp file (main image file)
                    @unlink($idTempFile);
                    break;
                }
            }
        } else {
            error_log("No ID Picture temp files found");
        }

        // === SAVE ENROLLMENT FORM (EAF) USING UnifiedFileService ===
        // tempEnrollmentDir already defined above with Railway volume support
        
        // Look for session-based enrollment form (LastName_FirstName_EAF pattern)
        $eafPattern = $tempEnrollmentDir . DIRECTORY_SEPARATOR . '*_EAF.*';
        $tempFiles = glob($eafPattern);
        
        // Filter to get only actual image files (PDF not supported)
        $tempFiles = array_filter($tempFiles, function($file) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            return in_array($ext, ['jpg', 'jpeg', 'png']) && is_file($file);
        });
        
        error_log("Processing EAF for student: $student_id (Session: $sessionPrefix)");
        error_log("EAF Pattern: $eafPattern");
        error_log("Found " . count($tempFiles) . " EAF files");
        
        if (!empty($tempFiles)) {
            foreach ($tempFiles as $tempFile) {
                error_log("Processing EAF temp file: $tempFile");
                
                $extension = pathinfo($tempFile, PATHINFO_EXTENSION);
                $newFilename = $student_id . '_' . $namePrefix . '_eaf.' . $extension;
                $tempEnrollmentPath = $tempEnrollmentDir . DIRECTORY_SEPARATOR . $newFilename;
                
                error_log("EAF new path will be: $tempEnrollmentPath");
                
                if (copy($tempFile, $tempEnrollmentPath)) {
                    error_log("EAF main file copied successfully");
                    
                    // Copy and UPDATE verification JSON with new paths
                    $oldVerifyPath = $tempFile . '.verify.json';
                    $newVerifyPath = $tempEnrollmentPath . '.verify.json';
                    error_log("Checking for verify.json at: $oldVerifyPath");
                    
                    if (file_exists($oldVerifyPath)) {
                        error_log("Found verify.json, calling updateVerificationJsonPaths");
                        $updateResult = updateVerificationJsonPaths(
                            $oldVerifyPath,
                            $newVerifyPath,
                            $tempFile,
                            $tempEnrollmentPath
                        );
                        error_log("updateVerificationJsonPaths result: " . ($updateResult ? 'SUCCESS' : 'FAILED'));
                        @unlink($oldVerifyPath);
                        error_log("Deleted old verify.json: $oldVerifyPath");
                    } else {
                        error_log("WARNING: verify.json NOT FOUND at: $oldVerifyPath");
                    }
                    // Copy associated OCR files (.ocr.txt, .tsv)
                    if (file_exists($tempFile . '.ocr.txt')) {
                        @copy($tempFile . '.ocr.txt', $tempEnrollmentPath . '.ocr.txt');
                        @unlink($tempFile . '.ocr.txt');
                    }
                    if (file_exists($tempFile . '.tsv')) {
                        @copy($tempFile . '.tsv', $tempEnrollmentPath . '.tsv');
                        @unlink($tempFile . '.tsv');
                    }
                    
                    // Rename confidence file (session-based → student ID-based)
                    renameConfidenceFile('eaf', $sessionPrefix, $tempEnrollmentPath, $tempEnrollmentDir);
                    
                    // Get OCR data from .verify.json file (not *_confidence.json)
                    $eafVerifyFile = $tempEnrollmentPath . '.verify.json';
                    $eafOcrData = ['ocr_confidence' => 75.0, 'verification_status' => 'pending'];
                    
                    if (file_exists($eafVerifyFile)) {
                        $verifyData = json_decode(file_get_contents($eafVerifyFile), true);
                        if ($verifyData) {
                            // Extract OCR confidence from TSV quality data
                            $ocrConf = $verifyData['tsv_quality']['avg_confidence'] ?? 75.0;
                            $verifScore = $verifyData['verification']['summary']['average_confidence'] ?? 75.0;
                            
                            $eafOcrData = [
                                'ocr_confidence' => $ocrConf,
                                'verification_score' => $verifScore,
                                'verification_status' => $verifScore >= 70 ? 'passed' : 'manual_review',
                                'verification_details' => $verifyData // Store full verification results including checks
                            ];
                        }
                    }
                    
                    // Save using UnifiedFileService
                    $saveResult = $fileService->saveDocument($student_id, 'eaf', $tempEnrollmentPath, $eafOcrData);
                    
                    if ($saveResult['success']) {
                        error_log("UnifiedFileService: Saved EAF - " . $saveResult['document_id']);
                    } else {
                        error_log("UnifiedFileService: Failed to save EAF - " . ($saveResult['error'] ?? 'Unknown error'));
                    }
                    
                    @unlink($tempFile);
                    break;
                }
            }
        }

        // === SAVE LETTER TO MAYOR USING UnifiedFileService ===
        // tempLetterDir already defined above with Railway volume support
        
        // Look for session-based letter file
        $letterPattern = $tempLetterDir . DIRECTORY_SEPARATOR . $sessionPrefix . '_Letter to mayor.*';
        $letterTempFiles = glob($letterPattern);
        
        // Filter to get only actual image files (PDF not supported)
        $letterTempFiles = array_filter($letterTempFiles, function($file) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            return in_array($ext, ['jpg', 'jpeg', 'png']) && is_file($file);
        });
        
        error_log("Processing Letter to Mayor for student: $student_id (Session: $sessionPrefix)");
        error_log("Letter pattern: $letterPattern");
        error_log("Letter files found: " . count($letterTempFiles));
        
        if (!empty($letterTempFiles)) {
            foreach ($letterTempFiles as $letterTempFile) {
                $letterExtension = pathinfo($letterTempFile, PATHINFO_EXTENSION);
                $newLetterFilename = $student_id . '_' . $namePrefix . '_lettertomayor.' . $letterExtension;
                $letterTempPath = $tempLetterDir . DIRECTORY_SEPARATOR . $newLetterFilename;

                if (copy($letterTempFile, $letterTempPath)) {
                    // Copy and UPDATE verification JSON with new paths
                    if (file_exists($letterTempFile . '.verify.json')) {
                        updateVerificationJsonPaths(
                            $letterTempFile . '.verify.json',
                            $letterTempPath . '.verify.json',
                            $letterTempFile,
                            $letterTempPath
                        );
                        @unlink($letterTempFile . '.verify.json');
                    }
                    // Copy associated OCR files (.ocr.txt, .tsv)
                    if (file_exists($letterTempFile . '.ocr.txt')) {
                        @copy($letterTempFile . '.ocr.txt', $letterTempPath . '.ocr.txt');
                        @unlink($letterTempFile . '.ocr.txt');
                    }
                    if (file_exists($letterTempFile . '.tsv')) {
                        @copy($letterTempFile . '.tsv', $letterTempPath . '.tsv');
                        @unlink($letterTempFile . '.tsv');
                    }
                    
                    // Rename confidence file (session-based → student ID-based)
                    renameConfidenceFile('letter_to_mayor', $sessionPrefix, $letterTempPath, $tempLetterDir);
                    
                    // Get OCR data from .verify.json file (not *_confidence.json)
                    $letterVerifyFile = $letterTempPath . '.verify.json';
                    $letterOcrData = ['ocr_confidence' => 75.0, 'verification_status' => 'pending'];
                    
                    if (file_exists($letterVerifyFile)) {
                        $verifyData = json_decode(file_get_contents($letterVerifyFile), true);
                        if ($verifyData) {
                            // Extract OCR confidence from TSV quality data
                            $ocrConf = $verifyData['tsv_quality']['avg_confidence'] ?? 75.0;
                            $verifScore = $verifyData['verification']['summary']['average_confidence'] ?? 75.0;
                            
                            $letterOcrData = [
                                'ocr_confidence' => $ocrConf,
                                'verification_score' => $verifScore,
                                'verification_status' => $verifScore >= 70 ? 'passed' : 'manual_review',
                                'verification_details' => $verifyData // Store full verification results including checks
                            ];
                        }
                    }
                    
                    // Save using UnifiedFileService
                    $saveResult = $fileService->saveDocument($student_id, 'letter_to_mayor', $letterTempPath, $letterOcrData);
                    
                    if ($saveResult['success']) {
                        error_log("UnifiedFileService: Saved Letter - " . $saveResult['document_id']);
                    } else {
                        error_log("UnifiedFileService: Failed to save Letter - " . ($saveResult['error'] ?? 'Unknown error'));
                    }

                    @unlink($letterTempFile);
                    break;
                }
            }
        }

        // === SAVE CERTIFICATE OF INDIGENCY USING UnifiedFileService ===
        // tempIndigencyDir already defined above with Railway volume support
        
        // Look for session-based certificate file
        $certificatePattern = $tempIndigencyDir . DIRECTORY_SEPARATOR . $sessionPrefix . '_Indigency.*';
        $certificateTempFiles = glob($certificatePattern);
        
        // Filter to get only actual image files (PDF not supported)
        $certificateTempFiles = array_filter($certificateTempFiles, function($file) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            return in_array($ext, ['jpg', 'jpeg', 'png']) && is_file($file);
        });
        
        error_log("Processing Certificate of Indigency for student: $student_id (Session: $sessionPrefix)");
        error_log("Certificate pattern: $certificatePattern");
        error_log("Certificate files found: " . count($certificateTempFiles));
        
        if (!empty($certificateTempFiles)) {
            foreach ($certificateTempFiles as $certificateTempFile) {
                $certificateExtension = pathinfo($certificateTempFile, PATHINFO_EXTENSION);
                $newCertificateFilename = $student_id . '_' . $namePrefix . '_indigency.' . $certificateExtension;
                $certificateTempPath = $tempIndigencyDir . DIRECTORY_SEPARATOR . $newCertificateFilename;

                if (copy($certificateTempFile, $certificateTempPath)) {
                    // Copy and UPDATE verification JSON with new paths
                    if (file_exists($certificateTempFile . '.verify.json')) {
                        updateVerificationJsonPaths(
                            $certificateTempFile . '.verify.json',
                            $certificateTempPath . '.verify.json',
                            $certificateTempFile,
                            $certificateTempPath
                        );
                        @unlink($certificateTempFile . '.verify.json');
                    }
                    // Copy associated OCR files (.ocr.txt, .tsv)
                    if (file_exists($certificateTempFile . '.ocr.txt')) {
                        @copy($certificateTempFile . '.ocr.txt', $certificateTempPath . '.ocr.txt');
                        @unlink($certificateTempFile . '.ocr.txt');
                    }
                    if (file_exists($certificateTempFile . '.tsv')) {
                        @copy($certificateTempFile . '.tsv', $certificateTempPath . '.tsv');
                        @unlink($certificateTempFile . '.tsv');
                    }
                    
                    // Rename confidence file (session-based → student ID-based)
                    renameConfidenceFile('indigency', $sessionPrefix, $certificateTempPath, $tempIndigencyDir);
                    
                    // Get OCR data from .verify.json file (not *_confidence.json)
                    $certVerifyFile = $certificateTempPath . '.verify.json';
                    $certOcrData = ['ocr_confidence' => 75.0, 'verification_status' => 'pending'];
                    
                    if (file_exists($certVerifyFile)) {
                        $verifyData = json_decode(file_get_contents($certVerifyFile), true);
                        if ($verifyData) {
                            // Extract OCR confidence from TSV quality data
                            $ocrConf = $verifyData['tsv_quality']['avg_confidence'] ?? 75.0;
                            $verifScore = $verifyData['verification']['summary']['average_confidence'] ?? 75.0;
                            
                            $certOcrData = [
                                'ocr_confidence' => $ocrConf,
                                'verification_score' => $verifScore,
                                'verification_status' => $verifScore >= 70 ? 'passed' : 'manual_review',
                                'verification_details' => $verifyData // Store full verification results including checks
                            ];
                        }
                    }
                    
                    // Save using UnifiedFileService
                    $saveResult = $fileService->saveDocument($student_id, 'certificate_of_indigency', $certificateTempPath, $certOcrData);
                    
                    if ($saveResult['success']) {
                        error_log("UnifiedFileService: Saved Certificate - " . $saveResult['document_id']);
                    } else {
                        error_log("UnifiedFileService: Failed to save Certificate - " . ($saveResult['error'] ?? 'Unknown error'));
                    }

                    @unlink($certificateTempFile);
                    break;
                }
            }
        }

        // === SAVE GRADES USING UnifiedFileService ===
        // tempGradesDir already defined above with Railway volume support
        
        // Look for session-based grades file
        $gradesPattern = $tempGradesDir . DIRECTORY_SEPARATOR . $sessionPrefix . '_Grades.*';
        $gradesTempFiles = glob($gradesPattern);
        
        // Filter to get only actual image files (PDF not supported)
        $gradesTempFiles = array_filter($gradesTempFiles, function($file) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            return in_array($ext, ['jpg', 'jpeg', 'png']) && is_file($file);
        });
        
        error_log("Processing Grades for student: $student_id (Session: $sessionPrefix)");
        error_log("Grades pattern: $gradesPattern");
        error_log("Grades files found: " . count($gradesTempFiles));
        
        if (!empty($gradesTempFiles)) {
            foreach ($gradesTempFiles as $gradesTempFile) {
                $gradesExtension = pathinfo($gradesTempFile, PATHINFO_EXTENSION);
                $newGradesFilename = $student_id . '_' . $namePrefix . '_grades.' . $gradesExtension;
                $gradesTempPath = $tempGradesDir . DIRECTORY_SEPARATOR . $newGradesFilename;

                if (copy($gradesTempFile, $gradesTempPath)) {
                    // Copy and UPDATE verification JSON with new paths
                    if (file_exists($gradesTempFile . '.verify.json')) {
                        updateVerificationJsonPaths(
                            $gradesTempFile . '.verify.json',
                            $gradesTempPath . '.verify.json',
                            $gradesTempFile,
                            $gradesTempPath
                        );
                        @unlink($gradesTempFile . '.verify.json'); // Delete after copying
                    }
                    // Copy associated OCR files (.ocr.txt, .tsv)
                    if (file_exists($gradesTempFile . '.ocr.txt')) {
                        @copy($gradesTempFile . '.ocr.txt', $gradesTempPath . '.ocr.txt');
                        @unlink($gradesTempFile . '.ocr.txt'); // Delete after copying
                    }
                    if (file_exists($gradesTempFile . '.tsv')) {
                        @copy($gradesTempFile . '.tsv', $gradesTempPath . '.tsv');
                        @unlink($gradesTempFile . '.tsv'); // Delete after copying
                    }
                    
                    // Rename confidence file (session-based → student ID-based)
                    renameConfidenceFile('grades', $sessionPrefix, $gradesTempPath, $tempGradesDir);
                    
                    // Get OCR data from .verify.json file (not *_confidence.json)
                    $gradesVerifyFile = $gradesTempPath . '.verify.json';
                    $gradesOcrData = ['ocr_confidence' => 75.0, 'verification_status' => 'pending'];
                    
                    if (file_exists($gradesVerifyFile)) {
                        $verifyData = json_decode(file_get_contents($gradesVerifyFile), true);
                        if ($verifyData) {
                            // Grades has different structure - extracted_data instead of verification
                            $ocrConf = $verifyData['tsv_quality']['avg_confidence'] ?? 75.0;
                            $verifScore = $verifyData['summary']['average_confidence'] ?? 75.0;
                            
                            $gradesOcrData = [
                                'ocr_confidence' => $ocrConf,
                                'verification_score' => $verifScore,
                                'verification_status' => $verifScore >= 70 ? 'passed' : 'manual_review',
                                'verification_details' => $verifyData, // Store full verification results
                                'extracted_grades' => $verifyData['extracted_grades'] ?? [],
                                'average_grade' => $verifyData['average_grade'] ?? null,
                                'passing_status' => $verifyData['passing_status'] ?? false
                            ];
                        }
                    }
                    
                    // Save using UnifiedFileService
                    $saveResult = $fileService->saveDocument($student_id, 'academic_grades', $gradesTempPath, $gradesOcrData);
                    
                    if ($saveResult['success']) {
                        error_log("UnifiedFileService: Saved Grades - " . $saveResult['document_id']);
                    } else {
                        error_log("UnifiedFileService: Failed to save Grades - " . ($saveResult['error'] ?? 'Unknown error'));
                    }

                    @unlink($gradesTempFile);
                    break;
                }
            }
        }

        // Note: semester and academic_year are stored in signup_slots table via students.slot_id relationship
        // No need to duplicate this data in a separate applications table

        // Calculate and store confidence score for the new registration
        $confidenceQuery = "UPDATE students SET confidence_score = calculate_confidence_score(student_id) WHERE student_id = $1";
        $confidenceResult = pg_query_params($connection, $confidenceQuery, [$student_id]);
        
        if ($confidenceResult) {
            // Get the calculated confidence score for logging
            $scoreQuery = "SELECT confidence_score FROM students WHERE student_id = $1";
            $scoreResult = pg_query_params($connection, $scoreQuery, [$student_id]);
            if ($scoreResult) {
                $scoreRow = pg_fetch_assoc($scoreResult);
                $confidence_score = $scoreRow['confidence_score'];
                error_log("Student ID $student_id registered with confidence score: " . number_format($confidence_score, 2) . "%");
            }
        }

        // Clean up any remaining confidence files from all temp directories
        $tempDirs = [
            $tempIDPictureDir,
            $tempEnrollmentDir,
            $tempLetterDir,
            $tempIndigencyDir,
            $tempGradesDir
        ];
        
        foreach ($tempDirs as $tempDir) {
            $confidenceFiles = glob($tempDir . DIRECTORY_SEPARATOR . $sessionPrefix . '*.confidence.json');
            foreach ($confidenceFiles as $file) {
                if (file_exists($file)) {
                    @unlink($file);
                    error_log("Cleaned up confidence file: " . basename($file));
                }
            }
        }

        // Mark bypass token as used if applicable
        if (isset($_SESSION['household_bypass_token']) && isset($_SESSION['household_bypass_attempt_id'])) {
            $markUsedQuery = "UPDATE household_block_attempts 
                              SET bypass_token_used = TRUE, 
                                  bypass_token_used_at = NOW()
                              WHERE attempt_id = $1 AND bypass_token = $2";
            $markUsedResult = pg_query_params($connection, $markUsedQuery, [
                $_SESSION['household_bypass_attempt_id'],
                $_SESSION['household_bypass_token']
            ]);
            
            if ($markUsedResult) {
                error_log("✅ Bypass token marked as used for attempt ID: " . $_SESSION['household_bypass_attempt_id']);
                
                // Log audit trail: Student successfully registered with bypass
                $auditLogger->logEvent(
                    'household_bypass_registration_complete',
                    'household_management',
                    "Student completed registration with bypass token: {$firstName} {$lastName}",
                    [
                        'user_id' => $studentId,
                        'user_type' => 'student',
                        'username' => $email,
                        'status' => 'success',
                        'affected_table' => 'students',
                        'affected_record_id' => $studentId,
                        'metadata' => [
                            'attempt_id' => $_SESSION['household_bypass_attempt_id'],
                            'student_id' => $studentId,
                            'student_name' => $firstName . ' ' . $lastName,
                            'student_email' => $email,
                            'university_id' => $universityId,
                            'bypass_token_used' => true
                        ]
                    ]
                );
            }
            
            // Clear bypass session data
            unset($_SESSION['household_bypass_active']);
            unset($_SESSION['household_bypass_token']);
            unset($_SESSION['household_bypass_attempt_id']);
            unset($_SESSION['prefill_data']);
        }

        // Clear OTP verification
        unset($_SESSION['otp_verified']);
        
        // Mark registration as completed (prevents cleanup on next visit)
        $_SESSION['registration_completed'] = true;
        
        // Clear registration session data (files, prefixes, tracking)
        unset($_SESSION['registration_session_id']);
        unset($_SESSION['registration_start_time']);
        unset($_SESSION['page_load_count']);
        unset($_SESSION['file_prefix']);
        unset($_SESSION['uploaded_files']);
        unset($_SESSION['temp_first_name']);
        unset($_SESSION['temp_last_name']);

        // Return a lightweight success page without forcing an immediate redirect
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=UTF-8');
        }
        echo "<!doctype html><html lang='en'><head><meta charset='utf-8'><meta name='viewport' content='width=device-width, initial-scale=1'>"
           . "<title>Registration Submitted</title>"
           . "<link href='../../assets/css/bootstrap.min.css' rel='stylesheet'></head><body class='bg-light'>"
           . "<div class='container py-5'><div class='mx-auto' style='max-width:720px'>"
           . "<div class='alert alert-success shadow-sm'><h4 class='alert-heading'>Registration submitted successfully!</h4>"
           . "<p>Your application is under review. You will receive an email notification once approved.</p>"
           . "<hr><div class='d-flex gap-2'><a class='btn btn-primary' href='../../unified_login.php'>Go to Login</a>"
           . "<a class='btn btn-outline-secondary' href='student_register.php'>Back to Registration</a></div></div>"
           . "</div></div></body></html>";
        exit;
    } else {
        // Log the PostgreSQL error for debugging
        $error = pg_last_error($connection);
        error_log("Registration Database Error: " . $error);
        echo "<script>alert('Registration failed due to a database error: " . addslashes($error) . "'); window.location.href = window.location.href;</script>";
        exit;
    }
}

// Only output main registration HTML for non-AJAX requests
if (!$isAjaxRequest) {
?>

<!-- Main Registration Content -->
<div class="container py-3">
        <div class="register-card mx-auto p-4 rounded shadow-sm bg-white" style="max-width: 600px;">
            <div class="mb-3">
                <h4 class="mb-0 text-primary">
                    <i class="bi bi-person-plus-fill me-2"></i>Register for EducAid
                </h4>
            </div>
            
            <?php if (isset($_SESSION['bypass_success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i>
                    <strong>Override Approved!</strong><br>
                    <?= htmlspecialchars($_SESSION['bypass_success']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['bypass_success']); ?>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['bypass_error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-x-circle-fill me-2"></i>
                    <strong>Bypass Link Error:</strong><br>
                    <?= htmlspecialchars($_SESSION['bypass_error']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['bypass_error']); ?>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['household_bypass_active']) && $_SESSION['household_bypass_active'] === true): ?>
                <div class="alert alert-info" role="alert" style="border-left: 4px solid #0dcaf0;">
                    <i class="bi bi-shield-check me-2"></i>
                    <strong>Bypass Mode Active</strong><br>
                    <small>You are registering with administrator approval. Household validation is disabled for your account.</small>
                </div>
            <?php endif; ?>
            
            <!-- Simple Step Progress Indicator -->
            <div class="step-progress-text">
                <div class="step-counter">
                    Step <span class="current-step" id="currentStepNumber">1</span> of 10
                </div>
                <div class="step-title" id="currentStepTitle">
                    <i class="bi bi-person-fill"></i>Personal Information
                </div>
                <div class="step-progress-bar-simple">
                    <div class="progress-fill" id="progressFill" style="width: 10%;"></div>
                </div>
            </div>
            
            <form id="multiStepForm" method="POST" autocomplete="off">
                <!-- Hidden Fields -->
                <input type="hidden" id="adminReviewRequiredFlag" name="admin_review_required" value="0" />
                
                <!-- Step 1: Personal Information -->
                <div class="step-panel" id="step-1">
                    <div class="mb-3">
                        <label class="form-label">First Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="first_name" id="firstNameInput" autocomplete="given-name" required />
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Middle Name <span class="text-muted">(Optional)</span></label>
                        <input type="text" class="form-control" name="middle_name" autocomplete="additional-name" />
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Last Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="last_name" id="lastNameInput" autocomplete="family-name" required />
                        
                        <!-- Warning Messages -->
                        <div id="nameDuplicateWarning" class="alert alert-danger mt-2" style="display:none;">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                            <strong>Duplicate Name Detected:</strong>
                            <span id="nameDuplicateMessage"></span>
                        </div>
                        <div id="householdWarning" class="alert alert-warning mt-2" style="display:none;">
                            <i class="bi bi-people-fill me-2"></i>
                            <strong>Household Notice:</strong>
                            <span id="householdMessage"></span>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Extension Name <span class="text-muted">(Optional)</span></label>
                        <select class="form-control" name="extension_name">
                            <option value="">None</option>
                            <option value="Jr.">Jr.</option>
                            <option value="Sr.">Sr.</option>
                            <option value="I">I</option>
                            <option value="II">II</option>
                            <option value="III">III</option>
                            <option value="IV">IV</option>
                            <option value="V">V</option>
                        </select>
                        <small class="form-text text-muted">Select suffix if applicable (Jr., Sr., I, II, etc.)</small>
                    </div>
                    
                    <!-- Mother's Full Name Field (Household Prevention) -->
                    <div class="mb-3">
                        <label class="form-label">
                            Mother's Full Maiden Name <span class="text-danger">*</span>
                            <i class="bi bi-info-circle ms-1" data-bs-toggle="tooltip" data-bs-placement="right" 
                               title="This helps us identify if another student from your household is already registered. Only one student per household can receive assistance."></i>
                        </label>
                        <input type="text" class="form-control" name="mothers_maiden_name" id="mothersMaidenNameInput" 
                               placeholder="e.g., Maria Cruz Santos, Ana Rose Reyes" 
                               pattern="[A-Za-z\s\-]+" 
                               maxlength="100"
                               required />
                        <small class="form-text text-muted">Enter your mother's complete maiden name (first, middle, and last name)</small>
                        
                        <!-- Loading Spinner for Household Check -->
                        <div id="householdCheckSpinner" class="mt-2" style="display:none;">
                            <div class="spinner-border spinner-border-sm text-primary" role="status">
                                <span class="visually-hidden">Checking...</span>
                            </div>
                            <small class="text-muted ms-2">Checking household...</small>
                        </div>
                        
                        <!-- Success Indicator -->
                        <div id="householdCheckSuccess" class="mt-2 text-success" style="display:none;">
                            <i class="bi bi-check-circle-fill me-1"></i>
                            <small>No duplicate household found</small>
                        </div>
                        
                        <!-- Household Block Error -->
                        <div id="householdBlockError" class="alert alert-danger mt-2" style="display:none;">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                            <strong>Cannot Proceed:</strong>
                            <span id="householdBlockMessage"></span>
                        </div>
                    </div>
                    
                    <button type="button" class="btn btn-primary w-100" onclick="nextStep()">Next</button>
                </div>
                <!-- Step 2: Birthdate and Sex -->
                <div class="step-panel d-none" id="step-2">
                    <div class="mb-3">
                        <label class="form-label">Date of Birth <small class="text-muted">(Must be 16 years or older)</small></label>
                        <input type="date" class="form-control" name="bdate" id="bdateInput" autocomplete="bday" 
                               placeholder="mm/dd/yyyy"
                               required />
                        <small class="form-text text-muted">You must be at least 16 years old to register.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label d-block">Gender</label>
                        <div class="form-check form-check-inline">
                            <input type="radio" class="form-check-input" name="sex" value="Male" required />
                            <label class="form-check-label">Male</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input type="radio" class="form-check-input" name="sex" value="Female" required />
                            <label class="form-check-label">Female</label>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Barangay</label>
                        <select name="barangay_id" class="form-select" required>
                            <option value="" disabled selected>Select your barangay</option>
                            <?php
                            $res = pg_query_params($connection, "SELECT barangay_id, name FROM barangays WHERE municipality_id = $1 ORDER BY name ASC", [$municipality_id]);
                            while ($row = pg_fetch_assoc($res)) {
                                echo "<option value='{$row['barangay_id']}'>" . htmlspecialchars($row['name']) . "</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <button type="button" class="btn btn-secondary w-100 mb-2" onclick="prevStep()">Back</button>
                    <button type="button" class="btn btn-primary w-100" onclick="nextStep()">Next</button>
                </div>
                <!-- Step 3: University and Year Level -->
                <div class="step-panel d-none" id="step-3">
                      <div class="mb-3">
                          <label class="form-label">University/College <span class="text-danger">*</span></label>
                          <select name="university_id" class="form-select" id="universitySelect" required>
                              <option value="" disabled selected>Select your university/college</option>
                              <?php
                              $res = pg_query($connection, "SELECT university_id, name FROM universities ORDER BY name ASC");
                              while ($row = pg_fetch_assoc($res)) {
                                  echo "<option value='{$row['university_id']}'>" . htmlspecialchars($row['name']) . "</option>";
                              }
                              ?>
                          </select>
                      </div>
                      
                      <!-- School Student ID Number Field -->
                      <div class="mb-3">
                          <label class="form-label">School Student ID Number <span class="text-danger">*</span></label>
                          <input type="text" class="form-control" name="school_student_id" id="schoolStudentId" required 
                                 placeholder="e.g., 2024-12345" 
                                 pattern="[A-Za-z0-9\-]+"
                                 maxlength="20">
                          <small class="form-text text-muted">
                              <i class="bi bi-info-circle me-1"></i>Enter your official school/university student ID number exactly as shown on your ID card
                          </small>
                          <div id="schoolStudentIdDuplicateWarning" class="alert alert-danger mt-2" style="display: none;">
                              <i class="bi bi-exclamation-triangle me-2"></i>
                              <strong>Warning:</strong> This school student ID is already registered in our system.
                          </div>
                          <div id="schoolStudentIdAvailable" class="alert alert-success mt-2" style="display: none;">
                              <i class="bi bi-check-circle me-2"></i>
                              <strong>Available:</strong> This school student ID is not registered yet.
                          </div>
                      </div>
                      
                      <div class="mb-3">
                          <label class="form-label">Year Level <span class="text-danger">*</span></label>
                          <select name="year_level_id" class="form-select" required>
                              <option value="" disabled selected>Select your year level</option>
                              <?php
                              $res = pg_query($connection, "SELECT year_level_id, name FROM year_levels ORDER BY sort_order ASC");
                              while ($row = pg_fetch_assoc($res)) {
                                  echo "<option value='{$row['year_level_id']}'>" . htmlspecialchars($row['name']) . "</option>";
                              }
                              ?>
                          </select>
                      </div>
                      
                      <button type="button" class="btn btn-secondary w-100 mb-2" onclick="prevStep()">Back</button>
                      <button type="button" class="btn btn-primary w-100" id="nextStep3Btn" onclick="nextStep()">Next</button>
                </div>
                
                <!-- Step 4: Student ID Picture Upload and OCR Verification -->
                <div class="step-panel d-none" id="step-4">
                    
                    <!-- Photo-Taking Guidelines -->
                    <div class="alert alert-info mb-3">
                        <strong><i class="bi bi-camera me-2"></i>Tips for Best Results:</strong>
                        <ul class="mb-0 mt-2 small">
                            <li><strong>Lighting:</strong> Use natural light or bright indoor lighting. Avoid shadows and flash glare.</li>
                            <li><strong>Angle:</strong> Take photo straight-on (not tilted). Place ID flat on a dark, matte surface.</li>
                            <li><strong>Distance:</strong> Fill the frame with your ID card, but don't cut off edges.</li>
                            <li><strong>Focus:</strong> Tap your phone screen on the ID to focus. Text should be sharp and readable.</li>
                            <li><strong>Surface:</strong> Use a non-reflective surface (dark paper/cloth) to reduce glare from lamination.</li>
                            <li><strong>Damaged ID?</strong> If your ID has scratches, try tilting slightly to reduce glare on damaged areas, or ensure good lighting on text.</li>
                        </ul>
                    </div>
                    
                    <!-- Quality Examples -->
                    <div class="row mb-3">
                        <div class="col-6">
                            <div class="card border-success">
                                <div class="card-body text-center py-2">
                                    <span class="text-success"><i class="bi bi-check-circle-fill"></i> GOOD</span>
                                    <p class="small text-muted mb-0">Clear, straight, well-lit</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="card border-danger">
                                <div class="card-body text-center py-2">
                                    <span class="text-danger"><i class="bi bi-x-circle-fill"></i> AVOID</span>
                                    <p class="small text-muted mb-0">Blurry, tilted, shadows</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Upload Student ID Picture</label>
                        <small class="form-text text-muted d-block">
                            Please upload a clear photo of your Student ID<br>
                            <strong>Required content:</strong> Your name and university
                        </small>
                        <input type="file" class="form-control" id="id_picture_file" accept="image/jpeg,image/jpg,image/png" required>
                        <div id="idPictureFilenameError" class="text-danger mt-1" style="display: none;">
                            <small><i class="bi bi-exclamation-triangle me-1"></i>Please upload a valid student ID picture</small>
                        </div>
                        <!-- Image Quality Warning -->
                        <div id="idQualityWarnings" class="d-none mt-2"></div>
                    </div>
                    <div id="idPictureUploadPreview" class="d-none">
                        <div class="mb-3">
                            <label class="form-label">Preview:</label>
                            <div id="idPicturePreviewContainer" class="border rounded p-2" style="max-height: 300px; overflow-y: auto;">
                                <img id="idPicturePreviewImage" class="img-fluid" style="max-width: 100%; display: none;" />
                                <div id="idPicturePdfPreview" class="text-center p-3" style="display: none;">
                                    <i class="bi bi-file-earmark-pdf fs-1 text-danger"></i>
                                    <p>PDF File Selected</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div id="idPictureOcrSection" class="d-none">
                        <div class="mb-3">
                            <button type="button" class="btn btn-info w-100" id="processIdPictureOcrBtn">
                                <i class="bi bi-search me-2"></i>Verify Student ID
                            </button>
                            <small class="text-muted d-block mt-1">
                                <i class="bi bi-info-circle me-1"></i>Click to verify your student ID information
                            </small>
                        </div>
                        <div id="idPictureOcrResults" class="d-none">
                            <div class="mb-3">
                                <label class="form-label">Verification Results:</label>
                                <div class="verification-checklist">
                                    <div class="form-check d-flex justify-content-between align-items-center" id="idpic-check-firstname">
                                        <div>
                                            <i class="bi bi-x-circle text-danger me-2"></i>
                                            <span>First Name Match</span>
                                        </div>
                                        <span class="badge bg-secondary confidence-score" id="idpic-confidence-firstname">0%</span>
                                    </div>
                                    <div class="form-check d-flex justify-content-between align-items-center" id="idpic-check-middlename">
                                        <div>
                                            <i class="bi bi-x-circle text-danger me-2"></i>
                                            <span>Middle Name Match</span>
                                        </div>
                                        <span class="badge bg-secondary confidence-score" id="idpic-confidence-middlename">0%</span>
                                    </div>
                                    <div class="form-check d-flex justify-content-between align-items-center" id="idpic-check-lastname">
                                        <div>
                                            <i class="bi bi-x-circle text-danger me-2"></i>
                                            <span>Last Name Match</span>
                                        </div>
                                        <span class="badge bg-secondary confidence-score" id="idpic-confidence-lastname">0%</span>
                                    </div>
                                    <div class="form-check d-flex justify-content-between align-items-center" id="idpic-check-university">
                                        <div>
                                            <i class="bi bi-x-circle text-danger me-2"></i>
                                            <span>University Match</span>
                                        </div>
                                        <span class="badge bg-secondary confidence-score" id="idpic-confidence-university">0%</span>
                                    </div>
                                    <div class="form-check d-flex justify-content-between align-items-center" id="idpic-check-document">
                                        <div>
                                            <i class="bi bi-x-circle text-danger me-2"></i>
                                            <span>Official Document Keywords</span>
                                        </div>
                                        <span class="badge bg-secondary confidence-score" id="idpic-confidence-document">0%</span>
                                    </div>
                                    <div class="form-check d-flex justify-content-between align-items-center" id="idpic-check-schoolid">
                                        <div>
                                            <i class="bi bi-x-circle text-danger me-2"></i>
                                            <span>School Student ID Number</span>
                                        </div>
                                        <span class="badge bg-secondary confidence-score" id="idpic-confidence-schoolid">0%</span>
                                    </div>
                                </div>
                                <div class="mt-3 p-3 bg-light rounded">
                                    <h6 class="mb-2">Overall Analysis:</h6>
                                    <div class="d-flex justify-content-between">
                                        <span>Average Confidence:</span>
                                        <span class="fw-bold" id="idpic-overall-confidence">0%</span>
                                    </div>
                                    <div class="d-flex justify-content-between">
                                        <span>Passed Checks:</span>
                                        <span class="fw-bold" id="idpic-passed-checks">0/6</span>
                                    </div>
                                    <div class="mt-2">
                                        <small class="text-muted" id="idpic-verification-recommendation">Processing document...</small>
                                    </div>
                                </div>
                            </div>
                            <div id="idPictureOcrFeedback" class="alert alert-warning mt-3" style="display: none;">
                                <strong>Verification Failed:</strong> Please ensure your student ID is clear and contains all required information.
                            </div>
                        </div>
                    </div>
                    <button type="button" class="btn btn-secondary w-100 mb-2" onclick="prevStep()">Back</button>
                    <button type="button" class="btn btn-secondary w-100" id="nextStep4Btn" onclick="nextStep()" disabled>
                        <i class="bi bi-lock me-2"></i>Continue - Verify Document First
                    </button>
                </div>
                
                <!-- Step 5: Enrollment Assessment Form Upload and OCR Verification -->
                <div class="step-panel d-none" id="step-5">
                    <div class="mb-3">
                        <label class="form-label">Upload Enrollment Assessment Form</label>
                        <small class="form-text text-muted d-block">
                            Please upload a clear photo of your Enrollment Assessment Form<br>
                            <!-- Filename format requirement removed - system will auto-rename files -->
                            <em>Accepted formats: JPG, PNG</em>
                        </small>
                        <input type="file" class="form-control" name="enrollment_form" id="enrollmentForm" accept="image/jpeg,image/jpg,image/png" required />
                        <div id="filenameError" class="text-danger mt-1" style="display: none;">
                            <small><i class="bi bi-exclamation-triangle me-1"></i>Please upload a valid image file</small>
                        </div>
                    </div>
                    <div id="uploadPreview" class="d-none">
                        <div class="mb-3">
                            <label class="form-label">Preview:</label>
                            <div id="previewContainer" class="border rounded p-2" style="max-height: 300px; overflow-y: auto;">
                                <img id="previewImage" class="img-fluid" style="max-width: 100%; display: none;" />
                                <div id="pdfPreview" class="text-center p-3" style="display: none;">
                                    <i class="bi bi-file-earmark-pdf fs-1 text-danger"></i>
                                    <p>PDF File Selected</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div id="ocrSection" class="d-none">
                        <div class="mb-3">
                            <button type="button" class="btn btn-info w-100" id="processOcrBtn" disabled>
                                <i class="bi bi-search me-2"></i>Process Document
                            </button>
                            <small class="text-muted d-block mt-1">
                                <i class="bi bi-info-circle me-1"></i>Upload a file to enable document processing
                            </small>
                        </div>
                        <div id="ocrResults" class="d-none">
                            <div class="mb-3">
                                <label class="form-label">Verification Results:</label>
                                <div class="verification-checklist">
                                    <div class="form-check d-flex justify-content-between align-items-center" id="check-firstname">
                                        <div>
                                            <i class="bi bi-x-circle text-danger me-2"></i>
                                            <span>First Name Match</span>
                                        </div>
                                        <span class="badge bg-secondary confidence-score" id="confidence-firstname">0%</span>
                                    </div>
                                    <div class="form-check d-flex justify-content-between align-items-center" id="check-middlename">
                                        <div>
                                            <i class="bi bi-x-circle text-danger me-2"></i>
                                            <span>Middle Name Match</span>
                                        </div>
                                        <span class="badge bg-secondary confidence-score" id="confidence-middlename">0%</span>
                                    </div>
                                    <div class="form-check d-flex justify-content-between align-items-center" id="check-lastname">
                                        <div>
                                            <i class="bi bi-x-circle text-danger me-2"></i>
                                            <span>Last Name Match</span>
                                        </div>
                                        <span class="badge bg-secondary confidence-score" id="confidence-lastname">0%</span>
                                    </div>
                                    <div class="form-check d-flex justify-content-between align-items-center" id="check-yearlevel">
                                        <div>
                                            <i class="bi bi-x-circle text-danger me-2"></i>
                                            <span>Year Level Match</span>
                                        </div>
                                        <span class="badge bg-secondary confidence-score" id="confidence-yearlevel">0%</span>
                                    </div>
                                    <div class="form-check d-flex justify-content-between align-items-center" id="check-university">
                                        <div>
                                            <i class="bi bi-x-circle text-danger me-2"></i>
                                            <span>University Match</span>
                                        </div>
                                        <span class="badge bg-secondary confidence-score" id="confidence-university">0%</span>
                                    </div>
                                    <div class="form-check d-flex justify-content-between align-items-center" id="check-document">
                                        <div>
                                            <i class="bi bi-x-circle text-danger me-2"></i>
                                            <span>Official Document Keywords</span>
                                        </div>
                                        <span class="badge bg-secondary confidence-score" id="confidence-document">0%</span>
                                    </div>
                                </div>
                                <div class="mt-3 p-3 bg-light rounded">
                                    <h6 class="mb-2">Overall Analysis:</h6>
                                    <div class="d-flex justify-content-between">
                                        <span>Average Confidence:</span>
                                        <span class="fw-bold" id="overall-confidence">0%</span>
                                    </div>
                                    <div class="d-flex justify-content-between">
                                        <span>Passed Checks:</span>
                                        <span class="fw-bold" id="passed-checks">0/6</span>
                                    </div>
                                    <div class="mt-2">
                                        <small class="text-muted" id="verification-recommendation">Processing document...</small>
                                    </div>
                                </div>
                            </div>
                            <div id="ocrFeedback" class="alert alert-warning mt-3" style="display: none;">
                                <strong>Verification Failed:</strong> Please ensure your document is clear and contains all required information.
                            </div>
                        </div>
                    </div>
                    
                    <!-- Course/Program Field - Auto-filled from Enrollment Form OCR -->
                    <div class="mb-3">
                        <label class="form-label">
                            Course/Program 
                            <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control" name="course" id="courseField" 
                               placeholder="Enter your course/program (e.g., BS Information Technology)" required>
                        <input type="hidden" name="course_verified" id="courseVerified" value="0">
                        <small class="form-text text-muted">
                            <i class="bi bi-info-circle me-1"></i>Please enter your course or program as shown in your enrollment form.
                        </small>
                    </div>
                    
                    <button type="button" class="btn btn-secondary w-100 mb-2" onclick="prevStep()">Back</button>
                    <button type="button" class="btn btn-secondary w-100" id="nextStep5Btn" onclick="nextStep()" disabled>
                        <i class="bi bi-lock me-2"></i>Continue - Verify Document First
                    </button>
                </div>
                
                <!-- Step 6: Letter to Mayor Upload and OCR Verification -->
                <div class="step-panel d-none" id="step-6">
                    <div class="mb-3">
                        <label class="form-label">Upload Letter to Mayor</label>
                        <small class="form-text text-muted d-block">
                            Please upload a clear photo of your Letter to Mayor<br>
                            <strong>Required content:</strong> Your name, barangay, and "Office of the Mayor" header
                        </small>
                        <input type="file" class="form-control" name="letter_to_mayor" id="letterToMayorForm" accept="image/jpeg,image/jpg,image/png" required />
                        <div id="letterFilenameError" class="text-danger mt-1" style="display: none;">
                            <small><i class="bi bi-exclamation-triangle me-1"></i>Please upload a valid letter to mayor document</small>
                        </div>
                    </div>
                    <div id="letterUploadPreview" class="d-none">
                        <div class="mb-3">
                            <label class="form-label">Preview:</label>
                            <div id="letterPreviewContainer" class="border rounded p-2" style="max-height: 300px; overflow-y: auto;">
                                <img id="letterPreviewImage" class="img-fluid" style="max-width: 100%; display: none;" />
                                <div id="letterPdfPreview" class="text-center p-3" style="display: none;">
                                    <i class="bi bi-file-earmark-pdf fs-1 text-danger"></i>
                                    <p>PDF File Selected</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div id="letterOcrSection" class="d-none">
                        <div class="mb-3">
                            <button type="button" class="btn btn-info w-100" id="processLetterOcrBtn" disabled>
                                <i class="bi bi-search me-2"></i>Verify Letter Content
                            </button>
                            <small class="text-muted d-block mt-1">
                                <i class="bi bi-info-circle me-1"></i>Upload a file to enable verification
                            </small>
                        </div>
                        <div id="letterOcrResults" class="d-none">
                            <div class="mb-3">
                                <label class="form-label">Verification Results:</label>
                                <div class="verification-checklist">
                                    <div class="form-check" id="check-letter-firstname">
                                        <i class="bi bi-x-circle text-danger me-2"></i>
                                        <span>First Name Found</span>
                                    </div>
                                    <div class="form-check" id="check-letter-lastname">
                                        <i class="bi bi-x-circle text-danger me-2"></i>
                                        <span>Last Name Found</span>
                                    </div>
                                    <div class="form-check" id="check-letter-barangay">
                                        <i class="bi bi-x-circle text-danger me-2"></i>
                                        <span>Barangay Match</span>
                                    </div>
                                    <div class="form-check" id="check-letter-header">
                                        <i class="bi bi-x-circle text-danger me-2"></i>
                                        <span>Office of the Mayor Header</span>
                                    </div>
                                    <div class="form-check" id="check-letter-municipality">
                                        <i class="bi bi-x-circle text-danger me-2"></i>
                                        <span><?php echo htmlspecialchars($municipality_name); ?> Municipality</span>
                                    </div>
                                </div>
                            </div>
                            <div id="letterOcrFeedback" class="alert alert-warning mt-3" style="display: none;">
                                <strong>Verification Failed:</strong> Please ensure your letter contains your name, barangay, and "Office of the Mayor" header.
                            </div>
                        </div>
                    </div>
                    <button type="button" class="btn btn-secondary w-100 mb-2" onclick="prevStep()">Back</button>
                    <button type="button" class="btn btn-secondary w-100" id="nextStep6Btn" onclick="nextStep()" disabled>
                        <i class="bi bi-lock me-2"></i>Continue - Verify Document First
                    </button>
                </div>
                
                <!-- Step 7: Certificate of Indigency Upload and OCR Verification -->
                <div class="step-panel d-none" id="step-7">
                    <div class="mb-3">
                        <label class="form-label">Upload Certificate of Indigency</label>
                        <small class="form-text text-muted d-block mb-2">
                            Please upload a clear photo of your Certificate of Indigency<br>
                            <strong>Required elements:</strong> Certificate title, your name, barangay, and "<?php echo htmlspecialchars($municipality_name); ?>"
                        </small>
                        <input type="file" class="form-control" name="certificate_of_indigency" id="certificateForm" accept="image/jpeg,image/jpg,image/png" required />
                        <div class="invalid-feedback" id="certificateError">
                            <small><i class="bi bi-exclamation-triangle me-1"></i>Please upload a valid certificate of indigency document</small>
                        </div>
                    </div>
                    <div id="certificateUploadPreview" class="d-none">
                        <div class="mb-3">
                            <label class="form-label">Preview:</label>
                            <div id="certificatePreviewContainer" class="border rounded p-2" style="max-height: 300px; overflow-y: auto;">
                                <img id="certificatePreviewImage" class="img-fluid" style="max-width: 100%; display: none;" />
                                <div id="certificatePdfPreview" class="text-center p-3" style="display: none;">
                                    <i class="bi bi-file-earmark-pdf fs-1 text-danger"></i>
                                    <p>PDF File Selected</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div id="certificateOcrSection" class="d-none">
                        <div class="mb-3">
                            <button type="button" class="btn btn-info w-100" id="processCertificateOcrBtn" disabled>
                                <i class="bi bi-search me-2"></i>Verify Certificate Content
                            </button>
                            <small class="text-muted d-block mt-1">
                                <i class="bi bi-info-circle me-1"></i>Upload a file to enable verification
                            </small>
                        </div>
                        <div id="certificateOcrResults" class="d-none">
                            <div class="mb-3">
                                <label class="form-label">Verification Results:</label>
                                <div class="verification-checklist">
                                    <div class="form-check" id="check-certificate-title">
                                        <i class="bi bi-x-circle text-danger me-2"></i>
                                        <span>Certificate of Indigency Title</span>
                                    </div>
                                    <div class="form-check" id="check-certificate-firstname">
                                        <i class="bi bi-x-circle text-danger me-2"></i>
                                        <span>First Name Found</span>
                                    </div>
                                    <div class="form-check" id="check-certificate-lastname">
                                        <i class="bi bi-x-circle text-danger me-2"></i>
                                        <span>Last Name Found</span>
                                    </div>
                                    <div class="form-check" id="check-certificate-barangay">
                                        <i class="bi bi-x-circle text-danger me-2"></i>
                                        <span>Barangay Match</span>
                                    </div>
                                    <div class="form-check" id="check-certificate-city">
                                        <i class="bi bi-x-circle text-danger me-2"></i>
                                        <span><?php echo htmlspecialchars($municipality_name); ?> Found</span>
                                    </div>
                                </div>
                            </div>
                            <div id="certificateOcrFeedback" class="alert alert-warning mt-3" style="display: none;">
                                <strong>Verification Failed:</strong> Please ensure the certificate contains your name, barangay, "Certificate of Indigency" title, and "<?php echo htmlspecialchars($municipality_name); ?>".
                            </div>
                        </div>
                    </div>
                    <button type="button" class="btn btn-secondary w-100 mb-2" onclick="prevStep()">Back</button>
                    <button type="button" class="btn btn-secondary w-100" id="nextStep7Btn" onclick="nextStep()" disabled>
                        <i class="bi bi-lock me-2"></i>Continue - Verify Document First
                    </button>
                </div>
                
                <!-- Step 8: Grade Scanning -->
                <div class="step-panel d-none" id="step-8">
                    <div class="mb-3">
                        <label class="form-label">Upload Grades Document</label>
                        <small class="form-text text-muted d-block mb-2">
                            Please upload a clear photo of your grades<br>
                            <strong>Required elements:</strong> Name, School Year, and Subject Grades
                            <br>Note: Grades must not be below 3.00 to proceed
                        </small>
                        <input type="file" class="form-control" name="grades_document" id="gradesForm" accept="image/jpeg,image/jpg,image/png" required />
                        <div class="invalid-feedback" id="gradesError">
                            <small><i class="bi bi-exclamation-triangle me-1"></i>Please upload a valid grades document</small>
                        </div>
                    </div>
                    <div id="gradesUploadPreview" class="d-none">
                        <div class="mb-3">
                            <label class="form-label">Preview:</label>
                            <div id="gradesPreviewContainer" class="border rounded p-2" style="max-height: 300px; overflow-y: auto;">
                                <img id="gradesPreviewImage" class="img-fluid" style="max-width: 100%; display: none;" />
                                <div id="gradesPdfPreview" class="text-center p-3" style="display: none;">
                                    <i class="bi bi-file-earmark-pdf fs-1 text-danger"></i>
                                    <p>PDF File Selected</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div id="gradesOcrSection" class="d-none">
                        <div class="mb-3">
                            <button type="button" class="btn btn-info w-100" id="processGradesOcrBtn" disabled>
                                <i class="bi bi-search me-2"></i>Verify Grades Content
                            </button>
                            <small class="text-muted d-block mt-1">
                                <i class="bi bi-info-circle me-1"></i>Upload a file to enable verification
                            </small>
                        </div>
                        <div id="gradesOcrResults" class="d-none">
                            <div class="mb-3">
                                <label class="form-label">Eligibility Validation Results:</label>
                                <div class="verification-checklist">
                                    <div class="form-check" id="check-grades-name">
                                        <i class="bi bi-x-circle text-danger me-2"></i>
                                        <span>Student Name Match</span>
                                    </div>
                                    <div class="form-check" id="check-grades-year">
                                        <i class="bi bi-x-circle text-danger me-2"></i>
                                        <span>Declared Year Level Match</span>
                                    </div>
                                    <div class="form-check" id="check-grades-semester">
                                        <i class="bi bi-x-circle text-danger me-2"></i>
                                        <span>Admin Required Semester Match</span>
                                    </div>
                                    <div class="form-check d-none" id="check-grades-school-year">
                                        <i class="bi bi-x-circle text-danger me-2"></i>
                                        <span>Admin Required School Year Match (Temporarily Disabled)</span>
                                    </div>
                                    <div class="form-check" id="check-grades-university">
                                        <i class="bi bi-x-circle text-danger me-2"></i>
                                        <span>University Match</span>
                                    </div>
                                    <div class="form-check" id="check-grades-student-id">
                                        <i class="bi bi-x-circle text-danger me-2"></i>
                                        <span>School Student ID Match</span>
                                    </div>
                                    <div class="form-check" id="check-grades-passing">
                                        <i class="bi bi-x-circle text-danger me-2"></i>
                                        <span>All Grades Below 3.00</span>
                                    </div>
                                </div>
                                <div class="mt-3">
                                    <div class="alert alert-info" id="eligibilityStatus">
                                        <strong><i class="bi bi-info-circle me-2"></i>Eligibility Status:</strong>
                                        <span id="eligibilityText">Pending validation...</span>
                                    </div>
                                </div>
                            </div>
                            <div id="gradesDetails" class="mt-3 d-none">
                                <h6>Detected Grades:</h6>
                                <div id="gradesTable" class="table-responsive">
                                    <!-- Grades will be inserted here -->
                                </div>
                            </div>
                            <div id="gradesOcrFeedback" class="alert alert-warning mt-3" style="display: none;">
                                <strong>Verification Failed:</strong> Please ensure your grades document is clear and contains your name, year level, and passing grades.
                            </div>
                        </div>
                    </div>
                    <button type="button" class="btn btn-secondary w-100 mb-2" onclick="prevStep()">Back</button>
                    <button type="button" class="btn btn-secondary w-100" id="nextStep8Btn" onclick="nextStep()" disabled>
                        <i class="bi bi-lock me-2"></i>Continue - Verify Document First
                    </button>
                </div>
                
                
                <!-- Step 9: OTP Verification -->
                <div class="step-panel d-none" id="step-9">
                      <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" name="email" id="emailInput" required />
                        <small class="form-text text-muted">We'll check if this email is already registered</small>
                        <div id="emailDuplicateWarning" class="alert alert-danger mt-2" style="display: none;">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            <strong>Email Already Registered!</strong> This email address is already registered in our system. Please use a different email address.
                        </div>
                        <span id="emailStatus" class="text-success d-none">Verified</span>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="mobile">Phone Number</label>
                        <input type="tel" class="form-control" name="mobile" id="mobile" maxlength="11" pattern="09[0-9]{9}" placeholder="e.g., 09123456789" required />
                        <!-- Mobile duplicate warning (hidden by default, shown via JavaScript) -->
                        <div id="mobileDuplicateWarning" class="alert alert-warning mt-2" style="display: none;">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            <strong>Mobile Number Already Registered!</strong> This mobile number is already registered in our system. Please use a different mobile number.
                        </div>
                    </div>
                    <div class="mb-3">
                        <button type="button" class="btn btn-info" id="sendOtpBtn">Send OTP (Email)</button>
                    </div>
                    <div id="otpSection" class="d-none mt-3">
                        <div class="alert alert-info">
                            <i class="bi bi-envelope-check me-2"></i>
                            <strong>OTP Sent!</strong> We've sent a 6-digit verification code to your email. Please check your inbox and spam folder.
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="otp">Enter 6-Digit OTP Code</label>
                            <input type="text" class="form-control text-center" name="otp" id="otp" maxlength="6" pattern="[0-9]{6}" placeholder="123456" required style="font-size: 1.2em; letter-spacing: 0.2em;" />
                            <small class="form-text text-muted">Enter the 6-digit code sent to your email</small>
                        </div>
                        <button type="button" class="btn btn-success w-100 mb-3" id="verifyOtpBtn">
                            <i class="bi bi-shield-check me-2"></i>Verify OTP
                        </button>
                        <div class="d-flex justify-content-between align-items-center">
                            <div id="otpTimer" class="text-muted small"></div>
                            <button type="button" class="btn btn-outline-warning btn-sm" id="resendOtpBtn" style="display:none;" disabled>
                                <i class="bi bi-arrow-clockwise me-2"></i>Resend OTP
                            </button>
                        </div>
                    </div>
                    <button type="button" class="btn btn-secondary w-100 mb-2" onclick="prevStep()">Back</button>
                    <button type="button" class="btn btn-primary w-100" id="nextStep9Btn" onclick="nextStep()">Next</button>
                </div>
                <!-- Step 10: Password and Confirmation -->
                <div class="step-panel d-none" id="step-10">
                    <div class="mb-3">
                        <label class="form-label" for="password">Password</label>
                        <div class="input-group">
                            <input type="password" class="form-control" name="password" id="password" minlength="12" required />
                            <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('password', 'passwordIcon')">
                                <i class="bi bi-eye" id="passwordIcon"></i>
                            </button>
                        </div>
                    </div>
                    <div class="form-text mb-2">
                        Must be at least 12 characters long with uppercase, lowercase, numbers, and special characters.
                    </div>
                    <!-- Password Strength Indicator -->
                    <div class="mb-3">
                        <label class="form-label">Password Strength</label>
                        <div class="progress" style="height: 25px;">
                            <div id="strengthBar" class="progress-bar" role="progressbar" style="width: 0%; transition: width 0.3s ease, background-color 0.3s ease;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
                        </div>
                        <small id="strengthText" class="text-muted d-block mt-1"></small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="confirmPassword">Confirm Password</label>
                        <div class="input-group">
                            <input type="password" class="form-control" name="confirm_password" id="confirmPassword" minlength="12" required />
                            <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('confirmPassword', 'confirmPasswordIcon')">
                                <i class="bi bi-eye" id="confirmPasswordIcon"></i>
                            </button>
                        </div>
                        <small id="passwordMatchText" class="d-block mt-1"></small>
                    </div>
                    <!-- Terms and Conditions -->
                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" name="agree_terms" id="agreeTerms" required readonly />
                        <label class="form-check-label" for="agreeTerms">
                            I agree to the 
                            <a href="#" class="text-primary" id="termsLink">
                                Terms and Conditions
                            </a>
                            <span class="text-danger">*</span>
                        </label>
                        <small class="d-block text-muted mt-1">Click "Terms and Conditions" to read and accept</small>
                    </div>
                    <!-- Navigation Buttons -->
                    <button type="button" class="btn btn-secondary w-100 mb-2" onclick="prevStep()">Back</button>
                    <button type="submit" name="register" id="submitButton" class="btn btn-success w-100" disabled>Submit</button>
                </div><!-- End Step 10 -->
            </form>
        </div>
    </div>
    
    <div id="notifier" class="notifier" role="alert" aria-live="polite"></div>
    
    <!-- Terms and Conditions Modal CSS -->
    <style>
    /* Ensure modal appears above everything */
    .modal {
        z-index: 1050;
    }
    .modal-backdrop {
        z-index: 1040;
    }
    
    /* Fallback styling for manual modal display */
    .modal.show {
        display: block !important;
    }
    
    .modal-open {
        overflow: hidden;
    }
    
    /* Tablet modal optimization (768px-991px) */
    @media (min-width: 768px) and (max-width: 991.98px) {
        .student-modal-responsive {
            max-width: 600px;
            margin: 2rem auto;
        }
        
        .student-modal-responsive .modal-content {
            border-radius: 1rem;
        }
        
        .student-modal-responsive .modal-header {
            padding: 1.25rem;
        }
        
        .student-modal-responsive .modal-body {
            padding: 1rem 1.25rem;
            max-height: 70vh;
            overflow-y: auto;
        }
        
        .student-modal-responsive .modal-footer {
            padding: 1rem 1.25rem;
        }
        
        .student-modal-responsive .modal-title {
            font-size: 1.1rem;
        }
        
        .student-modal-responsive .btn {
            font-size: 0.9rem;
            padding: 0.65rem 1.25rem;
        }
    }
    
    /* Mobile modal optimization */
    @media (max-width: 767.98px) {
        .student-modal-responsive {
            max-width: 90%;
            margin: 1.5rem auto;
        }
        
        .student-modal-responsive .modal-content {
            border-radius: 1rem;
        }
        
        .student-modal-responsive .modal-header {
            padding: 1rem;
        }
        
        .student-modal-responsive .modal-body {
            padding: 0.85rem 1rem;
            max-height: 60vh;
            overflow-y: auto;
        }
        
        .student-modal-responsive .modal-footer {
            padding: 0.75rem 1rem;
            flex-direction: column;
            gap: 0.5rem;
        }
        
        .student-modal-responsive .modal-footer .btn {
            width: 100%;
        }
        
        .student-modal-responsive .modal-title {
            font-size: 1rem;
        }
        
        .student-modal-responsive .btn {
            font-size: 0.875rem;
            padding: 0.6rem 1rem;
        }
    }
    
    /* Custom terms content styling */
    .terms-content h6 {
        color: #0d6efd;
        font-weight: 600;
        margin-top: 1.5rem;
        margin-bottom: 0.75rem;
    }
    
    .terms-content ul {
        margin-bottom: 1rem;
    }
    
    .terms-content li {
        margin-bottom: 0.25rem;
    }
    </style>
    
    <!-- Bootstrap JavaScript -->
    <script src="../../assets/js/bootstrap.bundle.min.js"></script>
    <!-- Consistent mobile navbar behavior (burger → X, body.navbar-open) -->
    <script src="../../assets/js/website/mobile-navbar.js"></script>

<!-- ✅ Load user_registration.js BEFORE inline scripts to prevent conflicts -->
<script src="../../assets/js/student/user_registration.js?v=<?php echo time(); ?>"></script>

<!-- Immediate function definitions for onclick handlers -->
<script>
// ============================================
// NAVIGATION FUNCTIONS - DEFINED FIRST FOR ONCLICK HANDLERS
// ============================================

// (Duplicate navigation function block removed - single source defined earlier)
console.log('🔍 Verification - window.nextStep type:', typeof window.nextStep);
console.log('🔍 Verification - Is it the stub?', window.nextStep.toString().includes('stub'));

// ============================================
// OTHER HELPER FUNCTIONS
// ============================================

// Password visibility toggle function
// Disable/enable all Back buttons during document processing to prevent state glitches
function setBackButtonsDisabled(disabled) {
    try {
        const backButtons = document.querySelectorAll('button[onclick="prevStep()"]');
        backButtons.forEach(btn => {
            btn.disabled = !!disabled;
            if (disabled) {
                btn.classList.add('disabled');
            } else {
                btn.classList.remove('disabled');
            }
        });
        window.isProcessingDocument = !!disabled;
    } catch (e) {
        console.warn('setBackButtonsDisabled error:', e);
    }
}
function togglePasswordVisibility(fieldId, iconId) {
    const field = document.getElementById(fieldId);
    const icon = document.getElementById(iconId);
    
    if (field.type === 'password') {
        field.type = 'text';
        icon.classList.remove('bi-eye');
        icon.classList.add('bi-eye-slash');
    } else {
        field.type = 'password';
        icon.classList.remove('bi-eye-slash');
        icon.classList.add('bi-eye');
    }
}

// ============================================
// INLINE-ONLY VARIABLES (not in user_registration.js)
// ============================================
// DO NOT redefine currentStep here - it's already in user_registration.js!
let hasNameDuplicate = false; // Track if name duplicate was detected
let hasHouseholdWarning = false; // Track if household warning is active
let householdConfirmed = false; // Track if user confirmed different household

// Check for duplicate name before proceeding from Step 1
async function checkNameBeforeProceeding() {
    console.log('🔍 Checking for duplicate name...');
    
    const firstName = document.querySelector('input[name="first_name"]')?.value.trim();
    const lastName = document.querySelector('input[name="last_name"]')?.value.trim();
    
    if (!firstName || !lastName) {
        console.log('❌ Missing name fields');
        return false; // Allow if fields empty (normal validation will catch it)
    }
    
    // If name was already flagged as duplicate, always block
    if (hasNameDuplicate) {
        console.log('🚫 Name still flagged as duplicate - blocking');
        showNameDuplicateBlockModal(firstName, lastName, 1);
        return true;
    }
    
    try {
        const formData = new FormData();
        formData.append('action', 'check_name_duplicate');
        formData.append('first_name', firstName);
        formData.append('last_name', lastName);
        
        const response = await fetch('student_register.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.exists && data.count > 0) {
            console.log('⚠️ NAME DUPLICATE FOUND!', data);
            hasNameDuplicate = true; // Set flag
            // Show blocking modal
            showNameDuplicateBlockModal(firstName, lastName, data.count);
            return true; // Block progression
        }
        
        console.log('✅ No name duplicate, allowing progression');
        hasNameDuplicate = false; // Clear flag if no duplicate
        return false; // Allow progression
    } catch (error) {
        console.error('❌ Error checking name duplicate:', error);
        return false; // On error, allow progression
    }
}

// Show name duplicate blocking modal
function showNameDuplicateBlockModal(firstName, lastName, count) {
    const modalHtml = `
        <div class="modal fade" id="nameDuplicateBlockModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
            <div class="modal-dialog modal-dialog-centered student-modal-responsive">
                <div class="modal-content border-danger">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                            Duplicate Name Detected
                        </h5>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-danger mb-3">
                            <i class="bi bi-shield-fill-exclamation me-2"></i>
                            <strong>Registration Not Allowed</strong>
                        </div>
                        <p class="mb-3 lead">
                            <i class="bi bi-person-fill-exclamation me-2"></i>
                            A student with the name <strong>"${firstName} ${lastName}"</strong> is already registered in our system.
                        </p>
                        <div class="alert alert-warning">
                            <i class="bi bi-info-circle me-2"></i>
                            <strong>What to do:</strong>
                            <ul class="mb-0 mt-2">
                                <li>If this is you, please check if you already have an account</li>
                                <li>If you forgot your credentials, use the password reset option</li>
                                <li>If this is a different person with the same name, please contact support</li>
                                <li>For assistance, email us or call our support line</li>
                            </ul>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeNameDuplicateModal()">
                            <i class="bi bi-arrow-left me-2"></i>Go Back
                        </button>
                        <a href="../../unified_login.php" class="btn btn-primary">
                            <i class="bi bi-box-arrow-in-right me-2"></i>Login Instead
                        </a>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    // Remove existing modal if any
    const existingModal = document.getElementById('nameDuplicateBlockModal');
    if (existingModal) {
        existingModal.remove();
    }
    
    // Add modal to body
    document.body.insertAdjacentHTML('beforeend', modalHtml);
    
    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('nameDuplicateBlockModal'));
    modal.show();
}

// Close name duplicate modal
function closeNameDuplicateModal() {
    const modal = bootstrap.Modal.getInstance(document.getElementById('nameDuplicateBlockModal'));
    if (modal) {
        modal.hide();
    }
    // Keep hasNameDuplicate flag - user must change name to clear it
}

// Check household confirmation before proceeding from Step 1
let householdCheckInProgress = false; // Prevent double-submissions
let lastHouseholdCheckTime = 0; // Timestamp of last check

async function checkHouseholdBeforeProceeding() {
    const now = Date.now();
    console.log('🏠 NEW: Checking household duplicate prevention...');
    console.log('🔍 householdCheckInProgress flag:', householdCheckInProgress);
    console.log('⏱️ Time since last check:', now - lastHouseholdCheckTime, 'ms');
    
    // Prevent double-clicking within 2 seconds
    if (householdCheckInProgress || (now - lastHouseholdCheckTime < 2000)) {
        console.log('⏳ Household check already in progress or too soon - skipping duplicate request');
        return true; // Block progression while check is running
    }
    
    // Update timestamp
    lastHouseholdCheckTime = now;
    
    // Check if household block is already active
    if (householdBlockActive) {
        console.log('❌ Household block is active - cannot proceed');
        
        alert('❌ Cannot Proceed\n\nA student from your household is already registered. Please see the error message above for details.');
        
        return true; // Block progression
    }
    
    // Check if all required fields are filled for household check
    const lastNameInput = document.getElementById('lastNameInput');
    const mothersMaidenNameInput = document.getElementById('mothersMaidenNameInput');
    const barangaySelect = document.querySelector('select[name="barangay_id"]'); // Step 2 field
    
    if (!lastNameInput || !mothersMaidenNameInput || !barangaySelect) {
        console.log('⚠️ Household check inputs not found');
        console.log('Last Name:', lastNameInput ? 'Found' : 'Missing');
        console.log('Maiden Name:', mothersMaidenNameInput ? 'Found' : 'Missing');
        console.log('Barangay:', barangaySelect ? 'Found' : 'Missing');
        return false; // Allow progression (shouldn't happen)
    }
    
    const lastName = lastNameInput.value.trim();
    const maidenName = mothersMaidenNameInput.value.trim();
    const barangayId = parseInt(barangaySelect.value);
    
    console.log('🔍 Household check values:', { lastName, maidenName, barangayId });
    
    if (!lastName || !maidenName || !barangayId) {
        console.log('❌ Required fields not filled for household check');
        
        alert('⚠️ Required Information\n\nPlease fill in all required fields, including your mother\'s full name and barangay.');
        
        return true; // Block progression
    }
    
    // CRITICAL: Perform the actual household duplicate check now
    console.log('🔍 Performing household duplicate check before proceeding...');
    
    // Set flag to prevent double-submissions
    householdCheckInProgress = true;
    
    // Get first name from Step 1
    const firstNameInput = document.getElementById('firstNameInput');
    const firstName = firstNameInput ? firstNameInput.value.trim() : '';
    
    try {
        const formData = new FormData();
        formData.append('action', 'check_household_duplicate');
        formData.append('first_name', firstName); // Include first name from Step 1
        formData.append('last_name', lastName);
        formData.append('mothers_maiden_name', maidenName);
        formData.append('barangay_id', barangayId);
        
        const response = await fetch('student_register.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        console.log('🏠 Household check response:', data);
        
        if (data.status === 'bypassed') {
            // BYPASS ACTIVE - Admin override, skip all checks
            householdCheckPassed = true;
            householdBlockActive = false;
            householdCheckInProgress = false;
            
            console.log('✅ BYPASS: Household check skipped - Admin approved override');
            return false; // Allow progression
            
        } else if (data.status === 'blocked') {
            // EXACT MATCH - BLOCK registration
            householdBlockActive = true;
            householdCheckPassed = false;
            
            householdCheckInProgress = false; // Clear flag before returning
            
            // Show modal with contact information option
            showHouseholdBlockedModal(data.student_id, data.student_name, data.attempt_id);
            
            return true; // Block progression
            
        } else if (data.status === 'warning') {
            // FUZZY MATCH - Show warning but allow to proceed
            console.log('⚠️ Fuzzy household match detected - showing warning');
            
            const userConfirmed = confirm('⚠️ Possible Household Match\n\n' +
                                         'We found a similar student record:\n\n' +
                                         'Student ID: ' + data.student_id + '\n' +
                                         'Name: ' + data.student_name + '\n' +
                                         'Similarity: ' + data.similarity_percentage + '\n\n' +
                                         'Are you sure this is a DIFFERENT household?\n\n' +
                                         'Click OK to continue, Cancel to go back.');
            
            householdCheckInProgress = false; // Clear flag before returning
            
            if (!userConfirmed) {
                return true; // Block progression - user clicked "Cancel"
            }
            
            // User confirmed different household - allow progression
            householdCheckPassed = true;
            householdBlockActive = false;
            console.log('✅ User confirmed different household');
            return false; // Allow progression
            
        } else {
            // NO MATCH - Clear progression
            householdCheckPassed = true;
            householdBlockActive = false;
            console.log('✅ No household match - clear to proceed');
            
            householdCheckInProgress = false; // Clear flag before returning
            return false; // Allow progression
        }
        
    } catch (error) {
        console.error('❌ Error checking household duplicate:', error);
        
        householdCheckInProgress = false; // Clear flag on error
        
        // On error, show warning but allow progression (fail-open for UX)
        alert('⚠️ Check Failed\n\nCould not verify household registration. Please ensure all information is correct.');
        
        return false; // Allow progression on error
    }
}

// Show household blocked modal with contact information
function showHouseholdBlockedModal(existingStudentId, existingStudentName, attemptId) {
    const modalHtml = `
        <div class="modal fade" id="householdBlockedModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-danger">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title">
                            <i class="bi bi-shield-x me-2"></i>
                            Household Already Registered
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-danger mb-3">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            <strong>Registration Blocked</strong>
                        </div>
                        
                        <p>A student from your household is already registered in the system:</p>
                        
                        <div class="card bg-light mb-3">
                            <div class="card-body">
                                <p class="mb-1"><strong>Student ID:</strong> ${existingStudentId}</p>
                                <p class="mb-0"><strong>Name:</strong> ${existingStudentName}</p>
                            </div>
                        </div>
                        
                        <p class="text-muted">
                            <small>This system prevents multiple students from the same household from registering to ensure fair distribution.</small>
                        </p>
                        
                        <hr>
                        
                        <h6 class="mb-3"><i class="bi bi-envelope-paper me-2"></i>Request Override</h6>
                        <p class="text-muted small mb-3">
                            If you believe this is an error or have a valid reason for registration, please provide your contact information below. 
                            The admin will review your request.
                        </p>
                        
                        <div class="mb-3">
                            <label for="blockedContactEmail" class="form-label">
                                <i class="bi bi-envelope me-1"></i>Email Address <span class="text-muted">(Optional)</span>
                            </label>
                            <input type="email" class="form-control" id="blockedContactEmail" 
                                   placeholder="your.email@example.com">
                        </div>
                        
                        <div class="mb-3">
                            <label for="blockedContactMobile" class="form-label">
                                <i class="bi bi-phone me-1"></i>Mobile Number <span class="text-muted">(Optional)</span>
                            </label>
                            <input type="tel" class="form-control" id="blockedContactMobile" 
                                   placeholder="09XX XXX XXXX"
                                   maxlength="11"
                                   pattern="09[0-9]{9}"
                                   oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 11)">
                            <small class="text-muted">Format: 09XXXXXXXXX (11 digits)</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="blockedContactReason" class="form-label">
                                <i class="bi bi-chat-left-text me-1"></i>Reason for Override Request <span class="text-muted">(Optional)</span>
                            </label>
                            <textarea class="form-control" id="blockedContactReason" rows="3" 
                                      placeholder="e.g., Different household, sibling at different address, etc."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="bi bi-x-circle me-1"></i>Close
                        </button>
                        <button type="button" class="btn btn-primary" onclick="submitOverrideRequest(${attemptId})">
                            <i class="bi bi-send me-1"></i>Submit Request
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    // Remove existing modal if any
    const existingModal = document.getElementById('householdBlockedModal');
    if (existingModal) {
        existingModal.remove();
    }
    
    // Add modal to page
    document.body.insertAdjacentHTML('beforeend', modalHtml);
    
    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('householdBlockedModal'));
    modal.show();
}

// Submit override request with contact information
async function submitOverrideRequest(attemptId) {
    const email = document.getElementById('blockedContactEmail').value.trim();
    const mobile = document.getElementById('blockedContactMobile').value.trim();
    const reason = document.getElementById('blockedContactReason').value.trim();
    
    // At least one contact method should be provided
    if (!email && !mobile) {
        alert('Please provide at least one contact method (email or mobile number) so the admin can reach you.');
        return;
    }
    
    // Validate mobile number format if provided
    if (mobile && !/^09[0-9]{9}$/.test(mobile)) {
        alert('❌ Invalid Mobile Number\n\nPlease enter a valid Philippine mobile number.\nFormat: 09XXXXXXXXX (11 digits starting with 09)');
        return;
    }
    
    // Validate email format if provided
    if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        alert('❌ Invalid Email Address\n\nPlease enter a valid email address.');
        return;
    }
    
    try {
        const formData = new FormData();
        formData.append('action', 'update_block_contact');
        formData.append('attempt_id', attemptId);
        formData.append('email', email);
        formData.append('mobile', mobile);
        formData.append('reason', reason);
        
        const response = await fetch('student_register.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            // Close the modal
            const modal = bootstrap.Modal.getInstance(document.getElementById('householdBlockedModal'));
            modal.hide();
            
            // Show success message
            alert('✅ Request Submitted\n\nYour override request has been submitted to the admin. They will review your request and contact you if approved.\n\nThank you for your patience.');
        } else {
            alert('❌ Error\n\n' + (data.message || 'Failed to submit request. Please try again.'));
        }
    } catch (error) {
        console.error('Error submitting override request:', error);
        alert('❌ Error\n\nFailed to submit request. Please check your internet connection and try again.');
    }
}

// Show household confirmation modal
async function showHouseholdConfirmationModal(lastName) {
    return new Promise((resolve) => {
        const modalHtml = `
            <div class="modal fade" id="householdConfirmModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content border-warning">
                        <div class="modal-header bg-warning text-dark">
                            <h5 class="modal-title">
                                <i class="bi bi-people-fill me-2"></i>
                                Household Confirmation
                            </h5>
                        </div>
                        <div class="modal-body">
                            <div class="alert alert-warning mb-3">
                                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                                <strong>Student with Same Last Name Found</strong>
                            </div>
                            <p class="mb-3 lead">
                                <i class="bi bi-house-fill me-2"></i>
                                We found student(s) with the last name <strong>"${lastName}"</strong> already registered in our system.
                            </p>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle-fill me-2"></i>
                                <strong>Important:</strong> Only ONE member per household can receive assistance.
                            </div>
                            <p class="mb-0">
                                <strong>Are you from a DIFFERENT household than the existing student(s)?</strong>
                            </p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-danger" id="householdSameBtn">
                                <i class="bi bi-x-circle me-2"></i>No, Same Household
                            </button>
                            <button type="button" class="btn btn-success" id="householdDifferentBtn">
                                <i class="bi bi-check-circle me-2"></i>Yes, Different Household
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        // Remove existing modal if any
        const existingModal = document.getElementById('householdConfirmModal');
        if (existingModal) {
            existingModal.remove();
        }
        
        // Add modal to body
        document.body.insertAdjacentHTML('beforeend', modalHtml);
        
        // Show modal
        const modal = new bootstrap.Modal(document.getElementById('householdConfirmModal'));
        modal.show();
        
        // Handle "Same Household" button
        document.getElementById('householdSameBtn').addEventListener('click', function() {
            householdConfirmed = false;
            modal.hide();
            
            // Show blocking message
            showNotifier('Registration cannot proceed. Only one member per household can receive assistance.', 'error');
            resolve(true); // Block progression
        });
        
        // Handle "Different Household" button
        document.getElementById('householdDifferentBtn').addEventListener('click', function() {
            householdConfirmed = true;
            modal.hide();
            
            // Show success message
            showNotifier('Thank you for confirming. You may proceed with registration.', 'success');
            resolve(false); // Allow progression
        });
    });
}

// Check for duplicate registration (first name, last name, university, school student ID)
async function checkForDuplicateRegistration() {
    console.log('🔍 Starting duplicate check...');
    
    const firstName = document.querySelector('input[name="first_name"]')?.value.trim();
    const lastName = document.querySelector('input[name="last_name"]')?.value.trim();
    const universityId = document.querySelector('select[name="university_id"]')?.value;
    const schoolStudentId = document.querySelector('input[name="school_student_id"]')?.value.trim();
    
    console.log('📋 Form values:', {firstName, lastName, universityId, schoolStudentId});
    
    if (!firstName || !lastName || !universityId || !schoolStudentId) {
        console.log('❌ Missing required fields for duplicate check');
        return false; // Missing data, let normal validation handle it
    }
    
    try {
        const formData = new FormData();
        formData.append('action', 'check_full_duplicate');
        formData.append('first_name', firstName);
        formData.append('last_name', lastName);
        formData.append('university_id', universityId);
        formData.append('school_student_id', schoolStudentId);
        
        console.log('📡 Sending duplicate check request...');
        
        const response = await fetch('student_register.php', {
            method: 'POST',
            body: formData
        });
        
        console.log('📨 Response status:', response.status);
        const text = await response.text();
        console.log('📨 Response text:', text);
        
        const data = JSON.parse(text);
        console.log('📨 Parsed data:', data);
        
        if (data.is_duplicate) {
            console.log('⚠️ DUPLICATE FOUND!', data);
            // Show modal with duplicate warning
            showDuplicateWarningModal(data);
            return true; // Duplicate found, block progression
        }
        
        console.log('✅ No duplicate found, allowing progression');
        return false; // No duplicate, allow progression
    } catch (error) {
        console.error('❌ Error checking for duplicates:', error);
        return false; // On error, allow progression
    }
}

// Show duplicate warning modal
function showDuplicateWarningModal(data) {
    const modalHtml = `
        <div class="modal fade" id="duplicateWarningModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-danger">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                            Duplicate Registration Detected
                        </h5>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-danger mb-3">
                            <i class="bi bi-shield-fill-exclamation me-2"></i>
                            <strong>Registration Not Allowed</strong>
                        </div>
                        <p class="mb-3 lead">
                            <i class="bi bi-person-fill-exclamation me-2"></i>
                            The student information you provided is already registered in our system.
                        </p>
                        <div class="alert alert-warning">
                            <i class="bi bi-info-circle me-2"></i>
                            <strong>Important:</strong> Creating multiple accounts with the same personal information is strictly prohibited 
                            and violates our registration policy. Each student is only allowed one account in the system.
                        </div>
                        <div class="alert alert-info mb-0">
                            <i class="bi bi-question-circle me-2"></i>
                            If you believe this is an error or need assistance, please contact the administrator for support.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="goBackToEdit()">
                            <i class="bi bi-arrow-left me-1"></i> Go Back and Edit
                        </button>
                        <button type="button" class="btn btn-primary" onclick="window.location.href='../../unified_login.php'">
                            <i class="bi bi-box-arrow-right me-1"></i> Return to Login
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    // Remove existing modal if any
    const existingModal = document.getElementById('duplicateWarningModal');
    if (existingModal) {
        existingModal.remove();
    }
    
    // Add modal to body
    document.body.insertAdjacentHTML('beforeend', modalHtml);
    
    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('duplicateWarningModal'));
    modal.show();
}

// Go back to step 1 to edit information
function goBackToEdit() {
    const modal = bootstrap.Modal.getInstance(document.getElementById('duplicateWarningModal'));
    if (modal) modal.hide();
    
    // Go back to step 1
    currentStep = 1;
    showStep(1);
}

// ❌ REMOVED: Duplicate nextStep(), prevStep(), showStep(), updateStepIndicators() definitions
// These functions are now defined at the TOP of the script block (line ~7105)
// so they're available when onclick handlers execute

// ============================================
// VALIDATION FUNCTIONS
// ============================================

function validateCurrentStepFields() {
    const currentPanel = document.getElementById(`step-${currentStep}`);
    if (!currentPanel) return { isValid: true };
    
    // CHECK FOR BYPASS MODE - Skip validation for steps 4-8 (documents) and step 10 (password)
    <?php if (file_exists(__DIR__ . '/../../config/ocr_bypass_config.php')): ?>
    <?php require_once __DIR__ . '/../../config/ocr_bypass_config.php'; ?>
    <?php if (defined('OCR_BYPASS_ENABLED') && OCR_BYPASS_ENABLED === true): ?>
    if (currentStep >= 4 && currentStep <= 8) {
        console.log('⚠️ BYPASS MODE: Skipping document validation for step', currentStep);
        return { isValid: true }; // Allow proceeding without documents
    }
    if (currentStep === 10) {
        console.log('⚠️ BYPASS MODE: Skipping password validation for step 10');
        return { isValid: true }; // Allow submitting without password validation
    }
    <?php endif; ?>
    <?php endif; ?>
    
    const requiredFields = currentPanel.querySelectorAll('input[required], select[required], textarea[required]');
    const emptyFields = [];
    const invalidFields = [];
    
    // STEP 1 VALIDATION: Check critical name fields explicitly
    if (currentStep === 1) {
        const firstNameInput = currentPanel.querySelector('input[name="first_name"]');
        const lastNameInput = currentPanel.querySelector('input[name="last_name"]');
        
        if (firstNameInput && !firstNameInput.value.trim()) {
            emptyFields.push({
                name: 'first_name',
                label: 'First Name',
                field: firstNameInput
            });
        }
        
        if (lastNameInput && !lastNameInput.value.trim()) {
            emptyFields.push({
                name: 'last_name',
                label: 'Last Name',
                field: lastNameInput
            });
        }
    }
    
    requiredFields.forEach(field => {
        const value = field.value.trim();
        
        // Check if field is empty
        if (!value) {
            emptyFields.push({
                field: field,
                name: field.name || field.id || 'Unknown field',
                label: getFieldLabel(field)
            });
            return;
        }
        
        // Specific validations by field type and step
        const validation = validateSpecificField(field, value);
        if (!validation.isValid) {
            invalidFields.push({
                field: field,
                name: field.name || field.id,
                label: getFieldLabel(field),
                error: validation.error
            });
        }
    });
    
    // Check for radio button groups (like gender)
    if (currentStep === 2) {
        // Check if gender is selected
        const genderChecked = currentPanel.querySelector('input[name="sex"]:checked');
        if (!genderChecked) {
            emptyFields.push({
                name: 'sex',
                label: 'Gender',
                field: currentPanel.querySelector('input[name="sex"]')
            });
        }
        
        // Check if birthdate is filled (no default value anymore)
        const bdateInput = currentPanel.querySelector('input[name="bdate"]');
        if (bdateInput && !bdateInput.value) {
            emptyFields.push({
                name: 'bdate',
                label: 'Date of Birth',
                field: bdateInput
            });
        }
        
        // Check if barangay is selected (not the default "Select your barangay" option)
        const barangaySelect = currentPanel.querySelector('select[name="barangay_id"]');
        if (barangaySelect && (!barangaySelect.value || barangaySelect.value === '')) {
            emptyFields.push({
                name: 'barangay_id',
                label: 'Barangay',
                field: barangaySelect
            });
        }
    }
    
    // Special validation for file upload steps
    // Step 4: Student ID Picture
    if (currentStep === 4) {
        const idPictureFile = currentPanel.querySelector('#id_picture_file');
        if (idPictureFile && !idPictureFile.files[0]) {
            emptyFields.push({
                name: 'id_picture',
                label: 'Student ID Picture',
                field: idPictureFile
            });
        }
    }
    
    // Step 5: Enrollment Assessment Form
    if (currentStep === 5) {
        const enrollmentFile = currentPanel.querySelector('#enrollmentForm');
        if (enrollmentFile && !enrollmentFile.files[0]) {
            emptyFields.push({
                name: 'enrollment_form',
                label: 'Enrollment Assessment Form',
                field: enrollmentFile
            });
        } else if (enrollmentFile && enrollmentFile.files[0]) {
            // FILENAME VALIDATION DISABLED - System auto-renames files
            // Students can now upload files with any name
            /*
            // Validate filename format
            const filename = enrollmentFile.files[0].name;
            const namePattern = /^[A-Za-z]+_[A-Za-z]+_EAF\.(jpg|jpeg|png)$/i;
            if (!namePattern.test(filename)) {
                invalidFields.push({
                    field: enrollmentFile,
                    name: 'enrollment_form',
                    label: 'Enrollment Assessment Form',
                    error: 'Filename must follow format: Lastname_Firstname_EAF.jpg (e.g., Santos_Juan_EAF.jpg)'
                });
            }
            */
        }
    }
    
    // Step 6: Letter to Mayor
    if (currentStep === 6) {
        const letterFile = currentPanel.querySelector('#letterToMayorForm');
        const nextBtn = document.getElementById('nextStep6Btn');
        
        if (letterFile && !letterFile.files[0]) {
            emptyFields.push({
                name: 'letter_to_mayor',
                label: 'Letter to Mayor',
                field: letterFile
            });
        } else if (nextBtn && nextBtn.disabled) {
            // Button is still disabled, meaning OCR verification hasn't passed
            return {
                isValid: false,
                message: 'Please process and verify the Letter to Mayor document before continuing.',
                fields: [letterFile]
            };
        }
    }
    
    // Step 7: Certificate of Indigency
    if (currentStep === 7) {
        const certificateFile = currentPanel.querySelector('#certificateForm');
        const nextBtn = document.getElementById('nextStep7Btn');
        
        if (certificateFile && !certificateFile.files[0]) {
            emptyFields.push({
                name: 'certificate_of_indigency',
                label: 'Certificate of Indigency',
                field: certificateFile
            });
        } else if (nextBtn && nextBtn.disabled) {
            // Button is still disabled, meaning OCR verification hasn't passed
            return {
                isValid: false,
                message: 'Please process and verify the Certificate of Indigency document before continuing.',
                fields: [certificateFile]
            };
        }
    }
    
    // Step 8: Grades Document
    if (currentStep === 8) {
        const gradesFile = currentPanel.querySelector('#gradesForm');
        const nextBtn = document.getElementById('nextStep8Btn');
        
        if (gradesFile && !gradesFile.files[0]) {
            emptyFields.push({
                name: 'grades_document',
                label: 'Grades Document',
                field: gradesFile
            });
        } else if (nextBtn && nextBtn.disabled) {
            // Button is still disabled, meaning OCR verification hasn't passed
            return {
                isValid: false,
                message: 'Please process and verify the Grades document before continuing.',
                fields: [gradesFile]
            };
        }
    }
    
    // Step 9: OTP Verification
    if (currentStep === 9) {
        // Cache references (avoid repeated DOM lookups)
        const emailStatus = document.getElementById('emailStatus');
        const otpSection = document.getElementById('otpSection');
        const mobileInput = currentPanel.querySelector('#mobile');
        const otpInput = currentPanel.querySelector('#otp');
        const otpFlag = document.getElementById('otpVerifiedFlag');
        const isOtpVerified = (window.otpVerified === true) || (otpFlag && otpFlag.value === '1');
        
        // Check if phone number is filled
        if (!mobileInput || !mobileInput.value.trim()) {
            return {
                isValid: false,
                message: 'Please enter your phone number.',
                fields: [mobileInput]
            };
        }
        
        // Validate phone number format (09XXXXXXXXX - 11 digits starting with 09)
        const phonePattern = /^09[0-9]{9}$/;
        if (!phonePattern.test(mobileInput.value.trim())) {
            return {
                isValid: false,
                message: 'Phone number must be 11 digits and start with 09 (e.g., 09123456789).',
                fields: [mobileInput]
            };
        }
        
        // Check if email is verified.
        // Accept any of the following as success:
        // 1. emailStatus element is visible (legacy behavior)
        // 2. Hidden flag otpVerifiedFlag exists with value '1'
        // 3. window.otpVerified === true (external script state)
        if (!isOtpVerified && (!emailStatus || emailStatus.classList.contains('d-none'))) {
            // If OTP area still visible user hasn't completed verification
            if (otpSection && !otpSection.classList.contains('d-none')) {
                return {
                    isValid: false,
                    message: 'Please verify your email by entering the OTP code sent to your email.',
                    fields: [otpInput || currentPanel.querySelector('#emailInput')].filter(Boolean)
                };
            }
            return {
                isValid: false,
                message: 'Please send and verify OTP to your email address.',
                fields: [currentPanel.querySelector('#emailInput')].filter(Boolean)
            };
        }
    }
    
    // Step 10: Password Strength Validation
    // ✅ REMOVED DUPLICATE VALIDATION - Now handled by user_registration.js
    // The setupPasswordStrength() function in user_registration.js handles:
    // - Real-time password strength calculation (100-point system)
    // - Live password match validation
    // - Submit button enable/disable based on all requirements
    // This prevents conflicts and ensures consistent validation behavior
    
    // Return validation result
    if (emptyFields.length > 0) {
        return {
            isValid: false,
            message: `Please fill in all required fields: ${emptyFields.map(f => f.label).join(', ')}`,
            fields: emptyFields.map(f => f.field)
        };
    }
    
    if (invalidFields.length > 0) {
        return {
            isValid: false,
            message: invalidFields[0].error,
            fields: invalidFields.map(f => f.field)
        };
    }
    
    return { isValid: true };
}

function validateSpecificField(field, value) {
    const fieldName = field.name;
    
    // Name validation (letters, spaces, hyphens, apostrophes only)
    if (['first_name', 'middle_name', 'last_name'].includes(fieldName)) {
        if (!/^[A-Za-z\s\-']+$/.test(value)) {
            return {
                isValid: false,
                error: 'Names can only contain letters, spaces, hyphens (-), and apostrophes (\')'
            };
        }
        
        // Additional gibberish/keyboard mashing checks for student names
        const nameType = fieldName.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase());
        
        // Must be at least 2 characters (except middle name which is optional)
        if (value.trim().length < 2 && fieldName !== 'middle_name') {
            return {
                isValid: false,
                error: `${nameType} must be at least 2 characters long`
            };
        }
        
        // Must contain at least one vowel (real names have vowels)
        // EXCEPTION: Middle and last names can be 2-character consonant surnames (e.g., "Dy", "Ng", "Wu")
        const isShortSurname = (fieldName === 'middle_name' || fieldName === 'last_name') && value.trim().length === 2;
        if (!/[aeiouAEIOU]/.test(value) && !isShortSurname) {
            return {
                isValid: false,
                error: `Please enter a valid ${nameType.toLowerCase()} (names typically contain vowels)`
            };
        }
        
        // Detect keyboard mashing patterns
        // 1. Check for excessive repeated characters (e.g., "aaaa", "jjjj")
        if (/(.)\1{3,}/.test(value.toLowerCase())) {
            return {
                isValid: false,
                error: `Please enter a real ${nameType.toLowerCase()} (too many repeated characters detected)`
            };
        }
        
        // 2. Check for repeated 2-character patterns (e.g., "adadad", "asdasd")
        // This catches things like "asdadawdasdad"
        if (/(.{2})\1{2,}/.test(value.toLowerCase())) {
            return {
                isValid: false,
                error: `Please enter a real ${nameType.toLowerCase()} (repeated pattern detected)`
            };
        }
        
        // 3. Check for repeated 3-character patterns (e.g., "abcabcabc")
        if (/(.{3})\1{2,}/.test(value.toLowerCase())) {
            return {
                isValid: false,
                error: `Please enter a real ${nameType.toLowerCase()} (repeated pattern detected)`
            };
        }
        
        // 4. Check for sequential keyboard patterns (horizontal rows)
        const keyboardRows = [
            'qwertyuiop',
            'asdfghjkl',
            'zxcvbnm',
            'qazwsxedcrfvtgbyhnujmikolp' // vertical patterns
        ];
        
        const lowerValue = value.toLowerCase().replace(/[^a-z]/g, '');
        for (const row of keyboardRows) {
            // Check for 4+ consecutive characters from same keyboard row
            for (let i = 0; i < row.length - 3; i++) {
                const pattern = row.substring(i, i + 4);
                if (lowerValue.includes(pattern) || lowerValue.includes(pattern.split('').reverse().join(''))) {
                    return {
                        isValid: false,
                        error: `Please enter a real ${nameType.toLowerCase()} (keyboard pattern detected)`
                    };
                }
            }
        }
        
        // 5. Check consonant-to-vowel ratio (real names have balanced ratios)
        // EXCEPTION: Skip ratio check for 2-character surnames (e.g., "Dy", "Ng")
        const consonants = (value.match(/[bcdfghjklmnpqrstvwxyzBCDFGHJKLMNPQRSTVWXYZ]/g) || []).length;
        const vowels = (value.match(/[aeiouAEIOU]/g) || []).length;
        if (consonants > 0 && vowels > 0 && !isShortSurname) {
            const ratio = consonants / vowels;
            // If ratio is > 5:1 for names, it's likely gibberish
            if (ratio > 5) {
                return {
                    isValid: false,
                    error: `Please enter a real ${nameType.toLowerCase()} (unusual letter pattern detected)`
                };
            }
        }
        
        // 6. Check for single letters repeated with spaces (e.g., "a b c d")
        const words = value.trim().split(/\s+/);
        const singleLetterWords = words.filter(w => w.length === 1);
        if (singleLetterWords.length >= 2) {
            return {
                isValid: false,
                error: `Please enter a real ${nameType.toLowerCase()} (too many single-letter parts detected)`
            };
        }
        
        // 7. Check for low character diversity (names should use varied letters)
        // Skip for 2-character surnames (e.g., "Dy", "Ng")
        if (!isShortSurname && value.length >= 5) {
            const uniqueChars = new Set(value.toLowerCase().replace(/[^a-z]/g, '').split('')).size;
            const totalChars = value.replace(/[^a-z]/gi, '').length;
            const diversityRatio = uniqueChars / totalChars;
            
            // If less than 40% unique characters, it's likely gibberish (e.g., "asdadawdasdad" has low diversity)
            if (diversityRatio < 0.4) {
                return {
                    isValid: false,
                    error: `Please enter a real ${nameType.toLowerCase()} (too many repeated letters detected)`
                };
            }
        }
    }
    
    // Mother's Full Maiden Name validation - prevent gibberish/keyboard mashing
    if (fieldName === 'mothers_fullname') {
        // Must contain only valid name characters
        if (!/^[A-Za-z\s\-']+$/.test(value)) {
            return {
                isValid: false,
                error: 'Mother\'s name can only contain letters, spaces, hyphens (-), and apostrophes (\')'
            };
        }
        
        // Split into words (must have at least 3 words - first, middle, and maiden surname)
        const words = value.trim().split(/\s+/).filter(w => w.length > 0);
        if (words.length < 3) {
            return {
                isValid: false,
                error: 'Please enter mother\'s complete maiden name (at least first, middle, and last name)'
            };
        }
        
        // Each word must be at least 2 characters
        for (const word of words) {
            if (word.length < 2) {
                return {
                    isValid: false,
                    error: 'Each part of the name must be at least 2 characters long'
                };
            }
        }
        
        // Must contain at least one vowel (real names have vowels)
        if (!/[aeiouAEIOU]/.test(value)) {
            return {
                isValid: false,
                error: 'Please enter a valid name (names typically contain vowels)'
            };
        }
        
        // Detect keyboard mashing patterns
        // 1. Check for excessive repeated characters (e.g., "aaaa", "jjjj")
        if (/(.)\1{3,}/.test(value.toLowerCase())) {
            return {
                isValid: false,
                error: 'Please enter a real name (too many repeated characters detected)'
            };
        }
        
        // 2. Check for repeated 2-character patterns (e.g., "adadad", "asdasd")
        if (/(.{2})\1{2,}/.test(value.toLowerCase())) {
            return {
                isValid: false,
                error: 'Please enter a real name (repeated pattern detected)'
            };
        }
        
        // 3. Check for repeated 3-character patterns (e.g., "abcabcabc")
        if (/(.{3})\1{2,}/.test(value.toLowerCase())) {
            return {
                isValid: false,
                error: 'Please enter a real name (repeated pattern detected)'
            };
        }
        
        // 4. Check for sequential keyboard patterns (horizontal rows)
        const keyboardRows = [
            'qwertyuiop',
            'asdfghjkl',
            'zxcvbnm',
            'qazwsxedcrfvtgbyhnujmikolp' // vertical patterns
        ];
        
        const lowerValue = value.toLowerCase().replace(/[^a-z]/g, '');
        for (const row of keyboardRows) {
            // Check for 4+ consecutive characters from same keyboard row
            for (let i = 0; i < row.length - 3; i++) {
                const pattern = row.substring(i, i + 4);
                if (lowerValue.includes(pattern) || lowerValue.includes(pattern.split('').reverse().join(''))) {
                    return {
                        isValid: false,
                        error: 'Please enter a real name (keyboard pattern detected)'
                    };
                }
            }
        }
        
        // 5. Check consonant-to-vowel ratio (real names have balanced ratios)
        const consonants = (value.match(/[bcdfghjklmnpqrstvwxyzBCDFGHJKLMNPQRSTVWXYZ]/g) || []).length;
        const vowels = (value.match(/[aeiouAEIOU]/g) || []).length;
        if (consonants > 0 && vowels > 0) {
            const ratio = consonants / vowels;
            // If ratio is > 6:1, it's likely gibberish (e.g., "hsjkfhlnds" has ratio ~9:1)
            if (ratio > 6) {
                return {
                    isValid: false,
                    error: 'Please enter a real name (unusual letter pattern detected)'
                };
            }
        }
        
        // 6. Check for low character diversity (names should use varied letters)
        if (value.length >= 5) {
            const uniqueChars = new Set(value.toLowerCase().replace(/[^a-z]/g, '').split('')).size;
            const totalChars = value.replace(/[^a-z]/gi, '').length;
            const diversityRatio = uniqueChars / totalChars;
            
            // If less than 40% unique characters, it's likely gibberish
            if (diversityRatio < 0.4) {
                return {
                    isValid: false,
                    error: 'Please enter a real name (too many repeated letters detected)'
                };
            }
        }
    }
    
    // Date validation
    if (fieldName === 'bdate') {
        const birthDate = new Date(value);
        const today = new Date();
        
        // Calculate age properly
        let age = today.getFullYear() - birthDate.getFullYear();
        const monthDiff = today.getMonth() - birthDate.getMonth();
        const dayDiff = today.getDate() - birthDate.getDate();
        
        // Adjust age if birthday hasn't occurred this year
        if (monthDiff < 0 || (monthDiff === 0 && dayDiff < 0)) {
            age--;
        }
        
        if (birthDate >= today) {
            return {
                isValid: false,
                error: 'Birth date cannot be in the future'
            };
        }
        
        if (age < 16) {
            return {
                isValid: false,
                error: `You must be at least 16 years old to register. You are currently ${age} years old.`
            };
        }
        
        if (age > 100) {
            return {
                isValid: false,
                error: 'Please enter a valid birth date'
            };
        }
    }
    
    return { isValid: true };
}

function getFieldLabel(field) {
    // Try to find associated label
    const label = field.closest('.mb-3')?.querySelector('label');
    if (label) {
        return label.textContent.replace('*', '').trim();
    }
    
    // Fallback to placeholder or name
    return field.placeholder || field.name || 'Field';
}

function showValidationError(message, fields) {
    // Remove previous highlights
    clearValidationErrors();
    
    // Highlight invalid fields
    if (fields) {
        fields.forEach(field => {
            if (field) {
                field.classList.add('is-invalid');
                field.style.borderColor = '#dc3545';
            }
        });
    }
    
    // Show error message
    showNotifier(message, 'error');
    
    // Focus on first invalid field
    if (fields && fields[0]) {
        fields[0].focus();
    }
}

function clearValidationErrors() {
    // Remove validation classes from all fields
    document.querySelectorAll('.is-invalid').forEach(field => {
        field.classList.remove('is-invalid');
        field.style.borderColor = '';
    });
    
    // Hide any existing error messages
    const notifier = document.getElementById('notifier');
    if (notifier) {
        notifier.style.display = 'none';
    }
}

function showNotifier(message, type = 'error') {
    // Create notifier if it doesn't exist
    let notifier = document.getElementById('notifier');
    if (!notifier) {
        notifier = document.createElement('div');
        notifier.id = 'notifier';
        notifier.style.cssText = `
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            padding: 12px 20px;
            border-radius: 6px;
            color: white;
            font-weight: 500;
            z-index: 9999;
            max-width: 500px;
            text-align: center;
            display: none;
        `;
        document.body.appendChild(notifier);
    }
    
    // Set message and style based on type
    notifier.textContent = message;
    notifier.style.display = 'block';
    
    if (type === 'error') {
        notifier.style.backgroundColor = '#dc3545';
    } else if (type === 'success') {
        notifier.style.backgroundColor = '#28a745';
    } else if (type === 'warning') {
        notifier.style.backgroundColor = '#ffc107';
        notifier.style.color = '#000';
    }
    
    // Auto-hide after 5 seconds
    setTimeout(() => {
        if (notifier) {
            notifier.style.display = 'none';
        }
    }, 5000);
}

// ============================================
// REAL-TIME VALIDATION SETUP
// ============================================

function setupRealTimeValidation() {
    // Add event listeners to all form fields
    document.addEventListener('input', function(e) {
        if (e.target.matches('input, select, textarea')) {
            // Clear validation error when user starts typing/selecting
            e.target.classList.remove('is-invalid');
            e.target.style.borderColor = '';
        }
    });
    
    // Add listeners for radio buttons
    document.addEventListener('change', function(e) {
        if (e.target.matches('input[type="radio"]')) {
            // Clear validation error for radio group
            const radioName = e.target.name;
            document.querySelectorAll(`input[name="${radioName}"]`).forEach(radio => {
                radio.classList.remove('is-invalid');
                radio.style.borderColor = '';
            });
        }
    });
}

// ============================================
// FILE UPLOAD HANDLERS
// ============================================

function setupFileUploadHandlers() {
    console.log('🔧 Setting up file upload handlers...');
    
    // Student ID Picture Upload Handler
    const idPictureFile = document.getElementById('id_picture_file');
    if (idPictureFile) {
        console.log('✅ Found ID picture input, attaching listener');
        idPictureFile.addEventListener('change', function(e) {
            console.log('[ID PIC] File input changed!');
            handleIdPictureFileUpload(e.target);
        });
    } else {
        console.warn('❌ ID picture input (#id_picture_file) not found!');
    }
    
    // Enrollment Form Upload Handler
    const enrollmentForm = document.getElementById('enrollmentForm');
    if (enrollmentForm) {
        console.log('✅ Found enrollment form input, attaching listener');
        enrollmentForm.addEventListener('change', function(e) {
            console.log('[ENROLLMENT] File input changed!');
            handleEnrollmentFileUpload(e.target);
        });
    } else {
        console.warn('❌ Enrollment form input (#enrollmentForm) not found!');
    }
    
    // Letter to Mayor Upload Handler
    const letterForm = document.getElementById('letterToMayorForm');
    if (letterForm) {
        console.log('✅ Found letter form input, attaching listener');
        letterForm.addEventListener('change', function(e) {
            console.log('[LETTER] File input changed!');
            handleLetterFileUpload(e.target);
        });
    } else {
        console.warn('❌ Letter form input (#letterToMayorForm) not found!');
    }
    
    // Certificate of Indigency Upload Handler
    const certificateForm = document.getElementById('certificateForm');
    if (certificateForm) {
        console.log('✅ Found certificate form input, attaching listener');
        certificateForm.addEventListener('change', function(e) {
            console.log('[CERTIFICATE] File input changed!');
            handleCertificateFileUpload(e.target);
        });
    } else {
        console.warn('❌ Certificate form input (#certificateForm) not found!');
    }
    
    // Grades Document Upload Handler
    const gradesForm = document.getElementById('gradesForm');
    if (gradesForm) {
        console.log('✅ Found grades form input, attaching listener');
        gradesForm.addEventListener('change', function(e) {
            console.log('[GRADES] File input changed!');
            handleGradesFileUpload(e.target);
        });
    } else {
        console.warn('❌ Grades form input (#gradesForm) not found!');
    }
    
    console.log('✅ File upload handlers initialization complete');
}

function handleIdPictureFileUpload(fileInput) {
    const file = fileInput.files[0];
    const previewContainer = document.getElementById('idPictureUploadPreview');
    const previewImage = document.getElementById('idPicturePreviewImage');
    const pdfPreview = document.getElementById('idPicturePdfPreview');
    const ocrSection = document.getElementById('idPictureOcrSection');
    const processBtn = document.getElementById('processIdPictureOcrBtn');

    console.log('[ID PIC] change event fired');
    if (!file) {
        console.log('[ID PIC] No file selected');
    } else {
        console.log(`[ID PIC] Selected file: name="${file.name}" type="${file.type}" size=${file.size} bytes`);
    }
    
    if (!file) {
        // Hide preview and OCR section if no file
        if (previewContainer) previewContainer.classList.add('d-none');
        if (ocrSection) ocrSection.classList.add('d-none');
        return;
    }
    
    // Show preview container
    if (previewContainer) previewContainer.classList.remove('d-none');
    
    // Handle image preview
    if (file.type.startsWith('image/')) {
        const reader = new FileReader();
        reader.onload = function(e) {
            if (previewImage) {
                previewImage.src = e.target.result;
                previewImage.style.display = 'block';
            }
        };
        reader.readAsDataURL(file);
    }
    
    // Show OCR section and enable process button
    if (ocrSection) ocrSection.classList.remove('d-none');
    if (processBtn) {
        processBtn.disabled = false;
        processBtn.classList.remove('btn-secondary');
        processBtn.classList.add('btn-info');
        processBtn.style.display = 'block';
        console.log('[ID PIC] Process button enabled');
        
        // Add click handler for processing
        processBtn.onclick = function() {
            console.log('[ID PIC] Process button clicked');
            processIdPictureDocument();
        };
    }
}

function processIdPictureDocument() {
    const processBtn = document.getElementById('processIdPictureOcrBtn');
    const fileInput = document.getElementById('id_picture_file');
    
    console.log('Processing ID Picture...');
    
    if (!fileInput.files[0]) {
        showNotifier('Please select a file first', 'error');
        return;
    }
    
    // Disable Back buttons during processing to avoid step state issues
    setBackButtonsDisabled(true);

    // Show processing state
    if (processBtn) {
        processBtn.disabled = true;
        processBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Processing...';
    }
    
    // Create form data for OCR processing
    const formData = new FormData();
    formData.append('processIdPictureOcr', '1');
    formData.append('id_picture', fileInput.files[0]);
    
    // Add form data for validation - use querySelector with name attributes
    const firstNameInput = document.querySelector('input[name="first_name"]');
    const middleNameInput = document.querySelector('input[name="middle_name"]');
    const lastNameInput = document.querySelector('input[name="last_name"]');
    const extensionNameInput = document.querySelector('input[name="extension_name"]');
    const universityInput = document.querySelector('select[name="university_id"]');
    const schoolStudentIdInput = document.querySelector('input[name="school_student_id"]');
    
    if (firstNameInput && firstNameInput.value) formData.append('first_name', firstNameInput.value);
    if (middleNameInput && middleNameInput.value) formData.append('middle_name', middleNameInput.value);
    if (lastNameInput && lastNameInput.value) formData.append('last_name', lastNameInput.value);
    if (extensionNameInput && extensionNameInput.value) formData.append('extension_name', extensionNameInput.value);
    if (universityInput && universityInput.value) formData.append('university_id', universityInput.value);
    if (schoolStudentIdInput && schoolStudentIdInput.value) formData.append('school_student_id', schoolStudentIdInput.value);
    
    formData.append('g-recaptcha-response', 'test'); // You may need proper reCAPTCHA
    
    // Send OCR request
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        return response.json();
    })
    .then(data => {
        console.log('ID Picture OCR Response:', data);
        handleIdPictureOcrResults(data);
    })
    .catch(error => {
        console.error('ID Picture OCR Error:', error);
        showNotifier('Error processing ID Picture: ' + error.message, 'error');
        resetIdPictureProcessButton();
    })
    .finally(() => {
        // Re-enable Back buttons after processing completes
        setBackButtonsDisabled(false);
    });
}

function updateCheckItem(checkId, confidenceId, checkData) {
    const checkEl = document.getElementById(checkId);
    const confEl = document.getElementById(confidenceId);
    
    if (!checkEl || !confEl || !checkData) return;
    
    const icon = checkEl.querySelector('i');
    
    if (checkData.passed) {
        // Check passed - show success
        if (icon) icon.className = 'bi bi-check-circle text-success me-2';
        confEl.className = 'badge bg-success confidence-score';
    } else {
        // Check failed - show error
        if (icon) icon.className = 'bi bi-x-circle text-danger me-2';
        confEl.className = 'badge bg-danger confidence-score';
    }
    
    // Update confidence display
    if (checkData.similarity !== undefined) {
        confEl.textContent = Math.round(checkData.similarity) + '%';
    } else if (checkData.auto_passed) {
        confEl.textContent = 'Auto-passed';
        confEl.className = 'badge bg-info confidence-score';
    } else if (checkData.found_count !== undefined) {
        confEl.textContent = checkData.found_count + '/' + checkData.required_count;
    } else {
        confEl.textContent = checkData.passed ? 'Passed' : 'Failed';
    }
}

function handleIdPictureOcrResults(data) {
    const resultsDiv = document.getElementById('idPictureOcrResults');
    const feedbackDiv = document.getElementById('idPictureOcrFeedback');
    const nextBtn = document.getElementById('nextStep4Btn');
    
    if (!resultsDiv) return;
    
    // Show results section
    resultsDiv.classList.remove('d-none');
    
    if (data.status === 'success' && data.verification) {
        const v = data.verification;
        const checks = v.checks;
        
        // Track uploaded file
        const fileInput = document.getElementById('idPictureForm');
        if (fileInput && fileInput.files[0]) {
            trackUploadedFile('id_picture', fileInput.files[0].name);
            markFileAsUploaded();
        }
        
        // Update individual check items
        updateCheckItem('idpic-check-firstname', 'idpic-confidence-firstname', checks.first_name_match);
        updateCheckItem('idpic-check-middlename', 'idpic-confidence-middlename', checks.middle_name_match);
        updateCheckItem('idpic-check-lastname', 'idpic-confidence-lastname', checks.last_name_match);
        updateCheckItem('idpic-check-university', 'idpic-confidence-university', checks.university_match);
        updateCheckItem('idpic-check-document', 'idpic-confidence-document', checks.document_keywords_found);
        updateCheckItem('idpic-check-schoolid', 'idpic-confidence-schoolid', checks.school_student_id_match);
        
        // Update overall summary
        const overallConfidenceEl = document.getElementById('idpic-overall-confidence');
        const passedChecksEl = document.getElementById('idpic-passed-checks');
        const recommendationEl = document.getElementById('idpic-verification-recommendation');
        
        if (overallConfidenceEl) overallConfidenceEl.textContent = v.summary.average_confidence + '%';
        if (passedChecksEl) passedChecksEl.textContent = v.summary.passed_checks + '/' + v.summary.total_checks;
        
        // Show recommendation
        if (recommendationEl) {
            if (v.summary.recommendation === 'Approve') {
                recommendationEl.innerHTML = '<i class="bi bi-check-circle-fill text-success me-1"></i>Document verified successfully!';
                if (feedbackDiv) feedbackDiv.style.display = 'none';
                if (nextBtn) {
                    nextBtn.disabled = false;
                    nextBtn.classList.remove('btn-secondary');
                    nextBtn.classList.add('btn-success');
                    nextBtn.innerHTML = '<i class="bi bi-check-circle me-2"></i>Continue - Document Verified';
                }
            } else {
                recommendationEl.innerHTML = '<i class="bi bi-exclamation-triangle-fill text-warning me-1"></i>' + v.summary.recommendation;
                if (feedbackDiv) {
                    feedbackDiv.style.display = 'block';
                    feedbackDiv.innerHTML = '<strong>Verification Warning:</strong> ' + v.summary.recommendation;
                }
                if (nextBtn) {
                    nextBtn.disabled = false; // Allow proceeding with warning
                    nextBtn.classList.remove('btn-secondary');
                    nextBtn.classList.add('btn-warning');
                    nextBtn.innerHTML = '<i class="bi bi-exclamation-triangle me-2"></i>Continue - With Warnings';
                }
            }
        }
        
        showNotifier('ID Picture processed successfully!', 'success');
    } else {
        // Show error feedback
        if (feedbackDiv) {
            feedbackDiv.style.display = 'block';
            feedbackDiv.className = 'alert alert-danger mt-3';
            feedbackDiv.innerHTML = '<strong>Processing Failed:</strong> ' + (data.message || 'Unable to process ID Picture');
        }
        if (nextBtn) nextBtn.disabled = true;
        showNotifier(data.message || 'Failed to process ID Picture', 'error');
    }
    
    resetIdPictureProcessButton();
}

function resetIdPictureProcessButton() {
    const processBtn = document.getElementById('processIdPictureOcrBtn');
    if (processBtn) {
        processBtn.disabled = false;
        processBtn.innerHTML = '<i class="bi bi-search me-2"></i>Verify Student ID';
    }
}

function handleEnrollmentFileUpload(fileInput) {
    const file = fileInput.files[0];
    const previewContainer = document.getElementById('uploadPreview');
    const previewImage = document.getElementById('previewImage');
    const pdfPreview = document.getElementById('pdfPreview');
    const ocrSection = document.getElementById('ocrSection');
    const processBtn = document.getElementById('processOcrBtn');
    
    if (!file) {
        // Hide preview and OCR section if no file
        if (previewContainer) previewContainer.classList.add('d-none');
        if (ocrSection) ocrSection.classList.add('d-none');
        return;
    }
    
    // Show preview container
    if (previewContainer) previewContainer.classList.remove('d-none');
    
    // Handle image preview
    if (file.type.startsWith('image/')) {
        const reader = new FileReader();
        reader.onload = function(e) {
            if (previewImage) {
                previewImage.src = e.target.result;
                previewImage.style.display = 'block';
            }
        };
        reader.readAsDataURL(file);
    }
    
    // FILENAME VALIDATION DISABLED - Allow any filename
    // System will auto-rename files using session prefix
    // Always show OCR section and enable process button when file is uploaded
    const alwaysValid = true; // Replace filename pattern check
    
    /*
    // Validate filename format
    const filename = file.name;
    const namePattern = /^[A-Za-z]+_[A-Za-z]+_EAF\.(jpg|jpeg|png)$/i;
    */
    
    if (alwaysValid) {
        // Show OCR section and enable process button
        if (ocrSection) ocrSection.classList.remove('d-none');
        if (processBtn) {
            processBtn.disabled = false;
            processBtn.classList.remove('btn-secondary');
            processBtn.classList.add('btn-info');
            processBtn.innerHTML = '<i class="bi bi-search me-2"></i>Process Document';
            
            // Add click handler for processing
            processBtn.onclick = function() {
                processEnrollmentDocument();
            };
        }
    } else {
        // This code is now unreachable but kept for reference
        /*
        // Hide OCR section and disable button
        if (ocrSection) ocrSection.classList.add('d-none');
        if (processBtn) {
            processBtn.disabled = true;
            processBtn.classList.add('btn-secondary');
            processBtn.classList.remove('btn-info');
        }
        
        // Show filename error
        showNotifier('Filename must follow format: Lastname_Firstname_EAF.jpg (e.g., Santos_Juan_EAF.jpg)', 'error');
        */
    }
}

function handleLetterFileUpload(fileInput) {
    console.log('[LETTER] handleLetterFileUpload called');
    const file = fileInput.files[0];
    if (!file) {
        console.log('[LETTER] No file');
        return;
    }
    console.log(`[LETTER] File: ${file.name}, ${file.size} bytes`);
    // Enable process button
    const processBtn = document.getElementById('processLetterOcrBtn');
    if (processBtn) {
        processBtn.disabled = false;
        processBtn.style.display = 'block';
        console.log('[LETTER] Process button enabled');
    }
}

function handleCertificateFileUpload(fileInput) {
    console.log('[CERTIFICATE] handleCertificateFileUpload called');
    const file = fileInput.files[0];
    if (!file) {
        console.log('[CERTIFICATE] No file');
        return;
    }
    console.log(`[CERTIFICATE] File: ${file.name}, ${file.size} bytes`);
    // Enable process button
    const processBtn = document.getElementById('processCertificateOcrBtn');
    if (processBtn) {
        processBtn.disabled = false;
        processBtn.style.display = 'block';
        console.log('[CERTIFICATE] Process button enabled');
    }
}

function handleGradesFileUpload(fileInput) {
    console.log('[GRADES] handleGradesFileUpload called');
    const file = fileInput.files[0];
    if (!file) {
        console.log('[GRADES] No file');
        return;
    }
    console.log(`[GRADES] File: ${file.name}, ${file.size} bytes`);
    // Enable process button
    const processBtn = document.getElementById('processGradesOcrBtn');
    if (processBtn) {
        processBtn.disabled = false;
        processBtn.style.display = 'block';
        console.log('[GRADES] Process button enabled');
    }
}

function processEnrollmentDocument() {
    const processBtn = document.getElementById('processOcrBtn');
    const fileInput = document.getElementById('enrollmentForm');
    
    console.log('Current URL:', window.location.href);
    console.log('Processing document...');
    
    if (!fileInput.files[0]) {
        showNotifier('Please select a file first', 'error');
        return;
    }
    
    // Disable Back buttons during processing to avoid step state issues
    setBackButtonsDisabled(true);

    // Show processing state
    if (processBtn) {
        processBtn.disabled = true;
        processBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Processing...';
    }
    
    // Create form data for OCR processing
    const formData = new FormData();
    formData.append('processEnrollmentOcr', '1');
    formData.append('enrollment_form', fileInput.files[0]);
    
    // Add form data for validation - use querySelector with name attributes
    const firstNameInput = document.querySelector('input[name="first_name"]');
    const middleNameInput = document.querySelector('input[name="middle_name"]');
    const lastNameInput = document.querySelector('input[name="last_name"]');
    const extensionNameInput = document.querySelector('input[name="extension_name"]');
    const universityInput = document.querySelector('select[name="university_id"]');
    const yearLevelInput = document.querySelector('select[name="year_level_id"]');
    
    if (firstNameInput && firstNameInput.value) formData.append('first_name', firstNameInput.value);
    if (middleNameInput && middleNameInput.value) formData.append('middle_name', middleNameInput.value);
    if (lastNameInput && lastNameInput.value) formData.append('last_name', lastNameInput.value);
    if (extensionNameInput && extensionNameInput.value) formData.append('extension_name', extensionNameInput.value);
    if (universityInput && universityInput.value) formData.append('university_id', universityInput.value);
    if (yearLevelInput && yearLevelInput.value) formData.append('year_level_id', yearLevelInput.value);
    
    formData.append('g-recaptcha-response', 'test'); // You may need proper reCAPTCHA
    
    // Send OCR request
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        return response.json();
    })
    .then(data => {
        console.log('OCR Response:', data);
        handleOcrResults(data);
        
        // Track uploaded file
        const fileInput = document.getElementById('enrollmentForm');
        if (fileInput && fileInput.files[0]) {
            trackUploadedFile('enrollment_form', fileInput.files[0].name);
            markFileAsUploaded();
        }
    })
    .catch(error => {
        console.error('OCR Error:', error);
        showNotifier('Error processing document: ' + error.message, 'error');
        resetProcessButton();
    })
    .finally(() => {
        // Re-enable Back buttons after processing completes
        setBackButtonsDisabled(false);
    });
}

function handleOcrResults(data) {
    const resultsSection = document.getElementById('ocrResults');
    const processBtn = document.getElementById('processOcrBtn');
    const nextBtn = document.getElementById('nextStep5Btn'); // Fixed: Changed from nextStep4Btn to nextStep5Btn
    
    if (data.status === 'success') {
        // Show results section
        if (resultsSection) resultsSection.classList.remove('d-none');
        
        // Update verification checkmarks
        updateVerificationChecks(data.verification);
        
        // Course auto-population removed - students input course manually
        // if (data.verification && data.verification.course_data) {
        //     populateCourseField(data.verification.course_data);
        // }
        
        // Enable next button if verification passed
        if (data.verification && data.verification.overall_success) {
            if (nextBtn) {
                nextBtn.disabled = false;
                nextBtn.classList.remove('btn-secondary');
                nextBtn.classList.add('btn-success');
                nextBtn.innerHTML = '<i class="bi bi-check-circle me-2"></i>Continue - Document Verified';
            }
            showNotifier('Document verification successful!', 'success');
        } else {
            showNotifier('Document verification failed. Please check the requirements.', 'error');
        }
    } else {
        showNotifier(data.message || 'Document processing failed', 'error');
    }
    
    resetProcessButton();
}

function populateCourseField(courseData) {
    const courseField = document.getElementById('courseField');
    const courseVerified = document.getElementById('courseVerified');
    const courseDetectionInfo = document.getElementById('courseDetectionInfo');
    const courseDetectionText = document.getElementById('courseDetectionText');
    
    if (!courseField) return;
    
    if (courseData && courseData.normalized_course) {
        // Set the course field value
        courseField.value = courseData.normalized_course;
        courseField.readOnly = true;
        courseField.classList.add('is-valid');
        courseField.classList.remove('is-invalid');
        
        // Show course detection info
        if (courseDetectionInfo && courseDetectionText) {
            courseDetectionInfo.style.display = 'block';
            courseDetectionInfo.className = 'alert alert-success mt-2';
            
            let infoText = courseData.normalized_course;
            
            // Add match type info
            if (courseData.match_type) {
                const matchBadge = {
                    'exact': '<span class="badge bg-success ms-2">Exact Match</span>',
                    'fuzzy': '<span class="badge bg-primary ms-2">Fuzzy Match</span>',
                    'keyword': '<span class="badge bg-info ms-2">Keyword Match</span>'
                }[courseData.match_type] || '';
                infoText += matchBadge;
            }
            
            // Add confidence badge
            if (courseData.match_confidence) {
                const confColor = courseData.match_confidence >= 80 ? 'success' : 'warning';
                infoText += ` <span class="badge bg-${confColor} ms-2">${courseData.match_confidence}% confidence</span>`;
            }
            
            // Add program duration
            if (courseData.program_duration) {
                infoText += `<br><small class="text-muted"><i class="bi bi-calendar-check me-1"></i>Program Duration: ${courseData.program_duration} years</small>`;
            }
            
            // Add category
            if (courseData.course_category) {
                infoText += `<br><small class="text-muted"><i class="bi bi-tag me-1"></i>Category: ${courseData.course_category}</small>`;
            }
            
            // Check if course needs admin review
            if (courseData.needs_admin_review) {
                infoText += '<br><small class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i>Course needs admin verification</small>';
                if (courseVerified) courseVerified.value = '0';
            } else {
                if (courseVerified) courseVerified.value = '1';
            }
            
            courseDetectionText.innerHTML = infoText;
        }
        
    } else {
        // No course detected
        courseField.value = '';
        courseField.readOnly = false;
        courseField.classList.remove('is-valid');
        courseField.placeholder = 'Course not detected - please enter manually';
        if (courseVerified) courseVerified.value = '0';
        
        if (courseDetectionInfo) {
            courseDetectionInfo.className = 'alert alert-warning mt-2';
            courseDetectionInfo.style.display = 'block';
            if (courseDetectionText) {
                courseDetectionText.innerHTML = '<i class="bi bi-exclamation-triangle me-2"></i>Course could not be detected from enrollment form. Please enter it manually.';
            }
        }
    }
}

function updateVerificationChecks(verification) {
    if (!verification) return;
    
    const checks = {
        'check-firstname': verification.first_name_match,
        'check-middlename': verification.middle_name_match,
        'check-lastname': verification.last_name_match,
        'check-yearlevel': verification.year_level_match,
        'check-university': verification.university_match,
        'check-document': verification.document_keywords_found
    };
    
    const confidenceMapping = {
        'check-firstname': 'first_name',
        'check-middlename': 'middle_name',
        'check-lastname': 'last_name',
        'check-yearlevel': 'year_level',
        'check-university': 'university',
        'check-document': 'document_keywords'
    };
    
    Object.keys(checks).forEach(checkId => {
        const element = document.getElementById(checkId);
        const confidenceId = 'confidence-' + checkId.replace('check-', '');
        const confidenceElement = document.getElementById(confidenceId);
        
        if (element) {
            const icon = element.querySelector('i');
            if (checks[checkId]) {
                icon.className = 'bi bi-check-circle text-success me-2';
                element.classList.add('text-success');
                element.classList.remove('text-danger');
            } else {
                icon.className = 'bi bi-x-circle text-danger me-2';
                element.classList.add('text-danger');
                element.classList.remove('text-success');
            }
        }
        
        // Update confidence score
        if (confidenceElement && verification.confidence_scores) {
            const confidenceKey = confidenceMapping[checkId];
            const score = verification.confidence_scores[confidenceKey] || 0;
            const roundedScore = Math.round(score);
            
            confidenceElement.textContent = roundedScore + '%';
            
            // Color code the confidence badge
            confidenceElement.className = 'badge confidence-score ';
            if (roundedScore >= 80) {
                confidenceElement.className += 'bg-success';
            } else if (roundedScore >= 60) {
                confidenceElement.className += 'bg-warning';
            } else {
                confidenceElement.className += 'bg-danger';
            }
        }
    });
    
    // Update overall statistics
    if (verification.summary) {
        const overallConfidence = document.getElementById('overall-confidence');
        const passedChecks = document.getElementById('passed-checks');
        const recommendation = document.getElementById('verification-recommendation');
        
        if (overallConfidence) {
            overallConfidence.textContent = verification.summary.average_confidence + '%';
        }
        
        if (passedChecks) {
            passedChecks.textContent = verification.summary.passed_checks + '/' + verification.summary.total_checks;
        }
        
        if (recommendation) {
            recommendation.textContent = verification.summary.recommendation;
        }
    }
    
    // Show OCR text preview if available (for debugging)
    if (verification.ocr_text_preview) {
        console.log('OCR Text Preview:', verification.ocr_text_preview);
    }
}

function resetProcessButton() {
    const processBtn = document.getElementById('processOcrBtn');
    if (processBtn) {
        processBtn.disabled = false;
        processBtn.innerHTML = '<i class="bi bi-search me-2"></i>Process Document';
    }
}


// ============================================
// OTP FUNCTIONALITY
// ============================================
// NOTE: OTP handlers are now in user_registration.js to prevent duplicate event listeners
// The following setupOtpHandlers() function is DISABLED to avoid double-firing AJAX requests

/*
function setupOtpHandlers() {
    const sendOtpBtn = document.getElementById('sendOtpBtn');
    const resendOtpBtn = document.getElementById('resendOtpBtn');
    const verifyOtpBtn = document.getElementById('verifyOtpBtn');
    
    if (sendOtpBtn) {
        sendOtpBtn.addEventListener('click', function() {
            sendOtp();
        });
    }
    
    if (resendOtpBtn) {
        resendOtpBtn.addEventListener('click', function() {
            sendOtp();
        });
    }
    
    if (verifyOtpBtn) {
        verifyOtpBtn.addEventListener('click', function() {
            verifyOtp();
        });
    }
    
    console.log('✅ OTP handlers initialized');
}
*/

/*
function sendOtp() {
    const emailInput = document.getElementById('emailInput');
    const sendBtn = document.getElementById('sendOtpBtn');
    const resendBtn = document.getElementById('resendOtpBtn');
    
    console.log('Send OTP clicked');
    console.log('Email input:', emailInput);
    console.log('Email value:', emailInput ? emailInput.value : 'null');
    
    if (!emailInput || !emailInput.value) {
        showNotifier('Please enter an email address first', 'error');
        return;
    }
    
    if (!validateEmail(emailInput.value)) {
        showNotifier('Please enter a valid email address', 'error');
        return;
    }
    
    // Disable button and show loading state
    if (sendBtn) {
        sendBtn.disabled = true;
        sendBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Sending OTP...';
    }
    
    const formData = new FormData();
    formData.append('sendOtp', '1');
    formData.append('email', emailInput.value);
    formData.append('g-recaptcha-response', 'test'); // You may need proper reCAPTCHA
    
    console.log('Sending OTP request...');
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => {
        console.log('Response received:', response.status);
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        return response.text();
    })
    .then(text => {
        console.log('Response text:', text);
        try {
            return JSON.parse(text);
        } catch (e) {
            console.error('JSON parse error:', e);
            throw new Error('Invalid JSON response: ' + text.substring(0, 200));
        }
    })
    .then(data => {
        console.log('Parsed data:', data);
        if (data.status === 'success') {
            showNotifier(data.message, 'success');
            
            // Show OTP input section and hide send button
            const otpSection = document.getElementById('otpSection');
            if (otpSection) {
                otpSection.classList.remove('d-none');
                otpSection.style.display = 'block';
            }
            
            if (sendBtn) {
                sendBtn.style.display = 'none';
            }
            
            if (resendBtn) {
                resendBtn.style.display = 'block';
                startResendCooldown();
            }
        } else {
            showNotifier(data.message || 'Failed to send OTP', 'error');
        }
    })
    .catch(error => {
        console.error('OTP Error:', error);
        showNotifier('Error sending OTP: ' + error.message, 'error');
    })
    .finally(() => {
        // Reset button state
        if (sendBtn) {
            sendBtn.disabled = false;
            sendBtn.innerHTML = 'Send OTP (Email)';
        }
    });
}
*/

function startResendCooldown() {
    const resendBtn = document.getElementById('resendOtpBtn');
    const timerDiv = document.getElementById('otpTimer');
    if (!resendBtn) return;
    
    let cooldown = 60; // 60 seconds cooldown
    resendBtn.disabled = true;
    
    const interval = setInterval(() => {
        resendBtn.innerHTML = `<i class="bi bi-arrow-clockwise me-2"></i>Resend (${cooldown}s)`;
        if (timerDiv) {
            timerDiv.innerHTML = `Code expires in ${cooldown} seconds`;
        }
        cooldown--;
        
        if (cooldown < 0) {
            clearInterval(interval);
            resendBtn.disabled = false;
            resendBtn.innerHTML = '<i class="bi bi-arrow-clockwise me-2"></i>Resend OTP';
            if (timerDiv) {
                timerDiv.innerHTML = 'Code expired - please request a new one';
                timerDiv.className = 'text-danger small';
            }
        }
    }, 1000);
}

// Add OTP input formatting
function setupOtpInputFormatting() {
    const otpInput = document.getElementById('otp');
    if (otpInput) {
        otpInput.addEventListener('input', function(e) {
            // Only allow numbers
            e.target.value = e.target.value.replace(/[^0-9]/g, '');
            
            // ❌ REMOVED: Auto-verify feature disabled - verifyOtp() is now in user_registration.js
            // User must click "Verify OTP" button manually
            /*
            // Auto-verify when 6 digits are entered
            if (e.target.value.length === 6) {
                // Small delay to let user see the complete input
                setTimeout(() => {
                    const verifyBtn = document.getElementById('verifyOtpBtn');
                    if (verifyBtn && !verifyBtn.disabled) {
                        verifyOtp();
                    }
                }, 500);
            }
            */
        });
        
        // Handle paste events
        otpInput.addEventListener('paste', function(e) {
            e.preventDefault();
            const paste = (e.clipboardData || window.clipboardData).getData('text');
            const numbers = paste.replace(/[^0-9]/g, '').substring(0, 6);
            e.target.value = numbers;
            
            // ❌ REMOVED: Auto-verify feature disabled - verifyOtp() is now in user_registration.js
            // User must click "Verify OTP" button manually
            /*
            if (numbers.length === 6) {
                setTimeout(() => {
                    const verifyBtn = document.getElementById('verifyOtpBtn');
                    if (verifyBtn && !verifyBtn.disabled) {
                        verifyOtp();
                    }
                }, 500);
            }
            */
        });
    }
}

/*
function verifyOtp() {
    const otpInput = document.querySelector('input[name="otp"]');
    const emailInput = document.querySelector('input[name="email"]');
    const verifyBtn = document.getElementById('verifyOtpBtn');
    
    if (!otpInput || !otpInput.value) {
        showNotifier('Please enter the OTP code', 'error');
        return;
    }
    
    if (!emailInput || !emailInput.value) {
        showNotifier('Email address is required', 'error');
        return;
    }
    
    // Disable button and show loading state
    if (verifyBtn) {
        verifyBtn.disabled = true;
        verifyBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Verifying OTP...';
    }
    
    const formData = new FormData();
    formData.append('verifyOtp', '1');
    formData.append('otp', otpInput.value);
    formData.append('email', emailInput.value);
    formData.append('g-recaptcha-response', 'test'); // You may need proper reCAPTCHA
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        return response.json();
    })
    .then(data => {
        if (data.status === 'success') {
            showNotifier(data.message, 'success');
            
            // Hide OTP verification section and show success
            const otpSection = document.getElementById('otpSection');
            if (otpSection) {
                otpSection.style.display = 'none';
            }
            
            // Show email verified status
            const emailStatus = document.getElementById('emailStatus');
            if (emailStatus) {
                emailStatus.classList.remove('d-none');
                console.log('✅ Email status set to verified');
            }
            
            // Enable next button for step 9 (OTP verification step)
            const nextBtn = document.getElementById('nextStep9Btn'); // Fixed: Changed from nextStep8Btn to nextStep9Btn
            if (nextBtn) {
                nextBtn.disabled = false;
                nextBtn.classList.remove('btn-secondary');
                nextBtn.classList.remove('btn-primary');
                nextBtn.classList.add('btn-success');
                nextBtn.innerHTML = '<i class="bi bi-check-circle me-2"></i>Continue - Email Verified';
                console.log('✅ Next button enabled for step 9');
            } else {
                console.error('❌ nextStep9Btn not found');
            }
        } else {
            showNotifier(data.message || 'OTP verification failed', 'error');
        }
    })
    .catch(error => {
        console.error('OTP Verification Error:', error);
        showNotifier('Error verifying OTP: ' + error.message, 'error');
    })
    .finally(() => {
        // Reset button state
        if (verifyBtn) {
            verifyBtn.disabled = false;
            verifyBtn.innerHTML = 'Verify OTP';
        }
    });
}
*/

function validateEmail(email) {
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return emailRegex.test(email);
}

// Initialize validation when page loads
// Check email duplicate on blur
function setupEmailDuplicateCheck() {
    const emailInput = document.getElementById('emailInput');
    const emailWarning = document.getElementById('emailDuplicateWarning');
    const sendOtpBtn = document.getElementById('sendOtpBtn');
    
    if (!emailInput) return;
    
    emailInput.addEventListener('blur', async function() {
        const email = this.value.trim();
        
        if (!email || !email.includes('@')) {
            if (emailWarning) emailWarning.style.display = 'none';
            return;
        }
        
        try {
            const formData = new FormData();
            formData.append('action', 'check_email_duplicate');
            formData.append('email', email);
            
            const response = await fetch('student_register.php', {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            if (data.exists) {
                if (emailWarning) emailWarning.style.display = 'block';
                if (sendOtpBtn) sendOtpBtn.disabled = true;
                showNotifier('This email is already registered. Please use a different email.', 'error');
            } else {
                if (emailWarning) emailWarning.style.display = 'none';
                if (sendOtpBtn) sendOtpBtn.disabled = false;
            }
        } catch (error) {
            console.error('Error checking email:', error);
        }
    });
}

// Check mobile duplicate on blur
function setupMobileDuplicateCheck() {
    const mobileInput = document.getElementById('mobile');
    const mobileWarning = document.getElementById('mobileDuplicateWarning');
    
    if (!mobileInput) return;
    
    mobileInput.addEventListener('blur', async function() {
        const mobile = this.value.trim();
        
        // Validate Philippines mobile format (09xxxxxxxxx)
        if (!mobile || !/^09[0-9]{9}$/.test(mobile)) {
            if (mobileWarning) mobileWarning.style.display = 'none';
            return;
        }
        
        try {
            const formData = new FormData();
            formData.append('action', 'check_mobile_duplicate');
            formData.append('mobile', mobile);
            
            const response = await fetch('student_register.php', {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            if (data.exists) {
                if (mobileWarning) {
                    mobileWarning.textContent = '⚠️ This mobile number is already registered. Please use a different mobile number.';
                    mobileWarning.className = 'alert alert-danger mt-2';
                    mobileWarning.style.display = 'block';
                }
                showNotifier('This mobile number is already registered. Please use a different mobile number.', 'error');
            } else {
                if (mobileWarning) {
                    mobileWarning.textContent = '✓ This mobile number is available.';
                    mobileWarning.className = 'alert alert-success mt-2';
                    mobileWarning.style.display = 'block';
                    // Hide success message after 3 seconds
                    setTimeout(() => {
                        mobileWarning.style.display = 'none';
                    }, 3000);
                }
            }
        } catch (error) {
            console.error('Error checking mobile:', error);
        }
    });
}

// Check name duplicate (First + Last Name) on blur
function setupNameDuplicateCheck() {
    const firstNameInput = document.getElementById('firstNameInput');
    const lastNameInput = document.getElementById('lastNameInput');
    const nameDuplicateWarning = document.getElementById('nameDuplicateWarning');
    const nameDuplicateMessage = document.getElementById('nameDuplicateMessage');
    
    if (!firstNameInput || !lastNameInput || !nameDuplicateWarning) return;
    
    // Clear duplicate flag when user changes name
    const clearDuplicateFlag = function() {
        hasNameDuplicate = false; // User changed name, clear the block
        console.log('🔄 Name changed - clearing duplicate flag');
    };
    
    firstNameInput.addEventListener('input', clearDuplicateFlag);
    lastNameInput.addEventListener('input', clearDuplicateFlag);
    
    const checkNameDuplicate = async function() {
        const firstName = firstNameInput.value.trim();
        const lastName = lastNameInput.value.trim();
        
        // Both names must be filled
        if (!firstName || !lastName) {
            if (nameDuplicateWarning) nameDuplicateWarning.style.display = 'none';
            return;
        }
        
        try {
            const formData = new FormData();
            formData.append('action', 'check_name_duplicate');
            formData.append('first_name', firstName);
            formData.append('last_name', lastName);
            
            const response = await fetch('student_register.php', {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            if (data.exists && data.count > 0) {
                if (nameDuplicateWarning && nameDuplicateMessage) {
                    nameDuplicateMessage.textContent = data.message;
                    nameDuplicateWarning.style.display = 'block';
                }
            } else {
                if (nameDuplicateWarning) {
                    nameDuplicateWarning.style.display = 'none';
                }
            }
        } catch (error) {
            console.error('Error checking name duplicate:', error);
        }
    };
    
    // Trigger check when both names are filled
    firstNameInput.addEventListener('blur', checkNameDuplicate);
    lastNameInput.addEventListener('blur', checkNameDuplicate);
}

// Check household warning (Last Name only) on blur
function setupHouseholdWarning() {
    const lastNameInput = document.getElementById('lastNameInput');
    const householdWarning = document.getElementById('householdWarning');
    const householdMessage = document.getElementById('householdMessage');
    
    if (!lastNameInput || !householdWarning) return;
    
    lastNameInput.addEventListener('blur', async function() {
        const lastName = this.value.trim();
        
        // Must have last name
        if (!lastName || lastName.length < 2) {
            if (householdWarning) householdWarning.style.display = 'none';
            hasHouseholdWarning = false; // Clear flag
            householdConfirmed = false; // Reset confirmation
            return;
        }
        
        try {
            const formData = new FormData();
            formData.append('action', 'check_household_lastname');
            formData.append('last_name', lastName);
            
            const response = await fetch('student_register.php', {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            if (data.exists && data.count > 0) {
                if (householdWarning && householdMessage) {
                    householdMessage.textContent = data.message;
                    householdWarning.style.display = 'block';
                    hasHouseholdWarning = true; // Set flag
                    householdConfirmed = false; // Reset confirmation when new warning appears
                }
            } else {
                if (householdWarning) {
                    householdWarning.style.display = 'none';
                    hasHouseholdWarning = false; // Clear flag
                    householdConfirmed = false; // Reset confirmation
                }
            }
        } catch (error) {
            console.error('Error checking household:', error);
        }
    });
    
    // Also reset flags when user types (name changed)
    lastNameInput.addEventListener('input', function() {
        householdConfirmed = false; // User is typing, reset confirmation
    });
}

// ❌ REMOVED: setupStudentNameValidation() - Now in user_registration.js
// ❌ REMOVED: setupMothersFullNameValidation() - Now in user_registration.js
// These client-side validation functions have been moved to user_registration.js
// to prevent conflicts with other inline code and ensure proper initialization order.
// The validation logic uses the same validateSpecificField() function defined below.

// ============================================================
// HOUSEHOLD DUPLICATE PREVENTION SYSTEM
// Using: Surname + Mother's Maiden Name + Barangay
// ============================================================

// Global variables for household prevention
let householdCheckPassed = false;
let householdBlockActive = false;
let sameAsSurnameAttempts = 0;
let householdCheckDebounceTimer = null;

// NOTE: Validation removed - now asking for mother's FULL NAME instead of just maiden surname
// No need to check if full name matches student surname (impossible scenario)
/*
// Check if mother's maiden name matches student's surname
function setupMothersMaidenNameValidation() {
    const lastNameInput = document.getElementById('lastNameInput');
    const mothersMaidenNameInput = document.getElementById('mothersMaidenNameInput');
    
    if (!lastNameInput || !mothersMaidenNameInput) return;
    
    mothersMaidenNameInput.addEventListener('blur', async function() {
        const lastName = lastNameInput.value.trim();
        const maidenName = this.value.trim();
        
        if (!maidenName || !lastName) return;
        
        // Check if maiden name matches surname (potential error)
        if (maidenName.toLowerCase() === lastName.toLowerCase()) {
            sameAsSurnameAttempts++;
            
            if (sameAsSurnameAttempts >= 3) {
                // After 3 attempts, show unusual case confirmation
                const result = await Swal.fire({
                    icon: 'question',
                    title: 'Unusual Case Detected',
                    html: `
                        <p>Your mother's maiden name is the same as your surname (<strong>${lastName}</strong>).</p>
                        <p>This is rare but can happen if your parents had the same last name before marriage.</p>
                        <p class="text-muted small mt-3">Is this correct?</p>
                    `,
                    showCancelButton: true,
                    confirmButtonText: 'Yes, this is correct',
                    cancelButtonText: 'No, let me re-enter',
                    confirmButtonColor: '#28a745',
                    cancelButtonColor: '#6c757d'
                });
                
                if (result.isConfirmed) {
                    // Mark for admin review
                    document.getElementById('adminReviewRequiredFlag') && (document.getElementById('adminReviewRequiredFlag').value = '1');
                    
                    // Show notice
                    Swal.fire({
                        icon: 'info',
                        title: 'Application Will Be Reviewed',
                        text: 'Your application will be manually reviewed by an administrator due to this unusual case.',
                        confirmButtonText: 'I understand'
                    });
                    
                    // Proceed with household check
                    triggerHouseholdCheck();
                } else {
                    this.value = '';
                    this.focus();
                    sameAsSurnameAttempts = 0; // Reset counter
                }
            } else {
                // Show warning for first 2 attempts
                await Swal.fire({
                    icon: 'warning',
                    title: 'Please Check',
                    html: `
                        <p>You entered your mother's <strong>current married name</strong> (<strong>${maidenName}</strong>).</p>
                        <p>We need her <strong>maiden name</strong> (last name <u>before marriage</u>).</p>
                        <div class="alert alert-info mt-3 text-start">
                            <strong>Example:</strong><br>
                            If your mother was <em>"Maria Reyes"</em> before marriage<br>
                            and is now <em>"Maria Santos"</em>, enter <strong>"Reyes"</strong>
                        </div>
                    `,
                    confirmButtonText: 'Let me correct it',
                    confirmButtonColor: '#0d6efd'
                });
                
                this.value = '';
                this.focus();
            }
        } else {
            // Different from surname - trigger household check
            sameAsSurnameAttempts = 0; // Reset counter
            triggerHouseholdCheck();
        }
    });
    
    // Reset attempts when user changes surname
    lastNameInput.addEventListener('input', function() {
        sameAsSurnameAttempts = 0;
        householdCheckPassed = false;
        householdBlockActive = false;
    });
    
    // Also trigger household check on input (debounced)
    mothersMaidenNameInput.addEventListener('input', function() {
        householdCheckPassed = false;
        householdBlockActive = false;
        triggerHouseholdCheck();
    });
}
*/

// Trigger household duplicate check (debounced)
function triggerHouseholdCheck() {
    // Clear existing timer
    if (householdCheckDebounceTimer) {
        clearTimeout(householdCheckDebounceTimer);
    }
    
    // Debounce for 500ms
    householdCheckDebounceTimer = setTimeout(() => {
        checkHouseholdDuplicate();
    }, 500);
}

// Check household duplicate: Surname + Mother's Maiden Name + Barangay
async function checkHouseholdDuplicate() {
    const lastNameInput = document.getElementById('lastNameInput');
    const mothersMaidenNameInput = document.getElementById('mothersMaidenNameInput');
    const barangaySelect = document.querySelector('select[name="barangay_id"]');
    const spinner = document.getElementById('householdCheckSpinner');
    const successIndicator = document.getElementById('householdCheckSuccess');
    const errorDiv = document.getElementById('householdBlockError');
    const errorMessage = document.getElementById('householdBlockMessage');
    
    if (!lastNameInput || !mothersMaidenNameInput || !barangaySelect) return;
    
    const lastName = lastNameInput.value.trim();
    const maidenName = mothersMaidenNameInput.value.trim();
    const barangayId = barangaySelect.value;
    
    // All three fields must be filled
    if (!lastName || !maidenName || !barangayId) {
        if (spinner) spinner.style.display = 'none';
        if (successIndicator) successIndicator.style.display = 'none';
        if (errorDiv) errorDiv.style.display = 'none';
        return;
    }
    
    // Skip if maiden name same as surname (handled separately)
    if (maidenName.toLowerCase() === lastName.toLowerCase()) {
        return;
    }
    
    try {
        // Show loading
        if (spinner) spinner.style.display = 'block';
        if (successIndicator) successIndicator.style.display = 'none';
        if (errorDiv) errorDiv.style.display = 'none';
        
        const formData = new FormData();
        formData.append('action', 'check_household_duplicate');
        formData.append('last_name', lastName);
        formData.append('mothers_maiden_name', maidenName);
        formData.append('barangay_id', barangayId);
        
        const response = await fetch('student_register.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        // Hide loading
        if (spinner) spinner.style.display = 'none';
        
        if (data.household_exists) {
            if (data.match_type === 'exact') {
                // HARD BLOCK - Exact match found
                handleExactHouseholdMatch(data);
            } else if (data.match_type === 'fuzzy') {
                // SOFT WARNING - Similar match found
                await handleFuzzyHouseholdMatch(data);
            }
        } else {
            // No match - success!
            handleNoHouseholdMatch();
        }
    } catch (error) {
        console.error('Error checking household duplicate:', error);
        if (spinner) spinner.style.display = 'none';
    }
}

// Handle exact household match (BLOCK)
function handleExactHouseholdMatch(data) {
    householdBlockActive = true;
    householdCheckPassed = false;
    
    const errorDiv = document.getElementById('householdBlockError');
    const errorMessage = document.getElementById('householdBlockMessage');
    const successIndicator = document.getElementById('householdCheckSuccess');
    
    if (successIndicator) successIndicator.style.display = 'none';
    
    if (errorDiv && errorMessage) {
        errorMessage.innerHTML = `
            A student from your household is already registered and cannot proceed with registration.
        `;
        errorDiv.style.display = 'block';
    }
    
    // Show detailed blocking modal
    Swal.fire({
        icon: 'error',
        title: 'Household Already Registered',
        html: `
            <div class="text-start">
                <p>A student from your household is already registered:</p>
                <div class="alert alert-secondary">
                    <strong>Student:</strong> ${data.existing_student.first_name} ${data.existing_student.last_name}<br>
                    <strong>Student ID:</strong> ${data.existing_student.student_id}<br>
                    <strong>Barangay:</strong> ${data.household_info.barangay}<br>
                    <strong>Registered:</strong> ${new Date(data.existing_student.registered_date).toLocaleDateString('en-PH', {year: 'numeric', month: 'long', day: 'numeric'})}
                </div>
                <p class="text-muted"><strong>Policy:</strong> Only one student per household can receive scholarship assistance to ensure fair distribution of resources.</p>
                <hr>
                <p class="mb-2"><strong>If you believe this is an error,</strong> please contact our office:</p>
                <p class="mb-1">📧 <strong>Email:</strong> scholarship@generaltrias.gov.ph</p>
                <p class="mb-1">📞 <strong>Phone:</strong> (046) 123-4567</p>
                <p class="mb-0">⏰ <strong>Office Hours:</strong> Monday-Friday, 8:00 AM - 5:00 PM</p>
            </div>
        `,
        confirmButtonText: 'Close',
        allowOutsideClick: false,
        confirmButtonColor: '#dc3545'
    });
}

// Handle fuzzy household match (WARNING)
async function handleFuzzyHouseholdMatch(data) {
    const result = await Swal.fire({
        icon: 'question',
        title: 'Please Verify',
        html: `
            <div class="text-start">
                <p>We found a household with similar information:</p>
                <div class="alert alert-warning">
                    <strong>Student:</strong> ${data.existing_student.first_name} ${data.existing_student.last_name}<br>
                    <strong>Barangay:</strong> ${data.household_info.barangay}<br>
                    <strong>Mother's Maiden Name in system:</strong> <span class="badge bg-primary">${data.household_info.mothers_maiden_name_database}</span><br>
                    <strong>You entered:</strong> <span class="badge bg-secondary">${data.household_info.mothers_maiden_name_entered}</span>
                </div>
                <p class="fw-bold">Is this the same household as yours?</p>
            </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'Yes, Same Household',
        cancelButtonText: 'No, Different Household',
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        allowOutsideClick: false
    });
    
    if (result.isConfirmed) {
        // User confirmed it's same household - treat as exact match
        data.match_type = 'user_confirmed';
        handleExactHouseholdMatch(data);
    } else {
        // User says it's different - allow to proceed
        handleNoHouseholdMatch();
        
        // Log this for admin review (optional)
        console.log('User denied fuzzy match - different household confirmed');
    }
}

// Handle no household match (SUCCESS)
function handleNoHouseholdMatch() {
    householdBlockActive = false;
    householdCheckPassed = true;
    
    const successIndicator = document.getElementById('householdCheckSuccess');
    const errorDiv = document.getElementById('householdBlockError');
    
    if (errorDiv) errorDiv.style.display = 'none';
    
    if (successIndicator) {
        successIndicator.style.display = 'block';
        // Hide after 5 seconds
        setTimeout(() => {
            successIndicator.style.display = 'none';
        }, 5000);
    }
}

// Also check household when barangay changes
function setupBarangayHouseholdCheck() {
    const barangaySelect = document.querySelector('select[name="barangay_id"]');
    
    if (!barangaySelect) return;
    
    barangaySelect.addEventListener('change', function() {
        householdCheckPassed = false;
        householdBlockActive = false;
        triggerHouseholdCheck();
    });
}

// NOTE: Password strength validation is now in user_registration.js
// The setupPasswordStrength() function handles:
// - Real-time password strength calculation (100-point system)
// - Live feedback with color-coded progress bar
// - Password match validation
// - Submit button enable/disable based on requirements
// Requirements: 12+ chars, uppercase, lowercase, number, special character, passwords match

document.addEventListener('DOMContentLoaded', function() {
    // Initialize form to step 1
    showStep(1);
    updateStepIndicators();
    console.log('✅ Form initialized to step 1');
    
    // ============================================================
    // OCR BYPASS MODE - Enable all document buttons immediately
    // ============================================================
    <?php if (OCR_BYPASS_ENABLED): ?>
    console.log('⚠️ OCR BYPASS MODE: Enabling all document step buttons');
    
    // Enable all document step Next buttons
    const documentButtons = [
        'nextStep4Btn',  // ID Picture
        'nextStep5Btn',  // Enrollment Form
        'nextStep6Btn',  // Letter to Mayor
        'nextStep7Btn',  // Certificate of Indigency
        'nextStep8Btn'   // Grades
    ];
    
    documentButtons.forEach(btnId => {
        const btn = document.getElementById(btnId);
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-skip-forward me-2"></i>Continue - Bypass Mode (Optional)';
            btn.classList.remove('btn-secondary');
            btn.classList.add('btn-warning');
            console.log(`✓ Enabled button: ${btnId}`);
        }
    });
    
    console.log('✅ All document buttons enabled - students can skip uploads');
    <?php else: ?>
    console.log('ℹ️ Normal mode - documents required and must be verified');
    <?php endif; ?>
    // ============================================================
    
    // Auto-cleanup on page refresh (if triggered by PHP)
    <?php if (isset($_SESSION['trigger_cleanup_on_load']) && $_SESSION['trigger_cleanup_on_load'] === true): ?>
    console.log('🔄 Page refresh detected (PHP flag) - verifying cleanup...');
    cleanupSessionFiles().then(() => {
        console.log('✅ JavaScript cleanup verification completed');
    });
    <?php 
    // Clear the flag after a short delay to allow JavaScript to execute
    // This prevents the flag from persisting across multiple refreshes
    $_SESSION['trigger_cleanup_on_load'] = false; 
    ?>
    <?php else: ?>
    console.log('ℹ️ Normal page load (not a refresh)');
    <?php endif; ?>
    
    setupRealTimeValidation();
    setupFileUploadHandlers();
    // setupOtpHandlers(); // DISABLED: OTP handlers now in user_registration.js to prevent duplicate event listeners
    setupOtpInputFormatting();
    setupEmailDuplicateCheck();
    setupMobileDuplicateCheck(); // Add mobile duplicate check
    setupNameDuplicateCheck(); // Add name duplicate check
    setupHouseholdWarning(); // Add household warning check (old system)
    // ❌ REMOVED: setupStudentNameValidation() - Now in user_registration.js (loads earlier)
    // ❌ REMOVED: setupMothersFullNameValidation() - Now in user_registration.js (loads earlier)
    setupMothersMaidenNameValidation(); // NEW: Mother's maiden name validation
    setupBarangayHouseholdCheck(); // NEW: Barangay change triggers household check
    // NOTE: setupPasswordStrength() is now called from user_registration.js automatically
    setupConnectionMonitoring();
    setupPageUnloadWarning(); // Setup refresh warning with auto-cleanup
    console.log('✅ All handlers initialized (password validation in user_registration.js)');
    console.log('⚠️ Page refresh warning enabled - will cleanup temp files on refresh');
    
    // Add debug button (temporary)
    const debugBtn = document.createElement('button');
    debugBtn.innerHTML = 'Test POST Request';
    debugBtn.onclick = testPostRequest;
    debugBtn.className = 'btn btn-warning btn-sm';
    debugBtn.style.position = 'fixed';
    debugBtn.style.top = '10px';
    debugBtn.style.right = '10px';
    debugBtn.style.zIndex = '9999';
    document.body.appendChild(debugBtn);
});

// ============================================
// SESSION CLEANUP & FILE MANAGEMENT
// ============================================

// ============================================
// SESSION CLEANUP & FILE MANAGEMENT
// ============================================

// Automatic cleanup when session files are detected on page load
async function cleanupSessionFiles() {
    try {
        const formData = new FormData();
        formData.append('cleanup_session_files', '1');
        
        const response = await fetch('student_register.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        console.log('🗑️ Session cleanup:', data);
    } catch (error) {
        console.error('Cleanup error:', error);
    }
}

async function trackUploadedFile(fileType, fileName) {
    try {
        const formData = new FormData();
        formData.append('track_uploaded_file', '1');
        formData.append('file_type', fileType);
        formData.append('file_name', fileName);
        
        await fetch('student_register.php', {
            method: 'POST',
            body: formData
        });
        
        // Mark that files have been uploaded (enables refresh warning)
        markFileAsUploaded();
    } catch (error) {
        console.error('Error tracking file:', error);
    }
}

// ============================================
// PAGE UNLOAD WARNING WITH AUTO-CLEANUP
// ============================================

// NOTE: hasUploadedFiles is already declared in user_registration.js
// Do NOT redeclare it here to avoid "already been declared" error
if (typeof isNavigatingAway === 'undefined') {
    var isNavigatingAway = false; // Track if user is intentionally leaving
}

function setupPageUnloadWarning() {
    // Check if user has uploaded files on page load
    checkUploadedFiles();
    
    // Enhanced beforeunload handler with cleanup
    window.addEventListener('beforeunload', function(e) {
        if (hasUploadedFiles && currentStep > 1 && currentStep < 10 && !isNavigatingAway) {
            // User is trying to refresh or close - trigger cleanup
            isNavigatingAway = true;
            
            // Trigger cleanup in background (fire-and-forget)
            // Use sendBeacon for reliable delivery even if page unloads
            const formData = new FormData();
            formData.append('cleanup_session_files', '1');
            
            // Modern browsers: sendBeacon is more reliable during page unload
            if (navigator.sendBeacon) {
                const blob = new Blob([new URLSearchParams(formData)], { type: 'application/x-www-form-urlencoded' });
                navigator.sendBeacon('student_register.php', blob);
                console.log('🗑️ Cleanup triggered via sendBeacon');
            } else {
                // Fallback: synchronous AJAX (not ideal but works)
                const xhr = new XMLHttpRequest();
                xhr.open('POST', 'student_register.php', false); // synchronous
                xhr.send(formData);
                console.log('🗑️ Cleanup triggered via sync XHR');
            }
            
            // Show browser's default confirmation dialog
            const message = '⚠️ WARNING: Refreshing will delete ALL your uploaded documents!\n\n' +
                          'Your uploaded files (ID picture, enrollment form, grades, etc.) will be permanently removed.\n' +
                          'You will need to upload them again if you return.\n\n' +
                          'Are you sure you want to refresh?';
            e.preventDefault();
            e.returnValue = message;
            return message;
        }
    });
    
    // Detect page hide (more reliable than beforeunload in modern browsers)
    window.addEventListener('pagehide', function(e) {
        if (hasUploadedFiles && currentStep > 1 && currentStep < 10) {
            // Page is definitely being unloaded - cleanup
            const formData = new FormData();
            formData.append('cleanup_session_files', '1');
            
            if (navigator.sendBeacon) {
                const blob = new Blob([new URLSearchParams(formData)], { type: 'application/x-www-form-urlencoded' });
                navigator.sendBeacon('student_register.php', blob);
                console.log('🗑️ Cleanup triggered on pagehide');
            }
        }
    });
    
    console.log('✅ Page refresh warning with auto-cleanup enabled');
}

async function checkUploadedFiles() {
    try {
        const formData = new FormData();
        formData.append('check_uploaded_files', '1');
        
        const response = await fetch('student_register.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        if (data.status === 'success') {
            hasUploadedFiles = data.has_files;
            console.log('📁 Has uploaded files:', hasUploadedFiles);
            if (hasUploadedFiles) {
                console.log('⚠️ Page refresh warning is ACTIVE - files will be deleted on refresh');
            }
        }
    } catch (error) {
        console.error('Error checking uploaded files:', error);
    }
}

// Call this after each successful file upload
function markFileAsUploaded() {
    hasUploadedFiles = true;
    console.log('📤 File uploaded - refresh warning NOW ACTIVE');
    console.log('⚠️ WARNING: Refreshing page will delete all temp files');
}

// ============================================
// SESSION TIMEOUT WARNING SYSTEM
// ============================================

// Session timeout is 30 minutes - warn at 25 minutes
const SESSION_TIMEOUT_MS = 30 * 60 * 1000; // 30 minutes
const WARNING_TIME_MS = 25 * 60 * 1000;    // 25 minutes
let sessionStartTime = Date.now();
let timeoutWarningShown = false;
let sessionTimeoutId = null;

function initSessionTimeoutWarning() {
    // Reset session timer on any user activity
    const resetTimer = () => {
        sessionStartTime = Date.now();
        timeoutWarningShown = false;
        
        // Clear existing timeout
        if (sessionTimeoutId) {
            clearTimeout(sessionTimeoutId);
        }
        
        // Set new timeout for warning
        sessionTimeoutId = setTimeout(showSessionTimeoutWarning, WARNING_TIME_MS);
    };
    
    // Activity events that reset the timer
    ['click', 'keypress', 'scroll', 'mousemove'].forEach(event => {
        document.addEventListener(event, () => {
            const timeSinceStart = Date.now() - sessionStartTime;
            // Only reset if more than 1 minute has passed (avoid constant resets)
            if (timeSinceStart > 60000) {
                resetTimer();
            }
        }, { passive: true });
    });
    
    // Start initial timer
    resetTimer();
}

function showSessionTimeoutWarning() {
    if (timeoutWarningShown) return;
    timeoutWarningShown = true;
    
    const timeRemaining = Math.ceil((SESSION_TIMEOUT_MS - WARNING_TIME_MS) / 60000); // Minutes
    
    const warningHTML = `
        <div class="modal fade" id="sessionTimeoutModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-warning">
                    <div class="modal-header bg-warning text-dark">
                        <h5 class="modal-title">
                            <i class="bi bi-clock-history me-2"></i>Session Timeout Warning
                        </h5>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-warning mb-3">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            <strong>Your session will expire in ${timeRemaining} minutes!</strong>
                        </div>
                        <p>Your uploaded files will be automatically deleted after <strong>60 minutes</strong> of inactivity.</p>
                        <p class="mb-0">Please complete your registration soon or your files will need to be re-uploaded.</p>
                        <div class="mt-3 text-muted small">
                            <i class="bi bi-info-circle me-1"></i>
                            Files are automatically cleaned up for security and storage management.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-primary" data-bs-dismiss="modal" onclick="continueRegistration()">
                            <i class="bi bi-check-circle me-2"></i>I Understand, Continue Registration
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    // Add modal to page if not exists
    if (!document.getElementById('sessionTimeoutModal')) {
        document.body.insertAdjacentHTML('beforeend', warningHTML);
    }
    
    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('sessionTimeoutModal'));
    modal.show();
}

function continueRegistration() {
    // User acknowledged the warning, reset timer
    sessionStartTime = Date.now();
    timeoutWarningShown = false;
    
    if (sessionTimeoutId) {
        clearTimeout(sessionTimeoutId);
    }
    
    sessionTimeoutId = setTimeout(showSessionTimeoutWarning, WARNING_TIME_MS);
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    initSessionTimeoutWarning();
    console.log('✅ Session timeout warning system initialized (Warning at 50 min, Cleanup at 60 min)');
});

// ============================================
// CONNECTION MONITORING
// ============================================

let isOnline = navigator.onLine;
let connectionCheckInterval = null;

function setupConnectionMonitoring() {
    // Monitor online/offline events
    window.addEventListener('online', handleOnline);
    window.addEventListener('offline', handleOffline);
    
    // Periodic connection check (every 30 seconds)
    connectionCheckInterval = setInterval(checkConnection, 30000);
    
    // Initial check
    if (!isOnline) {
        handleOffline();
    }
}

function handleOnline() {
    isOnline = true;
    console.log('✅ Connection restored');
    
    // Remove offline warning if present
    const offlineWarning = document.getElementById('offlineWarning');
    if (offlineWarning) {
        offlineWarning.remove();
    }
    
    showNotifier('Connection restored. You can continue your registration.', 'success');
}

function handleOffline() {
    isOnline = false;
    console.log('❌ Connection lost');
    
    // Show persistent offline warning
    const existingWarning = document.getElementById('offlineWarning');
    if (existingWarning) return;
    
    const warning = document.createElement('div');
    warning.id = 'offlineWarning';
    warning.className = 'alert alert-danger alert-dismissible fade show';
    warning.style.position = 'fixed';
    warning.style.top = '100px';
    warning.style.left = '50%';
    warning.style.transform = 'translateX(-50%)';
    warning.style.zIndex = '10000';
    warning.style.maxWidth = '500px';
    warning.style.width = '90%';
    warning.innerHTML = `
        <i class="bi bi-wifi-off"></i>
        <strong>Connection Lost!</strong>
        <p class="mb-0 mt-2">Your internet connection was interrupted. Please reconnect before continuing your registration. Your uploaded files are temporarily saved.</p>
    `;
    
    document.body.appendChild(warning);
    
    // Disable all upload buttons
    const uploadButtons = document.querySelectorAll('button[id*="Ocr"], button[id*="Upload"]');
    uploadButtons.forEach(btn => {
        btn.disabled = true;
        btn.title = 'No internet connection';
    });
}

async function checkConnection() {
    try {
        // Try to fetch a small resource to check connection
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 5000);
        
        const response = await fetch('student_register.php?ping=1', {
            method: 'HEAD',
            signal: controller.signal
        });
        
        clearTimeout(timeoutId);
        
        if (!isOnline && response.ok) {
            handleOnline();
        }
    } catch (error) {
        if (isOnline) {
            handleOffline();
        }
    }
}

// ❌ REMOVED: Duplicate window.* assignments (now done right after function definitions at line ~7895)
// The functions are already registered globally immediately after being defined

console.log('✅ Connection monitoring ready');
</script>

<!-- ❌ REMOVED: Duplicate user_registration.js load (now loaded earlier after Bootstrap) -->

<script>
    // Letter to Mayor Upload Handling
    document.getElementById('letterToMayorForm').addEventListener('change', function(e) {
        const file = e.target.files[0];
        const previewContainer = document.getElementById('letterUploadPreview');
        const previewImage = document.getElementById('letterPreviewImage');
        const pdfPreview = document.getElementById('letterPdfPreview');
        const ocrSection = document.getElementById('letterOcrSection');
        const processBtn = document.getElementById('processLetterOcrBtn');

        if (file) {
            // Show preview section
            previewContainer.classList.remove('d-none');
            ocrSection.classList.remove('d-none');

            if (file.type.startsWith('image/')) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    previewImage.src = e.target.result;
                    previewImage.style.display = 'block';
                };
                reader.readAsDataURL(file);
            }

            // Enable OCR processing button
            processBtn.disabled = false;
            processBtn.textContent = 'Verify Letter Content';
        } else {
            previewContainer.classList.add('d-none');
            ocrSection.classList.add('d-none');
            processBtn.disabled = true;
        }
    });

    // Letter to Mayor OCR Processing
    document.getElementById('processLetterOcrBtn').addEventListener('click', async function() {
        // Disable Back buttons during processing
        setBackButtonsDisabled(true);
        const formData = new FormData();
        const fileInput = document.getElementById('letterToMayorForm');
        const file = fileInput.files[0];

        if (!file) {
            alert('Please select a letter file first.');
            return;
        }

        // Add form data
        formData.append('letter_to_mayor', file);
        formData.append('processLetterOcr', '1');
        formData.append('first_name', document.querySelector('input[name="first_name"]').value);
        formData.append('last_name', document.querySelector('input[name="last_name"]').value);
        formData.append('barangay_id', document.querySelector('select[name="barangay_id"]').value);

        // Add reCAPTCHA token
        if (typeof grecaptcha !== 'undefined' && window.RECAPTCHA_SITE_KEY) {
            try {
                const token = await new Promise(res => grecaptcha.ready(() => {
                    grecaptcha.execute(window.RECAPTCHA_SITE_KEY, {action:'process_letter_ocr'})
                        .then(t => res(t)).catch(() => res(''));
                }));
                if (token) formData.append('g-recaptcha-response', token);
            } catch(e) { /* ignore */ }
        }

        // Show processing state
        this.disabled = true;
        this.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Processing...';

        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                displayLetterVerificationResults(data.verification);
            } else {
                // Enhanced error display for PDFs and suggestions
                let errorMessage = data.message;
                
                // Add debug info if available
                if (data.debug) {
                    console.error('Letter verification error:', data.debug);
                    errorMessage += '\n\nTechnical details: ' + data.debug;
                }
                
                if (data.suggestions && data.suggestions.length > 0) {
                    errorMessage += '\n\nSuggestions:\n' + data.suggestions.join('\n');
                }
                alert(errorMessage);
                
                // Also show suggestions in the feedback area
                if (data.suggestions) {
                    const resultsDiv = document.getElementById('letterOcrResults');
                    const feedbackDiv = document.getElementById('letterOcrFeedback');
                    
                    resultsDiv.classList.remove('d-none');
                    feedbackDiv.style.display = 'block';
                    feedbackDiv.className = 'alert alert-warning mt-3';
                    
                    let suggestionHTML = '<strong>' + data.message + '</strong><br><br>';
                    suggestionHTML += '<strong>Please try:</strong><ul>';
                    data.suggestions.forEach(suggestion => {
                        suggestionHTML += '<li>' + suggestion + '</li>';
                    });
                    suggestionHTML += '</ul>';
                    
                    feedbackDiv.innerHTML = suggestionHTML;
                }
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred during processing.');
        })
        .finally(() => {
            this.disabled = false;
            this.innerHTML = '<i class="bi bi-search me-2"></i>Verify Letter Content';
            // Re-enable Back buttons
            setBackButtonsDisabled(false);
        });
    });

    function displayLetterVerificationResults(verification) {
        const resultsDiv = document.getElementById('letterOcrResults');
        const feedbackDiv = document.getElementById('letterOcrFeedback');
        const nextButton = document.getElementById('nextStep6Btn'); // Fixed: Changed from nextStep5Btn to nextStep6Btn

        // Update verification checklist with confidence scores
        updateVerificationCheckWithDetails('check-letter-firstname', verification.first_name, 
            verification.confidence_scores?.first_name, verification.found_text_snippets?.first_name);
        updateVerificationCheckWithDetails('check-letter-lastname', verification.last_name, 
            verification.confidence_scores?.last_name, verification.found_text_snippets?.last_name);
        updateVerificationCheckWithDetails('check-letter-barangay', verification.barangay, 
            verification.confidence_scores?.barangay, verification.found_text_snippets?.barangay);
        updateVerificationCheckWithDetails('check-letter-header', verification.mayor_header, 
            verification.confidence_scores?.mayor_header, verification.found_text_snippets?.mayor_header);
        updateVerificationCheckWithDetails('check-letter-municipality', verification.municipality, 
            verification.confidence_scores?.municipality, verification.found_text_snippets?.municipality);

        // Show results
        resultsDiv.classList.remove('d-none');

        // Update feedback with detailed information
        if (verification.overall_success) {
            feedbackDiv.style.display = 'none';
            nextButton.disabled = false;
            nextButton.classList.add('btn-success');
            nextButton.classList.remove('btn-secondary', 'btn-primary');
            nextButton.innerHTML = '<i class="bi bi-check-circle me-2"></i>Continue - Document Verified';
        } else {
            // Show detailed feedback
            let feedbackMessage = `<strong>Verification Result:</strong> ${verification.summary?.recommendation || 'Some checks failed'}<br>`;
            feedbackMessage += `<small>Passed ${verification.summary?.passed_checks || 0} of ${verification.summary?.total_checks || 4} checks`;
            if (verification.summary?.average_confidence) {
                feedbackMessage += ` (Average confidence: ${verification.summary.average_confidence}%)`;
            }
            feedbackMessage += `</small>`;
            
            feedbackDiv.innerHTML = feedbackMessage;
            feedbackDiv.style.display = 'block';
            nextButton.disabled = true;
            nextButton.classList.remove('btn-success');
            nextButton.classList.add('btn-primary');
        }
    }

    function updateVerificationCheck(elementId, passed) {
        const element = document.getElementById(elementId);
        const icon = element.querySelector('i');
        
        if (passed) {
            icon.className = 'bi bi-check-circle text-success me-2';
            element.classList.add('text-success');
            element.classList.remove('text-danger');
        } else {
            icon.className = 'bi bi-x-circle text-danger me-2';
            element.classList.add('text-danger');
            element.classList.remove('text-success');
        }
    }
    
    // Enhanced version with confidence scores and found text
    function updateVerificationCheckWithDetails(elementId, passed, confidence, foundText) {
        const element = document.getElementById(elementId);
        const icon = element.querySelector('i');
        const textSpan = element.querySelector('span');
        
        if (passed) {
            icon.className = 'bi bi-check-circle text-success me-2';
            element.classList.add('text-success');
            element.classList.remove('text-danger');
            
            // Add confidence score and found text if available
            let originalText = textSpan.textContent.split(' (')[0]; // Remove any existing details
            let details = '';
            if (confidence !== undefined) {
                details += ` (${Math.round(confidence)}% match`;
                if (foundText) {
                    details += `, found: "${foundText}"`;
                }
                details += ')';
            }
            textSpan.innerHTML = originalText + '<small class="text-muted">' + details + '</small>';
        } else {
            icon.className = 'bi bi-x-circle text-danger me-2';
            element.classList.add('text-danger');
            element.classList.remove('text-success');
            
            // Show confidence if available for failed checks
            let originalText = textSpan.textContent.split(' (')[0];
            let details = '';
            if (confidence !== undefined && confidence > 0) {
                details += ` <small class="text-muted">(${Math.round(confidence)}% match - needs 70%+)</small>`;
            }
            textSpan.innerHTML = originalText + details;
        }
    }

    // Certificate of Indigency Upload Handling
    document.getElementById('certificateForm').addEventListener('change', function(e) {
        const file = e.target.files[0];
        const previewContainer = document.getElementById('certificateUploadPreview');
        const previewImage = document.getElementById('certificatePreviewImage');
        const pdfPreview = document.getElementById('certificatePdfPreview');
        const ocrSection = document.getElementById('certificateOcrSection');
        const processBtn = document.getElementById('processCertificateOcrBtn');

        if (file) {
            // Show preview section
            previewContainer.classList.remove('d-none');
            ocrSection.classList.remove('d-none');

            if (file.type.startsWith('image/')) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    previewImage.src = e.target.result;
                    previewImage.style.display = 'block';
                };
                reader.readAsDataURL(file);
            }

            // Enable OCR processing button
            processBtn.disabled = false;
            processBtn.textContent = 'Verify Certificate Content';
        } else {
            previewContainer.classList.add('d-none');
            ocrSection.classList.add('d-none');
            processBtn.disabled = true;
        }
    });

    // Certificate OCR Processing
    document.getElementById('processCertificateOcrBtn').addEventListener('click', async function() {
        // Disable Back buttons during processing
        setBackButtonsDisabled(true);
        const formData = new FormData();
        const fileInput = document.getElementById('certificateForm');
        const file = fileInput.files[0];

        if (!file) {
            alert('Please select a certificate file first.');
            return;
        }

        // Add form data
        formData.append('certificate_of_indigency', file);
        formData.append('processCertificateOcr', '1');
        formData.append('first_name', document.querySelector('input[name="first_name"]').value);
        formData.append('last_name', document.querySelector('input[name="last_name"]').value);
        formData.append('barangay_id', document.querySelector('select[name="barangay_id"]').value);

        // Add reCAPTCHA token
        if (typeof grecaptcha !== 'undefined' && window.RECAPTCHA_SITE_KEY) {
            try {
                const token = await new Promise(res => grecaptcha.ready(() => {
                    grecaptcha.execute(window.RECAPTCHA_SITE_KEY, {action:'process_certificate_ocr'})
                        .then(t => res(t)).catch(() => res(''));
                }));
                if (token) formData.append('g-recaptcha-response', token);
            } catch(e) { /* ignore */ }
        }

        // Show processing state
        this.disabled = true;
        this.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Processing...';

        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                displayCertificateVerificationResults(data.verification);
            } else {
                // Enhanced error display for PDFs and suggestions
                let errorMessage = data.message;
                if (data.suggestions && data.suggestions.length > 0) {
                    errorMessage += '\n\nSuggestions:\n' + data.suggestions.join('\n');
                }
                alert(errorMessage);
                
                // Also show suggestions in the feedback area
                if (data.suggestions) {
                    const resultsDiv = document.getElementById('certificateOcrResults');
                    const feedbackDiv = document.getElementById('certificateOcrFeedback');
                    
                    resultsDiv.classList.remove('d-none');
                    feedbackDiv.style.display = 'block';
                    feedbackDiv.className = 'alert alert-warning mt-3';
                    
                    let suggestionHTML = '<strong>' + data.message + '</strong><br><br>';
                    suggestionHTML += '<strong>Please try:</strong><ul>';
                    data.suggestions.forEach(suggestion => {
                        suggestionHTML += '<li>' + suggestion + '</li>';
                    });
                    suggestionHTML += '</ul>';
                    
                    feedbackDiv.innerHTML = suggestionHTML;
                }
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred during processing.');
        })
        .finally(() => {
            this.disabled = false;
            this.innerHTML = '<i class="bi bi-search me-2"></i>Verify Certificate Content';
            // Re-enable Back buttons
            setBackButtonsDisabled(false);
        });
    });

    function displayCertificateVerificationResults(verification) {
        const resultsDiv = document.getElementById('certificateOcrResults');
        const feedbackDiv = document.getElementById('certificateOcrFeedback');
        const nextButton = document.getElementById('nextStep7Btn'); // Fixed: Changed from nextStep6Btn to nextStep7Btn

        // Update verification checklist with confidence scores
        updateVerificationCheckWithDetails('check-certificate-title', verification.certificate_title, 
            verification.confidence_scores?.certificate_title, verification.found_text_snippets?.certificate_title);
        updateVerificationCheckWithDetails('check-certificate-firstname', verification.first_name, 
            verification.confidence_scores?.first_name, verification.found_text_snippets?.first_name);
        updateVerificationCheckWithDetails('check-certificate-lastname', verification.last_name, 
            verification.confidence_scores?.last_name, verification.found_text_snippets?.last_name);
        updateVerificationCheckWithDetails('check-certificate-barangay', verification.barangay, 
            verification.confidence_scores?.barangay, verification.found_text_snippets?.barangay);
        updateVerificationCheckWithDetails('check-certificate-city', verification.municipality, 
            verification.confidence_scores?.municipality, verification.found_text_snippets?.municipality);

        // Show results
        resultsDiv.classList.remove('d-none');

        // Update feedback with detailed information
        if (verification.overall_success) {
            feedbackDiv.style.display = 'none';
            nextButton.disabled = false;
            nextButton.classList.add('btn-success');
            nextButton.classList.remove('btn-secondary', 'btn-primary');
            nextButton.innerHTML = '<i class="bi bi-check-circle me-2"></i>Continue - Document Verified';
        } else {
            // Show detailed feedback
            let feedbackMessage = `<strong>Verification Result:</strong> ${verification.summary?.recommendation || 'Some checks failed'}<br>`;
            feedbackMessage += `<small>Passed ${verification.summary?.passed_checks || 0} of ${verification.summary?.total_checks || 5} checks`;
            if (verification.summary?.average_confidence) {
                feedbackMessage += ` (Average confidence: ${verification.summary.average_confidence}%)`;
            }
            feedbackMessage += `</small>`;
            
            feedbackDiv.innerHTML = feedbackMessage;
            feedbackDiv.style.display = 'block';
            nextButton.disabled = true;
            nextButton.classList.remove('btn-success');
            nextButton.classList.add('btn-primary');
        }
    }

// Grades Document Upload Handling
document.getElementById('gradesForm').addEventListener('change', function(e) {
    const file = e.target.files[0];
    const previewContainer = document.getElementById('gradesUploadPreview');
    const previewImage = document.getElementById('gradesPreviewImage');
    const pdfPreview = document.getElementById('gradesPdfPreview');
    const ocrSection = document.getElementById('gradesOcrSection');
    const processBtn = document.getElementById('processGradesOcrBtn');

    if (file) {
        previewContainer.classList.remove('d-none');
        ocrSection.classList.remove('d-none');

        if (file.type.startsWith('image/')) {
            const reader = new FileReader();
            reader.onload = function(e) {
                previewImage.src = e.target.result;
                previewImage.style.display = 'block';
            };
            reader.readAsDataURL(file);
        }

        processBtn.disabled = false;
    } else {
        previewContainer.classList.add('d-none');
        ocrSection.classList.add('d-none');
        processBtn.disabled = true;
    }
});

// Grades OCR Processing
document.getElementById('processGradesOcrBtn').addEventListener('click', async function() {
    // Disable Back buttons during processing
    setBackButtonsDisabled(true);
    const formData = new FormData();
    const fileInput = document.getElementById('gradesForm');
    const file = fileInput.files[0];

    if (!file) {
        alert('Please select a grades file first.');
        return;
    }

    // Add form data
    formData.append('grades_document', file);
    formData.append('processGradesOcr', '1');
    formData.append('first_name', document.querySelector('input[name="first_name"]').value);
    formData.append('last_name', document.querySelector('input[name="last_name"]').value);
    formData.append('year_level_id', document.querySelector('select[name="year_level_id"]').value);
    formData.append('university_id', document.querySelector('select[name="university_id"]').value);
    formData.append('school_student_id', document.querySelector('input[name="school_student_id"]').value); // Added for validation

    // Add reCAPTCHA token
    if (typeof grecaptcha !== 'undefined' && window.RECAPTCHA_SITE_KEY) {
        try {
            const token = await new Promise(res => grecaptcha.ready(() => {
                grecaptcha.execute(window.RECAPTCHA_SITE_KEY, {action:'process_grades_ocr'})
                    .then(t => res(t)).catch(() => res(''));
            }));
            if (token) formData.append('g-recaptcha-response', token);
        } catch(e) { /* ignore */ }
    }

    this.disabled = true;
    this.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Processing...';

    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            displayGradesVerificationResults(data.verification);
        } else {
            let errorMessage = data.message;
            if (data.suggestions && data.suggestions.length > 0) {
                errorMessage += '\n\nSuggestions:\n' + data.suggestions.join('\n');
            }
            
            // Show debug info in console for troubleshooting
            if (data.debug_info) {
                console.log('OCR Debug Information:', data.debug_info);
            }
            
            alert(errorMessage);
            
            const resultsDiv = document.getElementById('gradesOcrResults');
            const feedbackDiv = document.getElementById('gradesOcrFeedback');
            
            resultsDiv.classList.remove('d-none');
            feedbackDiv.style.display = 'block';
            feedbackDiv.className = 'alert alert-danger mt-3';
            
            let suggestionHTML = '<strong><i class="bi bi-exclamation-triangle me-2"></i>' + data.message + '</strong>';
            
            if (data.suggestions) {
                suggestionHTML += '<br><br><strong>Please try:</strong><ul>';
                data.suggestions.forEach(suggestion => {
                    suggestionHTML += '<li>' + suggestion + '</li>';
                });
                suggestionHTML += '</ul>';
            }
            
            // Add debug information if available
            if (data.debug_info) {
                suggestionHTML += '<br><br><details><summary><strong>Technical Details</strong> (click to expand)</summary>';
                suggestionHTML += '<small>';
                if (data.debug_info.file_size) {
                    suggestionHTML += 'File size: ' + (data.debug_info.file_size / 1024).toFixed(1) + ' KB<br>';
                }
                if (data.debug_info.file_extension) {
                    suggestionHTML += 'File type: ' + data.debug_info.file_extension + '<br>';
                }
                if (data.debug_info.text_length !== undefined) {
                    suggestionHTML += 'Extracted text length: ' + data.debug_info.text_length + ' characters<br>';
                }
                if (data.debug_info.tesseract_output) {
                    suggestionHTML += 'Tesseract output: <pre>' + data.debug_info.tesseract_output + '</pre>';
                }
                suggestionHTML += '</small></details>';
            }
            
            // Add link to debug tool
            suggestionHTML += '<br><br><a href="debug_ocr.php" target="_blank" class="btn btn-sm btn-outline-info">🔍 Use OCR Debug Tool</a>';
            
            feedbackDiv.innerHTML = suggestionHTML;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred during processing.');
    })
    .finally(() => {
        this.disabled = false;
        this.innerHTML = '<i class="bi bi-search me-2"></i>Verify Grades Content';
        
        // Track uploaded file on success
        const fileInput = document.getElementById('gradesForm');
        if (fileInput && fileInput.files[0]) {
            trackUploadedFile('grades', fileInput.files[0].name);
            markFileAsUploaded();
        }
        // Re-enable Back buttons
        setBackButtonsDisabled(false);
    });
});

    function displayGradesVerificationResults(verification) {
        const resultsDiv = document.getElementById('gradesOcrResults');
        const feedbackDiv = document.getElementById('gradesOcrFeedback');
        const gradesDetails = document.getElementById('gradesDetails');
        const gradesTable = document.getElementById('gradesTable');
        const nextButton = document.getElementById('nextStep8Btn'); // Fixed: Changed from nextStep7Btn to nextStep8Btn
        const eligibilityStatus = document.getElementById('eligibilityStatus');
        const eligibilityText = document.getElementById('eligibilityText');

        // Update verification checklist with new validation structure
        updateVerificationCheckWithDetails('check-grades-name', verification.name_match, 
            verification.confidence_scores?.name, verification.found_text_snippets?.name);
        updateVerificationCheckWithDetails('check-grades-year', verification.year_level_match, 
            verification.confidence_scores?.year_level, verification.found_text_snippets?.year_level);
        updateVerificationCheckWithDetails('check-grades-semester', verification.semester_match, 
            verification.confidence_scores?.semester, verification.found_text_snippets?.semester);
        updateVerificationCheckWithDetails('check-grades-school-year', verification.school_year_match, 
            verification.confidence_scores?.school_year, verification.found_text_snippets?.school_year);
        updateVerificationCheckWithDetails('check-grades-university', verification.university_match, 
            verification.confidence_scores?.university, verification.found_text_snippets?.university);
        updateVerificationCheckWithDetails('check-grades-student-id', verification.school_student_id_match, 
            verification.confidence_scores?.school_student_id, verification.found_text_snippets?.school_student_id);
        updateVerificationCheckWithDetails('check-grades-passing', verification.all_grades_passing, 
            verification.confidence_scores?.grades, null);

        // Update eligibility status
        if (verification.is_eligible) {
            eligibilityStatus.className = 'alert alert-success';
            eligibilityText.innerHTML = '<i class="bi bi-check-circle me-2"></i><strong>ELIGIBLE</strong> - All validations passed';
        } else {
            eligibilityStatus.className = 'alert alert-danger';
            eligibilityText.innerHTML = '<i class="bi bi-x-circle me-2"></i><strong>INELIGIBLE</strong> - ' + verification.summary?.recommendation;
        }

        // Show results
        resultsDiv.classList.remove('d-none');

        // Display grades table with enhanced validation info
        if (verification.grades && verification.grades.length > 0) {
            gradesDetails.classList.remove('d-none');
            
            // Show validation method used
            let validationMethodBadge = '';
            if (verification.validation_method === 'enhanced_per_subject') {
                validationMethodBadge = '<span class="badge bg-info mb-2">Enhanced Per-Subject Validation</span>';
            } else {
                validationMethodBadge = '<span class="badge bg-secondary mb-2">Legacy Threshold Validation</span>';
            }
            
            let tableHTML = `
                ${validationMethodBadge}
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Subject</th>
                            <th>Grade</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
            `;
            
            verification.grades.forEach(grade => {
                let status = '';
                let policyInfo = '';
                
                if (verification.validation_method === 'enhanced_per_subject') {
                    // Enhanced validation - check against university policy
                    const isFailingSubject = verification.enhanced_grade_validation?.failed_subjects?.some(
                        failedSubject => failedSubject.includes(grade.subject)
                    ) || false;
                    
                    status = isFailingSubject ? 
                        '<span class="text-danger">Fail</span>' : 
                        '<span class="text-success">Pass</span>';
                        
                        policyInfo = `<td><small>${verification.university_code || 'N/A'}</small></td>`;
                } else {
                    // Legacy validation - 3.00 threshold
                    status = parseFloat(grade.grade) <= 3.00 ? 
                        '<span class="text-success">Pass</span>' : 
                        '<span class="text-danger">Fail</span>';
                }
                
                // Get the grade value (fallback chain for compatibility)
                const gradeValue = grade.grade || grade.final || grade.midterm || grade.prelim || 'N/A';
                const canonicalGrade = parseFloat(gradeValue || '0');

                // Recompute status for legacy method if needed
                if (verification.validation_method !== 'enhanced_per_subject') {
                    status = !isNaN(canonicalGrade) && canonicalGrade <= 3.00 ?
                        '<span class="text-success">Pass</span>' : '<span class="text-danger">Fail</span>';
                }

                tableHTML += `
                    <tr>
                        <td>${grade.subject}</td>
                        <td>${gradeValue}</td>
                        <td>${status}</td>
                    </tr>
                `;
            });            tableHTML += '</tbody></table>';
            
            // Add enhanced validation summary if available
            if (verification.enhanced_grade_validation && verification.enhanced_grade_validation.success) {
                tableHTML += `
                    <div class="mt-2 p-2 bg-light rounded">
                        <small class="text-muted">
                            <strong>Enhanced Validation Summary:</strong><br>
                            Total Subjects: ${verification.enhanced_grade_validation.total_subjects}<br>
                            Passed: ${verification.enhanced_grade_validation.passed_subjects}<br>
                            Failed: ${verification.enhanced_grade_validation.failed_subjects.length}
                            ${verification.enhanced_grade_validation.failed_subjects.length > 0 ? 
                                '<br>Failed Subjects: ' + verification.enhanced_grade_validation.failed_subjects.join(', ') : ''
                            }
                        </small>
                    </div>
                `;
            }
            
            gradesTable.innerHTML = tableHTML;
        }

        // Update feedback and next button based on eligibility
        if (verification.is_eligible) {
            feedbackDiv.style.display = 'none';
            nextButton.disabled = false;
            nextButton.classList.add('btn-success');
            nextButton.classList.remove('btn-secondary', 'btn-primary');
            nextButton.innerHTML = '<i class="bi bi-check-circle me-2"></i>Continue - Student Eligible';
        } else {
            let feedbackMessage = `<strong>Eligibility Assessment:</strong> ${verification.summary?.recommendation || 'Student is not eligible'}<br>`;
            feedbackMessage += `<small>Validation Status: ${verification.summary?.eligibility_status || 'INELIGIBLE'}</small><br>`;
            feedbackMessage += `<small>Passed ${verification.summary?.passed_checks || 0} of ${verification.summary?.total_checks || 5} validation checks</small>`;
            
            // Show specific validation failures
            if (!verification.year_level_match) {
                feedbackMessage += '<br><br><strong>⚠️ Year Level Mismatch:</strong> The declared year level does not match what was found on the grade card.';
            }
            if (!verification.semester_match) {
                const requiredSemester = verification.admin_requirements?.required_semester || 'Not specified';
                feedbackMessage += `<br><br><strong>⚠️ Semester Mismatch:</strong> Grade card does not show required semester: "${requiredSemester}".`;
            }
            // TEMPORARILY DISABLED FOR TESTING
            // if (!verification.school_year_match) {
            //     const requiredSchoolYear = verification.admin_requirements?.required_school_year || 'Not specified';
            //     feedbackMessage += `<br><br><strong>⚠️ School Year Mismatch:</strong> Grade card does not show required school year: "${requiredSchoolYear}".`;
            // }
            if (!verification.university_match) {
                feedbackMessage += '<br><br><strong>⚠️ University Mismatch:</strong> The declared university does not match what was found on the grade card.';
            }
            if (!verification.all_grades_passing) {
                feedbackMessage += '<br><br><strong>⚠️ Failing Grades Found:</strong><ul>';
                verification.failing_grades.forEach(grade => {
                    feedbackMessage += `<li>${grade.subject}: ${grade.grade} (Grade ≥ 3.00 is failing)</li>`;
                });
                feedbackMessage += '</ul>';
            }
            if (!verification.name_match) {
                feedbackMessage += '<br><br><strong>⚠️ Name Mismatch:</strong> Student name not clearly found on the grade card.';
            }
            if (!verification.school_student_id_match) {
                feedbackMessage += '<br><br><strong>⚠️ School Student ID Mismatch:</strong> The school student ID from Step 3 was not found on the grade card. Please ensure your grades document shows your student ID number.';
            }
            
            feedbackDiv.innerHTML = feedbackMessage;
            feedbackDiv.style.display = 'block';
            nextButton.disabled = true;
            nextButton.classList.remove('btn-success');
            nextButton.classList.add('btn-danger');
            nextButton.innerHTML = '<i class="bi bi-x-circle me-2"></i>Cannot Continue - Ineligible';
        }
    }
</script>

<!-- ADD this modal HTML before closing </body> tag -->
<!-- Terms and Conditions Modal -->
<div class="modal fade" id="termsModal" tabindex="-1" aria-labelledby="termsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="termsModalLabel">
                    <i class="bi bi-file-text me-2"></i>Terms and Conditions
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="terms-content">
                    <h6>1. Eligibility Requirements</h6>
                    <p>To be eligible for EducAid scholarship, applicants must:</p>
                    <ul>
                        <li>Be currently enrolled in an accredited university/college</li>
                        <li>Maintain good academic standing</li>
                        <li>Be a resident of the participating municipality</li>
                        <li>Meet financial need requirements</li>
                    </ul>

                    <h6>2. Application Process</h6>
                    <p>All applicants must complete the online registration process and provide:</p>
                    <ul>
                        <li>Valid enrollment assessment form</li>
                        <li>Accurate personal and academic information</li>
                        <li>Valid email address and phone number for verification</li>
                    </ul>

                    <h6>3. Data Privacy and Security</h6>
                    <p>By registering, you consent to the collection and processing of your personal data for:</p>
                    <ul>
                        <li>Scholarship application evaluation</li>
                        <li>Communication regarding application status</li>
                        <li>Academic monitoring and reporting</li>
                        <li>Statistical analysis for program improvement</li>
                    </ul>

                    <h6>4. Obligations and Responsibilities</h6>
                    <p>If selected for the scholarship, recipients must:</p>
                    <ul>
                        <li>Maintain satisfactory academic performance</li>
                        <li>Provide regular updates on academic progress</li>
                        <li>Attend mandatory orientation and meetings</li>
                        <li>Use scholarship funds exclusively for educational purposes</li>
                    </ul>

                    <h6>5. Program Rules and Regulations</h6>
                    <ul>
                        <li>Scholarship awards are subject to available funding</li>
                        <li>Recipients must complete their program within the standard timeframe</li>
                        <li>Failure to meet requirements may result in scholarship termination</li>
                        <li>False information in the application may lead to immediate disqualification</li>
                    </ul>

                    <h6>6. Contact and Support</h6>
                    <p>For questions or concerns about the EducAid program, please contact:</p>
                    <ul>
                        <li>Email: support@educaid.gov.ph</li>
                        <li>Phone: (123) 456-7890</li>
                        <li>Office Hours: Monday to Friday, 8:00 AM - 5:00 PM</li>
                    </ul>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" id="acceptTermsBtn">
                    <i class="bi bi-check-circle me-2"></i>I Accept
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Terms and Conditions Modal Functionality -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // ============================================================
    // BYPASS MODE STATUS CHECK
    // ============================================================
    <?php if (isset($_SESSION['household_bypass_active']) && $_SESSION['household_bypass_active'] === true): ?>
        console.log('🔓 BYPASS MODE ACTIVE - Admin approved override');
        console.log('   Bypass Token:', '<?= $_SESSION['household_bypass_token'] ?? 'N/A' ?>');
        console.log('   Attempt ID:', <?= $_SESSION['household_bypass_attempt_id'] ?? 'N/A' ?>);
        console.log('   Household validation will be SKIPPED');
    <?php else: ?>
        console.log('🔒 NORMAL MODE - Household validation ACTIVE');
    <?php endif; ?>
    // ============================================================
    
    // ============================================================
    // PRE-FILL FORM DATA (from bypass token / admin override)
    // ============================================================
    <?php if (isset($_SESSION['prefill_data']) && isset($_SESSION['prefill_fresh']) && $_SESSION['prefill_fresh'] === true): ?>
        const prefillData = <?= json_encode($_SESSION['prefill_data']) ?>;
        console.log('✅ Pre-fill data available:', prefillData);
        
        // Pre-fill text inputs
        if (prefillData.first_name) {
            const firstNameInput = document.querySelector('input[name="first_name"]');
            if (firstNameInput) {
                firstNameInput.value = prefillData.first_name;
                console.log('✓ Pre-filled first_name:', prefillData.first_name);
            }
        }
        
        if (prefillData.last_name) {
            const lastNameInput = document.querySelector('input[name="last_name"]');
            if (lastNameInput) {
                lastNameInput.value = prefillData.last_name;
                console.log('✓ Pre-filled last_name:', prefillData.last_name);
            }
        }
        
        if (prefillData.email) {
            const emailInput = document.querySelector('input[name="email"]');
            if (emailInput) {
                emailInput.value = prefillData.email;
                console.log('✓ Pre-filled email:', prefillData.email);
            }
        }
        
        if (prefillData.mobile) {
            const mobileInput = document.querySelector('input[name="mobile"]');
            if (mobileInput) {
                mobileInput.value = prefillData.mobile;
                console.log('✓ Pre-filled mobile:', prefillData.mobile);
            }
        }
        
        if (prefillData.mothers_maiden_name) {
            const motherNameInput = document.querySelector('input[name="mothers_maiden_name"]');
            if (motherNameInput) {
                motherNameInput.value = prefillData.mothers_maiden_name;
                console.log('✓ Pre-filled mothers_maiden_name:', prefillData.mothers_maiden_name);
            }
        }
        
        // Pre-fill barangay select
        if (prefillData.barangay_id) {
            setTimeout(() => {
                const barangaySelect = document.querySelector('select[name="barangay_id"]');
                if (barangaySelect) {
                    barangaySelect.value = prefillData.barangay_id;
                    console.log('✓ Pre-filled barangay_id:', prefillData.barangay_id);
                    // Trigger change event in case there are any listeners
                    barangaySelect.dispatchEvent(new Event('change'));
                }
            }, 100); // Small delay to ensure select is populated
        }
        
        console.log('🎯 Form pre-filled successfully! Student can now proceed with registration.');
        
        <?php 
        // IMPORTANT: Clear prefill flags immediately after displaying the form
        // This ensures the data only appears once and doesn't persist on page reload
        unset($_SESSION['prefill_data']);
        unset($_SESSION['prefill_fresh']);
        ?>
    <?php else: ?>
        console.log('ℹ️ No prefill data available (normal registration flow)');
    <?php endif; ?>
    // ============================================================
    
    // Terms and Conditions modal functionality
    setupTermsAndConditions();
    
    // School Student ID duplicate checking
    setupSchoolStudentIdCheck();
    
    // Birthdate age validation (16 years minimum)
    setupBirthdateValidation();
    
    // Wait a moment for external scripts to load
    setTimeout(function() {
        console.log('🔍 Final function check:', {
            nextStep: typeof window.nextStep,
            prevStep: typeof window.prevStep,
            showStep: typeof window.showStep,
            bootstrap: typeof window.bootstrap
        });
        
        // Test if functions work
        const testButton = document.createElement('button');
        testButton.onclick = function() {
            console.log('✅ nextStep function test successful');
        };
        
        console.log('✅ Registration page initialization complete');
    });
});

// Real-time School Student ID duplicate check
function setupSchoolStudentIdCheck() {
    let schoolStudentIdCheckTimeout = null;
    const schoolStudentIdInput = document.getElementById('schoolStudentId');
    const universitySelect = document.getElementById('universitySelect');
    
    if (!schoolStudentIdInput || !universitySelect) {
        console.log('⚠️ School student ID or university select not found');
        return;
    }
    
    console.log('✅ School student ID check initialized');
    
    // Check on school student ID input
    schoolStudentIdInput.addEventListener('input', function() {
        clearTimeout(schoolStudentIdCheckTimeout);
        
        const schoolStudentId = this.value.trim();
        const universityId = universitySelect.value;
        const warningDiv = document.getElementById('schoolStudentIdDuplicateWarning');
        const availableDiv = document.getElementById('schoolStudentIdAvailable');
        const nextBtn = document.getElementById('nextStep3Btn');
        
        // Reset states
        if (warningDiv) warningDiv.style.display = 'none';
        if (availableDiv) availableDiv.style.display = 'none';
        
        if (schoolStudentId.length < 3 || !universityId) {
            return;
        }
        
        // Debounce API call
        schoolStudentIdCheckTimeout = setTimeout(async () => {
            await checkSchoolStudentIdDuplicate(schoolStudentId, universityId, warningDiv, availableDiv, nextBtn);
        }, 800); // 800ms debounce
    });
    
    // Re-check when university changes
    universitySelect.addEventListener('change', function() {
        const schoolStudentId = schoolStudentIdInput.value.trim();
        if (schoolStudentId.length >= 3) {
            schoolStudentIdInput.dispatchEvent(new Event('input'));
        }
    });
}

async function checkSchoolStudentIdDuplicate(schoolStudentId, universityId, warningDiv, availableDiv, nextBtn) {
    const formData = new FormData();
    formData.append('check_school_student_id', '1');
    formData.append('school_student_id', schoolStudentId);
    formData.append('university_id', universityId);
    
    // Include name and birthdate if available for identity matching
    const firstName = document.querySelector('input[name="first_name"]')?.value;
    const lastName = document.querySelector('input[name="last_name"]')?.value;
    const bdate = document.querySelector('input[name="bdate"]')?.value;
    
    if (firstName) formData.append('first_name', firstName);
    if (lastName) formData.append('last_name', lastName);
    if (bdate) formData.append('bdate', bdate);
    
    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.status === 'duplicate') {
            if (warningDiv) {
                let message = `<i class="bi bi-exclamation-triangle me-2"></i><strong>School Student ID Already Registered!</strong><br>`;
                message += `<small>Registered to: ${data.details.name}<br>`;
                message += `System ID: ${data.details.system_student_id}<br>`;
                message += `Status: ${data.details.status}<br>`;
                message += `Registered on: ${new Date(data.details.registered_at).toLocaleDateString()}<br>`;
                
                if (data.details.identity_match) {
                    message += `<br><span class="text-danger fw-bold">⚠️ This appears to be YOUR existing account.</span><br>`;
                    message += `Email: ${data.details.email_hint}<br>`;
                    message += `Mobile: ${data.details.mobile_hint}<br>`;
                    message += `<br><strong class="text-danger">🛑 MULTIPLE ACCOUNTS PROHIBITED</strong><br>`;
                    message += `You cannot create multiple accounts. Please login with your existing credentials.`;
                } else {
                    message += `<br><span class="text-danger fw-bold">⚠️ This school student ID belongs to another person.</span><br>`;
                    message += `<strong class="text-danger">🛑 MULTIPLE ACCOUNTS PROHIBITED</strong><br>`;
                    message += `Creating multiple accounts is strictly prohibited and may result in permanent disqualification.`;
                }
                
                if (data.details.can_reapply) {
                    message += `<br><br><span class="text-info">Note: Previous application was ${data.details.status}. You may reapply by logging in with your existing credentials.</span>`;
                }
                
                message += `</small>`;
                
                warningDiv.innerHTML = message;
                warningDiv.style.display = 'block';
                warningDiv.className = 'alert alert-danger mt-2';
            }
            
            // Disable next button
            if (nextBtn) {
                nextBtn.disabled = true;
                nextBtn.classList.remove('btn-primary');
                nextBtn.classList.add('btn-secondary');
            }
            
            // Show system notifier
            showNotifier(
                '🛑 MULTIPLE ACCOUNT DETECTED: This school student ID is already registered. Creating multiple accounts is strictly prohibited and will result in disqualification.', 
                'error'
            );
            
        } else if (data.status === 'available') {
            if (availableDiv) {
                availableDiv.style.display = 'block';
            }
            
            // Enable next button
            if (nextBtn) {
                nextBtn.disabled = false;
                nextBtn.classList.remove('btn-secondary');
                nextBtn.classList.add('btn-primary');
            }
        }
    } catch (error) {
        console.error('School student ID check error:', error);
    }
}

// Birthdate validation - Must be 16 years or older
function setupBirthdateValidation() {
    const bdateInput = document.querySelector('input[name="bdate"]');
    
    if (!bdateInput) {
        console.log('⚠️ Birthdate input not found');
        return;
    }
    
    // DYNAMIC: Calculate max date based on CURRENT date (16 years ago from today)
    // This ensures the validation is always accurate regardless of the current year
    const today = new Date();
    const maxDate = new Date(today.getFullYear() - 16, today.getMonth(), today.getDate());
    const maxDateString = maxDate.toISOString().split('T')[0];
    const minDate = new Date(today.getFullYear() - 100, today.getMonth(), today.getDate());
    const minDateString = minDate.toISOString().split('T')[0];
    
    // FORCE set dynamic attributes (remove any old values first)
    bdateInput.removeAttribute('max');
    bdateInput.removeAttribute('min');
    bdateInput.setAttribute('max', maxDateString);
    bdateInput.setAttribute('min', minDateString);
    
    // UX IMPROVEMENT: Set default value to max date (2009) so calendar opens to correct year
    // This is ONLY for calendar navigation - user still must actively select a date
    // The value will be cleared on blur if not explicitly selected
    let userHasInteracted = false;
    
    // On first focus, set temporary value to guide calendar to 2009
    bdateInput.addEventListener('focus', function() {
        if (!userHasInteracted && !this.value) {
            this.value = maxDateString; // Set to 2009 temporarily
            console.log(`📅 Calendar opened to max year: ${maxDateString}`);
        }
    });
    
    // When user makes a change, mark as interacted
    bdateInput.addEventListener('input', function() {
        userHasInteracted = true;
    });
    
    // On blur, clear if user didn't actively select
    bdateInput.addEventListener('blur', function() {
        if (this.value === maxDateString && !userHasInteracted) {
            this.value = ''; // Clear the helper value
            console.log(`📅 Cleared temporary calendar date - user did not select`);
        }
    });
    
    console.log(`✅ Dynamic birthdate validation set:`);
    console.log(`   Max date = ${maxDateString} (16 years ago = ${today.getFullYear() - 16})`);
    console.log(`   Min date = ${minDateString} (100 years ago = ${today.getFullYear() - 100})`);
    console.log(`   Current max attribute: ${bdateInput.getAttribute('max')}`);
    console.log(`   Current min attribute: ${bdateInput.getAttribute('min')}`);
    console.log(`   Calendar will open to year 2009 for better UX`);
    
    // Validation function
    const validateBirthdate = function() {
        if (!this.value) {
            // Empty date - show error
            this.setCustomValidity('Please select your date of birth');
            this.classList.add('is-invalid');
            this.classList.remove('is-valid');
            return;
        }
        
        const selectedDate = new Date(this.value);
        const selectedDateString = this.value;
        
        console.log(`📅 Validating birthdate: ${selectedDateString}`);
        console.log(`   Max allowed: ${maxDateString}`);
        
        // First check: Ensure date doesn't exceed max date (bypass prevention)
        if (selectedDateString > maxDateString) {
            console.log(`❌ Date exceeds max: ${selectedDateString} > ${maxDateString}`);
            this.value = ''; // Clear the invalid date
            this.setCustomValidity('Date cannot be after ' + maxDateString);
            this.classList.add('is-invalid');
            this.classList.remove('is-valid');
            
            showNotifier('⚠️ Invalid date: You must be at least 16 years old. Please select a valid birthdate.', 'error');
            return;
        }
        
        // Second check: Ensure date is not before min date
        if (selectedDateString < minDateString) {
            console.log(`❌ Date before min: ${selectedDateString} < ${minDateString}`);
            this.value = ''; // Clear the invalid date
            this.setCustomValidity('Please enter a valid birthdate.');
            this.classList.add('is-invalid');
            this.classList.remove('is-valid');
            
            showNotifier('⚠️ Invalid date: Please enter a valid birthdate.', 'error');
            return;
        }
        
        const today = new Date();
        
        // Calculate age ACCURATELY
        let age = today.getFullYear() - selectedDate.getFullYear();
        const monthDiff = today.getMonth() - selectedDate.getMonth();
        const dayDiff = today.getDate() - selectedDate.getDate();
        
        // Adjust age if birthday hasn't occurred this year
        if (monthDiff < 0 || (monthDiff === 0 && dayDiff < 0)) {
            age--;
        }
        
        console.log(`   Calculated age: ${age} years`);
        
        // Validate minimum age of 16
        if (age < 16) {
            console.log(`❌ Age too young: ${age} < 16`);
            this.setCustomValidity('You must be at least 16 years old to register.');
            this.classList.add('is-invalid');
            this.classList.remove('is-valid');
            
            // Create or update error message
            let errorMsg = this.parentElement.querySelector('.invalid-feedback');
            if (!errorMsg) {
                errorMsg = document.createElement('div');
                errorMsg.className = 'invalid-feedback';
                errorMsg.style.display = 'block';
                this.parentElement.appendChild(errorMsg);
            }
            errorMsg.textContent = `You must be at least 16 years old to register. You are currently ${age} years old.`;
            
            // Show notification and clear the input
            showNotifier(`⚠️ Invalid birthdate: You must be at least 16 years old to register. You are currently ${age} years old.`, 'error');
            
            // Clear the input value to prevent bypass
            this.value = '';
        } else if (age > 100) {
            console.log(`❌ Age too old: ${age} > 100`);
            this.setCustomValidity('Please enter a valid birthdate.');
            this.classList.add('is-invalid');
            this.classList.remove('is-valid');
            
            let errorMsg = this.parentElement.querySelector('.invalid-feedback');
            if (!errorMsg) {
                errorMsg = document.createElement('div');
                errorMsg.className = 'invalid-feedback';
                errorMsg.style.display = 'block';
                this.parentElement.appendChild(errorMsg);
            }
            errorMsg.textContent = 'Please enter a valid birthdate.';
            
            showNotifier('⚠️ Please enter a valid birthdate.', 'error');
            
            // Clear the input value
            this.value = '';
        } else {
            console.log(`✅ Valid birthdate: Age ${age} years`);
            this.setCustomValidity('');
            this.classList.remove('is-invalid');
            this.classList.add('is-valid');
            
            // Remove error message if it exists
            const errorMsg = this.parentElement.querySelector('.invalid-feedback');
            if (errorMsg) {
                errorMsg.remove();
            }
            
            // Show success notification
            showNotifier(`✅ Valid birthdate (Age: ${age} years old)`, 'success');
        }
    };
    
    // Add event listeners for both change, input, and blur events
    bdateInput.addEventListener('change', validateBirthdate);
    bdateInput.addEventListener('input', validateBirthdate);
    bdateInput.addEventListener('blur', validateBirthdate);
    
    console.log('✅ Birthdate validation initialized with event listeners');
}

function setupTermsAndConditions() {
    const termsLink = document.getElementById('termsLink');
    const acceptBtn = document.getElementById('acceptTermsBtn');
    const agreeCheckbox = document.getElementById('agreeTerms');
    const termsModal = document.getElementById('termsModal');
    
    console.log('🔍 Terms elements found:', {
        termsLink: !!termsLink,
        acceptBtn: !!acceptBtn,
        agreeCheckbox: !!agreeCheckbox,
        termsModal: !!termsModal,
        bootstrap: typeof window.bootstrap
    });
    
    if (!window.bootstrap) {
        console.warn('⚠️ Bootstrap not loaded! Modal may not work.');
        return;
    }
    
    // Make checkbox readonly so it can only be checked via the modal
    if (agreeCheckbox) {
        agreeCheckbox.readOnly = true;
        
        // Prevent manual checking of checkbox - force modal open
        agreeCheckbox.addEventListener('click', function(e) {
            // Always prevent default and open modal when checkbox is clicked
            e.preventDefault();
            console.log('📝 Checkbox clicked, opening modal...');
            
            // Open modal
            if (window.bootstrap && window.bootstrap.Modal && termsModal) {
                const modal = new bootstrap.Modal(termsModal);
                modal.show();
                console.log('✅ Terms modal opened from checkbox');
            }
        });
        
        // Also open modal when clicking the label
        const checkboxLabel = document.querySelector('label[for="agreeTerms"]');
        if (checkboxLabel) {
            checkboxLabel.addEventListener('click', function(e) {
                e.preventDefault();
                console.log('📝 Label clicked, opening modal...');
                if (termsLink) termsLink.click();
            });
        }
    }
    
    // Handle clicking the terms link to open modal
    if (termsLink) {
        termsLink.addEventListener('click', function(e) {
            e.preventDefault();
            
            if (window.bootstrap && window.bootstrap.Modal && termsModal) {
                const modal = new bootstrap.Modal(termsModal);
                modal.show();
                console.log('✅ Terms modal opened');
            }
        });
    }
    
    // Handle accept button
    if (acceptBtn) {
        acceptBtn.addEventListener('click', function() {
            // Check the terms checkbox
            if (agreeCheckbox) {
                agreeCheckbox.checked = true;
                agreeCheckbox.dispatchEvent(new Event('change'));
                console.log('✅ Terms accepted and checkbox checked');
            }
            
            // Close modal using Bootstrap
            if (window.bootstrap && window.bootstrap.Modal && termsModal) {
                const modal = bootstrap.Modal.getInstance(termsModal);
                if (modal) {
                    modal.hide();
                    console.log('✅ Terms modal closed via Bootstrap');
                }
            }
        });
    }
    
    // Handle modal close buttons (without accepting)
    const closeButtons = termsModal?.querySelectorAll('[data-bs-dismiss="modal"]:not(#acceptTermsBtn)');
    closeButtons?.forEach(button => {
        button.addEventListener('click', function() {
            console.log('ℹ️ Modal closed without accepting');
        });
    });
    
    // Add backdrop click to close
    if (termsModal) {
        termsModal.addEventListener('click', function(e) {
            if (e.target === termsModal) {
                const modal = bootstrap.Modal.getInstance(termsModal);
                if (modal) modal.hide();
            }
        });
    }
    
    console.log('✅ Terms and Conditions functionality initialized');
}
</script>
</body>
</html>
<?php
} // End of main registration HTML for non-AJAX requests
