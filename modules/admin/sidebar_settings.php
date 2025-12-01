<?php
// Load secure session configuration (must be before session_start)
require_once __DIR__ . '/../../config/session_config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include_once __DIR__ . '/../../config/database.php';
include_once __DIR__ . '/../../includes/permissions.php';
include_once __DIR__ . '/../../includes/CSRFProtection.php';
include_once __DIR__ . '/../../controllers/SidebarSettingsController.php';
include_once __DIR__ . '/../../services/SidebarThemeService.php';

// Check if user is logged in and is super admin
if (!isset($_SESSION['admin_id'])) {
    header("Location: ../../login.php");
    exit;
}

$admin_role = getCurrentAdminRole($connection);
if ($admin_role !== 'super_admin') {
    header("Location: homepage.php?error=access_denied");
    exit;
}

// Initialize services
$sidebarThemeService = new SidebarThemeService($connection);
$currentSettings = $sidebarThemeService->getCurrentSettings();

// Handle form submission
$message = '';
$messageType = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_sidebar_theme'])) {
    if (CSRFProtection::validateToken('sidebar_settings', $_POST['csrf_token'] ?? '')) {
        $controller = new SidebarSettingsController($connection);
        $result = $controller->handleSubmission($_POST);
        
        if ($result['success']) {
            $message = $result['message'];
            $messageType = 'success';
            // Refresh settings
            $currentSettings = $sidebarThemeService->getCurrentSettings();
        } else {
            $message = $result['message'];
            $messageType = 'error';
        }
    } else {
        $message = 'Invalid security token. Please try again.';
        $messageType = 'error';
    }
}
?>

