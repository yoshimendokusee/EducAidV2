<?php
include __DIR__ . '/../../config/database.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['admin_username'])) {
    header("Location: ../../unified_login.php");
    exit;
}

// Dev-only: Seed demo blacklisted students on localhost
if ((($_GET['seed'] ?? '') === 'demo') && in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1'])) {
    $adminId = $_SESSION['admin_id'] ?? null;
    $adminEmail = $_SESSION['admin_email'] ?? null;

    // Ensure we have adminId and email (fetch from DB if needed)
    if (!$adminId && !empty($_SESSION['admin_username'])) {
        $admRes = pg_query_params($connection, "SELECT admin_id, email FROM admins WHERE username = $1 LIMIT 1", [$_SESSION['admin_username']]);
        if ($admRes && pg_num_rows($admRes)) {
            $admRow = pg_fetch_assoc($admRes);
            $adminId = $admRow['admin_id'];
            $adminEmail = $admRow['email'] ?: $adminEmail;
        }
    }
    if ($adminId && !$adminEmail) {
        $admRes2 = pg_query_params($connection, "SELECT email FROM admins WHERE admin_id = $1 LIMIT 1", [$adminId]);
        if ($admRes2 && pg_num_rows($admRes2)) {
            $adminEmail = pg_fetch_result($admRes2, 0, 0);
        }
    }
    if (!$adminEmail) { $adminEmail = 'dev-seed@localhost'; }
    pg_query($connection, 'BEGIN');
    try {
        // Pick any municipality and a barangay under it
        $munRes = pg_query($connection, "SELECT municipality_id FROM municipalities ORDER BY municipality_id LIMIT 1");
        $municipalityId = $munRes && pg_num_rows($munRes) ? intval(pg_fetch_result($munRes, 0, 0)) : 1;

        $brgyRes = pg_query_params($connection, "SELECT barangay_id FROM barangays WHERE municipality_id = $1 ORDER BY barangay_id LIMIT 1", [$municipalityId]);
        if (!$brgyRes || !pg_num_rows($brgyRes)) {
            $brgyRes = pg_query($connection, "SELECT barangay_id FROM barangays ORDER BY barangay_id LIMIT 1");
        }
        $barangayId = $brgyRes && pg_num_rows($brgyRes) ? intval(pg_fetch_result($brgyRes, 0, 0)) : 1;

        $pwdHash = password_hash('DevSeeder#123', PASSWORD_BCRYPT);

        $samples = [
            [
                'first' => 'Blacklist', 'last' => 'User Alpha', 'email' => 'alpha.demo@example.test', 'mobile' => '+63 900 111 2222',
                'sex' => 'Female', 'reason' => 'system_abuse', 'detail' => 'Automated demo seed for mobile testing.'
            ],
            [
                'first' => 'Blacklist', 'last' => 'User Beta', 'email' => 'beta.demo@example.test', 'mobile' => '+63 900 333 4444',
                'sex' => 'Male', 'reason' => 'fraudulent_activity', 'detail' => 'Multiple suspicious signups detected (demo).'
            ],
            [
                'first' => 'Blacklist', 'last' => 'User Gamma', 'email' => 'gamma.demo@example.test', 'mobile' => '+63 900 555 6666',
                'sex' => 'Female', 'reason' => 'academic_misconduct', 'detail' => 'Forgery of documents (demo).'
            ],
        ];

        foreach ($samples as $i => $s) {
            $sid = 'DEMO-BL-' . date('YmdHis') . '-' . ($i + 1);

            // Insert student (status blacklisted)
            $insertStudent = pg_query_params(
                $connection,
                "INSERT INTO students (
                    municipality_id, first_name, last_name, email, mobile, password, sex, status,
                    application_date, bdate, barangay_id, student_id, status_blacklisted
                ) VALUES (
                    $1,$2,$3,$4,$5,$6,$7,'blacklisted', now(), $8, $9, $10, TRUE
                ) RETURNING student_id",
                [
                    $municipalityId,
                    $s['first'],
                    $s['last'],
                    $s['email'],
                    $s['mobile'],
                    $pwdHash,
                    $s['sex'],
                    date('Y-m-d', strtotime('2000-01-01 +'.($i*100).' days')),
                    $barangayId,
                    $sid
                ]
            );

            if (!$insertStudent) { throw new Exception('Failed to insert student'); }

            // Insert into blacklisted_students
            $ok = pg_query_params(
                $connection,
                "INSERT INTO blacklisted_students (student_id, reason_category, detailed_reason, blacklisted_by, admin_email, admin_notes, blacklisted_at)
                 VALUES ($1,$2,$3,$4,$5,$6, now())",
                [$sid, $s['reason'], $s['detail'], $adminId, $adminEmail, 'Seeded via blacklist_archive.php?seed=demo']
            );
            if (!$ok) { throw new Exception('Failed to insert blacklist record'); }
        }

        pg_query($connection, 'COMMIT');
        // Redirect back without seed param
        $base = strtok($_SERVER['REQUEST_URI'], '?');
        header('Location: ' . $base . '?seeded=1');
        exit;
    } catch (Exception $e) {
        pg_query($connection, 'ROLLBACK');
        http_response_code(500);
        echo '<pre>Seeder error: ' . htmlspecialchars($e->getMessage()) . '</pre>';
        exit;
    }
}

