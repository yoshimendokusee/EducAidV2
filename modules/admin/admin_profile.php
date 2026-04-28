<?php
/** @phpstan-ignore-file */
// Start output buffering to prevent any output before JSON responses
ob_start();

include __DIR__ . '/../../config/database.php';
include __DIR__ . '/../../bootstrap_services.php';
require_once __DIR__ . '/../../includes/CSRFProtection.php';

// Load secure session configuration (must be before session_start)
require_once __DIR__ . '/../../config/session_config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Helper function to send clean JSON responses
function sendJsonResponse($data) {
    ob_clean(); // Clear any output buffer
    header('Content-Type: application/json');
    echo json_encode($data);
    exit();
}

if (!isset($_SESSION['admin_username'])) {
  header("Location: ../../unified_login.php");
  exit;
}

$otpService = new OTPService($connection);

// Get current admin information
$currentAdmin = null;
$admin_id = $_SESSION['admin_id'] ?? null;
if (!$admin_id && isset($_SESSION['admin_username'])) {
  $adminQuery = pg_query_params($connection, "SELECT admin_id, username, email, first_name, middle_name, last_name FROM admins WHERE username = $1", [$_SESSION['admin_username']]);
  if ($adminQuery && pg_num_rows($adminQuery) > 0) {
    $currentAdmin = pg_fetch_assoc($adminQuery);
    $_SESSION['admin_id'] = $currentAdmin['admin_id'];
  }
} else if ($admin_id) {
  $adminQuery = pg_query_params($connection, "SELECT admin_id, username, email, first_name, middle_name, last_name FROM admins WHERE admin_id = $1", [$admin_id]);
  if ($adminQuery && pg_num_rows($adminQuery) > 0) {
    $currentAdmin = pg_fetch_assoc($adminQuery);
  }
}

