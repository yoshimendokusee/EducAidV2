<?php
// admin_sidebar.php — flat for sub_admin, dropbar for super_admin
// Prevent duplicate inclusion (which caused function redeclare fatal error)
if (defined('ADMIN_SIDEBAR_LOADED')) {
  return; // Stop if sidebar already included
}
define('ADMIN_SIDEBAR_LOADED', true);

include_once __DIR__ . '/../permissions.php';
include_once __DIR__ . '/../workflow_control.php';

$admin_role = 'super_admin'; // fallback
$admin_name = 'Administrator';
$workflow_status = ['can_schedule' => false, 'can_scan_qr' => false];

if (isset($_SESSION['admin_id'])) {
    include_once __DIR__ . '/../../config/database.php';
    $admin_role = getCurrentAdminRole($connection);
    $workflow_status = getWorkflowStatus($connection);
    // Fetch admin name (compose from first + last) – no full_name column assumed
    $nameRes = pg_query_params(
        $connection,
        "SELECT TRIM(BOTH FROM CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) AS display_name FROM admins WHERE admin_id = $1 LIMIT 1",
        [$_SESSION['admin_id']]
    );
    if ($nameRes && ($nameRow = pg_fetch_assoc($nameRes))) {
        $candidate = trim($nameRow['display_name'] ?? '');
        if ($candidate !== '') { $admin_name = $candidate; }
    } elseif (!empty($_SESSION['admin_username'])) {
        $admin_name = $_SESSION['admin_username'];
    }
    
  // Fetch theme settings for sidebar colors if table exists
  $sidebarThemeSettings = [];
  $tableExists = pg_query($connection, "SELECT 1 FROM information_schema.tables WHERE table_name='sidebar_theme_settings' LIMIT 1");
  if ($tableExists && pg_fetch_row($tableExists)) {
    $sidebarThemeQuery = pg_query_params($connection, "SELECT * FROM sidebar_theme_settings WHERE municipality_id = $1 LIMIT 1", [1]);
    if ($sidebarThemeQuery && ($sidebarThemeRow = pg_fetch_assoc($sidebarThemeQuery))) {
      $sidebarThemeSettings = $sidebarThemeRow;
    }
  }
}

$role_label = match($admin_role) {
  'super_admin' => 'Admin',
  'sub_admin', 'admin' => 'Sub Admin',
  default => ucfirst(str_replace('_',' ', $admin_role))
};

$current = basename($_SERVER['PHP_SELF']);
$canSchedule = (bool)($workflow_status['can_schedule'] ?? false);
$canScanQR   = (bool)($workflow_status['can_scan_qr'] ?? false);
$canManageApplicants = (bool)($workflow_status['can_manage_applicants'] ?? false);
$canVerifyStudents = (bool)($workflow_status['can_verify_students'] ?? false);
$canManageSlots = (bool)($workflow_status['can_manage_slots'] ?? false);
$canManageDistributions = (bool)($workflow_status['can_manage_applicants'] ?? false); // Same as manage applicants
$canEndDistribution = (bool)($workflow_status['can_manage_applicants'] ?? false); // Same as manage applicants

/** Helpers */
if (!function_exists('is_active')) {
  function is_active(string $file, string $current): string {
    return $current === $file ? 'active' : '';
  }
}
if (!function_exists('menu_link')) {
  function menu_link(string $href, string $icon, string $label, string $activeClass = '', ?array $badge = null, bool $disabled = false, string $lockedMsg = ''): string {
    $safeMsg = htmlspecialchars($lockedMsg, ENT_QUOTES);
    $aClass  = $disabled ? ' class="text-muted"' : '';
    $aHref   = $disabled ? '#' : $href;
    $aOnclk  = $disabled ? " onclick=\"alert('{$safeMsg}'); return false;\"" : '';

    $html  = '<li class="nav-item ' . ($disabled ? 'disabled ' : '') . $activeClass . '">';
    $html .=   '<a href="' . $aHref . '"' . $aOnclk . $aClass . '>';
    $html .=     '<i class="' . $icon . ' icon"></i>';
    $html .=     '<span class="links_name">' . $label . '</span>';
    if ($badge && !empty($badge['text']) && !empty($badge['class'])) {
      $html .= '<span class="badge ' . $badge['class'] . ' ms-2">' . $badge['text'] . '</span>';
    }
    $html .=   '</a>';
    $html .= '</li>';
    return $html;
  }
}

/** Submenu membership for "Distribution Management" (super_admin) */
$distributionFiles = [
    'distribution_control.php',
    'manage_slots.php',
    'verify_students.php',
    'manage_schedules.php',
    'scan_qr.php',
    'manage_distributions.php',
    'end_distribution.php',
    'distribution_archives.php',
    'storage_dashboard.php',
    'file_browser.php',
];
$isDistributionActive = in_array($current, $distributionFiles, true);

/** Submenu membership for "System Controls" (super_admin) */
$sysControlsFiles = [
    'blacklist_archive.php',
    'archived_students.php',
    'admin_management.php',
    'system_data.php',
    'settings.php',
];
$isSysControlsActive = in_array($current, $sysControlsFiles, true);

/** Submenu membership for "Website CMS" (super_admin) */
$cmsFiles = [
    'municipality_content.php',
    'header_appearance.php',
    'topbar_settings.php',
    'sidebar_settings.php',
    'footer_settings.php',
];
$isCMSActive = in_array($current, $cmsFiles, true);

// Load schedule publish state (for user-friendly gating of Scan QR access)
$settingsPath = __DIR__ . '/../../data/municipal_settings.json';
$settingsData = file_exists($settingsPath) ? json_decode(@file_get_contents($settingsPath), true) : [];
$schedulePublished = !empty($settingsData['schedule_published']);

// Refine Scan QR capability: must have workflow can_scan_qr AND schedule published
$canScanQRPublished = $canScanQR && $schedulePublished;
?>

