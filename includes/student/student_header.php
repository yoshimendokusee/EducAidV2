<?php
// Student Header with dynamic theming
// Requires: $_SESSION['student_username'], $_SESSION['student_id']

// Load security headers first (if not already loaded)
if (!defined('SECURITY_HEADERS_LOADED')) {
    require_once __DIR__ . '/../../config/security_headers.php';
}

$studentDisplay = htmlspecialchars($_SESSION['student_username'] ?? 'Student');
$studentId = $_SESSION['student_id'] ?? null;

// Get student info
include_once __DIR__ . '/../../config/database.php';
$student_info = ['first_name' => '', 'last_name' => ''];

if (isset($connection) && $studentId) {
  // Fetch student info
  $student_info_query = "SELECT first_name, last_name FROM students WHERE student_id = $1";
  $student_info_result = pg_query_params($connection, $student_info_query, [$studentId]);
  if ($student_info_result && pg_num_rows($student_info_result) > 0) {
    $student_info = pg_fetch_assoc($student_info_result);
  }
}
?>
<div class="student-main-header">
  <div class="container-fluid px-4">
    <div class="student-header-content">
      <div class="student-header-left d-flex align-items-center">
        <div class="sidebar-toggle me-2"><i class="bi bi-list" id="menu-toggle" aria-label="Toggle Sidebar"></i></div>
        <h5 class="mb-0 fw-bold d-none d-md-inline" style="color: #1e40af;">Dashboard</h5>
      </div>
      <div class="student-header-actions">
        <?php 
        // Include the new bell notifications component
        if (isset($connection) && $studentId) {
          include __DIR__ . '/bell_notifications.php';
        }
        ?>
        <div class="dropdown">
          <button class="student-icon-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Profile">
            <i class="bi bi-person-circle"></i>
          </button>
          <ul class="dropdown-menu dropdown-menu-end shadow-sm">
            <li><h6 class="dropdown-header"><?= htmlspecialchars(trim($student_info['first_name'] . ' ' . $student_info['last_name'])) ?: $studentDisplay ?></h6></li>
            <li><a class="dropdown-item" href="student_profile.php"><i class="bi bi-person me-2"></i>Profile</a></li>
            <li><a class="dropdown-item" href="student_settings.php"><i class="bi bi-gear me-2"></i>Settings</a></li>
            <li><hr class="dropdown-divider"/></li>
            <li><a class="dropdown-item" href="../../unified_login.php?logout=true" onclick="return confirm('Are you sure you want to logout?\n\nYou will be returned to the login page.');"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
          </ul>
        </div>
      </div>
    </div>
  </div>
</div><!-- /student-main-header -->
<?php
// Pull header theme settings from shared table (same as admin header)
if (!function_exists('educaid_get_student_header_theme')) {
  function educaid_get_student_header_theme($connection) {
    $defaults = [
      'header_bg_color' => '#ffffff',
      'header_border_color' => '#e1e7ec',
      'header_text_color' => '#0d6efd',
      'header_icon_color' => '#0d6efd',
      'header_hover_bg' => '#e7f1ff',
      'header_hover_icon_color' => '#0a58ca'
    ];
    if (!$connection) return $defaults;
    // Use shared header_theme_settings table (same as admin)
    $check = @pg_query_params($connection, "SELECT 1 FROM information_schema.tables WHERE table_name=$1", ['header_theme_settings']);
    if (!$check || !pg_fetch_row($check)) return $defaults;
    $res = @pg_query($connection, "SELECT header_bg_color, header_border_color, header_text_color, header_icon_color, header_hover_bg, header_hover_icon_color FROM header_theme_settings WHERE municipality_id=1 LIMIT 1");
    if ($res && ($row = pg_fetch_assoc($res))) {
      return array_merge($defaults, array_filter($row));
    }
    return $defaults;
  }
}
$__hdr = educaid_get_student_header_theme($connection ?? null);
?>
<style>
.student-main-header {
  background: <?= htmlspecialchars($__hdr['header_bg_color']) ?>;
  border-bottom: 1px solid <?= htmlspecialchars($__hdr['header_border_color']) ?>;
  box-shadow: 0 2px 4px rgba(0,0,0,.06);
  padding: .55rem 0;
  z-index: 1030; /* below sidebar/backdrop/topbar */
  position: fixed;
  top: var(--topbar-h, 44px);
  /* left property is animated by JS - no CSS transition to avoid collision */
  left: 250px;
  right: 0;
  width: calc(100% - 250px);
  height: 56px;
  color: <?= htmlspecialchars($__hdr['header_text_color']) ?>;
  overflow: visible;
  box-sizing: border-box;
}
.sidebar.close ~ .student-main-header { 
  left: 70px; 
  width: calc(100% - 70px);
}
.student-main-header .container-fluid { 
  height: 100%; 
  max-width: 100%;
  padding-left: 1rem;
  padding-right: 1rem;
  box-sizing: border-box;
}
.student-header-content {
  display: flex;
  align-items: center;
  justify-content: space-between;
  height: 100%;
  max-width: 100%;
  gap: 1rem;
  flex-wrap: nowrap;
}
.student-header-left {
  flex-shrink: 0;
  min-width: 0;
}
.student-header-actions {
  display: flex;
  align-items: center;
  gap: 1rem;
  flex-shrink: 0;
}
.student-icon-btn {
  background: #f8f9fb;
  border: 1px solid #d9dce4;
  border-radius: 10px;
  padding: .55rem .65rem;
  position: relative;
  cursor: pointer;
  transition: background-color .2s, border-color .2s, color .2s;
  color: <?= htmlspecialchars($__hdr['header_icon_color']) ?>;
}
.student-icon-btn .bi { font-size: 1.05rem; }
.student-icon-btn:hover {
  background: <?= htmlspecialchars($__hdr['header_hover_bg']) ?>;
  border-color: <?= htmlspecialchars($__hdr['header_hover_bg']) ?>;
  color: <?= htmlspecialchars($__hdr['header_hover_icon_color']) ?>;
}
.student-icon-btn .badge {
  position: absolute;
  top: -6px;
  right: -6px;
  font-size: .55rem;
}
#menu-toggle {
  font-size: 30px;
  cursor: pointer;
  color: <?= htmlspecialchars($__hdr['header_icon_color']) ?>;
  border-radius: 8px;
  padding: 4px 8px;
  transition: background-color .2s, color .2s;
  line-height: 1;
  display: flex;
  align-items: center;
  justify-content: center;
}
#menu-toggle:hover {
  background: <?= htmlspecialchars($__hdr['header_hover_bg']) ?>;
  color: <?= htmlspecialchars($__hdr['header_hover_icon_color']) ?>;
}
.student-main-header h5 {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 200px;
}
.student-main-header h5,
.student-main-header .dropdown-menu,
.student-main-header .student-header-left span {
  color: <?= htmlspecialchars($__hdr['header_text_color']) ?>;
}

