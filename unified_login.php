<?php
// Load security headers first (before any output)
require_once __DIR__ . '/config/security_headers.php';
include __DIR__ . '/config/database.php';
include __DIR__ . '/config/recaptcha_config.php';
require_once __DIR__ . '/bootstrap_services.php';
require_once __DIR__ . '/includes/SessionManager.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// For AJAX/POST JSON responses, prevent PHP warnings from leaking into output
if (
    ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ||
    (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
) {
    @ini_set('display_errors', '0');
    @ini_set('html_errors', '0');
}

// Fetch municipality data for navbar (General Trias as default)
$municipality_logo = null;
$municipality_name = 'General Trias';
$preset_logo = null; // For municipality logo display on login page

if (isset($connection)) {
    // Fetch General Trias municipality data with correct logo logic
    $muni_result = pg_query_params(
        $connection,
        "SELECT name, 
                custom_logo_image,
                preset_logo_image,
                use_custom_logo,
                CASE 
                    WHEN use_custom_logo = TRUE AND custom_logo_image IS NOT NULL AND custom_logo_image != '' 
                    THEN custom_logo_image
                    ELSE preset_logo_image
                END AS active_logo
         FROM municipalities 
         WHERE municipality_id = $1
         LIMIT 1",
        [1] // municipality_id for General Trias
    );
    
    if ($muni_result && pg_num_rows($muni_result) > 0) {
        $muni_data = pg_fetch_assoc($muni_result);
        $municipality_name = $muni_data['name'];
        
        if (!empty($muni_data['active_logo'])) {
            $logo_path = trim($muni_data['active_logo']);
            // Remove leading slash if present to make it relative to root
            $municipality_logo = ltrim($logo_path, '/');
            
            // Add cache-busting with file modification time (only updates when file changes)
            $absolutePath = $_SERVER['DOCUMENT_ROOT'] . '/' . $municipality_logo;
            $cacheKey = file_exists($absolutePath) ? filemtime($absolutePath) : '1';
            $municipality_logo .= '?v=' . $cacheKey;
        }
        
        // Get preset logo (municipality seal) for left side display
        if (!empty($muni_data['preset_logo_image'])) {
            $preset_path = trim($muni_data['preset_logo_image']);
            $preset_logo = ltrim($preset_path, '/');
            $presetAbsPath = $_SERVER['DOCUMENT_ROOT'] . '/' . $preset_logo;
            $presetCacheKey = file_exists($presetAbsPath) ? filemtime($presetAbsPath) : '1';
            $preset_logo .= '?v=' . $presetCacheKey;
        }
        
        pg_free_result($muni_result);
    }
}

// CMS System - Load content blocks for login page
$LOGIN_SAVED_BLOCKS = [];
if (isset($connection)) {
    $resBlocksLogin = @pg_query($connection, "SELECT block_key, html, text_color, bg_color, is_visible FROM login_content_blocks WHERE municipality_id=1");
    if ($resBlocksLogin) {
        while($r = pg_fetch_assoc($resBlocksLogin)) { 
            $LOGIN_SAVED_BLOCKS[$r['block_key']] = $r; 
        }
        pg_free_result($resBlocksLogin);
    }
}

// CMS Helper functions for login page
function login_block($key, $defaultHtml){
    global $LOGIN_SAVED_BLOCKS;
    if(isset($LOGIN_SAVED_BLOCKS[$key])){ 
        $h = $LOGIN_SAVED_BLOCKS[$key]['html'];
        $h = strip_tags($h, '<p><br><b><strong><i><em><u><a><span><div><h1><h2><h3><h4><h5><h6><ul><ol><li>');
        return $h !== '' ? $h : $defaultHtml;
    }
    return $defaultHtml;
}

function login_block_style($key){
    global $LOGIN_SAVED_BLOCKS;
    if(!isset($LOGIN_SAVED_BLOCKS[$key])) return '';
    $r = $LOGIN_SAVED_BLOCKS[$key];
    $s = [];
    if(!empty($r['text_color'])) $s[] = 'color:'.$r['text_color'];
    if(!empty($r['bg_color'])) $s[] = 'background-color:'.$r['bg_color'];
    return $s ? ' style="'.implode(';', $s).'"' : '';
}

// Generate edit button for login page content
function login_edit_btn($key, $title){
    global $IS_LOGIN_EDIT_MODE, $LOGIN_SAVED_BLOCKS;
    if(!$IS_LOGIN_EDIT_MODE) return '';
    
    $currentContent = isset($LOGIN_SAVED_BLOCKS[$key]) ? htmlspecialchars($LOGIN_SAVED_BLOCKS[$key]['html'], ENT_QUOTES) : '';
    
    return '<button class="edit-btn-inline" onclick="openEditModal(\''.$key.'\', `'.$currentContent.'`, \''.$title.'\')" title="Edit '.$title.'">
        <i class="bi bi-pencil-fill"></i>
    </button>';
}

// Check if a content section is visible
function login_section_visible($sectionKey){
    global $LOGIN_SAVED_BLOCKS;
    
    if(isset($LOGIN_SAVED_BLOCKS[$sectionKey]) && array_key_exists('is_visible', $LOGIN_SAVED_BLOCKS[$sectionKey])) {
        $visibleValue = $LOGIN_SAVED_BLOCKS[$sectionKey]['is_visible'];
        return $visibleValue === true || $visibleValue === 't' || $visibleValue === 'true' || $visibleValue === 1 || $visibleValue === '1';
    }
    
    // Default to visible if value not stored yet
    return true;
}

// Check if in edit mode (super admin with ?edit=1)
$IS_LOGIN_EDIT_MODE = false;
if (isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'super_admin' && isset($_GET['edit']) && $_GET['edit'] == '1') {
    $IS_LOGIN_EDIT_MODE = true;
    
    // Generate CSRF tokens for edit mode
    require_once __DIR__ . '/includes/CSRFProtection.php';
    $EDIT_CSRF_TOKEN = CSRFProtection::generateToken('edit_login_content');
    $TOGGLE_CSRF_TOKEN = CSRFProtection::generateToken('toggle_section');
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require __DIR__ . '/phpmailer/vendor/autoload.php';

// Include our professional email template
require_once __DIR__ . '/includes/email_templates/otp_email_template.php';

// Function to verify reCAPTCHA (supports v2 and v3)
function verifyRecaptcha($recaptchaResponse, $action = 'login') {
    // Support a dedicated v2 secret if provided, otherwise fall back to the main secret
    $v2Secret = getenv('RECAPTCHA_V2_SECRET_KEY') ?: RECAPTCHA_SECRET_KEY;
    $secretKey = $v2Secret;

    if (empty($recaptchaResponse)) {
        return ['success' => false, 'message' => 'No CAPTCHA response provided'];
    }

    $url = RECAPTCHA_VERIFY_URL;
    $data = [
        'secret' => $secretKey,
        'response' => $recaptchaResponse,
        'remoteip' => $_SERVER['REMOTE_ADDR'] ?? ''
    ];

    $options = [
        'http' => [
            'header' => "Content-type: application/x-www-form-urlencoded\r\n",
            'method' => 'POST',
            'content' => http_build_query($data),
            'timeout' => 5
        ]
    ];

    $context = stream_context_create($options);
    $result = @file_get_contents($url, false, $context);

    if ($result === false) {
        return ['success' => false, 'message' => 'CAPTCHA verification request failed'];
    }

    $resultJson = json_decode($result, true);

    if (!isset($resultJson['success']) || $resultJson['success'] !== true) {
        return ['success' => false, 'message' => 'CAPTCHA verification failed', 'raw' => $resultJson];
    }

    // If response contains a score → it's v3; apply score/action checks
    if (isset($resultJson['score'])) {
        $score = $resultJson['score'] ?? 0;
        $actionReceived = $resultJson['action'] ?? '';
        if ($score >= 0.5 && $actionReceived === $action) {
            return ['success' => true, 'score' => $score, 'v' => 3];
        }
        return ['success' => false, 'message' => 'CAPTCHA score too low or action mismatch', 'score' => $score, 'v' => 3];
    }

    // Otherwise it's v2 and success=true is sufficient
    return ['success' => true, 'v' => 2];
}

// Always return JSON for AJAX requests
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    header('Content-Type: application/json');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, no-cache, must-revalidate');
}

// LOGIN PHASE 1: credentials → send login-OTP (SIMPLIFIED)
if (
    isset($_POST['email'], $_POST['password'])
    && !isset($_POST['login_action'])
    && !isset($_POST['forgot_action'])
) {
    // Verify reCAPTCHA token (v2 or v3) server-side before sending OTP
    $recaptchaResponse = trim($_POST['g-recaptcha-response'] ?? '');

    // Development bypass: completely disable reCAPTCHA on localhost for testing
    $isDevelopment = (
        strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false ||
        strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') !== false ||
        (getenv('APP_ENV') === 'local') ||
        (getenv('APP_DEBUG') === 'true')
    );

    if ($isDevelopment) {
        $captchaResult = ['success' => true, 'note' => 'development bypass'];
    } else {
        if (empty($recaptchaResponse)) {
            echo json_encode(['status' => 'error', 'message' => 'Security verification required.']);
            exit;
        }
        $captchaResult = verifyRecaptcha($recaptchaResponse, 'login');
    }

    if (empty($captchaResult) || !$captchaResult['success']) {
        echo json_encode(['status'=>'error','message'=>'Security verification failed.']);
        exit;
    }
    
    $em = trim($_POST['email']);
    $pw = $_POST['password'];

    // Check if user is a student (only allow active students to login, block archived students)
    $studentRes = pg_query_params($connection,
        "SELECT student_id, password, first_name, last_name, status, is_archived, 'student' as role FROM students
         WHERE email = $1 AND status != 'under_registration' AND (is_archived = FALSE OR is_archived IS NULL)",
        [$em]
    );
    
    // Check if user is an admin (get actual role from database)
    $adminRes = pg_query_params($connection,
        "SELECT admin_id, password, first_name, last_name, role FROM admins
         WHERE email = $1",
        [$em]
    );

    $user = null;
    if ($studentRow = pg_fetch_assoc($studentRes)) {
        $user = $studentRow;
        $user['id'] = $user['student_id'];
        
        // Check if student is blacklisted
        if ($user['status'] === 'blacklisted') {
            // Get blacklist reason
            $blacklistQuery = pg_query_params($connection,
                "SELECT reason_category, detailed_reason FROM blacklisted_students WHERE student_id = $1",
                [$user['id']]
            );
            $blacklistInfo = pg_fetch_assoc($blacklistQuery);
            
            $reasonText = 'violation of terms';
            if ($blacklistInfo) {
                switch($blacklistInfo['reason_category']) {
                    case 'fraudulent_activity': $reasonText = 'fraudulent activity'; break;
                    case 'academic_misconduct': $reasonText = 'academic misconduct'; break;
                    case 'system_abuse': $reasonText = 'system abuse'; break;
                    case 'other': $reasonText = 'policy violation'; break;
                }
            }
            
            echo json_encode([
                'status' => 'error',
                'message' => "Account permanently suspended due to {$reasonText}. Please contact the Office of the Mayor for assistance.",
                'is_blacklisted' => true
            ]);
            exit;
        }
        
        // Check if student is archived
        if ($user['status'] === 'archived' || (isset($user['is_archived']) && $user['is_archived'] === true)) {
            // Get archive reason
            $archiveQuery = pg_query_params($connection,
                "SELECT archive_reason, archived_at FROM students WHERE student_id = $1",
                [$user['id']]
            );
            $archiveInfo = pg_fetch_assoc($archiveQuery);
            
            $reasonText = 'account inactivity';
            if ($archiveInfo && $archiveInfo['archive_reason']) {
                // Extract simple reason from archive_reason
                if (stripos($archiveInfo['archive_reason'], 'graduated') !== false) {
                    $reasonText = 'graduation';
                } elseif (stripos($archiveInfo['archive_reason'], 'inactive') !== false) {
                    $reasonText = 'prolonged inactivity';
                }
            }
            
            echo json_encode([
                'status' => 'error',
                'message' => "Your account has been archived due to {$reasonText}. If you believe this is an error, please contact the Office of the Mayor for assistance.",
                'is_archived' => true
            ]);
            exit;
        }
    } elseif ($adminRow = pg_fetch_assoc($adminRes)) {
        $user = $adminRow;
        $user['id'] = $user['admin_id'];
    }

    if (!$user) {
        // Check if the email exists but with 'under_registration' status
        $underRegistrationCheck = pg_query_params($connection,
            "SELECT student_id FROM students WHERE email = $1 AND status = 'under_registration'",
            [$em]
        );
        
        // Check if the email exists but is archived
        $archivedCheck = pg_query_params($connection,
            "SELECT student_id, archive_reason FROM students WHERE email = $1 AND (is_archived = TRUE OR status = 'archived')",
            [$em]
        );
        
        if (pg_fetch_assoc($underRegistrationCheck)) {
            echo json_encode([
                'status'=>'error',
                'message'=>'Your account is still under review. Please wait for admin approval before logging in.'
            ]);
        } elseif ($archivedRow = pg_fetch_assoc($archivedCheck)) {
            $reason = $archivedRow['archive_reason'] ?? 'Account archived';
            echo json_encode([
                'status'=>'error',
                'message'=>'Your account has been archived and is no longer active. Reason: ' . htmlspecialchars($reason) . '. Please contact the administrator for assistance.'
            ]);
        } else {
            echo json_encode(['status'=>'error','message'=>'Email not found.']);
        }
        exit;
    }
    
    if (!password_verify($pw, $user['password'])) {
        // Track failed login attempt if student
        if ($user['role'] === 'student') {
            $sessionManager = new SessionManager($connection);
            $sessionManager->logFailedLogin($user['id'], 'Invalid password');
        }
        echo json_encode(['status'=>'error','message'=>'Invalid password.']);
        exit;
    }

    // Credentials OK → reuse existing OTP if valid and for same user, else generate new
    $reuseExisting = false;
    if (
        isset($_SESSION['login_otp'], $_SESSION['login_otp_time'], $_SESSION['login_pending']) &&
        is_array($_SESSION['login_pending']) &&
        isset($_SESSION['login_pending']['user_id'], $_SESSION['login_pending']['role']) &&
        $_SESSION['login_pending']['user_id'] == $user['id'] &&
        $_SESSION['login_pending']['role'] === $user['role'] &&
        (time() - (int)$_SESSION['login_otp_time'] < 300)
    ) {
        // Reuse existing OTP window for same user; don't regenerate or clear
        $reuseExisting = true;
        error_log('OTP Generation Debug - Reusing existing pending OTP for same user.');
    }

    if ($reuseExisting) {
        echo json_encode(['status' => 'otp_sent', 'message' => 'OTP already sent. Please check your email.']);
        exit;
    }

    // Clear any stale login OTP data now that we know the intended user
    unset($_SESSION['login_otp'], $_SESSION['login_otp_time'], $_SESSION['login_pending']);

    // Generate and set new OTP
    $otp = rand(100000,999999);
    $_SESSION['login_otp'] = $otp;
    $_SESSION['login_otp_time'] = time();
    $_SESSION['login_pending'] = [
        'user_id' => $user['id'],
        'role' => $user['role'],
        'name' => trim($user['first_name'] . ' ' . $user['last_name']),
        'email' => $em
    ];

    // Debug: Log session state after setting OTP data
    error_log("OTP Generation Debug - Session ID: " . session_id());
    error_log("OTP Generation Debug - OTP set: " . $otp);
    error_log("OTP Generation Debug - Session keys after setting: " . implode(', ', array_keys($_SESSION)));

    // IMPORTANT: Flush session data to storage before sending response/mail
    // to ensure subsequent AJAX requests (OTP verify) can read it immediately
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    // Send via email (using professional template)
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'dilucayaka02@gmail.com';
        $mail->Password = 'jlld eygl hksj flvg';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        $mail->setFrom('dilucayaka02@gmail.com','EducAid System');
        $mail->addAddress($em);
        $mail->isHTML(true);
        $mail->Subject = 'EducAid Verification Code - ' . $otp;
        
        // Get user's full name for personalization
        $recipient_name = trim($user['first_name'] . ' ' . $user['last_name']) ?: 'User';
        
        // Use professional email template for login OTP
        $mail->Body = generateOTPEmailTemplate($otp, $recipient_name, 'login');

        $mail->send();
        error_log('Login OTP - email sent successfully to ' . $em);
        echo json_encode(['status'=>'otp_sent','message'=>'OTP sent to your email.']);
    } catch (Exception $e) {
        error_log('Login OTP - mail send FAILED for ' . $em . '. Exception: ' . $e->getMessage());
        error_log('Login OTP - PHPMailer error info: ' . $mail->ErrorInfo);
        echo json_encode(['status'=>'error','message'=>'Could not send OTP: ' . $e->getMessage()]);
    }
    exit;
}