// Pagination and filtering
$limit = 25;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $limit;

// Search and filter parameters
$search = trim($_GET['search'] ?? '');
$reason_filter = $_GET['reason'] ?? '';
$sort_by = $_GET['sort'] ?? 'blacklisted_at';
$sort_order = $_GET['order'] ?? 'DESC';

// Build WHERE clause
$whereConditions = ["s.status = 'blacklisted'"];
$params = [];
$paramCount = 1;

if (!empty($search)) {
    $whereConditions[] = "(s.first_name ILIKE $" . $paramCount . " OR s.last_name ILIKE $" . $paramCount . " OR s.email ILIKE $" . $paramCount . ")";
    $params[] = "%$search%";
    $paramCount++;
}

if (!empty($reason_filter)) {
    $whereConditions[] = "bl.reason_category = $" . $paramCount;
    $params[] = $reason_filter;
    $paramCount++;
}

$whereClause = implode(' AND ', $whereConditions);

// Valid sort columns
$validSorts = ['blacklisted_at', 'first_name', 'last_name', 'reason_category'];
if (!in_array($sort_by, $validSorts)) $sort_by = 'blacklisted_at';
if (!in_array($sort_order, ['ASC', 'DESC'])) $sort_order = 'DESC';

// Count total records
$countQuery = "SELECT COUNT(*) FROM students s
               JOIN blacklisted_students bl ON s.student_id = bl.student_id
               LEFT JOIN admins a ON bl.blacklisted_by = a.admin_id
               WHERE $whereClause";

$countResult = pg_query_params($connection, $countQuery, $params);
$totalRecords = intval(pg_fetch_result($countResult, 0, 0));
$totalPages = ceil($totalRecords / $limit);

// Fetch blacklisted students with pagination
$query = "SELECT s.*, bl.*, 
                 CONCAT(a.first_name, ' ', a.last_name) as blacklisted_by_name,
                 b.name as barangay_name
          FROM students s
          JOIN blacklisted_students bl ON s.student_id = bl.student_id
          LEFT JOIN admins a ON bl.blacklisted_by = a.admin_id
          LEFT JOIN barangays b ON s.barangay_id = b.barangay_id
          WHERE $whereClause
          ORDER BY bl.$sort_by $sort_order
          LIMIT $limit OFFSET $offset";

$result = pg_query_params($connection, $query, $params);
$blacklistedStudents = [];
while ($row = pg_fetch_assoc($result)) {
    $blacklistedStudents[] = $row;
}

