<?php
include __DIR__ . '/../../config/database.php';
include __DIR__ . '/../../includes/workflow_control.php';
require_once __DIR__ . '/../../includes/CSRFProtection.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['admin_username'])) {
    header("Location: ../../unified_login.php");
    exit;
}

// Lightweight API for sidebar: return active students count as JSON
if (isset($_GET['api']) && $_GET['api'] === 'badge_count') {
    header('Content-Type: application/json');
    $countRes = @pg_query($connection, "SELECT COUNT(*) FROM students WHERE status = 'active' AND (is_archived IS NULL OR is_archived = FALSE)");
    $count = 0;
    if ($countRes) {
        $count = (int) pg_fetch_result($countRes, 0, 0);
        pg_free_result($countRes);
    }
    echo json_encode(['count' => $count]);
    exit;
}

// Check workflow permissions - must have active distribution
$workflow_status = getWorkflowStatus($connection);
if (!$workflow_status['can_verify_students']) {
    $_SESSION['error_message'] = "Please start a distribution first before verifying students. Go to Distribution Control to begin.";
    header("Location: distribution_control.php");
    exit;
}

/* ---------------------------
   CONFIG / STATE
----------------------------*/
$isFinalized = false;
$configResult = pg_query($connection, "SELECT value FROM config WHERE key = 'student_list_finalized'");
if ($configResult && ($row = pg_fetch_assoc($configResult))) {
  $isFinalized = ($row['value'] === '1');
} else {
  // Persist default: unlocked unless explicitly locked later
  pg_query($connection, "INSERT INTO config (key, value) VALUES ('student_list_finalized', '0') ON CONFLICT (key) DO UPDATE SET value = '0'");
  $isFinalized = false;
}

// Check if any students have been scanned in the CURRENT distribution (prevents unlocking after distribution started)
$has_scanned_students = false;

// Get current distribution ID from config
$current_dist_query = pg_query($connection, "SELECT value FROM config WHERE key = 'current_academic_year'");
$current_semester_query = pg_query($connection, "SELECT value FROM config WHERE key = 'current_semester'");

if ($current_dist_query && $current_semester_query) {
    $current_year_row = pg_fetch_assoc($current_dist_query);
    $current_semester_row = pg_fetch_assoc($current_semester_query);
    
    if ($current_year_row && $current_semester_row) {
        $current_year = $current_year_row['value'];
        $current_semester = $current_semester_row['value'];
        
        // Check if there's a snapshot for current distribution with scanned students
        $scanned_check = pg_query_params($connection, 
            "SELECT COUNT(*) as count 
             FROM distribution_student_records dsr
             INNER JOIN distribution_snapshots ds ON dsr.snapshot_id = ds.snapshot_id
             WHERE ds.academic_year = $1 
             AND ds.semester = $2",
            [$current_year, $current_semester]
        );
        
        if ($scanned_check) {
            $scanned_data = pg_fetch_assoc($scanned_check);
            $has_scanned_students = intval($scanned_data['count']) > 0;
        }
    }
}

// Detect presence of distribution_payrolls table for conditional cleanup use later
$hasHistoryTable = false;
$historyTableRes = pg_query($connection, "SELECT 1 FROM information_schema.tables WHERE table_name='distribution_payrolls' LIMIT 1");
if ($historyTableRes && pg_num_rows($historyTableRes) > 0) {
  $hasHistoryTable = true;
}

// Get student counts
$student_counts = getStudentCounts($connection);

/* ---------------------------
   HELPERS
----------------------------*/
function fetch_students($connection, $status, $sort, $barangayFilter, $searchSurname = '') {
  $query = "
    SELECT s.student_id, s.first_name, s.middle_name, s.last_name, s.mobile, s.email,
         b.name AS barangay, s.payroll_no, s.student_id as display_student_id,
         s.mothers_maiden_name, s.admin_review_required,
         (
         SELECT unique_id FROM qr_codes q2
         WHERE q2.student_id = s.student_id AND q2.payroll_number = s.payroll_no
         ORDER BY q2.qr_id DESC LIMIT 1
         ) AS unique_id
    FROM students s
    JOIN barangays b ON s.barangay_id = b.barangay_id
    WHERE s.status = $1";
  $params = [$status];
  $paramIndex = 2;
  if (!empty($barangayFilter)) {
    $query .= " AND b.barangay_id = $" . $paramIndex;
    $params[] = $barangayFilter;
    $paramIndex++;
  }
  if (!empty($searchSurname)) {
    $query .= " AND s.last_name ILIKE $" . $paramIndex;
    $params[] = "%$searchSurname%";
    $paramIndex++;
  }
  $query .= " ORDER BY s.last_name " . ($sort === 'desc' ? 'DESC' : 'ASC');
  return pg_query_params($connection, $query, $params);
}

