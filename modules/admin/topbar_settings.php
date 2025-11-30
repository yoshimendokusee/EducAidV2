<?php
// Start output buffering to prevent any accidental output before JSON response
ob_start();

// Load secure session configuration (must be before session_start)
require_once __DIR__ . '/../../config/session_config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Security checks for regular page load
if (!isset($_SESSION['admin_username'])) {
    header("Location: ../../unified_login.php");
    exit;
}

// Include dependencies
include_once __DIR__ . '/../../includes/permissions.php';
include_once __DIR__ . '/../../config/database.php';
include_once __DIR__ . '/../../services/ThemeSettingsService.php';
include_once __DIR__ . '/../../controllers/TopbarSettingsController.php';
include_once __DIR__ . '/../../includes/CSRFProtection.php';

// Check if super admin
$admin_role = getCurrentAdminRole($connection);
if ($admin_role !== 'super_admin') {
    header("Location: homepage.php");
    exit;
}

// Initialize services
$themeService = new ThemeSettingsService($connection);
$controller = new TopbarSettingsController($themeService, $_SESSION['admin_id'] ?? 0, $connection);

// Ensure fresh CSRF token on GET requests (clear old tokens)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Clear any existing tokens for this form and generate a fresh one
    if (isset($_SESSION['csrf_tokens']['topbar_settings'])) {
        unset($_SESSION['csrf_tokens']['topbar_settings']);
    }
    CSRFProtection::generateToken('topbar_settings');
}