// Get reason categories for filter
$reasonCategories = [
    'fraudulent_activity' => 'Fraudulent Activity',
    'academic_misconduct' => 'Academic Misconduct',
    'system_abuse' => 'System Abuse',
    'other' => 'Other'
];
?>

<?php $page_title='Blacklist Archive'; include __DIR__ . '/../../includes/admin/admin_head.php'; ?>
<link rel="stylesheet" href="../../assets/css/admin/table_core.css">
<style>
    .filter-card{background:#ffffff;border:1px solid #e5e7eb;border-radius:16px;padding:1.25rem 1.25rem 1rem;margin-bottom:1.5rem;box-shadow:0 2px 4px rgba(0,0,0,.04);} 
    .filter-card h6{font-weight:600;font-size:.9rem;margin-bottom:.85rem;display:flex;align-items:center;gap:.4rem;color:#374151;letter-spacing:.5px;text-transform:uppercase;}
    .filter-card .form-label{font-size:.7rem;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;margin-bottom:.15rem;}
    .filter-card .btn{border-radius:10px;font-weight:500;}
    /* Remove shadow from table-responsive since we use table-wrap */
    .table-responsive{box-shadow:none;margin-top:0;border-radius:0;}
    .table-wrap{background:#fff;border:1px solid #e5e7eb;border-radius:18px;box-shadow:0 4px 10px -2px rgba(0,0,0,.05),0 2px 4px -2px rgba(0,0,0,.04);overflow:hidden;}
    /* Ensure dark header stays dark */
    .table thead.table-dark th{background:#495057!important;color:#fff!important;border:none!important;}
    .table tbody td{vertical-align:middle;font-size:.85rem;padding:.7rem .85rem;border-color:#f0f2f4;}
    .table tbody tr:hover{background:#f8f9fa;}
    /* Reason styling */
    .reason-badge{font-size:.65rem;padding:.35rem .55rem;font-weight:600;letter-spacing:.4px;border-radius:20px;color:#fff;}
    .reason-fraudulent{background:#dc3545;} .reason-academic{background:#fd7e14;} .reason-system{background:#6f42c1;} .reason-other{background:#6c757d;}
    /* Ensure detailed reason text stays visible */
    .detailed-reason{color:#6c757d!important;font-size:.8rem;font-style:italic;}
    .table tbody tr:hover .detailed-reason{color:#555!important;}
    .pagination-info{font-size:.7rem;color:#6b7280;font-weight:500;}
    .pagination .page-link{font-size:.75rem;padding:.35rem .6rem;border-radius:8px;margin:0 .15rem;}
    .pagination .page-item.active .page-link{background:#2563eb;border-color:#2563eb;}
    .empty-state{padding:3.5rem 1rem;}
    .empty-state i{opacity:.35;}
    .empty-state h3{font-size:1.15rem;font-weight:600;margin-top:1rem;}
    .badge-light.text-danger{background:#fff1f1;}
    .contact-icons i{font-size:.75rem;width:14px;text-align:center;opacity:.8;}
    .contact-icons small{display:block;line-height:1.15;margin-top:2px;}
    .truncate-50{max-width:240px;display:inline-block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;vertical-align:bottom;}
    @media (max-width: 992px){.filter-card{padding:1rem;} }
    @media (max-width: 576px){.filter-card .row > div{margin-bottom:.75rem;} .filter-card .row > div:last-child{margin-bottom:0;} .table-wrap{border-radius:14px;} }

    /* Mobile-only compact modal size for details modal (align with Manage Applicant modal feel) */
    @media (max-width: 576px) {
        .modal-mobile-compact .modal-dialog { max-width: 380px; width: 84%; margin: 1rem auto; }
        .modal-mobile-compact .modal-content { border-radius: 12px; }
        .modal-mobile-compact .modal-body { max-height: 58vh; overflow-y: auto; padding: .75rem; }
        .modal-mobile-compact .modal-header,
        .modal-mobile-compact .modal-footer { padding-top: 0.5rem; padding-bottom: 0.5rem; }
        .modal-mobile-compact .modal-title { font-size: 1rem; }
    }

    /* Slimmer dialog on larger screens too */
    @media (min-width: 576px) {
        #detailsModal .modal-dialog { max-width: 520px; }
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
            <div class="mb-4">
                <h1 class="fw-bold mb-1">Blacklist Archive</h1>
                <p class="text-muted mb-0">Manage and review all blacklisted student records with filtering & quick detail view.</p>
            </div>
            <div class="filter-card">
                <h6><i class="bi bi-funnel"></i> FILTER & SEARCH</h6>
                <form class="row g-3 align-items-end" method="GET">
                    <div class="col-lg-4 col-md-6">
                        <label class="form-label">Search</label>
                        <input type="text" class="form-control form-control-sm" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Name or email...">
                    </div>
                    <div class="col-lg-3 col-md-4 col-sm-6">
                        <label class="form-label">Reason Category</label>
                        <select class="form-select form-select-sm" name="reason">
                            <option value="">All Reasons</option>
                            <?php foreach ($reasonCategories as $key => $label): ?>
                                <option value="<?= $key ?>" <?= $reason_filter === $key ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-2 col-sm-6">
                        <label class="form-label">Sort By</label>
                        <select class="form-select form-select-sm" name="sort">
                            <option value="blacklisted_at" <?= $sort_by === 'blacklisted_at' ? 'selected' : '' ?>>Date</option>
                            <option value="first_name" <?= $sort_by === 'first_name' ? 'selected' : '' ?>>First Name</option>
                            <option value="last_name" <?= $sort_by === 'last_name' ? 'selected' : '' ?>>Last Name</option>
                            <option value="reason_category" <?= $sort_by === 'reason_category' ? 'selected' : '' ?>>Reason</option>
                        </select>
                    </div>
                    <div class="col-lg-1 col-sm-6">
                        <label class="form-label">Order</label>
                        <select class="form-select form-select-sm" name="order">
                            <option value="DESC" <?= $sort_order === 'DESC' ? 'selected' : '' ?>>↓</option>
                            <option value="ASC" <?= $sort_order === 'ASC' ? 'selected' : '' ?>>↑</option>
                        </select>
                    </div>
                    <div class="col-lg-2 col-sm-6 d-flex gap-1">
                        <button type="submit" class="btn btn-sm btn-primary flex-grow-1">Filter</button>
                        <a href="blacklist_archive.php" class="btn btn-sm btn-outline-secondary">Clear</a>
                    </div>
                </form>
            </div>
            <div class="table-wrap">
                <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom" style="background:#fafafa;">
                    <span class="fw-semibold small text-uppercase" style="letter-spacing:.6px;">Blacklisted Students</span>
                    <span class="badge bg-danger" style="font-size:.6rem;">Updated</span>
                </div>
                <div class="p-0">
                <?php if (empty($blacklistedStudents)): ?>
                    <div class="empty-state text-center">
                        <i class="bi bi-shield-check display-4 text-success"></i>
                        <h3 class="mt-2">No Blacklisted Students</h3>
                        <p class="text-muted small mb-0">Try adjusting your filters or search query.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle compact-cards" id="blacklistTable">
                            <thead class="table-dark">
                                <tr>
                                    <th style="min-width:180px;">Student</th>
                                    <th style="min-width:200px;">Contact</th>
                                    <th style="min-width:160px;">Reason</th>
                                    <th style="min-width:160px;">Blacklisted By</th>
                                    <th style="min-width:120px;">Date</th>
                                    <th style="width:140px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($blacklistedStudents as $student): ?>
                                <tr>
                                    <td data-label="Student">
                                        <strong><?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?></strong><br>
                                        <small class="text-muted"><?= htmlspecialchars($student['barangay_name'] ?? 'N/A') ?></small>
                                    </td>
                                    <td class="contact-icons" data-label="Contact">
                                        <i class="bi bi-envelope"></i> <small class="truncate-50" title="<?= htmlspecialchars($student['email']) ?>"><?= htmlspecialchars($student['email']) ?></small><br>
                                        <i class="bi bi-phone"></i> <small><?= htmlspecialchars($student['mobile'] ?? 'N/A') ?></small>
                                    </td>
                                    <td data-label="Reason">
                                        <?php $reasonClass = 'reason-' . str_replace('_','-',$student['reason_category']); ?>
                                        <span class="badge <?= $reasonClass ?> reason-badge"><?= $reasonCategories[$student['reason_category']] ?></span>
                                        <?php if (!empty($student['detailed_reason'])): ?>
                                            <div class="detailed-reason mt-1 truncate-50" title="<?= htmlspecialchars($student['detailed_reason']) ?>">
                                                <?= htmlspecialchars($student['detailed_reason']) ?>
                                            </div>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Blacklisted By">
                                        <strong><?= htmlspecialchars($student['blacklisted_by_name'] ?? 'System') ?></strong><br>
                                        <small class="text-muted truncate-50" title="<?= htmlspecialchars($student['admin_email']) ?>"><?= htmlspecialchars($student['admin_email']) ?></small>
                                    </td>
                                    <td data-label="Date">
                                        <?= date('M j, Y', strtotime($student['blacklisted_at'])) ?><br>
                                        <small class="text-muted"><?= date('g:i A', strtotime($student['blacklisted_at'])) ?></small>
                                    </td>
                                    <td data-label="Actions">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <button class="btn btn-outline-info" onclick="viewDetails('<?= $student['student_id'] ?>')" title="View Details">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                            <?php
                                            // Check if ZIP file exists
                                            $zipFile = __DIR__ . '/../../assets/uploads/blacklisted_students/' . $student['student_id'] . '.zip';
                                            if (file_exists($zipFile)):
                                            ?>
                                            <a href="download_blacklist_zip.php?student_id=<?= urlencode($student['student_id']) ?>" 
                                               class="btn btn-outline-success" title="Download Archive">
                                                <i class="bi bi-download"></i>
                                            </a>
                                            <?php else: ?>
                                            <button class="btn btn-outline-secondary" disabled title="No archive available">
                                                <i class="bi bi-file-zip"></i>
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="d-flex justify-content-between align-items-center p-2 border-top bg-white">
                        <div class="pagination-info">Showing <?= $offset + 1 ?> to <?= min($offset + $limit, $totalRecords) ?> of <?= $totalRecords ?> entries</div>
                        <?php if ($totalPages > 1): ?>
                            <nav>
                                <ul class="pagination mb-0">
                                    <?php
                                        $currentUrl = $_SERVER['REQUEST_URI'];
                                        $urlParts = parse_url($currentUrl);
                                        parse_str($urlParts['query'] ?? '', $queryParams);
                                        unset($queryParams['page']);
                                        $baseUrl = $urlParts['path'] . '?' . http_build_query($queryParams);
                                        $baseUrl .= empty($queryParams) ? 'page=' : '&page=';
                                        if ($page > 1): ?>
                                            <li class="page-item"><a class="page-link" href="<?= $baseUrl . ($page - 1) ?>">Prev</a></li>
                                        <?php endif; ?>
                                        <?php $start = max(1,$page-2); $end = min($totalPages,$page+2); for ($i=$start;$i<=$end;$i++): ?>
                                            <li class="page-item <?= $i === $page ? 'active' : '' ?>"><a class="page-link" href="<?= $baseUrl . $i ?>"><?= $i ?></a></li>
                                        <?php endfor; if ($page < $totalPages): ?>
                                            <li class="page-item"><a class="page-link" href="<?= $baseUrl . ($page + 1) ?>">Next</a></li>
                                        <?php endif; ?>
                                </ul>
                            </nav>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                </div>
            </div>
        </div>
    </section>
</div>

<!-- Details Modal -->
<div class="modal fade modal-mobile-compact" id="detailsModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="bi bi-person-x-fill"></i> Blacklist Details
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="detailsContent">
                <!-- Content will be loaded via AJAX -->
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../../assets/js/admin/sidebar.js"></script>

<script>
    // Guard and reusable details modal
    let detailsRequestInFlight = false;
    const detailsModalEl = document.getElementById('detailsModal');
    const detailsModal = detailsModalEl ? bootstrap.Modal.getOrCreateInstance(detailsModalEl, { backdrop: true, keyboard: true, focus: true }) : null;

    function cleanupExtraBackdrops() {
        const openModals = document.querySelectorAll('.modal.show').length;
        const backdrops = Array.from(document.querySelectorAll('.modal-backdrop'));
        if (backdrops.length > openModals) {
            for (let i = 0; i < backdrops.length - openModals; i++) {
                const bd = backdrops[i];
                bd.parentNode && bd.parentNode.removeChild(bd);
            }
        }
        if (openModals === 0) {
            document.body.classList.remove('modal-open');
            document.body.style.removeProperty('padding-right');
        }
    }
    if (detailsModalEl) {
        detailsModalEl.addEventListener('shown.bs.modal', cleanupExtraBackdrops);
        detailsModalEl.addEventListener('hidden.bs.modal', cleanupExtraBackdrops);
    }

    function viewDetails(studentId) {
        if (detailsRequestInFlight) return;
        if (detailsModalEl && detailsModalEl.classList.contains('show')) return;

        detailsRequestInFlight = true;
        const contentEl = document.getElementById('detailsContent');
        if (contentEl) {
            contentEl.innerHTML = `
                <div class="text-center py-4">
                    <div class="spinner-border text-danger" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2">Loading details...</p>
                </div>
            `;
        }
        if (detailsModal) detailsModal.show();

        fetch('blacklist_details.php?student_id=' + encodeURIComponent(studentId))
            .then(response => response.text())
            .then(html => {
                if (contentEl) contentEl.innerHTML = html;
            })
            .catch(() => {
                if (contentEl) {
                    contentEl.innerHTML = `
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle"></i>
                            Error loading details. Please try again.
                        </div>
                    `;
                }
            })
            .finally(() => {
                detailsRequestInFlight = false;
            });
    }
    
    // Global restore function for AJAX-loaded content
    window.restoreBlacklistedStudent = function(studentId, studentName) {
        console.log('Restore function called!', studentId, studentName);
        
        if (!confirm('⚠️ DEVELOPMENT RESTORE\n\nThis will completely restore ' + studentName + ':\n\n• Remove from blacklist\n• Restore student status to applicant\n• Extract files from ZIP archive\n• Delete blacklist ZIP\n\nContinue?')) {
            return;
        }
        
        // Find the button in the modal
        const btn = document.querySelector('.restoreBlacklistBtn');
        if (!btn) {
            alert('Error: Button not found');
            return;
        }
        
        const originalHtml = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Restoring...';
        btn.disabled = true;
        
        fetch('restore_blacklisted_student.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'student_id=' + encodeURIComponent(studentId)
        })
        .then(response => response.json())
        .then(data => {
            console.log('Response:', data);
            if (data.success) {
                alert('✓ Student restored successfully!\n\n' + data.message);
                window.location.reload();
            } else {
                alert('✗ Error: ' + data.message);
                btn.innerHTML = originalHtml;
                btn.disabled = false;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('✗ Network error: ' + error.message);
            btn.innerHTML = originalHtml;
            btn.disabled = false;
        });
    };
</script>
</body>
</html>

<?php pg_close($connection); ?>