// Handle success parameter from redirect
if (isset($_GET['success']) && isset($_GET['msg'])) {
  $successMessages = [
    'email' => 'Email address updated successfully!',
    'password' => 'Password updated successfully!'
  ];
  
  if (isset($successMessages[$_GET['msg']])) {
    $_SESSION['success_message'] = $successMessages[$_GET['msg']];
  } else {
    $_SESSION['success_message'] = "Profile updated successfully!";
  }
  
  header("Location: admin_profile.php");
  exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $isAjaxRequest = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
  
  // Handle email change OTP request
  if (isset($_POST['email_otp_request'])) {
    $token = $_POST['csrf_token'] ?? '';
    
    if (!CSRFProtection::validateToken('email_otp_request', $token)) {
      $response = [
        'status' => 'error',
        'message' => 'Security verification failed. Please refresh and try again.',
        'next_token' => CSRFProtection::generateToken('email_otp_request')
      ];
      
      if ($isAjaxRequest) {
        sendJsonResponse($response);
      }
    }
    
    $current_password = $_POST['current_password'];
    $new_email = trim($_POST['new_email']);
    
    $admin_id = $_SESSION['admin_id'] ?? null;
    if (!$admin_id && isset($_SESSION['admin_username'])) {
      $adminQuery = pg_query_params($connection, "SELECT admin_id, password, email FROM admins WHERE username = $1", [$_SESSION['admin_username']]);
      $adminData = pg_fetch_assoc($adminQuery);
      $admin_id = $adminData['admin_id'];
    } else {
      $adminQuery = pg_query_params($connection, "SELECT password, email FROM admins WHERE admin_id = $1", [$admin_id]);
      $adminData = pg_fetch_assoc($adminQuery);
    }
    
    $response = ['status' => 'error', 'message' => 'Unknown error occurred'];
    
    if (!$adminData) {
      $response['message'] = 'Session error. Please login again.';
      $response['next_token'] = CSRFProtection::generateToken('email_otp_request');
    } elseif (!password_verify($current_password, $adminData['password'])) {
      $response['message'] = 'Current password is incorrect.';
      $response['next_token'] = CSRFProtection::generateToken('email_otp_request');
    } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
      $response['message'] = 'Please enter a valid email address.';
      $response['next_token'] = CSRFProtection::generateToken('email_otp_request');
    } elseif ($new_email === $adminData['email']) {
      $response['message'] = 'New email must be different from current email.';
      $response['next_token'] = CSRFProtection::generateToken('email_otp_request');
    } else {
      $_SESSION['temp_new_email'] = $new_email;
      $_SESSION['temp_admin_id'] = $admin_id;
      
      error_log("ADMIN PROFILE: Attempting to send OTP to $new_email for admin $admin_id");
      
      if ($otpService->sendOTP($new_email, 'email_change', $admin_id)) {
        error_log("ADMIN PROFILE: OTP sent successfully");
        $response = [
          'status' => 'success', 
          'message' => 'Verification code sent to your new email address.',
          'next_token' => CSRFProtection::generateToken('email_otp_verify')
        ];
      } else {
        error_log("ADMIN PROFILE: OTP sending failed");
        $response['message'] = 'Failed to send verification code. Please try again.';
        $response['next_token'] = CSRFProtection::generateToken('email_otp_request');
      }
    }
    
    if ($isAjaxRequest) {
      sendJsonResponse($response);
    }
  }
  
  // Handle email change OTP verification
  elseif (isset($_POST['email_otp_verify'])) {
    $token = $_POST['csrf_token'] ?? '';
    
    if (!CSRFProtection::validateToken('email_otp_verify', $token)) {
      $response = [
        'status' => 'error',
        'message' => 'Security verification failed. Please refresh and try again.',
        'next_token' => CSRFProtection::generateToken('email_otp_verify')
      ];
      
      if ($isAjaxRequest) {
        sendJsonResponse($response);
      }
    }
    
    $otp = trim($_POST['otp']);
    $admin_id = $_SESSION['temp_admin_id'] ?? null;
    $new_email = $_SESSION['temp_new_email'] ?? null;
    
    $response = ['status' => 'error', 'message' => 'Unknown error occurred'];
    
    if (!$admin_id || !$new_email) {
      $response['message'] = 'Session expired. Please start the process again.';
      $response['next_token'] = CSRFProtection::generateToken('email_otp_verify');
    } elseif (empty($otp) || !preg_match('/^\d{6}$/', $otp)) {
      $response['message'] = 'Please enter a valid 6-digit verification code.';
      $response['next_token'] = CSRFProtection::generateToken('email_otp_verify');
    } elseif ($otpService->verifyOTP($admin_id, $otp, 'email_change')) {
      $result = pg_query_params($connection, "UPDATE admins SET email = $1 WHERE admin_id = $2", [$new_email, $admin_id]);
      
      if ($result) {
        unset($_SESSION['temp_new_email'], $_SESSION['temp_admin_id']);
        
        $notification_msg = "Admin email updated to " . $new_email;
        pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
        
        if ($isAjaxRequest) {
          $response = ['status' => 'success', 'message' => 'Email updated successfully!', 'redirect' => 'admin_profile.php?success=1&msg=email'];
        } else {
          $_SESSION['success_message'] = 'Email address updated successfully!';
          header('Location: admin_profile.php?success=1&msg=email');
          exit();
        }
      } else {
        $response['message'] = 'Failed to update email. Please try again.';
        $response['next_token'] = CSRFProtection::generateToken('email_otp_verify');
      }
    } else {
      $response['message'] = 'Invalid or expired verification code.';
      $response['next_token'] = CSRFProtection::generateToken('email_otp_verify');
    }
    
    if ($isAjaxRequest) {
      sendJsonResponse($response);
    }
  }
  
  // Handle password change OTP request
  elseif (isset($_POST['password_otp_request'])) {
    $token = $_POST['csrf_token'] ?? '';
    
    if (!CSRFProtection::validateToken('password_otp_request', $token)) {
      $response = [
        'status' => 'error',
        'message' => 'Security verification failed. Please refresh and try again.',
        'next_token' => CSRFProtection::generateToken('password_otp_request')
      ];
      
      if ($isAjaxRequest) {
        sendJsonResponse($response);
      }
    }
    
    $current_password = $_POST['current_password'];
    $new_password = trim($_POST['new_password']);
    $confirm_password = trim($_POST['confirm_password']);
    
    $admin_id = $_SESSION['admin_id'] ?? null;
    if (!$admin_id && isset($_SESSION['admin_username'])) {
      $adminQuery = pg_query_params($connection, "SELECT admin_id, password, email FROM admins WHERE username = $1", [$_SESSION['admin_username']]);
      $adminData = pg_fetch_assoc($adminQuery);
      $admin_id = $adminData['admin_id'];
    } else {
      $adminQuery = pg_query_params($connection, "SELECT password, email FROM admins WHERE admin_id = $1", [$admin_id]);
      $adminData = pg_fetch_assoc($adminQuery);
    }
    
    $response = ['status' => 'error', 'message' => 'Unknown error occurred'];
    
    if (!$adminData) {
      $response['message'] = 'Session error. Please login again.';
      $response['next_token'] = CSRFProtection::generateToken('password_otp_request');
    } elseif (!password_verify($current_password, $adminData['password'])) {
      $response['message'] = 'Current password is incorrect.';
      $response['next_token'] = CSRFProtection::generateToken('password_otp_request');
    } elseif ($new_password !== $confirm_password) {
      $response['message'] = 'New passwords do not match.';
      $response['next_token'] = CSRFProtection::generateToken('password_otp_request');
    } elseif (strlen($new_password) < 12) {
      $response['message'] = 'New password must be at least 12 characters.';
      $response['next_token'] = CSRFProtection::generateToken('password_otp_request');
    } elseif (!preg_match('/[A-Z]/', $new_password) || !preg_match('/[a-z]/', $new_password) || 
              !preg_match('/[0-9]/', $new_password) || !preg_match('/[!@#$%^&*()_+\-=\[\]{};:\'"\\|,.<>\/?]/', $new_password)) {
      $response['message'] = 'Password must contain uppercase, lowercase, numbers, and special characters.';
      $response['next_token'] = CSRFProtection::generateToken('password_otp_request');
    } else {
      $_SESSION['temp_new_password'] = password_hash($new_password, PASSWORD_DEFAULT);
      $_SESSION['temp_admin_id'] = $admin_id;
      
      if ($otpService->sendOTP($adminData['email'], 'password_change', $admin_id)) {
        $response = [
          'status' => 'success', 
          'message' => 'Verification code sent to your email address.',
          'next_token' => CSRFProtection::generateToken('password_otp_verify')
        ];
      } else {
        $response['message'] = 'Failed to send verification code. Please try again.';
        $response['next_token'] = CSRFProtection::generateToken('password_otp_request');
      }
    }
    
    if ($isAjaxRequest) {
      sendJsonResponse($response);
    }
  }
  
  // Handle password change OTP verification
  elseif (isset($_POST['password_otp_verify'])) {
    $token = $_POST['csrf_token'] ?? '';
    
    if (!CSRFProtection::validateToken('password_otp_verify', $token)) {
      $response = [
        'status' => 'error',
        'message' => 'Security verification failed. Please refresh and try again.',
        'next_token' => CSRFProtection::generateToken('password_otp_verify')
      ];
      
      if ($isAjaxRequest) {
        sendJsonResponse($response);
      }
    }
    
    $otp = trim($_POST['otp']);
    $admin_id = $_SESSION['temp_admin_id'] ?? null;
    $new_password_hash = $_SESSION['temp_new_password'] ?? null;
    
    $response = ['status' => 'error', 'message' => 'Unknown error occurred'];
    
    if (!$admin_id || !$new_password_hash) {
      $response['message'] = 'Session expired. Please start the process again.';
      $response['next_token'] = CSRFProtection::generateToken('password_otp_verify');
    } elseif (empty($otp) || !preg_match('/^\d{6}$/', $otp)) {
      $response['message'] = 'Please enter a valid 6-digit verification code.';
      $response['next_token'] = CSRFProtection::generateToken('password_otp_verify');
    } elseif ($otpService->verifyOTP($admin_id, $otp, 'password_change')) {
      $result = pg_query_params($connection, "UPDATE admins SET password = $1 WHERE admin_id = $2", [$new_password_hash, $admin_id]);
      
      if ($result) {
        unset($_SESSION['temp_new_password'], $_SESSION['temp_admin_id']);
        
        $notification_msg = "Admin password updated";
        pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
        
        if ($isAjaxRequest) {
          $response = ['status' => 'success', 'message' => 'Password updated successfully!', 'redirect' => 'admin_profile.php?success=1&msg=password'];
        } else {
          $_SESSION['success_message'] = 'Password updated successfully!';
          header('Location: admin_profile.php?success=1&msg=password');
          exit();
        }
      } else {
        $response['message'] = 'Failed to update password. Please try again.';
        $response['next_token'] = CSRFProtection::generateToken('password_otp_verify');
      }
    } else {
      $response['message'] = 'Invalid or expired verification code.';
      $response['next_token'] = CSRFProtection::generateToken('password_otp_verify');
    }
    
    if ($isAjaxRequest) {
      sendJsonResponse($response);
    }
  }
}