/* ---------------------------
   POST ACTIONS
----------------------------*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token for all POST operations
    $token = $_POST['csrf_token'] ?? '';
    if (!CSRFProtection::validateToken('verify_students_operation', $token)) {
        echo "<script>alert('Security validation failed. Please refresh the page and try again.'); window.location.href='verify_students.php';</script>";
        exit;
    }

    // Revert selected actives back to applicants
  if (isset($_POST['deactivate']) && !empty($_POST['selected_actives'])) {
    $count = count($_POST['selected_actives']);

    // Detect QR schema: whether qr_codes.student_unique_id exists
    $hasQrStudentUniqueCol = false;
    $colCheck = pg_query_params(
      $connection,
      "SELECT 1 FROM information_schema.columns WHERE table_name = 'qr_codes' AND column_name = 'student_unique_id' LIMIT 1",
      []
    );
    if ($colCheck && pg_num_rows($colCheck) > 0) { $hasQrStudentUniqueCol = true; }
    if ($colCheck) { pg_free_result($colCheck); }

    foreach ($_POST['selected_actives'] as $student_id) {
      // Delete QR code PNGs and DB records for this student, using detected schema
      if ($hasQrStudentUniqueCol) {
        // New schema: qr_codes.student_unique_id + students.unique_student_id
        $qr_res = pg_query_params(
          $connection,
          "SELECT q.unique_id
           FROM qr_codes q
           JOIN students s ON q.student_unique_id = s.unique_student_id
           WHERE s.student_id = $1",
          [$student_id]
        );
        if ($qr_res) {
          while ($qr_row = pg_fetch_assoc($qr_res)) {
            if (!empty($qr_row['unique_id'])) {
              $png_path = __DIR__ . '/../../assets/js/qrcode/phpqrcode-master/temp/' . $qr_row['unique_id'] . '.png';
              if (file_exists($png_path)) { @unlink($png_path); }
            }
          }
          pg_free_result($qr_res);
        }
        // Delete QR rows via join
        pg_query_params(
          $connection,
          "DELETE FROM qr_codes q USING students s
           WHERE q.student_unique_id = s.unique_student_id AND s.student_id = $1",
          [$student_id]
        );
      } else {
        // Old schema: qr_codes.student_id
        $qr_res_old = pg_query_params($connection, "SELECT unique_id FROM qr_codes WHERE student_id = $1", [$student_id]);
        if ($qr_res_old) {
          while ($qr_row = pg_fetch_assoc($qr_res_old)) {
            if (!empty($qr_row['unique_id'])) {
              $png_path = __DIR__ . '/../../assets/js/qrcode/phpqrcode-master/temp/' . $qr_row['unique_id'] . '.png';
              if (file_exists($png_path)) { @unlink($png_path); }
            }
          }
          pg_free_result($qr_res_old);
        }
        pg_query_params($connection, "DELETE FROM qr_codes WHERE student_id = $1", [$student_id]);
      }
      // Finally, revert student status
      pg_query_params($connection, "UPDATE students SET status = 'applicant' WHERE student_id = $1", [$student_id]);

      // Remove provisional history rows for this student for current period if table exists (only if list not yet scheduled/distributed)
      if ($hasHistoryTable && !empty($current_year) && !empty($current_semester) && !$workflow_status['has_schedules'] && !$has_scanned_students) {
        pg_query_params(
          $connection,
          "DELETE FROM distribution_payrolls WHERE student_id = $1 AND academic_year = $2 AND semester = $3 AND snapshot_id IS NULL",
          [$student_id, $current_year, $current_semester]
        );
      }
    }
        // Reset finalized flag using UPSERT
        pg_query($connection, "
            INSERT INTO config (key, value) VALUES ('student_list_finalized', '0')
            ON CONFLICT (key) DO UPDATE SET value = '0'
        ");
        $isFinalized = false;
        
        // Add admin notification
        $notification_msg = "Reverted " . $count . " student(s) from active to applicant status";
        pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
        
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    // Lock list (for payroll generation)
    if (isset($_POST['finalize_list'])) {
        // Use UPSERT to ensure the row exists
        pg_query($connection, "
            INSERT INTO config (key, value) VALUES ('student_list_finalized', '1')
            ON CONFLICT (key) DO UPDATE SET value = '1'
        ");
        $isFinalized = true;
        
        // Add admin notification
        $notification_msg = "Student list has been locked - ready for payroll generation";
        pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
        
        // Redirect to refresh the page and show updated buttons
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    // Revert list (optionally reset payroll numbers)
    if (isset($_POST['revert_list'])) {
        // Check if schedules exist - prevent reverting if schedules are created
        if ($workflow_status['has_schedules']) {
            echo "<script>alert('Cannot revert payroll numbers because schedules have been created. Please remove all schedules first.'); window.location.href='verify_students.php';</script>";
            exit;
        }
        
        // Use UPSERT to ensure the row exists
        pg_query($connection, "
            INSERT INTO config (key, value) VALUES ('student_list_finalized', '0')
            ON CONFLICT (key) DO UPDATE SET value = '0'
        ");
        $isFinalized = false;
        // Delete all QR code DB records (unconditional wipe)
        $delRes = pg_query($connection, "DELETE FROM qr_codes");
        if (!$delRes) {
            $notification_msg = "ERROR: Failed to delete QR code records.";
            pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
            echo "<script>alert('Failed to delete QR code records!'); window.location.href='verify_students.php';</script>";
            exit;
        }
        if (isset($_POST['reset_payroll'])) {
          pg_query($connection, "UPDATE students SET payroll_no = NULL WHERE status = 'active'");
          // Clean up provisional distribution_payrolls rows for current academic period (only those without snapshot linkage)
          if ($hasHistoryTable && !empty($current_year) && !empty($current_semester) && !$has_scanned_students) {
            pg_query_params(
              $connection,
              "DELETE FROM distribution_payrolls WHERE academic_year = $1 AND semester = $2 AND snapshot_id IS NULL",
              [$current_year, $current_semester]
            );
          }
          $notification_msg = "Student list reverted and payroll numbers reset. All QR code records deleted.";
        } else {
            $notification_msg = "Student list reverted (payroll numbers preserved). All QR code records deleted.";
        }
        // Add admin notification
        pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
        // Force reload to reflect changes
        echo "<script>alert('Revert complete! $notification_msg'); window.location.href='verify_students.php';</script>";
        exit;
    }

    // Generate payroll numbers (sorted A→Z by full name) and QR codes
    if (isset($_POST['generate_payroll'])) {

      // Gather context for formatted payroll codes
      $yearRes = pg_query($connection, "SELECT value FROM config WHERE key='current_academic_year'");
      $semRes  = pg_query($connection, "SELECT value FROM config WHERE key='current_semester'");
      $currentYear = ($yearRes && ($yr = pg_fetch_assoc($yearRes)) && !empty($yr['value'])) ? $yr['value'] : (date('Y') . '-' . (date('Y')+1));
      $currentSemester = ($semRes && ($sm = pg_fetch_assoc($semRes)) && !empty($sm['value'])) ? $sm['value'] : '1';

      // New compact format components
      // 1) Start year (e.g., 2026 from "2026-2027")
      $startYear = null;
      if (preg_match('/(\d{4})/', (string)$currentYear, $m)) { $startYear = $m[1]; }
      if (!$startYear) { $startYear = date('Y'); }
      // 2) Semester numeric (1 or 2)
      $semNum = '1';
      if (preg_match('/2/', (string)$currentSemester)) { $semNum = '2'; }
      elseif (preg_match('/1/', (string)$currentSemester)) { $semNum = '1'; }

      // Helper to derive a 3-letter municipality code
      $deriveShortCode = function(string $nameOrSlug): string {
        $src = strtoupper(trim($nameOrSlug));
        // Common mapping for General Trias City
        if (preg_match('/GENERAL\s*TRIAS/i', $src) || preg_match('/GENTRIAS/i', $src) || preg_match('/GENERALTRIAS/i', $src)) {
          return 'GTC';
        }
        // Build from words (ignore common fillers)
        $clean = preg_replace('/[^A-Z0-9\s]/', ' ', $src);
        $words = preg_split('/\s+/', $clean, -1, PREG_SPLIT_NO_EMPTY);
        $stop = ['CITY','OF','MUNICIPALITY'];
        $letters = '';
        foreach ($words as $w) {
          if (in_array($w, $stop, true)) continue;
          $letters .= substr($w, 0, 1);
          if (strlen($letters) >= 3) break;
        }
        if ($letters === '') { $letters = substr(preg_replace('/[^A-Z0-9]/','',$src), 0, 3); }
        return str_pad($letters, 3, 'X');
      };

      $adminIdForPayroll = $_SESSION['admin_id'] ?? null;
      $muniSlug = 'GENERAL';
      $muniShort = 'GEN';
      if ($adminIdForPayroll) {
        $muniRes = pg_query_params($connection, "SELECT m.slug, m.name FROM municipalities m JOIN admins a ON a.municipality_id=m.municipality_id WHERE a.admin_id=$1 LIMIT 1", [$adminIdForPayroll]);
        if ($muniRes && ($mrow = pg_fetch_assoc($muniRes))) {
          $rawSlug = !empty($mrow['slug']) ? $mrow['slug'] : $mrow['name'];
          $muniSlug = strtoupper(preg_replace('/[^A-Z0-9]/', '', strtoupper($rawSlug)));
          $muniShort = $deriveShortCode($mrow['name'] ?: $rawSlug);
        }
      }
      // Final prefix in compact form: e.g., GTC-2026-1-XXXXXX
      $prefixBase = $muniShort . '-' . $startYear . '-' . $semNum . '-';

      // 1. Assign payroll numbers + formatted codes
        $result = pg_query($connection, "
            SELECT student_id
            FROM students
            WHERE status = 'active'
            ORDER BY last_name ASC, first_name ASC, middle_name ASC
        ");
        $payroll_no = 1;
        $student_payrolls = [];
        if ($result) {
            while ($row = pg_fetch_assoc($result)) {
                $student_id = $row['student_id'];
          // Build formatted payroll code (compact)
          $formattedCode = $prefixBase . str_pad((string)$payroll_no, 6, '0', STR_PAD_LEFT);
          // Assign formatted payroll code into payroll_no
          pg_query_params(
            $connection,
            "UPDATE students SET payroll_no = $1 WHERE student_id = $2",
            [$formattedCode, $student_id]
          );
          // Record history (academic year + semester) if table exists
          pg_query($connection, "DO $$ BEGIN IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name='distribution_payrolls') THEN NULL; END IF; END $$;");
          pg_query_params(
            $connection,
            "INSERT INTO distribution_payrolls (student_id, payroll_no, academic_year, semester) VALUES ($1,$2,$3,$4)\n             ON CONFLICT (student_id, academic_year, semester) DO UPDATE SET payroll_no=EXCLUDED.payroll_no, assigned_at=NOW()",
            [$student_id, $formattedCode, $currentYear, $currentSemester]
          );
                $student_payrolls[] = [
                    'student_id' => $student_id,
            'payroll_no' => $formattedCode
                ];
                $payroll_no++;
            }
            
            // Add admin notification
            $total_assigned = $payroll_no - 1;
          $notification_msg = "Payroll numbers generated for " . $total_assigned . " active students (format: CODE-YYYY-S-######)";
            pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
        }
        // 2. Immediately create QR code records for each student/payroll with a unique_id
        foreach ($student_payrolls as $sp) {
            $qr_exists = pg_query_params(
                $connection,
                "SELECT qr_id FROM qr_codes WHERE student_id = $1 AND payroll_number = $2",
                [$sp['student_id'], $sp['payroll_no']]
            );
            if (!pg_fetch_assoc($qr_exists)) {
                $unique_id = 'qr_' . uniqid();
                pg_query_params(
                    $connection,
                    "INSERT INTO qr_codes (payroll_number, student_id, unique_id, status, created_at) VALUES ($1, $2, $3, 'Pending', NOW())",
                    [$sp['payroll_no'], $sp['student_id'], $unique_id]
                );
            }
        }
      echo "<script>alert('Payroll numbers and QR codes generated successfully with formatted codes.'); window.location.href='verify_students.php';</script>";
        exit;
    }
}

// Get workflow status and finalized state after all POST actions
$workflow_status = getWorkflowStatus($connection);
$student_counts = getStudentCounts($connection);

// Re-read the finalized status after POST actions
$isFinalized = false;
$configResult = pg_query($connection, "SELECT value FROM config WHERE key = 'student_list_finalized'");
if ($configResult && ($row = pg_fetch_assoc($configResult))) {
  $isFinalized = ($row['value'] === '1');
} else {
  // Ensure persisted default remains unlocked after operations if missing
  pg_query($connection, "INSERT INTO config (key, value) VALUES ('student_list_finalized', '0') ON CONFLICT (key) DO UPDATE SET value = '0'");
  $isFinalized = false;
}

// Generate CSRF token for all forms on this page
$csrfToken = CSRFProtection::generateToken('verify_students_operation');

/* ---------------------------
   FILTERS
----------------------------*/
$sort = $_GET['sort'] ?? 'asc';
$barangayFilter = $_GET['barangay'] ?? '';
$searchSurname = trim($_GET['search_surname'] ?? '');

