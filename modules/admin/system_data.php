<?php
include __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../services/AuditLogger.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['admin_username'])) {
    header("Location: ../../unified_login.php");
    exit;
}

// Initialize AuditLogger
$auditLogger = new AuditLogger($connection);

// Check if current admin is super_admin
$current_admin_role = 'super_admin'; // Default for backward compatibility
if (isset($_SESSION['admin_id'])) {
    $roleQuery = pg_query_params($connection, "SELECT role FROM admins WHERE admin_id = $1", [$_SESSION['admin_id']]);
    $roleData = pg_fetch_assoc($roleQuery);
    $current_admin_role = $roleData['role'] ?? 'super_admin';
}

// Only super_admin can access this page
if ($current_admin_role !== 'super_admin') {
    header("Location: homepage.php");
    exit;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Debug logging
    error_log("POST received: " . print_r($_POST, true));
    
    // Add University
    if (isset($_POST['add_university'])) {
        $name = trim($_POST['university_name']);
        $code = trim(strtoupper($_POST['university_code']));
        
        // Grading policy fields
        $scale_type = trim($_POST['scale_type'] ?? 'NUMERIC_1_TO_5');
        $higher_is_better = isset($_POST['higher_is_better']) && $_POST['higher_is_better'] === '1';
        $highest_value = trim($_POST['highest_value'] ?? '1.0');
        $passing_value = trim($_POST['passing_value'] ?? '3.0');
        $letter_order = !empty($_POST['letter_order']) ? trim($_POST['letter_order']) : null;
        
        error_log("Adding university: $name ($code) with grading policy: $scale_type");
        
        if (!empty($name) && !empty($code) && !empty($highest_value) && !empty($passing_value)) {
            // Start transaction
            pg_query($connection, "BEGIN");
            
            $insertQuery = "INSERT INTO universities (name, code) VALUES ($1, $2) RETURNING university_id";
            $result = pg_query_params($connection, $insertQuery, [$name, $code]);
            
            if ($result) {
                $new_university = pg_fetch_assoc($result);
                $university_id = $new_university['university_id'];
                
                // Insert grading policy
                $letter_order_array = null;
                if ($scale_type === 'LETTER' && !empty($letter_order)) {
                    // Convert comma-separated string to PostgreSQL array format
                    $letters = array_map('trim', explode(',', $letter_order));
                    $letter_order_array = '{' . implode(',', $letters) . '}';
                }
                
                $policyQuery = "INSERT INTO grading.university_passing_policy 
                    (university_key, scale_type, higher_is_better, highest_value, passing_value, letter_order, is_active) 
                    VALUES ($1, $2, $3, $4, $5, $6, TRUE)";
                $policyResult = pg_query_params($connection, $policyQuery, [
                    $code, 
                    $scale_type, 
                    $higher_is_better ? 't' : 'f', 
                    $highest_value, 
                    $passing_value,
                    $letter_order_array
                ]);
                
                if ($policyResult) {
                    pg_query($connection, "COMMIT");
                    
                    error_log("University added successfully with ID: $university_id and grading policy");
                    
                    $notification_msg = "New university added: " . $name . " (" . $code . ") with " . $scale_type . " grading";
                    pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
                    
                    // Audit Trail
                    $auditLogger->logEvent(
                        'university_added',
                        'system_data',
                        "Added new university: {$name} ({$code}) with grading policy",
                        [
                            'user_id' => $_SESSION['admin_id'] ?? null,
                            'user_type' => 'admin',
                            'username' => $_SESSION['admin_username'] ?? 'Unknown',
                            'affected_table' => 'universities',
                            'affected_record_id' => $university_id,
                            'new_values' => [
                                'university_id' => $university_id,
                                'name' => $name,
                                'code' => $code,
                                'scale_type' => $scale_type,
                                'higher_is_better' => $higher_is_better,
                                'highest_value' => $highest_value,
                                'passing_value' => $passing_value
                            ],
                            'metadata' => [
                                'action' => 'add',
                                'admin_role' => $current_admin_role
                            ]
                        ]
                    );
                    
                    $success = "University added successfully with grading policy!";
                    
                    // Redirect to prevent form resubmission
                    header("Location: " . $_SERVER['PHP_SELF'] . "?success=university_added");
                    exit;
                } else {
                    pg_query($connection, "ROLLBACK");
                    $db_error = pg_last_error($connection);
                    error_log("Failed to add grading policy: $db_error");
                    $error = "Failed to add grading policy. Error: " . $db_error;
                }
            } else {
                pg_query($connection, "ROLLBACK");
                $db_error = pg_last_error($connection);
                error_log("Failed to add university: $db_error");
                $error = "Failed to add university. Code may already exist. Error: " . $db_error;
            }
        } else {
            $error = "Please fill in all required fields including grading policy.";
        }
    }
    
    // Edit University
    if (isset($_POST['edit_university'])) {
        $university_id = intval($_POST['university_id']);
        $name = trim($_POST['university_name']);
        $code = trim(strtoupper($_POST['university_code']));
        
        // Grading policy fields
        $scale_type = trim($_POST['scale_type'] ?? 'NUMERIC_1_TO_5');
        $higher_is_better = isset($_POST['higher_is_better']) && $_POST['higher_is_better'] === '1';
        $highest_value = trim($_POST['highest_value'] ?? '1.0');
        $passing_value = trim($_POST['passing_value'] ?? '3.0');
        $letter_order = !empty($_POST['letter_order']) ? trim($_POST['letter_order']) : null;
        
        if (!empty($name) && !empty($code) && !empty($highest_value) && !empty($passing_value)) {
            // Get old values for audit
            $oldQuery = "SELECT u.name, u.code, p.scale_type, p.higher_is_better, p.highest_value, p.passing_value 
                         FROM universities u 
                         LEFT JOIN grading.university_passing_policy p ON u.code = p.university_key
                         WHERE u.university_id = $1";
            $oldResult = pg_query_params($connection, $oldQuery, [$university_id]);
            $oldValues = pg_fetch_assoc($oldResult);
            $old_code = $oldValues['code'];
            
            // Start transaction
            pg_query($connection, "BEGIN");
            
            $updateQuery = "UPDATE universities SET name = $1, code = $2 WHERE university_id = $3";
            $result = pg_query_params($connection, $updateQuery, [$name, $code, $university_id]);
            
            if ($result) {
                // Update or insert grading policy
                $letter_order_array = null;
                if ($scale_type === 'LETTER' && !empty($letter_order)) {
                    $letters = array_map('trim', explode(',', $letter_order));
                    $letter_order_array = '{' . implode(',', $letters) . '}';
                }
                
                // Check if policy exists for old code
                $policyExistsQuery = "SELECT policy_id FROM grading.university_passing_policy WHERE university_key = $1";
                $policyExists = pg_query_params($connection, $policyExistsQuery, [$old_code]);
                
                if ($policyExists && pg_num_rows($policyExists) > 0) {
                    // Update existing policy (update university_key if code changed)
                    $policyUpdateQuery = "UPDATE grading.university_passing_policy 
                        SET university_key = $1, scale_type = $2, higher_is_better = $3, 
                            highest_value = $4, passing_value = $5, letter_order = $6, updated_at = NOW()
                        WHERE university_key = $7";
                    $policyResult = pg_query_params($connection, $policyUpdateQuery, [
                        $code, $scale_type, $higher_is_better ? 't' : 'f', 
                        $highest_value, $passing_value, $letter_order_array, $old_code
                    ]);
                } else {
                    // Insert new policy
                    $policyInsertQuery = "INSERT INTO grading.university_passing_policy 
                        (university_key, scale_type, higher_is_better, highest_value, passing_value, letter_order, is_active) 
                        VALUES ($1, $2, $3, $4, $5, $6, TRUE)";
                    $policyResult = pg_query_params($connection, $policyInsertQuery, [
                        $code, $scale_type, $higher_is_better ? 't' : 'f', 
                        $highest_value, $passing_value, $letter_order_array
                    ]);
                }
                
                if ($policyResult) {
                    pg_query($connection, "COMMIT");
                    
                    $notification_msg = "University updated: " . $name . " (" . $code . ")";
                    pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
                    
                    // Audit Trail
                    $auditLogger->logEvent(
                        'university_updated',
                        'system_data',
                        "Updated university (ID: {$university_id}): {$oldValues['name']} → {$name}",
                        [
                            'user_id' => $_SESSION['admin_id'] ?? null,
                            'user_type' => 'admin',
                            'username' => $_SESSION['admin_username'] ?? 'Unknown',
                            'affected_table' => 'universities',
                            'affected_record_id' => $university_id,
                            'old_values' => [
                                'name' => $oldValues['name'],
                                'code' => $oldValues['code'],
                                'scale_type' => $oldValues['scale_type'],
                                'passing_value' => $oldValues['passing_value']
                            ],
                            'new_values' => [
                                'name' => $name,
                                'code' => $code,
                                'scale_type' => $scale_type,
                                'passing_value' => $passing_value
                            ],
                            'metadata' => [
                                'action' => 'edit',
                                'admin_role' => $current_admin_role
                            ]
                        ]
                    );
                    
                    $success = "University updated successfully!";
                    header("Location: " . $_SERVER['PHP_SELF'] . "?success=university_updated");
                    exit;
                } else {
                    pg_query($connection, "ROLLBACK");
                    $error = "Failed to update grading policy.";
                }
            } else {
                pg_query($connection, "ROLLBACK");
                $error = "Failed to update university. Code may already exist.";
            }
        }
    }
    
    // Add Barangay
    if (isset($_POST['add_barangay'])) {
        $name = trim($_POST['barangay_name']);
        $municipality_id = 1; // Default municipality
        
        if (!empty($name)) {
            $insertQuery = "INSERT INTO barangays (municipality_id, name) VALUES ($1, $2) RETURNING barangay_id";
            $result = pg_query_params($connection, $insertQuery, [$municipality_id, $name]);
            
            if ($result) {
                $new_barangay = pg_fetch_assoc($result);
                $barangay_id = $new_barangay['barangay_id'];
                
                $notification_msg = "New barangay added: " . $name;
                pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
                
                // Audit Trail
                $auditLogger->logEvent(
                    'barangay_added',
                    'system_data',
                    "Added new barangay: {$name}",
                    [
                        'user_id' => $_SESSION['admin_id'] ?? null,
                        'user_type' => 'admin',
                        'username' => $_SESSION['admin_username'] ?? 'Unknown',
                        'affected_table' => 'barangays',
                        'affected_record_id' => $barangay_id,
                        'new_values' => [
                            'barangay_id' => $barangay_id,
                            'name' => $name,
                            'municipality_id' => $municipality_id
                        ],
                        'metadata' => [
                            'action' => 'add',
                            'admin_role' => $current_admin_role
                        ]
                    ]
                );
                
                $success = "Barangay added successfully!";
            } else {
                $error = "Failed to add barangay.";
            }
        }
    }
    
    // Edit Barangay
    if (isset($_POST['edit_barangay'])) {
        $barangay_id = intval($_POST['barangay_id']);
        $name = trim($_POST['barangay_name']);
        
        if (!empty($name)) {
            // Get old values for audit
            $oldQuery = "SELECT name FROM barangays WHERE barangay_id = $1";
            $oldResult = pg_query_params($connection, $oldQuery, [$barangay_id]);
            $oldValues = pg_fetch_assoc($oldResult);
            
            $updateQuery = "UPDATE barangays SET name = $1 WHERE barangay_id = $2";
            $result = pg_query_params($connection, $updateQuery, [$name, $barangay_id]);
            
            if ($result) {
                $notification_msg = "Barangay updated: " . $name;
                pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
                
                // Audit Trail
                $auditLogger->logEvent(
                    'barangay_updated',
                    'system_data',
                    "Updated barangay (ID: {$barangay_id}): {$oldValues['name']} → {$name}",
                    [
                        'user_id' => $_SESSION['admin_id'] ?? null,
                        'user_type' => 'admin',
                        'username' => $_SESSION['admin_username'] ?? 'Unknown',
                        'affected_table' => 'barangays',
                        'affected_record_id' => $barangay_id,
                        'old_values' => [
                            'name' => $oldValues['name']
                        ],
                        'new_values' => [
                            'name' => $name
                        ],
                        'metadata' => [
                            'action' => 'edit',
                            'admin_role' => $current_admin_role
                        ]
                    ]
                );
                
                $success = "Barangay updated successfully!";
            } else {
                $error = "Failed to update barangay.";
            }
        }
    }
    
    // Delete University
    if (isset($_POST['delete_university'])) {
        $university_id = intval($_POST['university_id']);
        
        // Check if university is being used by students
        $checkQuery = "SELECT COUNT(*) as count FROM students WHERE university_id = $1";
        $checkResult = pg_query_params($connection, $checkQuery, [$university_id]);
        $checkData = pg_fetch_assoc($checkResult);
        
        if ($checkData['count'] > 0) {
            $error = "Cannot delete university. It is currently assigned to " . $checkData['count'] . " student(s).";
        } else {
            // Get university details for audit before deletion
            $getQuery = "SELECT name, code FROM universities WHERE university_id = $1";
            $getResult = pg_query_params($connection, $getQuery, [$university_id]);
            $universityData = pg_fetch_assoc($getResult);
            
            // Start transaction
            pg_query($connection, "BEGIN");
            
            // Delete grading policy first
            $deletePolicyQuery = "DELETE FROM grading.university_passing_policy WHERE university_key = $1";
            pg_query_params($connection, $deletePolicyQuery, [$universityData['code']]);
            
            $deleteQuery = "DELETE FROM universities WHERE university_id = $1";
            $result = pg_query_params($connection, $deleteQuery, [$university_id]);
            
            if ($result) {
                pg_query($connection, "COMMIT");
                
                $notification_msg = "University deleted: {$universityData['name']} (ID: {$university_id})";
                pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
                
                // Audit Trail
                $auditLogger->logEvent(
                    'university_deleted',
                    'system_data',
                    "Deleted university: {$universityData['name']} ({$universityData['code']})",
                    [
                        'user_id' => $_SESSION['admin_id'] ?? null,
                        'user_type' => 'admin',
                        'username' => $_SESSION['admin_username'] ?? 'Unknown',
                        'affected_table' => 'universities',
                        'affected_record_id' => $university_id,
                        'old_values' => [
                            'university_id' => $university_id,
                            'name' => $universityData['name'],
                            'code' => $universityData['code']
                        ],
                        'metadata' => [
                            'action' => 'delete',
                            'admin_role' => $current_admin_role,
                            'reason' => 'No students assigned'
                        ]
                    ]
                );
                
                $success = "University deleted successfully!";
            } else {
                pg_query($connection, "ROLLBACK");
            }
        }
    }
    
    // Delete Barangay
    if (isset($_POST['delete_barangay'])) {
        $barangay_id = intval($_POST['barangay_id']);
        
        // Check if barangay is being used by students
        $checkQuery = "SELECT COUNT(*) as count FROM students WHERE barangay_id = $1";
        $checkResult = pg_query_params($connection, $checkQuery, [$barangay_id]);
        $checkData = pg_fetch_assoc($checkResult);
        
        if ($checkData['count'] > 0) {
            $error = "Cannot delete barangay. It is currently assigned to " . $checkData['count'] . " student(s).";
        } else {
            // Get barangay details for audit before deletion
            $getQuery = "SELECT name FROM barangays WHERE barangay_id = $1";
            $getResult = pg_query_params($connection, $getQuery, [$barangay_id]);
            $barangayData = pg_fetch_assoc($getResult);
            
            $deleteQuery = "DELETE FROM barangays WHERE barangay_id = $1";
            $result = pg_query_params($connection, $deleteQuery, [$barangay_id]);
            
            if ($result) {
                $notification_msg = "Barangay deleted: {$barangayData['name']} (ID: {$barangay_id})";
                pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
                
                // Audit Trail
                $auditLogger->logEvent(
                    'barangay_deleted',
                    'system_data',
                    "Deleted barangay: {$barangayData['name']}",
                    [
                        'user_id' => $_SESSION['admin_id'] ?? null,
                        'user_type' => 'admin',
                        'username' => $_SESSION['admin_username'] ?? 'Unknown',
                        'affected_table' => 'barangays',
                        'affected_record_id' => $barangay_id,
                        'old_values' => [
                            'barangay_id' => $barangay_id,
                            'name' => $barangayData['name']
                        ],
                        'metadata' => [
                            'action' => 'delete',
                            'admin_role' => $current_admin_role,
                            'reason' => 'No students assigned'
                        ]
                    ]
                );
                
                $success = "Barangay deleted successfully!";
            }
        }
    }
}