// Generate initial CSRF tokens for the page
$csrf_email_token = CSRFProtection::generateToken('email_otp_request');
$csrf_password_token = CSRFProtection::generateToken('password_otp_request');
?>

<?php $page_title = 'My Profile'; include __DIR__ . '/../../includes/admin/admin_head.php'; ?>
<style>
  /* Profile-specific styles */
  .profile-header {
    background: linear-gradient(145deg, #f5f7fa 0%, #eef1f4 100%);
    border: 1px solid #e3e7ec;
    border-radius: 16px;
    color: #2f3a49;
    padding: 2.5rem 1.75rem 2rem 1.75rem;
    margin-bottom: 2rem;
    position: relative;
    overflow: hidden;
    text-align: center;
  }

  .profile-header::before,
  .profile-header::after {
    content: '';
    position: absolute;
    border-radius: 50%;
    background: radial-gradient(circle at 30% 30%, rgba(0,0,0,0.04), transparent 70%);
    opacity: 0.6;
    pointer-events: none;
  }
  .profile-header::before { width: 220px; height: 220px; top: -60px; right: -40px; }
  .profile-header::after { width: 160px; height: 160px; bottom: -50px; left: -30px; }

  .profile-avatar {
    width: 160px;
    height: 160px;
    background: linear-gradient(145deg,#667eea,#5a67d8);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 3.25rem;
    margin: 0 auto 1.25rem auto;
    border: 1px solid #dcdfe3;
    box-shadow: 0 4px 10px rgba(0,0,0,0.05), 0 1px 3px rgba(0,0,0,0.06) inset;
    position: relative;
    z-index: 2;
    transition: box-shadow .25s ease, transform .25s ease;
    color: white;
    font-weight: 600;
  }
  .profile-avatar:hover {
    box-shadow: 0 6px 14px rgba(0,0,0,0.08), 0 0 0 4px rgba(0,0,0,0.03) inset;
    transform: translateY(-2px);
  }
  
  .profile-info { position: relative; z-index: 2; }
  .profile-info h2 {
    margin: 0 0 .35rem 0;
    font-weight: 600;
    font-size: 1.95rem;
    letter-spacing: -.5px;
    color: #303a44;
  }
  .profile-info p {
    margin: 0;
    font-size: .95rem;
    color: #5c6773;
    font-weight: 500;
  }
  
  .info-card {
    background: linear-gradient(180deg,#ffffff 0%,#fafbfc 100%);
    border-radius: 14px;
    border: 1px solid #e2e6ea;
    margin-bottom: 1.5rem;
    overflow: hidden;
    transition: border-color .25s ease, box-shadow .25s ease;
    box-shadow: 0 2px 4px rgba(0,0,0,0.03);
  }
  .info-card:hover { border-color:#d5dae0; box-shadow:0 4px 14px rgba(0,0,0,0.06); }
  .info-card-header {
    background:#f5f7f9;
    padding:1.1rem 1.35rem;
    border-bottom:1px solid #e3e7eb;
    display:flex; align-items:center; gap:.75rem;
  }
  .info-card-header h5 { margin:0; color:#313b44; font-weight:600; font-size:1.02rem; letter-spacing:.25px; }
  .info-card-header .bi { color:#7c8792; font-size:1.15rem; }
  .info-card-body { padding:1.35rem 1.4rem 1.25rem 1.4rem; }
  .info-item { display:flex; align-items:flex-start; justify-content:space-between; padding:.65rem 0; border-top:1px dashed #e4e8ec; gap:1rem; }
  .info-item:first-of-type { border-top:none; }
  .info-item:last-child { padding-bottom:.2rem; }
  .info-label { font-weight:600; color:#46515c; font-size:.78rem; text-transform:uppercase; letter-spacing:.5px; min-width:140px; }
  .info-value { flex:1; color:#303a44; margin:0 .75rem; font-weight:500; }
  .info-actions { display:flex; gap:.5rem; align-items:center; }
  .settings-icon-btn { background:#ffffff; border:1px solid #d5dadf; width:40px; height:40px; display:flex; align-items:center; justify-content:center; border-radius:50%; transition:background .2s ease, border-color .2s ease, box-shadow .2s ease; color:#5e6974; }
  .settings-icon-btn:hover { background:#f3f5f7; border-color:#c5cbd1; box-shadow:0 2px 6px rgba(0,0,0,0.05); }
  
  .btn-edit {
    background: #667eea;
    border-color: #667eea;
    color: white;
    padding: 0.375rem 1rem;
    border-radius: 6px;
    font-size: 0.875rem;
    font-weight: 500;
    transition: all 0.2s ease;
  }
  
  .btn-edit:hover {
    background: #5a67d8;
    border-color: #5a67d8;
    color: white;
    transform: translateY(-1px);
  }
  
  .btn-change-pwd {
    background: #ed8936;
    border-color: #ed8936;
    color: white;
    padding: 0.375rem 1rem;
    border-radius: 6px;
    font-size: 0.875rem;
    font-weight: 500;
    transition: all 0.2s ease;
  }
  
  .btn-change-pwd:hover {
    background: #dd7724;
    border-color: #dd7724;
    color: white;
    transform: translateY(-1px);
  }
  
  /* Modal Improvements */
  .modal-content {
    border-radius: 12px;
    border: none;
    box-shadow: 0 10px 40px rgba(0,0,0,0.15);
  }
  
  .modal-header {
    background: #f8f9fa;
    border-bottom: 1px solid #e9ecef;
    border-radius: 12px 12px 0 0;
    padding: 1.5rem;
  }
  
  .modal-title {
    font-weight: 600;
    color: #495057;
  }
  
  .modal-body {
    padding: 1.5rem;
  }
  
  .modal-footer {
    border-top: 1px solid #e9ecef;
    padding: 1.5rem;
    background: #f8f9fa;
    border-radius: 0 0 12px 12px;
  }
  
  /* Modal responsive sizing */
  /* Tablet optimization (768px-991px) */
  @media (min-width: 768px) and (max-width: 991.98px) {
    #changeEmailModal .modal-dialog,
    #changePasswordModal .modal-dialog {
      max-width: 600px !important;
      margin: 2rem auto !important;
    }
    
    #changeEmailModal .modal-content,
    #changePasswordModal .modal-content {
      border-radius: 1rem;
    }
    
    #changeEmailModal .modal-header,
    #changePasswordModal .modal-header {
      padding: 1.25rem;
    }
    
    #changeEmailModal .modal-body,
    #changePasswordModal .modal-body {
      padding: 1rem 1.25rem;
    }
    
    #changeEmailModal .modal-footer,
    #changePasswordModal .modal-footer {
      padding: 1rem 1.25rem;
    }
    
    #changeEmailModal .modal-title,
    #changePasswordModal .modal-title {
      font-size: 1.1rem;
    }
    
    #changeEmailModal .form-control,
    #changePasswordModal .form-control {
      font-size: 0.95rem;
    }
    
    #changeEmailModal .btn,
    #changePasswordModal .btn {
      font-size: 0.9rem;
      padding: 0.65rem 1.25rem;
    }
  }
  
  @media (max-width: 767px) {
    /* Apply compact sizing similar to manage_applicants */
    #changeEmailModal .modal-dialog,
    #changePasswordModal .modal-dialog {
      max-width: 90% !important;
      margin: 1.5rem auto !important;
    }
    
    #changeEmailModal .modal-content,
    #changePasswordModal .modal-content {
      height: auto !important;
      max-height: 85vh !important;
      border-radius: 1rem !important;
      display: flex;
      flex-direction: column;
    }
    
    #changeEmailModal .modal-header,
    #changePasswordModal .modal-header {
      padding: 0.75rem 1rem;
      position: sticky;
      top: 0;
      background: #f8f9fa;
      z-index: 1;
      border-radius: 1rem 1rem 0 0;
      border-bottom: 1px solid #e0e0e0;
    }
    
    #changeEmailModal .modal-body,
    #changePasswordModal .modal-body {
      padding: 0.75rem 1rem;
      overflow-y: auto;
      max-height: calc(85vh - 140px);
      flex: 1;
    }
    
    #changeEmailModal .modal-footer,
    #changePasswordModal .modal-footer {
      padding: 0.75rem 1rem;
      position: sticky;
      bottom: 0;
      background: #fff;
      z-index: 1;
      border-top: 1px solid #e0e0e0;
      flex-shrink: 0;
    }
    
    #changeEmailModal .modal-title,
    #changePasswordModal .modal-title {
      font-size: 1rem;
      line-height: 1.3;
    }
    
    #changeEmailModal .modal-title i,
    #changePasswordModal .modal-title i {
      font-size: 0.9rem;
    }
    
    #changeEmailModal .form-label,
    #changePasswordModal .form-label {
      font-size: 0.85rem;
      margin-bottom: 0.4rem;
      font-weight: 600;
    }
    
    #changeEmailModal .form-control,
    #changePasswordModal .form-control {
      font-size: 0.9rem;
      padding: 0.6rem 0.75rem;
    }
    
    #changeEmailModal .btn,
    #changePasswordModal .btn {
      font-size: 0.875rem;
      padding: 0.6rem 1rem;
    }
    
    #changeEmailModal .small,
    #changePasswordModal .small,
    #changeEmailModal small,
    #changePasswordModal small {
      font-size: 0.75rem;
    }
    
    #changeEmailModal .alert,
    #changePasswordModal .alert {
      padding: 0.75rem;
      font-size: 0.85rem;
      margin-bottom: 0.75rem;
    }
    
    #changeEmailModal .input-group-text,
    #changePasswordModal .input-group-text {
      font-size: 0.85rem;
      padding: 0.6rem 0.75rem;
    }
    
    /* OTP input special styling */
    #changeEmailModal #emailOTP,
    #changePasswordModal #passwordOTP {
      font-size: 1.2rem !important;
      letter-spacing: 4px;
      padding: 0.75rem !important;
    }
  }
  
  /* Form Controls */
  .form-control {
    border-radius: 8px;
    border: 1px solid #d1d5db;
    padding: 0.75rem 1rem;
    font-size: 0.95rem;
    transition: all 0.2s ease;
  }
  
  .form-control:focus {
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
  }
  
  .form-label {
    font-weight: 600;
    color: #374151;
    margin-bottom: 0.5rem;
  }
  
  /* Flash Messages */
  .alert {
    border-radius: 10px;
    padding: 1rem 1.25rem;
    margin-bottom: 1.5rem;
    border: none;
  }
  
  .alert-success {
    background: #d1fae5;
    color: #065f46;
  }
  
  .alert-danger {
    background: #fee2e2;
    color: #991b1b;
  }
  
  /* Responsive */
  @media (max-width: 768px) {
    .profile-header {
      padding: 1.5rem;
      text-align: center;
    }
    
    .info-card-header {
      padding: 1rem 1.15rem;
      flex-wrap: wrap;
    }
    
    .info-card-header h5 {
      font-size: 0.95rem;
    }
    
    .info-card-body {
      padding: 1rem 1.15rem;
    }
    
    .info-item {
      flex-direction: column;
      align-items: stretch;
      gap: 0.5rem;
      padding: 1rem 0;
      border-top: 1px solid #e4e8ec;
    }
    
    .info-item:first-of-type {
      border-top: none;
      padding-top: 0;
    }
    
    .info-label {
      font-size: 0.7rem;
      min-width: auto;
      margin-bottom: 0.25rem;
      letter-spacing: 0.3px;
    }
    
    .info-value {
      margin: 0;
      font-size: 0.95rem;
      margin-bottom: 0.5rem;
    }
    
    .info-actions {
      width: 100%;
      justify-content: flex-start;
      margin-top: 0.25rem;
    }
    
    .info-actions .text-muted {
      font-size: 0.75rem;
    }
    
    .btn-edit, .btn-change-pwd {
      width: 100%;
      justify-content: center;
      padding: 0.5rem 1rem;
    }
    
    .settings-icon-btn {
      width: 36px;
      height: 36px;
    }
    
    .settings-icon-btn .bi {
      font-size: 0.95rem;
    }
  }