// LOGIN PHASE 2: verify login-OTP
if (isset($_POST['login_action']) && $_POST['login_action'] === 'verify_otp') {
    $userOtp = $_POST['login_otp'] ?? '';
    // Debug: Inspect session state at verification
    error_log("OTP Verification Debug - Session ID: " . session_id());
    error_log("OTP Verification Debug - Session keys: " . implode(', ', array_keys($_SESSION)));
    error_log('OTP Verification Debug - Has login_otp: ' . (isset($_SESSION['login_otp']) ? 'YES' : 'NO'));
    error_log('OTP Verification Debug - Has login_otp_time: ' . (isset($_SESSION['login_otp_time']) ? 'YES' : 'NO'));
    error_log('OTP Verification Debug - Has login_pending: ' . (isset($_SESSION['login_pending']) ? 'YES' : 'NO'));
    
    // Prevent double-submission by checking if already processing
    if (isset($_SESSION['otp_processing'])) {
        echo json_encode(['status'=>'error','message'=>'Request already processing. Please wait.']);
        exit;
    }
    $_SESSION['otp_processing'] = true;
    
    if (!isset($_SESSION['login_otp'], $_SESSION['login_otp_time'], $_SESSION['login_pending'])) {
        unset($_SESSION['otp_processing']);
        echo json_encode(['status'=>'error','message'=>'No login in progress.']);
        exit;
    }
    if (time() - $_SESSION['login_otp_time'] > 300) {
        unset($_SESSION['otp_processing']);
        session_unset();
        echo json_encode(['status'=>'error','message'=>'OTP expired.']);
        exit;
    }
    
    if ($userOtp != $_SESSION['login_otp']) {
        unset($_SESSION['otp_processing']);
        echo json_encode(['status'=>'error','message'=>'Incorrect OTP.']);
        exit;
    }

    // OTP OK → finalize login based on role
    $pending = $_SESSION['login_pending'];
    
    // Initialize audit logger and session manager
    $auditLogger = new AuditLogger($connection);
    $sessionManager = new SessionManager($connection);
    
    if ($pending['role'] === 'student') {
        $_SESSION['student_id'] = $pending['user_id'];
        $_SESSION['student_username'] = $pending['name'];
        
        // Get the previous login time and migration status before updating
        $prev_login_result = pg_query_params($connection,
            "SELECT last_login, admin_review_required, status FROM students WHERE student_id = $1",
            [$pending['user_id']]
        );
        $prev_login = pg_fetch_assoc($prev_login_result);
        $_SESSION['previous_login'] = $prev_login['last_login'] ?? null;
        
        $is_migrated = !empty($prev_login['admin_review_required']) && $prev_login['admin_review_required'];
        
        // Update last_login timestamp for student
        pg_query_params($connection, 
            "UPDATE students SET last_login = NOW() WHERE student_id = $1", 
            [$pending['user_id']]
        );
        
        // Log successful student login
        $auditLogger->logLogin($pending['user_id'], 'student', $pending['name']);
        
        // If migrated student, add additional audit log entry
        if ($is_migrated) {
            $migrated_audit_query = "INSERT INTO audit_logs 
                                    (user_id, user_type, username, event_type, event_category, 
                                     action_description, status, ip_address, user_agent, 
                                     request_method, metadata, created_at)
                                    VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, NOW())";
            
            $metadata = json_encode([
                'is_migrated_student' => true,
                'student_status' => $prev_login['status'] ?? 'applicant',
                'first_login_after_migration' => empty($prev_login['last_login'])
            ]);
            
            pg_query_params($connection, $migrated_audit_query, [
                $pending['user_id'],
                'student',
                $pending['name'],
                'migrated_student_login',
                'authentication',
                "Migrated student successfully logged in",
                'success',
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null,
                'POST',
                $metadata
            ]);
        }
        
        // Track session for login history
        $sessionManager->logLogin($pending['user_id'], session_id(), 'otp');
        
        $redirect = 'modules/student/student_homepage.php';
    } else {
        $_SESSION['admin_id'] = $pending['user_id'];
        $_SESSION['admin_username'] = $pending['name'];
        $_SESSION['admin_role'] = $pending['role']; // Store the actual admin role
        
        // Update last_login timestamp for admin
        pg_query_params($connection, 
            "UPDATE admins SET last_login = NOW() WHERE admin_id = $1", 
            [$pending['user_id']]
        );
        
        // Log successful admin login
        $auditLogger->logLogin($pending['user_id'], 'admin', $pending['name']);
        
        $redirect = 'modules/admin/homepage.php';
    }
    
    unset($_SESSION['login_otp'], $_SESSION['login_pending'], $_SESSION['otp_processing']);

    echo json_encode([
        'status' => 'success',
        'message' => 'Logged in!',
        'redirect' => $redirect
    ]);
    exit;
}

