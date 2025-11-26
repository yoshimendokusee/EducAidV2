<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['admin_username'])) { http_response_code(401); echo 'Unauthorized'; exit; }
require_once __DIR__ . '/../../config/database.php';

$student_id = $_GET['student_id'] ?? '';
if ($student_id === '') { http_response_code(400); echo 'Missing student_id'; exit; }

// Core profile fields (no documents)
$query = "
  SELECT s.student_id, s.first_name, s.middle_name, s.last_name,
         s.email, s.mobile, s.status, s.payroll_no,
         s.mothers_maiden_name, s.student_picture,
         b.name AS barangay
  FROM students s
  JOIN barangays b ON s.barangay_id = b.barangay_id
  WHERE s.student_id = $1
  LIMIT 1";
$res = pg_query_params($connection, $query, [$student_id]);
if (!$res || pg_num_rows($res) === 0) { echo '<div class="alert alert-warning">Student not found.</div>'; exit; }
$row = pg_fetch_assoc($res);
pg_free_result($res);

$fullName = trim(($row['first_name'] ?? '') . ' ' . ($row['middle_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
$photoRel = '';
if (!empty($row['student_picture'])) {
  // Construct relative path from modules/admin
  $photoRel = '../../' . ltrim($row['student_picture'], '/');
}
?>
<style>
  /* Scoped to this modal content */
  .sd-view .sd-label{font-size:.85rem;color:#6c757d}
  .sd-view .sd-value{font-weight:600;word-break:break-word}
  .sd-view .sd-avatar{width:110px;height:110px;border-radius:50%;object-fit:cover;border:2px solid #dee2e6}
  @media (min-width: 768px){.sd-view .sd-avatar{width:140px;height:140px}}
  .sd-view .chip{background:#e9ecef;color:#495057;border-radius:999px;padding:.25rem .5rem;font-size:.8rem}
  .sd-view .grid{row-gap:1rem}
</style>
<div class="container-fluid sd-view">
  <div class="row grid">
    <div class="col-12 col-md-3 d-flex justify-content-center justify-content-md-start mb-3 mb-md-0">
      <div class="text-center text-md-start w-100">
        <?php if ($photoRel && file_exists(__DIR__ . '/../../' . ltrim($row['student_picture'], '/'))): ?>
          <img src="<?= htmlspecialchars($photoRel) ?>" alt="Photo" class="sd-avatar" />
        <?php else: ?>
          <div class="sd-avatar d-flex align-items-center justify-content-center" style="background:#e9ecef;">
            <i class="bi bi-person-fill" style="font-size:3rem;color:#6c757d;"></i>
          </div>
        <?php endif; ?>
        <div class="mt-2"><span class="chip">ID: <?= htmlspecialchars($row['student_id']) ?></span></div>
      </div>
    </div>
    <div class="col-12 col-md-9">
      <div class="row row-cols-1 row-cols-md-2 g-3">
        <div class="col">
          <div class="sd-label">Full Name</div>
          <div class="sd-value"><?= htmlspecialchars($fullName) ?></div>
        </div>
        <div class="col">
          <div class="sd-label">Barangay</div>
          <div class="sd-value"><?= htmlspecialchars($row['barangay'] ?? '') ?></div>
        </div>
        <div class="col">
          <div class="sd-label">Email</div>
          <div class="sd-value"><?= htmlspecialchars($row['email'] ?? '') ?></div>
        </div>
        <div class="col">
          <div class="sd-label">Mobile</div>
          <div class="sd-value"><?= htmlspecialchars($row['mobile'] ?? '') ?></div>
        </div>
        <div class="col">
          <div class="sd-label">Status</div>
          <div class="sd-value">
            <span class="badge bg-<?= strtolower($row['status'])==='active'?'success':'secondary' ?>"><?= htmlspecialchars(ucfirst($row['status'])) ?></span>
          </div>
        </div>
        <div class="col">
          <div class="sd-label">Payroll Number</div>
          <div class="sd-value"><?= htmlspecialchars($row['payroll_no'] ?? 'N/A') ?></div>
        </div>
        <div class="col-12">
          <div class="sd-label">Mother's Complete Name</div>
          <div class="sd-value"><?= htmlspecialchars($row['mothers_maiden_name'] ?? '') ?></div>
        </div>
      </div>
    </div>
  </div>
  <hr class="my-3">
  <div class="small text-muted">No documents are shown here. This view is for quick profile details only.</div>
</div>
