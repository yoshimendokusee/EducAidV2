<?php
include __DIR__ . '/../../config/database.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['student_username'])) { header("Location: ../../unified_login.php"); exit; }

// Enforce session timeout via middleware
require_once __DIR__ . '/../../includes/SessionTimeoutMiddleware.php';
$timeoutMiddleware = new SessionTimeoutMiddleware();
$timeoutStatus = $timeoutMiddleware->handle();
$student_id = $_SESSION['student_id'];

// Require year level update before accessing this page
include __DIR__ . '/../../includes/require_year_level_update.php';

// Pagination setup
$page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int) $_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Notification query - Updated to handle read/unread status
$filterType = isset($_GET['filter']) ? $_GET['filter'] : 'all'; // Default to 'all' instead of 'unread'

$baseWhere = "WHERE student_id = $1";
if ($filterType === 'unread') {
    $baseWhere .= " AND (is_read = FALSE OR is_read IS NULL)";
} elseif ($filterType === 'read') {
    $baseWhere .= " AND is_read = TRUE";
}
// 'all' filter shows everything for this student

$baseSql = "
  SELECT notification_id, title, message, type, priority, created_at, is_read, action_url
  FROM student_notifications
  $baseWhere
  AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)
";

$countSql = "SELECT COUNT(*) AS total FROM student_notifications $baseWhere AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)";
$countRes = pg_query_params($connection, $countSql, [$student_id]);
$total = $countRes ? (int)pg_fetch_assoc($countRes)['total'] : 0;
$lastPage = (int)ceil($total / $limit);

$notifSql = "$baseSql ORDER BY created_at DESC LIMIT $limit OFFSET $offset";
$notifRes = @pg_query_params($connection, $notifSql, [$student_id]);
$notifications = $notifRes ? pg_fetch_all($notifRes) : [];

// Get total unread count for badge
$unreadCountSql = "SELECT COUNT(*) AS unread_count FROM student_notifications WHERE student_id = $1 AND (is_read = FALSE OR is_read IS NULL) AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)";
$unreadCountRes = pg_query_params($connection, $unreadCountSql, [$student_id]);
$unreadCount = $unreadCountRes ? (int)pg_fetch_assoc($unreadCountRes)['unread_count'] : 0;

