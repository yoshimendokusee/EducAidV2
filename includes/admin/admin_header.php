<?php
// Admin Header with dynamic theming
// Requires: $_SESSION['admin_username']
$adminDisplay = htmlspecialchars($_SESSION['admin_username'] ?? 'Admin');

// Get unread notification count and recent notifications for dropdown
include_once __DIR__ . '/../../config/database.php';
$unreadNotificationCount = 0;
$recentNotifications = [];

if (isset($connection)) {
  // Normalize any lingering NULL values quietly (one-time safety) so logic stays consistent
  @pg_query($connection, "UPDATE admin_notifications SET is_read = FALSE WHERE is_read IS NULL");

  // Get unread count (only is_read = FALSE). MUST match API logic.
  $notifCountQuery = "SELECT COUNT(*) as count FROM admin_notifications WHERE is_read = FALSE";
  $notifCountResult = pg_query($connection, $notifCountQuery);
  if ($notifCountResult) {
    $notifCountRow = pg_fetch_assoc($notifCountResult);
    $unreadNotificationCount = (int)$notifCountRow['count'];
  }
    
    // Get recent notifications for dropdown (last 5, prioritizing unread)
    $recentNotifQuery = "
        SELECT admin_notification_id, message, created_at, is_read 
        FROM admin_notifications 
        ORDER BY 
            CASE WHEN is_read = FALSE OR is_read IS NULL THEN 0 ELSE 1 END,
            created_at DESC 
        LIMIT 5
    ";
    $recentNotifResult = pg_query($connection, $recentNotifQuery);
    if ($recentNotifResult) {
        $recentNotifications = pg_fetch_all($recentNotifResult) ?: [];
    }
}

// Function to get notification icon based on content (for header dropdown)
if (!function_exists('getHeaderNotificationIcon')) {
    function getHeaderNotificationIcon($message) {
        $message = strtolower($message);
        if (strpos($message, 'system') !== false || strpos($message, 'maintenance') !== false) {
            return 'bi-gear-fill';
        } elseif (strpos($message, 'registration') !== false || strpos($message, 'student') !== false) {
            return 'bi-person-check';
        } elseif (strpos($message, 'backup') !== false || strpos($message, 'database') !== false) {
            return 'bi-database';
        } elseif (strpos($message, 'slot') !== false) {
            return 'bi-calendar-plus';
        } else {
            return 'bi-info-circle';
        }
    }
}

// Function to truncate notification message for dropdown
if (!function_exists('truncateMessage')) {
    function truncateMessage($message, $maxLength = 50) {
        return strlen($message) > $maxLength ? substr($message, 0, $maxLength) . '...' : $message;
    }
}
?>
<div class="admin-main-header">
  <div class="container-fluid px-4">
    <div class="admin-header-content">
      <div class="admin-header-left d-flex align-items-center">
        <div class="sidebar-toggle me-2">
            <i class="bi bi-list" id="menu-toggle" aria-label="Toggle Sidebar" role="button" tabindex="0"></i>
        </div>
        <h5 class="mb-0 fw-semibold d-none d-md-inline text-success-emphasis">Dashboard</h5>
      </div>
      <div class="admin-header-actions">
        <button class="admin-icon-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Notifications" data-unread-count="<?=$unreadNotificationCount?>">
          <i class="bi bi-bell"></i>
          <?php if ($unreadNotificationCount > 0): ?>
            <span class="badge rounded-pill bg-danger"><?= $unreadNotificationCount ?></span>
          <?php endif; ?>
        </button>
        <ul class="dropdown-menu dropdown-menu-end shadow-sm">
          <li><h6 class="dropdown-header">
            Notifications
            <?php if ($unreadNotificationCount > 0): ?>
              <span class="badge bg-danger ms-2" id="header-dropdown-unread-count"><?= $unreadNotificationCount ?></span>
            <?php endif; ?>
          </h6></li>
          
          <?php if (empty($recentNotifications)): ?>
            <li><div class="dropdown-item-text text-muted text-center py-3">No notifications</div></li>
          <?php else: ?>
            <?php foreach ($recentNotifications as $notification): ?>
              <li>
                <a class="dropdown-item notification-preview-item <?= ($notification['is_read'] === 'f') ? 'bg-light' : '' ?>" 
                   href="admin_notifications.php" 
                   title="<?= htmlspecialchars($notification['message']) ?>">
                  <div class="d-flex align-items-start gap-2">
                    <i class="<?= getHeaderNotificationIcon($notification['message']) ?> text-primary notification-icon"></i>
                    <div class="flex-grow-1 notification-content">
                      <div class="fw-medium"><?= htmlspecialchars(truncateMessage($notification['message'])) ?></div>
                      <small class="text-muted">
                        <?= date('M j, g:i A', strtotime($notification['created_at'])) ?>
                        <?php if ($notification['is_read'] === 'f'): ?>
                          <span class="badge badge-sm bg-primary ms-1">New</span>
                        <?php endif; ?>
                      </small>
                    </div>
                  </div>
                </a>
              </li>
            <?php endforeach; ?>
          <?php endif; ?>
          
          <li><hr class="dropdown-divider"/></li>
          <li><a class="dropdown-item text-center fw-medium" href="admin_notifications.php">
            <i class="bi bi-bell me-1"></i>View all notifications
          </a></li>
        </ul>
        <div class="dropdown">
          <button class="admin-icon-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Profile">
            <i class="bi bi-person-circle"></i>
          </button>
          <ul class="dropdown-menu dropdown-menu-end shadow-sm">
            <li><h6 class="dropdown-header"><?=$adminDisplay?></h6></li>
            <li><a class="dropdown-item" href="admin_profile.php"><i class="bi bi-person-circle me-2"></i>Profile</a></li>
            <li><a class="dropdown-item" href="settings.php"><i class="bi bi-gear me-2"></i>Settings</a></li>
            <li><hr class="dropdown-divider"/></li>
            <li><a class="dropdown-item" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
          </ul>
        </div>
      </div>
    </div>
  </div>