// Unified form submission (topbar + header)
$form_result = [
  'success' => false,
  'message' => '',
  'data' => $themeService->getCurrentSettings()
];
$successMessage = '';
$errorMessage = '';
$combinedSuccess = false;
$isPostRequest = ($_SERVER['REQUEST_METHOD'] === 'POST');
$isAjaxRequest = $isPostRequest && (
  (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
  (isset($_POST['ajax']) && $_POST['ajax'] === '1') ||
  (isset($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
);

if ($isPostRequest) {
  $headerSave = [ 'success' => false, 'message' => '' ];
  // Validate CSRF token with consume = false to allow resubmissions
  $csrfValid = CSRFProtection::validateToken('topbar_settings', $_POST['csrf_token'] ?? '', false);
  
  if ($csrfValid) {
    $form_result = $controller->handleFormSubmission();
    $headerSave = $headerThemeService->save($_POST, (int)($_SESSION['admin_id'] ?? 0));

    $topbarMessage = $form_result['message'] ?? '';
    $headerMessage = $headerSave['message'] ?? '';
    if ($headerSave['success'] && $headerMessage === '') {
      $headerMessage = 'Header theme updated.';
    }
    if (!$headerSave['success'] && $headerMessage === '') {
      $headerMessage = 'Header theme save failed.';
    }

    $combinedSuccess = ($form_result['success'] && $headerSave['success']);

    $successParts = [];
    $errorParts = [];

    if ($form_result['success']) {
      $successParts[] = $topbarMessage !== '' ? $topbarMessage : 'Topbar settings updated.';
    } elseif ($topbarMessage !== '') {
      $errorParts[] = $topbarMessage;
    }

    if ($headerSave['success']) {
      $successParts[] = $headerMessage;
    } else {
      $errorParts[] = $headerMessage;
    }

    $successMessage = trim(implode(' ', array_filter($successParts)));
    $errorMessage = trim(implode(' ', array_filter($errorParts)));

    if ($combinedSuccess && isset($_SESSION['csrf_tokens']['topbar_settings'])) {
      unset($_SESSION['csrf_tokens']['topbar_settings']);
    }
  } else {
    $combinedSuccess = false;
    $form_result['success'] = false;
    $form_result['message'] = '';

    $debug_info = '';
    if (isset($_POST['csrf_token'])) {
      $submitted_token = substr($_POST['csrf_token'], 0, 16) . '...';
      $session_data = $_SESSION['csrf_tokens']['topbar_settings'] ?? null;
      if (is_array($session_data)) {
        $session_preview = array_map(function ($token) {
          return substr($token, 0, 16) . '...';
        }, $session_data);
        $session_token = 'MULTI [' . implode(', ', $session_preview) . ']';
      } elseif (is_string($session_data)) {
        $session_token = substr($session_data, 0, 16) . '...';
      } else {
        $session_token = 'NO TOKEN IN SESSION';
      }
      $debug_info = " (Submitted: $submitted_token, Session: $session_token)";
    } else {
      $debug_info = ' (No csrf_token in POST data)';
    }
    $successMessage = '';
    $errorMessage = 'Security token validation failed. Please try again.' . $debug_info;
  }

  if ($isAjaxRequest) {
    // Clear output buffer to prevent any stray output before JSON
    ob_end_clean();
    
    if (isset($_SESSION['csrf_tokens']['topbar_settings'])) {
      unset($_SESSION['csrf_tokens']['topbar_settings']);
    }
    $newCsrfToken = CSRFProtection::generateToken('topbar_settings');
    $latestTopbarSettings = $themeService->getCurrentSettings();
    $latestHeaderSettings = $headerThemeService->getCurrentSettings();

    header('Content-Type: application/json');
    echo json_encode([
      'success' => $combinedSuccess,
      'message' => $combinedSuccess ? ($successMessage !== '' ? $successMessage : 'Settings updated successfully.') : '',
      'error' => $combinedSuccess ? '' : ($errorMessage !== '' ? $errorMessage : 'Unable to save settings.'),
      'topbar_settings' => $latestTopbarSettings,
      'header_settings' => $latestHeaderSettings,
      'csrf_token' => $newCsrfToken,
    ]);
    exit;
  }
}

// Derive success/error strings (set above when POST)
$success = $successMessage ?? '';
$error = $errorMessage ?? '';

// Get current settings (updated if form was submitted successfully)
$current_settings = $form_result['success'] && isset($form_result['data']) 
  ? $form_result['data'] 
  : $themeService->getCurrentSettings();

$defaults = $themeService->getDefaultSettings();
$topbar_bg_color = $current_settings['topbar_bg_color'] ?? ($defaults['topbar_bg_color'] ?? '#2e7d32');
if (empty($topbar_bg_color)) {
  $topbar_bg_color = $defaults['topbar_bg_color'] ?? '#2e7d32';
}
$topbar_bg_gradient_raw = $current_settings['topbar_bg_gradient'] ?? null;
$gradient_enabled = !empty($topbar_bg_gradient_raw);
$topbar_bg_gradient = $gradient_enabled ? $topbar_bg_gradient_raw : '';
$preview_background = $gradient_enabled
  ? sprintf('linear-gradient(135deg, %s 0%%, %s 100%%)', $topbar_bg_color, $topbar_bg_gradient_raw)
  : $topbar_bg_color;
$gradient_color_input_value = $gradient_enabled
  ? $topbar_bg_gradient_raw
  : ($defaults['topbar_bg_gradient'] ?? '#1b5e20');
$gradient_text_display = $gradient_enabled ? $topbar_bg_gradient_raw : 'Solid color only';
$preview_text_color = $current_settings['topbar_text_color'] ?? ($defaults['topbar_text_color'] ?? '#ffffff');
if (empty($preview_text_color)) {
  $preview_text_color = $defaults['topbar_text_color'] ?? '#ffffff';
}
?>
<?php $page_title='Topbar Settings'; $extra_css=[]; include __DIR__ . '/../../includes/admin/admin_head.php'; ?>
<style>
  /* Modern Enhanced UI Styling */
  body.topbar-settings-page {
    background: linear-gradient(135deg, #f5f7fa 0%, #e8ecf1 100%);
  }
  
  body.topbar-settings-page .settings-card {
    background: #ffffff;
    border-radius: 12px;
    padding: 2rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 4px 6px rgba(0,0,0,0.07), 0 1px 3px rgba(0,0,0,0.06);
    border: 1px solid rgba(0,0,0,0.05);
    transition: all 0.3s ease;
  }
  
  body.topbar-settings-page .settings-card:hover {
    box-shadow: 0 8px 15px rgba(0,0,0,0.1), 0 3px 6px rgba(0,0,0,0.08);
    transform: translateY(-2px);
  }
  
  body.topbar-settings-page .settings-card h5 {
    color: #1e293b;
    font-weight: 700;
    font-size: 1.15rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding-bottom: 1rem;
    border-bottom: 2px solid #e2e8f0;
  }
  
  body.topbar-settings-page .settings-card h5 i {
    color: #2e7d32;
    font-size: 1.3rem;
  }
  
  body.topbar-settings-page .preview-topbar {
    color: #fff;
    padding: 1rem 1.5rem;
    border-radius: 10px;
    margin-bottom: 1.5rem;
    font-size: 0.875rem;
    font-family: 'Poppins', var(--bs-font-sans-serif, Arial, sans-serif);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    transition: all 0.3s ease;
    animation: fadeInDown 0.5s ease;
  }
  
  /* Sticky preview wrapper */
  body.topbar-settings-page .preview-wrapper {
    position: sticky;
    top: 120px;
    z-index: 100;
    margin-bottom: 1.5rem;
    transition: all 0.3s ease;
  }
  
  body.topbar-settings-page .preview-wrapper.stuck {
    box-shadow: 0 8px 24px rgba(0,0,0,0.12);
  }
  
  body.topbar-settings-page .preview-wrapper.stuck .settings-card {
    border-radius: 0 0 12px 12px;
    box-shadow: 0 6px 16px rgba(0,0,0,0.1);
    background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
  }
  
  body.topbar-settings-page .preview-wrapper.stuck .preview-topbar {
    box-shadow: 0 6px 20px rgba(0,0,0,0.2);
  }
  
  /* Add a subtle indicator when stuck */
  body.topbar-settings-page .preview-wrapper.stuck::before {
    content: '';
    position: absolute;
    top: -3px;
    left: 0;
    right: 0;
    height: 3px;
    background: linear-gradient(90deg, #2e7d32 0%, #66bb6a 50%, #2e7d32 100%);
    border-radius: 12px 12px 0 0;
    animation: shimmer 2s ease-in-out infinite;
  }
  
  @keyframes shimmer {
    0%, 100% { opacity: 0.6; }
    50% { opacity: 1; }
  }
  
  @keyframes fadeInDown {
    from {
      opacity: 0;
      transform: translateY(-10px);
    }
    to {
      opacity: 1;
      transform: translateY(0);
    }
  }
  
  body.topbar-settings-page .preview-topbar:hover {
    box-shadow: 0 6px 20px rgba(0,0,0,0.2);
  }
  
  body.topbar-settings-page .form-label {
    font-weight: 600;
    color: #334155;
    margin-bottom: 0.5rem;
    font-size: 0.95rem;
    display: flex;
    align-items: center;
    gap: 0.4rem;
  }
  
  body.topbar-settings-page .form-label i {
    color: #64748b;
    font-size: 1rem;
  }
  
  body.topbar-settings-page .form-control {
    border: 2px solid #e2e8f0;
    border-radius: 8px;
    padding: 0.65rem 1rem;
    font-size: 0.95rem;
    transition: all 0.2s ease;
  }
  
  body.topbar-settings-page .form-control:focus {
    border-color: #2e7d32;
    box-shadow: 0 0 0 0.25rem rgba(46, 125, 50, 0.15);
    background-color: #f8fffe;
  }
  
  body.topbar-settings-page .form-control:hover:not(:focus) {
    border-color: #cbd5e1;
  }
  
  body.topbar-settings-page .input-group {
    box-shadow: 0 2px 4px rgba(0,0,0,0.04);
    border-radius: 8px;
    overflow: hidden;
  }
  
  body.topbar-settings-page .input-group-text {
    background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
    border: 2px solid #e2e8f0;
    border-right: none;
    color: #475569;
    font-weight: 500;
  }
  
  body.topbar-settings-page .input-group .form-control {
    border-left: none;
  }
  
  body.topbar-settings-page .form-control-color {
    width: 70px;
    height: 45px;
    border-radius: 8px 0 0 8px;
    cursor: pointer;
    border: 2px solid #e2e8f0;
    transition: all 0.2s ease;
  }
  
  body.topbar-settings-page .form-control-color:hover {
    transform: scale(1.05);
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
  }
  
  body.topbar-settings-page .input-group.gradient-disabled {
    opacity: 0.5;
    pointer-events: none;
  }
  
  body.topbar-settings-page .input-group.gradient-disabled input[type="text"] {
    font-style: italic;
    color: #94a3b8;
    background: #f8fafc;
  }
  
  body.topbar-settings-page .form-text {
    color: #64748b;
    font-size: 0.85rem;
    margin-top: 0.4rem;
  }
  
  body.topbar-settings-page .form-check-input:checked {
    background-color: #2e7d32;
    border-color: #2e7d32;
  }
  
  body.topbar-settings-page .btn {
    border-radius: 8px;
    padding: 0.65rem 1.5rem;
    font-weight: 600;
    transition: all 0.3s ease;
    border: none;
  }
  
  body.topbar-settings-page .btn-success {
    background: linear-gradient(135deg, #2e7d32 0%, #1b5e20 100%);
    box-shadow: 0 4px 10px rgba(46, 125, 50, 0.3);
  }
  
  body.topbar-settings-page .btn-success:hover {
    background: linear-gradient(135deg, #1b5e20 0%, #2e7d32 100%);
    box-shadow: 0 6px 15px rgba(46, 125, 50, 0.4);
    transform: translateY(-2px);
  }
  
  body.topbar-settings-page .btn-secondary {
    background: #64748b;
    box-shadow: 0 3px 8px rgba(100, 116, 139, 0.25);
  }
  
  body.topbar-settings-page .btn-secondary:hover {
    background: #475569;
    box-shadow: 0 5px 12px rgba(100, 116, 139, 0.35);
    transform: translateY(-2px);
  }
  
  body.topbar-settings-page .btn-outline-secondary {
    border: 2px solid #cbd5e1;
    color: #475569;
    background: white;
  }
  
  body.topbar-settings-page .btn-outline-secondary:hover {
    background: #f8fafc;
    border-color: #94a3b8;
    color: #334155;
    transform: translateX(-3px);
  }
  
  body.topbar-settings-page .alert {
    border-radius: 10px;
    border: none;
    padding: 1rem 1.25rem;
    font-weight: 500;
    box-shadow: 0 4px 10px rgba(0,0,0,0.08);
    animation: slideInRight 0.4s ease;
  }
  
  @keyframes slideInRight {
    from {
      opacity: 0;
      transform: translateX(20px);
    }
    to {
      opacity: 1;
      transform: translateX(0);
    }
  }
  
  body.topbar-settings-page .alert-success {
    background: linear-gradient(135deg, #d1f4dd 0%, #a7f3d0 100%);
    color: #065f46;
  }
  
  body.topbar-settings-page .alert-danger {
    background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
    color: #991b1b;
  }
  
  body.topbar-settings-page .preview-header {
    transition: all 0.3s ease;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
  }
  
  body.topbar-settings-page h2 {
    color: #1e293b;
    font-weight: 700;
    font-size: 2rem;
  }
  
  body.topbar-settings-page .text-muted {
    color: #64748b !important;
  }
  
  body.topbar-settings-page .instruction-card {
    background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
    border-left: 4px solid #2e7d32;
    padding: 1.5rem;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
  }
  
  body.topbar-settings-page .instruction-card h6 {
    color: #1e293b;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 0.5rem;
  }
  
  body.topbar-settings-page .instruction-card ul li {
    padding: 0.4rem 0;
    color: #334155;
  }
  
  body.topbar-settings-page .instruction-card ul li strong {
    color: #1e293b;
  }
  
  body.topbar-settings-page .instruction-card .bi-check {
    color: #2e7d32;
    font-size: 1.1rem;
    font-weight: bold;
  }
  
  body.topbar-settings-page .vr {
    opacity: 0.3;
  }
  
  /* Smooth scroll behavior */
  html {
    scroll-behavior: smooth;
  }
  
  /* Color input styling improvements */
  body.topbar-settings-page .input-group .form-control[readonly] {
    background-color: #f8fafc;
    border: 2px solid #e2e8f0;
    border-left: none;
    font-family: 'Courier New', monospace;
    font-weight: 600;
    color: #334155;
  }
  
  /* Loading spinner animation */
  @keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
  }
  
  body.topbar-settings-page .spinner-border {
    animation: spin 0.75s linear infinite;
  }
  
  /* Form validation styling */
  body.topbar-settings-page .form-control.is-invalid {
    border-color: #dc2626;
    background-image: none;
  }
  
  body.topbar-settings-page .form-control.is-invalid:focus {
    border-color: #dc2626;
    box-shadow: 0 0 0 0.25rem rgba(220, 38, 38, 0.15);
  }
  
  body.topbar-settings-page .invalid-feedback {
    color: #dc2626;
    font-size: 0.85rem;
    font-weight: 500;
    margin-top: 0.4rem;
  }
  
  /* Responsive improvements */
  @media (max-width: 991.98px) {
    body.topbar-settings-page .settings-card {
      padding: 1.5rem;
    }
    
    body.topbar-settings-page h2 {
      font-size: 1.5rem;
    }
    
    body.topbar-settings-page .btn {
      padding: 0.5rem 1rem;
      font-size: 0.9rem;
    }
    
    /* Adjust sticky position for mobile */
    body.topbar-settings-page .preview-wrapper {
      top: 60px;
    }
  }
  
  @media (max-width: 767.98px) {
    body.topbar-settings-page .preview-wrapper {
      top: 50px;
    }
    
    body.topbar-settings-page .preview-topbar {
      font-size: 0.8rem;
      padding: 0.75rem 1rem;
    }
  }
  
  /* Print styles */
  @media print {
    body.topbar-settings-page .btn,
    body.topbar-settings-page .alert,
    body.topbar-settings-page .instruction-card {
      display: none;
    }
  }
</style>
<body class="topbar-settings-page">
  <?php include __DIR__ . '/../../includes/admin/admin_topbar.php'; ?>
  
  <div id="wrapper" class="admin-wrapper">
    <?php include __DIR__ . '/../../includes/admin/admin_sidebar.php'; ?>
    <?php include __DIR__ . '/../../includes/admin/admin_header.php'; ?>

    <section class="home-section" id="mainContent">
      <div class="container-fluid py-4 px-4" style="max-width: 1400px; margin: 0 auto;">
        
        <div class="d-flex justify-content-between align-items-center mb-4">
          <div>
            <h2 class="mb-2 d-flex align-items-center gap-2">
              <i class="bi bi-gear-fill" style="color: #2e7d32; font-size: 1.8rem;"></i>
              Topbar Settings
            </h2>
            <p class="text-muted mb-0" style="font-size: 1.05rem;">
              Customize the contact information displayed in the admin topbar
            </p>
          </div>
          <div class="d-flex align-items-center gap-3">
            <a href="homepage.php" class="btn btn-outline-secondary d-flex align-items-center gap-2">
              <i class="bi bi-arrow-left"></i>
              <span>Back to Dashboard</span>
            </a>
          </div>
        </div>
        
        <?php if ($success): ?>
          <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($success) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
          <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>
        
        <!-- Topbar Live Preview (Sticky) -->
        <div class="preview-wrapper" id="previewWrapper">
          <div class="settings-card mb-0">
            <h5 class="mb-3">
              <i class="bi bi-eye-fill"></i>
              Topbar Preview
              <span class="badge bg-success ms-2" style="font-size: 0.7rem; padding: 0.35rem 0.6rem;">Live</span>
            </h5>
            <div class="preview-topbar" id="preview-topbar" style="background: <?= htmlspecialchars($preview_background, ENT_QUOTES) ?>; color: <?= htmlspecialchars($preview_text_color, ENT_QUOTES) ?>;">
              <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap">
                <div class="d-flex align-items-center gap-3 small">
                  <i class="bi bi-shield-lock" id="preview-topbar-icon"></i>
                  <span>Administrative Panel</span>
                  <span class="vr mx-2 d-none d-md-inline"></span>
                  <i class="bi bi-envelope"></i>
                  <a href="#" class="text-decoration-none" id="preview-email" style="color: <?= htmlspecialchars($current_settings['topbar_link_color']) ?>;">
                    <?= htmlspecialchars($current_settings['topbar_email']) ?>
                  </a>
                  <span class="vr mx-2 d-none d-lg-inline"></span>
                  <i class="bi bi-telephone"></i>
                  <span class="d-none d-sm-inline" id="preview-phone">
                    <?= htmlspecialchars($current_settings['topbar_phone']) ?>
                  </span>
                </div>
                <div class="d-flex align-items-center gap-3 small">
                  <i class="bi bi-clock"></i>
                  <span class="d-none d-md-inline" id="preview-hours">
                    <?= htmlspecialchars($current_settings['topbar_office_hours']) ?>
                  </span>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- (Header preview now moved next to header color changers below) -->
        
        <!-- Unified Settings Form (Topbar + Header) -->
  <form method="POST" id="settingsForm" action="">
          <?= CSRFProtection::getTokenField('topbar_settings') ?>
          <div class="row">
            <div class="col-12">
              <div class="settings-card">
                <h5 class="mb-4">
                  <i class="bi bi-telephone-fill"></i>
                  Topbar Contact Information
                </h5>
                
                <div class="mb-3">
                  <label for="topbar_email" class="form-label">Email Address <span class="text-danger">*</span></label>
                  <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                    <input type="email" class="form-control" id="topbar_email" name="topbar_email" 
                           value="<?= htmlspecialchars($current_settings['topbar_email']) ?>" 
                           placeholder="educaid@generaltrias.gov.ph" required>
                  </div>
                  <div class="form-text">This email will be displayed in the admin topbar and used for contact purposes.</div>
                </div>
                
                <div class="mb-3">
                  <label for="topbar_phone" class="form-label">Phone Number <span class="text-danger">*</span></label>
                  <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-telephone"></i></span>
                    <input type="text" class="form-control" id="topbar_phone" name="topbar_phone" 
                           value="<?= htmlspecialchars($current_settings['topbar_phone']) ?>" 
                           placeholder="(046) 886-4454" required>
                  </div>
                  <div class="form-text">Phone number for administrative inquiries.</div>
                </div>
                
                <div class="mb-0">
                  <label for="topbar_office_hours" class="form-label">Office Hours <span class="text-danger">*</span></label>
                  <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-clock"></i></span>
                    <input type="text" class="form-control" id="topbar_office_hours" name="topbar_office_hours" 
                           value="<?= htmlspecialchars($current_settings['topbar_office_hours']) ?>" 
                           placeholder="Mon–Fri 8:00AM - 5:00PM" required>
                  </div>
                  <div class="form-text">Operating hours for administrative services.</div>
                </div>
              </div>
              
              <!-- Color Settings Section -->
              <div class="settings-card">
                <h5 class="mb-4">
                  <i class="bi bi-palette-fill"></i>
                  Topbar Color Settings
                </h5>
                
                <div class="row mb-3">
                  <div class="col-md-6 mb-3 mb-md-0">
                    <label for="topbar_bg_color" class="form-label">
                      <i class="bi bi-paint-bucket"></i> Background Color
                    </label>
                    <div class="input-group">
                      <input type="color" 
                             class="form-control form-control-color" 
                             id="topbar_bg_color" 
                             name="topbar_bg_color" 
                             value="<?= htmlspecialchars($current_settings['topbar_bg_color']) ?>"
                             title="Choose background color">
                      <input type="text" 
                             class="form-control" 
                             value="<?= htmlspecialchars($current_settings['topbar_bg_color']) ?>"
                             readonly>
                    </div>
                  </div>
                  <div class="col-md-6">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                      <label for="topbar_bg_gradient" class="form-label mb-0">
                        <i class="bi bi-circle-half"></i> Gradient Color
                      </label>
                      <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="topbar_gradient_enabled" name="topbar_gradient_enabled" value="1" <?= $gradient_enabled ? 'checked' : '' ?>>
                        <label class="form-check-label small" for="topbar_gradient_enabled">Enable</label>
                      </div>
                    </div>
                    <div class="input-group <?= $gradient_enabled ? '' : 'gradient-disabled' ?>" data-gradient-group>
                      <input type="color" 
                             class="form-control form-control-color" 
                             id="topbar_bg_gradient" 
                             name="topbar_bg_gradient" 
                             value="<?= htmlspecialchars($gradient_color_input_value) ?>"
                             data-default="<?= htmlspecialchars($defaults['topbar_bg_gradient'] ?? '#1b5e20') ?>"
                             title="Choose gradient color" <?= $gradient_enabled ? '' : 'disabled' ?>>
                      <input type="text" 
                             class="form-control" 
                             id="topbar_bg_gradient_text"
                             value="<?= htmlspecialchars($gradient_text_display) ?>"
                             readonly>
                    </div>
                    <div class="form-text">Toggle to add a gradient overlay effect</div>
                  </div>
                </div>
                
                <div class="row mb-0">
                  <div class="col-md-6 mb-3 mb-md-0">
                    <label for="topbar_text_color" class="form-label">
                      <i class="bi bi-fonts"></i> Text Color
                    </label>
                    <div class="input-group">
                      <input type="color" 
                             class="form-control form-control-color" 
                             id="topbar_text_color" 
                             name="topbar_text_color" 
                             value="<?= htmlspecialchars($current_settings['topbar_text_color']) ?>"
                             title="Choose text color">
                      <input type="text" 
                             class="form-control" 
                             value="<?= htmlspecialchars($current_settings['topbar_text_color']) ?>"
                             readonly>
                    </div>
                  </div>
                  <div class="col-md-6">
                    <label for="topbar_link_color" class="form-label">
                      <i class="bi bi-link-45deg"></i> Link Color
                    </label>
                    <div class="input-group">
                      <input type="color" 
                             class="form-control form-control-color" 
                             id="topbar_link_color" 
                             name="topbar_link_color" 
                             value="<?= htmlspecialchars($current_settings['topbar_link_color']) ?>"
                             title="Choose link color">
                      <input type="text" 
                             class="form-control" 
                             value="<?= htmlspecialchars($current_settings['topbar_link_color']) ?>"
                             readonly>
                    </div>
                  </div>
                </div>
              </div>

              <div class="d-flex justify-content-end gap-3 mt-4">
                <a href="homepage.php" class="btn btn-secondary d-flex align-items-center gap-2">
                  <i class="bi bi-x-circle"></i>
                  <span>Cancel</span>
                </a>
                <button type="submit" class="btn btn-success d-flex align-items-center gap-2" id="topbarSettingsSubmit">
                  <i class="bi bi-check-circle"></i>
                  <span>Save Changes</span>
                </button>
              </div>
            </div>
          </div>
        </form>
        
      </div>
    </section>
  </div>
  
  
  <!-- Use unified Bootstrap version (5.3.0) to match admin_head include -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script src="../../assets/js/admin/topbar-settings.js"></script>
  
  <script>
    // Sticky preview behavior enhancement
    document.addEventListener('DOMContentLoaded', function() {
      const previewWrapper = document.getElementById('previewWrapper');
      
      if (previewWrapper) {
        // Create an Intersection Observer to detect when preview becomes sticky
        const observer = new IntersectionObserver(
          ([entry]) => {
            // When the preview is not intersecting (scrolled past), add stuck class
            if (!entry.isIntersecting) {
              previewWrapper.classList.add('stuck');
            } else {
              previewWrapper.classList.remove('stuck');
            }
          },
          {
            root: null,
            threshold: 0,
            rootMargin: '-80px 0px 0px 0px' // Account for the sticky top position
          }
        );
        
        // Create a sentinel element to observe
        const sentinel = document.createElement('div');
        sentinel.style.height = '1px';
        sentinel.style.position = 'absolute';
        sentinel.style.top = '0';
        sentinel.style.width = '100%';
        sentinel.style.pointerEvents = 'none';
        
        // Insert sentinel before the preview wrapper
        previewWrapper.parentNode.insertBefore(sentinel, previewWrapper);
        observer.observe(sentinel);
      }
    });
  </script>
</body>
</html>