<?php
// Load secure session configuration (must be before session_start)
require_once __DIR__ . '/../../config/session_config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Log logout before destroying session
if (isset($_SESSION['admin_id']) && isset($_SESSION['admin_username'])) {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../bootstrap_services.php';
    
    $auditLogger = new AuditLogger($connection);
    $auditLogger->logLogout(
        $_SESSION['admin_id'],
        'admin',
        $_SESSION['admin_username']
    );
}

// Clear all admin-specific session variables
unset($_SESSION['admin_id']);
unset($_SESSION['admin_username']);
unset($_SESSION['admin_role']); // New: Clear admin role

// Remove any admin-specific temporary variables (including blacklist verification)
$adminKeys = array_filter(array_keys($_SESSION), function($key) {
    return strpos($key, 'admin_') === 0 || 
           strpos($key, 'blacklist_') === 0 ||
           strpos($key, 'verification_') === 0;
});

foreach ($adminKeys as $key) {
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

// Clear any workflow or temporary admin states
unset($_SESSION['workflow_status']);
unset($_SESSION['temp_admin_action']);
unset($_SESSION['schedule_creation']);

header("Location: ../../unified_login.php?logout=success");
exit;
?>