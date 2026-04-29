<?php
// CRITICAL: Start session BEFORE any output
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// CRITICAL: Clean any output buffers before JSON response
while (ob_get_level()) {
    ob_end_clean();
}

// Suppress any PHP errors from appearing in JSON response
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Set JSON header IMMEDIATELY before any other code
header('Content-Type: application/json');

// Now include dependencies
include __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/CSRFProtection.php';
require_once __DIR__ . '/../../src/Services/BlacklistService.php';

// Load PHPMailer - autoload MUST come before use statements
require_once __DIR__ . '/../../phpmailer/vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Better error handling
try {

// Check database connection first
if (!isset($connection) || !$connection) {
    error_log('Blacklist Service: Database connection not available');
    echo json_encode(['status' => 'error', 'message' => 'Database connection error']);
    exit;
}

if (!isset($_SESSION['admin_username'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access']);
    exit;
}

$admin_id = $_SESSION['admin_id'];

// Get admin info including password for verification
$adminQuery = pg_query_params($connection, "SELECT email, first_name, last_name, password FROM admins WHERE admin_id = $1", [$admin_id]);

if (!$adminQuery) {
    error_log('Blacklist Service: Admin query failed - ' . pg_last_error($connection));
    echo json_encode(['status' => 'error', 'message' => 'Database query error']);
    exit;
}

$admin = pg_fetch_assoc($adminQuery);

if (!$admin) {
    echo json_encode(['status' => 'error', 'message' => 'Admin not found']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Debug logging BEFORE CSRF check
    error_log("=== BLACKLIST SERVICE REQUEST ===");
    error_log("Request Method: " . $_SERVER['REQUEST_METHOD']);
    error_log("Action received: " . $action);
    error_log("Student ID: " . ($_POST['student_id'] ?? 'NOT SET'));
    error_log("Reason category: " . ($_POST['reason_category'] ?? 'NOT SET'));
    error_log("Session admin_id: " . ($_SESSION['admin_id'] ?? 'NOT SET'));
    error_log("Session admin_username: " . ($_SESSION['admin_username'] ?? 'NOT SET'));
    error_log("================================");
    
    // CSRF Protection - validate token for all POST actions
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!CSRFProtection::validateToken('blacklist_operation', $csrfToken, false)) {
        error_log("BLACKLIST SERVICE: CSRF validation failed");
        echo json_encode(['status' => 'error', 'message' => 'Invalid security token. Please refresh the page.']);
        exit;
    }
    
    error_log("BLACKLIST SERVICE: CSRF validation passed");
    
    // Step 1: Initiate blacklist process - verify password and send OTP
    if ($action === 'initiate_blacklist') {
        // Validate required fields exist
        if (empty($_POST['student_id']) || empty($_POST['admin_password']) || empty($_POST['reason_category']) || empty($_POST['detailed_reason'])) {
            echo json_encode(['status' => 'error', 'message' => 'Missing required fields']);
            exit;
        }
        
        $student_id = trim($_POST['student_id']); // Remove intval for TEXT student_id
        $password = $_POST['admin_password'];
        $reason_category = $_POST['reason_category'];
        $detailed_reason = trim($_POST['detailed_reason'] ?? '');
        $admin_notes = trim($_POST['admin_notes'] ?? '');
        
        // Verify admin password
        if (!password_verify($password, $admin['password'])) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid admin password']);
            exit;
        }
        
        // Validate inputs - include all valid reason categories
        $validReasons = ['fraudulent_activity', 'academic_misconduct', 'system_abuse', 'duplicate', 'other'];
        if (!in_array($reason_category, $validReasons)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid reason category']);
            exit;
        }
        
        // Check if student exists and is not already blacklisted
        $studentCheck = pg_query_params($connection, 
            "SELECT student_id, first_name, last_name, email, status FROM students WHERE student_id = $1", 
            [$student_id]
        );
        $student = pg_fetch_assoc($studentCheck);
        
        if (!$student) {
            echo json_encode(['status' => 'error', 'message' => 'Student not found']);
            exit;
        }
        
        if ($student['status'] === 'blacklisted') {
            echo json_encode(['status' => 'error', 'message' => 'Student is already blacklisted']);
            exit;
        }
        
        // Generate OTP
        $otp = sprintf('%06d', rand(0, 999999));
        $expires_at = date('Y-m-d H:i:s', time() + 600); // 10 minutes (increased from 5)
        
        // Clean old verifications for this admin
        pg_query_params($connection, 
            "DELETE FROM admin_blacklist_verifications WHERE admin_id = $1 AND expires_at < NOW()", 
            [$admin_id]
        );
        
        // Get student details for storage
        $student_name = $student['first_name'] . ' ' . $student['last_name'];
        $student_email = $student['email'];
        
        // Store all the form data in session_data as JSON
        $session_data = json_encode([
            'reason_category' => $reason_category,
            'detailed_reason' => $detailed_reason,
            'admin_notes' => $admin_notes,
            'student_name' => $student_name,
            'student_email' => $student_email,
            'student_status' => $student['status'],
            'admin_name' => $admin['first_name'] . ' ' . $admin['last_name']
        ]);
        
        // Insert new verification record (only using columns that exist)
        $insertResult = pg_query_params($connection,
            "INSERT INTO admin_blacklist_verifications 
             (admin_id, student_id, otp, email, expires_at, session_data) 
             VALUES ($1, $2, $3, $4, $5, $6)",
            [$admin_id, $student_id, $otp, $admin['email'], $expires_at, $session_data]
        );
        
        if (!$insertResult) {
            echo json_encode(['status' => 'error', 'message' => 'Failed to create verification record']);
            exit;
        }
        
        // Send OTP email
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'dilucayaka02@gmail.com';
            $mail->Password = 'jlld eygl hksj flvg';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;

            $mail->setFrom('dilucayaka02@gmail.com', 'EducAid Security');
            $mail->addAddress($admin['email']);
            $mail->isHTML(true);
            $mail->Subject = 'CRITICAL: Blacklist Authorization Required';
            $mail->Body = "
                <h3 style='color: #dc3545;'>🚨 BLACKLIST AUTHORIZATION REQUIRED</h3>
                <p>Hello {$admin['first_name']},</p>
                <p>You are attempting to <strong>permanently blacklist</strong> the following student:</p>
                <div style='background: #f8f9fa; padding: 15px; border-left: 4px solid #dc3545; margin: 15px 0;'>
                    <strong>Student:</strong> {$student['first_name']} {$student['last_name']}<br>
                    <strong>Email:</strong> {$student['email']}<br>
                    <strong>Reason:</strong> " . ucwords(str_replace('_', ' ', $reason_category)) . "
                </div>
                <p><strong>Your authorization code is:</strong></p>
                <div style='font-size: 24px; font-weight: bold; color: #dc3545; text-align: center; 
                     background: #fff; border: 2px solid #dc3545; padding: 10px; margin: 10px 0;'>
                    {$otp}
                </div>
                <p><strong>⚠️ WARNING:</strong> This action is IRREVERSIBLE. The student will be permanently blocked from the system.</p>
                <p><em>Code expires in 10 minutes.</em></p>
                <hr>
                <small>If you did not initiate this action, please contact system administrator immediately.</small>
            ";

            $mail->send();
            
            echo json_encode([
                'status' => 'otp_sent',
                'message' => 'Security code sent to your email. Please check your inbox.',
                'student_name' => $student['first_name'] . ' ' . $student['last_name']
            ]);
            
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'Failed to send security code']);
        }
        exit;
    }
    
    // Step 2: Verify OTP and complete blacklist
    if ($action === 'complete_blacklist') {
        // Debug logging
        error_log("Complete blacklist action received");
        error_log("POST data: " . print_r($_POST, true));
        
        $student_id = trim($_POST['student_id']); // Remove intval for TEXT student_id
        $otp = $_POST['otp'];
        
        error_log("Student ID: $student_id, OTP: $otp, Admin ID: $admin_id");
        
        // Get verification record with detailed debugging
        $debug_query = "SELECT *, 
                              (expires_at > NOW()) as not_expired, 
                              NOW() as current_time,
                              expires_at
                       FROM admin_blacklist_verifications 
                       WHERE admin_id = $1 AND student_id = $2 AND otp = $3";
        
        $debug_result = pg_query_params($connection, $debug_query, [$admin_id, $student_id, $otp]);
        
        if ($debug_result) {
            $debug_record = pg_fetch_assoc($debug_result);
            error_log("Debug verification record: " . print_r($debug_record, true));
        }
        
        // Get verification record with more lenient time check
        $current_timestamp = time();
        
        $verifyQuery = pg_query_params($connection,
            "SELECT *, 
                    EXTRACT(EPOCH FROM expires_at) as expires_timestamp,
                    EXTRACT(EPOCH FROM NOW()) as current_timestamp
             FROM admin_blacklist_verifications 
             WHERE admin_id = $1 AND student_id = $2 AND otp = $3 AND used = false",
            [$admin_id, $student_id, $otp]
        );
        
        if (!$verifyQuery) {
            error_log("Verification query failed: " . pg_last_error($connection));
            echo json_encode(['status' => 'error', 'message' => 'Database query failed']);
            exit;
        }
        
        $verification = pg_fetch_assoc($verifyQuery);
        error_log("Verification record found: " . ($verification ? 'YES' : 'NO'));
        
        if ($verification) {
            // Manual expiry check with current timestamp
            $expires_timestamp = floatval($verification['expires_timestamp']);
            $current_timestamp = floatval($verification['current_timestamp']);
            $is_expired = $current_timestamp > $expires_timestamp;
            
            error_log("Manual expiry check - Current: $current_timestamp, Expires: $expires_timestamp, Expired: " . ($is_expired ? 'YES' : 'NO'));
            
            if ($is_expired) {
                echo json_encode(['status' => 'error', 'message' => 'Security code has expired. Please request a new one.']);
                exit;
            }
            
            if ($verification['used'] === 't' || $verification['used'] === true) {
                echo json_encode(['status' => 'error', 'message' => 'Security code has already been used']);
                exit;
            }
        } else {
            // Check what records exist for debugging
            $check_query = "SELECT *, 
                                  EXTRACT(EPOCH FROM expires_at) as expires_timestamp,
                                  EXTRACT(EPOCH FROM NOW()) as current_timestamp,
                                  used
                           FROM admin_blacklist_verifications 
                           WHERE admin_id = $1 AND student_id = $2 AND otp = $3";
            
            $check_result = pg_query_params($connection, $check_query, [$admin_id, $student_id, $otp]);
            if ($check_result && $check_record = pg_fetch_assoc($check_result)) {
                error_log("Found record but conditions failed: " . print_r($check_record, true));
                echo json_encode(['status' => 'error', 'message' => 'Invalid security code']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Security code not found']);
            }
            exit;
        }
        
        // Use BlacklistService to handle the blacklisting
        try {
            // Decode session data to get stored form information
            $session_data = json_decode($verification['session_data'], true);
            
            // Initialize BlacklistService
            $blacklistService = new \App\Services\BlacklistService();
            
            // Blacklist the student using the service
            $result = $blacklistService->blacklistStudent(
                $student_id,
                $session_data['reason_category'],
                $session_data['detailed_reason'],
                $admin_id,
                $session_data['admin_notes'],
                $verification['email'] // Admin email
            );
            
            if (!$result['success']) {
                throw new Exception($result['message']);
            }
            
            // Mark verification as used
            pg_query_params($connection,
                "UPDATE admin_blacklist_verifications SET used = true WHERE id = $1",
                [$verification['id']]
            );
            
            // Log success
            error_log("Blacklist: Student {$student_id} successfully blacklisted");
            if ($result['compression'] && $result['compression']['success']) {
                error_log("Blacklist: Files compressed - {$result['compression']['files_added']} files archived to blacklisted_students/");
            }
            
            echo json_encode([
                'status' => 'success',
                'message' => 'Student has been successfully blacklisted and files archived'
            ]);
            
        } catch (Exception $e) {
            error_log("Blacklist: Error - " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Failed to blacklist student: ' . $e->getMessage()]);
        }
        
        exit;
    }
}

echo json_encode(['status' => 'error', 'message' => 'Invalid request']);

} catch (Exception $e) {
    // Log the error with full details
    error_log("=== BLACKLIST SERVICE ERROR ===");
    error_log("Error Message: " . $e->getMessage());
    error_log("Error File: " . $e->getFile() . " on line " . $e->getLine());
    error_log("Stack trace: " . $e->getTraceAsString());
    error_log("POST data: " . print_r($_POST, true));
    error_log("Session data: " . print_r($_SESSION, true));
    error_log("==============================");
    
    echo json_encode(['status' => 'error', 'message' => 'System error: ' . $e->getMessage()]);
} catch (Error $e) {
    // Catch PHP 7+ fatal errors
    error_log("=== BLACKLIST SERVICE FATAL ERROR ===");
    error_log("Error Message: " . $e->getMessage());
    error_log("Error File: " . $e->getFile() . " on line " . $e->getLine());
    error_log("Stack trace: " . $e->getTraceAsString());
    error_log("=====================================");
    
    echo json_encode(['status' => 'error', 'message' => 'Fatal error: ' . $e->getMessage()]);
}

// Ensure clean exit
exit;