</div><!-- /admin-main-header (added closing wrapper to prevent layout trapping) -->
<?php
// Pull header theme settings (gracefully fallback)
if (!function_exists('educaid_get_header_theme')) {
  function educaid_get_header_theme($connection) {
    $defaults = [
      'header_bg_color' => '#ffffff',
      'header_border_color' => '#e1e7e3',
      'header_text_color' => '#2e7d32',
      'header_icon_color' => '#2e7d32',
      'header_hover_bg' => '#e9f5e9',
      'header_hover_icon_color' => '#1b5e20'
    ];
    $check = @pg_query_params($connection, "SELECT 1 FROM information_schema.tables WHERE table_name=$1", ['header_theme_settings']);
    if (!$check || !pg_fetch_row($check)) return $defaults;
    $res = @pg_query($connection, "SELECT header_bg_color, header_border_color, header_text_color, header_icon_color, header_hover_bg, header_hover_icon_color FROM header_theme_settings WHERE municipality_id=1 LIMIT 1");
    if ($res && ($row = pg_fetch_assoc($res))) {
      return array_merge($defaults, array_filter($row));
    }
    return $defaults;
  }
}
$__hdr = educaid_get_header_theme($connection ?? null);
?>
<style>
.admin-main-header {
  background: <?= htmlspecialchars($__hdr['header_bg_color']) ?>;
  border-bottom: 1px solid <?= htmlspecialchars($__hdr['header_border_color']) ?>;
  box-shadow: 0 2px 4px rgba(0,0,0,.06);
  padding: .55rem 0;
  z-index: 1030;
  position: fixed;
  top: var(--admin-topbar-h, 52px);
  left: 250px;
  right: 0;
  width: calc(100% - 250px);
  height: 56px;
  color: <?= htmlspecialchars($__hdr['header_text_color']) ?>;
  overflow: visible;
  box-sizing: border-box;
}
.sidebar.close ~ .admin-main-header {
  left: 70px;
  width: calc(100% - 70px);
}
@media (max-width:991.98px){
  .admin-main-header {
    left: 0 !important;
    right: 0;
    width: 100% !important;
  }
}
.admin-main-header .container-fluid{height:100%;}
.admin-header-content{display:flex;align-items:center;justify-content:space-between;}
.admin-header-actions{display:flex;align-items:center;gap:1rem;}
.admin-icon-btn{background:#f8fbf8;border:1px solid #d9e4d8;border-radius:10px;padding:.55rem .65rem;position:relative;cursor:pointer;transition:.2s;color:<?= htmlspecialchars($__hdr['header_icon_color']) ?>;}
.admin-icon-btn .bi{font-size:1.05rem;}
.admin-icon-btn:hover{background:<?= htmlspecialchars($__hdr['header_hover_bg']) ?>;border-color:<?= htmlspecialchars($__hdr['header_hover_bg']) ?>;color:<?= htmlspecialchars($__hdr['header_hover_icon_color']) ?>;}
.admin-icon-btn .badge{position:absolute;top:-6px;right:-6px;font-size:.55rem;}
#menu-toggle{font-size:30px;cursor:pointer;color:<?= htmlspecialchars($__hdr['header_icon_color']) ?>;border-radius:8px;padding:4px 8px;transition:.2s;-webkit-tap-highlight-color:transparent;user-select:none;-webkit-user-select:none;touch-action:manipulation;}#menu-toggle:hover{background:<?= htmlspecialchars($__hdr['header_hover_bg']) ?>;color:<?= htmlspecialchars($__hdr['header_hover_icon_color']) ?>;}#menu-toggle:active{transform:scale(0.95);}
.sidebar-toggle{display:inline-block;z-index:1070;position:relative;}
.admin-main-header h5, .admin-main-header .dropdown-menu, .admin-main-header .admin-header-left span { color: <?= htmlspecialchars($__hdr['header_text_color']) ?>; }

/* Enhanced notification dropdown styling */
.admin-header-actions .dropdown-menu {min-width: 320px; max-width: 400px;}
.admin-header-actions .dropdown-item {padding: 0.75rem 1rem; border-bottom: 1px solid #f0f0f0;}
.admin-header-actions .dropdown-item:last-child {border-bottom: none;}
.admin-header-actions .dropdown-item.bg-light {background-color: #f8f9ff !important;}
.admin-header-actions .notification-preview-item {white-space: normal;}
.admin-header-actions .notification-icon {font-size: 1.1rem; flex-shrink: 0; margin-top: 0.15rem;}
.admin-header-actions .notification-content {min-width: 0; flex: 1;}
.admin-header-actions .notification-content .fw-medium {font-size: 0.9rem; line-height: 1.3; margin-bottom: 0.25rem; word-wrap: break-word; overflow-wrap: anywhere;}
.admin-header-actions .dropdown-item small {font-size: 0.8rem;}
.admin-header-actions .dropdown-item:hover {background-color: #f1f7ff;}
.admin-header-actions .dropdown-item-text {font-size: 0.9rem;}
.badge-sm {font-size: 0.7rem; padding: 0.25em 0.5em;}

/* Tablet adjustments (768px-991px) */
@media (min-width: 768px) and (max-width: 991.98px) {
  .admin-main-header { padding: .45rem 0; }
  .admin-header-actions { gap: 0.85rem; }
  .admin-icon-btn { padding: .5rem .6rem; }
  .admin-icon-btn .bi { font-size: 1rem; }
  .admin-header-actions .dropdown-menu { min-width: 300px; max-width: 350px; }
  .notification-content .fw-medium { font-size: 0.875rem; }
  
  /* Notification dropdown optimization */
  .admin-header-actions .dropdown-item { padding: 0.65rem 0.85rem; }
  .admin-header-actions .notification-icon { font-size: 1rem; }
  .admin-header-actions .dropdown-item small { font-size: 0.75rem; }
  .admin-header-actions .dropdown-header { font-size: 0.9rem; padding: 0.65rem 0.85rem; }
  .badge-sm { font-size: 0.65rem; }
}

@media (max-width: 576px){
  .admin-main-header{padding:.25rem 0;}
  #menu-toggle{font-size:24px;}
  .admin-header-actions{gap:.5rem;}
  .admin-icon-btn{padding:.4rem .5rem;border-radius:8px;}
  .admin-icon-btn .bi{font-size:.95rem;}
  .admin-header-actions .dropdown-menu{min-width:260px;}
}
</style>
<script>
// Fast inline safeguard: if server rendered 0 unread (badge omitted) but a stale badge remains in cached DOM, remove it.
document.addEventListener('DOMContentLoaded', function(){
  var serverCount = <?=(int)$unreadNotificationCount?>;
  if(serverCount === 0){
    document.querySelectorAll('.admin-icon-btn[title="Notifications"] .badge').forEach(b=>{
      if(b && (b.textContent.trim()==='' || b.textContent.trim()==='0')){
        b.style.display='none';
      }
    });
  }
  // Dynamically sync CSS variable for header height (prevents overlap/gaps if style changes)
  const hdr = document.querySelector('.admin-main-header');
  if(hdr){
    const setVar = () => {
      const h = hdr.getBoundingClientRect().height;
      document.documentElement.style.setProperty('--admin-header-h', h + 'px');
    };
    setVar();
    window.addEventListener('resize', setVar);
  }
});
</script>
