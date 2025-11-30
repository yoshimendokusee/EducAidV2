<?php
// Load secure session configuration (must be before session_start)
require_once __DIR__ . '/../../config/session_config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['admin_username'])) {
    header("Location: ../../unified_login.php");
    exit;
}

include __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../services/AuditLogger.php';

// Get admin info
$adminId = $_SESSION['admin_id'] ?? null;
$adminUsername = $_SESSION['admin_username'] ?? null;
$adminRole = $_SESSION['admin_role'] ?? null;

// Get admin's municipality
$adminMunicipalityId = null;
if ($adminId) {
    $admRes = pg_query_params($connection, 
        "SELECT municipality_id FROM admins WHERE admin_id = $1", 
        [$adminId]
    );
    if ($admRes && pg_num_rows($admRes)) {
        $adminMunicipalityId = pg_fetch_assoc($admRes)['municipality_id'];
    }
}

// Initialize AuditLogger
$auditLogger = new AuditLogger($connection);

// Handle unarchive action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'unarchive') {
    // Ensure clean JSON output with no stray bytes/notices
    while (ob_get_level()) { ob_end_clean(); }
    ini_set('display_errors', '0');
    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');

    // JSON-safe error/exception handlers
    set_error_handler(function($errno, $errstr, $errfile, $errline) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Server error: ' . $errstr,
            'debug' => ['file' => basename($errfile), 'line' => $errline]
        ]);
        exit;
    });
    set_exception_handler(function($ex) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Server exception: ' . $ex->getMessage()
        ]);
        exit;
    });
    register_shutdown_function(function(){
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Fatal error: ' . $e['message']
            ]);
        }
    });

    try {
        $studentId = $_POST['student_id'] ?? null;
        $unarchiveReason = trim($_POST['unarchive_reason'] ?? '');
        
        if (!$studentId) {
            echo json_encode(['success' => false, 'message' => 'Student ID is required']);
            exit;
        }
        
        if (empty($unarchiveReason)) {
            echo json_encode(['success' => false, 'message' => 'Unarchive reason is required']);
            exit;
        }
        
        // Get student data before unarchiving for audit log
        $studentQuery = pg_query_params($connection,
            "SELECT student_id, first_name, last_name, middle_name, archive_reason, archived_at 
             FROM students WHERE student_id = $1",
            [$studentId]
        );
        
        if (!$studentQuery || pg_num_rows($studentQuery) === 0) {
            echo json_encode(['success' => false, 'message' => 'Student not found']);
            exit;
        }
        
        $student = pg_fetch_assoc($studentQuery);
        $fullName = trim($student['first_name'] . ' ' . ($student['middle_name'] ?? '') . ' ' . $student['last_name']);
        
        // Unarchive student with reason
        $result = pg_query_params($connection,
            "SELECT unarchive_student($1, $2, $3) as success",
            [$studentId, $adminId, $unarchiveReason]
        );
        
        if ($result && pg_fetch_assoc($result)['success'] === 't') {
            // Extract archived files back to permanent storage using NEW structure
            require_once '../../services/UnifiedFileService.php';
            $fileService = new UnifiedFileService($connection);
            $extractResult = $fileService->extractArchivedStudent($studentId);
            
            // Delete the ZIP file after successful extraction
            $zipDeleted = false;
            if ($extractResult['success']) {
                $zipDeleted = $fileService->deleteArchivedZip($studentId);
            }
            
            // Log to audit trail
            $auditLogger->logStudentUnarchived(
                $adminId,
                $adminUsername,
                $studentId,
                [
                    'full_name' => $fullName,
                    'archive_reason' => $student['archive_reason'],
                    'archived_at' => $student['archived_at'],
                    'files_restored' => $extractResult['files_extracted'] ?? 0,
                    'zip_deleted' => $zipDeleted
                ]
            );
            
            $message = 'Student successfully unarchived';
            if (($extractResult['files_extracted'] ?? 0) > 0) {
                $message .= ' and ' . $extractResult['files_extracted'] . ' files restored';
            }
            if ($zipDeleted) {
                $message .= '. Archive ZIP file removed';
            }
            
            echo json_encode(['success' => true, 'message' => $message]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to unarchive student']);
        }
    } catch (Throwable $t) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error: ' . $t->getMessage()]);
    }
    exit;
}