<!-- admin_sidebar.php -->
<div class="sidebar admin-sidebar" id="sidebar">
  <!-- Clickable Profile Section - Links to Profile Page -->
  <a href="admin_profile.php" class="sidebar-profile-link" role="region" aria-label="View Profile">
    <div class="sidebar-profile">
      <div class="avatar-circle" aria-hidden="true" title="<?= htmlspecialchars($admin_name) ?>">
        <?php $initials = strtoupper(mb_substr($admin_name,0,1)); echo htmlspecialchars($initials); ?>
      </div>
      <div class="profile-text">
        <span class="name" title="<?= htmlspecialchars($admin_name) ?>"><?= htmlspecialchars($admin_name) ?></span>
        <span class="role" title="<?= htmlspecialchars($role_label) ?>"><?= htmlspecialchars($role_label) ?></span>
      </div>
    </div>
  </a>

  <ul class="nav-list flex-grow-1 d-flex flex-column">

    <!-- Dashboard -->
    <?= menu_link('homepage.php', 'bi bi-house-door', 'Dashboard', is_active('homepage.php', $current)); ?>

    <!-- Review Registrations -->
    <?= menu_link('review_registrations.php', 'bi bi-clipboard-check', 'Review Registrations', is_active('review_registrations.php', $current)); ?>

    <!-- Manage Applicants -->
    <?php
      // Render Manage Applicants with a live badge (updated via JavaScript)
      $ma_href = 'manage_applicants.php';
      $ma_active = is_active('manage_applicants.php', $current);
      $ma_html  = '<li class="nav-item ' . $ma_active . '">';
      $ma_html .=   '<a href="' . $ma_href . '">';
      $ma_html .=     '<i class="bi bi-people icon"></i>';
      $ma_html .=     '<span class="links_name">Manage Applicants</span>';
      $ma_html .=     '<span id="manage-applicants-badge" class="badge bg-transparent ms-2" role="status" aria-live="polite"></span>';
      $ma_html .=   '</a>';
      $ma_html .= '</li>';
      echo $ma_html;
    ?>

  <!-- Household Blocked Registrations -->
    <?php
      // Count active blocks (not overridden)
      $blocked_count = 0;
      if (isset($connection)) {
        $countRes = @pg_query($connection, "SELECT COUNT(*) FROM household_block_attempts WHERE admin_override = FALSE");
        if ($countRes) {
          $blocked_count = (int) pg_fetch_result($countRes, 0, 0);
          pg_free_result($countRes);
        }
      }

      if ($blocked_count > 0) {
        $badge = ['text' => (string)$blocked_count, 'class' => 'bg-danger', 'id' => 'hb-badge'];
      } else {
        $badge = ['text' => '', 'class' => 'bg-transparent', 'id' => 'hb-badge'];
      }

      $hb_href = 'household_blocked_registrations.php';
      $hb_active = is_active('household_blocked_registrations.php', $current);
      $visibleLabel = 'Blocked Registrations';
      $tooltipLabel = 'View and Manage Household Blocked Registrations';
      $badgeClass = $badge['class'];
      $badgeText = $badge['text'];
      $badgeIdAttr = ' id="' . htmlspecialchars($badge['id']) . '"';

      $hbHtml  = '<li class="nav-item ' . $hb_active . '">';
      $hbHtml .=   '<a href="' . $hb_href . '" title="' . htmlspecialchars($tooltipLabel) . '" data-bs-toggle="tooltip" data-bs-placement="right" aria-describedby="' . htmlspecialchars($badge['id']) . '">';
      $hbHtml .=     '<i class="bi bi-shield-x icon" aria-hidden="true"></i>';
      $hbHtml .=     '<span class="links_name">' . htmlspecialchars($visibleLabel) . '</span>';
      $hbHtml .=     '<span' . $badgeIdAttr . ' class="badge ' . $badgeClass . '" role="status" aria-live="polite" aria-label="' . htmlspecialchars(($blocked_count>0? $blocked_count . ' blocked registrations' : '')) . '">' . htmlspecialchars($badgeText) . '</span>';
      $hbHtml .=   '</a>';
      $hbHtml .= '</li>';
      echo $hbHtml;
    ?>

    <!-- Distribution Management (super_admin only) -->
    <?php if ($admin_role === 'super_admin'): ?>
      <li class="nav-item dropdown">
        <a href="#submenu-distribution" data-bs-toggle="collapse" class="dropdown-toggle">
          <i class="bi bi-box-seam icon"></i>
          <span class="links_name">Distribution</span>
          <i class="bi bi-chevron-down ms-auto small"></i>
        </a>

        <ul class="collapse list-unstyled ms-3 <?= $isDistributionActive ? 'show' : '' ?>" id="submenu-distribution">
          <li>
            <a class="submenu-link <?= is_active('distribution_control.php', $current) ? 'active' : '' ?>" href="distribution_control.php">
              <i class="bi bi-gear-fill me-2"></i> Distribution Control
            </a>
          </li>
          <li>
            <?php if ($canManageSlots): ?>
              <a class="submenu-link <?= is_active('manage_slots.php', $current) ? 'active' : '' ?>" href="manage_slots.php">
                <i class="bi bi-sliders me-2"></i> Signup Slots
                <span class="badge bg-info ms-2">Ready</span>
              </a>
            <?php else: ?>
              <a class="submenu-link text-muted" href="#"
                 onclick="alert('Please start a distribution first before managing slots.'); return false;">
                <i class="bi bi-sliders me-2"></i> Signup Slots
                <span class="badge bg-secondary ms-2">Locked</span>
              </a>
            <?php endif; ?>
          </li>
          <li>
            <?php if ($canVerifyStudents): ?>
              <a class="submenu-link <?= is_active('verify_students.php', $current) ? 'active' : '' ?>" href="verify_students.php">
                <i class="bi bi-person-check me-2"></i> Verify Students
                <span id="verify-students-badge" class="badge bg-transparent ms-2" role="status" aria-live="polite"></span>
              </a>
            <?php else: ?>
              <a class="submenu-link text-muted" href="#"
                 onclick="alert('Please start a distribution first before verifying students.'); return false;">
                <i class="bi bi-person-check me-2"></i> Verify Students
                <span class="badge bg-secondary ms-2">Locked</span>
              </a>
            <?php endif; ?>
          </li>
          <li>
            <?php if ($canSchedule): ?>
              <a class="submenu-link <?= is_active('manage_schedules.php', $current) ? 'active' : '' ?>" href="manage_schedules.php">
                <i class="bi bi-calendar me-2"></i> Scheduling
                <span class="badge bg-info ms-2">Ready</span>
              </a>
            <?php else: ?>
              <a class="submenu-link text-muted" href="#"
                 onclick="alert('Please generate payroll numbers and QR codes first before scheduling.'); return false;">
                <i class="bi bi-calendar me-2"></i> Scheduling
                <span class="badge bg-secondary ms-2">Locked</span>
              </a>
            <?php endif; ?>
          </li>
          <li>
            <?php if ($canScanQRPublished): ?>
              <a class="submenu-link <?= is_active('scan_qr.php', $current) ? 'active' : '' ?>" href="scan_qr.php">
                <i class="bi bi-qr-code-scan me-2"></i> Scan QR
                <span class="badge bg-success ms-2">Ready</span>
              </a>
            <?php elseif(!$schedulePublished): ?>
              <a class="submenu-link text-muted" href="#" onclick="alert('Schedules are still not published. Please publish the schedule first.'); return false;">
                <i class="bi bi-qr-code-scan me-2"></i> Scan QR
                <span class="badge bg-warning text-dark ms-2">Not Published</span>
              </a>
            <?php else: ?>
              <a class="submenu-link text-muted" href="#" onclick="alert('Please generate payroll numbers and QR codes first before scanning.'); return false;">
                <i class="bi bi-qr-code-scan me-2"></i> Scan QR
                <span class="badge bg-secondary ms-2">Locked</span>
              </a>
            <?php endif; ?>
          </li>
          
          <!-- Divider -->
          <li><hr class="dropdown-divider my-2"></li>
          
          <li>
            <?php if ($canEndDistribution): ?>
              <a class="submenu-link <?= is_active('end_distribution.php', $current) ? 'active' : '' ?>" href="end_distribution.php">
                <i class="bi bi-stop-circle me-2"></i> End Distribution
                <span class="badge bg-info ms-2">Ready</span>
              </a>
            <?php else: ?>
              <a class="submenu-link text-muted" href="#"
                 onclick="alert('Please start a distribution first before ending distribution.'); return false;">
                <i class="bi bi-stop-circle me-2"></i> End Distribution
                <span class="badge bg-secondary ms-2">Locked</span>
              </a>
            <?php endif; ?>
          </li>
          <li>
            <a class="submenu-link <?= is_active('distribution_archives.php', $current) ? 'active' : '' ?>" href="distribution_archives.php">
              <i class="bi bi-archive me-2"></i> Distribution Archives
            </a>
          </li>
          <li>
            <a class="submenu-link <?= is_active('storage_dashboard.php', $current) ? 'active' : '' ?>" href="storage_dashboard.php">
              <i class="bi bi-hdd me-2"></i> Storage Dashboard
            </a>
          </li>
          <li>
            <a class="submenu-link <?= is_active('file_browser.php', $current) ? 'active' : '' ?>" href="file_browser.php">
              <i class="bi bi-folder2-open me-2"></i> File Browser
            </a>
          </li>
        </ul>
      </li>
    <?php endif; ?>

    <!-- Scan QR (sub_admin access) -->
    <?php if ($admin_role === 'sub_admin' || $admin_role === 'admin'): ?>
      <?php if ($canScanQRPublished): ?>
        <?= menu_link('scan_qr.php', 'bi bi-qr-code-scan', 'Scan QR', is_active('scan_qr.php', $current), ['text' => 'Ready', 'class' => 'bg-success']); ?>
      <?php elseif(!$schedulePublished): ?>
        <?= menu_link('#', 'bi bi-qr-code-scan', 'Scan QR', '', ['text' => 'Not Published', 'class' => 'bg-warning text-dark'], true, 'Schedules are still not published. Please publish the schedule first.'); ?>
      <?php else: ?>
        <?= menu_link('#', 'bi bi-qr-code-scan', 'Scan QR', '', ['text' => 'Locked', 'class' => 'bg-secondary'], true, 'Please generate payroll numbers and QR codes first before scanning.'); ?>
      <?php endif; ?>
    <?php endif; ?>

    <!-- System Controls (super_admin only) -->
    <?php if ($admin_role === 'super_admin'): ?>
  <li class="nav-item dropdown">
        <a href="#submenu-sys" data-bs-toggle="collapse" class="dropdown-toggle">
          <i class="bi bi-gear-wide-connected icon"></i>
          <span class="links_name">System Controls</span>
          <i class="bi bi-chevron-down ms-auto small"></i>
        </a>

  <ul class="collapse list-unstyled ms-3 <?= $isSysControlsActive ? 'show' : '' ?>" id="submenu-sys">
          <li>
            <a class="submenu-link <?= is_active('blacklist_archive.php', $current) ? 'active' : '' ?>" href="blacklist_archive.php">
              <i class="bi bi-person-x-fill me-2"></i> Blacklist Archive
            </a>
          </li>
          <li>
            <a class="submenu-link <?= is_active('archived_students.php', $current) ? 'active' : '' ?>" href="archived_students.php">
              <i class="bi bi-archive-fill me-2"></i> Archived Students
            </a>
          </li>
          <li>
            <a class="submenu-link <?= is_active('admin_management.php', $current) ? 'active' : '' ?>" href="admin_management.php">
              <i class="bi bi-people-fill me-2"></i> Admin Management
            </a>
          </li>
          <li>
            <a class="submenu-link <?= is_active('system_data.php', $current) ? 'active' : '' ?>" href="system_data.php">
              <i class="bi bi-database me-2"></i> System Data
            </a>
          </li>
          <li>
            <a class="submenu-link <?= is_active('settings.php', $current) ? 'active' : '' ?>" href="settings.php">
              <i class="bi bi-gear me-2"></i> Settings
            </a>
          </li>
        </ul>
      </li>
    <?php endif; ?>

    <!-- Website CMS (super_admin only) - Hidden on mobile and tablet -->
    <?php if ($admin_role === 'super_admin'): ?>
      <li class="nav-item dropdown cms-section d-none d-lg-block">
        <a href="#submenu-cms" data-bs-toggle="collapse" class="dropdown-toggle">
          <i class="bi bi-palette icon"></i>
          <span class="links_name">Website CMS</span>
          <i class="bi bi-chevron-down ms-auto small"></i>
        </a>

        <ul class="collapse list-unstyled ms-3 <?= $isCMSActive ? 'show' : '' ?>" id="submenu-cms">
          <li>
            <a class="submenu-link <?= is_active('municipality_content.php', $current) ? 'active' : '' ?>" href="municipality_content.php">
              <i class="bi bi-building me-2"></i> Municipality Branding
            </a>
          </li>
          <li>
            <a class="submenu-link <?= is_active('header_appearance.php', $current) ? 'active' : '' ?>" href="header_appearance.php">
              <i class="bi bi-layout-three-columns me-2"></i> Header Appearance
            </a>
          </li>
          <li>
            <a class="submenu-link <?= is_active('topbar_settings.php', $current) ? 'active' : '' ?>" href="topbar_settings.php">
              <i class="bi bi-layout-text-window-reverse me-2"></i> Topbar Settings
            </a>
          </li>
          <li>
            <a class="submenu-link <?= is_active('sidebar_settings.php', $current) ? 'active' : '' ?>" href="sidebar_settings.php">
              <i class="bi bi-layout-sidebar me-2"></i> Sidebar Settings
            </a>
          </li>
          <li>
            <a class="submenu-link <?= is_active('footer_settings.php', $current) ? 'active' : '' ?>" href="footer_settings.php">
              <i class="bi bi-layout-text-sidebar-reverse me-2"></i> Footer Settings
            </a>
          </li>
        </ul>
      </li>
    <?php endif; ?>

    <!-- Announcements -->
    <?php if ($admin_role === 'super_admin'): ?>
      <?= menu_link('manage_announcements.php', 'bi bi-megaphone', 'Announcements', is_active('manage_announcements.php', $current)); ?>
    <?php endif; ?>

    <!-- Audit Trail (super_admin only) -->
    <?php if ($admin_role === 'super_admin'): ?>
      <?= menu_link('audit_logs.php', 'bi bi-shield-lock-fill', 'Audit Trail', is_active('audit_logs.php', $current)); ?>
    <?php endif; ?>

    <!-- Reports & Analytics (super_admin only) -->
    <?php if ($admin_role === 'super_admin'): ?>
      <?= menu_link('reports.php', 'bi bi-file-earmark-bar-graph-fill', 'Reports & Analytics', is_active('reports.php', $current)); ?>
    <?php endif; ?>

    <!-- Filler flex spacer -->
    <li class="mt-auto p-0 m-0"></li>

    <!-- Logout at bottom -->
    <li class="nav-item logout mt-2 pt-1">
      <a href="#" data-bs-toggle="modal" data-bs-target="#logoutModal" class="logout-link">
        <i class="bi bi-box-arrow-right icon"></i>
        <span class="links_name">Logout</span>
      </a>
    </li>
  </ul>