// FORGOT-PASSWORD OTP FLOW (similar logic for both roles)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['forgot_action'])) {
    // SEND OTP for Forgot-Password
    if ($_POST['forgot_action'] === 'send_otp' && !empty($_POST['forgot_email'])) {
        $email = trim($_POST['forgot_email']);

        // Require reCAPTCHA token for forgot-password OTP send as well
        $recaptchaResponse = trim($_POST['g-recaptcha-response'] ?? '');
        $isDevelopment = (
            strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false ||
            strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') !== false ||
            (getenv('APP_ENV') === 'local') ||
            (getenv('APP_DEBUG') === 'true')
        );
        if (!$isDevelopment) {
            if (empty($recaptchaResponse)) {
                error_log('Forgot OTP - missing recaptcha token for email: ' . $email);
                echo json_encode(['status' => 'error', 'message' => 'Security verification required.']);
                exit;
            }
            $captchaResult = verifyRecaptcha($recaptchaResponse, 'forgot_password');
            // Log verification result for debugging (no PII beyond email)
            error_log('Forgot OTP - recaptcha verify for ' . $email . ': ' . json_encode($captchaResult));
            if (empty($captchaResult) || !$captchaResult['success']) {
                error_log('Forgot OTP - recaptcha failed for ' . $email);
                echo json_encode(['status' => 'error', 'message' => 'Security verification failed.']);
                exit;
            }
        }
        
        // Check both tables (exclude students under registration and blacklisted)
        $studentRes = pg_query_params($connection, "SELECT student_id, 'student' as role FROM students WHERE email = $1 AND status NOT IN ('under_registration', 'blacklisted', 'archived')", [$email]);
        $adminRes = pg_query_params($connection, "SELECT admin_id, role FROM admins WHERE email = $1", [$email]);
        
        $user = null;
        if ($studentRow = pg_fetch_assoc($studentRes)) {
            $user = $studentRow;
        } elseif ($adminRow = pg_fetch_assoc($adminRes)) {
            $user = $adminRow;
        }
        
        if (!$user) {
            // Check if it's a student under registration
            $underRegistrationCheck = pg_query_params($connection,
                "SELECT student_id FROM students WHERE email = $1 AND status = 'under_registration'",
                [$email]
            );
            
            // Check if it's a blacklisted student
            $blacklistedCheck = pg_query_params($connection,
                "SELECT student_id FROM students WHERE email = $1 AND status = 'blacklisted'",
                [$email]
            );
            
            // Check if it's an archived student
            $archivedCheck = pg_query_params($connection,
                "SELECT student_id FROM students WHERE email = $1 AND status = 'archived'",
                [$email]
            );
            
            if (pg_fetch_assoc($underRegistrationCheck)) {
                echo json_encode([
                    'status'=>'error',
                    'message'=>'Your account is still under review. Password reset is not available until your account is approved.'
                ]);
            } elseif (pg_fetch_assoc($blacklistedCheck)) {
                echo json_encode([
                    'status'=>'error',
                    'message'=>'Account suspended. Please contact the Office of the Mayor for assistance.'
                ]);
            } elseif (pg_fetch_assoc($archivedCheck)) {
                echo json_encode([
                    'status'=>'error',
                    'message'=>'Your account has been archived. Please contact the Office of the Mayor if you believe this is an error.'
                ]);
            } else {
                echo json_encode(['status'=>'error','message'=>'Email not found.']);
            }
            exit;
        }
        
    $otp = rand(100000,999999);
        $_SESSION['forgot_otp'] = $otp;
        $_SESSION['forgot_otp_email'] = $email;
        $_SESSION['forgot_otp_role'] = $user['role'];
        $_SESSION['forgot_otp_time'] = time();

    // Log OTP generation (do not log OTP value in production)
    error_log('Forgot OTP - generated for ' . $email . ' role=' . $user['role'] . ' at ' . date('c'));

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'dilucayaka02@gmail.com';
            $mail->Password = 'jlld eygl hksj flvg';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;

            $mail->setFrom('dilucayaka02@gmail.com','EducAid System');
            $mail->addAddress($email);
            $mail->isHTML(true);
            $mail->Subject = 'EducAid Password Reset Code - ' . $otp;
            
            // Get user's name for personalization
            $recipient_name = 'User';
            if ($_SESSION['forgot_otp_role'] === 'admin') {
                $nameQuery = "SELECT firstname, lastname FROM admin_accounts WHERE email = $1";
            } else {
                $nameQuery = "SELECT first_name, last_name FROM students WHERE email = $1";
            }
            
            $nameResult = pg_query_params($connection, $nameQuery, [$email]);
            if ($nameResult && pg_num_rows($nameResult) > 0) {
                $nameRow = pg_fetch_assoc($nameResult);
                if ($_SESSION['forgot_otp_role'] === 'admin') {
                    $recipient_name = trim($nameRow['firstname'] . ' ' . $nameRow['lastname']) ?: 'User';
                } else {
                    $recipient_name = trim($nameRow['first_name'] . ' ' . $nameRow['last_name']) ?: 'User';
                }
            }
            
            // Use professional email template
            $mail->Body = generateOTPEmailTemplate($otp, $recipient_name, 'password_reset');

            $mail->send();
            error_log('Forgot OTP - email sent to ' . $email);
            echo json_encode(['status'=>'success','message'=>'OTP sent to your email.']);
        } catch (Exception $e) {
            // Log exception for debugging
            error_log('Forgot OTP - mail send failed for ' . $email . '. Exception: ' . $e->getMessage());
            echo json_encode(['status'=>'error','message'=>'Failed to send OTP.']);
        }
        exit;
    }

    // VERIFY Forgot-Password OTP
    if ($_POST['forgot_action'] === 'verify_otp' && isset($_POST['forgot_otp'])) {
        if (!isset($_SESSION['forgot_otp'], $_SESSION['forgot_otp_time'], $_SESSION['forgot_otp_email'])) {
            echo json_encode(['status'=>'error','message'=>'Session expired.']);
            exit;
        }
        if (time() - $_SESSION['forgot_otp_time'] > 300) {
            session_unset();
            echo json_encode(['status'=>'error','message'=>'OTP expired.']);
            exit;
        }
        if ($_POST['forgot_otp'] == $_SESSION['forgot_otp']) {
            $_SESSION['forgot_otp_verified'] = true;
            echo json_encode(['status'=>'success','message'=>'OTP verified.']);
        } else {
            echo json_encode(['status'=>'error','message'=>'Incorrect OTP.']);
        }
        exit;
    }

    // SET NEW PASSWORD
    if ($_POST['forgot_action'] === 'set_new_password' && isset($_POST['forgot_new_password'])) {
        if (!isset($_SESSION['forgot_otp_verified'], $_SESSION['forgot_otp_email'], $_SESSION['forgot_otp_role'])
            || !$_SESSION['forgot_otp_verified']
        ) {
            echo json_encode(['status'=>'error','message'=>'OTP verification required.']);
            exit;
        }
        
        $newPwd = $_POST['forgot_new_password'];
        if (strlen($newPwd) < 12) {
            echo json_encode(['status'=>'error','message'=>'Password must be at least 12 characters.']);
            exit;
        }
        
        $hashed = password_hash($newPwd, PASSWORD_ARGON2ID);
        $table = $_SESSION['forgot_otp_role'] === 'student' ? 'students' : 'admins';
        
        $update = pg_query_params($connection,
            "UPDATE $table SET password = $1 WHERE email = $2",
            [$hashed, $_SESSION['forgot_otp_email']]
        );
        
        if ($update) {
            session_unset();
            echo json_encode(['status'=>'success','message'=>'Password updated successfully.']);
        } else {
            echo json_encode(['status'=>'error','message'=>'Update failed.']);
        }
        exit;
    }
}

// If no AJAX route matched and it's a regular page load, show the login form
// Provide a v2 site key variable for the client to render the widget (falls back to configured site key)
// NOTE: Set RECAPTCHA_V2_SITE_KEY and RECAPTCHA_V2_SECRET_KEY in your environment or .env for production.
// If only RECAPTCHA_SITE_KEY/SECRET are set (v3), the v2 site key will fall back to RECAPTCHA_SITE_KEY.
$recaptcha_v2_site_key = getenv('RECAPTCHA_V2_SITE_KEY') ?: (defined('RECAPTCHA_SITE_KEY') ? RECAPTCHA_SITE_KEY : '');