// Handle ZIP file download
if (isset($_GET['download_zip']) && !empty($_GET['student_id'])) {
    $studentId = $_GET['student_id'];
    
    // Security check - verify student is archived and admin has permission
    $checkQuery = pg_query_params($connection,
        "SELECT first_name, last_name, is_archived FROM students WHERE student_id = $1",
        [$studentId]
    );
    
    if (!$checkQuery || pg_num_rows($checkQuery) === 0) {
        $_SESSION['error_message'] = 'Student not found';
        header('Location: archived_students.php');
        exit;
    }
    
    $studentData = pg_fetch_assoc($checkQuery);
    
    if ($studentData['is_archived'] !== 't') {
        $_SESSION['error_message'] = 'Student is not archived';
        header('Location: archived_students.php');
        exit;
    }
    
    // Get ZIP file path
    require_once '../../services/FileManagementService.php';
    $fileService = new FileManagementService($connection);
    $zipFile = $fileService->getArchivedStudentZip($studentId);
    
    if (!$zipFile || !file_exists($zipFile)) {
        $_SESSION['error_message'] = 'Archive file not found for this student';
        header('Location: archived_students.php');
        exit;
    }
    
    // Log the download action
    $auditLogger->logEvent(
        'student_archive_download',
        'student_management',
        "Downloaded archived documents for student: {$studentData['first_name']} {$studentData['last_name']} (ID: {$studentId})",
        [
            'admin_id' => $adminId,
            'admin_username' => $adminUsername,
            'student_id' => $studentId,
            'student_name' => $studentData['first_name'] . ' ' . $studentData['last_name']
        ]
    );
    
    // Send file for download
    $fileName = $studentId . '_archived_documents.zip';
    
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . filesize($zipFile));
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
    
    readfile($zipFile);
    exit;
}

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $whereConditions = ["s.is_archived = TRUE"];
    $params = [];
    $paramCount = 1;
    
    // Apply filters for export
    if (!empty($_GET['search'])) {
        $searchTerm = '%' . $_GET['search'] . '%';
        $whereConditions[] = "(s.first_name ILIKE $" . $paramCount . " OR s.last_name ILIKE $" . ($paramCount+1) . " OR s.email ILIKE $" . ($paramCount+2) . " OR s.student_id ILIKE $" . ($paramCount+3) . ")";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $paramCount += 4;
    }
    
    if (!empty($_GET['archive_type'])) {
        if ($_GET['archive_type'] === 'manual') {
            $whereConditions[] = "s.archived_by IS NOT NULL";
        } elseif ($_GET['archive_type'] === 'automatic') {
            $whereConditions[] = "s.archived_by IS NULL";
        }
    }
    
    if (!empty($_GET['year_level'])) {
        $whereConditions[] = "s.year_level_id = $" . $paramCount++;
        $params[] = $_GET['year_level'];
    }
    
    if (!empty($_GET['date_from'])) {
        $whereConditions[] = "s.archived_at >= $" . $paramCount++;
        $params[] = $_GET['date_from'] . ' 00:00:00';
    }
    
    if (!empty($_GET['date_to'])) {
        $whereConditions[] = "s.archived_at <= $" . $paramCount++;
        $params[] = $_GET['date_to'] . ' 23:59:59';
    }
    
    // Municipality filter - include students with NULL municipality (visible to all)
    if ($adminMunicipalityId) {
        $whereConditions[] = "(s.municipality_id = $" . $paramCount . " OR s.municipality_id IS NULL)";
        $params[] = $adminMunicipalityId;
        $paramCount++;
    }
    
    $whereClause = implode(' AND ', $whereConditions);
    
    $query = "
        SELECT 
            s.student_id,
            s.first_name,
            s.middle_name,
            s.last_name,
            s.email,
            s.mobile,
            yl.name as year_level,
            u.name as university,
            s.archived_at,
            s.archive_reason,
            s.unarchived_at,
            s.unarchived_by,
            s.unarchive_reason,
            CASE WHEN s.archived_by IS NULL THEN 'Automatic' ELSE 'Manual' END as archive_type,
            CONCAT(a.first_name, ' ', a.last_name) as archived_by_name,
            CONCAT(ua.first_name, ' ', ua.last_name) as unarchived_by_name
        FROM students s
        LEFT JOIN year_levels yl ON s.year_level_id = yl.year_level_id
        LEFT JOIN universities u ON s.university_id = u.university_id
        LEFT JOIN admins a ON s.archived_by = a.admin_id
        LEFT JOIN admins ua ON s.unarchived_by = ua.admin_id
        WHERE {$whereClause}
        ORDER BY s.archived_at DESC
    ";
    
    $result = pg_query_params($connection, $query, $params);
    
    // Generate CSV
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="archived_students_' . date('Y-m-d_His') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Student ID', 'First Name', 'Middle Name', 'Last Name', 'Email', 'Mobile', 'Year Level', 'University', 'Archived At', 'Archive Type', 'Archived By', 'Archive Reason', 'Previously Unarchived At', 'Unarchived By', 'Unarchive Reason']);
    
    while ($row = pg_fetch_assoc($result)) {
        fputcsv($output, [
            $row['student_id'],
            $row['first_name'],
            $row['middle_name'],
            $row['last_name'],
            $row['email'],
            $row['mobile'],
            $row['year_level'],
            $row['university'],
            $row['archived_at'],
            $row['archive_type'],
            $row['archived_by_name'] ?? 'System',
            $row['archive_reason'],
            $row['unarchived_at'] ?? '',
            $row['unarchived_by_name'] ?? '',
            $row['unarchive_reason'] ?? ''
        ]);
    }
    
    fclose($output);
    exit;
}