</style>
<body>
<?php include __DIR__ . '/../../includes/admin/admin_topbar.php'; ?>
<div id="wrapper" class="admin-wrapper">
  <?php include __DIR__ . '/../../includes/admin/admin_sidebar.php'; ?>
  <?php include __DIR__ . '/../../includes/admin/admin_header.php'; ?>
  
  <section class="home-section" id="page-content-wrapper">
    <div class="container-fluid px-4">
      <!-- Flash Messages -->
      <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
          <i class="bi bi-check-circle me-2"></i>
          <?= htmlspecialchars($_SESSION['success_message']) ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['success_message']); ?>
      <?php endif; ?>

      <!-- Profile Header -->
      <div class="profile-header">
        <div class="profile-avatar position-relative">
          <?php
          $fullName = trim(($currentAdmin['first_name'] ?? '') . ' ' . ($currentAdmin['last_name'] ?? ''));
          $initials = strtoupper(mb_substr($currentAdmin['first_name'] ?? 'A', 0, 1) . mb_substr($currentAdmin['last_name'] ?? 'D', 0, 1));
          echo htmlspecialchars($initials);
          ?>
        </div>
        
        <div class="profile-info">
          <h2><?= htmlspecialchars($fullName ?: 'Administrator') ?></h2>
          <p><i class="bi bi-person-badge me-2"></i>Admin ID: <?= htmlspecialchars($currentAdmin['admin_id'] ?? 'N/A') ?> • @<?= htmlspecialchars($currentAdmin['username']) ?></p>
        </div>
      </div>

      <!-- Personal & Contact Information Card -->
      <div class="info-card">
        <div class="info-card-header d-flex justify-content-between align-items-center">
          <div>
            <i class="bi bi-person-lines-fill"></i>
            <h5 class="d-inline mb-0">Personal & Contact Information</h5>
          </div>
          <a href="settings.php" class="settings-icon-btn text-decoration-none" title="Settings">
            <i class="bi bi-gear" style="font-size:1.05rem;"></i>
          </a>
        </div>
        <div class="info-card-body">
          <!-- Personal Information Section -->
          <div class="mb-4">
            <h6 class="text-muted mb-3 fw-bold">
              <i class="bi bi-person-fill me-2"></i>Personal Information
            </h6>
            <div class="info-item">
              <div class="info-label">First Name</div>
              <div class="info-value"><?= htmlspecialchars($currentAdmin['first_name'] ?? 'Not set') ?></div>
              <div class="info-actions">
                <span class="text-muted small">Read-only</span>
              </div>
            </div>
            <div class="info-item">
              <div class="info-label">Middle Name</div>
              <div class="info-value"><?= htmlspecialchars($currentAdmin['middle_name'] ?? 'Not set') ?></div>
              <div class="info-actions">
                <span class="text-muted small">Read-only</span>
              </div>
            </div>
            <div class="info-item">
              <div class="info-label">Last Name</div>
              <div class="info-value"><?= htmlspecialchars($currentAdmin['last_name'] ?? 'Not set') ?></div>
              <div class="info-actions">
                <span class="text-muted small">Read-only</span>
              </div>
            </div>
            <div class="info-item">
              <div class="info-label">Username</div>
              <div class="info-value"><?= htmlspecialchars($currentAdmin['username']) ?></div>
              <div class="info-actions">
                <span class="text-muted small">Read-only</span>
              </div>
            </div>
          </div>
          
          <!-- Contact Information Section -->
          <div class="mb-0">
            <h6 class="text-muted mb-3 fw-bold">
              <i class="bi bi-envelope-fill me-2"></i>Contact Information
            </h6>
            <div class="info-item">
              <div class="info-label">Email Address</div>
              <div class="info-value"><?= htmlspecialchars($currentAdmin['email'] ?? 'Not set') ?></div>
              <div class="info-actions">
                <button type="button" class="btn btn-edit btn-sm" onclick="showChangeEmailModal()">
                  <i class="bi bi-pencil me-1"></i> Change
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Security Settings Card -->
      <div class="info-card">
        <div class="info-card-header">
          <i class="bi bi-shield-check"></i>
          <h5>Security Settings</h5>
        </div>
        <div class="info-card-body">
          <div class="info-item">
            <div class="info-label">Password</div>
            <div class="info-value">Change your account password to keep your account secure</div>
            <div class="info-actions">
              <button type="button" class="btn btn-change-pwd btn-sm" onclick="showChangePasswordModal()">
                <i class="bi bi-key me-1"></i> Change Password
              </button>
            </div>
          </div>
          <div class="info-item">
            <div class="info-label">Two-Factor Auth</div>
            <div class="info-value">All profile changes require OTP verification via email</div>
            <div class="info-actions">
              <span class="badge bg-success">
                <i class="bi bi-check-circle me-1"></i> Enabled
              </span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>
