<?php
/** @phpstan-ignore-file */
include __DIR__ . '/../../config/database.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['student_username'])) {
    header("Location: ../../unified_login.php");
    exit;
}
$student_id = $_SESSION['student_id'];

// Enforce session timeout via middleware
require_once __DIR__ . '/../../includes/SessionTimeoutMiddleware.php';
$timeoutMiddleware = new SessionTimeoutMiddleware();
$timeoutStatus = $timeoutMiddleware->handle();

// PHPMailer not required here but keep consistent includes if needed

// Get student info for header dropdown
$student_info_query = "SELECT first_name, last_name FROM students WHERE student_id = $1";
$student_info_result = pg_query_params($connection, $student_info_query, [$student_id]);
$student_info = pg_fetch_assoc($student_info_result);

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Password - EducAid</title>
  <link href="../../assets/css/bootstrap.min.css" rel="stylesheet" />
  <link href="../../assets/css/bootstrap-icons.css" rel="stylesheet" />
  <link href="../../assets/css/student/sidebar.css" rel="stylesheet" />
  <link rel="stylesheet" href="../../assets/css/student/accessibility.css" />
  <link rel="stylesheet" href="../../assets/css/student/animations.css" />
  <script src="../../assets/js/student/accessibility.js"></script>
  <script src="../../assets/js/student/animation_utils.js"></script>
  <style>
    /* FOUC Prevention */
    body { opacity: 0; transition: opacity 0.3s ease; background: #f7fafc; }
    body.ready { opacity: 1; }
    body:not(.ready) .sidebar { visibility: hidden; }
    
    /* Main Content Area Layout */
    .home-section {
      margin-left: 250px;
      width: calc(100% - 250px);
      min-height: calc(100vh - var(--topbar-h, 60px));
      background: #f7fafc;
      padding-top: 56px; /* Account for fixed header height */
      position: relative;
      z-index: 1;
      box-sizing: border-box;
    }

    .sidebar.close ~ .home-section {
      margin-left: 70px;
      width: calc(100% - 70px);
    }

    @media (max-width: 768px) {
      .home-section {
        margin-left: 0 !important;
        width: 100% !important;
      }
    }
    
    /* Settings Header */
    .settings-header {
      background: transparent;
      border-bottom: none;
      padding: 0;
      margin-bottom: 2rem;
    }
    
    .settings-header h1 {
      color: #1a202c;
      font-weight: 600;
      font-size: 2rem;
      margin: 0;
    }

    /* YouTube-Style Settings Navigation */
    .settings-nav {
      background: #f7fafc;
      border-radius: 12px;
      padding: 0.5rem;
      border: 1px solid #e2e8f0;
    }

    .settings-nav-item {
      display: flex;
      align-items: center;
      padding: 0.75rem 1rem;
      color: #4a5568;
      text-decoration: none;
      border-radius: 8px;
      font-weight: 500;
      font-size: 0.95rem;
      transition: all 0.2s ease;
      margin-bottom: 0.25rem;
    }

    .settings-nav-item:last-child {
      margin-bottom: 0;
    }

    .settings-nav-item:hover {
      background: #edf2f7;
      color: #2d3748;
      text-decoration: none;
    }

    .settings-nav-item.active {
      background: #4299e1;
      color: white;
    }

    .settings-nav-item.active:hover {
      background: #3182ce;
    }

    /* Settings Content Sections */
    .settings-content-section {
      margin-bottom: 3rem;
    }

    .section-title {
      color: #1a202c;
      font-weight: 600;
      font-size: 1.5rem;
      margin: 0 0 0.5rem 0;
    }

    .section-description {
      color: #718096;
      font-size: 0.95rem;
      margin: 0 0 1.5rem 0;
    }
    
    .content-card {
      background: white;
      border-radius: 12px;
      padding: 2rem;
      box-shadow: 0 1px 3px rgba(0,0,0,0.1);
      border: 1px solid #e2e8f0;
    }
    
    .back-btn {
      background: #f7fafc;
      border: 1px solid #e2e8f0;
      color: #4a5568;
      padding: 0.5rem 1rem;
      border-radius: 8px;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      font-weight: 500;
      transition: all 0.2s ease;
    }
    
    .back-btn:hover {
      background: #edf2f7;
      color: #2d3748;
      text-decoration: none;
    }
    
    /* Settings Section Cards */
    .settings-section {
      background: white;
      border-radius: 12px;
      box-shadow: 0 1px 3px rgba(0,0,0,0.1);
      border: 1px solid #e2e8f0;
      margin-bottom: 2rem;
      overflow: hidden;
    }
    
    .settings-section-header {
      background: #f7fafc;
      padding: 1.5rem;
      border-bottom: 1px solid #e2e8f0;
    }
    
    .settings-section-header h3 {
      color: #2d3748;
      font-weight: 600;
      font-size: 1.25rem;
      margin: 0;
      display: flex;
      align-items: center;
      gap: 0.75rem;
    }
    
    .settings-section-header p {
      color: #718096;
      margin: 0.5rem 0 0 0;
      font-size: 0.95rem;
    }
    
    .settings-section-body {
      padding: 2rem;
    }
    
    .setting-item {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 0;
    }
    
    .setting-info {
      flex: 1;
    }
    
    .setting-label {
      font-weight: 600;
      color: #2d3748;
      font-size: 1rem;
      margin-bottom: 0.25rem;
    }
    
    .setting-value {
      color: #718096;
      font-size: 0.95rem;
      margin-bottom: 0.25rem;
    }
    
    .setting-description {
      color: #a0aec0;
      font-size: 0.875rem;
    }
    
    .setting-actions {
      display: flex;
      gap: 0.75rem;
    }
    
    .btn-setting {
      padding: 0.5rem 1.25rem;
      border-radius: 8px;
      font-weight: 500;
      font-size: 0.9rem;
      border: 1px solid transparent;
      transition: all 0.2s ease;
    }
    
    .btn-setting-primary {
      background: #4299e1;
      color: white;
      border-color: #4299e1;
    }
    
    .btn-setting-primary:hover {
      background: #3182ce;
      border-color: #3182ce;
      color: white;
    }
    
    .btn-setting-danger {
      background: #e53e3e;
      color: white;
      border-color: #e53e3e;
    }
    
    .btn-setting-danger:hover {
      background: #c53030;
      border-color: #c53030;
      color: white;
    }
    
    .btn-setting-outline {
      background: transparent;
      color: #4a5568;
      border-color: #e2e8f0;
    }
    
    .btn-setting-outline:hover {
      background: #f7fafc;
      color: #2d3748;
    }
    
    /* Modal Improvements */
    .modal-content {
      border-radius: 16px;
      border: none;
      box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
    }
    
    .modal-header {
      background: #f7fafc;
      border-bottom: 1px solid #e2e8f0;
      border-radius: 16px 16px 0 0;
      padding: 1.5rem 2rem;
    }
    
    .modal-title {
      font-weight: 600;
      color: #2d3748;
      font-size: 1.25rem;
    }
    
    .modal-body {
      padding: 2rem;
    }
    
    .modal-footer {
      border-top: 1px solid #e2e8f0;
      padding: 1.5rem 2rem;
      background: #f7fafc;
      border-radius: 0 0 16px 16px;
    }
    
    /* Ensure modals appear above sidebar/backdrops */
    .modal { z-index: 1100 !important; position: fixed; pointer-events: auto; }
    .modal-backdrop { z-index: 1095 !important; }
    .modal-backdrop.show { opacity: 0.45 !important; }

    /* When any Bootstrap modal is open, neutralize potential stacking-context creators */
    body.modal-open .home-section,
    body.modal-open #wrapper,
    body.modal-open #wrapper * { transform: none !important; filter: none !important; }

    /* Prevent the student sidebar overlay from intercepting clicks while a modal is open */
    body.modal-open .sidebar-backdrop { pointer-events: none !important; opacity: 0 !important; z-index: 0 !important; }
    
    /* Mobile Responsiveness: Compact modal styling */
    @media (max-width: 767px) {
      .modal-dialog { 
        max-width: 90% !important; 
        margin: 1.5rem auto !important; 
      }
      
      .modal-content { 
        height: auto !important; 
        max-height: 85vh !important; 
        border-radius: 1rem !important; 
        display: flex; 
        flex-direction: column; 
      }
      
      .modal-header { 
        padding: 0.75rem 1rem; 
        position: sticky; 
        top: 0; 
        background: #f8f9fa; 
        z-index: 1; 
        border-radius: 1rem 1rem 0 0; 
        border-bottom: 1px solid #e0e0e0; 
      }
      
      .modal-body { 
        padding: 0.75rem 1rem; 
        overflow-y: auto; 
        max-height: calc(85vh - 140px); 
        flex: 1; 
      }
      
      .modal-footer { 
        padding: 0.75rem 1rem; 
        position: sticky; 
        bottom: 0; 
        background: #fff; 
        z-index: 1; 
        border-top: 1px solid #e0e0e0; 
        flex-shrink: 0; 
      }
      
      .modal-title { 
        font-size: 1rem; 
        line-height: 1.3; 
      }
      
      .modal-title i { 
        font-size: 0.9rem; 
      }
      
      .form-label {
        font-size: 0.9rem;
        margin-bottom: 0.4rem;
      }
      
      .form-control {
        padding: 0.6rem 0.75rem;
        font-size: 0.9rem;
      }
      
      .btn {
        padding: 0.5rem 0.75rem;
        font-size: 0.9rem;
      }
    }
    
    /* Form Styling */
    .form-control {
      border-radius: 8px;
      border: 1px solid #e2e8f0;
      padding: 0.75rem 1rem;
      font-size: 0.95rem;
      transition: all 0.2s ease;
    }
    
    .form-control:focus {
      border-color: #4299e1;
      box-shadow: 0 0 0 3px rgba(66, 153, 225, 0.1);
    }
    
    .form-label {
      font-weight: 600;
      color: #2d3748;
      margin-bottom: 0.5rem;
    }
    
    .verified-indicator { 
      color: #28a745; 
      font-weight: bold; 
    }
    
    .form-error { 
      color: #dc3545; 
      font-size: 0.875rem; 
      font-weight: 500;
      padding: 0.5rem 0.75rem;
      background: #f8d7da;
      border-radius: 6px;
      border-left: 3px solid #dc3545;
    }
    
    .form-success { 
      color: #28a745; 
      font-size: 0.875rem; 
      font-weight: 500;
      padding: 0.5rem 0.75rem;
      background: #d4edda;
      border-radius: 6px;
      border-left: 3px solid #28a745;
    }
    
    /* Hide inline error badges inside password modal (progress bar already provides feedback) */
    #passwordModal .form-error { display: none !important; }
    
    .alert {
      border-radius: 12px;
      padding: 1rem 1.25rem;
      margin-bottom: 1.5rem;
      border: none;
    }
    
    .alert-success {
      background: #c6f6d5;
      color: #22543d;
    }
    
    .alert-danger {
      background: #fed7d7;
      color: #742a2a;
    }
    
    .alert-info {
      background: #bee3f8;
      color: #2a4365;
    }
    
    .alert-warning {
      background: #faf089;
      color: #744210;
    }
    
    /* Responsive */
    @media (max-width: 768px) {
      .settings-header {
        padding: 1rem 0;
      }
      
      .settings-header h1 {
        font-size: 1.5rem;
      }
      
      .setting-item {
        flex-direction: column;
        align-items: flex-start;
        gap: 1rem;
      }
      
      .setting-actions {
        width: 100%;
        justify-content: flex-end;
      }
      
      .settings-section-body {
        padding: 1.5rem;
      }
    }
  </style>
</head>
<body>
  <?php include __DIR__ . '/../../includes/student/student_topbar.php'; ?>
  <div id="wrapper" style="padding-top: var(--topbar-h);">
    <?php include __DIR__ . '/../../includes/student/student_sidebar.php'; ?>
    <?php include __DIR__ . '/../../includes/student/student_header.php'; ?>

    <section class="home-section" id="page-content-wrapper">
      <div class="container-fluid py-4 px-4">
        <!-- Settings Header -->
        <div class="settings-header mb-4">
          <h1 class="mb-1">Settings</h1>
        </div>

        <!-- YouTube-style Layout: Sidebar + Content -->
        <div class="row g-4">
          <!-- Settings Navigation Sidebar -->
          <?php include __DIR__ . '/../../includes/student/settings_sidebar.php'; ?>

          <!-- Main Content -->
          <div class="col-12 col-lg-9">
            <!-- Password Section -->
            <div class="settings-content-section">
              <h2 class="section-title">Password</h2>
              <p class="section-description">Protect your account with a strong password</p>
              
              <!-- Content Card -->
              <div class="content-card">
                <div class="setting-item" id="password">
                  <div class="setting-info">
                    <div class="setting-label">Password</div>
                    <div class="setting-value">••••••••••••</div>
                    <div class="setting-description">Last changed: Recently (secure password required)</div>
                  </div>
                  <div class="setting-actions">
                    <button class="btn btn-setting btn-setting-danger" data-bs-toggle="modal" data-bs-target="#passwordModal">
                      <i class="bi bi-key me-1"></i>Change Password
                    </button>
                  </div>
                </div>
              </div>
            </div>

            <!-- Modals needed for security actions -->
            <div class="modal fade" id="passwordModal" tabindex="-1" aria-hidden="true">
              <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                  <div class="modal-header">
                    <h5 class="modal-title">Change Password</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                  </div>
                  <div class="modal-body">
                    <form id="passwordChangeForm">
                      <div class="mb-3">
                        <label class="form-label">Current Password</label>
                        <input type="password" name="current_password" class="form-control" required>
                      </div>
                      <div class="mb-3">
                        <label class="form-label">New Password</label>
                        <input type="password" name="new_password" class="form-control" required>
                      </div>
                      <div class="mb-3">
                        <label class="form-label">Confirm New Password</label>
                        <input type="password" name="confirm_password" class="form-control" required>
                      </div>
                    </form>
                  </div>
                  <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" id="savePasswordBtn" class="btn btn-primary">Save Password</button>
                  </div>
                </div>
              </div>
            </div>

          </div>
        </div>
      </div>
    </section>
  </div>

  <script src="../../assets/js/bootstrap.bundle.min.js"></script>
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      // FOUC Prevention - show body after load
      document.body.classList.add('ready');
      
      // Ensure password modal is rendered at <body> level to avoid stacking issues
      const passwordModalEl = document.getElementById('passwordModal');
      if (passwordModalEl) {
        passwordModalEl.addEventListener('show.bs.modal', () => {
          if (passwordModalEl.parentElement !== document.body) {
            document.body.appendChild(passwordModalEl);
          }
        });
      }

      // Hook password save
      document.getElementById('savePasswordBtn')?.addEventListener('click', function() {
        // Minimal client-side check
        const form = document.getElementById('passwordChangeForm');
        const newPass = form.querySelector('input[name="new_password"]').value;
        const confirm = form.querySelector('input[name="confirm_password"]').value;
        if (newPass !== confirm) {
          alert('New password and confirmation do not match.');
          return;
        }
        // Submit normally (implementation can use AJAX if desired)
        form.submit();
      });

      // Apply simple animations if user choice saved
      if (localStorage.getItem('simpleAnimations') === 'true') {
        window.applySimpleAnimations && window.applySimpleAnimations();
      }
    });
  </script>
</body>
</html>