/* Active list for table (with applied filters) */
// Use helper to apply status, barangay, and surname search consistently

/* Barangay options */
$barangayOptions = [];
$barangayResult = pg_query($connection, "SELECT barangay_id, name FROM barangays ORDER BY name ASC");
while ($row = pg_fetch_assoc($barangayResult)) {
    $barangayOptions[] = $row;
}

/* Check workflow status for UI decisions */
// This is now calculated after POST actions above
?>
<?php $page_title='Verify Students'; $extra_css=['../../assets/css/admin/table_core.css', '../../assets/css/admin/verify_students.css']; include __DIR__ . '/../../includes/admin/admin_head.php'; ?>
</head>
<body>
<?php include __DIR__ . '/../../includes/admin/admin_topbar.php'; ?>
<div id="wrapper" class="admin-wrapper">
  <?php include __DIR__ . '/../../includes/admin/admin_sidebar.php'; ?>
  <?php include __DIR__ . '/../../includes/admin/admin_header.php'; ?>
  <section class="home-section" id="mainContent">
    <div class="container-fluid py-4 px-4">
      <!-- Page Header - Clean, stacked for mobile -->
      <div class="mb-4">
        <h1 class="fw-bold mb-1">Manage Student Status</h1>
        <p class="text-muted mb-2">Lock the active list for payroll generation, or revert students back to applicants.</p>
        <span class="badge bg-primary fs-6"><?= $student_counts['active_count'] ?> Active</span>
      </div>
        
      <!-- Workflow Status Indicators -->
      <div class="quick-actions mb-4">
        <div class="qa-header mb-2">
          <h5 class="mb-1 d-flex align-items-center"><i class="bi bi-info-circle-fill me-2"></i>Workflow Status</h5>
          <small>Current verification and payroll status</small>
        </div>
        <div class="status-grid">
          <div class="status-chip">
            <i class="bi <?= $workflow_status['list_finalized'] ? 'bi-lock-fill' : 'bi-unlock' ?>"></i>
            <span>List <?= $workflow_status['list_finalized'] ? 'Locked' : 'Not Locked' ?></span>
          </div>
          <div class="status-chip">
            <i class="bi <?= $workflow_status['has_payroll_qr'] ? 'bi-check-circle' : 'bi-clock' ?>"></i>
            <span>Payroll & QR <?= $workflow_status['has_payroll_qr'] ? 'Generated' : 'Pending' ?></span>
          </div>
          <div class="status-chip">
            <i class="bi <?= $workflow_status['has_schedules'] ? 'bi-calendar-check' : 'bi-calendar' ?>"></i>
            <span>Schedules <?= $workflow_status['has_schedules'] ? 'Created' : 'Not Created' ?></span>
          </div>
          <?php if ($workflow_status['has_schedules']): ?>
          <div class="status-chip status-chip--alert">
            <i class="bi bi-exclamation-triangle"></i>
            <span>Payroll locked due to schedules</span>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Filters -->
      <div class="filter-section mb-4">
          <form method="GET" class="filter-grid filter-grid-4">
            <div class="filter-group">
              <label class="form-label">Sort</label>
              <select name="sort" class="form-select">
                <option value="asc"  <?= $sort === 'asc'  ? 'selected' : '' ?>>A to Z</option>
                <option value="desc" <?= $sort === 'desc' ? 'selected' : '' ?>>Z to A</option>
              </select>
            </div>
            <div class="filter-group">
              <label class="form-label">Search Surname</label>
              <input type="text" name="search_surname" class="form-control" value="<?= htmlspecialchars($searchSurname) ?>" placeholder="Enter surname...">
            </div>
            <div class="filter-group">
              <label class="form-label">Barangay</label>
              <select name="barangay" class="form-select">
                <option value="">All Barangays</option>
                <?php foreach ($barangayOptions as $b): ?>
                  <option value="<?= $b['barangay_id'] ?>" <?= $barangayFilter == $b['barangay_id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($b['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="filter-buttons">
              <button type="submit" class="btn btn-primary">Filter</button>
              <button type="button" class="btn btn-outline-secondary" id="resetFiltersBtn">Clear</button>
            </div>
          </form>
        </div>
      </div>

      <!-- Active Students -->
      <form method="POST" id="activeStudentsForm">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <div class="card shadow-sm mb-4">
          <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-people-fill me-2"></i>Active Students</span>
            <span class="badge" style="background: rgba(255,255,255,0.2); color: white;"><?= $isFinalized ? 'Locked' : 'Not Locked' ?></span>
          </div>
          <div class="card-body">
            <div class="table-card">
              <div class="table-responsive">
                <table class="table align-middle verify-table">
                  <thead>
                    <tr>
                      <th class="checkbox-col">
                        <input type="checkbox" id="selectAllActive" <?= $isFinalized ? 'disabled' : '' ?>>
                      </th>
                      <th>Full Name</th>
                      <th>Email</th>
                      <th>Mobile Number</th>
                      <th>Barangay</th>
                      <th class="payroll-col">Payroll #</th>
                      <th class="qr-col">QR Generated?</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php
                  // Re-apply barangay filter and sort using helper to ensure consistent join with barangays
                  $actives = fetch_students($connection, 'active', $sort, $barangayFilter, $searchSurname);
                  if ($actives && pg_num_rows($actives) > 0):
                    while ($row = pg_fetch_assoc($actives)):
                      $id       = htmlspecialchars($row['student_id'], ENT_QUOTES);
                      $name     = htmlspecialchars($row['last_name'] . ', ' . $row['first_name'] . ' ' . $row['middle_name']);
                      $email    = htmlspecialchars($row['email'] ?? '');
                      $mobile   = htmlspecialchars($row['mobile'] ?? '');
                      $barangay = htmlspecialchars($row['barangay'] ?? '');
                      $payroll  = htmlspecialchars((string)($row['payroll_no'] ?? ''));
                      $unique_id = $row['unique_id'];
                      $mothers_maiden = htmlspecialchars($row['mothers_maiden_name'] ?? '');
                      $admin_review_required = ($row['admin_review_required'] ?? false) == 't';
                      
                      // Check if QR code exists (don't display the actual QR image for security)
                      $has_qr = !empty($payroll) && !empty($unique_id);
                  ?>
                      <tr onclick="showStudentOptions('<?= $id ?>', '<?= htmlspecialchars($name, ENT_QUOTES) ?>', '<?= htmlspecialchars($email, ENT_QUOTES) ?>', '<?= htmlspecialchars($barangay, ENT_QUOTES) ?>')" style="cursor: pointer; <?= $admin_review_required ? 'background-color: #fff3cd;' : '' ?>" title="Click for options">
                        <td class="checkbox-col" data-label="Select" onclick="event.stopPropagation();">
                          <input type="checkbox" name="selected_actives[]" value="<?= $id ?>" <?= $isFinalized ? 'disabled' : '' ?> />
                        </td>
                        <td data-label="Full Name">
                          <?= $name ?>
                          <?php if ($admin_review_required): ?>
                            <span class="badge bg-warning text-dark ms-2" data-bs-toggle="tooltip" data-bs-placement="right" 
                                  title="Requires Admin Review: Mother's maiden name matches surname">
                              <i class="bi bi-exclamation-triangle-fill"></i> Review
                            </span>
                          <?php endif; ?>
                        </td>
                        <td data-label="Email"><?= $email ?></td>
                        <td data-label="Mobile Number"><?= $mobile ?></td>
                        <td data-label="Barangay"><?= $barangay ?></td>
                        <td data-label="Payroll #" class="payroll-col">
                          <?php if ($isFinalized && !empty($payroll)): ?>
                            <div><strong><?= $payroll ?></strong></div>
                          <?php else: ?>
                            <span class="text-muted">N/A</span>
                          <?php endif; ?>
                        </td>
                        <td data-label="QR Generated?" class="qr-col">
                          <?php if ($isFinalized && $has_qr): ?>
                            <span class="badge bg-success">
                              <i class="bi bi-check-circle me-1"></i>Yes
                            </span>
                          <?php elseif ($isFinalized && !empty($payroll)): ?>
                            <span class="badge bg-warning text-dark">
                              <i class="bi bi-clock me-1"></i>Pending
                            </span>
                          <?php else: ?>
                            <span class="badge bg-secondary">
                              <i class="bi bi-x-circle me-1"></i>No
                            </span>
                          <?php endif; ?>
                        </td>
                      </tr>
                  <?php
                    endwhile;
                  else:
                      $msg = !empty($searchSurname)
                        ? 'No student found with the surname \'' . htmlspecialchars($searchSurname, ENT_QUOTES) . '\'.'
                        : 'No active students found.';
                      echo '<tr><td colspan="7" class="text-center text-muted">' . $msg . '</td></tr>';
                  endif;
                  ?>
                </tbody>
              </table>
            </div>
          </div>

            <div class="d-flex flex-wrap gap-2 mt-2">
              <button type="submit" name="deactivate" class="btn btn-danger" id="revertBtn" <?= $isFinalized ? 'disabled' : '' ?>>
                <i class="bi bi-arrow-counterclockwise me-1"></i> Revert to Applicant
              </button>

              <?php if ($isFinalized): ?>
                <?php if ($workflow_status['has_schedules']): ?>
                <button type="button" class="btn btn-warning" disabled title="Cannot revert - schedules exist">
                  <i class="bi bi-backspace-reverse-fill me-1"></i> Revert List
                  <small class="d-block">Remove schedules first</small>
                </button>
                <?php elseif ($has_scanned_students): ?>
                <button type="button" class="btn btn-warning" disabled title="Cannot revert - students have been scanned">
                  <i class="bi bi-backspace-reverse-fill me-1"></i> Revert List
                  <small class="d-block">Distribution in progress</small>
                </button>
                <?php else: ?>
                <button type="button" class="btn btn-warning" id="revertTriggerBtn">
                  <i class="bi bi-backspace-reverse-fill me-1"></i> Revert List
                </button>
                <?php endif; ?>
                <?php if (!$workflow_status['has_payroll_qr']): ?>
                <button type="button" class="btn btn-primary ms-auto" id="generatePayrollBtn">
                  <i class="bi bi-gear me-1"></i> Generate Payroll Numbers
                </button>
                <?php else: ?>
                <button type="button" class="btn btn-primary ms-auto" id="generatePayrollBtn" disabled title="Payroll numbers already generated">
                  <i class="bi bi-check me-1"></i> Payroll Generated
                </button>
                <!-- DEV Tool: View QR Codes -->
                <a href="view_qr_codes_dev.php" class="btn btn-outline-warning ms-2" title="Development Tool - View Student QR Codes">
                  <i class="bi bi-qr-code me-1"></i> View QR Codes (DEV)
                </a>
                <?php endif; ?>
              <?php else: ?>
                <button type="button" class="btn btn-success" id="finalizeTriggerBtn">
                  <i class="bi bi-lock me-1"></i> Lock List
                </button>
                <input type="hidden" name="finalize_list" id="finalizeListInput" value="">
              <?php endif; ?>
              
              <!-- Next Steps Information -->
              <?php if ($isFinalized && !$workflow_status['has_payroll_qr']): ?>
              <div class="alert alert-warning mt-3 w-100">
                <i class="bi bi-exclamation-triangle me-2"></i>
                <strong>Next Step:</strong> Click "Generate Payroll Numbers" to create payroll numbers and QR codes. 
                This will unlock <strong>Scheduling</strong> and <strong>QR Scanning</strong> features.
              </div>
              <?php elseif ($workflow_status['has_payroll_qr'] && !$workflow_status['has_schedules']): ?>
              <div class="alert alert-info mt-3 w-100">
                <i class="bi bi-info-circle me-2"></i>
                <strong>Ready:</strong> Payroll numbers and QR codes are generated! You can now access 
                <a href="manage_schedules.php" class="alert-link">Scheduling</a> and 
                <a href="scan_qr.php" class="alert-link">QR Scanning</a> features.
              </div>
              <?php elseif ($workflow_status['has_schedules']): ?>
              <div class="alert alert-success mt-3 w-100">
                <i class="bi bi-check-circle me-2"></i>
                <strong>System Ready:</strong> All features are now available. 
                Schedules have been created - manage them in <a href="manage_schedules.php" class="alert-link">Scheduling</a>.
              </div>
              <?php elseif (!$isFinalized): ?>
              <div class="alert alert-primary mt-3 w-100">
                <i class="bi bi-info-circle me-2"></i>
                <strong>Getting Started:</strong> First, approve applicants in the <a href="manage_applicants.php" class="alert-link">Manage Applicants</a> page. 
                Once you have verified students, lock the student list here, then generate payroll numbers to unlock all features.
              </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </form>
    </div>
  </section>
</div>

<!-- Lock List Modal -->
<div class="modal fade" id="finalizeModal" tabindex="-1" aria-labelledby="finalizeModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="finalizeModalLabel">Lock Student List</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        Are you sure you want to lock the student list? Once locked, you can generate payroll numbers and QR codes.
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-success" id="finalizeConfirmBtnModal">
          <i class="bi bi-lock me-1"></i> Lock List
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Generate Payroll Modal -->
<div class="modal fade" id="generatePayrollModal" tabindex="-1" aria-labelledby="generatePayrollModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="generatePayrollModalLabel">Generate Payroll Numbers</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        Generate payroll numbers for all active students (A→Z by name). This will overwrite any existing payroll numbers.
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="generatePayrollConfirmBtnModal">Generate</button>
      </div>
    </div>
  </div>
</div>

<!-- Revert List Modal -->
<div class="modal fade" id="revertListModal" tabindex="-1" aria-labelledby="revertListModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="revertListModalLabel">Revert List</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        Revert the finalized list? Payroll numbers for actives will be reset to 0.
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="revertListConfirmBtnModal">Yes, Revert</button>
      </div>
    </div>
  </div>
</div>

<!-- Student Options Modal -->
<div class="modal fade" id="studentOptionsModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Student Options</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p id="studentOptionsInfo"></p>
                <div class="d-grid gap-2">
                    <button type="button" class="btn btn-info" onclick="viewStudentDetails()">
                        <i class="bi bi-eye me-2"></i>View Student Details
                    </button>
                    <?php if ($_SESSION['admin_role'] === 'super_admin'): ?>
                    <button type="button" class="btn btn-danger" onclick="triggerBlacklistFromOptions()">
                        <i class="bi bi-shield-exclamation me-2"></i>Blacklist Student
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Student Details Modal -->
<div class="modal fade" id="studentDetailsModal" tabindex="-1" aria-labelledby="studentDetailsLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-xl modal-fullscreen-sm-down">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="studentDetailsLabel">Student Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="studentDetailsBody">
        <div class="d-flex align-items-center gap-2 text-muted">
          <div class="spinner-border spinner-border-sm" role="status"></div>
          <span>Loading details…</span>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
  </div>

<!-- Include Blacklist Modal -->
<?php include __DIR__ . '/../../includes/admin/blacklist_modal.php'; ?>

<!-- JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../../assets/js/admin/sidebar.js"></script>
<script>
  // ====== Lock List flow ======
  function finalizeHandler(e) {
    e.preventDefault();
    new bootstrap.Modal(document.getElementById('finalizeModal')).show();
  }
  document.getElementById('finalizeTriggerBtn')?.addEventListener('click', finalizeHandler);
  document.getElementById('finalizeConfirmBtnModal')?.addEventListener('click', function () {
    document.getElementById('finalizeListInput').value = '1';
    document.getElementById('activeStudentsForm').submit();
  });

  // ====== Generate Payroll flow ======
  document.getElementById('generatePayrollBtn')?.addEventListener('click', function () {
    new bootstrap.Modal(document.getElementById('generatePayrollModal')).show();
  });
  document.getElementById('generatePayrollConfirmBtnModal')?.addEventListener('click', function () {
    // Hidden POST form
    var form = document.createElement('form');
    form.method = 'POST';
    form.style.display = 'none';
    var input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'generate_payroll';
    input.value = '1';
    form.appendChild(input);
    // Add CSRF token
    var csrfInput = document.createElement('input');
    csrfInput.type = 'hidden';
    csrfInput.name = 'csrf_token';
    csrfInput.value = '<?= htmlspecialchars($csrfToken) ?>';
    form.appendChild(csrfInput);
    document.body.appendChild(form);
    form.submit();
  });

  // ====== Revert List flow ======
  document.getElementById('revertTriggerBtn')?.addEventListener('click', function (e) {
    e.preventDefault();
    new bootstrap.Modal(document.getElementById('revertListModal')).show();
  });
  document.getElementById('revertListConfirmBtnModal')?.addEventListener('click', function () {
    var form = document.createElement('form');
    form.method = 'POST';
    form.style.display = 'none';
    var input1 = document.createElement('input');
    input1.type = 'hidden';
    input1.name = 'revert_list';
    input1.value = '1';
    var input2 = document.createElement('input');
    input2.type = 'hidden';
    input2.name = 'reset_payroll';
    input2.value = '1';
    form.appendChild(input1);
    form.appendChild(input2);
    // Add CSRF token
    var csrfInput = document.createElement('input');
    csrfInput.type = 'hidden';
    csrfInput.name = 'csrf_token';
    csrfInput.value = '<?= htmlspecialchars($csrfToken) ?>';
    form.appendChild(csrfInput);
    document.body.appendChild(form);
    form.submit();
  });

  // ====== Reset Filters ======
  document.getElementById('resetFiltersBtn')?.addEventListener('click', function(){
    // Navigate to base path without query string
    window.location.href = window.location.pathname;
  });

  // ====== Select-all for active students ======
  window.addEventListener('load', function() {
    var isFinalized = <?= $isFinalized ? 'true' : 'false' ?>;
    // Disable/enable inputs based on finalized
    document.querySelectorAll("input[name='selected_actives[]']").forEach(cb => cb.disabled = isFinalized);
    var revertBtn = document.getElementById('revertBtn');

    function updateRevertState() {
      var selectedCount = document.querySelectorAll("input[name='selected_actives[]']:checked").length;
      if (revertBtn) revertBtn.disabled = isFinalized || selectedCount === 0;
    }

    // Initial state
    updateRevertState();

    // Payroll and QR columns are always visible; cell content is N/A if not finalized

    // Select all behavior
    var selectAll = document.getElementById('selectAllActive');
    if (selectAll) {
      selectAll.addEventListener('change', function() {
        document.querySelectorAll("input[name='selected_actives[]']").forEach(cb => {
          if (!cb.disabled) cb.checked = selectAll.checked;
        });
        updateRevertState();
      });
    }

    // Individual checkbox changes
    document.querySelectorAll("input[name='selected_actives[]']").forEach(cb => {
      cb.addEventListener('change', updateRevertState);
    });
    
    // Mobile sticky action bar - show/hide based on selection
    function updateMobileStickyBar() {
      var actionBar = document.querySelector('#activeStudentsForm .d-flex.flex-wrap.gap-2.mt-2');
      if (!actionBar) return;
      
      // Only apply on mobile
      if (window.innerWidth <= 767) {
        var count = document.querySelectorAll("input[name='selected_actives[]']:checked").length;
        if (count > 0) {
          actionBar.classList.add('show-actions');
        } else {
          actionBar.classList.remove('show-actions');
        }
      } else {
        // Remove class on desktop
        actionBar.classList.remove('show-actions');
      }
    }
    
    // Update sticky bar on checkbox change
    if (selectAll) {
      selectAll.addEventListener('change', updateMobileStickyBar);
    }
    document.querySelectorAll("input[name='selected_actives[]']").forEach(cb => {
      cb.addEventListener('change', updateMobileStickyBar);
    });
    
    // Update on window resize
    window.addEventListener('resize', updateMobileStickyBar);
    
    // Initial check
    updateMobileStickyBar();

    // Prevent submit if none selected
    var form = document.getElementById('activeStudentsForm');
    if (form) {
      form.addEventListener('submit', function(e){
        if (document.activeElement && document.activeElement.name === 'deactivate') {
          var count = document.querySelectorAll("input[name='selected_actives[]']:checked").length;
          if (count === 0) {
            e.preventDefault();
            alert('Please select at least one student to revert.');
            return;
          }
          // Confirm revert action with selected count
          var proceed = confirm('Revert ' + count + ' selected student(s) back to applicant?');
          if (!proceed) {
            e.preventDefault();
            return;
          }
        }
      });
    }
  });

  // Student options functionality
  let currentStudent = null;

  function showStudentOptions(studentId, studentName, studentEmail, barangay) {
    currentStudent = {
      id: studentId,
      name: studentName,
      email: studentEmail,
      barangay: barangay
    };
    
    document.getElementById('studentOptionsInfo').innerHTML = `
      <strong>Student:</strong> ${studentName}<br>
      <strong>Email:</strong> ${studentEmail}<br>
      <strong>Barangay:</strong> ${barangay}
    `;
    
    new bootstrap.Modal(document.getElementById('studentOptionsModal')).show();
  }

  function viewStudentDetails() {
    if (currentStudent) {
      const detailsModal = new bootstrap.Modal(document.getElementById('studentDetailsModal'));
      const body = document.getElementById('studentDetailsBody');
      document.getElementById('studentDetailsLabel').textContent = 'Student Details – ' + currentStudent.name;
      body.innerHTML = '<div class="d-flex align-items-center gap-2 text-muted"><div class="spinner-border spinner-border-sm" role="status"></div><span>Loading details…</span></div>';
      detailsModal.show();
      fetch('ajax_student_details.php?student_id=' + encodeURIComponent(currentStudent.id), {cache:'no-store'})
        .then(r => r.text())
        .then(html => { body.innerHTML = html; })
        .catch(err => {
          console.error(err);
          body.innerHTML = '<div class="alert alert-danger">Failed to load details. Please try again.</div>';
        });
    }
  }

  function triggerBlacklistFromOptions() {
    if (currentStudent) {
      // Close the options modal first
      bootstrap.Modal.getInstance(document.getElementById('studentOptionsModal')).hide();
      
      // Show blacklist modal
      setTimeout(() => {
        showBlacklistModal(
          currentStudent.id, 
          currentStudent.name, 
          currentStudent.email, 
          {
            barangay: currentStudent.barangay,
            status: 'Active Student'
          }
        );
      }, 300);
    }
  }
</script>
</body>
</html>
<?php pg_close($connection); ?>