</div>

<div class="sidebar-backdrop d-none" id="sidebar-backdrop"></div>

<!-- Logout Confirmation Modal -->
<div class="modal fade" id="logoutModal" tabindex="-1" aria-labelledby="logoutModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-scrollable" style="margin-top: 80px;">
    <div class="modal-content" style="border-radius: 16px; border: none; box-shadow: 0 10px 40px rgba(0,0,0,0.2);">
      <div class="modal-header border-0" style="background: linear-gradient(135deg, #0d6efd 0%, #0b5ed7 100%); color: white; padding: 24px 28px; border-radius: 16px 16px 0 0;">
        <div class="d-flex align-items-center gap-3">
          <div style="width: 48px; height: 48px; background: rgba(255,255,255,0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center; backdrop-filter: blur(10px);">
            <i class="bi bi-box-arrow-right" style="font-size: 24px;"></i>
          </div>
          <div>
            <h5 class="modal-title mb-0" id="logoutModalLabel" style="font-weight: 700; font-size: 1.25rem;">Logout Confirmation</h5>
            <small style="opacity: 0.9;">Admin Session</small>
          </div>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" style="padding: 28px;">
        <div class="d-flex align-items-start gap-3 mb-3">
          <div style="width: 40px; height: 40px; background: #fef3c7; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
            <i class="bi bi-exclamation-triangle-fill" style="color: #f59e0b; font-size: 20px;"></i>
          </div>
          <div>
            <p class="mb-2" style="color: #1f2937; font-weight: 500;">You are about to logout from the admin panel.</p>
            <p class="mb-0" style="color: #6b7280; font-size: 0.9rem;">Your session data will be cleared and you will need to log in again to access the system.</p>
          </div>
        </div>
      </div>
      <div class="modal-footer border-0" style="padding: 0 28px 24px; gap: 12px;">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal" style="border-radius: 10px; padding: 10px 20px; font-weight: 600; border: 2px solid #e5e7eb;">
          <i class="bi bi-x-circle me-1"></i> Cancel
        </button>
        <a href="logout.php" class="btn" style="background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); color: white; border: none; border-radius: 10px; padding: 10px 20px; font-weight: 600;">
          <i class="bi bi-box-arrow-right me-1"></i> Yes, Logout
        </a>
      </div>
    </div>
  </div>
