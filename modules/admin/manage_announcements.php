<?php
include __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/FilePathConfig.php';
require_once __DIR__ . '/../../includes/CSRFProtection.php';
require_once __DIR__ . '/../../includes/student_notification_helper.php';
$pathConfig = FilePathConfig::getInstance();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['admin_username'])) {
    header("Location: ../../unified_login.php");
    exit;
}

// Generate CSRF tokens
$csrfTokenPost = CSRFProtection::generateToken('post_announcement');
$csrfTokenToggle = CSRFProtection::generateToken('toggle_announcement');

// Handle form submission for general announcements (create)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Validate CSRF token first
  $token = $_POST['csrf_token'] ?? '';
  
  // Toggle activation request (repost/unpost)
  if (isset($_POST['announcement_id'], $_POST['toggle_active'])) {
    if (!CSRFProtection::validateToken('toggle_announcement', $token)) {
      header('Location: ' . $_SERVER['PHP_SELF'] . '?error=csrf');
      exit;
    }
    
    $aid = (int)$_POST['announcement_id'];
    $toggle = (int)$_POST['toggle_active']; // 1 => set active, 0 => deactivate
    
    if ($toggle === 1) {
      // Deactivate all first
      $deactivate_all = pg_query($connection, "UPDATE announcements SET is_active = FALSE");
      if (!$deactivate_all) {
        error_log("Failed to deactivate all announcements: " . pg_last_error($connection));
        header('Location: ' . $_SERVER['PHP_SELF'] . '?error=toggle_failed');
        exit;
      }
      
      // Activate the selected one
      $activate_result = pg_query_params($connection, "UPDATE announcements SET is_active=TRUE, updated_at=now() WHERE announcement_id=$1", [$aid]);
      if (!$activate_result) {
        error_log("Failed to activate announcement {$aid}: " . pg_last_error($connection));
        header('Location: ' . $_SERVER['PHP_SELF'] . '?error=toggle_failed');
        exit;
      }
      
      // Verify the update affected a row
      if (pg_affected_rows($activate_result) === 0) {
        error_log("No rows affected when activating announcement {$aid}");
        header('Location: ' . $_SERVER['PHP_SELF'] . '?error=announcement_not_found');
        exit;
      }
    } else {
      // Deactivate the selected one
      $deactivate_result = pg_query_params($connection, "UPDATE announcements SET is_active=FALSE, updated_at=now() WHERE announcement_id=$1", [$aid]);
      if (!$deactivate_result) {
        error_log("Failed to deactivate announcement {$aid}: " . pg_last_error($connection));
        header('Location: ' . $_SERVER['PHP_SELF'] . '?error=toggle_failed');
        exit;
      }
      
      // Verify the update affected a row
      if (pg_affected_rows($deactivate_result) === 0) {
        error_log("No rows affected when deactivating announcement {$aid}");
        header('Location: ' . $_SERVER['PHP_SELF'] . '?error=announcement_not_found');
        exit;
      }
    }
    
    // Add timestamp to prevent caching
    header('Location: ' . $_SERVER['PHP_SELF'] . '?toggled=1&t=' . time());
    exit;
  }

  if (isset($_POST['post_announcement'])) {
    if (!CSRFProtection::validateToken('post_announcement', $token)) {
      header('Location: ' . $_SERVER['PHP_SELF'] . '?error=csrf');
      exit;
    }
    $title = trim($_POST['title']);
    $remarks = trim($_POST['remarks']);
    $event_date = !empty($_POST['event_date']) ? $_POST['event_date'] : null;
    $event_time = !empty($_POST['event_time']) ? $_POST['event_time'] : null;
    $location = !empty($_POST['location']) ? trim($_POST['location']) : null;
    $image_path = null;

    // Server-side validation for required fields
    if (empty($title) || strlen($title) > 255) {
      header('Location: ' . $_SERVER['PHP_SELF'] . '?error=invalid_title');
      exit;
    }
    if (empty($remarks) || strlen($remarks) > 5000) {
      header('Location: ' . $_SERVER['PHP_SELF'] . '?error=invalid_remarks');
      exit;
    }
    
    // Validate event date is not in the past
    if ($event_date) {
      $today = date('Y-m-d');
      if ($event_date < $today) {
        header('Location: ' . $_SERVER['PHP_SELF'] . '?error=past_date');
        exit;
      }
      
      // Check for duplicate event date (warn but allow with confirmation)
      $duplicate_check = pg_query_params(
        $connection, 
        "SELECT announcement_id, title FROM announcements WHERE event_date = $1", 
        [$event_date]
      );
      
      if ($duplicate_check && pg_num_rows($duplicate_check) > 0) {
        // If user hasn't confirmed the duplicate, redirect with warning
        if (!isset($_POST['confirm_duplicate'])) {
          $existing = pg_fetch_assoc($duplicate_check);
          $_SESSION['pending_announcement'] = [
            'title' => $title,
            'remarks' => $remarks,
            'event_date' => $event_date,
            'event_time' => $event_time,
            'location' => $location,
            'existing_title' => $existing['title']
          ];
          
          // If there's an uploaded file, store it temporarily
          if (!empty($_FILES['image']['tmp_name']) && is_uploaded_file($_FILES['image']['tmp_name'])) {
            $_SESSION['pending_image'] = [
              'name' => $_FILES['image']['name'],
              'type' => $_FILES['image']['type'],
              'tmp_name' => $_FILES['image']['tmp_name'],
              'size' => $_FILES['image']['size']
            ];
          }
          
          header('Location: ' . $_SERVER['PHP_SELF'] . '?warning=duplicate_date');
          exit;
        }
        // User confirmed, proceed with posting
        unset($_SESSION['pending_announcement']);
        unset($_SESSION['pending_image']);
      }
    }

    // Handle image upload (optional)
    if (!empty($_FILES['image']['name']) && is_uploaded_file($_FILES['image']['tmp_name'])) {
      // Check for upload errors
      if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        error_log("Upload error code: " . $_FILES['image']['error']);
        header('Location: ' . $_SERVER['PHP_SELF'] . '?error=upload_failed');
        exit;
      }
      
      // Check file size (5MB max)
      if ($_FILES['image']['size'] > 5 * 1024 * 1024) {
        header('Location: ' . $_SERVER['PHP_SELF'] . '?error=file_too_large');
        exit;
      }
      
      $uploadDir = $pathConfig->getAnnouncementsPath();
      
      // Check if directory exists and is writable
      if (!is_dir($uploadDir)) {
        if (!@mkdir($uploadDir, 0775, true)) {
          error_log("Failed to create upload directory: " . $uploadDir);
          header('Location: ' . $_SERVER['PHP_SELF'] . '?error=directory_failed');
          exit;
        }
      }
      
      if (!is_writable($uploadDir)) {
        error_log("Upload directory not writable: " . $uploadDir);
        header('Location: ' . $_SERVER['PHP_SELF'] . '?error=directory_not_writable');
        exit;
      }
      
      $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
      
      // Strict extension validation
      if (!in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
        header('Location: ' . $_SERVER['PHP_SELF'] . '?error=invalid_extension');
        exit;
      }
      
      $fname = 'ann_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
      $dest = $uploadDir . '/' . $fname;
      
      if (!move_uploaded_file($_FILES['image']['tmp_name'], $dest)) {
        error_log("Failed to move uploaded file to: " . $dest);
        header('Location: ' . $_SERVER['PHP_SELF'] . '?error=move_failed');
        exit;
      }
      
      // Store relative path for serving
      $image_path = 'assets/uploads/announcements/' . $fname;
    }

    // Deactivate previous active
    $deactivate_result = pg_query($connection, "UPDATE announcements SET is_active = FALSE");
    if (!$deactivate_result) {
      error_log("Failed to deactivate announcements: " . pg_last_error($connection));
      // Continue anyway as this is not critical
    }
    
    $query = "INSERT INTO announcements (title, remarks, event_date, event_time, location, image_path, is_active) VALUES ($1,$2,$3,$4,$5,$6,TRUE)";
    $result = pg_query_params($connection, $query, [$title, $remarks, $event_date, $event_time, $location, $image_path]);
    
    if (!$result) {
      error_log("Announcement insert failed: " . pg_last_error($connection));
      
      // Clean up uploaded image if database insert failed
      if ($image_path && file_exists(__DIR__ . '/../../' . $image_path)) {
        @unlink(__DIR__ . '/../../' . $image_path);
      }
      
      header('Location: ' . $_SERVER['PHP_SELF'] . '?error=db_insert');
      exit;
    }
    
    // Create admin notification
    $notification_msg = "New announcement posted: " . $title;
    $admin_notif_result = pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
    
    if (!$admin_notif_result) {
      error_log("Failed to create admin notification: " . pg_last_error($connection));
      // Continue as this is not critical
    }
    
    // Create student notifications for all active students
    $student_notif_title = "New Announcement: " . $title;
    $student_notif_message = $remarks;
    
    // Add event details to message if available
    if ($event_date || $event_time || $location) {
      $student_notif_message .= "\n\n";
      if ($event_date) $student_notif_message .= "Date: " . date('F j, Y', strtotime($event_date)) . "\n";
      if ($event_time) $student_notif_message .= "Time: " . $event_time . "\n";
      if ($location) $student_notif_message .= "Location: " . $location;
    }
    
    // Use helper function to send notifications (handles both bell notifications and emails automatically)
    try {
      createBulkStudentNotification(
        $connection,
        $student_notif_title,
        $student_notif_message,
        'announcement',
        'medium',
        '../../website/announcements.php'
      );
    } catch (Exception $e) {
      error_log("Failed to create student notifications: " . $e->getMessage());
      // Continue as announcement was posted successfully
    }
    
    header('Location: ' . $_SERVER['PHP_SELF'] . '?posted=1');
    exit;
  }

  // Handle edit announcement
  if (isset($_POST['edit_announcement'])) {
    error_log("=== EDIT ANNOUNCEMENT REQUEST ===");
    error_log("POST data: " . print_r($_POST, true));
    
    if (!CSRFProtection::validateToken('post_announcement', $token)) {
      error_log("CSRF validation failed");
      header('Location: ' . $_SERVER['PHP_SELF'] . '?error=csrf');
      exit;
    }

    $announcement_id = (int)$_POST['announcement_id'];
    $title = trim($_POST['title']);
    $remarks = trim($_POST['remarks']);
    $event_date = !empty($_POST['event_date']) ? $_POST['event_date'] : null;
    $event_time = !empty($_POST['event_time']) ? $_POST['event_time'] : null;
    $location = !empty($_POST['location']) ? trim($_POST['location']) : null;

    error_log("Parsed values - ID: $announcement_id, Title: $title");

    // Server-side validation
    if (empty($title) || strlen($title) > 255) {
      error_log("Title validation failed");
      header('Location: ' . $_SERVER['PHP_SELF'] . '?error=invalid_title');
      exit;
    }
    if (empty($remarks) || strlen($remarks) > 5000) {
      error_log("Remarks validation failed");
      header('Location: ' . $_SERVER['PHP_SELF'] . '?error=invalid_remarks');
      exit;
    }

    // Update announcement
    $query = "UPDATE announcements SET title=$1, remarks=$2, event_date=$3, event_time=$4, location=$5, updated_at=now() WHERE announcement_id=$6";
    $result = pg_query_params($connection, $query, [$title, $remarks, $event_date, $event_time, $location, $announcement_id]);
    
    if (!$result) {
      error_log("Announcement update failed: " . pg_last_error($connection));
      header('Location: ' . $_SERVER['PHP_SELF'] . '?error=db_update');
      exit;
    }

    $affected_rows = pg_affected_rows($result);
    error_log("Update successful. Affected rows: $affected_rows");

    header('Location: ' . $_SERVER['PHP_SELF'] . '?updated=1');
    exit;
  }
}

