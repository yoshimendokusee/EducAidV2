<?php
// Start output buffering to prevent any accidental output before JSON response
ob_start();

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
include_once __DIR__ . '/../../services/FooterThemeService.php';
include_once __DIR__ . '/../../includes/CSRFProtection.php';

// Check if super admin
$admin_role = getCurrentAdminRole($connection);
if ($admin_role !== 'super_admin') {
    header("Location: homepage.php");
    exit;
}

// Initialize service
$footerService = new FooterThemeService($connection);

// Handle form submission
$successMessage = '';
$errorMessage = '';
$isPostRequest = ($_SERVER['REQUEST_METHOD'] === 'POST');
$isAjaxRequest = $isPostRequest && (
    (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
    (isset($_POST['ajax']) && $_POST['ajax'] === '1')
);

if ($isPostRequest) {
    // Validate CSRF token
    $csrfValid = CSRFProtection::validateToken('footer_settings', $_POST['csrf_token'] ?? '', false);
    
    if ($csrfValid) {
        $result = $footerService->save($_POST, $_SESSION['admin_id'] ?? 0);
        
        if ($isAjaxRequest) {
            // Clear output buffer before sending JSON
            ob_end_clean();
            
            // Generate new CSRF token
            $newCsrfToken = CSRFProtection::generateToken('footer_settings');
            $latestSettings = $footerService->getCurrentSettings();
            
            header('Content-Type: application/json');
            echo json_encode([
                'success' => $result['success'],
                'message' => $result['success'] ? ($result['message'] ?: 'Footer settings updated successfully.') : '',
                'error' => $result['success'] ? '' : ($result['message'] ?: 'Unable to save footer settings.'),
                'footer_settings' => $latestSettings,
                'csrf_token' => $newCsrfToken
            ]);
            exit;
        }
        
        if ($result['success']) {
            $successMessage = $result['message'];
        } else {
            $errorMessage = $result['message'];
        }
    } else {
        $errorMessage = 'Security token validation failed. Please try again.';
    }
}

// Get current footer settings
$current_settings = $footerService->getCurrentSettings();

// Get current footer settings
$current_settings = $footerService->getCurrentSettings();

// Page title and extra CSS
$page_title = 'Footer Settings';
$extra_css = [];
include __DIR__ . '/../../includes/admin/admin_head.php';
?>
<style>
  /* Footer Settings Page Styling */
  body.footer-settings-page {
    background: #f8fafc;
  }
  
  body.footer-settings-page .settings-card {
    background: #ffffff;
    border-radius: 0.75rem;
    padding: 2rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06), 0 1px 2px rgba(0,0,0,0.04);
    border: 1px solid #e5e7eb;
    transition: all 0.3s ease;
  }
  
  body.footer-settings-page .settings-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.08), 0 2px 4px rgba(0,0,0,0.06);
    transform: translateY(-2px);
  }
  
  body.footer-settings-page .form-label { 
    font-weight: 600; 
    color: #374151;
    font-size: 0.875rem;
    margin-bottom: 0.5rem;
    display: flex;
    align-items: center;
    gap: 0.375rem;
  }
  
  body.footer-settings-page .form-label i {
    color: #6b7280;
    font-size: 0.875rem;
  }
  
  body.footer-settings-page .form-control,
  body.footer-settings-page .form-select {
    border-radius: 0.5rem;
    border: 1.5px solid #d1d5db;
    padding: 0.625rem 0.875rem;
    transition: all 0.2s ease;
    font-size: 0.9375rem;
  }
  
  body.footer-settings-page .form-control:focus,
  body.footer-settings-page .form-select:focus { 
    border-color: #0051f8; 
    box-shadow: 0 0 0 3px rgba(0,81,248,0.1); 
    outline: none;
  }
  
  body.footer-settings-page .input-group-text {
    background-color: #f9fafb;
    border: 1.5px solid #d1d5db;
    border-right: none;
    color: #6b7280;
    font-size: 0.9375rem;
    border-radius: 0.5rem 0 0 0.5rem;
  }
  
  body.footer-settings-page .input-group .form-control {
    border-left: none;
    border-radius: 0 0.5rem 0.5rem 0;
  }
  
  body.footer-settings-page .form-text {
    font-size: 0.8125rem;
    color: #6b7280;
    margin-top: 0.375rem;
    display: flex;
    align-items: center;
    gap: 0.25rem;
  }
  
  body.footer-settings-page .preview-footer {
    border-radius: 0.75rem;
    padding: 2.5rem 2rem;
    box-shadow: 0 8px 16px rgba(0,0,0,0.1), 0 4px 8px rgba(0,0,0,0.06);
    border: 1px solid rgba(0,0,0,0.08);
    margin-bottom: 0;
    transition: all 0.3s ease;
  }
  
  body.footer-settings-page .preview-footer:hover {
    box-shadow: 0 12px 24px rgba(0,0,0,0.12), 0 6px 12px rgba(0,0,0,0.08);
  }
  
  body.footer-settings-page h5 {
    font-size: 1.25rem;
    font-weight: 700;
    color: #111827;
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
  }
  
  body.footer-settings-page h5 i {
    color: #0051f8;
    font-size: 1.125rem;
  }
  
  body.footer-settings-page h6 {
    font-size: 1rem;
    font-weight: 600;
    color: #374151;
    margin-bottom: 0.875rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
  }
  
  body.footer-settings-page .color-group-label {
    background: linear-gradient(135deg, #f9fafb 0%, #f3f4f6 100%);
    padding: 0.625rem 1rem;
    border-radius: 0.5rem;
    font-size: 0.75rem;
    font-weight: 700;
    color: #4b5563;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-bottom: 1rem;
    margin-top: 1.5rem;
    border: 1px solid #e5e7eb;
    display: flex;
    align-items: center;
    gap: 0.5rem;
  }
  
  body.footer-settings-page .color-group-label:first-child {
    margin-top: 0;
  }
  
  body.footer-settings-page .form-control-color {
    width: 56px;
    height: 42px;
    padding: 0.25rem;
    border: 2px solid #d1d5db;
    border-radius: 0.5rem;
    cursor: pointer;
    transition: all 0.2s ease;
  }
  
  body.footer-settings-page .form-control-color:hover {
    border-color: #0051f8;
    transform: scale(1.05);
  }
  
  body.footer-settings-page .input-group .form-control-color {
    border-radius: 0.5rem 0 0 0.5rem;
    border-right: 1px solid #d1d5db;
  }
  
  body.footer-settings-page .btn-primary {
    background: linear-gradient(135deg, #0051f8 0%, #003fc7 100%);
    border: none;
    padding: 0.875rem 2.5rem;
    font-weight: 600;
    font-size: 1rem;
    border-radius: 0.625rem;
    box-shadow: 0 4px 8px rgba(0,81,248,0.25);
    transition: all 0.3s ease;
    text-transform: none;
  }
  
  body.footer-settings-page .btn-primary:hover {
    background: linear-gradient(135deg, #003fc7 0%, #0051f8 100%);
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(0,81,248,0.35);
  }
  
  body.footer-settings-page .btn-primary:active {
    transform: translateY(0);
  }
  
  body.footer-settings-page .btn-outline-secondary {
    border: 2px solid #d1d5db;
    color: #6b7280;
    font-weight: 600;
    padding: 0.875rem 1.5rem;
    border-radius: 0.625rem;
    transition: all 0.2s ease;
  }
  
  body.footer-settings-page .btn-outline-secondary:hover {
    background: #f9fafb;
    border-color: #9ca3af;
    color: #374151;
    transform: translateY(-1px);
  }
  
  body.footer-settings-page .sticky-save {
    position: sticky;
    top: 80px; /* Accounts for topbar/header height */
    z-index: 100;
    max-height: calc(100vh - 100px);
    overflow-y: auto;
  }
  
  /* Custom scrollbar for sticky sidebar */
  body.footer-settings-page .sticky-save::-webkit-scrollbar {
    width: 6px;
  }
  
  body.footer-settings-page .sticky-save::-webkit-scrollbar-track {
    background: transparent;
  }
  
  body.footer-settings-page .sticky-save::-webkit-scrollbar-thumb {
    background: #d1d5db;
    border-radius: 3px;
  }
  
  body.footer-settings-page .sticky-save::-webkit-scrollbar-thumb:hover {
    background: #9ca3af;
  }
  
  body.footer-settings-page .page-header {
    background: linear-gradient(135deg, #ffffff 0%, #f9fafb 100%);
    padding: 2rem;
    border-radius: 0.75rem;
    margin-bottom: 1.5rem;
    border: 1px solid #e5e7eb;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
  }
  
  body.footer-settings-page .page-header h2 {
    color: #111827;
    font-weight: 700;
    font-size: 1.75rem;
    margin-bottom: 0.5rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
  }
  
  body.footer-settings-page .page-header h2 i {
    color: #0051f8;
  }
  
  body.footer-settings-page .page-header p {
    color: #6b7280;
    margin-bottom: 0;
    font-size: 0.9375rem;
    line-height: 1.6;
  }
  
  /* Alert styling improvements */
  body.footer-settings-page .alert {
    border-radius: 0.75rem;
    border: none;
    padding: 1rem 1.25rem;
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    font-size: 0.9375rem;
  }
  
  body.footer-settings-page .alert-success {
    background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
    color: #065f46;
  }
  
  body.footer-settings-page .alert-danger {
    background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
    color: #991b1b;
  }
  
  body.footer-settings-page .alert-info {
    background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
    color: #1e40af;
    border: 1px solid #93c5fd !important;
  }
  
  /* List improvements */
  body.footer-settings-page .list-unstyled li {
    display: flex;
    align-items: flex-start;
    gap: 0.5rem;
    line-height: 1.6;
    margin-bottom: 0.75rem;
  }
  
  body.footer-settings-page .list-unstyled li i {
    margin-top: 0.125rem;
    flex-shrink: 0;
  }
  
  /* Responsive improvements */
  @media (max-width: 991.98px) {
    body.footer-settings-page .sticky-save {
      position: relative;
      top: 0;
      max-height: none;
      overflow-y: visible;
    }
  }
  
  @media (max-width: 767.98px) {
    body.footer-settings-page .settings-card {
      padding: 1.5rem;
    }
    
    body.footer-settings-page .page-header {
      padding: 1.5rem;
    }
    
    body.footer-settings-page .page-header h2 {
      font-size: 1.5rem;
    }
  }
</style>
<body class="footer-settings-page">
  <?php include __DIR__ . '/../../includes/admin/admin_topbar.php'; ?>
  
  <div id="wrapper" class="admin-wrapper">
    <?php include __DIR__ . '/../../includes/admin/admin_sidebar.php'; ?>
    <?php include __DIR__ . '/../../includes/admin/admin_header.php'; ?>

    <section class="home-section" id="mainContent">
      <div class="container-fluid py-4 px-4">
        
        <div class="mb-4">
          <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
              <h1 class="fw-bold mb-1">Footer Settings</h1>
              <p class="text-muted mb-0">Customize footer colors, content, and contact information across all website pages</p>
            </div>
            <div class="d-flex align-items-center gap-2">
              <a href="homepage.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-2"></i>Dashboard
              </a>
            </div>
          </div>
        </div>
        
        <?php if ($successMessage): ?>
          <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($successMessage) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>
        
        <?php if ($errorMessage): ?>
          <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($errorMessage) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>
        
        <!-- Footer Preview -->
        <div class="settings-card mb-3">
          <h5 class="mb-3"><i class="bi bi-eye me-2"></i>Footer Preview</h5>
          <div class="preview-footer" id="preview-footer" style="background: <?= htmlspecialchars($current_settings['footer_bg_color']) ?>; color: <?= htmlspecialchars($current_settings['footer_text_color']) ?>;">
            <div class="row g-4 align-items-center">
              <div class="col-lg-6">
                <div class="d-flex align-items-center gap-3">
                  <div id="preview-badge" style="width: 48px; height: 48px; background: <?= htmlspecialchars($current_settings['footer_link_hover_color']) ?>; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 1.1rem; color: <?= htmlspecialchars($current_settings['footer_bg_color']) ?>;">
                    EA
                  </div>
                  <div>
                    <div id="preview-title" style="font-size: 1.2rem; font-weight: 600; color: <?= htmlspecialchars($current_settings['footer_heading_color']) ?>;">
                      <?= htmlspecialchars($current_settings['footer_title']) ?>
                    </div>
                    <small id="preview-description" style="color: <?= htmlspecialchars($current_settings['footer_text_color']) ?>; opacity: 0.9;">
                      <?= htmlspecialchars($current_settings['footer_description']) ?>
                    </small>
                  </div>
                </div>
              </div>
              <div class="col-lg-6">
                <div class="row">
                  <div class="col-6 col-md-4">
                    <h6 style="color: <?= htmlspecialchars($current_settings['footer_heading_color']) ?>; font-weight: 600; font-size: 0.95rem;">Explore</h6>
                    <ul class="list-unstyled small mb-0">
                      <li><a href="#" style="color: <?= htmlspecialchars($current_settings['footer_link_color']) ?>; text-decoration: none;">Home</a></li>
                      <li><a href="#" style="color: <?= htmlspecialchars($current_settings['footer_link_color']) ?>; text-decoration: none;">About</a></li>
                      <li><a href="#" style="color: <?= htmlspecialchars($current_settings['footer_link_color']) ?>; text-decoration: none;">Process</a></li>
                    </ul>
                  </div>
                  <div class="col-6 col-md-4">
                    <h6 style="color: <?= htmlspecialchars($current_settings['footer_heading_color']) ?>; font-weight: 600; font-size: 0.95rem;">Resources</h6>
                    <ul class="list-unstyled small mb-0">
                      <li><a href="#" style="color: <?= htmlspecialchars($current_settings['footer_link_color']) ?>; text-decoration: none;">Requirements</a></li>
                      <li><a href="#" style="color: <?= htmlspecialchars($current_settings['footer_link_color']) ?>; text-decoration: none;">FAQs</a></li>
                      <li><a href="#" style="color: <?= htmlspecialchars($current_settings['footer_link_color']) ?>; text-decoration: none;">Contact</a></li>
                    </ul>
                  </div>
                  <div class="col-12 col-md-4 mt-3 mt-md-0">
                    <h6 style="color: <?= htmlspecialchars($current_settings['footer_heading_color']) ?>; font-weight: 600; font-size: 0.95rem;">Contact Info</h6>
                    <ul class="list-unstyled small mb-0">
                      <li class="mb-2" style="color: <?= htmlspecialchars($current_settings['footer_text_color']) ?>;"><i class="bi bi-geo-alt me-2"></i><span id="preview-address"><?= htmlspecialchars($current_settings['contact_address']) ?></span></li>
                      <li class="mb-2" style="color: <?= htmlspecialchars($current_settings['footer_text_color']) ?>;"><i class="bi bi-telephone me-2"></i><span id="preview-phone"><?= htmlspecialchars($current_settings['contact_phone']) ?></span></li>
                      <li class="mb-2" style="color: <?= htmlspecialchars($current_settings['footer_text_color']) ?>;"><i class="bi bi-envelope me-2"></i><span id="preview-email"><?= htmlspecialchars($current_settings['contact_email']) ?></span></li>
                    </ul>
                  </div>
                </div>
              </div>
            </div>
            <hr id="preview-divider" style="border-color: <?= htmlspecialchars($current_settings['footer_divider_color']) ?>; opacity: 0.25; margin: 1.5rem 0;">
            <div class="d-flex justify-content-between flex-wrap gap-2 small" style="color: <?= htmlspecialchars($current_settings['footer_text_color']) ?>;">
              <span>© <span id="year"><?= date('Y') ?></span> City Government of General Trias • EducAid</span>
              <span>Powered by the Office of the Mayor • IT</span>
            </div>
          </div>
        </div>

        <!-- Settings Form -->
        <form method="POST" id="settingsForm" action="">
          <?= CSRFProtection::getTokenField('footer_settings') ?>
          
          <div class="row">
            <div class="col-12">
              
              <!-- Color Settings -->
              <div class="settings-card">
                <h5><i class="bi bi-palette me-2"></i>Color Scheme</h5>
                <p class="text-muted small mb-4">Customize the footer color palette. Changes apply to all website pages.</p>
                
                <div class="color-group-label">
                  <i class="bi bi-circle-fill me-2" style="font-size: 0.625rem;"></i>Base Colors
                </div>
                
                <div class="row mb-3">
                  <div class="col-md-6">
                    <label for="footer_bg_color" class="form-label">
                      <i class="bi bi-paint-bucket"></i> Background Color
                    </label>
                    <div class="input-group">
                      <input type="color" 
                             class="form-control form-control-color" 
                             id="footer_bg_color" 
                             name="footer_bg_color" 
                             value="<?= htmlspecialchars($current_settings['footer_bg_color']) ?>"
                             title="Choose background color">
                      <input type="text" 
                             class="form-control" 
                             value="<?= htmlspecialchars($current_settings['footer_bg_color']) ?>"
                             readonly>
                    </div>
                  </div>
                  
                  <div class="col-md-6">
                    <label for="footer_text_color" class="form-label">
                      <i class="bi bi-fonts"></i> Text Color
                    </label>
                    <div class="input-group">
                      <input type="color" 
                             class="form-control form-control-color" 
                             id="footer_text_color" 
                             name="footer_text_color" 
                             value="<?= htmlspecialchars($current_settings['footer_text_color']) ?>"
                             title="Choose text color">
                      <input type="text" 
                             class="form-control" 
                             value="<?= htmlspecialchars($current_settings['footer_text_color']) ?>"
                             readonly>
                    </div>
                  </div>
                </div>
                
                <div class="color-group-label mt-4">
                  <i class="bi bi-circle-fill me-2" style="font-size: 0.625rem;"></i>Text & Headings
                </div>
                
                <div class="row mb-3">
                  <div class="col-md-6">
                    <label for="footer_heading_color" class="form-label">
                      <i class="bi bi-type-h1"></i> Heading Color
                    </label>
                    <div class="input-group">
                      <input type="color" 
                             class="form-control form-control-color" 
                             id="footer_heading_color" 
                             name="footer_heading_color" 
                             value="<?= htmlspecialchars($current_settings['footer_heading_color']) ?>"
                             title="Choose heading color">
                      <input type="text" 
                             class="form-control" 
                             value="<?= htmlspecialchars($current_settings['footer_heading_color']) ?>"
                             readonly>
                    </div>
                  </div>
                  
                  <div class="col-md-6">
                    <label for="footer_divider_color" class="form-label">
                      <i class="bi bi-dash-lg"></i> Divider Color
                    </label>
                    <div class="input-group">
                      <input type="color" 
                             class="form-control form-control-color" 
                             id="footer_divider_color" 
                             name="footer_divider_color" 
                             value="<?= htmlspecialchars($current_settings['footer_divider_color']) ?>"
                             title="Choose divider color">
                      <input type="text" 
                             class="form-control" 
                             value="<?= htmlspecialchars($current_settings['footer_divider_color']) ?>"
                             readonly>
                    </div>
                  </div>
                </div>
                
                <div class="color-group-label mt-4">
                  <i class="bi bi-circle-fill me-2" style="font-size: 0.625rem;"></i>Links & Interactive Elements
                </div>
                
                <div class="row mb-0">
                  <div class="col-md-6">
                    <label for="footer_link_color" class="form-label">
                      <i class="bi bi-link-45deg"></i> Link Color
                    </label>
                    <div class="input-group">
                      <input type="color" 
                             class="form-control form-control-color" 
                             id="footer_link_color" 
                             name="footer_link_color" 
                             value="<?= htmlspecialchars($current_settings['footer_link_color']) ?>"
                             title="Choose link color">
                      <input type="text" 
                             class="form-control" 
                             value="<?= htmlspecialchars($current_settings['footer_link_color']) ?>"
                             readonly>
                    </div>
                  </div>
                  
                  <div class="col-md-6">
                    <label for="footer_link_hover_color" class="form-label">
                      <i class="bi bi-cursor"></i> Link Hover Color
                    </label>
                    <div class="input-group">
                      <input type="color" 
                             class="form-control form-control-color" 
                             id="footer_link_hover_color" 
                             name="footer_link_hover_color" 
                             value="<?= htmlspecialchars($current_settings['footer_link_hover_color']) ?>"
                             title="Choose link hover color">
                      <input type="text" 
                             class="form-control" 
                             value="<?= htmlspecialchars($current_settings['footer_link_hover_color']) ?>"
                             readonly>
                    </div>
                  </div>
                </div>
              </div>
              
              <!-- Content Settings -->
              <div class="settings-card">
                <h5><i class="bi bi-pencil-square me-2"></i>Footer Content</h5>
                <p class="text-muted small mb-4">Edit the text displayed in your website footer</p>
                
                <div class="mb-3">
                  <label for="footer_title" class="form-label">Footer Title <span class="text-danger">*</span></label>
                  <input type="text" 
                         class="form-control" 
                         id="footer_title" 
                         name="footer_title" 
                         value="<?= htmlspecialchars($current_settings['footer_title']) ?>" 
                         placeholder="EducAid • General Trias" 
                         required>
                  <div class="form-text">Main branding text displayed in the footer</div>
                </div>
                
                <div class="mb-0">
                  <label for="footer_description" class="form-label">Description</label>
                  <textarea class="form-control" 
                            id="footer_description" 
                            name="footer_description" 
                            rows="3" 
                            placeholder="Brief description or tagline"><?= htmlspecialchars($current_settings['footer_description']) ?></textarea>
                  <div class="form-text">Short description or motto displayed below the title</div>
                </div>
              </div>
              
              <!-- Contact Information -->
              <div class="settings-card">
                <h5><i class="bi bi-telephone me-2"></i>Contact Information</h5>
                <p class="text-muted small mb-4">Display your contact details in the footer</p>
                
                <div class="mb-3">
                  <label for="contact_address" class="form-label">Address</label>
                  <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-geo-alt"></i></span>
                    <input type="text" 
                           class="form-control" 
                           id="contact_address" 
                           name="contact_address" 
                           value="<?= htmlspecialchars($current_settings['contact_address']) ?>" 
                           placeholder="City Hall, Address">
                  </div>
                </div>
                
                <div class="mb-3">
                  <label for="contact_phone" class="form-label">Phone Number</label>
                  <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-telephone"></i></span>
                    <input type="text" 
                           class="form-control" 
                           id="contact_phone" 
                           name="contact_phone" 
                           value="<?= htmlspecialchars($current_settings['contact_phone']) ?>" 
                           placeholder="(046) 886-4454"
                           readonly
                           style="background-color: #f8f9fa;">
                  </div>
                  <div class="form-text"><i class="bi bi-info-circle me-1"></i>Managed in <a href="topbar_settings.php">Topbar Settings</a> for consistency across all pages.</div>
                </div>
                
                <div class="mb-0">
                  <label for="contact_email" class="form-label">Email Address</label>
                  <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                    <input type="email" 
                           class="form-control" 
                           id="contact_email" 
                           name="contact_email" 
                           value="<?= htmlspecialchars($current_settings['contact_email']) ?>" 
                           placeholder="educaid@generaltrias.gov.ph"
                           readonly
                           style="background-color: #f8f9fa;">
                  </div>
                  <div class="form-text"><i class="bi bi-info-circle me-1"></i>Managed in <a href="topbar_settings.php">Topbar Settings</a> for consistency across all pages.</div>
                </div>
              </div>
              
              <div class="d-flex gap-3 mb-4">
                <button type="submit" class="btn btn-primary btn-lg flex-grow-1" id="footerSettingsSubmit">
                  <i class="bi bi-save me-2"></i>Save Footer Settings
                </button>
                <a href="homepage.php" class="btn btn-outline-secondary btn-lg">
                  <i class="bi bi-x-lg"></i>
                </a>
              </div>
            </div>
          </div>
        </form>
        
      </div>
    </section>
  </div>
  
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script src="../../assets/js/admin/footer-settings.js"></script>
</body>
</html>
  