</div>

<script>
// Live badge for Review Registrations (injected client-side; does NOT change server-side UI)
document.addEventListener('DOMContentLoaded', function() {
  try {
    // Find the Review Registrations anchor inside the sidebar
    const sidebar = document.getElementById('sidebar');
    if (!sidebar) return;
    const anchors = sidebar.querySelectorAll('a[href]');
    let reviewAnchor = null;
    anchors.forEach(a => {
      const href = a.getAttribute('href') || '';
      // match exact filename or path ending with review_registrations.php
      if (href.endsWith('review_registrations.php') || href.includes('/review_registrations.php')) {
        reviewAnchor = a;
      }
    });
    if (!reviewAnchor) return;

    // Create badge element only if not already present
    const badgeId = 'review-pending-badge';
    let badgeEl = reviewAnchor.querySelector('#' + badgeId);
    if (!badgeEl) {
      badgeEl = document.createElement('span');
      badgeEl.id = badgeId;
      badgeEl.className = 'badge bg-transparent ms-2';
      badgeEl.setAttribute('role', 'status');
      badgeEl.setAttribute('aria-live', 'polite');
      badgeEl.textContent = '';
      reviewAnchor.appendChild(badgeEl);
    }

    // Polling function to update count
    const apiUrl = 'review_registrations.php?api=badge_count';
    async function updateBadge() {
      try {
        const res = await fetch(apiUrl, {cache: 'no-store'});
        if (!res.ok) return;
        const data = await res.json();
        const count = parseInt(data.count || 0, 10) || 0;
        if (count > 0) {
          badgeEl.textContent = count;
          badgeEl.classList.remove('bg-transparent');
          badgeEl.classList.remove('bg-secondary');
          badgeEl.classList.add(count > 9 ? 'bg-danger' : 'bg-warning');
        } else {
          badgeEl.textContent = '';
          badgeEl.classList.remove('bg-danger');
          badgeEl.classList.remove('bg-warning');
          badgeEl.classList.add('bg-transparent');
        }
      } catch (e) {
        // silent
      }
    }

    // initial update shortly after load and then every 90s (increased from 45s)
    let badgePollInterval;
    
    function startBadgePolling() {
      if (badgePollInterval) clearInterval(badgePollInterval);
      badgePollInterval = setInterval(() => {
        // Only poll if page is visible
        if (!document.hidden) {
          updateBadge();
        }
      }, 90000);
    }
    
    setTimeout(() => {
      updateBadge();
      startBadgePolling();
    }, 300);
    
    // Pause polling when page is hidden
    document.addEventListener('visibilitychange', () => {
      if (!document.hidden) {
        updateBadge();
        startBadgePolling();
      } else if (badgePollInterval) {
        clearInterval(badgePollInterval);
      }
    });
  } catch (err) {
    console.debug('review badge init error', err);
  }
});
</script>