// Function to get notification icon based on type
function getNotificationIcon($type) {
    switch ($type) {
        case 'announcement':
            return 'bi-megaphone-fill text-primary';
        case 'document':
            return 'bi-file-earmark-check text-success';
        case 'schedule':
            return 'bi-calendar-event text-info';
        case 'warning':
            return 'bi-exclamation-triangle-fill text-warning';
        case 'error':
            return 'bi-x-circle-fill text-danger';
        case 'success':
            return 'bi-check-circle-fill text-success';
        case 'system':
            return 'bi-gear-fill text-secondary';
        default:
            return 'bi-info-circle-fill text-info';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Notifications</title>
  
  <!-- Critical CSS for FOUC Prevention -->
  <style>
    body {
      opacity: 0;
      transition: opacity 0.3s ease;
      background: #f5f5f5;
    }
    body.ready {
      opacity: 1;
    }
    body:not(.ready) .sidebar {
      visibility: hidden;
    }
  </style>
  
  <link href="../../assets/css/bootstrap.min.css" rel="stylesheet" />
  <link href="../../assets/css/bootstrap-icons.css" rel="stylesheet" />
  <link rel="stylesheet" href="../../assets/css/student/homepage.css" />
  <link rel="stylesheet" href="../../assets/css/student/sidebar.css" />
  <!-- Reuse admin notification stylesheet for 1:1 UI parity -->
  <link rel="stylesheet" href="../../assets/css/admin/notification.css" />
  <link rel="stylesheet" href="../../assets/css/student/accessibility.css" />
  <script src="../../assets/js/student/accessibility.js"></script>
</head>
<body>
  <!-- Student Topbar -->
  <?php include __DIR__ . '/../../includes/student/student_topbar.php'; ?>

  <div id="wrapper" style="padding-top: var(--topbar-h);">
    <!-- Sidebar -->
    <?php include __DIR__ . '/../../includes/student/student_sidebar.php'; ?>
    
    <!-- Student Header -->
    <?php include __DIR__ . '/../../includes/student/student_header.php'; ?>
    
    <!-- Page Content -->
    <section class="home-section" id="page-content-wrapper">
      <div class="container-fluid py-4 px-4" style="padding-top: calc(var(--student-header-h, 56px) + 1.5rem) !important;">
        
        <!-- Modern Page Header -->
        <div class="page-header-card mb-4">
          <div class="d-flex align-items-center gap-3">
            <div class="header-icon-box">
              <i class="bi bi-bell-fill"></i>
            </div>
            <div>
              <h3 class="fw-bold mb-0">Notifications
                <?php if ($unreadCount > 0): ?>
                <span class="badge bg-danger ms-2" id="unread-count"><?= $unreadCount ?></span>
                <?php endif; ?>
              </h3>
              <p class="text-muted mb-0" style="font-size: 0.95rem;">Stay updated with your application status</p>
            </div>
          </div>
        </div>
        
        <style>
          .page-header-card {
            background: white;
            border-radius: 16px;
            padding: 1.5rem 2rem;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
          }
          .header-icon-box {
            width: 56px;
            height: 56px;
            background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
            box-shadow: 0 4px 12px rgba(251, 191, 36, 0.3);
          }
        </style>

        <!-- Filter Controls (Desktop) -->
        <div class="notification-actions-desktop d-none d-md-flex justify-content-between align-items-center mb-4">
          <div class="btn-group filter-btn-group" role="group">
            <a href="?filter=unread&page=1" class="btn <?= $filterType === 'unread' ? 'btn-primary' : 'btn-outline-secondary' ?>">
              Unread (<?= $unreadCount ?>)
            </a>
            <a href="?filter=read&page=1" class="btn <?= $filterType === 'read' ? 'btn-primary' : 'btn-outline-secondary' ?>">
              Read
            </a>
            <a href="?filter=all&page=1" class="btn <?= $filterType === 'all' ? 'btn-primary' : 'btn-outline-secondary' ?>">
              All
            </a>
          </div>
          <button id="mark-all-read" class="btn btn-outline-primary">
            <i class="bi bi-envelope-open me-1"></i> Mark All as Read
          </button>
        </div>
        
        <style>
          .filter-btn-group .btn {
            border-radius: 20px !important;
            padding: 0.5rem 1.25rem;
            font-weight: 500;
          }
          .filter-btn-group .btn:first-child {
            border-top-right-radius: 0 !important;
            border-bottom-right-radius: 0 !important;
          }
          .filter-btn-group .btn:last-child {
            border-top-left-radius: 0 !important;
            border-bottom-left-radius: 0 !important;
          }
          .filter-btn-group .btn:not(:first-child):not(:last-child) {
            border-radius: 0 !important;
          }
          #mark-all-read, #mark-all-read-mobile {
            border-radius: 20px;
            font-weight: 500;
          }
        </style>

        <!-- Filter Controls (Mobile) -->
        <div class="notification-actions-mobile d-flex d-md-none mb-3 gap-2">
          <a href="?filter=unread&page=1" class="btn <?= $filterType === 'unread' ? 'btn-primary' : 'btn-outline-secondary' ?> flex-fill">
            Unread
          </a>
          <a href="?filter=read&page=1" class="btn <?= $filterType === 'read' ? 'btn-primary' : 'btn-outline-secondary' ?> flex-fill">
            Read
          </a>
          <a href="?filter=all&page=1" class="btn <?= $filterType === 'all' ? 'btn-primary' : 'btn-outline-secondary' ?> flex-fill">
            All
          </a>
          <button class="btn btn-outline-primary" id="mark-all-read-mobile">
            <i class="bi bi-envelope-open"></i>
          </button>
        </div>

        <!-- Notifications List -->
        <div class="notifications-list">
          <?php if (empty($notifications)): ?>
            <div id="empty-state">No notifications available.</div>
          <?php else: ?>
            <?php foreach ($notifications as $note): ?>
              <div class="notification-card <?= $note['is_read'] === 't' || $note['is_read'] === true ? 'read' : 'unread' ?>" 
                   data-notification-id="<?= $note['notification_id'] ?>" 
                   data-notification-type="<?= htmlspecialchars($note['type']) ?>">
                <div class="notification-header d-flex align-items-start">
                  <div class="notification-main d-flex flex-grow-1 align-items-start gap-3">
                    <span class="icon-box text-primary bg-light">
                      <i class="<?php echo getNotificationIcon($note['type']); ?>"></i>
                    </span>
                    <div>
                      <h6 class="mb-1"><?php echo htmlspecialchars($note['title']); ?></h6>
                      <p class="notification-message mb-0"><?php echo htmlspecialchars($note['message']); ?></p>
                    </div>
                  </div>
                  <div class="action-buttons align-self-start ms-3">
                    <?php if ($note['is_read'] === 'f' || $note['is_read'] === false): ?>
                      <i class="bi bi-envelope mark-read-btn" role="button" title="Mark as Read" 
                         data-notification-id="<?= $note['notification_id'] ?>"></i>
                    <?php else: ?>
                      <i class="bi bi-envelope-open text-success" title="Already Read"></i>
                    <?php endif; ?>
                    <i class="bi bi-trash text-danger delete-btn" role="button" title="Delete"
                       data-notification-id="<?= $note['notification_id'] ?>"></i>
                  </div>
                </div>
                <div class="text-muted small ms-5 mt-1">Posted: <?= date("F j, Y, g:i a", strtotime($note['created_at'])) ?></div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <!-- Pagination -->
        <nav class="mt-4 d-flex justify-content-center">
          <ul class="pagination mb-0">
            <?php if ($page > 1): ?>
              <li class="page-item">
                <a class="page-link" href="?filter=<?= $filterType ?>&page=<?= $page - 1 ?>">
                  <i class="bi bi-chevron-left"></i>
                </a>
              </li>
            <?php endif; ?>
            <?php for ($i = max(1, $page - 2); $i <= min($lastPage, $page + 2); $i++): ?>
              <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                <a class="page-link" href="?filter=<?= $filterType ?>&page=<?= $i ?>"><?= $i ?></a>
              </li>
            <?php endfor; ?>
            <?php if ($page < $lastPage): ?>
              <li class="page-item">
                <a class="page-link" href="?filter=<?= $filterType ?>&page=<?= $page + 1 ?>">
                  <i class="bi bi-chevron-right"></i>
                </a>
              </li>
            <?php endif; ?>
          </ul>
        </nav>
      </div>
    </section>
  </div>
  <script src="../../assets/js/student/sidebar.js"></script>
  <script src="../../assets/js/bootstrap.bundle.min.js"></script>
  
  <!-- Student notification functionality -->
  <script>
  document.addEventListener('DOMContentLoaded', function() {
      // Mark single notification as read
      const markReadButtons = document.querySelectorAll('.mark-read-btn');
      markReadButtons.forEach(btn => {
          btn.addEventListener('click', function(e) {
              e.stopPropagation();
              const notificationId = this.getAttribute('data-notification-id');
              markNotificationAsRead(notificationId);
          });
      });

      // Delete notification
      const deleteButtons = document.querySelectorAll('.delete-btn');
      deleteButtons.forEach(btn => {
          btn.addEventListener('click', function(e) {
              e.stopPropagation();
              const notificationId = this.getAttribute('data-notification-id');
              deleteNotification(notificationId);
          });
      });

      // Mark all as read
      const markAllReadBtn = document.getElementById('mark-all-read');
      const markAllReadMobileBtn = document.getElementById('mark-all-read-mobile');
      
      if (markAllReadBtn) {
          markAllReadBtn.addEventListener('click', markAllNotificationsAsRead);
      }
      if (markAllReadMobileBtn) {
          markAllReadMobileBtn.addEventListener('click', markAllNotificationsAsRead);
      }
  });

  function markNotificationAsRead(notificationId) {
      fetch('../../api/student/mark_notification_read.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ notification_id: notificationId })
      })
      .then(response => response.json())
      .then(data => {
          if (data.success) {
              const card = document.querySelector(`[data-notification-id="${notificationId}"]`);
              if (card) {
                  card.classList.remove('unread');
                  card.classList.add('read');
                  const markReadBtn = card.querySelector('.mark-read-btn');
                  if (markReadBtn) {
                      markReadBtn.outerHTML = '<i class="bi bi-envelope-open text-success" title="Already Read"></i>';
                  }
              }
              updateBadgeCount();
          }
      })
      .catch(error => console.error('Error:', error));
  }

  function markAllNotificationsAsRead() {
      if (!confirm('Mark all notifications as read?')) return;
      
      fetch('../../api/student/mark_all_notifications_read.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' }
      })
      .then(response => response.json())
      .then(data => {
          if (data.success) {
              location.reload();
          }
      })
      .catch(error => console.error('Error:', error));
  }

  function deleteNotification(notificationId) {
      if (!confirm('Delete this notification?')) return;
      
      fetch('../../api/student/delete_notification.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ notification_id: notificationId })
      })
      .then(response => response.json())
      .then(data => {
          if (data.success) {
              const card = document.querySelector(`[data-notification-id="${notificationId}"]`);
              if (card) {
                  card.style.transition = 'opacity 0.3s, transform 0.3s';
                  card.style.opacity = '0';
                  card.style.transform = 'translateX(20px)';
                  setTimeout(() => {
                      card.remove();
                      const notificationsList = document.querySelector('.notifications-list');
                      if (notificationsList && !notificationsList.querySelector('.notification-card')) {
                          notificationsList.innerHTML = '<div id="empty-state">No notifications available.</div>';
                      }
                  }, 300);
              }
              updateBadgeCount();
          }
      })
      .catch(error => console.error('Error:', error));
  }

  function updateBadgeCount() {
      fetch('../../api/student/get_notification_count.php')
          .then(response => response.json())
          .then(data => {
              if (data.success) {
                  const badge = document.getElementById('unread-count');
                  if (badge) {
                      badge.textContent = data.count;
                      badge.style.display = data.count > 0 ? 'inline-block' : 'none';
                  }
              }
          });
  }
  </script>
  
  <!-- Anti-FOUC Script -->
  <script>
    (function() {
      document.body.classList.add('ready');
      window.addEventListener('pageshow', function(event) {
        if (event.persisted) {
          document.body.classList.add('ready');
        }
      });
    })();
  </script>
  
  <div id="undo-snackbar" class="undo-snackbar"></div>
</body>
</html>