</div>

<!-- Email Change Modal -->
<div class="modal fade" id="changeEmailModal" tabindex="-1" aria-labelledby="changeEmailModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <!-- Step 1: Current Password & New Email -->
      <div id="emailStep1">
        <div class="modal-header">
          <h5 class="modal-title" id="changeEmailModalLabel">
            <i class="bi bi-envelope me-2"></i>Change Email Address
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form id="emailStep1Form" onsubmit="handleEmailStep1(event)">
          <div class="modal-body">
            <div id="emailStep1Error" class="alert alert-danger d-none"></div>
            
            <div class="mb-3">
              <label for="currentEmailPassword" class="form-label">Current Password <span class="text-danger">*</span></label>
              <div class="input-group">
                <input type="password" class="form-control" id="currentEmailPassword" name="current_password" required>
                <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('currentEmailPassword', this)">
                  <i class="bi bi-eye"></i>
                </button>
              </div>
              <small class="text-muted">Enter your current password to verify your identity</small>
            </div>
            
            <div class="mb-3">
              <label for="newEmail" class="form-label">New Email Address <span class="text-danger">*</span></label>
              <input type="email" class="form-control" id="newEmail" name="new_email" required>
              <div class="invalid-feedback">Please enter a valid email address.</div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">
              <i class="bi bi-send me-1"></i> Send Verification Code
            </button>
          </div>
        </form>
      </div>
      
      <!-- Step 2: OTP Verification -->
      <div id="emailStep2" class="d-none">
        <div class="modal-header">
          <h5 class="modal-title">
            <i class="bi bi-shield-check me-2"></i>Verify Email Change
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form id="emailStep2Form" onsubmit="handleEmailStep2(event)">
          <div class="modal-body">
            <div id="emailStep2Error" class="alert alert-danger d-none"></div>
            
            <div class="alert alert-info">
              <i class="bi bi-info-circle me-2"></i>
              We've sent a 6-digit verification code to your new email address. Please check your inbox and enter the code below.
            </div>
            
            <div class="mb-3">
              <label for="emailOTP" class="form-label">Verification Code <span class="text-danger">*</span></label>
              <input type="text" class="form-control text-center" id="emailOTP" name="otp" 
                     maxlength="6" pattern="[0-9]{6}" required placeholder="000000"
                     style="font-size: 18px; letter-spacing: 3px;">
              <small class="text-muted">Enter the 6-digit code sent to your email</small>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="backToEmailStep1()">Back</button>
            <button type="submit" class="btn btn-success">
              <i class="bi bi-check-circle me-1"></i> Update Email
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- Password Change Modal -->
<div class="modal fade" id="changePasswordModal" tabindex="-1" aria-labelledby="changePasswordModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <!-- Step 1: Current Password & New Password -->
      <div id="passwordStep1">
        <div class="modal-header">
          <h5 class="modal-title" id="changePasswordModalLabel">
            <i class="bi bi-key me-2"></i>Change Password
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form id="passwordStep1Form" onsubmit="handlePasswordStep1(event)">
          <div class="modal-body">
            <div id="passwordStep1Error" class="alert alert-danger d-none"></div>
            
            <div class="mb-3">
              <label for="currentPassword" class="form-label">Current Password <span class="text-danger">*</span></label>
              <div class="input-group">
                <input type="password" class="form-control" id="currentPassword" name="current_password" required>
                <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('currentPassword', this)">
                  <i class="bi bi-eye"></i>
                </button>
              </div>
            </div>
            
            <div class="mb-3">
              <label for="newPassword" class="form-label">New Password <span class="text-danger">*</span></label>
              <div class="input-group">
                <input type="password" class="form-control" id="newPassword" name="new_password" minlength="12" required>
                <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('newPassword', this)">
                  <i class="bi bi-eye"></i>
                </button>
              </div>
              <small class="text-muted">Must be at least 12 characters with uppercase, lowercase, numbers, and special characters</small>
            </div>
            
            <!-- Password Strength Indicator -->
            <div class="mb-3">
              <label class="form-label">Password Strength</label>
              <div class="progress" style="height: 25px;">
                <div 
                  id="strengthBar" 
                  class="progress-bar" 
                  role="progressbar" 
                  style="width: 0%; transition: width 0.3s ease, background-color 0.3s ease;" 
                  aria-valuenow="0" 
                  aria-valuemin="0" 
                  aria-valuemax="100"
                ></div>
              </div>
              <small id="strengthText" class="text-muted d-block mt-1"></small>
            </div>
            
            <div class="mb-3">
              <label for="confirmPassword" class="form-label">Confirm New Password <span class="text-danger">*</span></label>
              <div class="input-group">
                <input type="password" class="form-control" id="confirmPassword" name="confirm_password" minlength="12" required>
                <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('confirmPassword', this)">
                  <i class="bi bi-eye"></i>
                </button>
              </div>
              <small id="passwordMatchText" class="d-block mt-1"></small>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">
              <i class="bi bi-send me-1"></i> Send Verification Code
            </button>
          </div>
        </form>
      </div>
      
      <!-- Step 2: OTP Verification -->
      <div id="passwordStep2" class="d-none">
        <div class="modal-header">
          <h5 class="modal-title">
            <i class="bi bi-shield-check me-2"></i>Verify Password Change
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form id="passwordStep2Form" onsubmit="handlePasswordStep2(event)">
          <div class="modal-body">
            <div id="passwordStep2Error" class="alert alert-danger d-none"></div>
            
            <div class="alert alert-info">
              <i class="bi bi-info-circle me-2"></i>
              We've sent a 6-digit verification code to your email address. Please check your inbox and enter the code below.
            </div>
            
            <div class="mb-3">
              <label for="passwordOTP" class="form-label">Verification Code <span class="text-danger">*</span></label>
              <input type="text" class="form-control text-center" id="passwordOTP" name="otp" 
                     maxlength="6" pattern="[0-9]{6}" required placeholder="000000"
                     style="font-size: 18px; letter-spacing: 3px;">
              <small class="text-muted">Enter the 6-digit code sent to your email</small>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="backToPasswordStep1()">Back</button>
            <button type="submit" class="btn btn-success">
              <i class="bi bi-check-circle me-1"></i> Update Password
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// CSRF Token Management
let csrfTokens = {
  email: '<?php echo $csrf_email_token; ?>',
  password: '<?php echo $csrf_password_token; ?>'
};