<!-- Live badge for Manage Applicants (client-side polling) -->
<script>
document.addEventListener('DOMContentLoaded', function() {
  try {
    const sidebar = document.getElementById('sidebar');
    if (!sidebar) return;
    
    // Find badge element by ID (already rendered in HTML)
    const badgeEl = document.getElementById('manage-applicants-badge');
    if (!badgeEl) return;

    // Polling function to update count
    const apiUrl = 'manage_applicants.php?api=badge_count';
    async function updateBadge() {
      try {
        const res = await fetch(apiUrl, {cache: 'no-store'});
        if (!res.ok) return;
        const data = await res.json();
        const count = parseInt(data.count || 0, 10) || 0;
        if (count > 0) {
          badgeEl.textContent = count;
          badgeEl.classList.remove('bg-transparent', 'bg-secondary');
          badgeEl.classList.add(count > 9 ? 'bg-danger' : 'bg-info');
          badgeEl.setAttribute('aria-label', count + ' pending applicants');
        } else {
          badgeEl.textContent = '';
          badgeEl.classList.remove('bg-danger', 'bg-info');
          badgeEl.classList.add('bg-transparent');
          badgeEl.setAttribute('aria-label', '');
        }
      } catch (e) {
        // silent
      }
    }

    // initial update shortly after load and then every 90s
    let badgePollInterval;
    
    function startBadgePolling() {
      if (badgePollInterval) clearInterval(badgePollInterval);
      badgePollInterval = setInterval(() => {
        // Only poll if page is visible
        if (!document.hidden) {
          updateBadge();
        }
      }, 90000);
    }
    
    setTimeout(() => {
      updateBadge();
      startBadgePolling();
    }, 500); // Slightly delayed to avoid race with review badge
    
    // Pause polling when page is hidden
    document.addEventListener('visibilitychange', () => {
      if (!document.hidden) {
        updateBadge();
        startBadgePolling();
      } else if (badgePollInterval) {
        clearInterval(badgePollInterval);
      }
    });
  } catch (err) {
    console.debug('manage applicants badge init error', err);
  }
});
</script>

<!-- Live badge for Verify Students (client-side polling) -->
<script>
document.addEventListener('DOMContentLoaded', function() {
  try {
    const sidebar = document.getElementById('sidebar');
    if (!sidebar) return;
    
    // Find badge element by ID (already rendered in HTML)
    const badgeEl = document.getElementById('verify-students-badge');
    if (!badgeEl) return;

    // Polling function to update count
    const apiUrl = 'verify_students.php?api=badge_count';
    async function updateBadge() {
      try {
        const res = await fetch(apiUrl, {cache: 'no-store'});
        if (!res.ok) return;
        const data = await res.json();
        const count = parseInt(data.count || 0, 10) || 0;
        if (count > 0) {
          badgeEl.textContent = count;
          badgeEl.classList.remove('bg-transparent', 'bg-secondary');
          badgeEl.classList.add(count > 9 ? 'bg-danger' : 'bg-success');
          badgeEl.setAttribute('aria-label', count + ' active students to verify');
        } else {
          badgeEl.textContent = '';
          badgeEl.classList.remove('bg-danger', 'bg-success');
          badgeEl.classList.add('bg-transparent');
          badgeEl.setAttribute('aria-label', '');
        }
      } catch (e) {
        // silent
      }
    }

    // initial update shortly after load and then every 90s
    let badgePollInterval;
    
    function startBadgePolling() {
      if (badgePollInterval) clearInterval(badgePollInterval);
      badgePollInterval = setInterval(() => {
        // Only poll if page is visible
        if (!document.hidden) {
          updateBadge();
        }
      }, 90000);
    }
    
    setTimeout(() => {
      updateBadge();
      startBadgePolling();
    }, 700); // Slightly delayed to avoid race with other badges
    
    // Pause polling when page is hidden
    document.addEventListener('visibilitychange', () => {
      if (!document.hidden) {
        updateBadge();
        startBadgePolling();
      } else if (badgePollInterval) {
        clearInterval(badgePollInterval);
      }
    });
  } catch (err) {
    console.debug('verify students badge init error', err);
  }
});
</script>