// SEO Configuration
require_once __DIR__ . '/includes/seo_helpers.php';
$seoData = getSEOData('login');
$pageTitle = $seoData['title'];
$pageDescription = $seoData['description'];
$pageKeywords = $seoData['keywords'];
$pageImage = 'https://www.educ-aid.site' . $seoData['image'];
$pageUrl = 'https://www.educ-aid.site/unified_login.php';
$pageType = $seoData['type'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . '/includes/seo_head.php'; ?>
    
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700&family=Poppins:wght@400;600&display=swap" rel="stylesheet">
    <link href="assets/css/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/universal.css" rel="stylesheet">
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/website/landing_page.css" rel="stylesheet">
    
    <!-- Google reCAPTCHA v2 (visible checkbox) for testing -->
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    
    <style>
        /* Navbar enabled with isolation fixes applied */
        :root {
            --topbar-height: 40px;
            --navbar-height: 70px;
            --total-header-height: calc(var(--topbar-height) + var(--navbar-height));
            --thm-primary: #0051f8;
            --thm-green: #18a54a;
        }
        
        /* FIX 1: Modern Flexbox Layout - Body as flex container */
        body.login-page-isolated {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            padding-top: var(--total-header-height);
            overflow-x: hidden;
            font-family: "Manrope", sans-serif;
            margin: 0;
        }
        
        /* FIX 2: Content container takes remaining space */
        .login-page-isolated .login-content-container {
            flex: 1 0 auto;
            display: flex;
            flex-direction: column;
            width: 100%;
            max-width: none;
            margin: 0;
            padding: 0;
        }
        
        /* FIX 3: Main wrapper grows to fill available space */
        .login-page-isolated .login-main-wrapper {
            flex: 1 0 auto;
            width: 100%;
            display: flex;
            flex-direction: column;
        }
        
        /* Container-fluid takes full space */
        .login-page-isolated .container-fluid.p-0 {
            flex: 1;
            display: flex;
            flex-direction: column;
        }
        
        /* Row layout - full height split, side by side */
        .login-page-isolated .row.g-0.h-100 {
            flex: 1;
            display: flex !important;
            flex-wrap: wrap;
            min-height: calc(100vh - var(--total-header-height));
            margin: 0;
        }
        
        /* Ensure columns are side by side on desktop */
        @media (min-width: 992px) {
            .login-page-isolated .row.g-0.h-100 {
                flex-wrap: nowrap !important;
            }
            
            .login-page-isolated .row.g-0.h-100 > .col-lg-6.brand-section {
                flex: 0 0 50% !important;
                max-width: 50% !important;
                min-height: 100%;
                width: 50% !important;
            }
            
            .login-page-isolated .row.g-0.h-100 > .col-lg-6.form-section {
                flex: 0 0 50% !important;
                max-width: 50% !important;
                min-height: 100%;
                width: 50% !important;
            }
            
            /* Hide mobile brand header on desktop */
            .login-page-isolated .mobile-brand-header {
                display: none !important;
            }
        }
        
        /* FIX 4: CSS isolation for navbar to prevent page CSS bleed */
        .login-page-isolated nav.navbar.fixed-header {
            isolation: isolate;
            contain: layout style;
        }
        
        /* Match landing page navbar button styles */
        .login-page-isolated .navbar .btn-outline-primary {
            border: 2px solid var(--thm-primary) !important;
            color: var(--thm-primary) !important;
            background: #fff !important;
            font-weight: 500;
            padding: 0.5rem 1rem;
            border-radius: 0.375rem;
            transition: all 0.2s ease;
        }
        
        .login-page-isolated .navbar .btn-outline-primary:hover {
            background: var(--thm-primary) !important;
            color: #fff !important;
            border-color: var(--thm-primary) !important;
            transform: translateY(-1px);
        }
        
        .login-page-isolated .navbar .btn-primary {
            background: var(--thm-primary) !important;
            color: #fff !important;
            border: 2px solid var(--thm-primary) !important;
            font-weight: 500;
            padding: 0.5rem 1rem;
            border-radius: 0.375rem;
            transition: all 0.2s ease;
        }
        
        .login-page-isolated .navbar .btn-primary:hover {
            background: var(--thm-green) !important;
            border-color: var(--thm-green) !important;
            color: #fff !important;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(24, 165, 74, 0.3);
        }
        
        /* Match landing page navbar font */
        .login-page-isolated .navbar {
            font-family: "Manrope", sans-serif;
        }
        
        .login-page-isolated .navbar-brand {
            font-weight: 700;
        }
        
        .login-page-isolated .nav-link {
            font-weight: 500;
        }
        
        /* Topbar integration - Lower z-index so modals can appear above */
        .login-page-isolated .landing-topbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1040;
        }
        
        /* Adjust brand section - Dramatic background image with overlay */
        .brand-section {
            padding: 3rem 2rem;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100%;
            background: url('assets/images/loginpage.jpg') center center / cover no-repeat;
            position: relative;
            overflow: hidden;
        }
        
        /* Dark overlay for dramatic effect */
        .brand-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.65);
            pointer-events: none;
        }
        
        /* Brand content wrapper */
        .brand-content {
            position: relative;
            z-index: 1;
            max-width: 450px;
        }
        
        /* Brand logo */
        .brand-logo-wrapper {
            display: flex;
            justify-content: center;
        }
        
        .brand-logo-img {
            width: 100px;
            height: 100px;
            object-fit: contain;
            filter: brightness(0) invert(1);
            opacity: 0.95;
        }
        
        /* Brand title */
        .brand-title {
            font-size: 4.5rem;
            font-weight: 800;
            color: #fff;
            margin-bottom: 0.75rem;
            letter-spacing: -0.02em;
            text-shadow: 0 4px 20px rgba(0, 0, 0, 0.4);
        }
        
        /* Municipality Logo on Login Page */
        .municipality-logo-container {
            display: flex;
            justify-content: center;
            align-items: center;
        }
        
        .municipality-logo-login {
            width: 140px;
            height: 140px;
            object-fit: contain;
            filter: drop-shadow(0 4px 20px rgba(0, 0, 0, 0.3));
            transition: transform 0.3s ease;
        }
        
        .municipality-logo-login:hover {
            transform: scale(1.05);
        }
        
        /* Brand tagline */
        .brand-tagline {
            font-size: 1.5rem;
            font-weight: 600;
            color: #fff;
            margin-bottom: 1.5rem;
            letter-spacing: 0.02em;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
        }
        
        /* Brand subtitle */
        .brand-subtitle {
            font-size: 1.125rem;
            color: rgba(255, 255, 255, 0.95);
            line-height: 1.6;
            margin: 0;
            text-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
        }
        
        /* Container inside form section - Remove (not needed with new structure) */
        
        /* Ensure login form section adjusts properly - Fill the column */
        .login-page-isolated .form-section {
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding: 2rem 2.5rem;
            padding-top: 3rem;
            background: #fff;
            min-height: 100%;
            box-shadow: -8px 0 30px rgba(0, 0, 0, 0.1);
        }
        
        /* Remove overflow from containers to prevent internal scrollbars */
        .login-page-isolated .container-fluid,
        .login-page-isolated .row {
            overflow: visible;
        }
        
        /* LOGIN CARD - fills the form section with max-width for readability */
        .login-page-isolated .login-card {
            width: 100%;
            max-width: 360px;
            margin: 0 auto;
            max-height: none;
            overflow: visible;
        }
        
        /* Ensure buttons and form elements don't overflow */
        .login-page-isolated .login-card .btn,
        .login-page-isolated .login-card .form-control {
            max-width: 100%;
            word-wrap: break-word;
        }
        
        /* Tablet Devices (768px - 991px) */
        @media (min-width: 768px) and (max-width: 991.98px) {
            .brand-section,
            .col-lg-6:not(.brand-section) {
                padding: 1.5rem;
            }
            
            .login-page-isolated .form-section {
                padding: 2.5rem 2rem;
            }
            
            .login-page-isolated .login-card {
                max-width: 340px;
            }
            
            .login-page-isolated .form-control {
                padding: 0.6rem 0.85rem;
                font-size: 0.9rem;
            }
            
            .login-page-isolated .btn {
                padding: 0.6rem 1.25rem;
                font-size: 0.95rem;
            }
            
            .login-page-isolated h2 {
                font-size: 1.5rem;
            }
            
            .login-page-isolated h3 {
                font-size: 1.25rem;
            }
        }
        
        /* Mobile Devices (below 768px) */
        @media (max-width: 767.98px) {
            .brand-section,
            .col-lg-6:not(.brand-section) {
                padding: 1.5rem 1rem;
            }
            
            .login-page-isolated .form-section {
                padding: 1.5rem 1.25rem;
            }
        }
        
        /* Small mobile devices */
        @media (max-width: 575.98px) {
            .login-page-isolated .form-section {
                padding: 1.25rem 1rem;
            }
        }
        
        /* Large screens - limit form content width for readability */
        @media (min-width: 1400px) {
            .login-page-isolated .login-card {
                max-width: 380px;
            }
            
            .login-page-isolated .form-section {
                padding: 2.5rem 4rem;
            }
        }
        
        /* Ensure proper viewport scaling at all zoom levels */
        @media (min-width: 320px) and (max-width: 2560px) {
            .login-page-isolated .form-section {
                max-width: 100vw;
                overflow-x: hidden;
            }
            
            /* Prevent horizontal overflow at any zoom */
            .login-page-isolated * {
                max-width: 100%;
            }
        }
        
        /* ============================================
           BALANCED LOGIN CARD CONTENT
           ============================================ */
        
        /* Login header spacing - compact */
        .login-page-isolated .login-header {
            margin-bottom: 1.25rem !important;
            text-align: center;
        }
        
        .login-page-isolated .login-title {
            font-size: 1.5rem !important;
            margin-bottom: 0.375rem !important;
            line-height: 1.3 !important;
            font-weight: 700;
            color: #1e293b;
        }
        
        .login-page-isolated .login-subtitle {
            font-size: 0.875rem !important;
            margin-bottom: 0 !important;
            line-height: 1.5 !important;
            color: #64748b;
        }
        
        /* Form group spacing - compact */
        .login-page-isolated .form-group {
            margin-bottom: 1rem !important;
        }
        
        .login-page-isolated .form-label {
            font-size: 0.75rem !important;
            margin-bottom: 0.375rem !important;
            font-weight: 600 !important;
            text-transform: uppercase;
            letter-spacing: 0.025em;
            color: #475569;
        }
        
        /* Form control padding - compact */
        .login-page-isolated .form-control {
            padding: 0.625rem 0.875rem !important;
            font-size: 0.9rem !important;
            line-height: 1.4 !important;
            border-radius: 8px;
            border: 1.5px solid #e2e8f0;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        
        /* Smaller buttons */
        .login-page-isolated .btn-lg {
            padding: 0.5rem 1rem !important;
            font-size: 0.875rem !important;
            font-weight: 600;
            border-radius: 8px;
        }
        
        .login-page-isolated .form-control:focus {
            border-color: var(--thm-primary);
            box-shadow: 0 0 0 3px rgba(0, 81, 248, 0.1);
        }
        
        /* Primary button styling */
        .login-page-isolated .btn-success {
            background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
            border: none;
            box-shadow: 0 4px 12px rgba(34, 197, 94, 0.3);
            transition: all 0.2s ease;
        }
        
        .login-page-isolated .btn-success:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(34, 197, 94, 0.4);
        }
        
        /* Balanced margins and paddings */
        .login-page-isolated .mb-3 {
            margin-bottom: 1rem !important;
        }
        
        .login-page-isolated .mb-4 {
            margin-bottom: 1.5rem !important;
        }
        
        .login-page-isolated .mt-3 {
            margin-top: 1rem !important;
        }
        
        .login-page-isolated .mt-4 {
            margin-top: 1.5rem !important;
        }
        
        /* Balanced alerts */
        .login-page-isolated .alert {
            padding: 0.875rem 1rem !important;
            margin-bottom: 1rem !important;
            font-size: 0.9rem !important;
            border-radius: 10px;
        }
        
        /* Balanced step indicators spacing */
        .login-page-isolated .step-indicators {
            margin-bottom: 1rem !important;
        }
        
        /* Balanced text helpers */
        .login-page-isolated small,
        .login-page-isolated .small {
            font-size: 0.875rem !important;
            line-height: 1.5 !important;
        }
        
        /* Balanced OTP input size */
        .login-page-isolated .otp-input {
            font-size: 1.5rem !important;
            letter-spacing: 0.5em !important;
            padding: 0.875rem !important;
            text-align: center;
        }
        
        /* Balanced spacing between sections */
        .login-page-isolated .text-center.mt-3 {
            margin-top: 1rem !important;
        }
        
        /* Create account link section */
        .login-page-isolated .create-account-section {
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid #e2e8f0;
        }
        
        /* Mobile adjustments */
        @media (max-width: 575.98px) {
            .login-page-isolated .login-card {
                padding: 2rem 1.5rem !important;
                margin: 1rem;
                max-width: calc(100% - 2rem);
            }
            
            .login-page-isolated .login-title {
                font-size: 1.5rem !important;
            }
            
            .login-page-isolated .login-subtitle {
                font-size: 0.8125rem !important;
            }
            
            .login-page-isolated .form-control {
                padding: 0.5625rem 0.75rem !important;
                font-size: 0.875rem !important;
            }
            
            .login-page-isolated .btn-lg {
                padding: 0.625rem 1.25rem !important;
                font-size: 0.9375rem !important;
            }
            
            .login-page-isolated .form-group {
                margin-bottom: 0.75rem !important;
            }
        }
        
        /* Very short viewports - compact but no internal scroll */
        @media (max-height: 700px) {
            .login-page-isolated .login-card {
                padding: 1.5rem 1.25rem !important;
            }
            
            .login-page-isolated .login-header {
                margin-bottom: 1rem !important;
            }
            
            .login-page-isolated .login-title {
                font-size: 1.5rem !important;
            }
            
            .login-page-isolated .form-group {
                margin-bottom: 1rem !important;
            }
        }
        
        @media (max-height: 600px) {
            .login-page-isolated .login-card {
                padding: 1.25rem 1rem !important;
            }
            
            .login-page-isolated .login-header {
                margin-bottom: 0.875rem !important;
            }
            
            .login-page-isolated .login-title {
                font-size: 1.375rem !important;
            }
            
            .login-page-isolated .login-subtitle {
                font-size: 0.8125rem !important;
            }
            
            .login-page-isolated .form-group {
                margin-bottom: 0.875rem !important;
            }
            
            .login-page-isolated .form-control {
                padding: 0.625rem 0.75rem !important;
                font-size: 0.8125rem !important;
            }
            
            .login-page-isolated .btn-lg {
                padding: 0.5625rem 1.125rem !important;
                font-size: 0.875rem !important;
            }
        }
        
        /* reCAPTCHA v3 badge positioning */
        .grecaptcha-badge {
            z-index: 9999 !important;
            position: fixed !important;
            bottom: 14px !important;
            right: 14px !important;
        }
        
        /* CRITICAL FIX: Force footer to stay compact and not inherit height from login containers */
        #dynamic-footer,
        #dynamic-footer .container,
        #dynamic-footer .container-fluid,
        #dynamic-footer .row,
        #dynamic-footer [class*="col-"] {
            height: auto !important;
            min-height: auto !important;
            max-height: none !important;
        }
        
        /* Ensure footer is never affected by parent flex containers */
        body > footer,
        body > #dynamic-footer {
            flex: 0 0 auto !important;
        }
        
        /* ==== SIMPLIFIED BRAND SECTION STYLES ==== */
        /* Note: Main brand-section styling is defined above in the layout section */
        
        /* Responsive adjustments for brand section */
        @media (max-width: 1199.98px) {
            .brand-title { font-size: 3rem; }
            .brand-tagline { font-size: 1.125rem; }
        }
        
        @media (max-width: 991.98px) {
            .brand-section { display: none !important; }
        }
    </style>
    
    <?php if ($IS_LOGIN_EDIT_MODE): ?>
    <!-- Custom Inline Editor Styles -->
    <style>
        /* Edit button styling */
        .edit-btn-inline {
            display: inline-block;
            margin-left: 0.5rem;
            padding: 0.25rem 0.5rem;
            background: rgba(255, 255, 255, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.4);
            border-radius: 0.25rem;
            color: white;
            font-size: 0.75rem;
            cursor: pointer;
            transition: all 0.2s ease;
            backdrop-filter: blur(10px);
        }
        .edit-btn-inline:hover {
            background: rgba(255, 255, 255, 0.3);
            border-color: rgba(255, 255, 255, 0.6);
            transform: translateY(-1px);
        }
        .edit-btn-inline i {
            font-size: 0.875rem;
        }
        
        /* Edit mode banner */
        .edit-mode-banner {
            position: fixed;
            top: calc(var(--topbar-height) + var(--navbar-height));
            left: 0;
            right: 0;
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
            padding: 0.75rem 1rem;
            text-align: center;
            font-weight: 600;
            z-index: 9998;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }
        
        /* Make editable sections have subtle indication */
        [data-login-key] {
            position: relative;
        }
        
        /* Edit modal styling */
        #loginEditModal .modal-content {
            border-radius: 1rem;
            border: none;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
        }
        #loginEditModal .modal-header {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
            border-radius: 1rem 1rem 0 0;
            border: none;
        }
        #loginEditModal .modal-header .btn-close {
            filter: brightness(0) invert(1);
        }
        #loginEditModal textarea {
            min-height: 150px;
            border-radius: 0.5rem;
            border: 2px solid #e5e7eb;
            transition: border-color 0.2s ease;
        }
        #loginEditModal textarea:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        /* reCAPTCHA Security Modal - Properly layer above all content */
        /* Force Bootstrap modal variables to use higher z-index */
        .modal {
            --bs-modal-zindex: 10500 !important;
        }
        
        .modal-backdrop {
            --bs-backdrop-zindex: 10499 !important;
            --bs-backdrop-opacity: 0.85 !important;
        }
        
        #recaptchaModal {
            z-index: 10500 !important;
        }
        
        #recaptchaModal.show {
            display: block !important;
        }
        
        #recaptchaModal .modal-dialog {
            position: relative;
            width: auto !important;
            max-width: min(92vw, 460px) !important;
            margin: 1.75rem auto !important;
            z-index: 10501 !important;
        }
        
        #recaptchaModal .modal-content {
            border-radius: 1rem;
            border: none;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
            background: #ffffff !important;
            position: relative;
            z-index: 10502;
        }
        
        #recaptchaModal .modal-header {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
            border-radius: 1rem 1rem 0 0;
            border: none;
            padding: 1.25rem 1.5rem;
        }
        
        #recaptchaModal .modal-title {
            font-weight: 600;
            font-size: 1.25rem;
        }
        
        #recaptchaModal .modal-header .btn-close {
            filter: brightness(0) invert(1);
            opacity: 1;
        }
        
        #recaptchaModal .modal-body {
            padding: 2rem 1.5rem;
            background: #ffffff;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: .75rem;
            text-align: center;
        }
        
        #recaptchaModal .modal-body p {
            color: #64748b;
            margin-bottom: 1.5rem;
            font-size: 0.9375rem;
        }
        
        /* Ensure modal backdrop appears above ALL login content with much darker overlay */
        .modal-backdrop {
            z-index: 10499 !important;
            background-color: #000 !important;
        }
        
        .modal-backdrop.fade {
            opacity: 0 !important;
        }
        
        .modal-backdrop.show {
            opacity: 0.85 !important;
        }
        
        /* Force backdrop to cover entire viewport */
        body.modal-open > .modal-backdrop {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            width: 100vw !important;
            height: 100vh !important;
            z-index: 10499 !important;
        }
        
        /* Override any potential conflicts with login page elements */
        body.modal-open .login-page-isolated .login-content-container,
        body.modal-open .login-page-isolated .brand-section,
        body.modal-open .login-page-isolated .login-card,
        body.modal-open .login-page-isolated .landing-topbar {
            position: relative;
            z-index: 1 !important;
        }
        
        /* Fix for reCAPTCHA widget inside modal */
        #recaptchaWidget {
            display: inline-block;
            margin: 0 auto;
        }
        /* Ensure Google reCAPTCHA inner container centers as well */
        #recaptchaWidget > div { margin: 0 auto !important; }
    </style>
    <?php endif; ?>

