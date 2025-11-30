<?php
/**
 * Reset Distribution Script
 * 
 * This script resets students back to 'applicant' status and reopens the distribution
 * Use this when you need to undo a distribution that was started prematurely
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/CSRFProtection.php';

// Check admin authentication
if (!isset($_SESSION['admin_username'])) {
    header("Location: ../../unified_login.php");
    exit;
}

$csrf_token = CSRFProtection::generateToken('reset_distribution');

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_reset'])) {
    $token = $_POST['csrf_token'] ?? '';
    if (!CSRFProtection::validateToken('reset_distribution', $token)) {
        $_SESSION['error_message'] = 'Security validation failed. Please try again.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    
    $admin_password = $_POST['admin_password'] ?? '';
    $reset_type = $_POST['reset_type'] ?? 'students_only';
    
    if (empty($admin_password)) {
        $_SESSION['error_message'] = 'Admin password is required for security verification.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    
    // Verify admin password
    $admin_id = $_SESSION['admin_id'] ?? null;
    if (!$admin_id) {
        $username = $_SESSION['admin_username'] ?? null;
        if ($username) {
            $admin_lookup = pg_query_params($connection, "SELECT admin_id FROM admins WHERE username = $1", [$username]);
            if ($admin_lookup && pg_num_rows($admin_lookup) > 0) {
                $admin_data_lookup = pg_fetch_assoc($admin_lookup);
                $admin_id = $admin_data_lookup['admin_id'];
                $_SESSION['admin_id'] = $admin_id;
            }
        }
    }
    
    if (!$admin_id) {
        $_SESSION['error_message'] = 'Admin session invalid.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    
    $password_check = pg_query_params($connection, "SELECT password FROM admins WHERE admin_id = $1", [$admin_id]);
    if (!$password_check || pg_num_rows($password_check) === 0) {
        $_SESSION['error_message'] = 'Admin not found.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    
    $admin_data = pg_fetch_assoc($password_check);
    if (!password_verify($admin_password, $admin_data['password'])) {
        $_SESSION['error_message'] = 'Incorrect password.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    
    try {
        pg_query($connection, "BEGIN");
        
        error_log("=== Distribution Reset Started ===");
        error_log("Reset type: $reset_type");
        error_log("Admin ID: $admin_id");
        
        // Get counts before reset
        $before_active = pg_fetch_assoc(pg_query($connection, "SELECT COUNT(*) as count FROM students WHERE status = 'active'"))['count'];
        $before_given = pg_fetch_assoc(pg_query($connection, "SELECT COUNT(*) as count FROM students WHERE status = 'given'"))['count'];
        
        // 1. Reset students from 'active' and 'given' back to 'applicant'
        $reset_students_query = "
            UPDATE students 
            SET status = 'applicant' 
            WHERE status IN ('active', 'given')
        ";
        $reset_result = pg_query($connection, $reset_students_query);
        
        if (!$reset_result) {
            throw new Exception('Failed to reset student statuses: ' . pg_last_error($connection));
        }
        
        $students_reset = pg_affected_rows($reset_result);
        error_log("Reset $students_reset students back to 'applicant' status");
        
        // 2. Reset QR codes back to 'Pending'
        $reset_qr_query = "UPDATE qr_codes SET status = 'Pending' WHERE status = 'Done'";
        $reset_qr_result = pg_query($connection, $reset_qr_query);
        
        if (!$reset_qr_result) {
            throw new Exception('Failed to reset QR codes: ' . pg_last_error($connection));
        }
        
        $qr_codes_reset = pg_affected_rows($reset_qr_result);
        error_log("Reset $qr_codes_reset QR codes back to 'Pending'");
        
        if ($reset_type === 'full_reset') {
            // 3. Delete temporary/unfinalized distribution snapshots
            $delete_temp_snapshots_query = "
                DELETE FROM distribution_snapshots 
                WHERE finalized_at IS NULL 
                OR notes LIKE '%Auto-created during QR scanning%'
            ";
            $delete_snapshots_result = pg_query($connection, $delete_temp_snapshots_query);
            
            if (!$delete_snapshots_result) {
                throw new Exception('Failed to delete temporary snapshots: ' . pg_last_error($connection));
            }
            
            $snapshots_deleted = pg_affected_rows($delete_snapshots_result);
            error_log("Deleted $snapshots_deleted temporary distribution snapshot(s)");
            
            // 4. Delete distribution student records for deleted snapshots
            $delete_records_query = "
                DELETE FROM distribution_student_records 
                WHERE snapshot_id NOT IN (SELECT snapshot_id FROM distribution_snapshots)
            ";
            $delete_records_result = pg_query($connection, $delete_records_query);
            
            if (!$delete_records_result) {
                throw new Exception('Failed to delete distribution records: ' . pg_last_error($connection));
            }
            
            $records_deleted = pg_affected_rows($delete_records_result);
            error_log("Deleted $records_deleted orphaned distribution record(s)");
            
            // 5. Delete QR scan logs
            $delete_logs_query = "DELETE FROM qr_logs";
            $delete_logs_result = pg_query($connection, $delete_logs_query);
            
            if (!$delete_logs_result) {
                throw new Exception('Failed to delete QR logs: ' . pg_last_error($connection));
            }
            
            $logs_deleted = pg_affected_rows($delete_logs_result);
            error_log("Deleted $logs_deleted QR scan log(s)");
            
            // 6. Reopen signup slots (set is_active = true)
            $reopen_slots_query = "
                UPDATE signup_slots 
                SET is_active = true 
                WHERE is_active = false
            ";
            $reopen_slots_result = pg_query($connection, $reopen_slots_query);
            
            if (!$reopen_slots_result) {
                error_log("Warning: Failed to reopen slots: " . pg_last_error($connection));
            } else {
                $slots_reopened = pg_affected_rows($reopen_slots_result);
                error_log("Reopened $slots_reopened signup slot(s)");
            }
        }
        
        pg_query($connection, "COMMIT");
        
        $success_message = "✓ Distribution reset completed successfully!\n\n";
        $success_message .= "• Students reset: $students_reset (from active/given → applicant)\n";
        $success_message .= "• QR codes reset: $qr_codes_reset (Done → Pending)\n";
        
        if ($reset_type === 'full_reset') {
            $success_message .= "• Temporary snapshots deleted: " . ($snapshots_deleted ?? 0) . "\n";
            $success_message .= "• Distribution records deleted: " . ($records_deleted ?? 0) . "\n";
            $success_message .= "• QR scan logs deleted: " . ($logs_deleted ?? 0) . "\n";
            $success_message .= "• Signup slots reopened: " . ($slots_reopened ?? 0) . "\n";
        }
        
        $success_message .= "\nYou can now proceed with the distribution workflow properly.";
        
        $_SESSION['success_message'] = $success_message;
        error_log("=== Distribution Reset Completed Successfully ===");
        
    } catch (Exception $e) {
        pg_query($connection, "ROLLBACK");
        error_log("Distribution Reset Error: " . $e->getMessage());
        $_SESSION['error_message'] = 'Error resetting distribution: ' . $e->getMessage();
    }
    
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Get current stats
$stats_query = "
    SELECT 
        COUNT(CASE WHEN status = 'applicant' THEN 1 END) as applicant_count,
        COUNT(CASE WHEN status = 'active' THEN 1 END) as active_count,
        COUNT(CASE WHEN status = 'given' THEN 1 END) as given_count,
        COUNT(CASE WHEN status = 'rejected' THEN 1 END) as rejected_count
    FROM students
";
$stats_result = pg_query($connection, $stats_query);
$stats = $stats_result ? pg_fetch_assoc($stats_result) : [
    'applicant_count' => 0,
    'active_count' => 0,
    'given_count' => 0,
    'rejected_count' => 0
];

// Check for temporary snapshots
$temp_snapshots_query = "
    SELECT COUNT(*) as count 
    FROM distribution_snapshots 
    WHERE finalized_at IS NULL OR notes LIKE '%Auto-created during QR scanning%'
";
$temp_snapshots_result = pg_query($connection, $temp_snapshots_query);
$temp_snapshots_count = $temp_snapshots_result ? pg_fetch_assoc($temp_snapshots_result)['count'] : 0;

// Check QR codes
$qr_stats_query = "
    SELECT 
        COUNT(CASE WHEN status = 'Pending' THEN 1 END) as pending_count,
        COUNT(CASE WHEN status = 'Done' THEN 1 END) as done_count
    FROM qr_codes
";
$qr_stats_result = pg_query($connection, $qr_stats_query);
$qr_stats = $qr_stats_result ? pg_fetch_assoc($qr_stats_result) : [
    'pending_count' => 0,
    'done_count' => 0
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Distribution - EducAid Admin</title>
    <link rel="stylesheet" href="../../assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="../../assets/css/admin_sidebar.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body { font-family: 'Poppins', sans-serif; }
        .stat-card {
            border-radius: 16px;
            transition: transform 0.2s;
            border: none !important;
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
            font-size: 4rem;
            opacity: 0.15;
            transform: rotate(-10deg);
            color: white;
        }
        .stat-card .card-body h6 {
            color: rgba(255,255,255,0.85);
            font-size: 0.85rem;
            font-weight: 500;
        }
        .stat-card .card-body h2 {
            color: white;
            font-weight: 700;
        }
        .stat-applicant {
            background: linear-gradient(135deg, #64748b 0%, #475569 100%);
            box-shadow: 0 4px 14px rgba(100, 116, 139, 0.35);
        }
        .stat-active {
            background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
            box-shadow: 0 4px 14px rgba(34, 197, 94, 0.35);
        }
        .stat-given {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            box-shadow: 0 4px 14px rgba(239, 68, 68, 0.35);
        }
        .stat-rejected {
            background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
            box-shadow: 0 4px 14px rgba(139, 92, 246, 0.35);
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../../includes/admin/admin_topbar.php'; ?>
    <div id="wrapper" class="admin-wrapper">
        <?php include __DIR__ . '/../../includes/admin/admin_sidebar.php'; ?>
        <?php include __DIR__ . '/../../includes/admin/admin_header.php'; ?>
        
        <section class="home-section" id="page-content-wrapper">
            <div class="container py-5">
                <div class="mb-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h1 class="fw-bold mb-0">Reset Distribution</h1>
                    <a href="verify_students.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-1"></i>Back to Verify Students
                    </a>
                </div>

                <!-- Flash Messages -->
                <?php if (isset($_SESSION['success_message'])): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="bi bi-check-circle me-2"></i>
                        <pre class="mb-0" style="white-space: pre-wrap; font-family: inherit;"><?php echo htmlspecialchars($_SESSION['success_message']); ?></pre>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php unset($_SESSION['success_message']); ?>
                <?php endif; ?>

                <?php if (isset($_SESSION['error_message'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <?php echo htmlspecialchars($_SESSION['error_message']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php unset($_SESSION['error_message']); ?>
                <?php endif; ?>

                <!-- Warning Box -->
                <div class="alert alert-warning">
                    <h5 class="alert-heading"><i class="bi bi-exclamation-triangle me-2"></i>Caution</h5>
                    <p>This tool resets the distribution process. Use this when:</p>
                    <ul class="mb-0">
                        <li>You accidentally started scanning without publishing the schedule</li>
                        <li>You need to undo a prematurely started distribution</li>
                        <li>Students were incorrectly marked as 'active' or 'given'</li>
                    </ul>
                </div>

                <!-- Current Statistics -->
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-bar-chart me-2"></i>Current System Status</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-3">
                                <div class="card stat-card stat-applicant">
                                    <div class="card-body">
                                        <i class="bi bi-person-badge watermark-icon"></i>
                                        <h6>Applicants</h6>
                                        <h2 class="mb-0"><?php echo number_format($stats['applicant_count']); ?></h2>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card stat-card stat-active">
                                    <div class="card-body">
                                        <i class="bi bi-check-circle watermark-icon"></i>
                                        <h6>Active (Eligible)</h6>
                                        <h2 class="mb-0"><?php echo number_format($stats['active_count']); ?></h2>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card stat-card stat-given">
                                    <div class="card-body">
                                        <i class="bi bi-gift watermark-icon"></i>
                                        <h6>Given (Distributed)</h6>
                                        <h2 class="mb-0"><?php echo number_format($stats['given_count']); ?></h2>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card stat-card stat-rejected">
                                    <div class="card-body">
                                        <i class="bi bi-x-circle watermark-icon"></i>
                                        <h6>Rejected</h6>
                                        <h2 class="mb-0"><?php echo number_format($stats['rejected_count']); ?></h2>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <hr>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <h6>QR Code Status</h6>
                                <p class="mb-1">Pending: <strong><?php echo number_format($qr_stats['pending_count']); ?></strong></p>
                                <p class="mb-0">Done (Scanned): <strong><?php echo number_format($qr_stats['done_count']); ?></strong></p>
                            </div>
                            <div class="col-md-6">
                                <h6>Distribution Snapshots</h6>
                                <p class="mb-0">Temporary/Unfinalized: <strong><?php echo number_format($temp_snapshots_count); ?></strong></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Reset Form -->
                <div class="card">
                    <div class="card-header bg-danger text-white">
                        <h5 class="mb-0"><i class="bi bi-arrow-counterclockwise me-2"></i>Reset Distribution</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="resetForm">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="confirm_reset" value="1">

                            <div class="mb-4">
                                <label class="form-label fw-bold">Reset Type</label>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="radio" name="reset_type" id="studentsOnly" value="students_only" checked>
                                    <label class="form-check-label" for="studentsOnly">
                                        <strong>Students Only</strong> - Reset student statuses and QR codes only
                                        <br><small class="text-muted">Keeps distribution snapshots and history intact</small>
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="reset_type" id="fullReset" value="full_reset">
                                    <label class="form-check-label" for="fullReset">
                                        <strong>Full Reset</strong> - Reset everything (students, QR codes, snapshots, logs, reopen slots)
                                        <br><small class="text-muted">⚠️ Deletes temporary distribution data and reopens signup slots</small>
                                    </label>
                                </div>
                            </div>

                            <div class="mb-4">
                                <label for="admin_password" class="form-label fw-bold">
                                    Admin Password <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="admin_password" name="admin_password" required>
                                    <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                                        <i class="bi bi-eye" id="passwordIcon"></i>
                                    </button>
                                </div>
                                <small class="text-muted">Enter your admin password to confirm this action</small>
                            </div>

                            <div class="alert alert-danger">
                                <h6 class="alert-heading"><i class="bi bi-exclamation-triangle me-2"></i>What will be reset:</h6>
                                <ul class="mb-0" id="resetDetails">
                                    <li><strong>Students Only:</strong> Resets <?php echo $stats['active_count'] + $stats['given_count']; ?> students back to 'applicant' status</li>
                                    <li><strong>Full Reset:</strong> Also deletes temporary snapshots, distribution records, QR logs, and reopens signup slots</li>
                                </ul>
                            </div>

                            <div class="d-flex justify-content-between">
                                <a href="verify_students.php" class="btn btn-secondary">
                                    <i class="bi bi-x-circle me-1"></i>Cancel
                                </a>
                                <button type="submit" class="btn btn-danger">
                                    <i class="bi bi-arrow-counterclockwise me-1"></i>Reset Distribution
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Instructions -->
                <div class="card mt-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-info-circle me-2"></i>After Reset - Proper Workflow</h5>
                    </div>
                    <div class="card-body">
                        <ol>
                            <li><strong>Verify Students</strong> - Ensure all students are verified and have payroll numbers</li>
                            <li><strong>Generate Schedule</strong> - Create and publish the distribution schedule</li>
                            <li><strong>Scan QR Codes</strong> - Use the QR scanner to distribute aid to students</li>
                            <li><strong>Complete Distribution</strong> - Finalize the distribution when done</li>
                        </ol>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <script src="../../assets/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/js/admin/sidebar.js"></script>
    <script>
        // Password toggle
        document.getElementById('togglePassword').addEventListener('click', function() {
            const passwordInput = document.getElementById('admin_password');
            const passwordIcon = document.getElementById('passwordIcon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                passwordIcon.className = 'bi bi-eye-slash';
            } else {
                passwordInput.type = 'password';
                passwordIcon.className = 'bi bi-eye';
            }
        });

        // Form submission confirmation
        document.getElementById('resetForm').addEventListener('submit', function(e) {
            const resetType = document.querySelector('input[name="reset_type"]:checked').value;
            const activeCount = <?php echo $stats['active_count']; ?>;
            const givenCount = <?php echo $stats['given_count']; ?>;
            const totalToReset = activeCount + givenCount;
            
            let confirmMsg = '⚠️ CONFIRM DISTRIBUTION RESET\n\n';
            
            if (resetType === 'students_only') {
                confirmMsg += 'This will reset ' + totalToReset + ' students back to applicant status.\n\n';
                confirmMsg += 'What will be reset:\n';
                confirmMsg += '• Student statuses (active/given → applicant)\n';
                confirmMsg += '• QR codes (Done → Pending)\n\n';
                confirmMsg += 'What will NOT be affected:\n';
                confirmMsg += '• Distribution snapshots (preserved)\n';
                confirmMsg += '• Signup slots (remain closed)\n';
            } else {
                confirmMsg += 'This will FULLY RESET the distribution system.\n\n';
                confirmMsg += 'What will be reset:\n';
                confirmMsg += '• ' + totalToReset + ' students (active/given → applicant)\n';
                confirmMsg += '• QR codes (Done → Pending)\n';
                confirmMsg += '• Temporary distribution snapshots (deleted)\n';
                confirmMsg += '• Distribution records (deleted)\n';
                confirmMsg += '• QR scan logs (deleted)\n';
                confirmMsg += '• Signup slots (reopened)\n';
            }
            
            confirmMsg += '\nThis action cannot be undone. Continue?';
            
            if (!confirm(confirmMsg)) {
                e.preventDefault();
                return false;
            }
        });

        // Update reset details dynamically
        document.querySelectorAll('input[name="reset_type"]').forEach(radio => {
            radio.addEventListener('change', function() {
                const resetDetails = document.getElementById('resetDetails');
                const activeCount = <?php echo $stats['active_count']; ?>;
                const givenCount = <?php echo $stats['given_count']; ?>;
                const totalToReset = activeCount + givenCount;
                
                if (this.value === 'students_only') {
                    resetDetails.innerHTML = `
                        <li>Resets ${totalToReset} students (${activeCount} active + ${givenCount} given) back to 'applicant'</li>
                        <li>Resets ${<?php echo $qr_stats['done_count']; ?>} QR codes from 'Done' to 'Pending'</li>
                        <li><strong>Keeps:</strong> Distribution snapshots and history intact</li>
                    `;
                } else {
                    resetDetails.innerHTML = `
                        <li>Resets ${totalToReset} students back to 'applicant'</li>
                        <li>Resets ${<?php echo $qr_stats['done_count']; ?>} QR codes to 'Pending'</li>
                        <li>Deletes ${<?php echo $temp_snapshots_count; ?>} temporary snapshot(s)</li>
                        <li>Deletes all distribution records and QR scan logs</li>
                        <li>Reopens all closed signup slots</li>
                        <li><strong class="text-danger">⚠️ This is a complete system reset</strong></li>
                    `;
                }
            });
        });
    </script>
</body>
</html>