<style>
<?php
// Dynamic sidebar theming using dedicated sidebar theme settings
$sidebarBgStart = $sidebarThemeSettings['sidebar_bg_start'] ?? '#f8f9fa';
$sidebarBgEnd = $sidebarThemeSettings['sidebar_bg_end'] ?? '#ffffff';
$sidebarBorder = $sidebarThemeSettings['sidebar_border_color'] ?? '#dee2e6';
$navTextColor = $sidebarThemeSettings['nav_text_color'] ?? '#212529';
$navIconColor = $sidebarThemeSettings['nav_icon_color'] ?? '#6c757d';
$navHoverBg = $sidebarThemeSettings['nav_hover_bg'] ?? '#e9ecef';
$navHoverText = $sidebarThemeSettings['nav_hover_text'] ?? '#212529';
$navActiveBg = $sidebarThemeSettings['nav_active_bg'] ?? '#0d6efd';
$navActiveText = $sidebarThemeSettings['nav_active_text'] ?? '#ffffff';
$profileAvatarStart = $sidebarThemeSettings['profile_avatar_bg_start'] ?? '#0d6efd';
$profileAvatarEnd = $sidebarThemeSettings['profile_avatar_bg_end'] ?? '#0b5ed7';
$profileNameColor = $sidebarThemeSettings['profile_name_color'] ?? '#212529';
$profileRoleColor = $sidebarThemeSettings['profile_role_color'] ?? '#6c757d';
$profileBorderColor = $sidebarThemeSettings['profile_border_color'] ?? '#dee2e6';
$submenuBg = $sidebarThemeSettings['submenu_bg'] ?? '#f8f9fa';
$submenuTextColor = $sidebarThemeSettings['submenu_text_color'] ?? '#495057';
$submenuHoverBg = $sidebarThemeSettings['submenu_hover_bg'] ?? '#e9ecef';
$submenuActiveBg = $sidebarThemeSettings['submenu_active_bg'] ?? '#e7f3ff';
$submenuActiveText = $sidebarThemeSettings['submenu_active_text'] ?? '#0d6efd';

// Function to adjust color opacity for subtle effects
if (!function_exists('adjustColorOpacity')) {
    function adjustColorOpacity($color, $opacity = 0.3) {
        $color = str_replace('#', '', $color);
        if (strlen($color) === 3) {
            $color = $color[0].$color[0].$color[1].$color[1].$color[2].$color[2];
        }
        $r = hexdec(substr($color, 0, 2));
        $g = hexdec(substr($color, 2, 2));
        $b = hexdec(substr($color, 4, 2));
        return "rgba($r, $g, $b, $opacity)";
    }
}
?>
.admin-sidebar {
    background: linear-gradient(180deg, <?= htmlspecialchars($sidebarBgStart) ?> 0%, <?= htmlspecialchars($sidebarBgEnd) ?> 100%);
    border-right: 1px solid <?= htmlspecialchars($sidebarBorder) ?>;
}
 .admin-sidebar .nav-item a {
    display: flex;
    align-items: center;
    gap: .6rem;
    border-radius: 10px;
    margin: 2px 12px;
    padding: 10px 14px;
    font-size: .8rem;
    font-weight: 500;
    color: <?= htmlspecialchars($navTextColor) ?>;
    /* inline flex layout keeps badge inside its own anchor so it can't visually overlap other items */
    overflow: visible;   /* avoid clipping badge with border-radius */
}
/* Slightly smaller label text to avoid wrapping and fit narrow sidebars */
.admin-sidebar .links_name { font-size: .8rem; line-height:1.1; flex: 1 1 auto; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.admin-sidebar .nav-item a .icon {
    color: <?= htmlspecialchars($navIconColor) ?>;
    transition: .2s;
    font-size: 1.1rem;
}
.admin-sidebar .nav-item a:hover {
    background: <?= htmlspecialchars($navHoverBg) ?>;
    color: <?= htmlspecialchars($navHoverText) ?>;
}
.admin-sidebar .nav-item a:hover .icon {
    color: <?= htmlspecialchars($navHoverText) ?>;
}
.admin-sidebar .nav-item.active > a {
    background: <?= htmlspecialchars($navActiveBg) ?>;
    color: <?= htmlspecialchars($navActiveText) ?>;
    box-shadow: 0 2px 4px rgba(0,0,0,.15);
}
.admin-sidebar .nav-item.active > a .icon {
    color: <?= htmlspecialchars($navActiveText) ?>;
}
.admin-sidebar .nav-item.active > a::before {
    background: <?= htmlspecialchars($navActiveBg) ?>;
}
.admin-sidebar .dropdown > a {
    display: flex;
    align-items: center;
    gap: .55rem;
    margin: 4px 12px;
    padding: 10px 14px;
    border-radius: 10px;
}
.admin-sidebar .submenu-link {
    display: flex;
    align-items: center;
    padding: .4rem .75rem .4rem 2.1rem;
    margin: 2px 0;
    border-radius: 8px;
    font-size: .8rem;
    color: <?= htmlspecialchars($submenuTextColor) ?>;
}
.admin-sidebar .submenu-link.active {
    background: <?= htmlspecialchars($submenuActiveBg) ?>;
    font-weight: 600;
    color: <?= htmlspecialchars($submenuActiveText) ?>;
}
.admin-sidebar .submenu-link:hover {
    background: <?= htmlspecialchars($submenuHoverBg) ?>;
    color: <?= htmlspecialchars($submenuTextColor) ?>;
}
.admin-sidebar .submenu-link .bi {
    width: 1.05rem;
    text-align: center;
    font-size: .9rem;
}
.admin-sidebar .nav-item.logout a.logout-link {
    background: #ffebee;
    color: #c62828;
    border: 1px solid #ffcdd2;
    margin: 4px 12px 6px;
    padding: 10px 14px;
    border-radius: 10px;
    font-weight: 600;
    display: flex;
    align-items: center;
}
.admin-sidebar .nav-item.logout a.logout-link:hover {
    background: #ffcdd2;
    color: #b71c1c;
}
/* Extended Tablet / Medium Desktop: Hide CMS section up to 1400px per new requirement */
@media (min-width: 768px) and (max-width: 1400px) {
    .dropdown.cms-section,
    #submenu-cms {
        display: none !important;
    }
    
    .admin-sidebar .nav-item a { margin: 3px 10px; padding: 11px 14px; }
    .admin-sidebar .dropdown > a { margin: 4px 10px; }
    .admin-sidebar .nav-item.logout a.logout-link { margin: 6px 10px 8px; }
}