// Get filter values
$searchTerm = $_GET['search'] ?? '';
$archiveType = $_GET['archive_type'] ?? '';
$yearLevel = $_GET['year_level'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 50;
$offset = ($page - 1) * $perPage;

// Build query with filters
$whereConditions = ["s.is_archived = TRUE"];
$params = [];
$paramCount = 1;

if (!empty($searchTerm)) {
    $searchParam = '%' . $searchTerm . '%';
    $whereConditions[] = "(s.first_name ILIKE $" . $paramCount . " OR s.last_name ILIKE $" . ($paramCount+1) . " OR s.email ILIKE $" . ($paramCount+2) . " OR s.student_id ILIKE $" . ($paramCount+3) . ")";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $paramCount += 4;
}

if (!empty($archiveType)) {
    if ($archiveType === 'manual') {
        $whereConditions[] = "s.archived_by IS NOT NULL";
    } elseif ($archiveType === 'automatic') {
        $whereConditions[] = "s.archived_by IS NULL";
    }
}

if (!empty($yearLevel)) {
    $whereConditions[] = "s.year_level_id = $" . $paramCount++;
    $params[] = $yearLevel;
}

if (!empty($dateFrom)) {
    $whereConditions[] = "s.archived_at >= $" . $paramCount++;
    $params[] = $dateFrom . ' 00:00:00';
}

if (!empty($dateTo)) {
    $whereConditions[] = "s.archived_at <= $" . $paramCount++;
    $params[] = $dateTo . ' 23:59:59';
}

// Municipality filter - include students with NULL municipality (they're visible to all)
if ($adminMunicipalityId) {
    $whereConditions[] = "(s.municipality_id = $" . $paramCount . " OR s.municipality_id IS NULL)";
    $params[] = $adminMunicipalityId;
    $paramCount++;
}

$whereClause = implode(' AND ', $whereConditions);

// Get total count
$countQuery = "SELECT COUNT(*) as total FROM students s WHERE {$whereClause}";
$countResult = pg_query_params($connection, $countQuery, $params);
$totalRecords = pg_fetch_assoc($countResult)['total'];
$totalPages = ceil($totalRecords / $perPage);

// Get archived students
$params[] = $perPage;
$params[] = $offset;

$query = "
    SELECT 
        s.student_id,
        s.first_name,
        s.middle_name,
        s.last_name,
        s.extension_name,
        s.email,
        s.mobile,
        s.bdate,
        yl.name as year_level_name,
        u.name as university_name,
        s.first_registered_academic_year as academic_year_registered,
        s.expected_graduation_year,
        s.archived_at,
        s.archived_by,
        s.archive_reason,
        s.unarchived_at,
        s.unarchived_by,
        s.unarchive_reason,
        s.last_login,
        CONCAT(a.first_name, ' ', a.last_name) as archived_by_name,
        CONCAT(ua.first_name, ' ', ua.last_name) as unarchived_by_name,
        CASE 
            WHEN s.archived_by IS NULL THEN 'Automatic'
            ELSE 'Manual'
        END as archive_type
    FROM students s
    LEFT JOIN year_levels yl ON s.year_level_id = yl.year_level_id
    LEFT JOIN universities u ON s.university_id = u.university_id
    LEFT JOIN admins a ON s.archived_by = a.admin_id
    LEFT JOIN admins ua ON s.unarchived_by = ua.admin_id
    WHERE {$whereClause}
    ORDER BY s.archived_at DESC
    LIMIT $" . $paramCount++ . " OFFSET $" . $paramCount;

$result = pg_query_params($connection, $query, $params);

// Check for query errors
if (!$result) {
    error_log("Archived students query error: " . pg_last_error($connection));
    error_log("Query: " . $query);
    error_log("Params: " . print_r($params, true));
}

// Fetch all results into an array to avoid pointer issues later
$students = $result ? pg_fetch_all($result) : [];
$resultRowCount = $students ? count($students) : 0;

// Get year levels for filter
$yearLevelsQuery = pg_query($connection, "SELECT year_level_id, name FROM year_levels ORDER BY sort_order");
$yearLevels = pg_fetch_all($yearLevelsQuery);

// Get statistics
$statsQuery = "
    SELECT 
        COUNT(*) as total_archived,
        COUNT(CASE WHEN archived_by IS NULL THEN 1 END) as auto_archived,
        COUNT(CASE WHEN archived_by IS NOT NULL THEN 1 END) as manual_archived,
        COUNT(CASE WHEN archived_at >= CURRENT_DATE - INTERVAL '30 days' THEN 1 END) as archived_last_30_days,
        COUNT(CASE WHEN unarchived_at IS NOT NULL THEN 1 END) as previously_unarchived
    FROM students
    WHERE is_archived = TRUE" . 
    ($adminMunicipalityId ? " AND (municipality_id = " . $adminMunicipalityId . " OR municipality_id IS NULL)" : "");

$statsResult = pg_query($connection, $statsQuery);
$stats = pg_fetch_assoc($statsResult);
?>
<?php $page_title='Archived Students'; $extra_css=['../../assets/css/admin/manage_applicants.css','../../assets/css/admin/table_core.css']; include __DIR__ . '/../../includes/admin/admin_head.php'; ?>
<style>
    :root {
        --primary-color: #2c3e50;
        --secondary-color: #3498db;
        --success-color: #27ae60;
        --warning-color: #f39c12;
        --danger-color: #e74c3c;
        --info-color: #3498db;
        --light-bg: #ecf0f1;
    }

    /* Single-row, scrollable stats bar to avoid orphaned last card */
    .stats-cards {
        display: flex;
        flex-wrap: nowrap;
        gap: 16px;
        margin-bottom: 25px;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        padding-bottom: 6px; /* space for scrollbar */
        scroll-snap-type: x proximity;
    }
    .stats-cards .stat-card { flex: 0 0 240px; scroll-snap-align: start; }
    @media (min-width: 1400px) { .stats-cards .stat-card { flex-basis: 260px; } }

    .stat-card {
        padding: 20px;
        border-radius: 16px;
        transition: transform 0.3s ease;
        text-align: center;
        position: relative;
        overflow: hidden;
    }

    .stat-card:hover {
        transform: translateY(-5px);
    }
    
    .stat-card .watermark-icon {
        position: absolute;
        right: -10px;
        bottom: -10px;
        font-size: 5rem;
        opacity: 0.15;
        transform: rotate(-10deg);
        color: white;
    }

    .stat-number {
        font-size: 2.5rem;
        font-weight: bold;
        margin: 10px 0;
        color: white;
    }

    .stat-label {
        color: rgba(255,255,255,0.85);
        font-size: 0.9rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        font-weight: 500;
    }
    
    .stat-blue {
        background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
        box-shadow: 0 4px 14px rgba(59, 130, 246, 0.35);
    }
    .stat-green {
        background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
        box-shadow: 0 4px 14px rgba(34, 197, 94, 0.35);
    }
    .stat-cyan {
        background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
        box-shadow: 0 4px 14px rgba(6, 182, 212, 0.35);
    }
    .stat-amber {
        background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        box-shadow: 0 4px 14px rgba(245, 158, 11, 0.35);
    }
    .stat-purple {
        background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
        box-shadow: 0 4px 14px rgba(139, 92, 246, 0.35);
    }

    .filter-card {
        background: white;
        padding: 1.25rem;
        border-radius: 12px;
        border: 1px solid #e5e7eb;
        box-shadow: 0 2px 4px rgba(0,0,0,0.04);
        margin-bottom: 1.5rem;
    }
    .filter-card .form-label {
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #6b7280;
        margin-bottom: 0.35rem;
    }
    .filter-card .form-control,
    .filter-card .form-select {
        font-size: 0.9rem;
        border-radius: 8px;
        border: 1px solid #d1d5db;
    }
    .filter-card .form-control:focus,
    .filter-card .form-select:focus {
        border-color: var(--primary-color);
        box-shadow: 0 0 0 3px rgba(17, 130, 255, 0.1);
    }
    .filter-grid {
        display: grid;
        grid-template-columns: 2fr 1fr 1fr 1fr 1fr auto;
        gap: 1rem;
        align-items: end;
    }
    .filter-actions {
        display: flex;
        gap: 0.5rem;
        align-items: center;
        flex-wrap: wrap;
        margin-top: 1rem;
        padding-top: 1rem;
        border-top: 1px solid #f0f0f0;
    }
    @media (max-width: 1200px) {
        .filter-grid {
            grid-template-columns: 1fr 1fr 1fr;
        }
    }
    @media (max-width: 768px) {
        .filter-grid {
            grid-template-columns: 1fr 1fr;
        }
    }
    @media (max-width: 576px) {
        .filter-grid {
            grid-template-columns: 1fr;
        }
    }

    .table-card {
        background: white;
        border-radius: 10px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        overflow: hidden;
    }

    .table {
        margin-bottom: 0;
    }

    .table thead th {
        background-color: var(--primary-color);
        color: white;
        font-weight: 600;
        border: none;
        padding: 15px;
    }

    .table tbody tr:hover {
        background-color: #f8f9fa;
    }

    .badge.automatic {
        background-color: #27ae60 !important;
        color: white;
    }

    .badge.manual {
        background-color: #3498db !important;
        color: white;
    }
    
    .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: #7f8c8d;
    }
    
    .empty-state i {
        font-size: 4rem;
        margin-bottom: 20px;
        opacity: 0.3;
    }
    
    .table-section {
        background: white;
        padding: 20px;
        border-radius: 10px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }

    .btn-action {
        padding: 5px 10px;
        font-size: 0.875rem;
    }

    .pagination-container {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 20px;
        background: white;
        border-top: 1px solid #dee2e6;
    }

    /* Fix modal z-index to appear above sidebar/topbar */
    .modal {
        z-index: 9999 !important;
    }
    
    .modal-backdrop {
        z-index: 9998 !important;
    }

    /* Mobile-only compact modal size for this page */
    @media (max-width: 576px) {
        .modal-mobile-compact .modal-dialog {
            max-width: 420px; /* cap width so background remains visible */
            width: 88%;
            margin: 1rem auto;
        }
        .modal-mobile-compact .modal-content {
            border-radius: 12px;
        }
        .modal-mobile-compact .modal-body {
            max-height: 60vh; /* shorter height to expose backdrop */
            overflow-y: auto;
        }
        .modal-mobile-compact .modal-header,
        .modal-mobile-compact .modal-footer {
            padding-top: 0.6rem;
            padding-bottom: 0.6rem;
        }
    }