// Check for success flags
$posted = isset($_GET['posted']);
$updated = isset($_GET['updated']);
?>
<?php $page_title='Manage Announcements'; $extra_css=['../../assets/css/admin/manage_announcements.css', '../../assets/css/admin/table_core.css']; include __DIR__ . '/../../includes/admin/admin_head.php'; ?>

<style>
  .card:hover { transform:none!important; transition:none!important; }
  .card h5 { font-size:1.25rem; font-weight:600; color:#333; }
  /* Layout */
  .announcement-form-grid { display:grid; gap:1.25rem; }
  @media (min-width:992px){ .announcement-form-grid { grid-template-columns:2fr 1fr; align-items:start; } }
  .event-block { background:#f8fafc; border:1px solid #e2e8f0; border-radius:.5rem; padding:1rem 1.1rem; position:relative; }
  .event-block h6 { font-size:.9rem; font-weight:600; text-transform:uppercase; margin:0 0 .75rem; color:#2563eb; letter-spacing:.5px; }
  .event-inline { display:flex; flex-wrap:wrap; gap:.75rem; }
  .event-inline .form-control { min-width:160px; }
  .image-upload-wrap { border:2px dashed #cbd5e1; padding:1.1rem; text-align:center; border-radius:.75rem; cursor:pointer; background:#fff; transition:border-color .2s, background .2s; }
  .image-upload-wrap.dragover { border-color:#2563eb; background:#f1f8ff; }
  .image-upload-wrap input { display:none; }
  .image-preview { margin-top:.75rem; display:flex; gap:1rem; flex-wrap:wrap; }
  .image-preview figure { margin:0; position:relative; }
  .image-preview img { max-width:160px; max-height:110px; object-fit:cover; border:1px solid #cbd5e1; border-radius:.4rem; box-shadow:0 2px 4px rgba(0,0,0,.06); }
  .image-preview .remove-btn { position:absolute; top:4px; right:4px; background:#ef4444; border:none; color:#fff; width:26px; height:26px; border-radius:50%; font-size:.8rem; display:flex; align-items:center; justify-content:center; cursor:pointer; }
  /* Inline (in-zone) preview */
  .image-upload-wrap { position:relative; }
  .image-upload-wrap .inline-preview { display:flex; justify-content:center; align-items:center; min-height:140px; }
  .image-upload-wrap .inline-preview figure { margin:0; position:relative; }
  .image-upload-wrap .inline-preview img { max-width:100%; max-height:220px; object-fit:contain; display:block; border-radius:.5rem; box-shadow:0 2px 6px rgba(0,0,0,.08); }
  .image-upload-wrap .remove-btn { position:absolute; top:6px; right:6px; background:#ef4444; border:none; color:#fff; width:30px; height:30px; border-radius:50%; font-size:.85rem; display:flex; align-items:center; justify-content:center; cursor:pointer; box-shadow:0 2px 4px rgba(0,0,0,.15); }
  .image-upload-wrap.dragover .placeholder { opacity:.4; }
  .image-preview figcaption { font-size:.65rem; text-align:center; margin-top:.3rem; max-width:160px; color:#475569; }
  .form-section-title { font-size:.75rem; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:.5px; margin:0 0 .5rem; }
  /* Table */
  #ann-body td { vertical-align:top; }
  #ann-body tr.active-ann { box-shadow:0 0 0 2px #16a34a33 inset; background:#f0fff4; }
  .remarks-trunc { position:relative; max-width:420px; }
  .remarks-trunc.collapsed .full-text { display:none; }
  .remarks-trunc.expanded .truncate-text { display:none; }
  .remarks-toggle { color:#2563eb; font-size:.7rem; cursor:pointer; display:inline-block; margin-top:.25rem; }
  .badge-success { background:#16a34a!important; }
  .badge-secondary { background:#64748b!important; }
  .announcement-img-thumb { border:1px solid #e2e8f0; background:#fff; padding:2px; border-radius:.35rem; display:inline-block; box-shadow:0 1px 2px rgba(0,0,0,.08); }
  .announcement-img-thumb img { max-width:80px; max-height:50px; object-fit:cover; border-radius:.25rem; display:block; }
  .pagination-controls { display:flex; gap:.5rem; align-items:center; justify-content:center; margin-top:1rem; }
  .pagination-controls input[type='number'] { width:60px; text-align:center; }
</style>
</head>
<body>
<?php include __DIR__ . '/../../includes/admin/admin_topbar.php'; ?>
<div id="wrapper" class="admin-wrapper">
  <?php include __DIR__ . '/../../includes/admin/admin_sidebar.php'; ?>
  <?php include __DIR__ . '/../../includes/admin/admin_header.php'; ?>
  <section class="home-section" id="mainContent">
  <div class="container-fluid py-4 px-4">
      <div class="mb-4">
          <h1 class="fw-bold mb-1">Manage Announcements</h1>
      </div>

      <div class="card p-4 mb-4">
        <form method="POST" enctype="multipart/form-data" id="announcementForm">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfTokenPost) ?>">
          <div class="mb-3">
            <label class="form-label">Title</label>
            <input type="text" name="title" id="title" class="form-control form-control-lg" placeholder="Scholarship Orientation" required>
          </div>
          <div class="event-block mb-3">
            <h6><i class="bi bi-calendar-event me-1"></i> Event Schedule (Optional)</h6>
            <div class="event-inline mb-2">
              <input type="date" name="event_date" class="form-control" aria-label="Event Date" id="eventDate" min="<?= date('Y-m-d') ?>">
              <input type="time" name="event_time" class="form-control" aria-label="Event Time" id="eventTime">
            </div>
            <input type="text" name="location" class="form-control" placeholder="Location / Venue" aria-label="Location" id="location">
          </div>
          <div class="mb-3">
            <label class="form-label">Remarks / Description</label>
            <textarea name="remarks" id="remarksEditor" class="form-control form-control-lg" rows="6" placeholder="Provide details, agenda, instructions, deadlines..." required></textarea>
          </div>
          <div class="mb-3">
            <div class="form-section-title">Image (Optional)</div>
            <label class="image-upload-wrap" id="imageDropZone">
              <input type="file" name="image" id="imageInput" accept="image/*">
              <div class="placeholder small text-muted text-center w-100"><i class="bi bi-image me-1"></i>Drag & drop or click to select an image (jpg/png/gif/webp)</div>
              <div class="inline-preview" id="inlineImagePreview" hidden></div>
            </label>
          </div>
          <button type="submit" name="post_announcement" class="btn btn-primary">
            <i class="bi bi-send me-1"></i> Post Announcement
          </button>
        </form>
        <?php if ($posted): ?>
          <div class="alert alert-success alert-dismissible fade show mt-3" role="alert">
            <i class="bi bi-check-circle me-2"></i>Announcement posted successfully!
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>
        <?php endif; ?>
        
        <?php if ($updated): ?>
          <div class="alert alert-success alert-dismissible fade show mt-3" role="alert">
            <i class="bi bi-check-circle me-2"></i>Announcement updated successfully!
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['toggled'])): ?>
          <div class="alert alert-success alert-dismissible fade show mt-3" role="alert">
            <i class="bi bi-check-circle me-2"></i>Announcement status updated successfully!
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['warning']) && $_GET['warning'] === 'duplicate_date' && isset($_SESSION['pending_announcement'])): ?>
          <?php $pending = $_SESSION['pending_announcement']; ?>
          <div class="alert alert-warning alert-dismissible fade show mt-3" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <strong>Duplicate Event Date Warning!</strong><br>
            You already have an announcement "<strong><?= htmlspecialchars($pending['existing_title']) ?></strong>" scheduled for <strong><?= date('F j, Y', strtotime($pending['event_date'])) ?></strong>.<br>
            Are you sure you want to post another announcement for the same date?
            <div class="mt-3">
              <form method="POST" style="display:inline;" id="confirmDuplicateForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfTokenPost) ?>">
                <input type="hidden" name="post_announcement" value="1">
                <input type="hidden" name="confirm_duplicate" value="1">
                <input type="hidden" name="title" value="<?= htmlspecialchars($pending['title']) ?>">
                <input type="hidden" name="remarks" value="<?= htmlspecialchars($pending['remarks']) ?>">
                <input type="hidden" name="event_date" value="<?= htmlspecialchars($pending['event_date']) ?>">
                <input type="hidden" name="event_time" value="<?= htmlspecialchars($pending['event_time']) ?>">
                <input type="hidden" name="location" value="<?= htmlspecialchars($pending['location']) ?>">
                <button type="submit" class="btn btn-warning btn-sm me-2">
                  <i class="bi bi-check-circle me-1"></i>Yes, Post Anyway
                </button>
              </form>
              <a href="<?= $_SERVER['PHP_SELF'] ?>" class="btn btn-secondary btn-sm" onclick="<?php unset($_SESSION['pending_announcement']); unset($_SESSION['pending_image']); ?>">
                <i class="bi bi-x-circle me-1"></i>Cancel
              </a>
            </div>
          </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['error'])): ?>
          <div class="alert alert-danger alert-dismissible fade show mt-3" role="alert">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <?php 
            switch ($_GET['error']) {
              case 'csrf': 
                echo 'Security validation failed. Please refresh and try again.'; 
                break;
              case 'invalid_title': 
                echo 'Invalid title. Title must be between 1-255 characters.'; 
                break;
              case 'invalid_remarks': 
                echo 'Invalid remarks. Remarks must be between 1-5000 characters.'; 
                break;
              case 'upload_failed': 
                echo 'Image upload failed. Please try again.'; 
                break;
              case 'file_too_large': 
                echo 'Image file is too large. Maximum size is 5MB.'; 
                break;
              case 'invalid_extension': 
                echo 'Invalid image format. Please use JPG, PNG, GIF, or WebP files only.'; 
                break;
              case 'directory_failed': 
                echo 'Failed to create upload directory. Please contact administrator.'; 
                break;
              case 'directory_not_writable': 
                echo 'Upload directory is not writable. Please contact administrator.'; 
                break;
              case 'move_failed': 
                echo 'Failed to save uploaded image. Please try again.'; 
                break;
              case 'db_insert': 
                echo 'Failed to save announcement to database. Please try again.'; 
                break;
              case 'db_update':
                echo 'Failed to update announcement in database. Please try again.';
                break;
              case 'toggle_failed': 
                echo 'Failed to update announcement status. Please try again.'; 
                break;
              case 'announcement_not_found': 
                echo 'Announcement not found or already deleted.'; 
                break;
              case 'past_date': 
                echo 'Event date cannot be in the past. Please select today or a future date.'; 
                break;
              default: 
                echo 'An unexpected error occurred. Please try again.';
            }
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>
        <?php endif; ?>
      </div>

      <h2 class="fw-bold mb-3">Existing Announcements</h2>
      <div class="card p-4">
        <?php
  $annRes = pg_query($connection, "SELECT announcement_id, title, remarks, posted_at, is_active, event_date, event_time, location, image_path FROM announcements ORDER BY posted_at DESC");
        $announcements = [];
        while ($a = pg_fetch_assoc($annRes)) {
          $announcements[] = $a;
        }
        pg_free_result($annRes);
        ?>
        <div class="table-responsive">
          <table class="table table-bordered">
            <thead>
              <tr>
                <th>Title</th>
                <th>Remarks</th>
                <th>Event</th>
                <th>Posted At</th>
                <th>Active</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody id="ann-body"></tbody>
          </table>
        </div>
        <div class="pagination-controls">
          <label>Page:</label>
          <input type="number" id="page-input" min="1" value="1">
          <button id="prev-btn" class="btn btn-outline-secondary btn-sm">&laquo;</button>
          <button id="next-btn" class="btn btn-outline-secondary btn-sm">&raquo;</button>
          <span id="page-info" class="ms-2"></span>
        </div>
      </div>
    </div>
  </section>
</div>
<script>
const announcements = <?php echo json_encode($announcements); ?>;
let currentPage = 0;
const pageSize = 5;
const totalPages = Math.ceil(announcements.length / pageSize);

function renderPage() {
  const start = currentPage * pageSize;
  const slice = announcements.slice(start, start + pageSize);
  const tbody = document.getElementById('ann-body');
  tbody.innerHTML = '';
  slice.forEach(a => {
    // FIX: Use actual is_active field from database, not position in array
    const isActive = a.is_active === 't' || a.is_active === true;
    const badge = isActive
      ? '<span class="badge bg-success">Active</span>'
      : '<span class="badge bg-secondary">Inactive</span>';
    const btnLabel = isActive ? 'Unpost' : 'Repost';
    const btnClass = isActive ? 'danger' : 'success';
    const toggleValue = isActive ? 0 : 1;
    const tr = document.createElement('tr');
    // Event summary formatting
    let eventCell = '';
    if (a.event_date || a.event_time || a.location) {
      const d = a.event_date ? a.event_date : '';
      const t = a.event_time ? a.event_time.substring(0,5) : '';
      const loc = a.location ? a.location : '';
      eventCell = `<div class='small'>${d} ${t}</div>${loc ? `<div class='text-muted small'>${loc}</div>` : ''}`;
    } else {
      eventCell = '<span class="text-muted small">—</span>';
    }
    const imgThumb = a.image_path ? `<div class='mt-1'><img src='../../${a.image_path}' alt='img' style='max-width:80px; max-height:50px; object-fit:cover; border:1px solid #ddd; border-radius:4px;'></div>` : '';
    // Truncate remarks & build cell
    let truncated = a.remarks.length > 220 ? a.remarks.substring(0,220) + '…' : a.remarks;
    const needsToggle = a.remarks.length > 220;
    const remarksHtml = `
      <div class="remarks-trunc collapsed">
        <div class="truncate-text">${escapeHtml(truncated)}</div>
        <div class="full-text">${escapeHtml(a.remarks)}</div>
        ${needsToggle ? '<span class="remarks-toggle">Show more</span>' : ''}
      </div>`;
    tr.classList.toggle('active-ann', isActive);
    tr.innerHTML = `
      <td><strong>${escapeHtml(a.title)}</strong>${imgThumb}</td>
      <td>${remarksHtml}</td>
      <td>${eventCell}</td>
      <td>${a.posted_at}</td>
      <td>${badge}</td>
      <td>
        <button type="button" class="btn btn-sm btn-outline-primary me-1 mb-1" onclick="editAnnouncement('${a.announcement_id}')">
          <i class="bi bi-pencil"></i> Edit
        </button>
        <form method="POST" class="d-inline">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfTokenToggle) ?>">
          <input type="hidden" name="announcement_id" value="${a.announcement_id}">
          <input type="hidden" name="toggle_active" value="${toggleValue}">
          <button type="submit" class="btn btn-sm btn-outline-${btnClass} mb-1">${btnLabel}</button>
        </form>
      </td>`;
    tbody.appendChild(tr);
  });
  document.getElementById('page-info').textContent = `Page ${currentPage + 1} of ${totalPages}`;
  document.getElementById('prev-btn').disabled = currentPage === 0;
  document.getElementById('next-btn').disabled = currentPage >= totalPages - 1;
  document.getElementById('page-input').value = currentPage + 1;
}

renderPage();
function escapeHtml(str){ return str.replace(/[&<>"']/g, c=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[c]||c)); }
document.getElementById('prev-btn').addEventListener('click', () => {
  if (currentPage > 0) {
    currentPage--;
    renderPage();
  }
});
document.getElementById('next-btn').addEventListener('click', () => {
  if (currentPage < totalPages - 1) {
    currentPage++;
    renderPage();
  }
});
document.getElementById('page-input').addEventListener('change', (e) => {
  const value = parseInt(e.target.value);
  if (!isNaN(value) && value >= 1 && value <= totalPages) {
    currentPage = value - 1;
    renderPage();
  }
});
// Expand / collapse remarks
document.addEventListener('click', e=>{
  if(e.target.classList.contains('remarks-toggle')){
    const wrap = e.target.closest('.remarks-trunc');
    if(!wrap) return;
    const expanded = wrap.classList.toggle('expanded');
    wrap.classList.toggle('collapsed', !expanded);
    e.target.textContent = expanded? 'Show less' : 'Show more';
  }
});
// Image preview & drag-drop (inline centered)
const dropZone = document.getElementById('imageDropZone');
const imgInput = document.getElementById('imageInput');
const inlinePreview = document.getElementById('inlineImagePreview');
if(dropZone && imgInput && inlinePreview){
  const placeholder = dropZone.querySelector('.placeholder');
  const clearPreview = () => { inlinePreview.innerHTML=''; inlinePreview.hidden=true; if(placeholder) placeholder.hidden=false; };
  const showPreview = (file) => {
    if(!file) return clearPreview();
    const url = URL.createObjectURL(file);
    inlinePreview.innerHTML = `<figure><button type="button" class="remove-btn" title="Remove">&times;</button><img src="${url}" alt="Selected image"></figure>`;
    inlinePreview.hidden = false;
    if(placeholder) placeholder.hidden = true;
  };
  imgInput.addEventListener('change', e => showPreview(e.target.files[0]));
  inlinePreview.addEventListener('click', e => { if(e.target.classList.contains('remove-btn')) { imgInput.value=''; clearPreview(); }});
  ['dragenter','dragover'].forEach(ev => dropZone.addEventListener(ev, e => { e.preventDefault(); e.stopPropagation(); dropZone.classList.add('dragover'); }));
  ['dragleave','drop'].forEach(ev => dropZone.addEventListener(ev, e => { e.preventDefault(); e.stopPropagation(); dropZone.classList.remove('dragover'); }));
  dropZone.addEventListener('drop', e => { const file = e.dataTransfer.files && e.dataTransfer.files[0]; if(file){ imgInput.files = e.dataTransfer.files; imgInput.dispatchEvent(new Event('change')); }});
}

// Edit announcement function
function editAnnouncement(id) {
  console.log('Edit clicked for announcement ID:', id, 'Type:', typeof id);
  console.log('Available announcements:', announcements.length);
  
  // Convert to number for comparison
  const numericId = parseInt(id);
  const announcement = announcements.find(a => parseInt(a.announcement_id) === numericId);
  
  if (!announcement) {
    console.error('Announcement not found:', id);
    console.log('Looking for ID:', numericId);
    console.log('Available IDs:', announcements.map(a => a.announcement_id));
    alert('Error: Announcement not found. Please refresh the page and try again.');
    return;
  }
  
  console.log('Found announcement:', announcement);
  
  // Get the form
  const form = document.getElementById('announcementForm');
  if (!form) {
    console.error('Form not found');
    alert('Error: Form not found. Please refresh the page.');
    return;
  }
  
  // Reset form first to clear any previous state
  resetForm();
  
  // Populate form fields
  const titleInput = document.getElementById('title');
  const eventDateInput = document.getElementById('eventDate');
  const eventTimeInput = document.getElementById('eventTime');
  const locationInput = document.getElementById('location');
  
  if (titleInput) titleInput.value = announcement.title;
  if (eventDateInput) eventDateInput.value = announcement.event_date || '';
  if (eventTimeInput) eventTimeInput.value = announcement.event_time || '';
  if (locationInput) locationInput.value = announcement.location || '';
  
  // Update remarks textarea content
  const remarksEditor = document.getElementById('remarksEditor');
  if (remarksEditor) {
    remarksEditor.value = announcement.remarks;
  }
  
  // Change form to edit mode
  const submitBtn = form.querySelector('button[type="submit"]');
  if (!submitBtn) {
    console.error('Submit button not found');
    return;
  }
  
  submitBtn.innerHTML = '<i class="bi bi-check-circle me-1"></i> Update Announcement';
  
  // Remove post_announcement name attribute from submit button
  submitBtn.removeAttribute('name');
  
  // Add hidden field for announcement_id
  let editIdInput = form.querySelector('input[name="announcement_id"]');
  if (!editIdInput) {
    editIdInput = document.createElement('input');
    editIdInput.type = 'hidden';
    editIdInput.name = 'announcement_id';
    form.insertBefore(editIdInput, form.firstChild);
  }
  editIdInput.value = numericId;
  console.log('Set announcement_id to:', editIdInput.value);
  
  // Add hidden input to ensure edit_announcement is submitted
  let editActionInput = form.querySelector('input[name="edit_announcement"]');
  if (!editActionInput) {
    editActionInput = document.createElement('input');
    editActionInput.type = 'hidden';
    editActionInput.name = 'edit_announcement';
    editActionInput.value = '1';
    form.insertBefore(editActionInput, form.firstChild);
  }
  console.log('Set edit_announcement flag');
  
  console.log('Edit mode enabled for announcement:', numericId);
  
  // Add cancel button if not exists
  let cancelBtn = form.querySelector('.cancel-edit-btn');
  if (!cancelBtn) {
    cancelBtn = document.createElement('button');
    cancelBtn.type = 'button';
    cancelBtn.className = 'btn btn-secondary cancel-edit-btn ms-2';
    cancelBtn.innerHTML = '<i class="bi bi-x-circle me-1"></i> Cancel';
    cancelBtn.onclick = resetForm;
    submitBtn.parentNode.appendChild(cancelBtn);
  }
  
  // Scroll to form
  form.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function resetForm() {
  const form = document.getElementById('announcementForm');
  if (!form) return;
  
  form.reset();
  
  // Reset remarks textarea
  const remarksEditor = document.getElementById('remarksEditor');
  if (remarksEditor) {
    remarksEditor.value = '';
  }
  
  const submitBtn = form.querySelector('button[type="submit"]');
  if (submitBtn) {
    submitBtn.innerHTML = '<i class="bi bi-send me-1"></i> Post Announcement';
    submitBtn.name = 'post_announcement';
  }
  
  // Remove edit-specific inputs
  const editIdInput = form.querySelector('input[name="announcement_id"]');
  if (editIdInput) editIdInput.remove();
  
  const editActionInput = form.querySelector('input[name="edit_announcement"]');
  if (editActionInput) editActionInput.remove();
  
  const cancelBtn = form.querySelector('.cancel-edit-btn');
  if (cancelBtn) cancelBtn.remove();
  
  // Clear image preview
  const imgInput = document.getElementById('imageInput');
  const inlinePreview = document.getElementById('inlineImagePreview');
  const placeholder = document.getElementById('imageDropZone').querySelector('.placeholder');
  if (imgInput) imgInput.value = '';
  if (inlinePreview) { inlinePreview.innerHTML = ''; inlinePreview.hidden = true; }
  if (placeholder) placeholder.hidden = false;
}

// Add form submit event listener for debugging
document.getElementById('announcementForm').addEventListener('submit', function(e) {
  const formData = new FormData(this);
  console.log('=== FORM SUBMISSION ===');
  console.log('Form submission data:');
  for (let [key, value] of formData.entries()) {
    console.log(key + ':', value);
  }
  
  // Check if we're in edit mode
  const editMode = formData.has('edit_announcement');
  const hasAnnouncementId = formData.has('announcement_id');
  console.log('Edit mode:', editMode);
  console.log('Has announcement_id:', hasAnnouncementId);
  
  if (editMode && !hasAnnouncementId) {
    console.error('ERROR: Missing announcement_id in edit mode!');
    alert('Error: Missing announcement ID. Please try again.');
    e.preventDefault();
    return false;
  }
  
  if (editMode) {
    console.log('Submitting EDIT for announcement ID:', formData.get('announcement_id'));
  } else {
    console.log('Submitting NEW announcement');
  }
});
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../../assets/js/admin/sidebar.js"></script>
</body>
</html>