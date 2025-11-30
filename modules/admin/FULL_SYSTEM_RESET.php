<?php
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

// NUCLEAR OPTION: Full system reset
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nuclear_reset'])) {
    $token = $_POST['csrf_token'] ?? '';
    if (!CSRFProtection::validateToken('nuclear_reset', $token)) {
        $_SESSION['error_message'] = 'Security validation failed. Please refresh and try again.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    
    $password = $_POST['admin_password'] ?? '';
    $confirmation = $_POST['confirmation_text'] ?? '';
    
    if (empty($password) || $confirmation !== 'DELETE EVERYTHING') {
        $_SESSION['error_message'] = 'Password and exact confirmation text required.';
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
    if (!password_verify($password, $admin_data['password'])) {
        $_SESSION['error_message'] = 'Incorrect password.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    
    try {
        pg_query($connection, "BEGIN");
        
        error_log("=== NUCLEAR SYSTEM RESET STARTED ===");
        error_log("Admin ID: $admin_id");
        error_log("Timestamp: " . date('Y-m-d H:i:s'));
        
        $deleted_counts = [];
        
        // 1. Delete all distribution-related database records
        error_log("Step 1: Deleting distribution records...");
        
        $queries = [
            'distribution_student_snapshot' => "DELETE FROM distribution_student_snapshot",
            'distribution_student_records' => "DELETE FROM distribution_student_records",
            'distribution_snapshots' => "DELETE FROM distribution_snapshots",
            'qr_logs' => "DELETE FROM qr_logs",
            'qr_codes' => "DELETE FROM qr_codes",
            'schedules' => "DELETE FROM schedules",
        ];
        
        foreach ($queries as $table => $query) {
            $result = pg_query($connection, $query);
            if ($result) {
                $count = pg_affected_rows($result);
                $deleted_counts[$table] = $count;
                error_log("  - Deleted $count records from $table");
            } else {
                throw new Exception("Failed to delete from $table: " . pg_last_error($connection));
            }
        }
        
        // 2. Delete all student records (must handle foreign key constraints)
        error_log("Step 2: Deleting student records...");
        
        $student_queries = [
            'admin_blacklist_verifications' => "DELETE FROM admin_blacklist_verifications",
            'student_notifications' => "DELETE FROM student_notifications",
            'student_notification_preferences' => "DELETE FROM student_notification_preferences",
            'students' => "DELETE FROM students",
        ];
        
        foreach ($student_queries as $table => $query) {
            $result = pg_query($connection, $query);
            if ($result) {
                $count = pg_affected_rows($result);
                $deleted_counts[$table] = $count;
                error_log("  - Deleted $count records from $table");
            } else {
                throw new Exception("Failed to delete from $table: " . pg_last_error($connection));
            }
        }
        
        // 3. Reset signup slots
        error_log("Step 3: Closing all signup slots...");
        $slots_result = pg_query($connection, "UPDATE signup_slots SET is_active = FALSE");
        if ($slots_result) {
            $count = pg_affected_rows($slots_result);
            $deleted_counts['signup_slots_closed'] = $count;
            error_log("  - Closed $count signup slots");
        }
        
        // 4. Delete all physical files
        error_log("Step 4: Deleting physical files...");
        
        $directories_to_clean = [
            __DIR__ . '/../../uploads/documents/',
            __DIR__ . '/../../uploads/qr_codes/',
            __DIR__ . '/../../uploads/archives/',
            __DIR__ . '/../../data/temp/',
        ];
        
        $total_files_deleted = 0;
        $total_dirs_deleted = 0;
        
        foreach ($directories_to_clean as $dir) {
            if (is_dir($dir)) {
                error_log("  - Cleaning directory: $dir");
                $result = deleteDirectoryContents($dir);
                $total_files_deleted += $result['files'];
                $total_dirs_deleted += $result['dirs'];
                error_log("    Deleted {$result['files']} files, {$result['dirs']} subdirectories");
            }
        }
        
        $deleted_counts['files_deleted'] = $total_files_deleted;
        $deleted_counts['directories_deleted'] = $total_dirs_deleted;
        
        // 5. Unpublish schedule
        error_log("Step 5: Unpublishing schedule...");
        $settingsPath = __DIR__ . '/../../data/municipal_settings.json';
        $settings = file_exists($settingsPath) ? json_decode(file_get_contents($settingsPath), true) : [];
        $settings['schedule_published'] = false;
        file_put_contents($settingsPath, json_encode($settings, JSON_PRETTY_PRINT));
        error_log("  - Schedule unpublished");
        
        // 6. Reset payroll counter (optional - uncomment if you want to reset payroll numbering)
        // error_log("Step 6: Resetting payroll counter...");
        // pg_query($connection, "ALTER SEQUENCE IF EXISTS payroll_counter RESTART WITH 1");
        
        pg_query($connection, "COMMIT");
        
        error_log("=== NUCLEAR SYSTEM RESET COMPLETED SUCCESSFULLY ===");
        error_log("Summary: " . json_encode($deleted_counts));
        
        $summary = "System reset completed successfully!\n\n";
        $summary .= "Database Records Deleted:\n";
        $summary .= "- Distribution Snapshots: {$deleted_counts['distribution_snapshots']}\n";
        $summary .= "- Distribution Student Records: {$deleted_counts['distribution_student_records']}\n";
        $summary .= "- Distribution Student Snapshots: {$deleted_counts['distribution_student_snapshot']}\n";
        $summary .= "- QR Codes: {$deleted_counts['qr_codes']}\n";
        $summary .= "- QR Logs: {$deleted_counts['qr_logs']}\n";
        $summary .= "- Schedules: {$deleted_counts['schedules']}\n";
        $summary .= "- Students: {$deleted_counts['students']}\n";
        $summary .= "- Student Notifications: {$deleted_counts['student_notifications']}\n";
        $summary .= "- Signup Slots Closed: {$deleted_counts['signup_slots_closed']}\n\n";
        $summary .= "Files Deleted:\n";
        $summary .= "- Total Files: {$deleted_counts['files_deleted']}\n";
        $summary .= "- Total Directories: {$deleted_counts['directories_deleted']}\n\n";
        $summary .= "The system is now in a clean state. You can start fresh!";
        
        $_SESSION['success_message'] = $summary;
        
    } catch (Exception $e) {
        pg_query($connection, "ROLLBACK");
        error_log("NUCLEAR RESET ERROR: " . $e->getMessage());
        $_SESSION['error_message'] = 'Reset failed: ' . $e->getMessage();
    }
    
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Recursive directory deletion function
function deleteDirectoryContents($dir) {
    $files_deleted = 0;
    $dirs_deleted = 0;
    
    if (!is_dir($dir)) {
        return ['files' => 0, 'dirs' => 0];
    }
    
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    
    foreach ($items as $item) {
        if ($item->isDir()) {
            if (@rmdir($item->getRealPath())) {
                $dirs_deleted++;
            }
        } else {
            if (@unlink($item->getRealPath())) {
                $files_deleted++;
            }
        }
    }
    
    return ['files' => $files_deleted, 'dirs' => $dirs_deleted];
}

// Get current statistics
$stats = [];

$stats_queries = [
    'students' => "SELECT COUNT(*) as count FROM students",
    'qr_codes' => "SELECT COUNT(*) as count FROM qr_codes",
    'distribution_snapshots' => "SELECT COUNT(*) as count FROM distribution_snapshots",
    'distribution_records' => "SELECT COUNT(*) as count FROM distribution_student_records",
    'schedules' => "SELECT COUNT(*) as count FROM schedules",
    'active_slots' => "SELECT COUNT(*) as count FROM signup_slots WHERE is_active = true",
];

foreach ($stats_queries as $key => $query) {
    $result = pg_query($connection, $query);
    if ($result) {
        $row = pg_fetch_assoc($result);
        $stats[$key] = intval($row['count']);
    } else {
        $stats[$key] = 0;
    }
}

// Count files in directories
$file_dirs = [
    'documents' => __DIR__ . '/../../uploads/documents/',
    'qr_codes' => __DIR__ . '/../../uploads/qr_codes/',
    'archives' => __DIR__ . '/../../uploads/archives/',
];

$file_counts = [];
foreach ($file_dirs as $key => $dir) {
    if (is_dir($dir)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        $count = 0;
        foreach ($files as $file) {
            if ($file->isFile()) {
                $count++;
            }
        }
        $file_counts[$key] = $count;
    } else {
        $file_counts[$key] = 0;
    }
}

$csrf_token = CSRFProtection::generateToken('nuclear_reset');
?>

<?php $page_title = 'Full System Reset'; include __DIR__ . '/../../includes/admin/admin_head.php'; ?>
<style>
    .danger-zone {
        border: 3px solid #dc3545;
        border-radius: 10px;
        background: linear-gradient(135deg, #fff5f5 0%, #ffe6e6 100%);
        padding: 30px;
        margin: 20px 0;
    }
    
    .nuclear-button {
        background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
        border: none;
        color: white;
        font-size: 1.2rem;
        font-weight: bold;
        padding: 15px 40px;
        border-radius: 8px;
        box-shadow: 0 4px 15px rgba(220, 53, 69, 0.4);
        transition: all 0.3s ease;
    }
    
    .nuclear-button:hover {
        background: linear-gradient(135deg, #c82333 0%, #bd2130 100%);
        box-shadow: 0 6px 20px rgba(220, 53, 69, 0.6);
        transform: translateY(-2px);
    }
    
    .stat-card {
        border-radius: 16px;
        padding: 20px;
        margin-bottom: 20px;
        position: relative;
        overflow: hidden;
        transition: transform 0.2s;
    }
    
    .stat-card:hover {
        transform: translateY(-4px);
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
    
    .stat-card h5 {
        color: rgba(255,255,255,0.85);
        font-size: 0.9rem;
        font-weight: 500;
        margin-bottom: 0.5rem;
    }
    
    .stat-card h2 {
        color: white;
        font-weight: 700;
        font-size: 2rem;
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
    .stat-amber {
        background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        box-shadow: 0 4px 14px rgba(245, 158, 11, 0.35);
    }
    .stat-slate {
        background: linear-gradient(135deg, #64748b 0%, #475569 100%);
        box-shadow: 0 4px 14px rgba(100, 116, 139, 0.35);
    }
    .stat-red {
        background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
        box-shadow: 0 4px 14px rgba(239, 68, 68, 0.35);
    }
    
    .warning-banner {
        background: linear-gradient(135deg, #ff6b6b 0%, #ee5a6f 100%);
        color: white;
        padding: 20px;
        border-radius: 10px;
        margin-bottom: 30px;
        animation: pulse 2s infinite;
    }
    
    @keyframes pulse {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.8; }
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
                <h1 class="fw-bold mb-0">Full System Reset</h1>
                <a href="verify_students.php" class="btn btn-secondary">
                    <i class="bi bi-arrow-left me-2"></i>Back to Dashboard
                </a>
            </div>

            <!-- Flash Messages -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="bi bi-check-circle me-2"></i>
                    <pre style="white-space: pre-wrap; margin: 0;"><?php echo htmlspecialchars($_SESSION['success_message']); ?></pre>
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

            <!-- Warning Banner -->
            <div class="warning-banner text-center">
                <h2><i class="bi bi-exclamation-triangle-fill me-3"></i>DANGER ZONE<i class="bi bi-exclamation-triangle-fill ms-3"></i></h2>
                <p class="mb-0 fs-5">This action is IRREVERSIBLE and will DELETE ALL DATA from the system!</p>
            </div>

            <!-- Current System Statistics -->
            <div class="card mb-4">
                <div class="card-header bg-dark text-white">
                    <h4 class="mb-0"><i class="bi bi-bar-chart me-2"></i>Current System Status</h4>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="stat-card stat-blue">
                                <i class="bi bi-mortarboard watermark-icon"></i>
                                <h5>Students</h5>
                                <h2 class="mb-0"><?php echo number_format($stats['students']); ?></h2>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="stat-card stat-cyan">
                                <i class="bi bi-qr-code watermark-icon"></i>
                                <h5>QR Codes</h5>
                                <h2 class="mb-0"><?php echo number_format($stats['qr_codes']); ?></h2>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="stat-card stat-green">
                                <i class="bi bi-camera watermark-icon"></i>
                                <h5>Distribution Snapshots</h5>
                                <h2 class="mb-0"><?php echo number_format($stats['distribution_snapshots']); ?></h2>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="stat-card stat-amber">
                                <i class="bi bi-list-check watermark-icon"></i>
                                <h5>Distribution Records</h5>
                                <h2 class="mb-0"><?php echo number_format($stats['distribution_records']); ?></h2>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="stat-card stat-slate">
                                <i class="bi bi-calendar3 watermark-icon"></i>
                                <h5>Schedules</h5>
                                <h2 class="mb-0"><?php echo number_format($stats['schedules']); ?></h2>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="stat-card stat-red">
                                <i class="bi bi-clock watermark-icon"></i>
                                <h5>Active Slots</h5>
                                <h2 class="mb-0"><?php echo number_format($stats['active_slots']); ?></h2>
                            </div>
                        </div>
                    </div>
                    
                    <hr class="my-4">
                    
                    <h5 class="mb-3">File System Status</h5>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="stat-card stat-blue">
                                <i class="bi bi-file-earmark-text watermark-icon"></i>
                                <h5>Document Files</h5>
                                <h2 class="mb-0"><?php echo number_format($file_counts['documents']); ?></h2>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="stat-card stat-cyan">
                                <i class="bi bi-qr-code-scan watermark-icon"></i>
                                <h5>QR Code Images</h5>
                                <h2 class="mb-0"><?php echo number_format($file_counts['qr_codes']); ?></h2>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="stat-card stat-green">
                                <i class="bi bi-archive watermark-icon"></i>
                                <h5>Archive Files</h5>
                                <h2 class="mb-0"><?php echo number_format($file_counts['archives']); ?></h2>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Nuclear Reset Section -->
            <div class="danger-zone">
                <h3 class="text-danger mb-4">
                    <i class="bi bi-nuclear me-2"></i>Nuclear System Reset
                </h3>
                
                <div class="alert alert-danger mb-4">
                    <h5><i class="bi bi-exclamation-octagon me-2"></i>This will permanently delete:</h5>
                    <ul class="mb-0">
                        <li><strong>All student records</strong> (<?php echo number_format($stats['students']); ?> students)</li>
                        <li><strong>All distribution snapshots</strong> (<?php echo number_format($stats['distribution_snapshots']); ?> snapshots)</li>
                        <li><strong>All distribution records</strong> (<?php echo number_format($stats['distribution_records']); ?> records)</li>
                        <li><strong>All QR codes and logs</strong> (<?php echo number_format($stats['qr_codes']); ?> codes)</li>
                        <li><strong>All schedules</strong> (<?php echo number_format($stats['schedules']); ?> schedules)</li>
                        <li><strong>All student notifications</strong></li>
                        <li><strong>All uploaded documents</strong> (<?php echo number_format($file_counts['documents']); ?> files)</li>
                        <li><strong>All QR code images</strong> (<?php echo number_format($file_counts['qr_codes']); ?> files)</li>
                        <li><strong>All archive files</strong> (<?php echo number_format($file_counts['archives']); ?> files)</li>
                        <li><strong>Close all signup slots</strong></li>
                        <li><strong>Unpublish schedule</strong></li>
                    </ul>
                </div>
                
                <div class="alert alert-warning">
                    <i class="bi bi-shield-check me-2"></i>
                    <strong>What will NOT be deleted:</strong>
                    <ul class="mb-0">
                        <li>Admin accounts</li>
                        <li>Barangays, universities, year levels, and other lookup tables</li>
                        <li>System configuration (municipal settings will be preserved)</li>
                    </ul>
                </div>
                
                <button type="button" class="nuclear-button" data-bs-toggle="modal" data-bs-target="#nuclearResetModal">
                    <i class="bi bi-trash3 me-2"></i>RESET ENTIRE SYSTEM
                </button>
            </div>
        </div>
    </section>
</div>

<!-- Nuclear Reset Confirmation Modal -->
<div class="modal fade" id="nuclearResetModal" tabindex="-1" aria-labelledby="nuclearResetModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-danger" style="border-width: 3px;">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="nuclearResetModalLabel">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>Confirm Nuclear System Reset
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="nuclearResetForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="nuclear_reset" value="1">
                
                <div class="modal-body">
                    <div class="alert alert-danger">
                        <h5 class="alert-heading">
                            <i class="bi bi-exclamation-octagon me-2"></i>FINAL WARNING
                        </h5>
                        <p class="mb-0">
                            You are about to <strong>PERMANENTLY DELETE ALL DATA</strong> from the system.
                            This includes all students, distributions, files, and records.
                            <strong>This action CANNOT be undone!</strong>
                        </p>
                    </div>
                    
                    <div class="mb-3">
                        <label for="admin_password" class="form-label fw-bold">
                            <i class="bi bi-key me-1"></i>Enter Your Admin Password
                        </label>
                        <div class="input-group">
                            <input type="password" class="form-control form-control-lg" 
                                   id="admin_password" name="admin_password" required>
                            <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                                <i class="bi bi-eye" id="passwordIcon"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="confirmation_text" class="form-label fw-bold">
                            <i class="bi bi-keyboard me-1"></i>Type exactly: <code class="text-danger">DELETE EVERYTHING</code>
                        </label>
                        <input type="text" class="form-control form-control-lg" 
                               id="confirmation_text" name="confirmation_text" 
                               placeholder="Type: DELETE EVERYTHING" required>
                        <div class="form-text">This confirmation is case-sensitive.</div>
                    </div>
                    
                    <div class="alert alert-info">
                        <h6><i class="bi bi-info-circle me-2"></i>What happens after reset:</h6>
                        <ol class="mb-0">
                            <li>All data will be deleted</li>
                            <li>System will return to clean state</li>
                            <li>You can create new signup slots</li>
                            <li>Students can register fresh</li>
                            <li>You can start a new distribution cycle</li>
                        </ol>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-lg" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-2"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-danger btn-lg" id="confirmResetButton">
                        <i class="bi bi-nuclear me-2"></i>EXECUTE NUCLEAR RESET
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="../../assets/js/admin/sidebar.js"></script>
<script src="../../assets/js/bootstrap.bundle.min.js"></script>
<script>
    // Password toggle
    const toggleBtn = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('admin_password');
    const passwordIcon = document.getElementById('passwordIcon');
    
    toggleBtn.addEventListener('click', function() {
        const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
        passwordInput.setAttribute('type', type);
        passwordIcon.className = type === 'password' ? 'bi bi-eye' : 'bi bi-eye-slash';
    });
    
    // Form validation
    const form = document.getElementById('nuclearResetForm');
    const confirmButton = document.getElementById('confirmResetButton');
    const confirmationInput = document.getElementById('confirmation_text');
    
    confirmationInput.addEventListener('input', function() {
        if (this.value === 'DELETE EVERYTHING') {
            confirmButton.disabled = false;
            this.classList.remove('is-invalid');
            this.classList.add('is-valid');
        } else {
            confirmButton.disabled = true;
            this.classList.remove('is-valid');
            if (this.value.length > 0) {
                this.classList.add('is-invalid');
            }
        }
    });
    
    // Initial state
    confirmButton.disabled = true;
    
    // Confirm before submit
    form.addEventListener('submit', function(e) {
        const password = passwordInput.value.trim();
        const confirmation = confirmationInput.value;
        
        if (!password) {
            e.preventDefault();
            alert('Please enter your admin password.');
            return;
        }
        
        if (confirmation !== 'DELETE EVERYTHING') {
            e.preventDefault();
            alert('Please type exactly: DELETE EVERYTHING');
            return;
        }
        
        if (!confirm('FINAL CONFIRMATION: Are you absolutely sure you want to delete ALL data? This cannot be undone!')) {
            e.preventDefault();
            return;
        }
        
        // Show loading state
        confirmButton.disabled = true;
        confirmButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Executing Nuclear Reset...';
    });
</script>
</body>
</html>