<?php $page_title='Sidebar Theme Settings'; $extra_css=[]; include __DIR__ . '/../../includes/admin/admin_head.php'; ?>
<style>
  /* Modern Enhanced UI Styling for Sidebar Settings */
  body.sidebar-settings-page {
    background: linear-gradient(135deg, #f5f7fa 0%, #e8ecf1 100%);
  }
  
  body.sidebar-settings-page .card {
    background: #ffffff;
    border-radius: 12px;
    border: 1px solid rgba(0,0,0,0.05);
    box-shadow: 0 4px 6px rgba(0,0,0,0.07), 0 1px 3px rgba(0,0,0,0.06);
    margin-bottom: 1.5rem;
    transition: all 0.3s ease;
  }
  
  body.sidebar-settings-page .card:hover {
    box-shadow: 0 8px 15px rgba(0,0,0,0.1), 0 3px 6px rgba(0,0,0,0.08);
    transform: translateY(-2px);
  }
  
  body.sidebar-settings-page .card-header {
    background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
    border-bottom: 2px solid #e2e8f0;
    border-radius: 12px 12px 0 0 !important;
    padding: 1.25rem 1.5rem;
  }
  
  body.sidebar-settings-page .card-title {
    color: #1e293b;
    font-weight: 700;
    font-size: 1.1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
  }
  
  body.sidebar-settings-page .card-title i {
    color: #2e7d32;
    font-size: 1.2rem;
  }
  
  body.sidebar-settings-page .card-body {
    padding: 2rem 1.5rem;
  }
  
  /* Preview Sidebar Styles */
  body.sidebar-settings-page .preview-sidebar {
    background: linear-gradient(180deg, <?= htmlspecialchars($currentSettings['sidebar_bg_start']) ?> 0%, <?= htmlspecialchars($currentSettings['sidebar_bg_end']) ?> 100%);
    border: 2px solid <?= htmlspecialchars($currentSettings['sidebar_border_color']) ?>;
    border-radius: 12px;
    padding: 1.25rem;
    min-height: 450px;
    font-family: 'Poppins', var(--bs-font-sans-serif, Arial, sans-serif);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    transition: all 0.3s ease;
  }
  
  /* Sticky Preview */
  body.sidebar-settings-page .preview-wrapper {
    position: sticky;
    top: 80px;
    z-index: 100;
    transition: all 0.3s ease;
  }
  
  body.sidebar-settings-page .preview-wrapper.stuck {
    box-shadow: 0 8px 24px rgba(0,0,0,0.12);
  }
  
  body.sidebar-settings-page .preview-wrapper.stuck .card {
    border-radius: 0 0 12px 12px;
    box-shadow: 0 6px 16px rgba(0,0,0,0.1);
  }
  
  body.sidebar-settings-page .preview-wrapper.stuck::before {
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
  
  body.sidebar-settings-page .preview-profile {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding-bottom: 1rem;
    margin-bottom: 1rem;
    border-bottom: 2px solid <?= htmlspecialchars($currentSettings['profile_border_color']) ?>;
  }
  
  body.sidebar-settings-page .preview-avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: linear-gradient(135deg, <?= htmlspecialchars($currentSettings['profile_avatar_bg_start']) ?>, <?= htmlspecialchars($currentSettings['profile_avatar_bg_end']) ?>);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 1.1rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.2);
    transition: all 0.3s ease;
  }
  
  body.sidebar-settings-page .preview-avatar:hover {
    transform: scale(1.1);
    box-shadow: 0 4px 12px rgba(0,0,0,0.3);
  }
  
  body.sidebar-settings-page .preview-nav-item {
    padding: 0.65rem 1rem;
    margin: 0.35rem 0;
    border-radius: 8px;
    color: <?= htmlspecialchars($currentSettings['nav_text_color']) ?>;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 0.65rem;
    transition: all 0.2s ease;
    font-weight: 500;
    font-size: 0.95rem;
  }
  
  body.sidebar-settings-page .preview-nav-item:hover {
    background: <?= htmlspecialchars($currentSettings['nav_hover_bg']) ?>;
    color: <?= htmlspecialchars($currentSettings['nav_hover_text']) ?>;
    transform: translateX(4px);
    box-shadow: 0 2px 6px rgba(0,0,0,0.1);
  }
  
  body.sidebar-settings-page .preview-nav-item.active {
    background: <?= htmlspecialchars($currentSettings['nav_active_bg']) ?>;
    color: <?= htmlspecialchars($currentSettings['nav_active_text']) ?>;
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
    font-weight: 600;
  }
  
  body.sidebar-settings-page .preview-nav-item i {
    color: <?= htmlspecialchars($currentSettings['nav_icon_color']) ?>;
    font-size: 1.1rem;
    transition: all 0.2s ease;
  }
  
  body.sidebar-settings-page .preview-nav-item:hover i {
    transform: scale(1.15);
  }
  
  body.sidebar-settings-page .preview-submenu {
    background: <?= htmlspecialchars($currentSettings['submenu_bg']) ?>;
    margin: 0.35rem 0;
    border-radius: 8px;
    padding: 0.5rem;
    box-shadow: inset 0 1px 3px rgba(0,0,0,0.1);
  }
  
  body.sidebar-settings-page .preview-submenu-item {
    padding: 0.5rem 0.75rem 0.5rem 2rem;
    margin: 0.25rem 0;
    border-radius: 6px;
    color: <?= htmlspecialchars($currentSettings['submenu_text_color']) ?>;
    font-size: 0.875rem;
    cursor: pointer;
    transition: all 0.2s ease;
    position: relative;
  }
  
  body.sidebar-settings-page .preview-submenu-item::before {
    content: '•';
    position: absolute;
    left: 1rem;
    opacity: 0.5;
  }
  
  body.sidebar-settings-page .preview-submenu-item:hover {
    background: <?= htmlspecialchars($currentSettings['submenu_hover_bg']) ?>;
    transform: translateX(3px);
  }
  
  body.sidebar-settings-page .preview-submenu-item.active {
    background: <?= htmlspecialchars($currentSettings['submenu_active_bg']) ?>;
    color: <?= htmlspecialchars($currentSettings['submenu_active_text']) ?>;
    font-weight: 600;
  }
  
  /* Color Input Group Enhancements */
  body.sidebar-settings-page .color-input-group {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.5rem;
    background: #f8fafc;
    border-radius: 8px;
    transition: all 0.2s ease;
  }
  
  body.sidebar-settings-page .color-input-group:hover {
    background: #f1f5f9;
    box-shadow: 0 2px 6px rgba(0,0,0,0.08);
  }
  
  body.sidebar-settings-page .color-input-group input[type=color] {
    width: 50px;
    height: 50px;
    border: 2px solid #e2e8f0;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s ease;
  }
  
  body.sidebar-settings-page .color-input-group input[type=color]:hover {
    transform: scale(1.1);
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
    border-color: #2e7d32;
  }
  
  body.sidebar-settings-page .color-input-group input[type=text] {
    flex: 1;
    font-family: 'Courier New', monospace;
    text-transform: uppercase;
    font-weight: 600;
    border: 2px solid #e2e8f0;
    border-radius: 6px;
    padding: 0.5rem;
    background: white;
    color: #334155;
  }
  
  /* Form Label Enhancements */
  body.sidebar-settings-page .form-label {
    font-weight: 600;
    color: #334155;
    margin-bottom: 0.65rem;
    font-size: 0.95rem;
  }
  
  /* Section Headers */
  body.sidebar-settings-page h6 {
    color: #1e293b;
    font-weight: 700;
    font-size: 1rem;
    padding-bottom: 0.75rem;
    margin-bottom: 1.25rem;
    border-bottom: 2px solid #e2e8f0 !important;
  }
  
  /* Button Enhancements */
  body.sidebar-settings-page .btn {
    border-radius: 8px;
    padding: 0.65rem 1.5rem;
    font-weight: 600;
    transition: all 0.3s ease;
    border: none;
  }
  
  body.sidebar-settings-page .btn-primary {
    background: linear-gradient(135deg, #2e7d32 0%, #1b5e20 100%);
    box-shadow: 0 4px 10px rgba(46, 125, 50, 0.3);
  }
  
  body.sidebar-settings-page .btn-primary:hover {
    background: linear-gradient(135deg, #1b5e20 0%, #2e7d32 100%);
    box-shadow: 0 6px 15px rgba(46, 125, 50, 0.4);
    transform: translateY(-2px);
  }
  
  body.sidebar-settings-page .btn-outline-secondary {
    border: 2px solid #cbd5e1;
    color: #475569;
    background: white;
  }
  
  body.sidebar-settings-page .btn-outline-secondary:hover {
    background: #f8fafc;
    border-color: #94a3b8;
    color: #334155;
    transform: translateX(-3px);
  }
  
  /* Alert Enhancements */
  body.sidebar-settings-page .alert {
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
  
  body.sidebar-settings-page .alert-success {
    background: linear-gradient(135deg, #d1f4dd 0%, #a7f3d0 100%);
    color: #065f46;
  }
  
  body.sidebar-settings-page .alert-danger {
    background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
    color: #991b1b;
  }
  
  /* Page Header */
  body.sidebar-settings-page h2 {
    color: #1e293b;
    font-weight: 700;
    font-size: 2rem;
  }
  
  body.sidebar-settings-page .text-muted {
    color: #64748b !important;
    font-size: 1.05rem;
  }
  
  /* Responsive */
  @media (max-width: 991.98px) {
    body.sidebar-settings-page .preview-wrapper {
      position: relative;
      top: 0;
    }
    
    body.sidebar-settings-page h2 {
      font-size: 1.5rem;
    }
  }
  
  /* Smooth Scroll */
  html {
    scroll-behavior: smooth;
  }
</style>
<body class="sidebar-settings-page">
    <?php include __DIR__ . '/../../includes/admin/admin_topbar.php'; ?>
    <div id="wrapper" class="admin-wrapper">
        <?php include __DIR__ . '/../../includes/admin/admin_sidebar.php'; ?>
        <?php include __DIR__ . '/../../includes/admin/admin_header.php'; ?>

        <section class="home-section" id="mainContent">
            <div class="container-fluid py-4 px-4" style="max-width: 1400px; margin: 0 auto;">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h2 class="mb-2 d-flex align-items-center gap-2">
                            Sidebar Theme Settings
                        </h2>
                        <p class="text-muted mb-0">Customize the colors and gradients used in the admin sidebar</p>
                    </div>
                </div>

                <?php if ($message): ?>
                    <div class="alert alert-<?= $messageType === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show" role="alert">
                        <i class="bi bi-<?= $messageType === 'success' ? 'check-circle' : 'exclamation-triangle' ?> me-2"></i><?= htmlspecialchars($message) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="row">
                    <div class="col-lg-8">
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-palette-fill"></i>
                                    Theme Configuration
                                </h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" id="sidebarSettingsForm">
                                    <input type="hidden" name="csrf_token" value="<?= CSRFProtection::generateToken('sidebar_settings') ?>">
                                    <input type="hidden" name="update_sidebar_theme" value="1">

                                    <!-- Sidebar Background -->
                                    <div class="row mb-4">
                                        <div class="col-12">
                                            <h6>Sidebar Background</h6>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Background Start Color</label>
                                            <div class="color-input-group">
                                                <input type="color" id="sidebar_bg_start" name="sidebar_bg_start" value="<?= htmlspecialchars($currentSettings['sidebar_bg_start']) ?>">
                                                <input type="text" class="form-control" value="<?= htmlspecialchars($currentSettings['sidebar_bg_start']) ?>" readonly>
                                            </div>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Background End Color</label>
                                            <div class="color-input-group">
                                                <input type="color" id="sidebar_bg_end" name="sidebar_bg_end" value="<?= htmlspecialchars($currentSettings['sidebar_bg_end']) ?>">
                                                <input type="text" class="form-control" value="<?= htmlspecialchars($currentSettings['sidebar_bg_end']) ?>" readonly>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Navigation Colors -->
                                    <div class="row mb-4">
                                        <div class="col-12">
                                            <h6>Navigation</h6>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Text Color</label>
                                            <div class="color-input-group">
                                                <input type="color" id="nav_text_color" name="nav_text_color" value="<?= htmlspecialchars($currentSettings['nav_text_color']) ?>">
                                                <input type="text" class="form-control" value="<?= htmlspecialchars($currentSettings['nav_text_color']) ?>" readonly>
                                            </div>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Icon Color</label>
                                            <div class="color-input-group">
                                                <input type="color" id="nav_icon_color" name="nav_icon_color" value="<?= htmlspecialchars($currentSettings['nav_icon_color']) ?>">
                                                <input type="text" class="form-control" value="<?= htmlspecialchars($currentSettings['nav_icon_color']) ?>" readonly>
                                            </div>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Hover Background</label>
                                            <div class="color-input-group">
                                                <input type="color" id="nav_hover_bg" name="nav_hover_bg" value="<?= htmlspecialchars($currentSettings['nav_hover_bg']) ?>">
                                                <input type="text" class="form-control" value="<?= htmlspecialchars($currentSettings['nav_hover_bg']) ?>" readonly>
                                            </div>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Hover Text Color</label>
                                            <div class="color-input-group">
                                                <input type="color" id="nav_hover_text" name="nav_hover_text" value="<?= htmlspecialchars($currentSettings['nav_hover_text'] ?? '#212529') ?>">
                                                <input type="text" class="form-control" value="<?= htmlspecialchars($currentSettings['nav_hover_text'] ?? '#212529') ?>" readonly>
                                            </div>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Active Background</label>
                                            <div class="color-input-group">
                                                <input type="color" id="nav_active_bg" name="nav_active_bg" value="<?= htmlspecialchars($currentSettings['nav_active_bg']) ?>">
                                                <input type="text" class="form-control" value="<?= htmlspecialchars($currentSettings['nav_active_bg']) ?>" readonly>
                                            </div>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Active Text Color</label>
                                            <div class="color-input-group">
                                                <input type="color" id="nav_active_text" name="nav_active_text" value="<?= htmlspecialchars($currentSettings['nav_active_text'] ?? '#ffffff') ?>">
                                                <input type="text" class="form-control" value="<?= htmlspecialchars($currentSettings['nav_active_text'] ?? '#ffffff') ?>" readonly>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Profile Section -->
                                    <div class="row mb-4">
                                        <div class="col-12">
                                            <h6>Profile Section</h6>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Avatar Start Color</label>
                                            <div class="color-input-group">
                                                <input type="color" id="profile_avatar_bg_start" name="profile_avatar_bg_start" value="<?= htmlspecialchars($currentSettings['profile_avatar_bg_start']) ?>">
                                                <input type="text" class="form-control" value="<?= htmlspecialchars($currentSettings['profile_avatar_bg_start']) ?>" readonly>
                                            </div>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Avatar End Color</label>
                                            <div class="color-input-group">
                                                <input type="color" id="profile_avatar_bg_end" name="profile_avatar_bg_end" value="<?= htmlspecialchars($currentSettings['profile_avatar_bg_end']) ?>">
                                                <input type="text" class="form-control" value="<?= htmlspecialchars($currentSettings['profile_avatar_bg_end']) ?>" readonly>
                                            </div>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Name Color</label>
                                            <div class="color-input-group">
                                                <input type="color" id="profile_name_color" name="profile_name_color" value="<?= htmlspecialchars($currentSettings['profile_name_color']) ?>">
                                                <input type="text" class="form-control" value="<?= htmlspecialchars($currentSettings['profile_name_color']) ?>" readonly>
                                            </div>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Role Color</label>
                                            <div class="color-input-group">
                                                <input type="color" id="profile_role_color" name="profile_role_color" value="<?= htmlspecialchars($currentSettings['profile_role_color']) ?>">
                                                <input type="text" class="form-control" value="<?= htmlspecialchars($currentSettings['profile_role_color']) ?>" readonly>
                                            </div>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Profile Border Color</label>
                                            <div class="color-input-group">
                                                <input type="color" id="profile_border_color" name="profile_border_color" value="<?= htmlspecialchars($currentSettings['profile_border_color'] ?? '#dee2e6') ?>">
                                                <input type="text" class="form-control" value="<?= htmlspecialchars($currentSettings['profile_border_color'] ?? '#dee2e6') ?>" readonly>
                                            </div>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Sidebar Border Color</label>
                                            <div class="color-input-group">
                                                <input type="color" id="sidebar_border_color" name="sidebar_border_color" value="<?= htmlspecialchars($currentSettings['sidebar_border_color'] ?? '#dee2e6') ?>">
                                                <input type="text" class="form-control" value="<?= htmlspecialchars($currentSettings['sidebar_border_color'] ?? '#dee2e6') ?>" readonly>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Submenu Section -->
                                    <div class="row mb-4">
                                        <div class="col-12">
                                            <h6>Submenu</h6>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Submenu Background</label>
                                            <div class="color-input-group">
                                                <input type="color" id="submenu_bg" name="submenu_bg" value="<?= htmlspecialchars($currentSettings['submenu_bg'] ?? '#f8f9fa') ?>">
                                                <input type="text" class="form-control" value="<?= htmlspecialchars($currentSettings['submenu_bg'] ?? '#f8f9fa') ?>" readonly>
                                            </div>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Submenu Text Color</label>
                                            <div class="color-input-group">
                                                <input type="color" id="submenu_text_color" name="submenu_text_color" value="<?= htmlspecialchars($currentSettings['submenu_text_color'] ?? '#495057') ?>">
                                                <input type="text" class="form-control" value="<?= htmlspecialchars($currentSettings['submenu_text_color'] ?? '#495057') ?>" readonly>
                                            </div>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Submenu Hover Background</label>
                                            <div class="color-input-group">
                                                <input type="color" id="submenu_hover_bg" name="submenu_hover_bg" value="<?= htmlspecialchars($currentSettings['submenu_hover_bg'] ?? '#e9ecef') ?>">
                                                <input type="text" class="form-control" value="<?= htmlspecialchars($currentSettings['submenu_hover_bg'] ?? '#e9ecef') ?>" readonly>
                                            </div>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Submenu Active Background</label>
                                            <div class="color-input-group">
                                                <input type="color" id="submenu_active_bg" name="submenu_active_bg" value="<?= htmlspecialchars($currentSettings['submenu_active_bg'] ?? '#e7f3ff') ?>">
                                                <input type="text" class="form-control" value="<?= htmlspecialchars($currentSettings['submenu_active_bg'] ?? '#e7f3ff') ?>" readonly>
                                            </div>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Submenu Active Text</label>
                                            <div class="color-input-group">
                                                <input type="color" id="submenu_active_text" name="submenu_active_text" value="<?= htmlspecialchars($currentSettings['submenu_active_text'] ?? '#0d6efd') ?>">
                                                <input type="text" class="form-control" value="<?= htmlspecialchars($currentSettings['submenu_active_text'] ?? '#0d6efd') ?>" readonly>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="d-flex justify-content-between mt-4">
                                        <button type="button" class="btn btn-outline-secondary d-flex align-items-center gap-2" id="resetDefaults">
                                            <i class="bi bi-arrow-clockwise"></i>
                                            <span>Reset to Defaults</span>
                                        </button>
                                        <button type="submit" class="btn btn-primary d-flex align-items-center gap-2">
                                            <i class="bi bi-check-circle"></i>
                                            <span>Save Settings</span>
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-4">
                        <div class="preview-wrapper" id="previewWrapper">
                            <div class="card mb-4">
                                <div class="card-header">
                                    <h6 class="card-title mb-0 d-flex align-items-center gap-2">
                                        <i class="bi bi-eye-fill"></i>
                                        Live Preview
                                        <span class="badge bg-success ms-auto" style="font-size: 0.7rem; padding: 0.35rem 0.6rem;">Live</span>
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div class="preview-sidebar" id="previewSidebar">
                                        <div class="preview-profile">
                                            <div class="preview-avatar" id="previewAvatar">A</div>
                                            <div>
                                                <div style="color: <?= htmlspecialchars($currentSettings['profile_name_color']) ?>; font-weight: 600; font-size: 0.9rem;" id="previewName">Admin User</div>
                                                <div style="color: <?= htmlspecialchars($currentSettings['profile_role_color']) ?>; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.75px;" id="previewRole">Super Admin</div>
                                            </div>
                                        </div>
                                        <div class="preview-nav-item active">
                                            <i class="bi bi-house-door-fill"></i>
                                            <span>Dashboard</span>
                                        </div>
                                        <div class="preview-nav-item">
                                            <i class="bi bi-people-fill"></i>
                                            <span>Manage Users</span>
                                        </div>
                                        <div class="preview-nav-item">
                                            <i class="bi bi-gear-fill"></i>
                                            <span>System Controls</span>
                                        </div>
                                        <div class="preview-submenu">
                                            <div class="preview-submenu-item active">Settings</div>
                                            <div class="preview-submenu-item">Users</div>
                                            <div class="preview-submenu-item">Reports</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/js/admin/sidebar.js"></script>
    <script src="../../assets/js/admin/sidebar-theme-settings.js"></script>
    
    <script>
        // Sticky preview behavior enhancement
        document.addEventListener('DOMContentLoaded', function() {
            const previewWrapper = document.getElementById('previewWrapper');
            
            if (previewWrapper) {
                const observer = new IntersectionObserver(
                    ([entry]) => {
                        if (!entry.isIntersecting) {
                            previewWrapper.classList.add('stuck');
                        } else {
                            previewWrapper.classList.remove('stuck');
                        }
                    },
                    {
                        root: null,
                        threshold: 0,
                        rootMargin: '-80px 0px 0px 0px'
                    }
                );
                
                const sentinel = document.createElement('div');
                sentinel.style.height = '1px';
                sentinel.style.position = 'absolute';
                sentinel.style.top = '0';
                sentinel.style.width = '100%';
                sentinel.style.pointerEvents = 'none';
                
                previewWrapper.parentNode.insertBefore(sentinel, previewWrapper);
                observer.observe(sentinel);
            }
        });
    </script>
</body>
</html>