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
include_once __DIR__ . '/../../services/HeaderThemeService.php';
include_once __DIR__ . '/../../includes/CSRFProtection.php';

// Check if super admin
$admin_role = getCurrentAdminRole($connection);
if ($admin_role !== 'super_admin') {
    header("Location: homepage.php");
    exit;
}

// Initialize service
$headerThemeService = new HeaderThemeService($connection);

// Ensure fresh CSRF token on GET requests (clear old tokens)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Clear any existing tokens for this form and generate a fresh one
    if (isset($_SESSION['csrf_tokens']['header_appearance'])) {
        unset($_SESSION['csrf_tokens']['header_appearance']);
    }
    CSRFProtection::generateToken('header_appearance');
}

// Form submission handling
$form_result = [
  'success' => false,
  'message' => '',
  'data' => $headerThemeService->getCurrentSettings()
];
$successMessage = '';
$errorMessage = '';
$isPostRequest = ($_SERVER['REQUEST_METHOD'] === 'POST');
$isAjaxRequest = $isPostRequest && (
  (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
  (isset($_POST['ajax']) && $_POST['ajax'] === '1') ||
  (isset($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
);

if ($isPostRequest) {
  // Validate CSRF token with consume = false to allow resubmissions
  $csrfValid = CSRFProtection::validateToken('header_appearance', $_POST['csrf_token'] ?? '', false);
  
  if ($csrfValid) {
    $form_result = $headerThemeService->save($_POST, (int)($_SESSION['admin_id'] ?? 0));

    if ($form_result['success']) {
      $successMessage = 'Header appearance settings updated successfully!';
      // Consume token on success
      if (isset($_SESSION['csrf_tokens']['header_appearance'])) {
        unset($_SESSION['csrf_tokens']['header_appearance']);
      }
    } else {
      $errorMessage = $form_result['errors']['database'] ?? 'Failed to update settings.';
    }
  } else {
    $form_result['success'] = false;
    $errorMessage = 'Security token validation failed. Please try again.';
  }

  if ($isAjaxRequest) {
    // Clear output buffer to prevent any stray output before JSON
    ob_end_clean();
    
    if (isset($_SESSION['csrf_tokens']['header_appearance'])) {
      unset($_SESSION['csrf_tokens']['header_appearance']);
    }
    $newCsrfToken = CSRFProtection::generateToken('header_appearance');
    $latestHeaderSettings = $headerThemeService->getCurrentSettings();

    header('Content-Type: application/json');
    echo json_encode([
      'success' => $form_result['success'],
      'message' => $form_result['success'] ? $successMessage : $errorMessage,
      'data' => $latestHeaderSettings,
      'csrf_token' => $newCsrfToken,
    ]);
    exit;
  }
}

// Derive success/error strings (set above when POST)
$success = $successMessage ?? '';
$error = $errorMessage ?? '';

// Get current settings (updated if form was submitted successfully)
$header_settings = $form_result['success'] && isset($form_result['data']) 
  ? $form_result['data'] 
  : $headerThemeService->getCurrentSettings();
?>
<?php $page_title='Header Appearance'; $extra_css=[]; include __DIR__ . '/../../includes/admin/admin_head.php'; ?>
<style>
  /* Modern Enhanced UI Styling */
  body.header-appearance-page {
    background: linear-gradient(135deg, #f5f7fa 0%, #e8ecf1 100%);
  }
  
  body.header-appearance-page .settings-card {
    background: #ffffff;
    border-radius: 12px;
    padding: 2rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 4px 6px rgba(0,0,0,0.07), 0 1px 3px rgba(0,0,0,0.06);
    border: 1px solid rgba(0,0,0,0.05);
    transition: all 0.3s ease;
  }
  
  body.header-appearance-page .settings-card:hover {
    box-shadow: 0 8px 15px rgba(0,0,0,0.1), 0 3px 6px rgba(0,0,0,0.08);
    transform: translateY(-2px);
  }
  
  body.header-appearance-page .settings-card h5 {
    color: #1e293b;
    font-weight: 700;
    font-size: 1.15rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 1.5rem;
  }
  
  body.header-appearance-page .settings-card h5 i {
    color: #2e7d32;
  }
  
  body.header-appearance-page .preview-header {
    min-height: 80px;
    transition: all 0.2s ease;
  }
  
  /* Sticky preview wrapper */
  body.header-appearance-page .preview-wrapper {
    position: sticky;
    top: 80px;
    z-index: 10;
  }
  
  body.header-appearance-page .form-label {
    font-weight: 600;
    color: #475569;
    font-size: 0.9rem;
    margin-bottom: 0.5rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
  }
  
  body.header-appearance-page .form-label i {
    color: #2e7d32;
  }
  
  body.header-appearance-page .form-control {
    border-radius: 8px;
    border: 1px solid #e2e8f0;
    padding: 0.625rem 0.875rem;
    transition: all 0.2s ease;
  }
  
  body.header-appearance-page .form-control:focus {
    border-color: #2e7d32;
    box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.1);
  }
  
  body.header-appearance-page .input-group {
    border-radius: 8px;
    overflow: hidden;
  }
  
  body.header-appearance-page .input-group-text {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    color: #64748b;
  }
  
  body.header-appearance-page .form-control-color {
    width: 60px;
    height: 45px;
    border-radius: 8px;
    border: 2px solid #e2e8f0;
    cursor: pointer;
  }
  
  body.header-appearance-page .form-control-color:hover {
    border-color: #2e7d32;
    transform: scale(1.05);
  }
  
  body.header-appearance-page .btn {
    border-radius: 8px;
    padding: 0.625rem 1.25rem;
    font-weight: 600;
    transition: all 0.2s ease;
  }
  
  body.header-appearance-page .btn-success {
    background: linear-gradient(135deg, #2e7d32 0%, #1b5e20 100%);
    border: none;
  }
  
  body.header-appearance-page .btn-success:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(46, 125, 50, 0.3);
  }
  
  body.header-appearance-page .btn-secondary {
    background: #64748b;
    border: none;
  }
  
  body.header-appearance-page .btn-secondary:hover {
    background: #475569;
    transform: translateY(-2px);
  }
  
  body.header-appearance-page .alert {
    border-radius: 12px;
    border: none;
    padding: 1rem 1.25rem;
    margin-bottom: 1.5rem;
    animation: slideInRight 0.3s ease;
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
  
  body.header-appearance-page .alert-success {
    background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
    color: #155724;
  }
  
  body.header-appearance-page .alert-danger {
    background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
    color: #721c24;
  }
  
  body.header-appearance-page h2 {
    font-weight: 700;
    color: #1e293b;
  }
  
  body.header-appearance-page .text-muted {
    color: #64748b !important;
  }
  
  body.header-appearance-page .instruction-card {
    background: linear-gradient(135deg, #e0f2f1 0%, #b2dfdb 100%);
    border-left: 4px solid #2e7d32;
  }
  
  body.header-appearance-page .instruction-card h6 {
    color: #1b5e20;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 0.5rem;
  }
  
  /* Responsive improvements */
  @media (max-width: 991.98px) {
    body.header-appearance-page .settings-card {
      padding: 1.5rem;
    }
    
    body.header-appearance-page .preview-wrapper {
      position: static;
    }
  }
  
  @media (max-width: 767.98px) {
    body.header-appearance-page .settings-card {
      padding: 1rem;
    }
  }
</style>
<body class="header-appearance-page">
  <?php include __DIR__ . '/../../includes/admin/admin_topbar.php'; ?>
  
  <div id="wrapper" class="admin-wrapper">
    <?php include __DIR__ . '/../../includes/admin/admin_sidebar.php'; ?>
    <?php include __DIR__ . '/../../includes/admin/admin_header.php'; ?>
    
    <section class="home-section" id="mainContent">
      <div class="container-fluid px-4 py-4">
        
        <div class="mb-4">
          <div>
            <h1 class="fw-bold mb-1">Header Appearance</h1>
            <p class="text-muted mb-0">Customize the look and feel of the admin header area</p>
          </div>
        </div>
        
        <?php if (!empty($success)): ?>
          <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i>
            <?= htmlspecialchars($success) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>
        <?php endif; ?>
        
        <?php if (!empty($error)): ?>
          <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <?= htmlspecialchars($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>
        <?php endif; ?>
        
        <form method="POST" id="headerAppearanceForm" action="">
          <?= CSRFProtection::getTokenField('header_appearance') ?>
          <div class="row">
            <div class="col-lg-8">
              <!-- Header Color Settings (with inline preview) -->
              <div class="settings-card">
                <h5>
                  <i class="bi bi-layout-three-columns"></i>
                  Header Appearance
                </h5>
                <div class="row mb-4 g-3 align-items-stretch">
                  <div class="col-lg-7">
                    <!-- Header color inputs -->
                    <div class="row mb-3">
                      <div class="col-md-6 mb-3 mb-md-0">
                        <label class="form-label"><i class="bi bi-paint-bucket"></i> Header Background</label>
                        <div class="input-group">
                          <input type="color" class="form-control form-control-color" name="header_bg_color" id="header_bg_color" value="<?= htmlspecialchars($header_settings['header_bg_color']) ?>">
                          <input type="text" class="form-control" value="<?= htmlspecialchars($header_settings['header_bg_color']) ?>" readonly>
                        </div>
                      </div>
                      <div class="col-md-6">
                        <label class="form-label"><i class="bi bi-border-style"></i> Header Border Color</label>
                        <div class="input-group">
                          <input type="color" class="form-control form-control-color" name="header_border_color" id="header_border_color" value="<?= htmlspecialchars($header_settings['header_border_color']) ?>">
                          <input type="text" class="form-control" value="<?= htmlspecialchars($header_settings['header_border_color']) ?>" readonly>
                        </div>
                      </div>
                    </div>
                    <div class="row mb-3">
                      <div class="col-md-6 mb-3 mb-md-0">
                        <label class="form-label"><i class="bi bi-fonts"></i> Header Text Color</label>
                        <div class="input-group">
                          <input type="color" class="form-control form-control-color" name="header_text_color" id="header_text_color" value="<?= htmlspecialchars($header_settings['header_text_color']) ?>">
                          <input type="text" class="form-control" value="<?= htmlspecialchars($header_settings['header_text_color']) ?>" readonly>
                        </div>
                      </div>
                      <div class="col-md-6">
                        <label class="form-label"><i class="bi bi-brightness-high"></i> Header Icon Color</label>
                        <div class="input-group">
                          <input type="color" class="form-control form-control-color" name="header_icon_color" id="header_icon_color" value="<?= htmlspecialchars($header_settings['header_icon_color']) ?>">
                          <input type="text" class="form-control" value="<?= htmlspecialchars($header_settings['header_icon_color']) ?>" readonly>
                        </div>
                      </div>
                    </div>
                    <div class="row mb-0">
                      <div class="col-md-6 mb-3 mb-md-0">
                        <label class="form-label"><i class="bi bi-mouse"></i> Header Hover Background</label>
                        <div class="input-group">
                          <input type="color" class="form-control form-control-color" name="header_hover_bg" id="header_hover_bg" value="<?= htmlspecialchars($header_settings['header_hover_bg']) ?>">
                          <input type="text" class="form-control" value="<?= htmlspecialchars($header_settings['header_hover_bg']) ?>" readonly>
                        </div>
                      </div>
                      <div class="col-md-6">
                        <label class="form-label"><i class="bi bi-cursor"></i> Header Hover Icon Color</label>
                        <div class="input-group">
                          <input type="color" class="form-control form-control-color" name="header_hover_icon_color" id="header_hover_icon_color" value="<?= htmlspecialchars($header_settings['header_hover_icon_color']) ?>">
                          <input type="text" class="form-control" value="<?= htmlspecialchars($header_settings['header_hover_icon_color']) ?>" readonly>
                        </div>
                      </div>
                    </div>
                  </div>
                  <div class="col-lg-5">
                    <div class="border rounded-3 p-3 h-100 d-flex flex-column" style="background: linear-gradient(135deg, #fafafa 0%, #f0f0f0 100%); border: 2px solid #e2e8f0 !important;">
                      <div class="fw-semibold small text-muted mb-3 d-flex align-items-center gap-2">
                        <i class="bi bi-eye-fill"></i>
                        Header Preview
                        <span class="badge bg-info" style="font-size: 0.65rem; padding: 0.25rem 0.5rem;">Live</span>
                      </div>
                      <div class="preview-header border rounded-2 p-3 flex-grow-1" id="preview-header" style="background: <?= htmlspecialchars($header_settings['header_bg_color']) ?>; border:2px solid <?= htmlspecialchars($header_settings['header_border_color']) ?> !important;">
                        <div class="d-flex align-items-center justify-content-between">
                          <div class="d-flex align-items-center gap-2">
                            <button type="button" class="btn btn-sm" id="preview-menu-btn" style="background: <?= htmlspecialchars($header_settings['header_hover_bg']) ?>; color: <?= htmlspecialchars($header_settings['header_icon_color']) ?>; border-radius: 6px;">
                              <i class="bi bi-list"></i>
                            </button>
                            <span class="fw-semibold" id="preview-header-title" style="color: <?= htmlspecialchars($header_settings['header_text_color']) ?>;">Header Area</span>
                          </div>
                          <div class="d-flex align-items-center gap-2">
                            <button type="button" class="btn btn-sm" style="background:#f8fbf8; color: <?= htmlspecialchars($header_settings['header_icon_color']) ?>; border-radius: 6px;"><i class="bi bi-bell"></i></button>
                            <button type="button" class="btn btn-sm" style="background:#f8fbf8; color: <?= htmlspecialchars($header_settings['header_icon_color']) ?>; border-radius: 6px;"><i class="bi bi-person-circle"></i></button>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <div class="d-flex justify-content-end gap-3 mt-4">
                <a href="homepage.php" class="btn btn-secondary d-flex align-items-center gap-2">
                  <i class="bi bi-x-circle"></i>
                  <span>Cancel</span>
                </a>
                <button type="submit" class="btn btn-success d-flex align-items-center gap-2" id="headerAppearanceSubmit">
                  <i class="bi bi-check-circle"></i>
                  <span>Save Changes</span>
                </button>
              </div>
            </div>
            
            <div class="col-lg-4">
              <div class="settings-card instruction-card">
                <h6 class="mb-3"><i class="bi bi-info-circle me-2"></i>Instructions</h6>
                <ul class="list-unstyled small">
                  <li class="mb-2">
                    <i class="bi bi-check me-2"></i>
                    Changes will be applied immediately after saving
                  </li>
                  <li class="mb-2">
                    <i class="bi bi-check me-2"></i>
                    Colors must be valid hex codes (e.g., #2e7d32)
                  </li>
                  <li class="mb-3">
                    <i class="bi bi-check me-2"></i>
                    Use the live preview to see how changes will look
                  </li>
                </ul>
              </div>
              
              <div class="settings-card" style="background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); border-left: 4px solid #f59e0b;">
                <h6 class="mb-3"><i class="bi bi-palette me-2"></i>Color Guide</h6>
                <ul class="list-unstyled small">
                  <li class="mb-2">
                    <strong>Background:</strong> Main header background color
                  </li>
                  <li class="mb-2">
                    <strong>Border:</strong> Border around the header area
                  </li>
                  <li class="mb-2">
                    <strong>Text:</strong> Color for header text and labels
                  </li>
                  <li class="mb-2">
                    <strong>Icon:</strong> Color for icons in the header
                  </li>
                  <li class="mb-2">
                    <strong>Hover BG:</strong> Background on hover/active state
                  </li>
                  <li class="mb-0">
                    <strong>Hover Icon:</strong> Icon color on hover state
                  </li>
                </ul>
              </div>
            </div>
          </div>
        </form>
        
      </div>
    </section>
  </div>
  
  <!-- Use unified Bootstrap version (5.3.0) to match admin_head include -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  
  <script>
    // Live preview update
    document.addEventListener('DOMContentLoaded', function() {
      const previewHeader = document.getElementById('preview-header');
      const previewMenuBtn = document.getElementById('preview-menu-btn');
      const previewTitle = document.getElementById('preview-header-title');
      
      // Update preview when color inputs change
      document.getElementById('header_bg_color').addEventListener('input', function(e) {
        previewHeader.style.background = e.target.value;
        this.nextElementSibling.value = e.target.value;
      });
      
      document.getElementById('header_border_color').addEventListener('input', function(e) {
        previewHeader.style.border = `2px solid ${e.target.value}`;
        this.nextElementSibling.value = e.target.value;
      });
      
      document.getElementById('header_text_color').addEventListener('input', function(e) {
        previewTitle.style.color = e.target.value;
        this.nextElementSibling.value = e.target.value;
      });
      
      document.getElementById('header_icon_color').addEventListener('input', function(e) {
        previewMenuBtn.style.color = e.target.value;
        document.querySelectorAll('#preview-header .btn-sm:not(#preview-menu-btn)').forEach(btn => {
          btn.style.color = e.target.value;
        });
        this.nextElementSibling.value = e.target.value;
      });
      
      document.getElementById('header_hover_bg').addEventListener('input', function(e) {
        previewMenuBtn.style.background = e.target.value;
        this.nextElementSibling.value = e.target.value;
      });
      
      document.getElementById('header_hover_icon_color').addEventListener('input', function(e) {
        this.nextElementSibling.value = e.target.value;
      });
    });
  </script>
</body>
</html>