@media (max-width: 767.98px) {
    /* Mobile: Also hide CMS section */
    .dropdown.cms-section,
    #submenu-cms {
        display: none !important;
    }
    
    .admin-sidebar .nav-item a { margin: 2px 8px; }
    .admin-sidebar .dropdown > a { margin: 4px 8px; }
    .admin-sidebar .nav-item.logout a.logout-link { margin: 6px 8px 8px; }
}
/* Badge positioning and gap tuning */
.admin-sidebar .nav-item a .badge {
  /* Use inline placement in the flex row so the badge is always inside its anchor
     and pushes to the right using auto margin. This prevents overlap with siblings. */
  position: static;
  margin-left: auto;
  z-index: 5;
  min-width: 20px;
  height: 20px;
  padding: 0 6px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: .75rem;
  border-radius: 999px;
}
@media (max-width:992px) {
  .admin-sidebar .nav-item a { padding-right: 30px; }
  .admin-sidebar .nav-item a .badge { right: 8px; }
}
/* Collapsed sidebar: nudge badge closer to icon so it remains visible */
.admin-sidebar.close .nav-item a { padding-right: 14px; }
.admin-sidebar.close .nav-item a .badge { margin-left: 0; }
/* Collapse behavior for submenus when sidebar collapsed */
.admin-sidebar.close #submenu-sys,
.admin-sidebar.close #submenu-distribution { 
    display: none !important; 
}
.admin-sidebar.close .dropdown > a { 
    background: transparent; 
}
.admin-sidebar.close .dropdown > a .bi-chevron-down { 
    display: none; 
}
/* Profile block */
.admin-sidebar .sidebar-profile {
    display: flex;
    align-items: center;
    gap: .75rem;
    padding: 0 1rem 1rem 1rem;
    margin-bottom: .35rem;
    border-bottom: 1px solid <?= adjustColorOpacity($profileBorderColor, 0.4) ?>;
}
.admin-sidebar.close .sidebar-profile .profile-text { display: none; }
.admin-sidebar .sidebar-profile .avatar-circle {
    width: 42px;
    height: 42px;
    border-radius: 50%;
    background: linear-gradient(135deg, <?= htmlspecialchars($profileAvatarStart) ?>, <?= htmlspecialchars($profileAvatarEnd) ?>);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 1rem;
    box-shadow: 0 2px 4px rgba(0,0,0,.15);
}
.admin-sidebar .sidebar-profile .profile-text {
    display: flex;
    flex-direction: column;
    line-height: 1.1;
    min-width: 0;
}
.admin-sidebar .sidebar-profile .profile-text .name {
    font-size: .9rem;
    font-weight: 600;
    color: <?= htmlspecialchars($profileNameColor) ?>;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 140px;
}
.admin-sidebar .sidebar-profile .profile-text .role {
    font-size: .6rem;
    letter-spacing: .75px;
    text-transform: uppercase;
    color: <?= htmlspecialchars($profileRoleColor) ?>;
    font-weight: 600;
    opacity: .85;
}
/* ================= Tablet/Mobile spacing & safe-area fixes ================= */
@media (max-width: 992px) {
  /* Ensure nav items start directly under profile without large gap if global CSS sets space-between */
  .admin-sidebar .nav-list { justify-content: flex-start !important; padding-top: .25rem; }
  /* Compact profile padding */
  .admin-sidebar .sidebar-profile { padding: .65rem .9rem .7rem .9rem; margin-bottom: .2rem; }
  /* Slightly smaller avatar for narrow devices */
  .admin-sidebar .sidebar-profile .avatar-circle { width: 38px; height: 38px; font-size: .9rem; }
  /* Reduce horizontal margins on items */
  .admin-sidebar .nav-item a, .admin-sidebar .dropdown > a { margin: 2px 6px; padding: 9px 12px; }
  /* Provide bottom safe-area on the actual scroller so content can scroll past the home indicator */
  .admin-sidebar .nav-list { padding-bottom: calc(env(safe-area-inset-bottom, 0px) + 72px) !important; }
  /* Remove flex spacer on mobile; sticky logout handles pinning */
  .admin-sidebar .nav-list > li.mt-auto { display: none !important; }
  /* Make logout sticky just above safe area */
  .admin-sidebar .nav-item.logout { position: sticky; bottom: calc(env(safe-area-inset-bottom, 0px) + 4px); z-index: 10; }
  .admin-sidebar .nav-item.logout a.logout-link { margin-bottom: 0; }
}
/* Ultra-small devices (<380px) tighten further */
@media (max-width: 380px) {
  .admin-sidebar .nav-item a, .admin-sidebar .dropdown > a { padding: 8px 11px; }
  .admin-sidebar .sidebar-profile .profile-text .name { max-width: 110px; }
}
</style>

<script>
// Auto-hide dropdowns when sidebar collapses; restore if active when expanded
document.addEventListener('DOMContentLoaded', function(){
  const sidebar = document.getElementById('sidebar');
  const sysMenu = document.getElementById('submenu-sys');
  const distMenu = document.getElementById('submenu-distribution');
  if(!sidebar) return;
  
  function hasActiveChild(menu){
    if(!menu) return false;
    return !!menu.querySelector('.submenu-link.active');
  }
  
  function syncMenus(){
    if(sidebar.classList.contains('close')){
      // Hide all submenus when collapsed
      if(sysMenu) sysMenu.classList.remove('show');
      if(distMenu) distMenu.classList.remove('show');
    } else {
      // Show submenus with active children when expanded
      if(sysMenu && hasActiveChild(sysMenu) && !sysMenu.classList.contains('show')) {
        sysMenu.classList.add('show');
      }
      if(distMenu && hasActiveChild(distMenu) && !distMenu.classList.contains('show')) {
        distMenu.classList.add('show');
      }
    }
  }
  
  // Observe class changes (JS animation toggles .close)
  const observer = new MutationObserver(syncMenus);
  observer.observe(sidebar,{attributes:true, attributeFilter:['class']});
  syncMenus();
});
</script>

