<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Strict super_admin only access
if (!isset($_SESSION['admin_username']) || !isset($_SESSION['admin_role']) || $_SESSION['admin_role'] !== 'super_admin') {
    header("Location: ../../unified_login.php");
    exit;
}

include __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../bootstrap_services.php';

// Initialize variables
$logs = [];
$totalLogs = 0;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
// Allow user to select rows per page (10, 15, 25, 50)
$perPage = isset($_GET['per_page']) ? intval($_GET['per_page']) : 15;
$perPage = in_array($perPage, [10, 15, 25, 50]) ? $perPage : 15; // Validate
$offset = ($page - 1) * $perPage;

// Filter parameters
$filterUserType = $_GET['user_type'] ?? '';
$filterEventCategory = $_GET['event_category'] ?? '';
$filterEventType = $_GET['event_type'] ?? '';
$filterStatus = $_GET['status'] ?? '';
$filterUsername = $_GET['username'] ?? '';
$filterDateFrom = $_GET['date_from'] ?? '';
$filterDateTo = $_GET['date_to'] ?? '';
$filterIpAddress = $_GET['ip_address'] ?? '';
$searchQuery = $_GET['search'] ?? '';

// Build WHERE clause
$conditions = [];
$params = [];
$paramCount = 1;

if (!empty($filterUserType) && $filterUserType !== 'all') {
    $conditions[] = "user_type = $" . $paramCount++;
    $params[] = $filterUserType;
}

if (!empty($filterEventCategory) && $filterEventCategory !== 'all') {
    $conditions[] = "event_category = $" . $paramCount++;
    $params[] = $filterEventCategory;
}

if (!empty($filterEventType) && $filterEventType !== 'all') {
    $conditions[] = "event_type = $" . $paramCount++;
    $params[] = $filterEventType;
}

if (!empty($filterStatus) && $filterStatus !== 'all') {
    $conditions[] = "status = $" . $paramCount++;
    $params[] = $filterStatus;
}

if (!empty($filterUsername)) {
    $conditions[] = "username ILIKE $" . $paramCount++;
    $params[] = '%' . $filterUsername . '%';
}

if (!empty($filterDateFrom)) {
    $conditions[] = "created_at >= $" . $paramCount++;
    $params[] = $filterDateFrom . ' 00:00:00';
}

if (!empty($filterDateTo)) {
    $conditions[] = "created_at <= $" . $paramCount++;
    $params[] = $filterDateTo . ' 23:59:59';
}

if (!empty($filterIpAddress)) {
    $conditions[] = "ip_address = $" . $paramCount++;
    $params[] = $filterIpAddress;
}

if (!empty($searchQuery)) {
    $conditions[] = "(action_description ILIKE $" . $paramCount . " OR event_type ILIKE $" . $paramCount . " OR username ILIKE $" . $paramCount . ")";
    $params[] = '%' . $searchQuery . '%';
    $paramCount++;
}

$whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

// Get total count
$countQuery = "SELECT COUNT(*) as total FROM audit_logs $whereClause";
$countResult = pg_query_params($connection, $countQuery, $params);
$countRow = pg_fetch_assoc($countResult);
$totalLogs = $countRow['total'] ?? 0;
$totalPages = ceil($totalLogs / $perPage);

// Get logs
$logsQuery = "
    SELECT 
        audit_id,
        user_id,
        user_type,
        username,
        event_type,
        event_category,
        action_description,
        status,
        ip_address,
        user_agent,
        request_method,
        request_uri,
        affected_table,
        affected_record_id,
        old_values,
        new_values,
        metadata,
        created_at,
        session_id
    FROM audit_logs
    $whereClause
    ORDER BY created_at DESC
    LIMIT $perPage OFFSET $offset
";

$logsResult = pg_query_params($connection, $logsQuery, $params);
if ($logsResult) {
    while ($row = pg_fetch_assoc($logsResult)) {
        $logs[] = $row;
    }
}

// Get statistics for dashboard cards
$statsQuery = "
    SELECT 
        COUNT(*) as total_events,
        COUNT(CASE WHEN user_type = 'admin' THEN 1 END) as admin_events,
        COUNT(CASE WHEN user_type = 'student' THEN 1 END) as student_events,
        COUNT(CASE WHEN status = 'failure' THEN 1 END) as failed_events,
        COUNT(CASE WHEN event_category = 'authentication' THEN 1 END) as auth_events
    FROM audit_logs
    WHERE created_at >= NOW() - INTERVAL '24 hours'
