<?php
if (session_status() === PHP_SESSION_NONE) {
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
}

// Log logout before destroying session
if (isset($_SESSION['student_id']) && isset($_SESSION['student_username'])) {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../bootstrap_services.php';
    
    $auditLogger = new AuditLogger($connection);
    $auditLogger->logLogout(
        $_SESSION['student_id'],
        'student',
        $_SESSION['student_username']
    );
}

// Only unset student-specific session variables
unset($_SESSION['student_id']);
unset($_SESSION['student_username']);
unset($_SESSION['schedule_modal_shown']);

// Remove any student-specific temporary variables
$studentKeys = array_filter(array_keys($_SESSION), function($key) {
    return strpos($key, 'student_') === 0 || 
           strpos($key, 'profile_') === 0 || 
           strpos($key, 'upload_') === 0 ||
           strpos($key, 'qr_codes') === 0;
});

foreach ($studentKeys as $key) {
    unset($_SESSION[$key]);
}

// Clear any shared login/OTP variables (since user is logging out)
unset($_SESSION['login_otp']);
unset($_SESSION['login_otp_time']);
unset($_SESSION['login_pending']);
unset($_SESSION['forgot_otp']);
unset($_SESSION['forgot_otp_time']);
unset($_SESSION['forgot_otp_email']);
unset($_SESSION['forgot_otp_role']);
unset($_SESSION['forgot_otp_verified']);

header("Location: ../../unified_login.php?logout=success");
exit;
?>