<script>
// Admin sidebar open/close behavior (mobile + desktop)
document.addEventListener('DOMContentLoaded', function(){
  // Prevent double-binding with external controller (assets/js/admin/sidebar.js)
  if (window.__ADMIN_SIDEBAR_BOUND) {
    // Another sidebar controller already attached; skip binding here
    return;
  }
  window.__ADMIN_SIDEBAR_BOUND = 'inline';
  const sidebar = document.getElementById('sidebar');
  const toggleBtn = document.getElementById('menu-toggle');
  const backdrop = document.getElementById('sidebar-backdrop');
  const header = document.querySelector('.admin-main-header');
  const homeSection = document.querySelector('.home-section') || document.getElementById('mainContent');
  if(!sidebar || !toggleBtn) return;

  const isMobile = () => window.innerWidth <= 992;

  // Align header/content offsets with sidebar width on desktop
  const adjustLayout = () => {
    if(!header) return;
    if(isMobile()){
      header.style.left = '0px';
      if (homeSection) { homeSection.style.marginLeft = '0px'; homeSection.style.width = '100%'; }
      return;
    }
    const closed = sidebar.classList.contains('close');
    const left = closed ? 70 : 250;
    header.style.left = left + 'px';
    if (homeSection) { homeSection.style.marginLeft = ''; homeSection.style.width = ''; }
  };

  function updateSidebarState(){
    // On mobile, sidebar state is always "open" only when toggled, never saved
    if(isMobile()){
      sidebar.classList.remove('open','close');
      if(backdrop){ backdrop.classList.add('d-none'); }
      document.body.style.overflow = '';
      if (homeSection) homeSection.classList.remove('expanded');
      adjustLayout();
    } else {
      // Desktop: remember state in localStorage
      const state = localStorage.getItem('adminSidebarState');
      if(state === 'closed'){
        sidebar.classList.add('close');
        if (homeSection) homeSection.classList.remove('expanded');
      } else {
        sidebar.classList.remove('close');
        if (homeSection) homeSection.classList.add('expanded');
      }
      sidebar.classList.remove('open');
      if(backdrop){ backdrop.classList.add('d-none'); }
      document.body.style.overflow = '';
      adjustLayout();
    }
  }

  // On page load, set sidebar state
  updateSidebarState();

  // === JS Animation for both desktop and mobile ===
  let sidebarAnimFrame = null;
  let backdropAnimFrame = null;
  let sidebarAnimating = false;

  function animateSidebar(expand) {
    if (sidebarAnimating) {
      cancelAnimationFrame(sidebarAnimFrame);
      if (backdropAnimFrame) cancelAnimationFrame(backdropAnimFrame);
      sidebarAnimating = false;
    }

    if (isMobile()) {
      // Mobile: JS-driven animation
      if (expand) {
        sidebar.classList.add('open');
        sidebar.classList.remove('close');
        if(backdrop) backdrop.classList.remove('d-none');
        document.body.style.overflow = 'hidden';
        
        sidebarAnimating = true;
        const startTime = performance.now();
        const duration = 350;
        
        function slideIn(now){
          const elapsed = now - startTime;
          const progress = Math.min(1, elapsed / duration);
          const eased = 1 - Math.pow(1 - progress, 3); // easeOutCubic
          
          const translateX = -100 + (eased * 100); // -100% to 0%
          sidebar.style.transform = `translateX(${translateX}%)`;
          if(backdrop) backdrop.style.opacity = eased;
          
          if(progress < 1){
            sidebarAnimFrame = requestAnimationFrame(slideIn);
          } else {
            sidebar.style.transform = '';
            sidebarAnimating = false;
          }
        }
        requestAnimationFrame(slideIn);
      } else {
        sidebar.classList.remove('open');
        sidebar.classList.add('close');
        document.body.style.overflow = '';
        
        sidebarAnimating = true;
        const startTime = performance.now();
        const duration = 350;
        
        function slideOut(now){
          const elapsed = now - startTime;
          const progress = Math.min(1, elapsed / duration);
          const eased = 1 - Math.pow(1 - progress, 3); // easeOutCubic
          
          const translateX = -(eased * 100); // 0% to -100%
          sidebar.style.transform = `translateX(${translateX}%)`;
          if(backdrop) backdrop.style.opacity = 1 - eased;
          
          if(progress < 1){
            sidebarAnimFrame = requestAnimationFrame(slideOut);
          } else {
            sidebar.style.transform = '';
            if(backdrop){
              backdrop.classList.add('d-none');
              backdrop.style.opacity = '';
            }
            sidebarAnimating = false;
          }
        }
        requestAnimationFrame(slideOut);
      }
      
      adjustLayout();
      return;
    }

    // Desktop animation
    const startWidth = sidebar.offsetWidth;
    const targetWidth = expand ? 250 : 70;
    const startTime = performance.now();
    const duration = 220;

    if (!expand) {
      sidebar.classList.remove("close");
    }

    sidebarAnimating = true;

    function easeOutQuad(t) { return 1 - (1 - t) * (1 - t); }

    function step(now) {
      const elapsed = now - startTime;
      const progress = Math.min(1, elapsed / duration);
      const eased = easeOutQuad(progress);
      const current = Math.round(startWidth + (targetWidth - startWidth) * eased);
      sidebar.style.width = current + 'px';

      if (header && !isMobile()) header.style.left = current + 'px';
      if (homeSection && !isMobile()) {
        homeSection.style.marginLeft = current + 'px';
        homeSection.style.width = `calc(100% - ${current}px)`;
      }

      if (progress < 1) {
        sidebarAnimFrame = requestAnimationFrame(step);
      } else {
        sidebarAnimating = false;
        sidebar.style.width = '';
        if (expand) {
          sidebar.classList.remove("close");
          localStorage.setItem("adminSidebarState", "open");
          if (homeSection) homeSection.classList.add("expanded");
        } else {
          sidebar.classList.add("close");
          localStorage.setItem("adminSidebarState", "closed");
          if (homeSection) homeSection.classList.remove("expanded");
        }
        if (homeSection) {
          homeSection.style.marginLeft = '';
          homeSection.style.width = '';
        }
        adjustLayout();
      }
    }

    requestAnimationFrame(step);
  }

  toggleBtn.addEventListener('click', function(e){
    e.stopPropagation();
    // On mobile: toggle based on 'open' class; On desktop: toggle based on 'close' class
    const expanding = isMobile() ? !sidebar.classList.contains('open') : sidebar.classList.contains('close');
    animateSidebar(expanding);
  });

  if(backdrop){
    backdrop.addEventListener('click', function(){
      animateSidebar(false);
    });
  }

  // Hide sidebar on mobile when clicking outside of it
  document.addEventListener('click', function(e){
    if(isMobile() && sidebar.classList.contains('open')){
      const isClickInside = sidebar.contains(e.target) || toggleBtn.contains(e.target);
      if(!isClickInside){
        animateSidebar(false);
      }
    }
  });

  // Always update sidebar state on resize to keep in sync
  window.addEventListener('resize', () => { updateSidebarState(); });
  
  document.addEventListener('keydown', function(e){
    if(isMobile() && e.key === 'Escape' && sidebar.classList.contains('open')){
      animateSidebar(false);
    }
  });
});
</script>

</style>
<!-- No longer needed - Edit Landing Page moved to Content Areas -->
<script>
// Legacy modal functionality removed
// Content editing now handled through Municipality Content Hub
</script>