function updateCSRFToken(type, newToken) {
  if (newToken) {
    csrfTokens[type] = newToken;
  }
}

// Email and Password Change Functions
function showChangeEmailModal() {
  document.getElementById('emailStep1').classList.remove('d-none');
  document.getElementById('emailStep2').classList.add('d-none');
  
  document.getElementById('emailStep1Form').reset();
  document.getElementById('emailStep2Form').reset();
  hideError('emailStep1Error');
  hideError('emailStep2Error');
  
  const modal = new bootstrap.Modal(document.getElementById('changeEmailModal'));
  modal.show();
}

function showChangePasswordModal() {
  document.getElementById('passwordStep1').classList.remove('d-none');
  document.getElementById('passwordStep2').classList.add('d-none');
  
  document.getElementById('passwordStep1Form').reset();
  document.getElementById('passwordStep2Form').reset();
  hideError('passwordStep1Error');
  hideError('passwordStep2Error');
  
  const modal = new bootstrap.Modal(document.getElementById('changePasswordModal'));
  modal.show();
}

function togglePasswordVisibility(inputId, button) {
  const input = document.getElementById(inputId);
  const icon = button.querySelector('i');
  
  if (input.type === 'password') {
    input.type = 'text';
    icon.className = 'bi bi-eye-slash';
  } else {
    input.type = 'password';
    icon.className = 'bi bi-eye';
  }
}

