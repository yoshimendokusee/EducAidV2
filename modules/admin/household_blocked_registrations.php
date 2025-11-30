<?php
/**
 * Household Blocked Registrations Log
 * View all household duplicate registration attempts that were blocked
 * Administrators can review, override, and manage blocked attempts
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['admin_username'])) {
    header("Location: ../../unified_login.php");
    exit;
}

include __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/CSRFProtection.php';
require_once __DIR__ . '/../../services/AuditLogger.php';

// Initialize audit logger
$auditLogger = new AuditLogger($connection);

// PHPMailer for sending email notifications
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require __DIR__ . '/../../phpmailer/vendor/autoload.php';

// Helper function for JSON response
function json_response($data) {
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// API endpoint for badge count (for sidebar)
if (isset($_GET['api']) && $_GET['api'] === 'badge_count') {
    $countRes = @pg_query($connection, "SELECT COUNT(*) FROM household_block_attempts WHERE admin_override = FALSE");
    $count = 0;
    if ($countRes) {
        $count = (int) pg_fetch_result($countRes, 0, 0);
        pg_free_result($countRes);
    }
    json_response(['count' => $count]);
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    $token = $_POST['csrf_token'] ?? '';
    if (!CSRFProtection::validateToken('household_blocks', $token)) {
        json_response(['success' => false, 'message' => 'Security validation failed']);
    }
    
    // Bulk delete attempts
    if (isset($_POST['action']) && $_POST['action'] === 'bulk_delete') {
        $attemptIds = $_POST['attempt_ids'] ?? [];
        
        if (empty($attemptIds) || !is_array($attemptIds)) {
            json_response(['success' => false, 'message' => 'No records selected']);
        }
        
        // Sanitize IDs
        $attemptIds = array_map('intval', $attemptIds);
        $attemptIds = array_filter($attemptIds, function($id) { return $id > 0; });
        
        if (empty($attemptIds)) {
            json_response(['success' => false, 'message' => 'Invalid selection']);
        }
        
        // Delete the selected attempts
        $placeholders = implode(',', array_map(function($i) { return '$' . ($i + 1); }, array_keys($attemptIds)));
        $deleteQuery = "DELETE FROM household_block_attempts WHERE attempt_id IN ($placeholders)";
        
        $result = pg_query_params($connection, $deleteQuery, array_values($attemptIds));
        
        if ($result) {
            $deletedCount = pg_affected_rows($result);
            json_response([
                'success' => true,
                'message' => "Successfully deleted $deletedCount record(s)"
            ]);
        } else {
            json_response(['success' => false, 'message' => 'Database error: ' . pg_last_error($connection)]);
        }
    }
    
    // Override and allow registration
    if (isset($_POST['action']) && $_POST['action'] === 'override') {
        $attemptId = intval($_POST['attempt_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        
        if ($attemptId === 0 || empty($reason)) {
            json_response(['success' => false, 'message' => 'Invalid request']);
        }
        
        // Generate bypass token
        $bypassToken = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));
        
        // Update the block attempt
        $updateQuery = "UPDATE household_block_attempts 
                        SET admin_override = TRUE,
                            override_reason = $1,
                            override_by_admin_id = $2,
                            override_at = NOW(),
                            bypass_token = $3,
                            bypass_token_expires_at = $4
                        WHERE attempt_id = $5";
        
        $adminId = $_SESSION['admin_id'] ?? null;
        $result = pg_query_params($connection, $updateQuery, [
            $reason,
            $adminId,
            $bypassToken,
            $expiresAt,
            $attemptId
        ]);
        
        if ($result) {
            // Get attempt details for email
            $detailsQuery = "SELECT attempted_email, attempted_first_name, attempted_last_name FROM household_block_attempts WHERE attempt_id = $1";
            $detailsResult = pg_query_params($connection, $detailsQuery, [$attemptId]);
            $details = pg_fetch_assoc($detailsResult);
            
            // Generate bypass URL - supports both localhost and production
            $appUrl = getenv('APP_URL');
            if (empty($appUrl)) {
                // Fallback: Auto-detect protocol and host for localhost
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'];
                $appUrl = $protocol . '://' . $host;
            }
            $bypassUrl = rtrim($appUrl, '/') . '/modules/student/student_register.php?bypass_token=' . $bypassToken;
            
            $studentEmail = $details['attempted_email'] ?? '';
            $studentFirstName = $details['attempted_first_name'] ?? 'Student';
            $studentFullName = trim(($details['attempted_first_name'] ?? '') . ' ' . ($details['attempted_last_name'] ?? ''));
            $emailSent = false;
            $emailError = null;
            
            // Send email notification if email address is available
            if (!empty($studentEmail) && filter_var($studentEmail, FILTER_VALIDATE_EMAIL)) {
                try {
                    $mail = new PHPMailer(true);
                    
                    // SMTP configuration
                    $mail->isSMTP();
                    $mail->Host       = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
                    $mail->SMTPAuth   = true;
                    $mail->Username   = getenv('SMTP_USERNAME') ?: 'example@email.test';
                    $mail->Password   = getenv('SMTP_PASSWORD') ?: '';
                    $encryption       = getenv('SMTP_ENCRYPTION') ?: 'tls';
                    
                    if (strtolower($encryption) === 'ssl') {
                        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                    } else {
                        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    }
                    $mail->Port = (int)(getenv('SMTP_PORT') ?: 587);
                    
                    // From address
                    $fromEmail = getenv('SMTP_FROM_EMAIL') ?: ($mail->Username ?: 'no-reply@educaid.local');
                    $fromName  = getenv('SMTP_FROM_NAME')  ?: 'EducAid Registration System';
                    $mail->setFrom($fromEmail, $fromName);
                    
                    // To address
                    $mail->addAddress($studentEmail, $studentFullName);
                    
                    // Email content
                    $mail->isHTML(true);
                    $mail->Subject = 'EducAid Registration - Override Approved';
                    
                    $expiresAtFormatted = date('F j, Y \a\t g:i A', strtotime($expiresAt));
                    
                    $mail->Body = "
                    <!DOCTYPE html>
                    <html>
                    <head>
                        <style>
                            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                            .header { background: linear-gradient(135deg, #2e7d32 0%, #1b5e20 100%); color: white; padding: 20px; border-radius: 8px 8px 0 0; text-align: center; }
                            .content { background: #f8f9fa; padding: 30px; border-radius: 0 0 8px 8px; }
                            .button { display: inline-block; background: linear-gradient(135deg, #2e7d32 0%, #1b5e20 100%); color: white; padding: 12px 30px; text-decoration: none; border-radius: 6px; margin: 20px 0; font-weight: bold; }
                            .warning { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; border-radius: 4px; }
                            .info-box { background: white; border: 2px solid #e0e0e0; padding: 15px; border-radius: 6px; margin: 15px 0; }
                            .footer { text-align: center; color: #666; font-size: 12px; margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; }
                        </style>
                    </head>
                    <body>
                        <div class='container'>
                            <div class='header'>
                                <h2 style='margin: 0;'>🎓 EducAid Registration</h2>
                                <p style='margin: 5px 0 0 0;'>Override Approved</p>
                            </div>
                            <div class='content'>
                                <p>Dear <strong>{$studentFirstName}</strong>,</p>
                                
                                <p>Great news! Your registration request has been <strong>approved by an administrator</strong>.</p>
                                
                                <div class='info-box'>
                                    <p><strong>📋 What This Means:</strong></p>
                                    <p>Your registration was initially blocked by our household duplicate prevention system. However, after reviewing your case, an administrator has approved your registration to proceed.</p>
                                </div>
                                
                                <p><strong>To complete your registration, please click the button below:</strong></p>
                                
                                <div style='text-align: center;'>
                                    <a href='{$bypassUrl}' class='button'>Continue Registration</a>
                                </div>
                                
                                <div class='warning'>
                                    <strong>⚠️ Important:</strong>
                                    <ul style='margin: 10px 0 0 0; padding-left: 20px;'>
                                        <li>This link is <strong>one-time use only</strong> and will expire on <strong>{$expiresAtFormatted}</strong></li>
                                        <li>If the link expires, you'll need to contact the administrator again</li>
                                        <li>Please complete your registration as soon as possible</li>
                                    </ul>
                                </div>
                                
                                <div class='info-box'>
                                    <p><strong>Override Reason:</strong></p>
                                    <p style='font-style: italic; color: #555;'>\"{$reason}\"</p>
                                </div>
                                
                                <p>If you did not request this registration or if you have any questions, please contact the EducAid administration office.</p>
                            </div>
                            <div class='footer'>
                                <p>This is an automated message from the EducAid Registration System.</p>
                                <p>Please do not reply to this email.</p>
                            </div>
                        </div>
                    </body>
                    </html>
                    ";
                    
                    $mail->AltBody = "Dear {$studentFirstName},\n\n"
                        . "Great news! Your registration request has been approved by an administrator.\n\n"
                        . "Your registration was initially blocked by our household duplicate prevention system. However, after reviewing your case, an administrator has approved your registration to proceed.\n\n"
                        . "To complete your registration, please visit this link:\n{$bypassUrl}\n\n"
                        . "IMPORTANT:\n"
                        . "- This link is one-time use only and will expire on {$expiresAtFormatted}\n"
                        . "- If the link expires, you'll need to contact the administrator again\n"
                        . "- Please complete your registration as soon as possible\n\n"
                        . "Override Reason: \"{$reason}\"\n\n"
                        . "If you did not request this registration or if you have any questions, please contact the EducAid administration office.\n\n"
                        . "This is an automated message from the EducAid Registration System.\n"
                        . "Please do not reply to this email.";
                    
                    $mail->send();
                    $emailSent = true;
                    
                    error_log("Override email sent successfully to: {$studentEmail} for attempt ID: {$attemptId}");
                    
                } catch (Exception $e) {
                    $emailError = $e->getMessage();
                    error_log("Failed to send override email to {$studentEmail}: " . $e->getMessage());
                    error_log("PHPMailer Error Info: {$mail->ErrorInfo}");
                }
            }
            
            // Log audit trail: Admin approved household override
            $auditLogger->logEvent(
                'household_override_approved',
                'household_management',
                "Admin approved household duplicate override for {$details['attempted_first_name']} {$details['attempted_last_name']}",
                [
                    'user_id' => $adminId,
                    'user_type' => 'admin',
                    'username' => $_SESSION['admin_username'] ?? 'Unknown Admin',
                    'status' => 'success',
                    'affected_table' => 'household_block_attempts',
                    'affected_record_id' => $attemptId,
                    'metadata' => [
                        'attempt_id' => $attemptId,
                        'student_name' => $details['attempted_first_name'] . ' ' . $details['attempted_last_name'],
                        'student_email' => $studentEmail,
                        'override_reason' => $reason,
                        'bypass_token_expires_at' => $expiresAt,
                        'email_sent' => $emailSent,
                        'email_address' => $studentEmail
                    ]
                ]
            );
            
            json_response([
                'success' => true,
                'message' => 'Override approved successfully',
                'bypass_url' => $bypassUrl,
                'expires_at' => $expiresAt,
                'email' => $studentEmail,
                'email_sent' => $emailSent,
                'email_error' => $emailError
            ]);
        } else {
            json_response(['success' => false, 'message' => 'Database error']);
        }
    }
}

// Fetch blocked attempts with filters
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$whereConditions = ['TRUE'];
$params = [];
$paramIndex = 1;

// Filters
$barangayFilter = trim($_GET['barangay'] ?? '');
$overrideFilter = trim($_GET['override_status'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');

if (!empty($barangayFilter)) {
    $whereConditions[] = "barangay_entered ILIKE $" . $paramIndex;
    $params[] = "%$barangayFilter%";
    $paramIndex++;
}

if ($overrideFilter === 'overridden') {
    $whereConditions[] = "admin_override = TRUE";
} elseif ($overrideFilter === 'blocked') {
    $whereConditions[] = "admin_override = FALSE";
}

if (!empty($dateFrom)) {
    $whereConditions[] = "blocked_at >= $" . $paramIndex;
    $params[] = $dateFrom . ' 00:00:00';
    $paramIndex++;
}

if (!empty($dateTo)) {
    $whereConditions[] = "blocked_at <= $" . $paramIndex;
    $params[] = $dateTo . ' 23:59:59';
    $paramIndex++;
}

$whereClause = implode(' AND ', $whereConditions);

// Get total count
$countQuery = "SELECT COUNT(*) as total FROM household_block_attempts WHERE $whereClause";
$countResult = pg_query_params($connection, $countQuery, $params);
$totalRecords = pg_fetch_result($countResult, 0, 'total');
$totalPages = ceil($totalRecords / $perPage);

// Get records
$params[] = $perPage;
$params[] = $offset;
$paramCount = count($params);

$query = "SELECT 
    hba.attempt_id,
    hba.attempted_first_name,
    hba.attempted_last_name,
    hba.attempted_email,
    hba.attempted_mobile,
    hba.mothers_maiden_name_entered,
    hba.barangay_entered,
    hba.blocked_at,
    hba.match_type,
    hba.similarity_score,
    hba.admin_override,
    hba.override_reason,
    hba.override_at,
    hba.bypass_token_used,
    s.first_name as existing_first_name,
    s.last_name as existing_last_name,
    s.student_id as existing_student_id,
    CONCAT(a.first_name, ' ', a.last_name) as override_by_name
FROM household_block_attempts hba
LEFT JOIN students s ON hba.blocked_by_student_id = s.student_id
LEFT JOIN admins a ON hba.override_by_admin_id = a.admin_id
WHERE $whereClause
ORDER BY hba.blocked_at DESC
LIMIT $" . ($paramCount - 1) . " OFFSET $" . $paramCount;

$result = pg_query_params($connection, $query, $params);
$records = [];
while ($row = pg_fetch_assoc($result)) {
    $records[] = $row;
}

// Get statistics
$statsQuery = "SELECT 
    COUNT(*) as total_blocks,
    COUNT(CASE WHEN admin_override = TRUE THEN 1 END) as overridden,
    COUNT(CASE WHEN admin_override = FALSE THEN 1 END) as active_blocks,
    COUNT(CASE WHEN blocked_at >= CURRENT_DATE - INTERVAL '7 days' THEN 1 END) as blocks_last_7d,
    COUNT(CASE WHEN blocked_at >= CURRENT_DATE - INTERVAL '30 days' THEN 1 END) as blocks_last_30d
FROM household_block_attempts";
$statsResult = pg_query($connection, $statsQuery);
$stats = pg_fetch_assoc($statsResult);

// Get barangays for filter
$barangaysQuery = "SELECT DISTINCT barangay_entered FROM household_block_attempts ORDER BY barangay_entered";
$barangaysResult = pg_query($connection, $barangaysQuery);
$barangays = [];
while ($row = pg_fetch_assoc($barangaysResult)) {
    $barangays[] = $row['barangay_entered'];
}
?>
<?php $page_title='Household Blocked Registrations'; $extra_css=['../../assets/css/admin/manage_applicants.css', '../../assets/css/admin/table_core.css']; include __DIR__ . '/../../includes/admin/admin_head.php'; ?>

<!-- SweetAlert2 Library -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11.14.5/dist/sweetalert2.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.14.5/dist/sweetalert2.all.min.js"></script>

<style>
    .bulk-actions-bar {
        background: #ffffff;
        border: 1px solid #dee2e6;
        padding: 12px 20px;
        border-radius: 10px;
        margin-bottom: 16px;
        position: sticky;
        top: calc(var(--admin-topbar-h, 52px) + var(--admin-header-h, 56px) + 8px);
        z-index: 1020;
        box-shadow: 0 6px 14px rgba(0,0,0,.1);
        backdrop-filter: saturate(1.2) blur(2px);
    }
    @media (max-width: 767.98px) {
        .bulk-actions-bar {
            padding: 10px 12px;
            border-radius: 12px;
        }
    }
</style>

<!-- Page Content Starts Here -->
<?php include __DIR__ . '/../../includes/admin/admin_topbar.php'; ?>

<div id="wrapper" class="admin-wrapper">
    <?php include __DIR__ . '/../../includes/admin/admin_sidebar.php'; ?>
    <?php include __DIR__ . '/../../includes/admin/admin_header.php'; ?>
    
    <section class="home-section" id="mainContent">
        <div class="container-fluid py-4">
            <!-- Page Header -->
            <div class="mb-4">
                <h1 class="fw-bold mb-2">
                    Household Blocked Registrations
                </h1>
                <p class="text-muted mb-0">View and manage registration attempts blocked by household duplicate prevention</p>
            </div>

                <!-- Statistics Cards -->
                <div class="row g-3 g-md-4 mb-4">
                    <div class="col-6 col-md-3">
                        <div class="card" style="border-left: 4px solid #dc3545; background: linear-gradient(135deg, #fff 0%, #ffe5e8 100%);">
                            <div class="card-body">
                                <p class="text-muted mb-1 fw-semibold small">Total Blocks</p>
                                <h3 class="fw-bold mb-0" style="color: #dc3545;"><?= $stats['total_blocks'] ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="card" style="border-left: 4px solid #ffc107; background: linear-gradient(135deg, #fff 0%, #fff8e1 100%);">
                            <div class="card-body">
                                <p class="text-muted mb-1 fw-semibold small">Active Blocks</p>
                                <h3 class="fw-bold mb-0" style="color: #f57c00;"><?= $stats['active_blocks'] ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="card" style="border-left: 4px solid #28a745; background: linear-gradient(135deg, #fff 0%, #e8f5e9 100%);">
                            <div class="card-body">
                                <p class="text-muted mb-1 fw-semibold small">Overridden</p>
                                <h3 class="fw-bold mb-0" style="color: #28a745;"><?= $stats['overridden'] ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="card" style="border-left: 4px solid #17a2b8; background: linear-gradient(135deg, #fff 0%, #e0f7fa 100%);">
                            <div class="card-body">
                                <p class="text-muted mb-1 fw-semibold small">Last 30 Days</p>
                                <h3 class="fw-bold mb-0" style="color: #17a2b8;"><?= $stats['blocks_last_30d'] ?></h3>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="filter-section mb-4">
                    <form method="GET" class="filter-grid filter-grid-6">
                        <div class="filter-group">
                            <label class="form-label">Barangay</label>
                                <select name="barangay" class="form-select">
                                    <option value="">All Barangays</option>
                                    <?php foreach ($barangays as $brgy): ?>
                                        <option value="<?= htmlspecialchars($brgy) ?>" <?= $barangayFilter === $brgy ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($brgy) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label class="form-label">Status</label>
                                <select name="override_status" class="form-select">
                                    <option value="">All</option>
                                    <option value="blocked" <?= $overrideFilter === 'blocked' ? 'selected' : '' ?>>Blocked</option>
                                    <option value="overridden" <?= $overrideFilter === 'overridden' ? 'selected' : '' ?>>Overridden</option>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label class="form-label">Date From</label>
                                <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($dateFrom) ?>">
                        </div>
                        <div class="filter-group">
                            <label class="form-label">Date To</label>
                            <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($dateTo) ?>">
                        </div>
                        <div class="filter-buttons">
                            <button type="submit" class="btn btn-primary">Filter</button>
                            <a href="household_blocked_registrations.php" class="btn btn-outline-secondary">Clear</a>
                        </div>
                    </form>
                </div>

                <!-- Blocked Attempts Table -->
                <div class="mb-3">
                    <h5 class="mb-0 fw-bold">
                        Blocked Registration Attempts
                        <span class="badge bg-danger ms-2"><?= count($records) ?> Records</span>
                    </h5>
                </div>

                <!-- Sticky Bulk Actions Bar -->
                <div id="bulkActionsBar" class="bulk-actions-bar d-none">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="d-flex align-items-center gap-2">
                            <input type="checkbox" id="selectAllSticky" class="form-check-input" onchange="toggleSelectAll(this)">
                            <span class="fw-semibold"><span id="selectedCountText">0</span> selected</span>
                        </div>
                        <button class="btn btn-danger btn-sm" onclick="confirmBulkDelete()">
                            <i class="bi bi-trash-fill me-1"></i>Delete Selected
                        </button>
                    </div>
                </div>

                <?php if (empty($records)): ?>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>No blocked registration attempts found matching your criteria.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                                <table class="table table-hover blocked-table">
                                    <thead>
                                        <tr>
                                            <th class="checkbox-col">
                                                <input type="checkbox" id="selectAll" class="form-check-input" onchange="toggleSelectAll(this)">
                                            </th>
                                            <th><i class="bi bi-clock me-1"></i>Date/Time</th>
                                            <th><i class="bi bi-person me-1"></i>Attempted Student</th>
                                            <th><i class="bi bi-person-heart me-1"></i>Mother's Full Name</th>
                                            <th><i class="bi bi-geo-alt me-1"></i>Barangay</th>
                                            <th><i class="bi bi-shield-check me-1"></i>Blocked By (Existing)</th>
                                            <th><i class="bi bi-diagram-3 me-1"></i>Match Type</th>
                                            <th><i class="bi bi-toggle2-on me-1"></i>Status</th>
                                            <th><i class="bi bi-tools me-1"></i>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($records as $record): ?>
                                            <tr>
                                                <td class="checkbox-col" data-label="Select">
                                                    <input type="checkbox" class="form-check-input record-checkbox" 
                                                           value="<?= $record['attempt_id'] ?>" 
                                                           onchange="updateBulkDeleteButton()">
                                                </td>
                                                <td data-label="Date/Time">
                                                    <div class="fw-semibold"><?= date('M d, Y', strtotime($record['blocked_at'])) ?></div>
                                                    <small class="text-muted"><?= date('h:i A', strtotime($record['blocked_at'])) ?></small>
                                                </td>
                                                <td data-label="Attempted Student">
                                                    <div class="fw-bold"><?= htmlspecialchars($record['attempted_first_name'] . ' ' . $record['attempted_last_name']) ?></div>
                                                    <small class="text-muted d-block">
                                                        <i class="bi bi-envelope me-1"></i><?= htmlspecialchars($record['attempted_email'] ?? 'N/A') ?>
                                                    </small>
                                                    <small class="text-muted">
                                                        <i class="bi bi-telephone me-1"></i><?= htmlspecialchars($record['attempted_mobile'] ?? 'N/A') ?>
                                                    </small>
                                                    <?php if (!empty($record['attempted_email']) || !empty($record['attempted_mobile'])): ?>
                                                        <div class="mt-1">
                                                            <span class="badge bg-info">
                                                                <i class="bi bi-inbox-fill me-1"></i>Contact Provided
                                                            </span>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if (!empty($record['override_reason'])): ?>
                                                        <div class="mt-1">
                                                            <small class="text-muted" title="<?= htmlspecialchars($record['override_reason']) ?>">
                                                                <i class="bi bi-chat-left-quote me-1"></i>
                                                                <?= htmlspecialchars(substr($record['override_reason'], 0, 30)) . (strlen($record['override_reason']) > 30 ? '...' : '') ?>
                                                            </small>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td data-label="Mother's Name" class="fw-semibold"><?= htmlspecialchars($record['mothers_maiden_name_entered']) ?></td>
                                                <td data-label="Barangay">
                                                    <span class="badge bg-secondary">
                                                        <?= htmlspecialchars($record['barangay_entered']) ?>
                                                    </span>
                                                </td>
                                                <td data-label="Blocked By (Existing)">
                                                    <?php if (!empty($record['existing_student_id'])): ?>
                                                        <div class="fw-bold text-primary"><?= htmlspecialchars($record['existing_first_name'] . ' ' . $record['existing_last_name']) ?></div>
                                                        <small class="text-muted"><i class="bi bi-hash me-1"></i><?= htmlspecialchars($record['existing_student_id']) ?></small>
                                                    <?php else: ?>
                                                        <div class="text-muted fst-italic">
                                                            <i class="bi bi-person-dash me-1"></i>Student Removed/Archived
                                                        </div>
                                                        <small class="text-muted">Original student no longer in system</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td data-label="Match Type">
                                                    <?php if ($record['match_type'] === 'exact'): ?>
                                                        <span class="badge bg-danger">
                                                            <i class="bi bi-exclamation-diamond-fill me-1"></i>Exact Match
                                                        </span>
                                                    <?php elseif ($record['match_type'] === 'fuzzy'): ?>
                                                        <span class="badge bg-warning text-dark">
                                                            <i class="bi bi-graph-up me-1"></i>Fuzzy (~<?= round($record['similarity_score'] * 100) ?>%)
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="badge bg-info">
                                                            <i class="bi bi-check2-square me-1"></i>User Confirmed
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td data-label="Status">
                                                    <?php if ($record['admin_override'] == 't'): ?>
                                                        <span class="badge bg-success badge-override">
                                                            <i class="bi bi-check-circle-fill me-1"></i>Overridden
                                                        </span>
                                                        <div class="mt-1"><small class="text-muted"><?= date('M d, Y', strtotime($record['override_at'])) ?></small></div>
                                                        <div><small class="text-muted"><i class="bi bi-person me-1"></i><?= htmlspecialchars($record['override_by_name']) ?></small></div>
                                                        <?php if ($record['bypass_token_used'] == 't'): ?>
                                                            <span class="badge bg-secondary mt-1">
                                                                <i class="bi bi-key-fill me-1"></i>Token Used
                                                            </span>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger badge-override">
                                                            <i class="bi bi-x-circle-fill me-1"></i>Blocked
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td data-label="Actions">
                                                    <?php if ($record['admin_override'] != 't'): ?>
                                                        <button class="btn btn-sm btn-warning override-btn" 
                                                                id="override-btn-<?= $record['attempt_id'] ?>"
                                                                onclick="showOverrideModal(<?= $record['attempt_id'] ?>, '<?= htmlspecialchars($record['attempted_first_name'] . ' ' . $record['attempted_last_name'], ENT_QUOTES) ?>')">
                                                            <i class="bi bi-unlock-fill me-1"></i>Override
                                                        </button>
                                                    <?php else: ?>
                                                        <button class="btn btn-sm btn-secondary" disabled>
                                                            <i class="bi bi-check-lg me-1"></i>Resolved
                                                        </button>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Pagination -->
                            <?php if ($totalPages > 1): ?>
                                <nav class="mt-4">
                                    <ul class="pagination justify-content-center">
                                        <?php if ($page > 1): ?>
                                            <li class="page-item">
                                                <a class="page-link" href="?page=<?= $page - 1 ?>&<?= http_build_query(array_filter($_GET, fn($k) => $k !== 'page', ARRAY_FILTER_USE_KEY)) ?>">
                                                    Previous
                                                </a>
                                            </li>
                                        <?php endif; ?>
                                        
                                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                                <a class="page-link" href="?page=<?= $i ?>&<?= http_build_query(array_filter($_GET, fn($k) => $k !== 'page', ARRAY_FILTER_USE_KEY)) ?>">
                                                    <?= $i ?>
                                                </a>
                                            </li>
                                        <?php endfor; ?>
                                        
                                        <?php if ($page < $totalPages): ?>
                                            <li class="page-item">
                                                <a class="page-link" href="?page=<?= $page + 1 ?>&<?= http_build_query(array_filter($_GET, fn($k) => $k !== 'page', ARRAY_FILTER_USE_KEY)) ?>">
                                                    Next
                                                </a>
                                            </li>
                                        <?php endif; ?>
                                    </ul>
                                </nav>
                            <?php endif; ?>
                        <?php endif; ?>
            </div>
        </section>
    </div>

    <script src="../../assets/js/bootstrap.bundle.min.js"></script>
    <script>
        const csrfToken = '<?= CSRFProtection::generateToken('household_blocks') ?>';

        // Toggle select all checkboxes
        function toggleSelectAll(checkbox) {
            const checkboxes = document.querySelectorAll('.record-checkbox');
            checkboxes.forEach(cb => {
                cb.checked = checkbox.checked;
            });
            updateBulkDeleteButton();
        }

        // Update bulk delete button visibility and count
        function updateBulkDeleteButton() {
            const checkboxes = document.querySelectorAll('.record-checkbox:checked');
            const bulkActionsBar = document.getElementById('bulkActionsBar');
            const selectedCountText = document.getElementById('selectedCountText');
            const selectAllSticky = document.getElementById('selectAllSticky');
            const selectAll = document.getElementById('selectAll');

            if (checkboxes.length > 0) {
                if (bulkActionsBar) bulkActionsBar.classList.remove('d-none');
                if (selectedCountText) selectedCountText.textContent = checkboxes.length;
                
                // Sync sticky checkbox with selection state
                const allCheckboxes = document.querySelectorAll('.record-checkbox');
                if (selectAllSticky) selectAllSticky.checked = checkboxes.length === allCheckboxes.length;
                if (selectAll) selectAll.checked = checkboxes.length === allCheckboxes.length;
            } else {
                if (bulkActionsBar) bulkActionsBar.classList.add('d-none');
                if (selectAll) selectAll.checked = false;
                if (selectAllSticky) selectAllSticky.checked = false;
            }
        }

        // Confirm and process bulk delete
        async function confirmBulkDelete() {
            const checkboxes = document.querySelectorAll('.record-checkbox:checked');
            const selectedIds = Array.from(checkboxes).map(cb => cb.value);
            
            if (selectedIds.length === 0) {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'warning',
                        title: 'No Selection',
                        text: 'Please select at least one record to delete'
                    });
                } else {
                    alert('Please select at least one record to delete');
                }
                return;
            }

            let confirmed = false;
            if (typeof Swal !== 'undefined') {
                const result = await Swal.fire({
                    title: 'Delete Selected Records?',
                    html: `
                        <div class="text-start">
                            <p>You are about to permanently delete <strong>${selectedIds.length}</strong> blocked attempt record(s).</p>
                            <p class="text-danger"><i class="bi bi-exclamation-triangle me-2"></i><strong>This action cannot be undone!</strong></p>
                            <p class="text-muted">These records will be removed from the database and will no longer appear in this log.</p>
                        </div>
                    `,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, Delete',
                    confirmButtonColor: '#dc3545',
                    cancelButtonText: 'Cancel'
                });
                confirmed = result.isConfirmed;
            } else {
                confirmed = confirm(`Delete ${selectedIds.length} selected record(s)?\n\nThis action cannot be undone!`);
            }

            if (confirmed) {
                await processBulkDelete(selectedIds);
            }
        }

        // Process bulk delete
        async function processBulkDelete(attemptIds) {
            try {
                const formData = new FormData();
                formData.append('action', 'bulk_delete');
                formData.append('csrf_token', csrfToken);
                attemptIds.forEach(id => {
                    formData.append('attempt_ids[]', id);
                });

                const response = await fetch('household_blocked_registrations.php', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.success) {
                    if (typeof Swal !== 'undefined') {
                        await Swal.fire({
                            icon: 'success',
                            title: 'Deleted Successfully',
                            text: data.message,
                            timer: 2000,
                            showConfirmButton: false
                        });
                    } else {
                        alert(data.message || 'Records deleted successfully');
                    }
                    location.reload();
                } else {
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: data.message || 'Failed to delete records'
                        });
                    } else {
                        alert('Error: ' + (data.message || 'Failed to delete records'));
                    }
                }
            } catch (error) {
                console.error('Bulk delete error:', error);
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'An error occurred while deleting records'
                    });
                } else {
                    alert('An error occurred while deleting records');
                }
            }
        }

        function showOverrideModal(attemptId, studentName) {
            // Check if button is already disabled (override already in progress or completed)
            const overrideButton = document.getElementById('override-btn-' + attemptId);
            if (overrideButton && overrideButton.disabled) {
                console.log('⚠️ Override button already disabled for attempt ID:', attemptId);
                return; // Prevent showing modal if button is disabled
            }
            
            // Temporarily disable the button while modal is open
            if (overrideButton) {
                overrideButton.disabled = true;
            }
            
            Swal.fire({
                title: 'Override Household Block',
                html: `
                    <div class="text-start">
                        <p>You are about to allow <strong>${studentName}</strong> to register despite household duplicate detection.</p>
                        <p class="text-muted">Please provide a reason for this override:</p>
                        <textarea id="overrideReason" class="form-control" rows="4" 
                                  placeholder="e.g., Verified different household, Data entry error, etc."></textarea>
                    </div>
                `,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Approve Override',
                confirmButtonColor: '#ffc107',
                cancelButtonText: 'Cancel',
                preConfirm: () => {
                    const reason = document.getElementById('overrideReason').value.trim();
                    if (!reason) {
                        Swal.showValidationMessage('Please provide a reason for the override');
                        return false;
                    }
                    return reason;
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    processOverride(attemptId, result.value);
                } else {
                    // Re-enable button if user cancels
                    if (overrideButton) {
                        overrideButton.disabled = false;
                    }
                }
            });
        }

        async function processOverride(attemptId, reason) {
            // Disable the confirm button and show loading state
            let swalConfirmButton = null;
            let originalButtonText = '';
            
            if (typeof Swal !== 'undefined' && Swal.getConfirmButton) {
                swalConfirmButton = Swal.getConfirmButton();
                if (swalConfirmButton) {
                    originalButtonText = swalConfirmButton.innerHTML;
                    swalConfirmButton.disabled = true;
                    swalConfirmButton.innerHTML = '<i class="spinner-border spinner-border-sm me-2"></i>Processing...';
                }
            }
            
            try {
                const formData = new FormData();
                formData.append('action', 'override');
                formData.append('attempt_id', attemptId);
                formData.append('reason', reason);
                formData.append('csrf_token', csrfToken);

                const response = await fetch('household_blocked_registrations.php', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.success) {
                    // Immediately disable the override button in the table
                    const overrideButton = document.getElementById('override-btn-' + attemptId);
                    if (overrideButton) {
                        overrideButton.disabled = true;
                        overrideButton.classList.remove('btn-warning');
                        overrideButton.classList.add('btn-secondary');
                        overrideButton.innerHTML = '<i class="bi bi-check-lg me-1"></i>Resolved';
                        console.log('✅ Override button disabled for attempt ID:', attemptId);
                    }
                    
                    // Build email status message
                    let emailStatusHtml = '';
                    if (data.email && data.email !== 'N/A') {
                        if (data.email_sent) {
                            emailStatusHtml = `
                                <div class="alert alert-success mt-3">
                                    <i class="bi bi-check-circle-fill me-2"></i>
                                    <strong>Email Notification Sent!</strong><br>
                                    <small>The student has been notified at <strong>${data.email}</strong> with instructions to complete their registration.</small>
                                </div>
                            `;
                        } else if (data.email_error) {
                            emailStatusHtml = `
                                <div class="alert alert-warning mt-3">
                                    <i class="bi bi-exclamation-triangle me-2"></i>
                                    <strong>Email Failed to Send</strong><br>
                                    <small>Please manually share the bypass URL with the student at <strong>${data.email}</strong></small><br>
                                    <small class="text-muted">Error: ${data.email_error}</small>
                                </div>
                            `;
                        }
                    } else {
                        emailStatusHtml = `
                            <div class="alert alert-warning mt-3">
                                <i class="bi bi-info-circle me-2"></i>
                                <strong>No Email Address Provided</strong><br>
                                <small>Please manually share the bypass URL with the student.</small>
                            </div>
                        `;
                    }
                    
                    if (typeof Swal !== 'undefined') {
                        await Swal.fire({
                            icon: 'success',
                            title: 'Override Approved',
                            html: `
                                <div class="text-start">
                                    <p>The household block has been overridden. A one-time registration bypass link has been generated:</p>
                                    <div class="alert alert-info">
                                        <small><strong>Bypass URL:</strong></small><br>
                                        <input type="text" class="form-control form-control-sm mt-1" value="${data.bypass_url}" readonly 
                                               onclick="this.select()">
                                    </div>
                                    <p><small class="text-muted">
                                        <i class="bi bi-clock me-1"></i>Expires: ${new Date(data.expires_at).toLocaleString()}
                                    </small></p>
                                    ${emailStatusHtml}
                                    <p class="text-warning"><small>
                                        <i class="bi bi-exclamation-triangle me-1"></i>
                                        This link can only be used once and expires in 24 hours.
                                    </small></p>
                                </div>
                            `,
                            confirmButtonText: 'OK',
                            width: '600px'
                        });
                    } else {
                        alert('Override approved successfully!\n\nBypass URL: ' + data.bypass_url);
                    }
                    location.reload();
                } else {
                    // Re-enable button on error
                    const overrideButton = document.getElementById('override-btn-' + attemptId);
                    if (overrideButton) {
                        overrideButton.disabled = false;
                    }
                    
                    if (swalConfirmButton) {
                        swalConfirmButton.disabled = false;
                        swalConfirmButton.innerHTML = originalButtonText;
                    }
                    
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: data.message || 'Failed to process override'
                        });
                    } else {
                        alert('Error: ' + (data.message || 'Failed to process override'));
                    }
                }
            } catch (error) {
                console.error('Override error:', error);
                // Re-enable button on exception
                const overrideButton = document.getElementById('override-btn-' + attemptId);
                if (overrideButton) {
                    overrideButton.disabled = false;
                }
                
                if (swalConfirmButton) {
                    swalConfirmButton.disabled = false;
                    swalConfirmButton.innerHTML = originalButtonText;
                }
                
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'An error occurred while processing the override'
                    });
                } else {
                    alert('An error occurred while processing the override');
                }
            }
        }
    </script>
</body>
</html>
