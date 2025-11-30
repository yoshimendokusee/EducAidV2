<?php
include __DIR__ . '/../../config/database.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['admin_username'])) {
  header("Location: ../../unified_login.php");
  exit;
}

// Pagination setup
$page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int) $_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Notification query - Updated to handle read/unread status
$filterType = isset($_GET['filter']) ? $_GET['filter'] : 'all'; // Default to 'all' instead of 'unread'

$baseWhere = "";
if ($filterType === 'unread') {
    $baseWhere = "WHERE (is_read = FALSE OR is_read IS NULL)";
} elseif ($filterType === 'read') {
    $baseWhere = "WHERE is_read = TRUE";
}
// 'all' filter has no WHERE clause, shows everything

$baseSql = "
  SELECT 'System' as type, message AS message, created_at, admin_notification_id::text as notification_id, 
         COALESCE(is_read, FALSE) as is_read 
  FROM admin_notifications
";

$countSql = "SELECT COUNT(*) AS total FROM ($baseSql) AS sub $baseWhere";
$countRes = pg_query($connection, $countSql);
$total = $countRes ? (int)pg_fetch_assoc($countRes)['total'] : 0;
$lastPage = (int)ceil($total / $limit);

$adminNotifSql = "SELECT * FROM ($baseSql) AS combined $baseWhere ORDER BY created_at DESC LIMIT $limit OFFSET $offset";
$adminNotifRes = @pg_query($connection, $adminNotifSql);
$adminNotifs = $adminNotifRes ? pg_fetch_all($adminNotifRes) : [];

// Get total unread count for badge
$unreadCountSql = "SELECT COUNT(*) AS unread_count FROM ($baseSql) AS sub WHERE (is_read = FALSE OR is_read IS NULL)";
$unreadCountRes = pg_query($connection, $unreadCountSql);
$unreadCount = $unreadCountRes ? (int)pg_fetch_assoc($unreadCountRes)['unread_count'] : 0;

// Function to get notification icon based on type
function getNotificationIcon($type) {
    switch ($type) {
        case 'Announcement':
            return 'bi-megaphone-fill text-primary';
        case 'Slot':
            return 'bi-calendar-plus-fill text-success';
        case 'Schedule':
            return 'bi-clock-fill text-info';
        case 'System':
            return 'bi-gear-fill text-warning';
        default:
            return 'bi-info-circle-fill text-secondary';
    }
}
?>

<?php $page_title='Notifications'; $extra_css=['../../assets/css/admin/notification.css']; include __DIR__ . '/../../includes/admin/admin_head.php'; ?>
<body>
  <?php include __DIR__ . '/../../includes/admin/admin_topbar.php'; ?>
  <div id="wrapper" class="admin-wrapper">
    <?php include __DIR__ . '/../../includes/admin/admin_sidebar.php'; ?>
    <?php include __DIR__ . '/../../includes/admin/admin_header.php'; ?>

    <section class="home-section" id="mainContent">

  <div class="container-fluid py-4 px-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h3 class="fw-bold mb-0">Notifications
            <?php if ($unreadCount > 0): ?>
            <span class="badge bg-danger ms-1" id="unread-count"><?= $unreadCount ?></span>
            <?php endif; ?>
          </h3>
        </div>

        <!-- Filter Controls -->
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
          #mark-all-read {
            border-radius: 20px;
            font-weight: 500;
          }
        </style>

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
          <?php if (empty($adminNotifs)): ?>
            <div id="empty-state">No notifications available.</div>
          <?php else: ?>
            <?php foreach ($adminNotifs as $note): ?>
              <div class="notification-card <?= $note['is_read'] === 't' || $note['is_read'] === true ? 'read' : 'unread' ?>" 
                   data-notification-id="<?= $note['notification_id'] ?>" 
                   data-notification-type="<?= htmlspecialchars($note['type']) ?>">
                <div class="notification-header d-flex align-items-start">
                  <div class="notification-main d-flex flex-grow-1 align-items-start gap-3">
                    <span class="icon-box text-primary bg-light">
                      <i class="<?php echo getNotificationIcon($note['type']); ?>"></i>
                    </span>
                    <p class="notification-message mb-0"><?php echo htmlspecialchars($note['message']); ?></p>
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

  <!-- Snackbar -->
  <div id="undo-snackbar" class="undo-snackbar"></div>

  <!-- Scripts -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script src="../../assets/js/admin/sidebar.js"></script>
  <script src="../../assets/js/admin/notifications.js"></script>
</body>
</html>

<?php pg_close($connection); ?>