/* Enhanced notification dropdown styling */
.student-header-actions .dropdown-menu {
  min-width: 320px;
  max-width: 400px;
  z-index: 1060 !important;
}
.student-header-actions .dropdown-item {
  padding: 0.75rem 1rem;
  border-bottom: 1px solid #f0f0f0;
  white-space: normal;
}
.student-header-actions .dropdown-item:last-child { border-bottom: none; }
.student-header-actions .dropdown-item.bg-light { background-color: #f8f9ff !important; }
.student-header-actions .dropdown-item .fw-medium {
  font-size: 0.9rem;
  line-height: 1.3;
  margin-bottom: 0.25rem;
}
.student-header-actions .dropdown-item small { font-size: 0.8rem; }
.student-header-actions .dropdown-item:hover { background-color: #f1f7ff; }
.student-header-actions .dropdown-item-text { font-size: 0.9rem; }
.badge-sm {
  font-size: 0.7rem;
  padding: 0.25em 0.5em;
}

@media (max-width: 992px) {
  .student-main-header { 
    padding: .4rem 0; 
    left: 0 !important; 
    right: 0;
    width: 100% !important;
  }
  #menu-toggle { font-size: 26px; }
  .student-header-actions { gap: .65rem; }
  .student-header-actions .dropdown-menu { min-width: 280px; }
  .student-main-header h5 { max-width: 150px; }
}
</style>
<script>
// Student notification JS disabled temporarily (kept minimal for layout syncs).
document.addEventListener('DOMContentLoaded', function(){
  // Keep badge hidden if server reports 0 unread.
  var serverCount = <?=(int)$unreadNotificationCount?>;
  if(serverCount === 0){
    document.querySelectorAll('.student-icon-btn[title="Notifications"] .badge').forEach(function(b){
      if(b && (b.textContent.trim()==='' || b.textContent.trim()==='0')){
        b.style.display='none';
      }
    });
  }
  // Maintain CSS variable for header height.
  var hdr = document.querySelector('.student-main-header');
  if(hdr){
    var setVar = function(){
      var h = hdr.getBoundingClientRect().height;
      document.documentElement.style.setProperty('--student-header-h', h + 'px');
    };
    setVar();
    window.addEventListener('resize', setVar);
  }

  // Notification polling/commented out pending schema fix.
  /*
  function markNotificationAsRead(notificationId) {
    fetch('/EducAid/services/student_notification_actions.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: 'action=mark_read&notification_id=' + notificationId
    }).then(response => response.json())
      .then(data => {
        if (data.success) {
          updateNotificationCount();
        }
      });
  }

  function updateNotificationCount() {
    fetch('/EducAid/services/student_notification_actions.php?action=get_unread_count')
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          const count = data.count;
          const badges = document.querySelectorAll('.student-icon-btn[title="Notifications"] .badge');
          const headerCount = document.getElementById('header-dropdown-unread-count');

          if (count > 0) {
            badges.forEach(badge => {
              badge.textContent = count;
              badge.style.display = '';
            });
            if (headerCount) headerCount.textContent = count;
          } else {
            badges.forEach(badge => badge.style.display = 'none');
            if (headerCount) headerCount.style.display = 'none';
          }
        }
      });
  }
  */
});

// Initialize session timeout warning system
<?php if (isset($GLOBALS['session_timeout_status']) && $GLOBALS['session_timeout_status']['status'] === 'active'): ?>
window.sessionTimeoutConfig = {
  idle_timeout_minutes: <?= $GLOBALS['session_timeout_status']['idle_timeout_seconds'] / 60 ?>,
  absolute_timeout_hours: <?= $GLOBALS['session_timeout_status']['absolute_timeout_seconds'] / 3600 ?>,
  warning_before_logout_seconds: <?= $GLOBALS['session_timeout_status']['warning_threshold'] ?>,
  enabled: true
};
<?php endif; ?>
</script>

<?php if (isset($GLOBALS['session_timeout_status']) && $GLOBALS['session_timeout_status']['status'] === 'active'): ?>
<!-- Session Timeout Warning System -->
<link rel="stylesheet" href="/EducAid/assets/css/session-timeout-warning.css">
<script src="/EducAid/assets/js/session-timeout-warning.js"></script>
<?php endif; ?>