";
$statsResult = pg_query($connection, $statsQuery);
$stats = pg_fetch_assoc($statsResult);

// Get unique values for dropdowns
$userTypesQuery = "SELECT DISTINCT user_type FROM audit_logs WHERE user_type != 'system' ORDER BY user_type";
$eventCategoriesQuery = "SELECT DISTINCT event_category FROM audit_logs ORDER BY event_category";
$eventTypesQuery = "SELECT DISTINCT event_type FROM audit_logs ORDER BY event_type";

$userTypes = pg_fetch_all(pg_query($connection, $userTypesQuery)) ?: [];
$eventCategories = pg_fetch_all(pg_query($connection, $eventCategoriesQuery)) ?: [];
$eventTypes = pg_fetch_all(pg_query($connection, $eventTypesQuery)) ?: [];

// Handle export to CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="audit_logs_' . date('Y-m-d_His') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // CSV headers
    fputcsv($output, [
        'Audit ID',
        'Date & Time',
        'User Type',
        'Username',
        'Event Category',
        'Event Type',
        'Action Description',
        'Status',
        'IP Address',
        'Affected Table',
        'Affected Record ID'
    ]);
    
    // Export all matching records (no pagination)
    $exportQuery = "SELECT * FROM audit_logs $whereClause ORDER BY created_at DESC";
    $exportResult = pg_query_params($connection, $exportQuery, $params);
    
    while ($row = pg_fetch_assoc($exportResult)) {
        fputcsv($output, [
            $row['audit_id'],
            $row['created_at'],
            $row['user_type'],
            $row['username'],
            $row['event_category'],
            $row['event_type'],
            $row['action_description'],
            $row['status'],
            $row['ip_address'],
            $row['affected_table'] ?? '',
            $row['affected_record_id'] ?? ''
        ]);
    }
    
    fclose($output);
    exit;
}
?>
<?php $page_title='Audit Trail'; $extra_css=['../../assets/css/admin/table_core.css']; include __DIR__ . '/../../includes/admin/admin_head.php'; ?>
<style>
    .stat-card {
        border-radius: 16px;
        padding: 1.5rem;
        transition: transform 0.2s, box-shadow 0.2s;
        position: relative;
        overflow: hidden;
    }
    .stat-card:hover {
        transform: translateY(-4px);
    }
    .stat-card .watermark-icon {
        position: absolute;
        right: -10px;
        bottom: -10px;
        font-size: 5rem;
        opacity: 0.15;
        transform: rotate(-10deg);
    }
    .stat-value {
        font-size: 2.25rem;
        font-weight: 700;
        margin: 0;
        color: white;
    }
    .stat-label {
        color: rgba(255,255,255,0.85);
        font-size: 0.875rem;
        font-weight: 500;
        margin: 0;
    }
    .stat-blue {
        background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
        box-shadow: 0 4px 14px rgba(59, 130, 246, 0.35);
    }
    .stat-cyan {
        background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
        box-shadow: 0 4px 14px rgba(6, 182, 212, 0.35);
    }
    .stat-green {
        background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
        box-shadow: 0 4px 14px rgba(34, 197, 94, 0.35);
    }
    .stat-red {
        background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
        box-shadow: 0 4px 14px rgba(239, 68, 68, 0.35);
    }
    .filter-card {
        background: white;
        border-radius: 12px;
        padding: 1.5rem;
        box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        margin-bottom: 1.5rem;
    }
    .log-table {
        background: white;
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    }
    .log-row {
        border-bottom: 1px solid #e9ecef;
        transition: background-color 0.2s;
    }
    .log-row:hover {
        background-color: #f8f9fa;
    }
    .log-row:last-child {
        border-bottom: none;
    }
    .status-badge {
        padding: 0.25rem 0.75rem;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
    }
    .status-success {
        background: #d1f4e0;
        color: #0d6832;
    }
    .status-failure {
        background: #fce8e8;
        color: #c92a2a;
    }
    .status-warning {
        background: #fff3cd;
        color: #856404;
    }
    .event-category-badge {
        font-size: 0.75rem;
        padding: 0.25rem 0.5rem;
        border-radius: 4px;
        font-weight: 600;
    }
    .details-btn {
        padding: 0.25rem 0.5rem;
        font-size: 0.75rem;
    }
    .modal-json {
        background: #f8f9fa;
        padding: 1rem;
        border-radius: 8px;
        max-height: 400px;
        overflow-y: auto;
        font-family: 'Courier New', monospace;
        font-size: 0.875rem;
    }
    .user-type-admin { color: #0d6efd; }
    .user-type-student { color: #198754; }
    .user-type-system { color: #6c757d; }
    
    .pagination {
        margin-top: 1.5rem;
    }
    
    .filter-section {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
        margin-bottom: 1rem;
    }
    
    @media (max-width: 768px) {
        .filter-section {
            grid-template-columns: 1fr;
        }
        .stat-card {
            margin-bottom: 1rem;
        }
    }
    /* Action Description column responsiveness fix */
    .log-table table th.action-desc,
    .log-table table td.action-desc {
        white-space: normal !important; /* override table_core default nowrap */
        word-break: break-word;
        overflow-wrap: anywhere;
    }
    /* Prevent extreme column collapse causing vertical letter stacking */
    .log-table table th.action-desc { min-width: 220px; }
    @media (max-width: 1600px) { .log-table table th.action-desc { min-width: 200px; } }
    @media (max-width: 1400px) { .log-table table th.action-desc { min-width: 180px; } }
    @media (max-width: 1200px) { .log-table table th.action-desc { min-width: 160px; } }
    @media (max-width: 992px)  { .log-table table th.action-desc { min-width: 140px; } }

    /* Status column & badge responsiveness */
    .log-table table th[style*="Status"],
    .log-table table th.status-col { width: 100px; }
    .log-table table td[data-label="Status"] { white-space: nowrap; }
    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        white-space: nowrap;
        line-height: 1.1;
        letter-spacing: .5px;
    }
    @media (max-width: 1400px) {
        .status-badge { font-size: 0.7rem; padding: 0.2rem 0.55rem; }
    }
    @media (max-width: 1200px) {
        .status-badge { font-size: 0.68rem; padding: 0.18rem 0.5rem; }
    }
    @media (max-width: 992px) {
        .status-badge { font-size: 0.66rem; padding: 0.16rem 0.45rem; }
    }
    /* Mobile card layout: allow full-width & center */
    @media (max-width: 767px) {
        .log-table table td[data-label="Status"] { white-space: normal; text-align: left; }
        .status-badge { font-size: 0.75rem; padding: 4px 10px; letter-spacing: 0; }
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
            
            <!-- Page Header -->
            <div class="mb-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <h1 class="fw-bold mb-1">Audit Trail</h1>
                        <p class="text-muted mb-0">Comprehensive system activity log for security and compliance</p>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="?export=csv&<?= http_build_query($_GET) ?>" class="btn btn-success">
                            <i class="bi bi-download me-1"></i> Export CSV
                        </a>
                        <button class="btn btn-outline-secondary" onclick="location.reload()">
                            <i class="bi bi-arrow-clockwise me-1"></i> Refresh
                        </button>
                    </div>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <div class="stat-card stat-blue">
                        <i class="bi bi-calendar-event watermark-icon"></i>
                        <p class="stat-value"><?= number_format($stats['total_events'] ?? 0) ?></p>
                        <p class="stat-label">Events (24h)</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card stat-cyan">
                        <i class="bi bi-person-gear watermark-icon"></i>
                        <p class="stat-value"><?= number_format($stats['admin_events'] ?? 0) ?></p>
                        <p class="stat-label">Admin Actions (24h)</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card stat-green">
                        <i class="bi bi-mortarboard watermark-icon"></i>
                        <p class="stat-value"><?= number_format($stats['student_events'] ?? 0) ?></p>
                        <p class="stat-label">Student Actions (24h)</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card stat-red">
                        <i class="bi bi-x-circle watermark-icon"></i>
                        <p class="stat-value"><?= number_format($stats['failed_events'] ?? 0) ?></p>
                        <p class="stat-label">Failed Events (24h)</p>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filter-card">
                <form method="GET" action="" id="filterForm">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0"><i class="bi bi-funnel me-2"></i>Filters</h5>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearFilters()">
                            <i class="bi bi-x-circle me-1"></i> Clear All
                        </button>
                    </div>
                    
                    <div class="filter-section">
                        <!-- Search -->
                        <div>
                            <label class="form-label fw-bold">Search</label>
                            <input type="text" name="search" class="form-control" placeholder="Search description, event, username..." value="<?= htmlspecialchars($searchQuery) ?>">
                        </div>
                        
                        <!-- User Type -->
                        <div>
                            <label class="form-label fw-bold">User Type</label>
                            <select name="user_type" class="form-select">
                                <option value="all" <?= $filterUserType === 'all' || empty($filterUserType) ? 'selected' : '' ?>>All Types</option>
                                <option value="admin" <?= $filterUserType === 'admin' ? 'selected' : '' ?>>Admin</option>
                                <option value="student" <?= $filterUserType === 'student' ? 'selected' : '' ?>>Student</option>
                                <option value="system" <?= $filterUserType === 'system' ? 'selected' : '' ?>>System</option>
                            </select>
                        </div>
                        
                        <!-- Event Category -->
                        <div>
                            <label class="form-label fw-bold">Event Category</label>
                            <select name="event_category" class="form-select">
                                <option value="all" <?= $filterEventCategory === 'all' || empty($filterEventCategory) ? 'selected' : '' ?>>All Categories</option>
                                <?php foreach ($eventCategories as $cat): ?>
                                    <option value="<?= htmlspecialchars($cat['event_category']) ?>" <?= $filterEventCategory === $cat['event_category'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars(ucwords(str_replace('_', ' ', $cat['event_category']))) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- Status -->
                        <div>
                            <label class="form-label fw-bold">Status</label>
                            <select name="status" class="form-select">
                                <option value="all" <?= $filterStatus === 'all' || empty($filterStatus) ? 'selected' : '' ?>>All Statuses</option>
                                <option value="success" <?= $filterStatus === 'success' ? 'selected' : '' ?>>Success</option>
                                <option value="failure" <?= $filterStatus === 'failure' ? 'selected' : '' ?>>Failure</option>
                                <option value="warning" <?= $filterStatus === 'warning' ? 'selected' : '' ?>>Warning</option>
                            </select>
                        </div>
                        
                        <!-- Username -->
                        <div>
                            <label class="form-label fw-bold">Username</label>
                            <input type="text" name="username" class="form-control" placeholder="Exact or partial match" value="<?= htmlspecialchars($filterUsername) ?>">
                        </div>
                        
                        <!-- Date From -->
                        <div>
                            <label class="form-label fw-bold">Date From</label>
                            <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($filterDateFrom) ?>">
                        </div>
                        
                        <!-- Date To -->
                        <div>
                            <label class="form-label fw-bold">Date To</label>
                            <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($filterDateTo) ?>">
                        </div>
                        
                        <!-- IP Address -->
                        <div>
                            <label class="form-label fw-bold">IP Address</label>
                            <input type="text" name="ip_address" class="form-control" placeholder="e.g., 192.168.1.1" value="<?= htmlspecialchars($filterIpAddress) ?>">
                        </div>
                    </div>
                    
                    <div class="mt-3">
                        <button type="submit" class="btn btn-primary">Filter</button>
                        <button type="button" class="btn btn-outline-secondary" onclick="clearFilters()">Clear</button>
                        <span class="ms-3 text-muted">
                            <i class="bi bi-info-circle me-1"></i>
                            Showing <?= number_format(count($logs)) ?> of <?= number_format($totalLogs) ?> events
                        </span>
                        
                        <!-- Rows per page selector -->
                        <div class="d-inline-flex align-items-center gap-2 ms-3">
                            <label class="text-muted small mb-0">Rows:</label>
                            <select name="per_page" class="form-select form-select-sm" style="width: 80px;" onchange="this.form.submit()">
                                <option value="10" <?= $perPage === 10 ? 'selected' : '' ?>>10</option>
                                <option value="15" <?= $perPage === 15 ? 'selected' : '' ?>>15</option>
                                <option value="25" <?= $perPage === 25 ? 'selected' : '' ?>>25</option>
                                <option value="50" <?= $perPage === 50 ? 'selected' : '' ?>>50</option>
                            </select>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Audit Logs Table -->
            <div class="log-table">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th style="width: 80px;">ID</th>
                                <th style="width: 160px;">Date & Time</th>
                                <th style="width: 100px;">User Type</th>
                                <th style="width: 150px;">Username</th>
                                <th style="width: 150px;">Category</th>
                                <th class="action-desc">Action Description</th>
                                <th style="width: 100px;">Status</th>
                                <th style="width: 140px;">IP Address</th>
                                <th style="width: 100px;">Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($logs)): ?>
                                <tr>
                                    <td colspan="9" class="text-center py-5 text-muted">
                                        <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                        No audit logs found matching your criteria.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($logs as $log): ?>
                                    <tr class="log-row">
                                        <td data-label="ID"><code><?= htmlspecialchars($log['audit_id']) ?></code></td>
                                        <td data-label="Date & Time">
                                            <small>
                                                <?= date('M d, Y', strtotime($log['created_at'])) ?><br>
                                                <span class="text-muted"><?= date('g:i:s A', strtotime($log['created_at'])) ?></span>
                                            </small>
                                        </td>
                                        <td data-label="User Type">
                                            <span class="user-type-<?= htmlspecialchars($log['user_type']) ?>">
                                                <i class="bi bi-person-circle me-1"></i>
                                                <?= htmlspecialchars(ucfirst($log['user_type'])) ?>
                                            </span>
                                        </td>
                                        <td data-label="Username">
                                            <strong><?= htmlspecialchars($log['username'] ?? 'N/A') ?></strong>
                                            <?php if (!empty($log['user_id'])): ?>
                                                <br><small class="text-muted">ID: <?= htmlspecialchars($log['user_id']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Category">
                                            <span class="badge bg-secondary event-category-badge">
                                                <?= htmlspecialchars(str_replace('_', ' ', $log['event_category'])) ?>
                                            </span>
                                            <br><small class="text-muted"><?= htmlspecialchars($log['event_type']) ?></small>
                                        </td>
                                        <td data-label="Action Description" class="action-desc">
                                            <small><?= htmlspecialchars($log['action_description']) ?></small>
                                        </td>
                                        <td data-label="Status">
                                            <?php
                                            $statusClass = 'status-' . $log['status'];
                                            $statusIcon = $log['status'] === 'success' ? 'check-circle' : ($log['status'] === 'failure' ? 'x-circle' : 'exclamation-triangle');
                                            ?>
                                            <span class="status-badge <?= $statusClass ?>">
                                                <i class="bi bi-<?= $statusIcon ?> me-1"></i>
                                                <?= htmlspecialchars(ucfirst($log['status'])) ?>
                                            </span>
                                        </td>
                                        <td data-label="IP Address"><code><?= htmlspecialchars($log['ip_address'] ?? 'N/A') ?></code></td>
                                        <td data-label="Details">
                                            <button class="btn btn-sm btn-outline-primary details-btn" 
                                                    onclick="showDetails(<?= htmlspecialchars(json_encode($log), ENT_QUOTES) ?>)">
                                                <i class="bi bi-eye me-1"></i> View
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="d-flex justify-content-between align-items-center p-3 border-top">
                        <div class="text-muted">
                            Page <?= $page ?> of <?= $totalPages ?>
                        </div>
                        <nav>
                            <ul class="pagination mb-0">
                                <?php if ($page > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=<?= $page - 1 ?>&<?= http_build_query(array_filter($_GET, function($key) { return $key !== 'page'; }, ARRAY_FILTER_USE_KEY)) ?>">
                                            <i class="bi bi-chevron-left"></i>
                                        </a>
                                    </li>
                                <?php endif; ?>
                                
                                <?php
                                $startPage = max(1, $page - 2);
                                $endPage = min($totalPages, $page + 2);
                                
                                for ($i = $startPage; $i <= $endPage; $i++):
                                ?>
                                    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                        <a class="page-link" href="?page=<?= $i ?>&<?= http_build_query(array_filter($_GET, function($key) { return $key !== 'page'; }, ARRAY_FILTER_USE_KEY)) ?>">
                                            <?= $i ?>
                                        </a>
                                    </li>
                                <?php endfor; ?>
                                
                                <?php if ($page < $totalPages): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=<?= $page + 1 ?>&<?= http_build_query(array_filter($_GET, function($key) { return $key !== 'page'; }, ARRAY_FILTER_USE_KEY)) ?>">
                                            <i class="bi bi-chevron-right"></i>
                                        </a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    </div>
                <?php endif; ?>
            </div>
            
        </div>
    </section>
</div>

<!-- Details Modal -->
<div class="modal fade" id="detailsModal" tabindex="-1" aria-labelledby="detailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="detailsModalLabel">
                    <i class="bi bi-file-text me-2"></i>Audit Log Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="detailsModalBody">
                <!-- Content will be populated by JavaScript -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../../assets/js/admin/sidebar.js"></script>

<script>
function clearFilters() {
    window.location.href = 'audit_logs.php';
}

function showDetails(log) {
    const modal = new bootstrap.Modal(document.getElementById('detailsModal'));
    const body = document.getElementById('detailsModalBody');
    
    // Format JSON data
    const formatJSON = (data) => {
        if (!data) return '<em class="text-muted">No data</em>';
        try {
            const parsed = typeof data === 'string' ? JSON.parse(data) : data;
            return '<pre class="modal-json">' + JSON.stringify(parsed, null, 2) + '</pre>';
        } catch (e) {
            return '<pre class="modal-json">' + data + '</pre>';
        }
    };
    
    const content = `
        <div class="row g-3">
            <div class="col-md-6">
                <strong>Audit ID:</strong><br>
                <code>${log.audit_id}</code>
            </div>
            <div class="col-md-6">
                <strong>Timestamp:</strong><br>
                ${log.created_at}
            </div>
            <div class="col-md-6">
                <strong>User Type:</strong><br>
                ${log.user_type}
            </div>
            <div class="col-md-6">
                <strong>Username:</strong><br>
                ${log.username || 'N/A'}
            </div>
            <div class="col-md-6">
                <strong>User ID:</strong><br>
                ${log.user_id || 'N/A'}
            </div>
            <div class="col-md-6">
                <strong>Session ID:</strong><br>
                <code style="font-size: 0.75rem;">${log.session_id || 'N/A'}</code>
            </div>
            <div class="col-12">
                <strong>Event Type:</strong><br>
                <span class="badge bg-info">${log.event_type}</span>
            </div>
            <div class="col-12">
                <strong>Event Category:</strong><br>
                <span class="badge bg-secondary">${log.event_category}</span>
            </div>
            <div class="col-12">
                <strong>Action Description:</strong><br>
                ${log.action_description}
            </div>
            <div class="col-md-6">
                <strong>Status:</strong><br>
                <span class="badge bg-${log.status === 'success' ? 'success' : log.status === 'failure' ? 'danger' : 'warning'}">
                    ${log.status}
                </span>
            </div>
            <div class="col-md-6">
                <strong>IP Address:</strong><br>
                <code>${log.ip_address || 'N/A'}</code>
            </div>
            ${log.request_method ? `
            <div class="col-md-6">
                <strong>Request Method:</strong><br>
                <code>${log.request_method}</code>
            </div>
            ` : ''}
            ${log.request_uri ? `
            <div class="col-md-6">
                <strong>Request URI:</strong><br>
                <code style="font-size: 0.75rem; word-break: break-all;">${log.request_uri}</code>
            </div>
            ` : ''}
            ${log.user_agent ? `
            <div class="col-12">
                <strong>User Agent:</strong><br>
                <small class="text-muted">${log.user_agent}</small>
            </div>
            ` : ''}
            ${log.affected_table ? `
            <div class="col-md-6">
                <strong>Affected Table:</strong><br>
                <code>${log.affected_table}</code>
            </div>
            ` : ''}
            ${log.affected_record_id ? `
            <div class="col-md-6">
                <strong>Affected Record ID:</strong><br>
                <code>${log.affected_record_id}</code>
            </div>
            ` : ''}
            ${log.old_values ? `
            <div class="col-12">
                <strong>Old Values:</strong>
                ${formatJSON(log.old_values)}
            </div>
            ` : ''}
            ${log.new_values ? `
            <div class="col-12">
                <strong>New Values:</strong>
                ${formatJSON(log.new_values)}
            </div>
            ` : ''}
            ${log.metadata ? `
            <div class="col-12">
                <strong>Metadata:</strong>
                ${formatJSON(log.metadata)}
            </div>
            ` : ''}
        </div>
    `;
    
    body.innerHTML = content;
    modal.show();
}

// Quick date filters
function setQuickDate(days) {
    const today = new Date();
    const from = new Date();
    from.setDate(today.getDate() - days);
    
    document.querySelector('input[name="date_from"]').value = from.toISOString().split('T')[0];
    document.querySelector('input[name="date_to"]').value = today.toISOString().split('T')[0];
}
</script>

</body>
</html>
<?php
if (isset($connection) && $connection instanceof \PgSql\Connection) {
    pg_close($connection);
}
?>