function showError(errorId, message) {
  const errorDiv = document.getElementById(errorId);
  errorDiv.textContent = message;
  errorDiv.classList.remove('d-none');
}

function hideError(errorId) {
  const errorDiv = document.getElementById(errorId);
  errorDiv.classList.add('d-none');
}

function handleEmailStep1(event) {
  event.preventDefault();
  
  const formData = new FormData(event.target);
  formData.append('email_otp_request', '1');
  formData.append('csrf_token', csrfTokens.email);
  
  const submitBtn = event.target.querySelector('button[type="submit"]');
  const originalText = submitBtn.innerHTML;
  
  submitBtn.disabled = true;
  submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i> Sending...';
  
  hideError('emailStep1Error');
  
  fetch(window.location.href, {
    method: 'POST',
    headers: {
      'X-Requested-With': 'XMLHttpRequest'
    },
    body: formData
  })
  .then(response => response.json())
  .then(data => {
    // Update CSRF token
    if (data.next_token) {
      updateCSRFToken('email', data.next_token);
    }
    
    if (data.status === 'success') {
      document.getElementById('emailStep1').classList.add('d-none');
      document.getElementById('emailStep2').classList.remove('d-none');
    } else {
      showError('emailStep1Error', data.message);
    }
  })
  .catch(error => {
    console.error('Error:', error);
    showError('emailStep1Error', 'Network error. Please try again.');
  })
  .finally(() => {
    submitBtn.disabled = false;
    submitBtn.innerHTML = originalText;
  });
}