</head>
<body class="login-page-isolated has-header-offset<?php echo $IS_LOGIN_EDIT_MODE ? ' edit-mode' : ''; ?>">
    
    <?php if (isset($_GET['debug'])): ?>
    <!-- DEBUG INFO - Remove in production -->
    <div style="position: fixed; top: 0; left: 0; right: 0; background: #000; color: #0f0; padding: 1rem; z-index: 99999; font-family: monospace; font-size: 12px;">
        <strong>DEBUG MODE:</strong><br>
        SESSION ROLE (admin_role): <?php echo isset($_SESSION['admin_role']) ? $_SESSION['admin_role'] : 'NOT SET'; ?><br>
        ADMIN ID: <?php echo isset($_SESSION['admin_id']) ? $_SESSION['admin_id'] : 'NOT SET'; ?><br>
        GET[edit]: <?php echo isset($_GET['edit']) ? $_GET['edit'] : 'NOT SET'; ?><br>
        $IS_LOGIN_EDIT_MODE: <?php echo $IS_LOGIN_EDIT_MODE ? 'TRUE' : 'FALSE'; ?><br>
        BLOCKS LOADED: <?php echo count($LOGIN_SAVED_BLOCKS); ?><br>
        <a href="unified_login.php?edit=1&debug=1" style="color: #fff;">With Edit</a> | 
        <a href="unified_login.php?debug=1" style="color: #fff;">Without Edit</a> | 
        <a href="unified_login.php" style="color: #fff;">Normal View</a>
    </div>
    <?php endif; ?>
    
    <?php
    // Include topbar
    include 'includes/website/topbar.php';
    
    // Configure navbar for login page
    // Let navbar use database-driven system information from theme_settings
    // Municipality logo and name are already fetched at the top of this file
    $custom_brand_config = [
        'href' => 'website/landingpage.php',
        'hide_educaid_logo' => true, // Flag to hide the EducAid logo in navbar
        'show_municipality' => true,
        'municipality_logo' => $municipality_logo,
        'municipality_name' => $municipality_name
    ];
    
    // Empty nav links array - no navigation menu items
    $custom_nav_links = [];
    
    // Include navbar with custom configuration and isolation fixes
    include 'includes/website/navbar.php';
    ?>
    
    <?php
    // Capture session timeout message for display as toast at bottom right
    $timeoutAlert = '';
    if (isset($_GET['timeout'])) {
        $timeoutReason = htmlspecialchars($_GET['timeout']);
        $timeoutMessages = [
            'idle_timeout' => [
                'icon' => 'clock-history',
                'title' => 'Session Expired',
                'message' => 'Your session expired due to inactivity. Please log in again.'
            ],
            'absolute_timeout' => [
                'icon' => 'shield-exclamation',
                'title' => 'Session Expired',
                'message' => 'Your session exceeded the maximum duration. Please log in again.'
            ],
            'session_not_found' => [
                'icon' => 'x-circle',
                'title' => 'Session Invalid',
                'message' => 'Your session was not found. Please log in again.'
            ]
        ];
        
        $messageData = $timeoutMessages[$timeoutReason] ?? [
            'icon' => 'info-circle',
            'title' => 'Session Ended',
            'message' => 'Your session has ended. Please log in again.'
        ];
        
        $timeoutAlert = '
        <div class="position-fixed bottom-0 end-0 p-3" style="z-index: 1100;">
            <div class="alert alert-warning alert-dismissible fade show shadow-lg" role="alert" style="max-width: 320px; font-size: 0.875rem;">
                <div class="d-flex align-items-start gap-2">
                    <i class="bi bi-' . $messageData['icon'] . ' fs-5"></i>
                    <div>
                        <strong>' . $messageData['title'] . '</strong><br>
                        <small>' . $messageData['message'] . '</small>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close" style="font-size: 0.75rem;"></button>
            </div>
        </div>';
    }
    ?>
    
    <?php if ($timeoutAlert): ?>
        <?php echo $timeoutAlert; ?>
    <?php endif; ?>
    
    <!-- Main Login Container - Using unique classes to isolate from navbar -->
    <div class="login-content-container">
        <div class="login-main-wrapper">
            <div class="container-fluid p-0">
                <div class="row g-0 h-100">
            <!-- Brand Section - Hidden on mobile, visible on tablet+ -->
            <div class="col-lg-6 d-none d-lg-flex brand-section">
                <div class="brand-content text-center">
                    <!-- Municipality Logo -->
                    <?php if ($preset_logo): ?>
                    <div class="municipality-logo-container mb-4">
                        <img src="<?= htmlspecialchars($preset_logo) ?>" 
                             alt="<?= htmlspecialchars($municipality_name) ?> Logo" 
                             class="municipality-logo-login"
                             onerror="this.style.display='none';">
                    </div>
                    <?php endif; ?>
                    
                    <!-- Main Title -->
                    <h1 class="brand-title">EducAid</h1>
                    
                    <!-- Tagline -->
                    <p class="brand-tagline">Empowering Dreams Through Education</p>
                    
                    <!-- Subtitle -->
                    <p class="brand-subtitle">Your gateway to accessible educational<br>financial assistance in General Trias</p>
                </div>
            </div>

            <!-- Form Section -->
            <div class="col-lg-6 form-section">
                <div class="login-card">
                    <div class="login-header">
                        <h2 class="login-title">Welcome Back</h2>
                        <p class="login-subtitle">Sign in to access your EducAid account</p>
                    </div>

                    <!-- Step Indicators -->
                    <div class="step-indicators justify-content-center mb-4" style="display: none;">
                        <div class="step-indicator-item">
                            <div class="step-indicator" id="indicator1"></div>
                            <span class="step-label d-none d-sm-block" id="label1">Email</span>
                        </div>
                        <div class="step-indicator-item">
                            <div class="step-indicator" id="indicator2"></div>
                            <span class="step-label d-none d-sm-block" id="label2">Verify</span>
                        </div>
                        <div class="step-indicator-item">
                            <div class="step-indicator" id="indicator3"></div>
                            <span class="step-label d-none d-sm-block" id="label3">Password</span>
                        </div>
                    </div>

                    <!-- Messages Container -->
                    <div id="messages" class="message-container mb-3"></div>

                    <!-- Logout Success Message -->
                    <?php if (isset($_SESSION['logout_message'])): ?>
                                <div class="alert alert-success alert-dismissible fade show" role="alert">
                                    <i class="bi bi-check-circle-fill me-2"></i>
                                    <?= htmlspecialchars($_SESSION['logout_message']) ?>
                                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                </div>
                                <?php unset($_SESSION['logout_message']); ?>
                                <?php endif; ?>

                                <!-- Archived Account Message -->
                                <?php if (isset($_GET['archived']) && $_GET['archived'] == '1'): ?>
                                <div class="alert alert-warning alert-dismissible fade show" role="alert">
                                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                                    <strong>Account Archived</strong><br>
                                    Your account has been archived because you did not advance to the next year level. 
                                    Please contact the admin office for verification and account reactivation.
                                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                </div>
                                <?php endif; ?>

                                <!-- Step 1: Simplified Credentials (Email + Password Only) -->
                                <div id="step1" class="step active">
                                    <form id="loginForm">
                                        <div class="form-group mb-3">
                                            <label class="form-label">Email Address</label>
                                            <input type="email" class="form-control form-control" id="email" name="email" 
                                                   placeholder="Enter your email address" required autocomplete="email">
                                        </div>
                                        <div class="form-group mb-4">
                                            <label class="form-label">Password</label>
                                            <div class="position-relative">
                                                <input type="password" class="form-control form-control" id="password" name="password" 
                                                       placeholder="Enter your password" required autocomplete="current-password">
                                                <button type="button" class="btn btn-link position-absolute end-0 top-50 translate-middle-y" 
                                                        onclick="togglePassword('password')" style="text-decoration: none; padding: 0 15px;">
                                                    <i class="bi bi-eye" id="password-toggle-icon"></i>
                                                </button>
                                            </div>
                                        </div>
                                        
                                        <div class="d-grid">
                                            <button type="submit" class="btn btn-primary btn-lg" id="loginSubmitBtn">
                                                <i class="bi bi-envelope me-2"></i>Send Verification Code
                                            </button>
                                        </div>
                                    </form>
                                    <div class="text-center mt-3">
                                        <a href="#" onclick="showForgotPassword()" class="text-decoration-none">
                                            <small>Forgot your password?</small>
                                        </a>
                                    </div>
                                </div>

                                <!-- Step 2: OTP Verification -->
                                <div id="step2" class="step">
                                    <div class="text-center mb-4">
                                        <i class="bi bi-shield-check text-primary" style="font-size: 3rem;"></i>
                                        <h5 class="mt-3">Verify Your Identity</h5>
                                        <p class="text-muted">Enter the 6-digit code sent to your email</p>
                                    </div>
                                    <form id="otpForm">
                                        <div class="form-group mb-4">
                                            <input type="text" class="form-control form-control otp-input text-center" 
                                                   id="login_otp" name="login_otp" placeholder="000000" maxlength="6" required
                                                   style="font-size: 1.5rem; letter-spacing: 0.5em;">
                                        </div>
                                        <div class="d-grid">
                                            <button type="submit" class="btn btn-primary btn-lg">
                                                <i class="bi bi-shield-check me-2"></i>Verify & Sign In
                                            </button>
                                        </div>
                                    </form>
                                    <div class="text-center mt-3">
                                        <a href="#" onclick="showStep1()" class="text-decoration-none">
                                            <i class="bi bi-arrow-left me-2"></i><small>Back to login</small>
                                        </a>
                                    </div>
                                </div>

                                <!-- Forgot Password Step 1: Email -->
                                <div id="forgotStep1" class="step">
                                    <div class="text-center mb-4">
                                        <i class="bi bi-key text-primary" style="font-size: 3rem;"></i>
                                        <h5 class="mt-3">Reset Your Password</h5>
                                        <p class="text-muted">Enter your email address to receive a reset code</p>
                                    </div>
                                    <form id="forgotForm">
                                        <div class="form-group mb-4">
                                            <label class="form-label">Email Address</label>
                                            <input type="email" class="form-control form-control" id="forgot_email" name="forgot_email" required>
                                        </div>
                                        <div class="d-grid">
                                            <button type="submit" class="btn btn-primary btn-lg">
                                                <i class="bi bi-envelope me-2"></i>Send Reset Code
                                            </button>
                                        </div>
                                    </form>
                                    <div class="text-center mt-3">
                                        <a href="#" onclick="showStep1()" class="text-decoration-none">
                                            <i class="bi bi-arrow-left me-2"></i><small>Back to login</small>
                                        </a>
                                    </div>
                                </div>

                                <!-- Forgot Password Step 2: OTP Verification -->
                                <div id="forgotStep2" class="step">
                                    <div class="text-center mb-4">
                                        <i class="bi bi-check-circle text-primary" style="font-size: 3rem;"></i>
                                        <h5 class="mt-3">Enter Reset Code</h5>
                                        
                                        <p class="text-muted">Enter the 6-digit code sent to your email</p>
                                    </div>
                                    <form id="forgotOtpForm">
                                        <div class="form-group mb-4">
                                            <input type="text" class="form-control form-control otp-input text-center" 
                                                   id="forgot_otp" name="forgot_otp" placeholder="000000" maxlength="6" required
                                                   style="font-size: 1.5rem; letter-spacing: 0.5em;">
                                        </div>
                                        <div class="d-grid">
                                            <button type="submit" class="btn btn-primary btn-lg">
                                                <i class="bi bi-check-circle me-2"></i>Verify Code
                                            </button>
                                        </div>
                                    </form>
                                    <div class="text-center mt-3">
                                        <a href="#" onclick="showForgotPassword()" class="text-decoration-none">
                                            <i class="bi bi-arrow-left me-2"></i><small>Back to email</small>
                                        </a>
                                    </div>
                                </div>

                                <!-- Forgot Password Step 3: New Password -->
                                <div id="forgotStep3" class="step">
                                    <div class="text-center mb-4">
                                        <i class="bi bi-lock text-primary" style="font-size: 3rem;"></i>
                                        <h5 class="mt-3">Set New Password</h5>
                                        <p class="text-muted">Choose a strong password for your account</p>
                                    </div>
                                    <form id="newPasswordForm">
                                        <div class="form-group mb-3">
                                            <label class="form-label">New Password</label>
                                            <div class="position-relative">
                                                <input type="password" class="form-control" id="forgot_new_password" 
                                                       name="forgot_new_password" placeholder="Enter a strong password" required>
                                                <button type="button" class="btn btn-link position-absolute end-0 top-50 translate-middle-y" 
                                                        onclick="toggleForgotPassword('forgot_new_password')" style="text-decoration: none; padding: 0 15px;">
                                                    <i class="bi bi-eye" id="forgot_new_password-toggle-icon"></i>
                                                </button>
                                            </div>
                                            <!-- Password Strength Indicator -->
                                            <div class="progress mt-2" style="height: 6px;">
                                                <div class="progress-bar bg-secondary" role="progressbar" style="width: 0%" 
                                                     aria-valuenow="0" aria-valuemin="0" aria-valuemax="100" id="forgotStrengthBar"></div>
                                            </div>
                                            <small id="forgotStrengthText" class="text-muted d-block mt-1">
                                                <i class="bi bi-info-circle me-1"></i>Enter a password to see strength
                                            </small>
                                        </div>
                                        <div class="form-group mb-4">
                                            <label class="form-label">Confirm New Password</label>
                                            <div class="position-relative">
                                                <input type="password" class="form-control" id="confirm_password" 
                                                       name="confirm_password" placeholder="Re-enter your password" required>
                                                <button type="button" class="btn btn-link position-absolute end-0 top-50 translate-middle-y" 
                                                        onclick="toggleForgotPassword('confirm_password')" style="text-decoration: none; padding: 0 15px;">
                                                    <i class="bi bi-eye" id="confirm_password-toggle-icon"></i>
                                                </button>
                                            </div>
                                            <small id="forgotPasswordMatchText" class="text-muted d-block mt-1">
                                                <i class="bi bi-info-circle me-1"></i>Re-enter your password to confirm
                                            </small>
                                        </div>
                                        <div class="d-grid">
                                            <button type="submit" class="btn btn-primary btn-lg" id="forgotPasswordSubmitBtn" disabled>
                                                <i class="bi bi-key me-2"></i>Update Password
                                            </button>
                                        </div>
                                    </form>
                                </div>

                                <!-- Registration Section -->
                                <div class="signup-section mt-3 text-center">
                                    <span class="text-muted small">Don't have an account? </span>
                                    <a href="modules/student/student_register.php" class="text-decoration-none small fw-semibold">
                                        Create Account
                                    </a>
                                </div>
                </div> <!-- Close login-card -->
            </div> <!-- Close form-section col-lg-6 -->
        </div> <!-- Close row -->
            </div> <!-- Close container-fluid -->
        </div> <!-- Close login-main-wrapper -->
    </div> <!-- Close login-content-container -->

    <script src="assets/js/bootstrap.bundle.min.js"></script>
    <!-- Consistent mobile navbar behavior (burger → X, body.navbar-open) -->
    <script src="assets/js/website/mobile-navbar.js"></script>
    <script src="assets/js/login.js"></script>
    <script src="assets/js/shared/password_strength_validator.js"></script>
    
    <!-- reCAPTCHA v2 modal + integration -->
    <script>
        // Email validation function
        function isValidEmail(email) {
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return emailRegex.test(email);
        }
        
        // Message display function
        function showMessage(message, type) {
            const messagesContainer = document.getElementById('messages');
            messagesContainer.innerHTML = `
                <div class="alert alert-${type} alert-dismissible fade show" role="alert">
                    ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            `;
            
            // Auto-dismiss after 5 seconds
            setTimeout(() => {
                const alert = messagesContainer.querySelector('.alert');
                if (alert) {
                    alert.remove();
                }
            }, 5000);
        }
        
        // Button loading state function
        function setButtonLoading(button, loading, originalText = '') {
            if (loading) {
                button.disabled = true;
                button.innerHTML = 'Processing...';
            } else {
                button.disabled = false;
                button.innerHTML = originalText || button.innerHTML;
            }
        }
        
        // Override the login form submission to require reCAPTCHA v2 token from a modal
        document.addEventListener('DOMContentLoaded', function() {
            const loginForm = document.getElementById('loginForm');
            if (loginForm) {
                // Remove existing event listeners and add our own
                const newForm = loginForm.cloneNode(true);
                loginForm.parentNode.replaceChild(newForm, loginForm);
                
                newForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    const email = document.getElementById('email').value.trim();
                    const password = document.getElementById('password').value;
                    
                    // Basic validation
                    if (!email || !password) {
                        showMessage('Please fill in all fields.', 'danger');
                        return;
                    }
                    
                    if (!isValidEmail(email)) {
                        showMessage('Please enter a valid email address.', 'danger');
                        return;
                    }
                    // Open reCAPTCHA modal and render widget (or reuse existing)
                    openRecaptchaModal({
                        onSuccess: function(token) {
                            const submitBtn = newForm.querySelector('button[type="submit"]');
                            const originalText = submitBtn.innerHTML;
                            setButtonLoading(submitBtn, true);

                            const formData = new FormData();
                            formData.append('email', email);
                            formData.append('password', password);
                            formData.append('g-recaptcha-response', token);

                            fetch('unified_login.php', {
                                method: 'POST',
                                body: formData,
                                credentials: 'same-origin', // Include cookies for session persistence
                                headers: {
                                    'X-Requested-With': 'XMLHttpRequest'
                                }
                            })
                            .then(response => response.json())
                            .then(data => {
                                setButtonLoading(submitBtn, false, originalText);
                                if (data.status === 'otp_sent') {
                                    showStep2();
                                    showMessage('Verification code sent to your email!', 'success');
                                } else {
                                    showMessage(data.message || 'Security verification failed.', 'danger');
                                }
                            })
                            .catch(error => {
                                setButtonLoading(submitBtn, false, originalText);
                                showMessage('Connection error. Please try again.', 'danger');
                            });
                        },
                        onFail: function(errMsg) {
                            showMessage(errMsg || 'CAPTCHA verification failed or was cancelled.', 'danger');
                        }
                    });
                });
            }
        });

        // reCAPTCHA modal handling
        let recaptchaWidgetId = null;
        let recaptchaRendered = false;
        // Global references used to ensure widget callbacks can hide the currently shown modal
        window.__recaptchaPendingCallbacks = null;
        window.__recaptchaCurrentBsModal = null;

        function openRecaptchaModal(callbacks) {
            // Create modal if not exists
            let modalEl = document.getElementById('recaptchaModal');
            if (!modalEl) {
                modalEl = document.createElement('div');
                modalEl.className = 'modal fade';
                modalEl.id = 'recaptchaModal';
                modalEl.tabIndex = -1;
                modalEl.innerHTML = `
                    <div class="modal-dialog modal-dialog-centered modal-sm" style="max-width: 460px;">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Security Check</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <p style="text-align: center; margin-bottom: 1.5rem;">Please complete the reCAPTCHA to continue.</p>
                                <div style="display: flex; justify-content: center; align-items: center; width: 100%;">
                                    <div id="recaptchaWidget" style="display: inline-block;"></div>
                                </div>
                                <div id="recaptchaError" class="text-danger mt-2" style="display:none; text-align: center;"></div>
                            </div>
                        </div>
                    </div>
                `;
                document.body.appendChild(modalEl);
            }

            // Show modal
            const bsModal = new bootstrap.Modal(modalEl);
            bsModal.show();

            // store current modal and callbacks globally so widget callback can always reference them
            window.__recaptchaPendingCallbacks = callbacks || null;
            window.__recaptchaCurrentBsModal = bsModal;

            // Render widget once
            setTimeout(() => {
                if (!recaptchaRendered) {
                    if (typeof grecaptcha === 'undefined' || !grecaptcha.render) {
                        document.getElementById('recaptchaError').textContent = 'reCAPTCHA failed to load.';
                        document.getElementById('recaptchaError').style.display = 'block';
                        if (callbacks && callbacks.onFail) callbacks.onFail('reCAPTCHA failed to load.');
                        return;
                    }

                    // render with a global callback so it always calls the current modal instance
                    recaptchaWidgetId = grecaptcha.render('recaptchaWidget', {
                        'sitekey': '<?php echo $recaptcha_v2_site_key; ?>',
                        'theme': 'light',
                        'callback': function(token) {
                            try {
                                if (window.__recaptchaCurrentBsModal) {
                                    window.__recaptchaCurrentBsModal.hide();
                                }
                            } catch (e) { /* ignore */ }

                            // call the most recent pending callback
                            if (window.__recaptchaPendingCallbacks && window.__recaptchaPendingCallbacks.onSuccess) {
                                // copy callback ref and clear global to avoid double-calls
                                const cb = window.__recaptchaPendingCallbacks.onSuccess;
                                window.__recaptchaPendingCallbacks = null;
                                try { cb(token); } catch (err) { console.error(err); }
                            }

                            // Reset widget for next time
                            try { grecaptcha.reset(recaptchaWidgetId); } catch (e) {}
                        },
                        'expired-callback': function() {
                            if (window.__recaptchaPendingCallbacks && window.__recaptchaPendingCallbacks.onFail) {
                                const cbf = window.__recaptchaPendingCallbacks.onFail;
                                window.__recaptchaPendingCallbacks = null;
                                try { cbf('reCAPTCHA token expired. Please try again.'); } catch (err) {}
                            }
                        }
                    });
                    recaptchaRendered = true;
                } else {
                    // If already rendered, just reset and make sure pending callbacks reference the current modal
                    try { grecaptcha.reset(recaptchaWidgetId); } catch (e) {}
                }
            }, 200);
        }

        // Hook forgot-password form to require reCAPTCHA before sending reset OTP
        document.addEventListener('DOMContentLoaded', function() {
            const forgotForm = document.getElementById('forgotForm');
            if (forgotForm) {
                const newForgot = forgotForm.cloneNode(true);
                forgotForm.parentNode.replaceChild(newForgot, forgotForm);

                newForgot.addEventListener('submit', function(e) {
                    e.preventDefault();
                    const email = document.getElementById('forgot_email').value.trim();
                    if (!isValidEmail(email)) {
                        showMessage('Please enter a valid email address.', 'danger');
                        return;
                    }

                    openRecaptchaModal({
                        onSuccess: function(token) {
                            const submitBtn = newForgot.querySelector('button[type="submit"]');
                            const originalText = submitBtn.innerHTML;
                            setButtonLoading(submitBtn, true);

                            const formData = new FormData();
                            formData.append('forgot_action', 'send_otp');
                            formData.append('forgot_email', email);
                            formData.append('g-recaptcha-response', token);

                            fetch('unified_login.php', {
                                method: 'POST',
                                body: formData,
                                credentials: 'same-origin',
                                headers: { 'X-Requested-With': 'XMLHttpRequest' }
                            })
                            .then(r => r.text())
                            .then(txt => {
                                // log raw server response for debugging
                                console.log('Forgot OTP response text:', txt);
                                let data = {};
                                try { data = JSON.parse(txt); } catch (e) { data = { status: 'error', message: 'Invalid JSON response' }; }
                                setButtonLoading(submitBtn, false, originalText);
                                if (data.status === 'success') {
                                    showMessage('Reset code sent to your email!', 'success');
                                    // Move to OTP verification step for forgot-password
                                    if (typeof showForgotStep2 === 'function') {
                                        showForgotStep2();
                                    } else {
                                        // fallback to generic forgot view
                                        showForgotPassword();
                                    }
                                } else {
                                    showMessage(data.message || 'Security verification failed.', 'danger');
                                }
                            })
                            .catch(err => {
                                setButtonLoading(submitBtn, false, originalText);
                                showMessage('Connection error. Please try again.', 'danger');
                            });
                        },
                        onFail: function(msg) {
                            showMessage(msg || 'CAPTCHA verification failed or cancelled.', 'danger');
                        }
                    });
                });
            }
        });
    </script>
    
    <?php if ($IS_LOGIN_EDIT_MODE): ?>
    <!-- Edit Mode Banner -->
    <div class="edit-mode-banner">
        <i class="bi bi-pencil-square me-2"></i>
        <strong>EDIT MODE ACTIVE</strong> - Click the <i class="bi bi-pencil-fill"></i> buttons to edit content
        <a href="modules/admin/municipality_content.php" style="color: white; text-decoration: underline; margin-left: 1rem;">Exit Edit Mode</a>
    </div>
    
    <!-- Edit Modal -->
    <div class="modal fade" id="loginEditModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-pencil-fill me-2"></i>
                        <span id="editModalTitle">Edit Content</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="editContent" class="form-label fw-bold">Content</label>
                        <textarea class="form-control" id="editContent" rows="5"></textarea>
                        <small class="text-muted">
                            <i class="bi bi-info-circle me-1"></i>
                            You can use HTML for formatting (e.g., &lt;b&gt;bold&lt;/b&gt;, &lt;i&gt;italic&lt;/i&gt;, &lt;br&gt; for line breaks)
                        </small>
                    </div>
                    <input type="hidden" id="editBlockKey">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-1"></i> Cancel
                    </button>
                    <button type="button" class="btn btn-primary" id="saveContentBtn">
                        <i class="bi bi-check-circle me-1"></i> Save Changes
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Custom Editor Script -->
    <script>
        console.log('=== LOGIN PAGE EDITOR INITIALIZED ===');
        
        // Open edit modal
        window.openEditModal = function(blockKey, currentContent, title) {
            console.log('Opening edit modal for:', blockKey);
            
            document.getElementById('editModalTitle').textContent = 'Edit ' + title;
            document.getElementById('editContent').value = currentContent;
            document.getElementById('editBlockKey').value = blockKey;
            
            const modal = new bootstrap.Modal(document.getElementById('loginEditModal'));
            modal.show();
        };
        
        // Save content
        document.getElementById('saveContentBtn').addEventListener('click', function() {
            const blockKey = document.getElementById('editBlockKey').value;
            const newContent = document.getElementById('editContent').value.trim();
            const btn = this;
            
            if (!newContent) {
                alert('Content cannot be empty');
                return;
            }
            
            // Show loading state
            const originalHTML = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving...';
            
            const formData = new FormData();
            formData.append('municipality_id', '1');
            formData.append('csrf_token', '<?= $EDIT_CSRF_TOKEN ?>');
            formData.append(blockKey, newContent);
            
            console.log('Saving block:', blockKey, 'Content:', newContent);
            
            fetch('services/save_login_content.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                console.log('Save response:', data);
                
                if (data.success) {
                    // Update the content on the page
                    const element = document.querySelector('[data-login-key="' + blockKey + '"]');
                    if (element) {
                        element.innerHTML = newContent;
                    }
                    
                    // Show success message
                    showToast('Content saved successfully!', 'success');
                    
                    // Close modal
                    bootstrap.Modal.getInstance(document.getElementById('loginEditModal')).hide();
                } else {
                    showToast('Failed to save: ' + (data.error || 'Unknown error'), 'danger');
                }
            })
            .catch(error => {
                console.error('Save error:', error);
                showToast('Error saving content. Please try again.', 'danger');
            })
            .finally(() => {
                btn.disabled = false;
                btn.innerHTML = originalHTML;
            });
        });
        
        // Toast notification function
        window.showToast = function(message, type = 'success') {
            const toastContainer = document.getElementById('toastContainer') || createToastContainer();
            
            const toast = document.createElement('div');
            toast.className = `toast align-items-center text-white bg-${type} border-0`;
            toast.setAttribute('role', 'alert');
            toast.innerHTML = `
                <div class="d-flex">
                    <div class="toast-body">
                        <i class="bi bi-${type === 'success' ? 'check-circle' : 'exclamation-triangle'} me-2"></i>
                        ${message}
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                </div>
            `;
            
            toastContainer.appendChild(toast);
            const bsToast = new bootstrap.Toast(toast, { delay: 3000 });
            bsToast.show();
            
            toast.addEventListener('hidden.bs.toast', () => toast.remove());
        };
        
        function createToastContainer() {
            const container = document.createElement('div');
            container.id = 'toastContainer';
            container.className = 'toast-container position-fixed top-0 end-0 p-3';
            container.style.zIndex = '9999';
            document.body.appendChild(container);
            return container;
        }
        
        // Toggle section visibility (hide/show)
        window.toggleSectionVisibility = function(sectionKey, isVisible) {
            if (!confirm(isVisible ? 'Show this section?' : 'Hide this section? (Content will be archived and can be restored later)')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('section_key', sectionKey);
            formData.append('is_visible', isVisible ? '1' : '0');
            formData.append('csrf_token', '<?= $TOGGLE_CSRF_TOKEN ?>');
            
            fetch('services/toggle_section_visibility.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    // Reload page to reflect changes
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                } else {
                    showToast('Failed to toggle visibility: ' + (data.error || 'Unknown error'), 'danger');
                }
            })
            .catch(error => {
                console.error('Toggle visibility error:', error);
                showToast('Error toggling visibility. Please try again.', 'danger');
            });
        };
        
        console.log('✅ Login Page Editor ready');
    </script>
    <?php endif; ?>

    <!-- Password Strength Validator for Forgot Password -->
    <script>
        // Toggle password visibility for forgot password fields
        function toggleForgotPassword(inputId) {
            const input = document.getElementById(inputId);
            const icon = document.getElementById(inputId + '-toggle-icon');
            if (input && icon) {
                if (input.type === 'password') {
                    input.type = 'text';
                    icon.classList.remove('bi-eye');
                    icon.classList.add('bi-eye-slash');
                } else {
                    input.type = 'password';
                    icon.classList.remove('bi-eye-slash');
                    icon.classList.add('bi-eye');
                }
            }
        }

        // Initialize password strength validator for forgot password form
        document.addEventListener('DOMContentLoaded', function() {
            // Check if setupPasswordValidation function exists
            if (typeof setupPasswordValidation === 'function') {
                setupPasswordValidation({
                    passwordInputId: 'forgot_new_password',
                    confirmPasswordInputId: 'confirm_password',
                    strengthBarId: 'forgotStrengthBar',
                    strengthTextId: 'forgotStrengthText',
                    passwordMatchTextId: 'forgotPasswordMatchText',
                    submitButtonSelector: '#forgotPasswordSubmitBtn',
                    minStrength: 70,
                    requireMatch: true
                });
                console.log('✅ Password strength validator initialized for forgot password');
            } else {
                console.warn('⚠️ Password strength validator not loaded');
            }
        });
    </script>

</body>
</html>