</style>
<body>
<?php include __DIR__ . '/../../includes/admin/admin_topbar.php'; ?>
<div id="wrapper" class="admin-wrapper">
    <?php include __DIR__ . '/../../includes/admin/admin_sidebar.php'; ?>
    <?php include __DIR__ . '/../../includes/admin/admin_header.php'; ?>

    <section class="home-section" id="mainContent">
        <div class="container-fluid py-4 px-4">
            <div class="mb-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h1 class="fw-bold mb-1">Archived Students</h1>
                </div>
                <span class="badge bg-secondary fs-6"><?php echo $stats['total_archived']; ?> Archived</span>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-cards">
                <div class="stat-card stat-blue">
                    <i class="bi bi-archive watermark-icon"></i>
                    <div class="stat-number"><?php echo $stats['total_archived']; ?></div>
                    <div class="stat-label">Total Archived</div>
                </div>

                <div class="stat-card stat-green">
                    <i class="bi bi-gear watermark-icon"></i>
                    <div class="stat-number"><?php echo $stats['auto_archived']; ?></div>
                    <div class="stat-label">Automatic Archives</div>
                </div>

                <div class="stat-card stat-cyan">
                    <i class="bi bi-hand-index watermark-icon"></i>
                    <div class="stat-number"><?php echo $stats['manual_archived']; ?></div>
                    <div class="stat-label">Manual Archives</div>
                </div>

                <div class="stat-card stat-amber">
                    <i class="bi bi-calendar-event watermark-icon"></i>
                    <div class="stat-number"><?php echo $stats['archived_last_30_days']; ?></div>
                    <div class="stat-label">Last 30 Days</div>
                </div>
                
                <div class="stat-card stat-purple">
                    <i class="bi bi-arrow-repeat watermark-icon"></i>
                    <div class="stat-number"><?php echo $stats['previously_unarchived']; ?></div>
                    <div class="stat-label">Re-archived (Previously Restored)</div>
                </div>
            </div>

            <!-- Filter Card -->
            <div class="filter-card">
                <form method="GET" id="filterForm">
                <div class="filter-grid">
                    <div>
                        <label class="form-label">Search</label>
                        <input type="text" class="form-control" name="search" 
                               placeholder="Name, Email, or ID" 
                               value="<?php echo htmlspecialchars($searchTerm); ?>">
                    </div>

                    <div>
                        <label class="form-label">Archive Type</label>
                        <select class="form-select" name="archive_type">
                            <option value="">All Types</option>
                            <option value="automatic" <?php echo $archiveType === 'automatic' ? 'selected' : ''; ?>>Automatic</option>
                            <option value="manual" <?php echo $archiveType === 'manual' ? 'selected' : ''; ?>>Manual</option>
                        </select>
                    </div>

                    <div>
                        <label class="form-label">Year Level</label>
                        <select class="form-select" name="year_level">
                            <option value="">All Levels</option>
                            <?php foreach ($yearLevels as $yl): ?>
                                <option value="<?php echo $yl['year_level_id']; ?>" 
                                        <?php echo $yearLevel == $yl['year_level_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($yl['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="form-label">Date From</label>
                        <input type="date" class="form-control" name="date_from" 
                               value="<?php echo htmlspecialchars($dateFrom); ?>">
                    </div>

                    <div>
                        <label class="form-label">Date To</label>
                        <input type="date" class="form-control" name="date_to" 
                               value="<?php echo htmlspecialchars($dateTo); ?>">
                    </div>

                    <div>
                        <button type="submit" class="btn btn-primary">Filter</button>
                    </div>
                </div>

                <div class="filter-actions">
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="clearFilters()">Clear</button>
                    <a href="?export=csv<?php 
                        echo !empty($searchTerm) ? '&search=' . urlencode($searchTerm) : '';
                        echo !empty($archiveType) ? '&archive_type=' . urlencode($archiveType) : '';
                        echo !empty($yearLevel) ? '&year_level=' . urlencode($yearLevel) : '';
                        echo !empty($dateFrom) ? '&date_from=' . urlencode($dateFrom) : '';
                        echo !empty($dateTo) ? '&date_to=' . urlencode($dateTo) : '';
                    ?>" class="btn btn-success btn-sm">
                        <i class="bi bi-download"></i> Export to CSV
                    </a>
                </div>
                </div>
            </form>
        </div>

        <!-- Table Section: removed card wrapper for consistency -->
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">Archived Students (<?php echo number_format($totalRecords); ?>)</h5>
                <span class="text-muted">Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
            </div>

            <?php if ($result && $resultRowCount > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Student ID</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Year Level</th>
                                <th>University</th>
                                <th>Archived At</th>
                                <th>Type</th>
                                <th>Archived By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            foreach ($students as $student):
                                $fullName = trim($student['first_name'] . ' ' . 
                                          ($student['middle_name'] ? $student['middle_name'] . ' ' : '') . 
                                          $student['last_name'] . ' ' . 
                                          ($student['extension_name'] ?? ''));
                            ?>
                                <tr>
                                    <td data-label="Student ID"><?php echo htmlspecialchars($student['student_id']); ?></td>
                                    <td data-label="Name">
                                        <strong><?php echo htmlspecialchars($fullName); ?></strong>
                                    </td>
                                    <td data-label="Email"><?php echo htmlspecialchars($student['email']); ?></td>
                                    <td data-label="Year Level"><?php echo htmlspecialchars($student['year_level_name'] ?? 'N/A'); ?></td>
                                    <td data-label="University"><?php echo htmlspecialchars($student['university_name'] ?? 'N/A'); ?></td>
                                    <td data-label="Archived At"><?php echo date('M d, Y h:i A', strtotime($student['archived_at'])); ?></td>
                                    <td data-label="Type">
                                        <span class="badge <?php echo strtolower($student['archive_type']); ?>">
                                            <?php echo $student['archive_type']; ?>
                                        </span>
                                        <?php if ($student['unarchived_at']): ?>
                                            <br>
                                            <small class="text-muted" 
                                                   title="Previously restored on <?php echo date('M d, Y h:i A', strtotime($student['unarchived_at'])); ?> by <?php echo htmlspecialchars($student['unarchived_by_name'] ?? 'Unknown'); ?><?php echo $student['unarchive_reason'] ? '\nReason: ' . htmlspecialchars($student['unarchive_reason']) : ''; ?>">
                                                <i class="bi bi-arrow-counterclockwise"></i> Re-archived
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Archived By">
                                        <?php echo $student['archived_by_name'] ?? '<em>System</em>'; ?>
                                        <?php if ($student['unarchived_at']): ?>
                                            <br>
                                            <small class="text-muted" 
                                                   title="<?php echo htmlspecialchars($student['unarchive_reason'] ?? 'No reason provided'); ?>">
                                                <i class="bi bi-info-circle"></i> Last restored: <?php echo date('M d, Y', strtotime($student['unarchived_at'])); ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Actions">
                                        <button class="btn btn-sm btn-info" 
                                                onclick="viewDetails('<?php echo htmlspecialchars($student['student_id'], ENT_QUOTES); ?>')">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                        <a href="?download_zip=1&student_id=<?php echo urlencode($student['student_id']); ?>" 
                                           class="btn btn-sm btn-primary"
                                           title="Download archived documents">
                                            <i class="bi bi-download"></i> ZIP
                                        </a>
                                        <button class="btn btn-sm btn-success btn-unarchive" 
                                                onclick="unarchiveStudent('<?php echo htmlspecialchars($student['student_id'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($fullName, ENT_QUOTES); ?>')">
                                            <i class="bi bi-arrow-counterclockwise"></i> Unarchive
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <nav>
                        <ul class="pagination">
                            <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $page - 1; ?><?php 
                                        echo !empty($searchTerm) ? '&search=' . urlencode($searchTerm) : '';
                                        echo !empty($archiveType) ? '&archive_type=' . urlencode($archiveType) : '';
                                        echo !empty($yearLevel) ? '&year_level=' . urlencode($yearLevel) : '';
                                        echo !empty($dateFrom) ? '&date_from=' . urlencode($dateFrom) : '';
                                        echo !empty($dateTo) ? '&date_to=' . urlencode($dateTo) : '';
                                    ?>">Previous</a>
                                </li>
                            <?php endif; ?>

                            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?><?php 
                                        echo !empty($searchTerm) ? '&search=' . urlencode($searchTerm) : '';
                                        echo !empty($archiveType) ? '&archive_type=' . urlencode($archiveType) : '';
                                        echo !empty($yearLevel) ? '&year_level=' . urlencode($yearLevel) : '';
                                        echo !empty($dateFrom) ? '&date_from=' . urlencode($dateFrom) : '';
                                        echo !empty($dateTo) ? '&date_to=' . urlencode($dateTo) : '';
                                    ?>"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>

                            <?php if ($page < $totalPages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $page + 1; ?><?php 
                                        echo !empty($searchTerm) ? '&search=' . urlencode($searchTerm) : '';
                                        echo !empty($archiveType) ? '&archive_type=' . urlencode($archiveType) : '';
                                        echo !empty($yearLevel) ? '&year_level=' . urlencode($yearLevel) : '';
                                        echo !empty($dateFrom) ? '&date_from=' . urlencode($dateFrom) : '';
                                        echo !empty($dateTo) ? '&date_to=' . urlencode($dateTo) : '';
                                    ?>">Next</a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                <?php endif; ?>

            <?php elseif (!$result): ?>
                <div class="alert alert-danger">
                    <h4><i class="bi bi-exclamation-triangle"></i> Database Error</h4>
                    <p>Failed to retrieve archived students. Error: <?php echo htmlspecialchars(pg_last_error($connection)); ?></p>
                    <p><small>Please check the error log or contact system administrator.</small></p>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="bi bi-archive"></i>
                    <h4>No Archived Students Found</h4>
                    <p>There are no archived students matching your criteria.</p>
                </div>
            <?php endif; ?>
        
    </section>
</body>div>

<!-- Student Details Modal - MUST BE OUTSIDE WRAPPER -->
<div class="modal fade modal-mobile-compact" id="detailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-info-circle"></i> Student Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="detailsContent">
                <!-- Content loaded via AJAX -->
            </div>
        </div>
    </div>
</div>

<!-- Unarchive Modal -->
<div class="modal fade modal-mobile-compact" id="unarchiveModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">
                    <i class="bi bi-arrow-counterclockwise"></i> Unarchive Student
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="unarchiveForm">
                <div class="modal-body">
                    <input type="hidden" id="unarchiveStudentId">
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        You are about to unarchive <strong id="unarchiveStudentName"></strong>.
                        This will restore their account and they will be able to log in again.
                    </div>
                    
                    <div class="mb-3">
                        <label for="unarchiveReason" class="form-label">
                            Reason for Unarchiving <span class="text-danger">*</span>
                        </label>
                        <textarea class="form-control" 
                                  id="unarchiveReason" 
                                  name="unarchive_reason" 
                                  rows="3" 
                                  required
                                  placeholder="e.g., Account was archived by mistake, Student re-enrolled, Administrative error, etc."></textarea>
                        <div class="form-text">
                            Please provide a clear reason for restoring this student's account.
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-arrow-counterclockwise"></i> Confirm Unarchive
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Guard and reusable modal instance for details
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
                document.body.style.removeProperty('overflow');
            }
        }

        if (detailsModalEl) {
            detailsModalEl.addEventListener('shown.bs.modal', cleanupExtraBackdrops);
            detailsModalEl.addEventListener('hidden.bs.modal', cleanupExtraBackdrops);
        }
        function clearFilters() {
            window.location.href = 'archived_students.php';
        }

        function viewDetails(studentId) {
            if (detailsRequestInFlight) return;
            if (detailsModalEl && detailsModalEl.classList.contains('show')) return;

            detailsRequestInFlight = true;
            const content = document.getElementById('detailsContent');
            if (content) {
                content.innerHTML = '<div class="text-center py-4"><div class="spinner-border" role="status"></div><p class="mt-2">Loading...</p></div>';
            }
            if (detailsModal) detailsModal.show();

            fetch(`get_archived_student_details.php?student_id=${encodeURIComponent(studentId)}`)
                .then(response => response.text())
                .then(html => {
                    if (content) content.innerHTML = html;
                })
                .catch(() => {
                    if (content) content.innerHTML = '<div class="alert alert-danger">Error loading student details.</div>';
                })
                .finally(() => {
                    detailsRequestInFlight = false;
                });
        }
        
        // Clean up modal on close
        document.addEventListener('DOMContentLoaded', function() {
            const modalEl = document.getElementById('detailsModal');
            if (modalEl) {
                modalEl.addEventListener('hidden.bs.modal', function() {
                    // Clean up any lingering backdrops
                    const backdrops = document.querySelectorAll('.modal-backdrop');
                    backdrops.forEach(backdrop => backdrop.remove());
                    document.body.classList.remove('modal-open');
                    document.body.style.removeProperty('overflow');
                    document.body.style.removeProperty('padding-right');
                });
            }
        });

        function unarchiveStudent(studentId, studentName) {
            // Show the unarchive modal
            const modal = new bootstrap.Modal(document.getElementById('unarchiveModal'));
            document.getElementById('unarchiveStudentId').value = studentId;
            document.getElementById('unarchiveStudentName').textContent = studentName;
            document.getElementById('unarchiveReason').value = '';
            modal.show();
        }

        // Handle unarchive form submission
        document.addEventListener('DOMContentLoaded', function() {
            const unarchiveForm = document.getElementById('unarchiveForm');
            if (unarchiveForm) {
                unarchiveForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    const studentId = document.getElementById('unarchiveStudentId').value;
                    const reason = document.getElementById('unarchiveReason').value.trim();
                    
                    if (!reason) {
                        alert('Please provide a reason for unarchiving this student.');
                        return;
                    }
                    
                    const formData = new FormData();
                    formData.append('action', 'unarchive');
                    formData.append('student_id', studentId);
                    formData.append('unarchive_reason', reason);

                    // Disable submit button
                    const submitBtn = this.querySelector('button[type="submit"]');
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Processing...';

                    fetch('archived_students.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert(data.message);
                            location.reload();
                        } else {
                            alert('Error: ' + data.message);
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = '<i class="bi bi-arrow-counterclockwise"></i> Confirm Unarchive';
                        }
                    })
                    .catch(error => {
                        alert('An error occurred. Please try again.');
                        console.error(error);
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = '<i class="bi bi-arrow-counterclockwise"></i> Confirm Unarchive';
                    });
                });
            }
        });
    </script>

<script src="../../assets/js/admin/sidebar.js"></script>
</body>
</html>