// Fetch data
$universitiesQuery = "SELECT u.university_id, u.name, u.code, u.created_at, 
    COUNT(s.student_id) as student_count,
    COALESCE(p.scale_type, 'NUMERIC_1_TO_5') as scale_type,
    COALESCE(p.higher_is_better, FALSE) as higher_is_better,
    COALESCE(p.highest_value, '1.0') as highest_value,
    COALESCE(p.passing_value, '3.0') as passing_value,
    p.letter_order
FROM universities u 
LEFT JOIN students s ON u.university_id = s.university_id 
LEFT JOIN grading.university_passing_policy p ON u.code = p.university_key
GROUP BY u.university_id, u.name, u.code, u.created_at, p.scale_type, p.higher_is_better, p.highest_value, p.passing_value, p.letter_order 
ORDER BY u.name";
$universitiesResult = pg_query($connection, $universitiesQuery);
$universities = pg_fetch_all($universitiesResult) ?: [];

$barangaysQuery = "SELECT b.barangay_id, b.name, COUNT(s.student_id) as student_count FROM barangays b LEFT JOIN students s ON b.barangay_id = s.barangay_id GROUP BY b.barangay_id, b.name ORDER BY b.name";
$barangaysResult = pg_query($connection, $barangaysQuery);
$barangays = pg_fetch_all($barangaysResult) ?: [];

