<?php
/**
 * Manual Slot Threshold Notification Trigger (Admin Tool)
 * 
 * Allows admins to manually trigger slot threshold checks and view notification history
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once '../config/database.php';
require_once '../includes/permissions.php';

// Verify admin access
if (!isset($_SESSION['admin_id'])) {
    header('Location: ../admin_login.php');
    exit;
}

$admin_role = getCurrentAdminRole($connection);
if (!in_array($admin_role, ['super_admin', 'admin'])) {
    die('Access denied. Super Admin or Admin role required.');
}

$message = '';
$message_type = '';

// Handle manual trigger
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['trigger'])) {
    $output = [];
    $return_var = 0;
    
    // Execute the threshold check script
    $script_path = __DIR__ . '/../check_slot_thresholds.php';
    $php_path = PHP_BINARY; // Gets the current PHP executable path
    
    exec("\"$php_path\" \"$script_path\" 2>&1", $output, $return_var);
    
    $message = "Threshold check executed. Output:\n" . implode("\n", $output);
    $message_type = $return_var === 0 ? 'success' : 'danger';
}

// Fetch threshold notification history
$history_query = "
    SELECT 
        stn.slot_id,
        ss.academic_year,
        ss.semester,
        ss.slot_count,
        stn.last_threshold,
        stn.last_notified_at,
        stn.students_notified,
        (SELECT COUNT(*) FROM students WHERE slot_id = stn.slot_id) as slots_used,
        (ss.slot_count - (SELECT COUNT(*) FROM students WHERE slot_id = stn.slot_id)) as slots_left
    FROM slot_threshold_notifications stn
    JOIN signup_slots ss ON ss.slot_id = stn.slot_id
    ORDER BY stn.last_notified_at DESC
    LIMIT 20
";
$history_result = pg_query($connection, $history_query);
$history = $history_result ? pg_fetch_all($history_result) : [];

// Fetch active distributions
$active_query = "
    SELECT 
        ss.slot_id,
        ss.academic_year,
        ss.semester,
        ss.slot_count,
        COUNT(s.student_id) as slots_used,
        (ss.slot_count - COUNT(s.student_id)) as slots_left,
        ROUND((COUNT(s.student_id)::decimal / ss.slot_count) * 100, 2) as fill_percentage
    FROM signup_slots ss
    LEFT JOIN students s ON s.slot_id = ss.slot_id
    WHERE ss.is_active = TRUE
    GROUP BY ss.slot_id, ss.slot_count, ss.academic_year, ss.semester
";
$active_result = pg_query($connection, $active_query);
$active_distributions = $active_result ? pg_fetch_all($active_result) : [];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Slot Threshold Notifications - Admin</title>
    <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { padding: 2rem; background: #f8f9fa; }
        .container { max-width: 1200px; }
        .badge-notice { background: #0dcaf0; color: white; }
        .badge-warning { background: #ffc107; color: #000; }
        .badge-urgent { background: #fd7e14; color: white; }
        .badge-critical { background: #dc3545; color: white; }
        .output-box { background: #000; color: #0f0; font-family: monospace; padding: 1rem; border-radius: 0.5rem; max-height: 400px; overflow-y: auto; white-space: pre-wrap; }
    </style>
</head>
<body>
    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="fw-bold mb-1">Slot Threshold Notifications</h1>
            <a href="../modules/admin/dashboard.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Back to Dashboard
            </a>
        </div>

        <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show">
            <div class="output-box"><?php echo htmlspecialchars($message); ?></div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Active Distributions -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-activity"></i> Active Distributions</h5>
            </div>
            <div class="card-body">
                <?php if (empty($active_distributions)): ?>
                    <p class="text-muted">No active distributions found.</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Period</th>
                                <th>Slots Used</th>
                                <th>Slots Left</th>
                                <th>Fill %</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($active_distributions as $dist): 
                                $fill = (float)$dist['fill_percentage'];
                                $badge_class = $fill >= 99 ? 'critical' : ($fill >= 95 ? 'urgent' : ($fill >= 90 ? 'warning' : ($fill >= 80 ? 'notice' : 'success')));
                                $badge_text = $fill >= 99 ? 'Critical' : ($fill >= 95 ? 'Urgent' : ($fill >= 90 ? 'Warning' : ($fill >= 80 ? 'Notice' : 'Healthy')));
                            ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($dist['academic_year'] . ' ' . $dist['semester']); ?></strong></td>
                                <td><?php echo $dist['slots_used']; ?> / <?php echo $dist['slot_count']; ?></td>
                                <td><span class="badge bg-secondary"><?php echo $dist['slots_left']; ?></span></td>
                                <td><?php echo $fill; ?>%</td>
                                <td><span class="badge badge-<?php echo $badge_class; ?>"><?php echo $badge_text; ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Manual Trigger -->
        <div class="card mb-4">
            <div class="card-header bg-warning">
                <h5 class="mb-0"><i class="bi bi-play-circle"></i> Manual Trigger</h5>
            </div>
            <div class="card-body">
                <p>Run the threshold check script manually to test or force immediate notification.</p>
                <form method="POST">
                    <button type="submit" name="trigger" class="btn btn-warning">
                        <i class="bi bi-lightning"></i> Run Threshold Check Now
                    </button>
                </form>
            </div>
        </div>

        <!-- Notification History -->
        <div class="card">
            <div class="card-header bg-secondary text-white">
                <h5 class="mb-0"><i class="bi bi-clock-history"></i> Notification History</h5>
            </div>
            <div class="card-body">
                <?php if (empty($history)): ?>
                    <p class="text-muted">No notification history found.</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Period</th>
                                <th>Threshold</th>
                                <th>Notified At</th>
                                <th>Students Notified</th>
                                <th>Slots Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($history as $row): 
                                $threshold_map = [
                                    'notice_80' => ['Notice (80%)', 'notice'],
                                    'warning_90' => ['Warning (90%)', 'warning'],
                                    'urgent_95' => ['Urgent (95%)', 'urgent'],
                                    'critical_99' => ['Critical (99%)', 'critical']
                                ];
                                [$label, $badge] = $threshold_map[$row['last_threshold']] ?? ['Unknown', 'secondary'];
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['academic_year'] . ' ' . $row['semester']); ?></td>
                                <td><span class="badge badge-<?php echo $badge; ?>"><?php echo $label; ?></span></td>
                                <td><?php echo date('M d, Y g:i A', strtotime($row['last_notified_at'])); ?></td>
                                <td><?php echo number_format($row['students_notified']); ?></td>
                                <td><?php echo $row['slots_used']; ?> / <?php echo $row['slot_count']; ?> (<?php echo $row['slots_left']; ?> left)</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="mt-4 text-center">
            <a href="../SLOT_THRESHOLD_NOTIFICATIONS_GUIDE.md" target="_blank" class="btn btn-link">
                <i class="bi bi-book"></i> View Documentation
            </a>
        </div>
    </div>

    <script src="../assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>