function handleEmailStep2(event) {
  event.preventDefault();
  
  const formData = new FormData(event.target);
  formData.append('email_otp_verify', '1');
  formData.append('csrf_token', csrfTokens.email);
  
  const submitBtn = event.target.querySelector('button[type="submit"]');
  const originalText = submitBtn.innerHTML;
  
  submitBtn.disabled = true;
  submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i> Verifying...';
  
  hideError('emailStep2Error');
  
  fetch(window.location.href, {
    method: 'POST',
    headers: {
      'X-Requested-With': 'XMLHttpRequest'
    },
    body: formData
  })
  .then(response => response.json())
  .then(data => {
    // Update CSRF token
    if (data.next_token) {
      updateCSRFToken('email', data.next_token);
    }
    
    if (data.status === 'success') {
      if (data.redirect) {
        window.location.href = data.redirect;
      } else {
        const modal = bootstrap.Modal.getInstance(document.getElementById('changeEmailModal'));
        modal.hide();
        location.reload();
      }
    } else {
      showError('emailStep2Error', data.message);
    }
  })
  .catch(error => {
    console.error('Error:', error);
    showError('emailStep2Error', 'Network error. Please try again.');
  })
  .finally(() => {
    submitBtn.disabled = false;
    submitBtn.innerHTML = originalText;
  });
}

function handlePasswordStep1(event) {
  event.preventDefault();
  
  const newPassword = document.getElementById('newPassword').value;
  const confirmPassword = document.getElementById('confirmPassword').value;
  
  if (newPassword !== confirmPassword) {
    showError('passwordStep1Error', 'Password confirmation does not match.');
    return;
  }
  
  const formData = new FormData(event.target);
  formData.append('password_otp_request', '1');
  formData.append('csrf_token', csrfTokens.password);
  
  const submitBtn = event.target.querySelector('button[type="submit"]');
  const originalText = submitBtn.innerHTML;
  
  submitBtn.disabled = true;
  submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i> Sending...';
  
  hideError('passwordStep1Error');
  
  fetch(window.location.href, {
    method: 'POST',
    headers: {
      'X-Requested-With': 'XMLHttpRequest'
    },
    body: formData
  })
  .then(response => response.json())
  .then(data => {
    // Update CSRF token
    if (data.next_token) {
      updateCSRFToken('password', data.next_token);
    }
    
    if (data.status === 'success') {
      document.getElementById('passwordStep1').classList.add('d-none');
      document.getElementById('passwordStep2').classList.remove('d-none');
    } else {
      showError('passwordStep1Error', data.message);
    }
  })
  .catch(error => {
    console.error('Error:', error);
    showError('passwordStep1Error', 'Network error. Please try again.');
  })
  .finally(() => {
    submitBtn.disabled = false;
    submitBtn.innerHTML = originalText;
  });
}

function handlePasswordStep2(event) {
  event.preventDefault();
  
  const formData = new FormData(event.target);
  formData.append('password_otp_verify', '1');
  formData.append('csrf_token', csrfTokens.password);
  
  const submitBtn = event.target.querySelector('button[type="submit"]');
  const originalText = submitBtn.innerHTML;
  
  submitBtn.disabled = true;
  submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i> Verifying...';
  
  hideError('passwordStep2Error');
  
  fetch(window.location.href, {
    method: 'POST',
    headers: {
      'X-Requested-With': 'XMLHttpRequest'
    },
    body: formData
  })
  .then(response => response.json())
  .then(data => {
    // Update CSRF token
    if (data.next_token) {
      updateCSRFToken('password', data.next_token);
    }
    
    if (data.status === 'success') {
      if (data.redirect) {
        window.location.href = data.redirect;
      } else {
        const modal = bootstrap.Modal.getInstance(document.getElementById('changePasswordModal'));
        modal.hide();
        location.reload();
      }
    } else {
      showError('passwordStep2Error', data.message);
    }
  })
  .catch(error => {
    console.error('Error:', error);
    showError('passwordStep2Error', 'Network error. Please try again.');
  })
  .finally(() => {
    submitBtn.disabled = false;
    submitBtn.innerHTML = originalText;
  });
}

function backToEmailStep1() {
  document.getElementById('emailStep2').classList.add('d-none');
  document.getElementById('emailStep1').classList.remove('d-none');
  hideError('emailStep2Error');
}

function backToPasswordStep1() {
  document.getElementById('passwordStep2').classList.add('d-none');
  document.getElementById('passwordStep1').classList.remove('d-none');
  hideError('passwordStep2Error');
}

// Real-time password validation (handled by password_strength_validator.js)
document.addEventListener('DOMContentLoaded', function() {
  // Format OTP inputs to only accept numbers
  const otpInputs = ['emailOTP', 'passwordOTP'];
  otpInputs.forEach(inputId => {
    const input = document.getElementById(inputId);
    if (input) {
      input.addEventListener('input', function(e) {
        this.value = this.value.replace(/\D/g, '');
      });
    }
  });
  
  // Initialize password strength validator for change password modal
  // Note: We use custom IDs since this is in a modal with different field names
  if (document.getElementById('newPassword') && document.getElementById('strengthBar')) {
    setupPasswordValidation({
      passwordInputId: 'newPassword',
      confirmPasswordInputId: 'confirmPassword',
      strengthBarId: 'strengthBar',
      strengthTextId: 'strengthText',
      passwordMatchTextId: 'passwordMatchText',
      currentPasswordInputId: 'currentPassword', // Check for password reuse
      submitButtonSelector: '#passwordStep1Form button[type="submit"]',
      minStrength: 70,
      requireMatch: true
    });
    console.log('✅ Password strength validator initialized for change password modal');
  }
});
</script>
<script src="../../assets/js/shared/password_strength_validator.js"></script>
<script src="../../assets/js/admin/sidebar.js"></script>
</body>
</html>
