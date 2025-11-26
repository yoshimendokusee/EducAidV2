<?php
/**
 * View QR Codes (DEV Tool)
 * Displays generated QR codes for students with payroll numbers
 * ⚠️ FOR DEVELOPMENT USE ONLY - Quick QR scanning without students on-site
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['admin_username'])) {
    header("Location: ../../unified_login.php");
    exit;
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/FilePathConfig.php';

$pathConfig = FilePathConfig::getInstance();

// Get filters
$barangayFilter = $_GET['barangay'] ?? '';
$searchTerm = trim($_GET['search'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Build query
$query = "
    SELECT 
        s.student_id,
        s.first_name,
        s.middle_name,
        s.last_name,
        s.payroll_no,
        s.student_picture,
        b.name AS barangay,
        q.unique_id AS qr_unique_id,
        q.status AS qr_status
    FROM students s
    JOIN barangays b ON s.barangay_id = b.barangay_id
    LEFT JOIN qr_codes q ON s.student_id = q.student_id AND s.payroll_no = q.payroll_number
    WHERE s.status = 'active'
    AND s.payroll_no IS NOT NULL
    AND q.unique_id IS NOT NULL
";

$params = [];
$paramIndex = 1;

if (!empty($barangayFilter)) {
    $query .= " AND b.barangay_id = $" . $paramIndex;
    $params[] = $barangayFilter;
    $paramIndex++;
}

if (!empty($searchTerm)) {
    $query .= " AND (s.last_name ILIKE $" . $paramIndex . " OR s.first_name ILIKE $" . $paramIndex . ")";
    $params[] = "%$searchTerm%";
    $paramIndex++;
}

$query .= " ORDER BY CASE WHEN q.status ILIKE 'pending' THEN 0 ELSE 1 END, s.last_name ASC, s.first_name ASC";
$query .= " LIMIT $perPage OFFSET $offset";

// Execute query with or without parameters (avoid generic $result collisions from includes)
if (!empty($params)) {
  $qr_result = pg_query_params($connection, $query, $params);
} else {
  $qr_result = pg_query($connection, $query);
}

// Check for errors
if (!$qr_result) {
  echo "<div class='alert alert-danger'>QR Query Error: " . pg_last_error($connection) . "</div>";
  echo "<pre>Query: " . htmlspecialchars($query) . "</pre>";
}

// Get total count for pagination
$countQuery = "
  SELECT COUNT(*) as total
  FROM students s
  JOIN barangays b ON s.barangay_id = b.barangay_id
  LEFT JOIN qr_codes q ON s.student_id = q.student_id AND s.payroll_no = q.payroll_number
  WHERE s.status = 'active'
  AND s.payroll_no IS NOT NULL
  AND q.unique_id IS NOT NULL
"; // count unaffected by ordering
$countParams = [];
$countIndex = 1;
if (!empty($barangayFilter)) {
    $countQuery .= " AND b.barangay_id = $" . $countIndex;
    $countParams[] = $barangayFilter;
    $countIndex++;
}
if (!empty($searchTerm)) {
    $countQuery .= " AND (s.last_name ILIKE $" . $countIndex . " OR s.first_name ILIKE $" . $countIndex . ")";
    $countParams[] = "%$searchTerm%";
}

// Execute count query
if (!empty($countParams)) {
  $count_result = pg_query_params($connection, $countQuery, $countParams);
} else {
  $count_result = pg_query($connection, $countQuery);
}

if (!$count_result) {
  echo "<div class='alert alert-danger'>Count Query Error: " . pg_last_error($connection) . "</div>";
  echo "<pre>Count Query: " . htmlspecialchars($countQuery) . "</pre>";
}

$count_row = $count_result ? pg_fetch_assoc($count_result) : null;
$totalStudents = $count_row['total'] ?? 0;
$totalPages = ceil($totalStudents / $perPage);

// Get barangays for filter
$barangaysResult = pg_query($connection, "SELECT barangay_id, name FROM barangays ORDER BY name ASC");
$barangays = pg_fetch_all($barangaysResult) ?: [];

// Function to get QR code display URL (dynamic generator)
function getQrCodeUrl($uniqueId) {
  if (empty($uniqueId)) return null;
  // Use dynamic generation script (no stored PNGs needed)
  // Path relative to this admin module file
  return 'phpqrcode/generate_qr.php?data=' . urlencode($uniqueId);
}
?>
<?php $page_title='View QR Codes (DEV)'; $extra_css=[]; include __DIR__ . '/../../includes/admin/admin_head.php'; ?>
<link rel="stylesheet" href="../../assets/css/admin/table_core.css"/>
<style>
.qr-card {
    border: 1px solid #dee2e6;
    border-radius: 8px;
    padding: 20px;
    background: white;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    transition: transform 0.2s, box-shadow 0.2s;
}
.qr-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.15);
}
.qr-image {
    width: 250px;
    height: 250px;
    object-fit: contain;
    border: 2px solid #f0f0f0;
    border-radius: 4px;
    background: white;
}
.dev-warning {
    background: linear-gradient(135deg, #ff6b6b 0%, #ee5a6f 100%);
    color: white;
    padding: 15px 20px;
    border-radius: 8px;
    margin-bottom: 20px;
    font-weight: 500;
}
.student-photo {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid #dee2e6;
}
</style>
</head>
<body>
<?php include __DIR__ . '/../../includes/admin/admin_topbar.php'; ?>
<div id="wrapper" class="admin-wrapper">
  <?php include __DIR__ . '/../../includes/admin/admin_sidebar.php'; ?>
  <?php include __DIR__ . '/../../includes/admin/admin_header.php'; ?>
  <section class="home-section" id="mainContent">
    <div class="container-fluid py-4 px-4">
      
      <!-- DEV Warning Banner -->
      <div class="dev-warning">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <strong>⚠️ DEVELOPMENT TOOL</strong> - For Testing Only. This page displays student QR codes for testing purposes without requiring students on-site.
      </div>

      <!-- Page Header -->
      <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
          <h1 class="mb-1">View QR Codes</h1>
          <p class="text-muted mb-0">Displaying <?= $totalStudents ?> student(s) with generated QR codes</p>
        </div>
        <a href="verify_students.php" class="btn btn-secondary">
          <i class="bi bi-arrow-left me-1"></i> Back to Verify Students
        </a>
      </div>

      <!-- Filters -->
      <div class="card shadow-sm mb-4">
        <div class="card-body">
          <form method="GET" class="row g-3">
            <div class="col-md-5">
              <label class="form-label">Filter by Barangay</label>
              <select name="barangay" class="form-select">
                <option value="">All Barangays</option>
                <?php foreach ($barangays as $b): ?>
                  <option value="<?= $b['barangay_id'] ?>" <?= $barangayFilter == $b['barangay_id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($b['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-5">
              <label class="form-label">Search by Name</label>
              <input type="text" name="search" class="form-control" placeholder="Enter surname or first name" value="<?= htmlspecialchars($searchTerm) ?>">
            </div>
            <div class="col-md-2 d-flex align-items-end">
              <button type="submit" class="btn btn-primary w-100">
                <i class="bi bi-funnel me-1"></i> Filter
              </button>
            </div>
          </form>
        </div>
      </div>

      <!-- QR Code Grid -->
      <?php
      $qr_rows = $qr_result ? pg_num_rows($qr_result) : 0;
      if ($totalStudents > 0 && $qr_rows === 0) {
          echo "<div class='alert alert-warning'>Diagnostic: Count reports $totalStudents student(s) with QR codes but main query returned 0 rows. Likely variable collision or include overwrote result set. Variable now isolated as <code>$qr_result</code>.</div>";
      }
      ?>
      <?php if ($qr_result && $qr_rows > 0): ?>
      <div class="row g-4 mb-4">
        <?php while ($student = pg_fetch_assoc($qr_result)) : 
          $fullName = trim($student['first_name'] . ' ' . ($student['middle_name'] ?? '') . ' ' . $student['last_name']);
          $qrUrl = getQrCodeUrl($student['qr_unique_id']);
          $photoPath = !empty($student['student_picture']) ? '../../' . ltrim($student['student_picture'], '/') : null;
        ?>
        <div class="col-lg-3 col-md-4 col-sm-6" data-student-id="<?= htmlspecialchars($student['student_id']) ?>" data-status="<?= htmlspecialchars(strtolower($student['qr_status'])) ?>">
          <div class="qr-card text-center">
            <!-- Student Info -->
            <div class="mb-3 student-info-wrapper">
              <?php if ($photoPath && file_exists($photoPath)): ?>
                <img src="<?= htmlspecialchars($photoPath) ?>" alt="Photo" class="student-photo mb-2">
              <?php else: ?>
                <div class="student-photo mb-2 d-flex align-items-center justify-content-center mx-auto" style="background: #e9ecef;">
                  <i class="bi bi-person-fill fs-3 text-muted"></i>
                </div>
              <?php endif; ?>
              <h6 class="mb-1 fw-bold name"><?= htmlspecialchars($fullName) ?></h6>
              <small class="text-muted d-block barangay"><?= htmlspecialchars($student['barangay']) ?></small>
              <?php if (!empty($student['payroll_no'])): ?>
                <small class="badge bg-primary mt-1 payroll"><?= htmlspecialchars($student['payroll_no']) ?></small>
              <?php endif; ?>
            </div>

            <!-- QR Code -->
            <?php if ($qrUrl): ?>
              <img src="<?= htmlspecialchars($qrUrl) ?>" alt="QR Code" class="qr-image mx-auto">
              <div class="mt-2 status-holder">
                <?php if (strtolower($student['qr_status']) === 'pending'): ?>
                  <span class="badge bg-warning text-dark"><i class="bi bi-clock-history me-1"></i>Pending Scan</span>
                <?php elseif (strtolower($student['qr_status']) === 'scanned'): ?>
                  <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Scanned</span>
                <?php else: ?>
                  <span class="badge bg-secondary"><i class="bi bi-info-circle me-1"></i><?= htmlspecialchars($student['qr_status']) ?></span>
                <?php endif; ?>
              </div>
            <?php else: ?>
              <div class="qr-image mx-auto d-flex align-items-center justify-content-center" style="background: #f8f9fa;">
                <div class="text-center text-muted">
                  <i class="bi bi-qr-code fs-1 d-block mb-2"></i>
                  <small>No QR Data</small>
                </div>
              </div>
              <div class="mt-2 status-holder">
                <span class="badge bg-secondary"><i class="bi bi-info-circle me-1"></i>N/A</span>
              </div>
            <?php endif; ?>
          </div>
        </div>
        <?php endwhile; ?>
      </div>

      <!-- Pagination -->
      <?php if ($totalPages > 1): ?>
      <nav aria-label="Page navigation">
        <ul class="pagination justify-content-center">
          <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
            <a class="page-link" href="?page=<?= $page - 1 ?>&barangay=<?= urlencode($barangayFilter) ?>&search=<?= urlencode($searchTerm) ?>">Previous</a>
          </li>
          <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <li class="page-item <?= $i == $page ? 'active' : '' ?>">
              <a class="page-link" href="?page=<?= $i ?>&barangay=<?= urlencode($barangayFilter) ?>&search=<?= urlencode($searchTerm) ?>"><?= $i ?></a>
            </li>
          <?php endfor; ?>
          <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
            <a class="page-link" href="?page=<?= $page + 1 ?>&barangay=<?= urlencode($barangayFilter) ?>&search=<?= urlencode($searchTerm) ?>">Next</a>
          </li>
        </ul>
      </nav>
      <?php endif; ?>

      <?php else: ?>
      <div class="alert alert-info">
        <i class="bi bi-info-circle me-2"></i>
        No students found with QR codes. Make sure payroll numbers have been generated and QR codes are created.
      </div>
      <?php endif; ?>

    </div>
  </section>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../../assets/js/admin/sidebar.js"></script>
<script>
// Live polling for QR status updates
const QR_POLL_INTERVAL = 10000; // 10s
function fetchQrStatuses() {
  const params = new URLSearchParams();
  const barangaySelect = document.querySelector('select[name="barangay"]');
  const searchInput = document.querySelector('input[name="search"]');
  if (barangaySelect && barangaySelect.value) params.append('barangay', barangaySelect.value);
  if (searchInput && searchInput.value.trim()) params.append('search', searchInput.value.trim());
  fetch('ajax_qr_codes_feed.php?' + params.toString(), {cache: 'no-store'})
    .then(r => r.json())
    .then(json => {
      if (!json.data) return;
      updateQrGrid(json.data);
    })
    .catch(err => console.error('QR feed error', err));
}

function statusBadgeHTML(status) {
  const s = (status||'').toLowerCase();
  if (s === 'pending') return '<span class="badge bg-warning text-dark"><i class="bi bi-clock-history me-1"></i>Pending Scan</span>';
  if (s === 'scanned') return '<span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Scanned</span>';
  return '<span class="badge bg-secondary"><i class="bi bi-info-circle me-1"></i>'+escapeHtml(status)+'</span>';
}

function escapeHtml(str){return (str||'').replace(/[&<>"']/g,m=>({"&":"&amp;","<":"&lt;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[m]));}

function updateQrGrid(data) {
  const grid = document.querySelector('.row.g-4');
  if (!grid) return;
  // Build a map of existing cards
  const existing = new Map();
  grid.querySelectorAll('[data-student-id]').forEach(card => {
    existing.set(card.getAttribute('data-student-id'), card);
  });
  // Separate pending and scanned for ordering
  const pending = [];
  const scanned = [];
  data.forEach(item => {
    const targetArr = (item.qr_status||'').toLowerCase()==='pending' ? pending : scanned;
    targetArr.push(item);
  });
  const ordered = pending.concat(scanned);
  // Rebuild / update
  ordered.forEach(item => {
    let card = existing.get(item.student_id);
    const qrUrl = 'phpqrcode/generate_qr.php?data=' + encodeURIComponent(item.qr_unique_id);
    if (!card) {
      card = document.createElement('div');
      card.className = 'col-lg-3 col-md-4 col-sm-6';
      card.setAttribute('data-student-id', item.student_id);
      card.innerHTML = `
        <div class="qr-card text-center">
          <div class="mb-3 student-info-wrapper">
            <div class="student-photo mb-2 d-flex align-items-center justify-content-center mx-auto" style="background:#e9ecef;">
              <i class="bi bi-person-fill fs-3 text-muted"></i>
            </div>
            <h6 class="mb-1 fw-bold name"></h6>
            <small class="text-muted d-block barangay"></small>
            <small class="badge bg-primary mt-1 payroll"></small>
          </div>
          <img class="qr-image mx-auto" alt="QR Code">
          <div class="mt-2 status-holder"></div>
        </div>`;
      grid.appendChild(card);
    }
    // Update contents
    card.querySelector('.name').textContent = item.full_name;
    card.querySelector('.barangay').textContent = item.barangay;
    card.querySelector('.payroll').textContent = item.payroll_no;
    const img = card.querySelector('.qr-image');
    if (img.getAttribute('src') !== qrUrl) img.setAttribute('src', qrUrl);
    card.querySelector('.status-holder').innerHTML = statusBadgeHTML(item.qr_status);
    card.setAttribute('data-status', (item.qr_status||'').toLowerCase());
  });
  // Remove cards no longer present
  existing.forEach((card, sid) => {
    if (!ordered.find(i => i.student_id === sid)) {
      card.remove();
    }
  });
  // Reorder DOM to reflect pending first
  ordered.forEach(item => {
    const card = grid.querySelector('[data-student-id="'+item.student_id+'"]');
    if (card) grid.appendChild(card); // append moves to end preserving order sequence
  });
}

document.addEventListener('DOMContentLoaded', () => {
  fetchQrStatuses();
  setInterval(fetchQrStatuses, QR_POLL_INTERVAL);
  // Re-fetch on filter submit
  const form = document.querySelector('form.row');
  if (form) form.addEventListener('submit', () => setTimeout(fetchQrStatuses, 300));
});
</script>
</body>
</html>
<?php pg_close($connection); ?>