$yearLevelsQuery = "SELECT * FROM year_levels ORDER BY sort_order";
$yearLevelsResult = pg_query($connection, $yearLevelsQuery);
$yearLevels = pg_fetch_all($yearLevelsResult) ?: [];

// Page title for shared admin header/topbar components
$page_title = 'System Data Management';

// Handle success messages from redirects
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'university_added':
            $success = "University added successfully!";
            break;
        case 'university_updated':
            $success = "University updated successfully!";
            break;
        case 'university_deleted':
            $success = "University deleted successfully!";
            break;
        case 'barangay_added':
            $success = "Barangay added successfully!";
            break;
        case 'barangay_updated':
            $success = "Barangay updated successfully!";
            break;
        case 'barangay_deleted':
            $success = "Barangay deleted successfully!";
            break;
    }
}

if (isset($_GET['error'])) {
    $error = htmlspecialchars($_GET['error']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Data Management</title>
    <link rel="stylesheet" href="../../assets/css/bootstrap.min.css" />
    <link rel="stylesheet" href="../../assets/css/bootstrap-icons.css" />
    <link rel="stylesheet" href="../../assets/css/admin/homepage.css" />
    <link rel="stylesheet" href="../../assets/css/admin/sidebar.css" />
    <link rel="stylesheet" href="../../assets/css/admin/table_core.css" />
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet" />
    <style>
        .page-title { font-weight: 700; color: #111; }
        .page-subtitle { color: #6c757d; }
        .card-gradient .card-header {
            background: linear-gradient(135deg, #6f42c1 0%, #0d6efd 100%);
            color: #fff;
        }
        .tab-card .card { border: 1px solid rgba(0,0,0,.075); box-shadow: 0 2px 8px rgba(0,0,0,.05); }
        .nav-tabs .nav-link { font-weight: 600; }
        .section-actions .btn { white-space: nowrap; }
        @media (max-width: 576px) {
            .modal-mobile-compact .modal-dialog { max-width: 92vw; margin: .75rem auto; }
            .modal-mobile-compact .modal-content { max-height: 75vh; overflow: auto; }
        }
    </style>
</head>
<body>
<div id="wrapper">
    <?php include __DIR__ . '/../../includes/admin/admin_sidebar.php'; ?>
    <div class="sidebar-backdrop d-none" id="sidebar-backdrop"></div>
    <?php // Topbar / header (adds consistency with other admin pages)
          if (file_exists(__DIR__ . '/../../includes/admin/admin_topbar.php')) {
              include __DIR__ . '/../../includes/admin/admin_topbar.php';
          }
          if (file_exists(__DIR__ . '/../../includes/admin/admin_header.php')) {
              include __DIR__ . '/../../includes/admin/admin_header.php';
          }
    ?>
    <section class="home-section" id="mainContent">
        <!-- Removed duplicate burger menu nav (already provided by topbar/header includes) -->
        
        <div class="container-fluid py-4 px-4">
            <div class="mb-4">
                <h4 class="page-title mb-1">System Data Management</h4>
                <div class="page-subtitle">Manage reference lists for universities, barangays, and year levels.</div>
            </div>

            <?php if (isset($success)): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            <?php if (isset($error)): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <ul class="nav nav-tabs" id="systemDataTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="universities-tab" data-bs-toggle="tab" data-bs-target="#tab-universities" type="button" role="tab" aria-controls="tab-universities" aria-selected="true">
                        Universities <span class="badge bg-secondary ms-1"><?= count($universities) ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="barangays-tab" data-bs-toggle="tab" data-bs-target="#tab-barangays" type="button" role="tab" aria-controls="tab-barangays" aria-selected="false">
                        Barangays <span class="badge bg-secondary ms-1"><?= count($barangays) ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="yearlevels-tab" data-bs-toggle="tab" data-bs-target="#tab-yearlevels" type="button" role="tab" aria-controls="tab-yearlevels" aria-selected="false">
                        Year Levels <span class="badge bg-secondary ms-1"><?= count($yearLevels) ?></span>
                    </button>
                </li>
            </ul>

            <div class="tab-content pt-3 tab-card" id="systemDataTabsContent">
                <!-- Universities Management -->
                <div class="tab-pane fade show active" id="tab-universities" role="tabpanel" aria-labelledby="universities-tab">
                    <div class="card card-gradient mb-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Universities</h5>
                            <div class="d-flex align-items-center gap-2 section-actions">
                                <span class="badge bg-light text-dark"><?= count($universities) ?> total</span>
                                <button type="button" class="btn btn-light" data-bs-toggle="modal" data-bs-target="#addUniversityModal">Add University</button>
                            </div>
                        </div>
                        <div class="card-body p-4">
                            <div class="row mb-3 g-2">
                                <div class="col-md-6">
                                    <div class="input-group">
                                        <input type="text" class="form-control" id="universitySearch" placeholder="Search universities...">
                                        <span class="input-group-text">Search</span>
                                    </div>
                                </div>
                                <div class="col-md-6 text-md-end">
                                    <select id="universitiesPerPage" class="form-select form-select-sm" style="display:inline-block; width:auto;">
                                        <option value="10">10 per page</option>
                                        <option value="25" selected>25 per page</option>
                                        <option value="50">50 per page</option>
                                        <option value="100">100 per page</option>
                                    </select>
                                </div>
                            </div>

                            <div class="table-responsive">
                                <table class="table table-hover mb-0" id="universitiesTable">
                                    <thead class="table-dark">
                                        <tr>
                                            <th style="width: 50px;">#</th>
                                            <th>University Name</th>
                                            <th>Code</th>
                                            <th>Grading Scale</th>
                                            <th>Students</th>
                                            <th>Created</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $displayNumber = 1;
                                        foreach ($universities as $university): 
                                            $letterOrderStr = '';
                                            if (!empty($university['letter_order'])) {
                                                // Convert PostgreSQL array format {A,B,C} to comma-separated
                                                $letterOrderStr = trim($university['letter_order'], '{}');
                                            }
                                        ?>
                                            <tr>
                                                <td data-label="#" class="fw-semibold text-muted"><?= $displayNumber++ ?></td>
                                                <td data-label="University Name"><?= htmlspecialchars($university['name']) ?></td>
                                                <td data-label="Code"><span class="badge text-bg-info"><?= htmlspecialchars($university['code']) ?></span></td>
                                                <td data-label="Grading Scale">
                                                    <small class="text-muted"><?= htmlspecialchars($university['scale_type']) ?></small><br>
                                                    <small>Pass: <?= htmlspecialchars($university['passing_value']) ?></small>
                                                </td>
                                                <td data-label="Students"><?= $university['student_count'] ?> students</td>
                                                <td data-label="Created"><?= date('M d, Y', strtotime($university['created_at'])) ?></td>
                                                <td data-label="Actions">
                                                    <button type="button" class="btn btn-sm btn-outline-primary me-1" onclick="showEditUniversityModal(<?= $university['university_id'] ?>, '<?= htmlspecialchars($university['name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($university['code'], ENT_QUOTES) ?>', '<?= htmlspecialchars($university['scale_type'], ENT_QUOTES) ?>', <?= $university['higher_is_better'] === 't' ? 'true' : 'false' ?>, '<?= htmlspecialchars($university['highest_value'], ENT_QUOTES) ?>', '<?= htmlspecialchars($university['passing_value'], ENT_QUOTES) ?>', '<?= htmlspecialchars($letterOrderStr, ENT_QUOTES) ?>')">Edit</button>
                                                    <?php if ($university['student_count'] == 0): ?>
                                                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="showDeleteUniversityModal(<?= $university['university_id'] ?>, '<?= htmlspecialchars($university['name'], ENT_QUOTES) ?>')">Delete</button>
                                                    <?php else: ?>
                                                        <span class="text-muted small">(<?= $university['student_count'] ?> students)</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="d-flex justify-content-between align-items-center mt-3">
                                <div><span id="universitiesInfo" class="text-muted"></span></div>
                                <nav><ul class="pagination pagination-sm mb-0" id="universitiesPagination"></ul></nav>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Barangays Management -->
                <div class="tab-pane fade" id="tab-barangays" role="tabpanel" aria-labelledby="barangays-tab">
                    <div class="card card-gradient mb-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Barangays</h5>
                            <div class="d-flex align-items-center gap-2 section-actions">
                                <span class="badge bg-light text-dark"><?= count($barangays) ?> total</span>
                                <button type="button" class="btn btn-light" data-bs-toggle="modal" data-bs-target="#addBarangayModal">Add Barangay</button>
                            </div>
                        </div>
                        <div class="card-body p-4">
                            <div class="row mb-3 g-2">
                                <div class="col-md-6">
                                    <div class="input-group">
                                        <input type="text" class="form-control" id="barangaySearch" placeholder="Search barangays...">
                                        <span class="input-group-text">Search</span>
                                    </div>
                                </div>
                                <div class="col-md-6 text-md-end">
                                    <select id="barangaysPerPage" class="form-select form-select-sm" style="display:inline-block; width:auto;">
                                        <option value="10">10 per page</option>
                                        <option value="25" selected>25 per page</option>
                                        <option value="50">50 per page</option>
                                        <option value="100">100 per page</option>
                                    </select>
                                </div>
                            </div>

                            <div class="table-responsive">
                                <table class="table table-hover mb-0" id="barangaysTable">
                                    <thead class="table-dark">
                                        <tr>
                                            <th style="width: 50px;">#</th>
                                            <th>Barangay Name</th>
                                            <th>Students</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $displayNumber = 1;
                                        foreach ($barangays as $barangay): ?>
                                            <tr>
                                                <td data-label="#" class="fw-semibold text-muted"><?= $displayNumber++ ?></td>
                                                <td data-label="Barangay Name"><?= htmlspecialchars($barangay['name']) ?></td>
                                                <td data-label="Students"><?= $barangay['student_count'] ?> students</td>
                                                <td data-label="Actions">
                                                    <button type="button" class="btn btn-sm btn-outline-primary me-1" onclick="showEditBarangayModal(<?= $barangay['barangay_id'] ?>, '<?= htmlspecialchars($barangay['name'], ENT_QUOTES) ?>')">Edit</button>
                                                    <?php if ($barangay['student_count'] == 0): ?>
                                                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="showDeleteBarangayModal(<?= $barangay['barangay_id'] ?>, '<?= htmlspecialchars($barangay['name'], ENT_QUOTES) ?>')">Delete</button>
                                                    <?php else: ?>
                                                        <span class="text-muted small">(<?= $barangay['student_count'] ?> students)</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="d-flex justify-content-between align-items-center mt-3">
                                <div><span id="barangaysInfo" class="text-muted"></span></div>
                                <nav><ul class="pagination pagination-sm mb-0" id="barangaysPagination"></ul></nav>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Year Levels (Read-only) -->
                <div class="tab-pane fade" id="tab-yearlevels" role="tabpanel" aria-labelledby="yearlevels-tab">
                    <div class="card card-gradient mb-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Year Levels (System Defined)</h5>
                        </div>
                        <div class="card-body p-4">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Year Level</th>
                                            <th>Code</th>
                                            <th>Order</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($yearLevels as $yearLevel): ?>
                                            <tr>
                                                <td data-label="Year Level"><?= htmlspecialchars($yearLevel['name']) ?></td>
                                                <td data-label="Code"><span class="badge text-bg-secondary"><?= htmlspecialchars($yearLevel['code']) ?></span></td>
                                                <td data-label="Order"><?= $yearLevel['sort_order'] ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <small class="text-muted">Year levels are system-defined and cannot be modified to maintain data integrity.</small>
                        </div>
                    </div>
                </div>
            </div>
        </div><!-- /.container-fluid -->
    </section>
</div>

<!-- Add University Modal -->
<div class="modal fade modal-mobile-compact" id="addUniversityModal" tabindex="-1" aria-labelledby="addUniversityModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addUniversityModalLabel">Add New University</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="addUniversityForm">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="university_name" class="form-label">University Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="university_name" name="university_name" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="university_code" class="form-label">University Code <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="university_code" name="university_code" placeholder="e.g., UST" maxlength="10" required>
                                <small class="text-muted">Short code/abbreviation for the university</small>
                            </div>
                        </div>
                    </div>
                    
                    <hr class="my-3">
                    <h6 class="mb-3"><i class="fas fa-graduation-cap me-2"></i>Grading Policy (OCR Grade Checking)</h6>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="scale_type" class="form-label">Grading Scale Type <span class="text-danger">*</span></label>
                                <select class="form-select" id="scale_type" name="scale_type" required onchange="toggleLetterOrderField(this, 'add')">
                                    <option value="NUMERIC_1_TO_5" selected>Numeric 1 to 5 (1 = highest)</option>
                                    <option value="NUMERIC_0_TO_4">Numeric 0 to 4 (4 = highest)</option>
                                    <option value="PERCENT">Percentage (0-100%)</option>
                                    <option value="LETTER">Letter Grades (A, B, C, etc.)</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label d-block">Grade Direction</label>
                                <div class="form-check form-switch mt-2">
                                    <input class="form-check-input" type="checkbox" id="higher_is_better" name="higher_is_better" value="1">
                                    <label class="form-check-label" for="higher_is_better">Higher grade is better</label>
                                </div>
                                <small class="text-muted">Check if higher numbers mean better grades (e.g., percentage)</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="highest_value" class="form-label">Highest/Best Grade Value <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="highest_value" name="highest_value" value="1.0" required>
                                <small class="text-muted">e.g., 1.0 for 1-5 scale, 100 for percentage</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="passing_value" class="form-label">Passing Grade Value <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="passing_value" name="passing_value" value="3.0" required>
                                <small class="text-muted">e.g., 3.0 for 1-5 scale, 75 for percentage</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row" id="add_letter_order_row" style="display: none;">
                        <div class="col-12">
                            <div class="mb-3">
                                <label for="letter_order" class="form-label">Letter Grade Order (Best to Worst)</label>
                                <input type="text" class="form-control" id="letter_order" name="letter_order" placeholder="e.g., A+, A, A-, B+, B, B-, C+, C, C-, D, F">
                                <small class="text-muted">Comma-separated list from best to worst grade</small>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_university" class="btn btn-primary">Add University</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit University Modal -->
<div class="modal fade modal-mobile-compact" id="editUniversityModal" tabindex="-1" aria-labelledby="editUniversityModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editUniversityModalLabel">Edit University</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="editUniversityForm">
                <div class="modal-body">
                    <input type="hidden" id="edit_university_id" name="university_id">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="edit_university_name" class="form-label">University Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="edit_university_name" name="university_name" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="edit_university_code" class="form-label">University Code <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="edit_university_code" name="university_code" placeholder="e.g., UST" maxlength="10" required>
                                <small class="text-muted">Short code/abbreviation for the university</small>
                            </div>
                        </div>
                    </div>
                    
                    <hr class="my-3">
                    <h6 class="mb-3"><i class="fas fa-graduation-cap me-2"></i>Grading Policy (OCR Grade Checking)</h6>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="edit_scale_type" class="form-label">Grading Scale Type <span class="text-danger">*</span></label>
                                <select class="form-select" id="edit_scale_type" name="scale_type" required onchange="toggleLetterOrderField(this, 'edit')">
                                    <option value="NUMERIC_1_TO_5">Numeric 1 to 5 (1 = highest)</option>
                                    <option value="NUMERIC_0_TO_4">Numeric 0 to 4 (4 = highest)</option>
                                    <option value="PERCENT">Percentage (0-100%)</option>
                                    <option value="LETTER">Letter Grades (A, B, C, etc.)</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label d-block">Grade Direction</label>
                                <div class="form-check form-switch mt-2">
                                    <input class="form-check-input" type="checkbox" id="edit_higher_is_better" name="higher_is_better" value="1">
                                    <label class="form-check-label" for="edit_higher_is_better">Higher grade is better</label>
                                </div>
                                <small class="text-muted">Check if higher numbers mean better grades</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="edit_highest_value" class="form-label">Highest/Best Grade Value <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="edit_highest_value" name="highest_value" required>
                                <small class="text-muted">e.g., 1.0 for 1-5 scale, 100 for percentage</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="edit_passing_value" class="form-label">Passing Grade Value <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="edit_passing_value" name="passing_value" required>
                                <small class="text-muted">e.g., 3.0 for 1-5 scale, 75 for percentage</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row" id="edit_letter_order_row" style="display: none;">
                        <div class="col-12">
                            <div class="mb-3">
                                <label for="edit_letter_order" class="form-label">Letter Grade Order (Best to Worst)</label>
                                <input type="text" class="form-control" id="edit_letter_order" name="letter_order" placeholder="e.g., A+, A, A-, B+, B, B-, C+, C, C-, D, F">
                                <small class="text-muted">Comma-separated list from best to worst grade</small>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="edit_university" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Barangay Modal -->
<div class="modal fade modal-mobile-compact" id="addBarangayModal" tabindex="-1" aria-labelledby="addBarangayModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addBarangayModalLabel">Add New Barangay</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="addBarangayForm">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="barangay_name" class="form-label">Barangay Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="barangay_name" name="barangay_name" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_barangay" class="btn btn-success">Add Barangay</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Barangay Modal -->
<div class="modal fade modal-mobile-compact" id="editBarangayModal" tabindex="-1" aria-labelledby="editBarangayModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editBarangayModalLabel">Edit Barangay</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="editBarangayForm">
                <div class="modal-body">
                    <input type="hidden" id="edit_barangay_id" name="barangay_id">
                    <div class="mb-3">
                        <label for="edit_barangay_name" class="form-label">Barangay Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="edit_barangay_name" name="barangay_name" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="edit_barangay" class="btn btn-success">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete University Modal -->
<div class="modal fade modal-mobile-compact" id="deleteUniversityModal" tabindex="-1" aria-labelledby="deleteUniversityModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteUniversityModalLabel">Confirm Deletion</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="deleteUniversityForm">
                <div class="modal-body">
                    <div class="alert alert-warning"><strong>Warning:</strong> This action cannot be undone.</div>
                    <p>Are you sure you want to delete the university <strong id="deleteUniversityName"></strong>?</p>
                    <input type="hidden" id="deleteUniversityId" name="university_id">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="delete_university" class="btn btn-danger">Delete University</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Barangay Modal -->
<div class="modal fade modal-mobile-compact" id="deleteBarangayModal" tabindex="-1" aria-labelledby="deleteBarangayModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteBarangayModalLabel">Confirm Deletion</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="deleteBarangayForm">
                <div class="modal-body">
                    <div class="alert alert-warning"><strong>Warning:</strong> This action cannot be undone.</div>
                    <p>Are you sure you want to delete the barangay <strong id="deleteBarangayName"></strong>?</p>
                    <input type="hidden" id="deleteBarangayId" name="barangay_id">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="delete_barangay" class="btn btn-danger">Delete Barangay</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../../assets/js/admin/sidebar.js"></script>

<script>
// Debug form submission
document.addEventListener('DOMContentLoaded', function() {
    const addUniversityForm = document.getElementById('addUniversityForm');
    
    addUniversityForm.addEventListener('submit', function(e) {
        console.log('Form submitted!');
        console.log('Form data:', {
            name: document.getElementById('university_name').value,
            code: document.getElementById('university_code').value
        });
    });
});

// Pagination and Search functionality
class TableManager {
    constructor(tableId, searchId, perPageId, paginationId, infoId) {
        this.table = document.getElementById(tableId);
        this.searchInput = document.getElementById(searchId);
        this.perPageSelect = document.getElementById(perPageId);
        this.pagination = document.getElementById(paginationId);
        this.info = document.getElementById(infoId);
        
        this.rows = Array.from(this.table.querySelectorAll('tbody tr'));
        this.filteredRows = [...this.rows];
        this.currentPage = 1;
        this.perPage = parseInt(this.perPageSelect.value);
        
        this.init();
    }
    
    init() {
        this.searchInput.addEventListener('input', () => this.handleSearch());
        this.perPageSelect.addEventListener('change', () => this.handlePerPageChange());
        this.update();
    }
    
    handleSearch() {
        const query = this.searchInput.value.toLowerCase();
        this.filteredRows = this.rows.filter(row => {
            return row.textContent.toLowerCase().includes(query);
        });
        this.currentPage = 1;
        this.update();
    }
    
    handlePerPageChange() {
        this.perPage = parseInt(this.perPageSelect.value);
        this.currentPage = 1;
        this.update();
    }
    
    update() {
        this.showRows();
        this.updatePagination();
        this.updateInfo();
    }
    
    showRows() {
        // Hide all rows first
        this.rows.forEach(row => row.style.display = 'none');
        
        // Calculate start and end indices
        const start = (this.currentPage - 1) * this.perPage;
        const end = start + this.perPage;
        
        // Show filtered rows for current page
        this.filteredRows.slice(start, end).forEach(row => {
            row.style.display = '';
        });
    }
    
    updatePagination() {
        const totalPages = Math.ceil(this.filteredRows.length / this.perPage);
        this.pagination.innerHTML = '';
        
        if (totalPages <= 1) return;
        
        // Previous button
        const prevLi = document.createElement('li');
        prevLi.className = 'page-item' + (this.currentPage === 1 ? ' disabled' : '');
        prevLi.innerHTML = '<a class="page-link" href="#" data-page="prev">Previous</a>';
        this.pagination.appendChild(prevLi);
        
        // Page numbers
        const startPage = Math.max(1, this.currentPage - 2);
        const endPage = Math.min(totalPages, this.currentPage + 2);
        
        if (startPage > 1) {
            const firstLi = document.createElement('li');
            firstLi.className = 'page-item';
            firstLi.innerHTML = '<a class="page-link" href="#" data-page="1">1</a>';
            this.pagination.appendChild(firstLi);
            
            if (startPage > 2) {
                const ellipsisLi = document.createElement('li');
                ellipsisLi.className = 'page-item disabled';
                ellipsisLi.innerHTML = '<span class="page-link">...</span>';
                this.pagination.appendChild(ellipsisLi);
            }
        }
        
        for (let i = startPage; i <= endPage; i++) {
            const pageLi = document.createElement('li');
            pageLi.className = 'page-item' + (i === this.currentPage ? ' active' : '');
            pageLi.innerHTML = '<a class="page-link" href="#" data-page="' + i + '">' + i + '</a>';
            this.pagination.appendChild(pageLi);
        }
        
        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                const ellipsisLi = document.createElement('li');
                ellipsisLi.className = 'page-item disabled';
                ellipsisLi.innerHTML = '<span class="page-link">...</span>';
                this.pagination.appendChild(ellipsisLi);
            }
            
            const lastLi = document.createElement('li');
            lastLi.className = 'page-item';
            lastLi.innerHTML = '<a class="page-link" href="#" data-page="' + totalPages + '">' + totalPages + '</a>';
            this.pagination.appendChild(lastLi);
        }
        
        // Next button
        const nextLi = document.createElement('li');
        nextLi.className = 'page-item' + (this.currentPage === totalPages ? ' disabled' : '');
        nextLi.innerHTML = '<a class="page-link" href="#" data-page="next">Next</a>';
        this.pagination.appendChild(nextLi);
        
        // Add click handlers
        this.pagination.addEventListener('click', (e) => {
            e.preventDefault();
            if (e.target.classList.contains('page-link')) {
                const page = e.target.getAttribute('data-page');
                this.goToPage(page);
            }
        });
    }
    
    goToPage(page) {
        const totalPages = Math.ceil(this.filteredRows.length / this.perPage);
        
        if (page === 'prev' && this.currentPage > 1) {
            this.currentPage--;
        } else if (page === 'next' && this.currentPage < totalPages) {
            this.currentPage++;
        } else if (!isNaN(page)) {
            this.currentPage = parseInt(page);
        }
        
        this.update();
    }
    
    updateInfo() {
        const start = Math.min((this.currentPage - 1) * this.perPage + 1, this.filteredRows.length);
        const end = Math.min(this.currentPage * this.perPage, this.filteredRows.length);
        const total = this.filteredRows.length;
        
        if (total === 0) {
            this.info.textContent = 'No results found';
        } else {
            this.info.textContent = 'Showing ' + start + '-' + end + ' of ' + total + ' entries';
        }
    }
}

// Toggle letter order field visibility based on scale type
function toggleLetterOrderField(selectElement, prefix) {
    const rowId = prefix === 'edit' ? 'edit_letter_order_row' : 'add_letter_order_row';
    const letterOrderRow = document.getElementById(rowId);
    if (selectElement.value === 'LETTER') {
        letterOrderRow.style.display = 'flex';
    } else {
        letterOrderRow.style.display = 'none';
    }
}

// Update defaults based on scale type selection
function updateScaleDefaults(selectElement, prefix) {
    const scaleType = selectElement.value;
    const highestInput = document.getElementById(prefix + '_highest_value') || document.getElementById('highest_value');
    const passingInput = document.getElementById(prefix + '_passing_value') || document.getElementById('passing_value');
    const higherIsBetter = document.getElementById(prefix + '_higher_is_better') || document.getElementById('higher_is_better');
    
    switch(scaleType) {
        case 'NUMERIC_1_TO_5':
            highestInput.value = '1.0';
            passingInput.value = '3.0';
            higherIsBetter.checked = false;
            break;
        case 'NUMERIC_0_TO_4':
            highestInput.value = '4.0';
            passingInput.value = '2.0';
            higherIsBetter.checked = true;
            break;
        case 'PERCENT':
            highestInput.value = '100';
            passingInput.value = '75';
            higherIsBetter.checked = true;
            break;
        case 'LETTER':
            highestInput.value = 'A';
            passingInput.value = 'C';
            higherIsBetter.checked = false;
            break;
    }
}

// Initialize table managers
document.addEventListener('DOMContentLoaded', function() {
    new TableManager('universitiesTable', 'universitySearch', 'universitiesPerPage', 'universitiesPagination', 'universitiesInfo');
    new TableManager('barangaysTable', 'barangaySearch', 'barangaysPerPage', 'barangaysPagination', 'barangaysInfo');
});

// Functions for delete modals
function showDeleteUniversityModal(universityId, universityName) {
    document.getElementById('deleteUniversityId').value = universityId;
    document.getElementById('deleteUniversityName').textContent = universityName;
    new bootstrap.Modal(document.getElementById('deleteUniversityModal')).show();
}

function showDeleteBarangayModal(barangayId, barangayName) {
    document.getElementById('deleteBarangayId').value = barangayId;
    document.getElementById('deleteBarangayName').textContent = barangayName;
    new bootstrap.Modal(document.getElementById('deleteBarangayModal')).show();
}

// Functions for edit modals
function showEditUniversityModal(universityId, universityName, universityCode, scaleType, higherIsBetter, highestValue, passingValue, letterOrder) {
    document.getElementById('edit_university_id').value = universityId;
    document.getElementById('edit_university_name').value = universityName;
    document.getElementById('edit_university_code').value = universityCode;
    document.getElementById('edit_scale_type').value = scaleType || 'NUMERIC_1_TO_5';
    document.getElementById('edit_higher_is_better').checked = higherIsBetter;
    document.getElementById('edit_highest_value').value = highestValue || '1.0';
    document.getElementById('edit_passing_value').value = passingValue || '3.0';
    document.getElementById('edit_letter_order').value = letterOrder || '';
    
    // Toggle letter order visibility
    const letterOrderRow = document.getElementById('edit_letter_order_row');
    letterOrderRow.style.display = (scaleType === 'LETTER') ? 'flex' : 'none';
    
    new bootstrap.Modal(document.getElementById('editUniversityModal')).show();
}

function showEditBarangayModal(barangayId, barangayName) {
    document.getElementById('edit_barangay_id').value = barangayId;
    document.getElementById('edit_barangay_name').value = barangayName;
    new bootstrap.Modal(document.getElementById('editBarangayModal')).show();
}

// Form validation
document.getElementById('addUniversityForm').addEventListener('submit', function(e) {
    const name = document.getElementById('university_name').value.trim();
    const code = document.getElementById('university_code').value.trim();
    
    if (!name || !code) {
        e.preventDefault();
        alert('Please fill in all required fields.');
        return false;
    }
    
    if (code.length > 10) {
        e.preventDefault();
        alert('University code must be 10 characters or less.');
        return false;
    }
});

document.getElementById('editUniversityForm').addEventListener('submit', function(e) {
    const name = document.getElementById('edit_university_name').value.trim();
    const code = document.getElementById('edit_university_code').value.trim();
    
    if (!name || !code) {
        e.preventDefault();
        alert('Please fill in all required fields.');
        return false;
    }
    
    if (code.length > 10) {
        e.preventDefault();
        alert('University code must be 10 characters or less.');
        return false;
    }
});

document.getElementById('addBarangayForm').addEventListener('submit', function(e) {
    const name = document.getElementById('barangay_name').value.trim();
    
    if (!name) {
        e.preventDefault();
        alert('Please enter a barangay name.');
        return false;
    }
});

document.getElementById('editBarangayForm').addEventListener('submit', function(e) {
    const name = document.getElementById('edit_barangay_name').value.trim();
    
    if (!name) {
        e.preventDefault();
        alert('Please enter a barangay name.');
        return false;
    }
});

// Clear form when modal is closed
document.getElementById('addUniversityModal').addEventListener('hidden.bs.modal', function () {
    document.getElementById('addUniversityForm').reset();
});

document.getElementById('editUniversityModal').addEventListener('hidden.bs.modal', function () {
    document.getElementById('editUniversityForm').reset();
});

document.getElementById('addBarangayModal').addEventListener('hidden.bs.modal', function () {
    document.getElementById('addBarangayForm').reset();
});

document.getElementById('editBarangayModal').addEventListener('hidden.bs.modal', function () {
    document.getElementById('editBarangayForm').reset();
});
</script>
</body>
</html>

<?php pg_close($connection); ?>