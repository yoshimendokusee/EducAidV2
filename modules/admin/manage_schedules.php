<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['admin_username'])) {
    header("Location: ../../unified_login.php");
    exit;
}
include __DIR__ . '/../../config/database.php';
include __DIR__ . '/../../includes/workflow_control.php';
require_once __DIR__ . '/../../includes/CSRFProtection.php';

// Check workflow status - prevent access if payroll/QR not ready
$workflow_status = getWorkflowStatus($connection);
if (!$workflow_status['can_schedule']) {
    echo "<script>alert('Access denied: Please generate payroll numbers and QR codes first.'); window.location.href = 'verify_students.php';</script>";
    exit;
}

// Get admin ID for tracking
$admin_id = $_SESSION['admin_id'] ?? 1;

// Load settings for publish state
$settingsPath = __DIR__ . '/../../data/municipal_settings.json';
$settings = file_exists($settingsPath) ? json_decode(file_get_contents($settingsPath), true) : [];

// Get documents deadline from config
$documents_deadline = '';
$deadline_query = pg_query($connection, "SELECT value FROM config WHERE key = 'documents_deadline'");
if ($deadline_query && $deadline_row = pg_fetch_assoc($deadline_query)) {
    $documents_deadline = $deadline_row['value'];
}

// Calculate minimum schedule date (deadline + 5 days)
$min_schedule_date = '';
if ($documents_deadline) {
    $deadline_timestamp = strtotime($documents_deadline);
    $min_schedule_timestamp = strtotime('+5 days', $deadline_timestamp);
    $min_schedule_date = date('Y-m-d', $min_schedule_timestamp);
}

// Handle AJAX requests for date validation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'check_date_availability') {
        $date = $_POST['date'];
        $result = pg_query_params($connection, 
            "SELECT COUNT(*) as count FROM schedules WHERE distribution_date = $1", 
            [$date]
        );
        $row = pg_fetch_assoc($result);
        echo json_encode(['available' => $row['count'] == 0]);
        exit;
    }
    
    if ($_POST['action'] === 'validate_time_slot') {
        $date = $_POST['date'];
        $start_time = $_POST['start_time'];
        $end_time = $_POST['end_time'];
        
        // Check for time overlaps on the same date
        $result = pg_query_params($connection,
            "SELECT COUNT(*) as count FROM schedules 
             WHERE distribution_date = $1 
             AND ((time_slot LIKE $2) OR (time_slot LIKE $3))",
            [$date, "%{$start_time}%", "%{$end_time}%"]
        );
        $row = pg_fetch_assoc($result);
        echo json_encode(['valid' => $row['count'] == 0]);
        exit;
    }
}

// Handle schedule creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_schedule'])) {
    // Validate CSRF token
    $token = $_POST['csrf_token'] ?? '';
    if (!CSRFProtection::validateToken('manage_schedules', $token)) {
        $error_message = "Security validation failed. Please refresh the page and try again.";
        error_log("CSRF validation failed for create_schedule");
    } else {
    // Debug: Log the received data with more detail
    error_log("Received POST data: " . print_r($_POST, true));
    error_log("Raw schedule_data: " . ($_POST['schedule_data'] ?? 'NOT SET'));
    error_log("Schedule_data length: " . strlen($_POST['schedule_data'] ?? ''));
    
    if (!isset($_POST['schedule_data'])) {
        $error_message = "No schedule data received in POST request.";
        error_log("ERROR: No schedule_data in POST");
    } else {
        $schedule_data = json_decode($_POST['schedule_data'], true);
        
        // Debug: Log the decoded data
        error_log("Decoded schedule data: " . print_r($schedule_data, true));
        error_log("JSON decode error: " . json_last_error_msg());
        
        if ($schedule_data === null) {
            $error_message = "Invalid JSON data received: " . json_last_error_msg();
            error_log("JSON decode failed: " . json_last_error_msg() . " for data: " . $_POST['schedule_data']);
        } elseif (!isset($schedule_data['start_date']) || !isset($schedule_data['end_date']) || !isset($schedule_data['location']) || !isset($schedule_data['batches'])) {
            $error_message = "Invalid schedule data received. Please ensure all fields are filled correctly.";
            error_log("Invalid schedule data: " . $_POST['schedule_data']);
        } else {
            $start_date = $schedule_data['start_date'];
            $end_date = $schedule_data['end_date'];
            $location = $schedule_data['location'];
            $batches = $schedule_data['batches'];
            
            // Additional validation
            if (empty($batches) || !is_array($batches)) {
                $error_message = "No batches were configured. Please select number of batches and configure them.";
            } elseif (strtotime($end_date) < strtotime($start_date)) {
                $error_message = "End date cannot be before start date.";
            } elseif ($documents_deadline && strtotime($start_date) < strtotime($min_schedule_date)) {
                $formatted_deadline = date('F j, Y', strtotime($documents_deadline));
                $formatted_min_date = date('F j, Y', strtotime($min_schedule_date));
                $error_message = "Distribution schedules must be at least 5 days after the document submission deadline. " .
                                "Deadline: {$formatted_deadline}. Earliest schedule date: {$formatted_min_date}.";
            } else {
                // Check if any dates in the range are already used
                $conflicting_dates = [];
                $current_date = $start_date;
                while (strtotime($current_date) <= strtotime($end_date)) {
                    $date_check = pg_query_params($connection, 
                        "SELECT COUNT(*) as count FROM schedules WHERE distribution_date = $1", 
                        [$current_date]
                    );
                    $date_row = pg_fetch_assoc($date_check);
                    if ($date_row && $date_row['count'] > 0) {
                        $conflicting_dates[] = $current_date;
                    }
                    $current_date = date('Y-m-d', strtotime($current_date . ' +1 day'));
                }
                
                if (!empty($conflicting_dates)) {
                    $error_message = "The following dates are already used: " . implode(', ', $conflicting_dates) . ". Please choose different dates.";
                } else {
                    // Begin transaction
                    pg_query($connection, "BEGIN");
                    
                    try {
                        // First, get all students with payroll numbers to know actual capacity
                        $studentCountResult = pg_query($connection, "SELECT COUNT(*) as count FROM students WHERE payroll_no IS NOT NULL AND payroll_no <> ''");
                        $studentCountRow = pg_fetch_assoc($studentCountResult);
                        $actualStudentCount = intval($studentCountRow['count']);
                        
                        if ($actualStudentCount == 0) {
                            throw new Exception("No students with payroll numbers found in the database. Please ensure students have been assigned payroll numbers.");
                        }
                        
                        $counter = 1;
                        $total_students = 0;
                        $schedule_dates = [];
                        
                        // Create schedules for each date in the range
                        $current_date = $start_date;
                        while (strtotime($current_date) <= strtotime($end_date)) {
                            $schedule_dates[] = $current_date;
                            
                            if (is_array($batches) && count($batches) > 0) {
                                foreach ($batches as $batch_num => $batch) {
                                    $students_in_batch = intval($batch['capacity'] ?? 0);
                                    $start_time = $batch['start_time'] ?? '';
                                    $end_time = $batch['end_time'] ?? '';
                                    $time_slot = $start_time . ' - ' . $end_time;
                                    
                                    // Only schedule as many students as we have capacity for and exist in DB
                                    $students_to_schedule = min($students_in_batch, $actualStudentCount - ($counter - 1));
                                    
                                    for ($i = 0; $i < $students_to_schedule; $i++) {
                                        // Fetch the next student in payroll order (payroll_no is TEXT formatted)
                                        $offset = $counter - 1;
                                        $sidRes = pg_query_params(
                                            $connection,
                                            "SELECT student_id, payroll_no FROM students WHERE payroll_no IS NOT NULL AND payroll_no <> '' ORDER BY payroll_no ASC LIMIT 1 OFFSET $1",
                                            [$offset]
                                        );

                                        if (!$sidRes) {
                                            error_log("Failed to query student at offset: $offset - " . pg_last_error($connection));
                                            continue;
                                        }

                                        $sid = pg_fetch_assoc($sidRes);
                                        if (!$sid) {
                                            error_log("No student found at offset: $offset");
                                            break;
                                        }

                                        $studentId = intval($sid['student_id']);
                                        $pno = (string)$sid['payroll_no'];

                                        // Debug the data being inserted
                                        error_log("Inserting schedule: student_id=$studentId, payroll_no=$pno, batch_no=" . ($batch_num + 1) . ", date=$current_date, time_slot=$time_slot, location=$location");

                                        $insertResult = pg_query_params($connection,
                                            "INSERT INTO schedules (student_id, payroll_no, batch_no, distribution_date, time_slot, location) 
                                             VALUES ($1, $2, $3, $4, $5, $6)",
                                            [$studentId, $pno, $batch_num + 1, $current_date, $time_slot, $location]
                                        );
                                        
                                        if (!$insertResult) {
                                            $error = pg_last_error($connection);
                                            error_log("Failed to insert schedule for payroll_no $pno: $error");
                                            throw new Exception("Database insertion failed: $error");
                                        }
                                        
                                        $counter++;
                                        $total_students++;
                                    }
                                    
                                    // If we've scheduled all available students, break out of batch loop
                                    if ($counter > $actualStudentCount) {
                                        break;
                                    }
                                }
                            }
                            
                            $current_date = date('Y-m-d', strtotime($current_date . ' +1 day'));
                        }
                        
                        // Save schedule metadata
                        $settings['schedule_meta'] = [
                            'start_date' => $start_date,
                            'end_date' => $end_date,
                            'location' => $location,
                            'batches' => $batches,
                            'total_students' => $total_students,
                            'schedule_dates' => $schedule_dates
                        ];
                        file_put_contents($settingsPath, json_encode($settings, JSON_PRETTY_PRINT));
                        
                        pg_query($connection, "COMMIT");
                        $date_range = ($start_date === $end_date) ? $start_date : "{$start_date} to {$end_date}";
                        $success_message = "Schedule created successfully for {$date_range} with {$total_students} students across " . count($batches) . " batches per day.";
                        
                        // Add info if actual students is less than requested capacity
                        $total_requested_capacity = array_sum(array_column($batches, 'capacity'));
                        if ($total_students < $total_requested_capacity) {
                            $success_message .= " Note: Only {$actualStudentCount} students are available in the database (requested capacity: {$total_requested_capacity}).";
                        }
                
                } catch (Exception $e) {
                    pg_query($connection, "ROLLBACK");
                    $error_message = "Failed to create schedule: " . $e->getMessage();
                }
            }
            }
        }
    }
    } // Close CSRF validation else block
}

// Handle publish schedule action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['publish_schedule'])) {
    // Validate CSRF token
    $token = $_POST['csrf_token'] ?? '';
    if (!CSRFProtection::validateToken('manage_schedules', $token)) {
        echo "<script>alert('Security validation failed. Please refresh and try again.'); window.location.href='manage_schedules.php';</script>";
        exit;
    }
    
    $settings['schedule_published'] = true;
    file_put_contents($settingsPath, json_encode($settings, JSON_PRETTY_PRINT));
    
    // Add admin notification
    $notification_msg = "Distribution schedule published";
    pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
    
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Handle unpublish
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unpublish_schedule'])) {
    // Validate CSRF token
    $token = $_POST['csrf_token'] ?? '';
    if (!CSRFProtection::validateToken('manage_schedules', $token)) {
        echo "<script>alert('Security validation failed. Please refresh and try again.'); window.location.href='manage_schedules.php';</script>";
        exit;
    }
    
    // CONSTRAINT 1: Check if any student has been scanned (status = 'given')
    $scanned_check = pg_query($connection, "SELECT COUNT(*) as count FROM students WHERE status = 'given'");
    $scanned_count = 0;
    if ($scanned_check) {
        $scanned_data = pg_fetch_assoc($scanned_check);
        $scanned_count = intval($scanned_data['count']);
    }
    
    if ($scanned_count > 0) {
        echo "<script>alert('Cannot unpublish schedule: " . $scanned_count . " student(s) have already received their aid. Schedule must remain published for record-keeping.'); window.location.href='manage_schedules.php';</script>";
        exit;
    }
    
    // CONSTRAINT 2: Check if we're within 1 day of distribution start date
    $earliest_date_query = pg_query($connection, "SELECT MIN(distribution_date) as earliest_date FROM schedules");
    if ($earliest_date_query) {
        $date_row = pg_fetch_assoc($earliest_date_query);
        $earliest_date = $date_row['earliest_date'];
        
        if ($earliest_date) {
            $start_timestamp = strtotime($earliest_date);
            $current_timestamp = time();
            $time_diff = $start_timestamp - $current_timestamp;
            $hours_until_start = $time_diff / 3600;
            
            // If less than 24 hours until distribution starts
            if ($hours_until_start < 24 && $hours_until_start >= 0) {
                $formatted_date = date('F j, Y', $start_timestamp);
                $hours_remaining = round($hours_until_start, 1);
                echo "<script>alert('Cannot unpublish schedule: Distribution starts on " . $formatted_date . " (in " . $hours_remaining . " hours). Schedule cannot be unpublished within 24 hours of distribution start.'); window.location.href='manage_schedules.php';</script>";
                exit;
            }
        }
    }
    
    // CONSTRAINT 3: Check if distribution has been completed but not yet compressed/archived
    // This prevents unpublishing after "Complete Distribution" is clicked but before "End Distribution" compresses files
    $uncompressed_snapshot_check = pg_query($connection, 
        "SELECT snapshot_id, academic_year, semester, finalized_at 
         FROM distribution_snapshots 
         WHERE finalized_at IS NOT NULL 
         AND (files_compressed = FALSE OR files_compressed IS NULL)
         ORDER BY finalized_at DESC 
         LIMIT 1"
    );
    
    if ($uncompressed_snapshot_check && pg_num_rows($uncompressed_snapshot_check) > 0) {
        $snapshot_data = pg_fetch_assoc($uncompressed_snapshot_check);
        $formatted_date = date('F j, Y g:i A', strtotime($snapshot_data['finalized_at']));
        echo "<script>alert('Cannot unpublish schedule: Distribution was completed on " . $formatted_date . " for " . $snapshot_data['academic_year'] . " " . $snapshot_data['semester'] . " but files have not been compressed yet. Please go to End Distribution to compress and archive the distribution files first.'); window.location.href='manage_schedules.php';</script>";
        exit;
    }
    
    // If all constraints pass, allow unpublishing
    $settings['schedule_published'] = false;
    file_put_contents($settingsPath, json_encode($settings, JSON_PRETTY_PRINT));
    
    // NOTE: We no longer delete schedules - just toggle visibility
    // This preserves all schedule data while hiding it from students
    
    // Add admin notification
    $notification_msg = "Distribution schedule unpublished (schedules preserved)";
    pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
    
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Handle clear schedule data (permanent deletion)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_schedule_data'])) {
    // Validate CSRF token
    $token = $_POST['csrf_token'] ?? '';
    if (!CSRFProtection::validateToken('manage_schedules', $token)) {
        echo "<script>alert('Security validation failed. Please refresh and try again.'); window.location.href='manage_schedules.php';</script>";
        exit;
    }
    
    // CRITICAL: Check if distribution has been completed but not yet compressed
    // Prevent clearing schedule data if there are uncompressed distribution snapshots
    $uncompressed_check = pg_query($connection, 
        "SELECT snapshot_id, academic_year, semester, finalized_at 
         FROM distribution_snapshots 
         WHERE finalized_at IS NOT NULL 
         AND (files_compressed = FALSE OR files_compressed IS NULL)
         ORDER BY finalized_at DESC 
         LIMIT 1"
    );
    
    if ($uncompressed_check && pg_num_rows($uncompressed_check) > 0) {
        $snapshot = pg_fetch_assoc($uncompressed_check);
        $formatted_date = date('F j, Y g:i A', strtotime($snapshot['finalized_at']));
        echo "<script>alert('Cannot clear schedule data: Distribution was completed on " . $formatted_date . " but files have not been compressed yet. Please go to End Distribution to compress and archive the distribution files first, then you can clear the schedule data.'); window.location.href='manage_schedules.php';</script>";
        exit;
    }
    
    // Check if any student has been scanned (additional safety check)
    $scanned_check = pg_query($connection, "SELECT COUNT(*) as count FROM students WHERE status = 'given'");
    $scanned_count = 0;
    if ($scanned_check) {
        $scanned_data = pg_fetch_assoc($scanned_check);
        $scanned_count = intval($scanned_data['count']);
    }
    
    if ($scanned_count > 0) {
        echo "<script>alert('Cannot clear schedule data: " . $scanned_count . " student(s) have received their aid. You must complete and compress the distribution first before clearing schedule data.'); window.location.href='manage_schedules.php';</script>";
        exit;
    }
    
    // Delete all schedules from database
    pg_query($connection, "DELETE FROM schedules");
    
    // Reset published state and clear schedule metadata
    $settings['schedule_published'] = false;
    if (isset($settings['schedule_meta'])) {
        unset($settings['schedule_meta']);
    }
    file_put_contents($settingsPath, json_encode($settings, JSON_PRETTY_PRINT));
    
    // Add admin notification
    $notification_msg = "All schedule data permanently deleted";
    pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
    
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

$schedulePublished = !empty($settings['schedule_published']);

// Generate CSRF token for all forms on this page
$csrfToken = CSRFProtection::generateToken('manage_schedules');

// Get payroll info
$result = pg_query($connection, "SELECT MAX(payroll_no) AS max_no FROM students");
$row = pg_fetch_assoc($result);
$maxPayroll = isset($row['max_no']) ? $row['max_no'] : '';

$countRes = pg_query($connection, "SELECT COUNT(*) AS count_no FROM students WHERE payroll_no IS NOT NULL AND payroll_no <> ''");
$rowCount = pg_fetch_assoc($countRes);
$countStudents = isset($rowCount['count_no']) ? intval($rowCount['count_no']) : 0;

// Check if schedule exists
$scheduleExists = false;
$currentSchedule = [];
$result = pg_query($connection, "SELECT COUNT(*) as count FROM schedules");
$row = pg_fetch_assoc($result);
if ($row && $row['count'] > 0) {
    $scheduleExists = true;
    // Get current schedule details
    $scheduleResult = pg_query($connection, 
        "SELECT distribution_date, location, batch_no, time_slot, COUNT(*) as student_count 
         FROM schedules 
         GROUP BY distribution_date, location, batch_no, time_slot 
         ORDER BY batch_no"
    );
    
    if ($scheduleResult) {
        while ($scheduleRow = pg_fetch_assoc($scheduleResult)) {
            $currentSchedule[] = $scheduleRow;
        }
    }
}

// Get used dates to prevent duplicates
$usedDatesResult = pg_query($connection, "SELECT DISTINCT distribution_date FROM schedules ORDER BY distribution_date");
$usedDates = [];
if ($usedDatesResult) {
    while ($dateRow = pg_fetch_assoc($usedDatesResult)) {
        $usedDates[] = $dateRow['distribution_date'];
    }
}
?>
<?php $page_title='Manage Schedules'; $extra_css=['../../assets/css/admin/table_core.css']; include __DIR__ . '/../../includes/admin/admin_head.php'; ?>
    <style>
        .batch-card {
            border: 2px solid #e9ecef;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        .batch-card:hover {
            border-color: #007bff;
            box-shadow: 0 4px 8px rgba(0,123,255,0.1);
        }
        .time-input {
            border-radius: 6px;
        }
        .batch-header {
            background: linear-gradient(135deg, #007bff, #0056b3);
            color: white;
            border-radius: 6px 6px 0 0;
        }
        .used-date {
            background-color: #f8d7da;
            color: #721c24;
            pointer-events: none;
        }
        .validation-message {
            font-size: 0.875rem;
            margin-top: 0.5rem;
        }
        .floating-total {
            position: sticky;
            top: 20px;
            z-index: 1000;
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
                <h1 class="fw-bold mb-1">Manage Student Schedules</h1>
                <p class="text-muted mb-0">Create flexible scheduling with custom batches and time slots</p>
            </div>

            <!-- Alert Messages -->
            <?php if (isset($success_message)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($success_message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if (isset($error_message)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($error_message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php 
            // Collect toast notifications to show
            $toastNotifications = [];
            
            // Info about publish/unpublish functionality
            if ($scheduleExists) {
                $toastNotifications[] = [
                    'type' => 'info',
                    'icon' => 'info-circle-fill',
                    'title' => 'Schedule Control',
                    'message' => 'Use "Hide from Students" to make schedules invisible without deleting data. Use "Clear All Schedule Data" only if you want to permanently delete everything.',
                    'autoHide' => true,
                    'delay' => 10000
                ];
            }
            
            // Check if unpublishing is allowed
            $can_unpublish = true;
            $unpublish_reason = '';
            
            if ($schedulePublished) {
                // Check if distribution has been completed but not yet compressed
                $uncompressed_snapshot_check = pg_query($connection, 
                    "SELECT snapshot_id, academic_year, semester, finalized_at 
                     FROM distribution_snapshots 
                     WHERE finalized_at IS NOT NULL 
                     AND (files_compressed = FALSE OR files_compressed IS NULL)
                     ORDER BY finalized_at DESC 
                     LIMIT 1"
                );
                
                if ($uncompressed_snapshot_check && pg_num_rows($uncompressed_snapshot_check) > 0) {
                    $can_unpublish = false;
                    $snapshot_data = pg_fetch_assoc($uncompressed_snapshot_check);
                    $formatted_date = date('F j, Y g:i A', strtotime($snapshot_data['finalized_at']));
                    $unpublish_reason = 'Distribution was completed on ' . $formatted_date . ' for ' . $snapshot_data['academic_year'] . ' ' . $snapshot_data['semester'] . ' but files have not been compressed yet. Please go to <a href="end_distribution.php" class="toast-link">End Distribution</a> to compress and archive first.';
                }
                
                // Check if any student has been scanned (only if not already blocked by snapshot check)
                if ($can_unpublish) {
                    $scanned_check = pg_query($connection, "SELECT COUNT(*) as count FROM students WHERE status = 'given'");
                    $scanned_count = 0;
                    if ($scanned_check) {
                        $scanned_data = pg_fetch_assoc($scanned_check);
                        $scanned_count = intval($scanned_data['count']);
                    }
                    
                    if ($scanned_count > 0) {
                        $can_unpublish = false;
                        $unpublish_reason = $scanned_count . ' student(s) have already received their aid. Schedule must remain published for record-keeping.';
                    } else {
                        // Check if we're within 24 hours of distribution start
                        $earliest_date_query = pg_query($connection, "SELECT MIN(distribution_date) as earliest_date FROM schedules");
                        if ($earliest_date_query) {
                            $date_row = pg_fetch_assoc($earliest_date_query);
                            $earliest_date = $date_row['earliest_date'];
                            
                            if ($earliest_date) {
                                $start_timestamp = strtotime($earliest_date);
                                $current_timestamp = time();
                                $time_diff = $start_timestamp - $current_timestamp;
                                $hours_until_start = $time_diff / 3600;
                                
                                if ($hours_until_start < 24 && $hours_until_start >= 0) {
                                    $can_unpublish = false;
                                    $formatted_date = date('F j, Y', $start_timestamp);
                                    $hours_remaining = round($hours_until_start, 1);
                                    $unpublish_reason = 'Distribution starts on ' . $formatted_date . ' (in ' . $hours_remaining . ' hours). Cannot unpublish within 24 hours of start.';
                                }
                            }
                        }
                    }
                }
                
                // Add warning toast if unpublishing is blocked
                if (!$can_unpublish) {
                    $toastNotifications[] = [
                        'type' => 'warning',
                        'icon' => 'lock-fill',
                        'title' => 'Schedule Locked',
                        'message' => $unpublish_reason,
                        'autoHide' => false,
                        'delay' => 0
                    ];
                }
            }
            ?>

            <!-- Stats Card -->
            <div class="row mb-4 g-3">
                <div class="col-md-4">
                    <div class="card border-0 shadow-sm h-100" style="border-radius: 10px; overflow: hidden; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                        <div class="card-body text-center text-white" style="padding: 1.25rem;">
                            <div style="font-size: 2rem; margin-bottom: 0.25rem;">
                                <i class="bi bi-people-fill"></i>
                            </div>
                            <h5 class="mb-1" style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px; opacity: 0.9;">Total Students</h5>
                            <h2 class="mb-0 fw-bold" style="font-size: 1.75rem;"><?= number_format($countStudents) ?></h2>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card border-0 shadow-sm h-100" style="border-radius: 10px; overflow: hidden; background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                        <div class="card-body text-center text-white" style="padding: 1.25rem;">
                            <div style="font-size: 2rem; margin-bottom: 0.25rem;">
                                <i class="bi bi-hash"></i>
                            </div>
                            <h5 class="mb-1" style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px; opacity: 0.9;">Max Payroll Number</h5>
                            <h2 class="mb-0 fw-bold" style="font-size: 1.5rem; word-wrap: break-word;">
                                <?= $maxPayroll ? (is_numeric($maxPayroll) ? number_format((float)$maxPayroll) : htmlspecialchars($maxPayroll)) : '—' ?>
                            </h2>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card border-0 shadow-sm h-100" style="border-radius: 10px; overflow: hidden; background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                        <div class="card-body text-center text-white" style="padding: 1.25rem;">
                            <div style="font-size: 2rem; margin-bottom: 0.25rem;">
                                <i class="bi bi-calendar-check"></i>
                            </div>
                            <h5 class="mb-1" style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px; opacity: 0.9;">Used Dates</h5>
                            <h2 class="mb-0 fw-bold" style="font-size: 1.75rem;"><?= count($usedDates) ?></h2>
                        </div>
                    </div>
                </div>
            </div>
            <?php if ($scheduleExists && !empty($currentSchedule)): ?>
                <!-- Current Schedule Display -->
                <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px; overflow: hidden; border-left: 5px solid <?= $schedulePublished ? '#10b981' : '#f59e0b' ?>;">
                    <div class="card-header" style="background: <?= $schedulePublished ? 'linear-gradient(135deg, #10b981 0%, #059669 100%)' : 'linear-gradient(135deg, #f59e0b 0%, #d97706 100%)' ?>; padding: 1.25rem;">
                        <div class="d-flex flex-column gap-3">
                            <h5 class="mb-0 text-white fw-bold">
                                <i class="bi bi-calendar-check me-2"></i> Current Schedule
                            </h5>
                            <div class="d-flex flex-wrap align-items-center gap-2">
                                <?php if ($schedulePublished): ?>
                                    <span class="badge rounded-pill bg-white text-success fw-semibold px-3 py-2 shadow-sm">
                                        <i class="bi bi-eye me-1"></i> Visible to Students
                                    </span>
                                <?php else: ?>
                                    <span class="badge rounded-pill bg-white text-warning fw-semibold px-3 py-2 shadow-sm">
                                        <i class="bi bi-eye-slash me-1"></i> Hidden from Students
                                    </span>
                                <?php endif; ?>

                                <?php if (!$schedulePublished): ?>
                                    <form method="POST" class="mb-0">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                        <button type="submit" name="publish_schedule" class="btn btn-light fw-bold w-100 w-sm-auto"
                                                style="padding: 0.55rem 1.25rem; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.12); color: #d97706;">
                                            <i class="bi bi-send me-2"></i> Publish
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <?php if ($can_unpublish): ?>
                                        <form method="POST" class="mb-0">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                            <button type="submit" name="unpublish_schedule" class="btn btn-light fw-bold w-100 w-sm-auto"
                                                    style="padding: 0.55rem 1.25rem; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.12); color: #059669;"
                                                    onclick="return confirm('This will hide the schedule from students but preserve all data. Continue?')">
                                                <i class="bi bi-eye-slash me-2"></i> Hide
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="badge rounded-pill bg-light text-muted border fw-semibold px-3 py-2" title="<?= htmlspecialchars($unpublish_reason) ?>">
                                            <i class="bi bi-lock me-1"></i> Locked
                                        </span>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <button type="button" class="btn btn-light fw-bold w-100 w-sm-auto" 
                                        onclick="clearSchedule()"
                                        style="padding: 0.55rem 1.25rem; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.12); color: #dc2626;">
                                    <i class="bi bi-trash me-2"></i> Clear Data
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="card-body" style="background: #f8f9fa; padding: 1.75rem;">
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <div class="info-box" style="background: white; padding: 1.25rem; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
                                    <div class="d-flex align-items-center">
                                        <div class="icon-wrapper me-3" style="width: 50px; height: 50px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                                            <i class="bi bi-calendar3 text-white" style="font-size: 1.5rem;"></i>
                                        </div>
                                        <div>
                                            <small class="text-muted text-uppercase" style="font-size: 0.75rem; letter-spacing: 0.5px;">Distribution Date</small>
                                            <h6 class="mb-0 fw-bold text-dark"><?= htmlspecialchars($currentSchedule[0]['distribution_date'] ?? 'N/A') ?></h6>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="info-box" style="background: white; padding: 1.25rem; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
                                    <div class="d-flex align-items-center">
                                        <div class="icon-wrapper me-3" style="width: 50px; height: 50px; background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                                            <i class="bi bi-geo-alt text-white" style="font-size: 1.5rem;"></i>
                                        </div>
                                        <div>
                                            <small class="text-muted text-uppercase" style="font-size: 0.75rem; letter-spacing: 0.5px;">Location</small>
                                            <h6 class="mb-0 fw-bold text-dark"><?= htmlspecialchars($currentSchedule[0]['location'] ?? 'N/A') ?></h6>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="table-responsive" style="background: white; border-radius: 10px; padding: 1rem; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
                            <table class="table table-hover mb-0">
                                <thead style="background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);">
                                    <tr>
                                        <th style="border: none; padding: 1rem; font-weight: 600; color: #374151;">Batch #</th>
                                        <th style="border: none; padding: 1rem; font-weight: 600; color: #374151;">Time Slot</th>
                                        <th style="border: none; padding: 1rem; font-weight: 600; color: #374151;">Students</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($currentSchedule as $batch): ?>
                                        <tr style="border-bottom: 1px solid #e5e7eb;">
                                            <td style="padding: 1rem;">
                                                <span class="badge" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 0.5rem 1rem; font-size: 0.9rem; border-radius: 8px;">
                                                    Batch <?= htmlspecialchars($batch['batch_no'] ?? '') ?>
                                                </span>
                                            </td>
                                            <td style="padding: 1rem; color: #374151; font-weight: 500;">
                                                <i class="bi bi-clock me-2 text-primary"></i><?= htmlspecialchars($batch['time_slot'] ?? 'N/A') ?>
                                            </td>
                                            <td style="padding: 1rem; color: #374151; font-weight: 500;">
                                                <i class="bi bi-people me-2 text-success"></i><?= number_format($batch['student_count'] ?? 0) ?> students
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <!-- Create New Schedule -->
                <div class="card border-0 shadow-sm" style="border-radius: 12px; overflow: hidden;">
                    <div class="card-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 1.25rem;">
                        <h5 class="mb-0 text-white fw-bold"><i class="bi bi-plus-circle me-2"></i> Create New Schedule</h5>
                    </div>
                    <div class="card-body">
                        <form id="scheduleForm">
                            <!-- Basic Info -->
                            <div class="row mb-4">
                                <div class="col-md-4">
                                    <label for="schedule_date" class="form-label">
                                        <i class="bi bi-calendar3"></i> Start Date
                                    </label>
                                    <input type="date" class="form-control" id="schedule_date" name="schedule_date" 
                                           <?php if ($min_schedule_date): ?>min="<?= $min_schedule_date ?>"<?php endif; ?> required>
                                    <div class="validation-message" id="date-validation"></div>
                                    <?php if ($documents_deadline && $min_schedule_date): ?>
                                        <small class="text-muted">
                                            <i class="bi bi-info-circle"></i> 
                                            Must be at least 5 days after deadline (<?= date('M j', strtotime($documents_deadline)) ?>). 
                                            Earliest: <strong><?= date('M j, Y', strtotime($min_schedule_date)) ?></strong>
                                        </small>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-4">
                                    <label for="end_date" class="form-label">
                                        <i class="bi bi-calendar-check"></i> End Date
                                    </label>
                                    <input type="date" class="form-control" id="end_date" name="end_date" 
                                           <?php if ($min_schedule_date): ?>min="<?= $min_schedule_date ?>"<?php endif; ?> required>
                                    <div class="validation-message" id="end-date-validation"></div>
                                </div>
                                <div class="col-md-4">
                                    <label for="location" class="form-label">
                                        <i class="bi bi-geo-alt"></i> Location
                                    </label>
                                    <input type="text" class="form-control" id="location" name="location" required placeholder="Enter distribution location">
                                </div>
                            </div>

                            <!-- Number of Batches -->
                            <div class="row mb-4">
                                <div class="col-md-4">
                                    <label for="num_batches" class="form-label">
                                        <i class="bi bi-layers"></i> Number of Batches
                                    </label>
                                    <select class="form-control" id="num_batches" name="num_batches" required>
                                        <option value="">Select number of batches</option>
                                        <option value="1">1 Batch</option>
                                        <option value="2">2 Batches</option>
                                        <option value="3">3 Batches</option>
                                        <option value="4">4 Batches</option>
                                        <option value="5">5 Batches</option>
                                        <option value="6">6 Batches</option>
                                    </select>
                                </div>
                                <div class="col-md-8">
                                    <div class="floating-total">
                                        <div class="card bg-light">
                                            <div class="card-body py-2">
                                                <strong>Total Students: <span id="total-students">0</span> / <?= $countStudents ?></strong>
                                                <div class="progress mt-1" style="height: 6px;">
                                                    <div class="progress-bar" id="student-progress" style="width: 0%"></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Batches Container -->
                            <div id="batches-container">
                                <!-- Batches will be dynamically generated here -->
                            </div>

                            <!-- Submit Button -->
                            <div class="text-center mt-4" id="submit-container" style="display: none;">
                                <button type="button" class="btn btn-lg me-2 fw-bold" onclick="createSchedule()"
                                        style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); border: none; color: white; padding: 0.75rem 2.5rem; border-radius: 8px; box-shadow: 0 4px 12px rgba(16,185,129,0.3); transition: all 0.3s ease;"
                                        onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 16px rgba(16,185,129,0.4)';"
                                        onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 12px rgba(16,185,129,0.3)';">
                                    <i class="bi bi-calendar-plus me-2"></i> Create Schedule
                                </button>
                                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="debugBatches()"
                                        style="padding: 0.5rem 1.5rem; border-radius: 8px;">
                                    <i class="bi bi-bug me-2"></i> Debug Info
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Used Dates Display -->
            <?php if (!empty($usedDates)): ?>
                <div class="card border-0 shadow-sm mt-4" style="border-radius: 12px; overflow: hidden; border-left: 5px solid #f59e0b;">
                    <div class="card-header" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); padding: 1.25rem;">
                        <h6 class="mb-0 text-white fw-bold"><i class="bi bi-exclamation-triangle me-2"></i> Previously Used Dates</h6>
                    </div>
                    <div class="card-body" style="background: white; padding: 1.75rem;">
                        <div class="row g-3">
                            <?php foreach ($usedDates as $date): ?>
                                <div class="col-md-2 col-sm-3 col-6">
                                    <span class="badge w-100" style="background: linear-gradient(135deg, #6b7280 0%, #4b5563 100%); padding: 0.75rem; font-size: 0.9rem; border-radius: 8px; box-shadow: 0 2px 6px rgba(0,0,0,0.1);">
                                        <i class="bi bi-calendar-x me-1"></i><?= htmlspecialchars($date) ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../../assets/js/admin/sidebar.js"></script>

<script>
const maxStudents = <?= $countStudents ?>;
const usedDates = <?= json_encode($usedDates) ?>;
const documentsDeadline = <?= $documents_deadline ? "'" . $documents_deadline . "'" : 'null' ?>;
const minScheduleDate = <?= $min_schedule_date ? "'" . $min_schedule_date . "'" : 'null' ?>;
let batches = [];

// Date validation for start date
document.getElementById('schedule_date')?.addEventListener('change', function() {
    const selectedDate = this.value;
    const endDateInput = document.getElementById('end_date');
    const validation = document.getElementById('date-validation');
    
    // Check if date is before minimum schedule date
    if (minScheduleDate && selectedDate < minScheduleDate) {
        const deadlineFormatted = new Date(documentsDeadline).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        const minDateFormatted = new Date(minScheduleDate).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        validation.innerHTML = `<span class="text-danger"><i class="bi bi-exclamation-triangle"></i> Schedule must be at least 5 days after document deadline (${deadlineFormatted}). Earliest: ${minDateFormatted}</span>`;
        this.classList.add('is-invalid');
        return;
    }
    
    // Set end date to same as start date if not set
    if (!endDateInput.value) {
        endDateInput.value = selectedDate;
    }
    
    // Set minimum end date
    endDateInput.min = selectedDate;
    
    if (usedDates.includes(selectedDate)) {
        validation.innerHTML = '<span class="text-danger"><i class="bi bi-exclamation-triangle"></i> This date has already been used for scheduling</span>';
        this.classList.add('is-invalid');
    } else {
        validation.innerHTML = '<span class="text-success"><i class="bi bi-check-circle"></i> Date is available</span>';
        this.classList.remove('is-invalid');
        this.classList.add('is-valid');
    }
});

// Date validation for end date
document.getElementById('end_date')?.addEventListener('change', function() {
    const selectedDate = this.value;
    const startDate = document.getElementById('schedule_date').value;
    const validation = document.getElementById('end-date-validation');
    
    // Check if date is before minimum schedule date
    if (minScheduleDate && selectedDate < minScheduleDate) {
        const minDateFormatted = new Date(minScheduleDate).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        validation.innerHTML = `<span class="text-danger"><i class="bi bi-exclamation-triangle"></i> Must be at least ${minDateFormatted}</span>`;
        this.classList.add('is-invalid');
        return;
    }
    
    if (selectedDate < startDate) {
        validation.innerHTML = '<span class="text-danger"><i class="bi bi-exclamation-triangle"></i> End date cannot be before start date</span>';
        this.classList.add('is-invalid');
    } else if (usedDates.includes(selectedDate)) {
        validation.innerHTML = '<span class="text-warning"><i class="bi bi-exclamation-triangle"></i> This date has already been used</span>';
        this.classList.add('is-invalid');
    } else {
        validation.innerHTML = '<span class="text-success"><i class="bi bi-check-circle"></i> Date is available</span>';
        this.classList.remove('is-invalid');
        this.classList.add('is-valid');
    }
});

// Generate batches when number is selected
document.getElementById('num_batches')?.addEventListener('change', function() {
    const numBatches = parseInt(this.value);
    generateBatches(numBatches);
});

function generateBatches(numBatches) {
    const container = document.getElementById('batches-container');
    const submitContainer = document.getElementById('submit-container');
    
    container.innerHTML = '';
    batches = [];
    
    for (let i = 0; i < numBatches; i++) {
        batches.push({
            start_time: '',
            end_time: '',
            capacity: 0
        });
        
        const batchCard = createBatchCard(i, numBatches);
        container.appendChild(batchCard);
    }
    
    submitContainer.style.display = 'block';
    updateTotalStudents();
}

function createBatchCard(batchIndex, totalBatches) {
    const card = document.createElement('div');
    card.className = 'batch-card mb-3 p-3';
    
    // Suggest default times
    const suggestedStartTime = getSuggestedTime(batchIndex, totalBatches, 'start');
    const suggestedEndTime = getSuggestedTime(batchIndex, totalBatches, 'end');
    const suggestedCapacity = Math.ceil(maxStudents / totalBatches);
    
    card.innerHTML = `
        <div class="batch-header p-2 mb-3">
            <h6 class="mb-0"><i class="bi bi-clock"></i> Batch ${batchIndex + 1}</h6>
        </div>
        <div class="row">
            <div class="col-md-3">
                <label class="form-label">Start Time</label>
                <input type="time" class="form-control time-input" 
                       id="start_time_${batchIndex}" 
                       value="${suggestedStartTime}"
                       min="06:00" max="17:00" step="900"
                       onchange="updateBatch(${batchIndex}, 'start_time', this.value)">
            </div>
            <div class="col-md-3">
                <label class="form-label">End Time</label>
                <input type="time" class="form-control time-input" 
                       id="end_time_${batchIndex}" 
                       value="${suggestedEndTime}"
                       min="06:00" max="17:00" step="900"
                       onchange="updateBatch(${batchIndex}, 'end_time', this.value)">
            </div>
            <div class="col-md-3">
                <label class="form-label">Students in Batch</label>
                <input type="number" class="form-control" 
                       id="capacity_${batchIndex}" 
                       value="${suggestedCapacity}"
                       min="1" max="${maxStudents}"
                       onchange="updateBatch(${batchIndex}, 'capacity', parseInt(this.value))">
            </div>
            <div class="col-md-3">
                <label class="form-label">Duration</label>
                <div class="form-control-static">
                    <span id="duration_${batchIndex}" class="badge bg-info">--</span>
                </div>
            </div>
        </div>
        <div class="mt-2">
            <small class="text-muted" id="time_validation_${batchIndex}"></small>
        </div>
    `;
    
    // Initialize batch data
    batches[batchIndex] = {
        start_time: suggestedStartTime,
        end_time: suggestedEndTime,
        capacity: suggestedCapacity
    };
    
    return card;
}

function getSuggestedTime(batchIndex, totalBatches, type) {
    const startHour = 8; // 8 AM start
    const endHour = 17; // 5 PM end
    const totalHours = endHour - startHour;
    const hoursPerBatch = totalHours / totalBatches;
    
    if (type === 'start') {
        const hour = Math.floor(startHour + (batchIndex * hoursPerBatch));
        return `${hour.toString().padStart(2, '0')}:00`;
    } else {
        const hour = Math.floor(startHour + ((batchIndex + 1) * hoursPerBatch));
        return `${hour.toString().padStart(2, '0')}:00`;
    }
}

function updateBatch(batchIndex, field, value) {
    // Ensure batches array exists and has the right length
    if (!batches[batchIndex]) {
        batches[batchIndex] = {};
    }
    
    batches[batchIndex][field] = value;
    
    console.log(`Updated batch ${batchIndex}, field ${field} to:`, value);
    console.log('Current batches:', batches);
    
    if (field === 'start_time' || field === 'end_time') {
        updateDuration(batchIndex);
        validateTimeSlot(batchIndex);
    }
    
    if (field === 'capacity') {
        updateTotalStudents();
    }
}

function updateDuration(batchIndex) {
    const batch = batches[batchIndex];
    if (batch.start_time && batch.end_time) {
        const start = new Date(`2000-01-01 ${batch.start_time}`);
        const end = new Date(`2000-01-01 ${batch.end_time}`);
        const diffMs = end - start;
        const diffHours = diffMs / (1000 * 60 * 60);
        
        const durationElement = document.getElementById(`duration_${batchIndex}`);
        if (diffHours > 0) {
            durationElement.textContent = `${diffHours.toFixed(1)}h`;
            durationElement.className = 'badge bg-info';
        } else {
            durationElement.textContent = 'Invalid';
            durationElement.className = 'badge bg-danger';
        }
    }
}

function validateTimeSlot(batchIndex) {
    const batch = batches[batchIndex];
    const validationElement = document.getElementById(`time_validation_${batchIndex}`);
    
    if (!batch.start_time || !batch.end_time) {
        validationElement.innerHTML = '';
        return;
    }
    
    const start = new Date(`2000-01-01 ${batch.start_time}`);
    const end = new Date(`2000-01-01 ${batch.end_time}`);
    
    if (end <= start) {
        validationElement.innerHTML = '<span class="text-danger"><i class="bi bi-exclamation-triangle"></i> End time must be after start time</span>';
        return;
    }
    
    // Check for overlaps with other batches
    for (let i = 0; i < batches.length; i++) {
        if (i === batchIndex) continue;
        
        const otherBatch = batches[i];
        if (otherBatch.start_time && otherBatch.end_time) {
            const otherStart = new Date(`2000-01-01 ${otherBatch.start_time}`);
            const otherEnd = new Date(`2000-01-01 ${otherBatch.end_time}`);
            
            if ((start < otherEnd && end > otherStart)) {
                validationElement.innerHTML = `<span class="text-warning"><i class="bi bi-exclamation-triangle"></i> Time overlaps with Batch ${i + 1}</span>`;
                return;
            }
        }
    }
    
    validationElement.innerHTML = '<span class="text-success"><i class="bi bi-check-circle"></i> Time slot is valid</span>';
}

function updateTotalStudents() {
    const total = batches.reduce((sum, batch) => sum + (batch.capacity || 0), 0);
    const totalElement = document.getElementById('total-students');
    const progressElement = document.getElementById('student-progress');
    
    if (totalElement) {
        totalElement.textContent = total.toLocaleString();
        
        const percentage = (total / maxStudents) * 100;
        progressElement.style.width = `${Math.min(percentage, 100)}%`;
        
        if (total > maxStudents) {
            progressElement.className = 'progress-bar bg-danger';
            totalElement.classList.add('text-danger');
        } else if (total === maxStudents) {
            progressElement.className = 'progress-bar bg-success';
            totalElement.classList.remove('text-danger');
            totalElement.classList.add('text-success');
        } else {
            progressElement.className = 'progress-bar bg-primary';
            totalElement.classList.remove('text-danger', 'text-success');
        }
    }
}

function createSchedule() {
    const startDateInput = document.getElementById('schedule_date');
    const endDateInput = document.getElementById('end_date');
    const locationInput = document.getElementById('location');
    const numBatchesSelect = document.getElementById('num_batches');
    
    // Create error display area if it doesn't exist
    let errorDisplay = document.getElementById('error-display');
    if (!errorDisplay) {
        errorDisplay = document.createElement('div');
        errorDisplay.id = 'error-display';
        errorDisplay.className = 'alert alert-danger mt-3';
        errorDisplay.style.display = 'none';
        document.getElementById('scheduleForm').appendChild(errorDisplay);
    }
    
    function showError(message) {
        errorDisplay.innerHTML = `<i class="bi bi-exclamation-triangle-fill"></i> ${message}`;
        errorDisplay.style.display = 'block';
        errorDisplay.scrollIntoView({ behavior: 'smooth' });
        return false;
    }
    
    console.log('Creating schedule...');
    console.log('Start Date:', startDateInput.value);
    console.log('End Date:', endDateInput.value);
    console.log('Location:', locationInput.value);
    console.log('Number of batches:', numBatchesSelect.value);
    console.log('Batches array:', batches);
    
    // Hide previous errors
    errorDisplay.style.display = 'none';
    
    if (!startDateInput.value || !endDateInput.value || !locationInput.value) {
        return showError('Please fill in start date, end date, and location');
    }
    
    if (!numBatchesSelect.value) {
        return showError('Please select the number of batches');
    }
    
    if (endDateInput.value < startDateInput.value) {
        return showError('End date cannot be before start date');
    }
    
    // Check if start date is before minimum schedule date
    if (minScheduleDate && startDateInput.value < minScheduleDate) {
        const deadlineFormatted = new Date(documentsDeadline).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
        const minDateFormatted = new Date(minScheduleDate).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
        return showError(`Distribution schedules must be at least 5 days after the document submission deadline (${deadlineFormatted}). Earliest allowed date: ${minDateFormatted}`);
    }
    
    // Check if any date in range is already used
    const startDate = new Date(startDateInput.value);
    const endDate = new Date(endDateInput.value);
    const conflictingDates = [];
    
    for (let d = new Date(startDate); d <= endDate; d.setDate(d.getDate() + 1)) {
        const dateStr = d.toISOString().split('T')[0];
        if (usedDates.includes(dateStr)) {
            conflictingDates.push(dateStr);
        }
    }
    
    if (conflictingDates.length > 0) {
        return showError(`The following dates are already used: ${conflictingDates.join(', ')}. Please choose different dates.`);
    }
    
    // Ensure batches array is populated
    if (!batches || batches.length === 0) {
        return showError('Please configure the batches first by selecting the number of batches');
    }
    
    const totalStudents = batches.reduce((sum, batch) => sum + (batch.capacity || 0), 0);
    if (totalStudents > maxStudents) {
        return showError(`Total students (${totalStudents}) exceeds available students (${maxStudents})`);
    }
    
    if (totalStudents === 0) {
        return showError('Please set the number of students for each batch');
    }
    
    // Validate all batches
    for (let i = 0; i < batches.length; i++) {
        const batch = batches[i];
        if (!batch.start_time || !batch.end_time || !batch.capacity || batch.capacity <= 0) {
            return showError(`Please complete all fields for Batch ${i + 1}`);
        }
        
        // Validate time order
        if (batch.start_time >= batch.end_time) {
            return showError(`Batch ${i + 1}: End time must be after start time`);
        }
    }
    
    const scheduleData = {
        start_date: startDateInput.value,
        end_date: endDateInput.value,
        location: locationInput.value,
        batches: batches
    };
    
    console.log('Final schedule data:', scheduleData);
    console.log('JSON data being sent:', JSON.stringify(scheduleData));
    
    // Show confirmation before submitting
    const dateRange = (scheduleData.start_date === scheduleData.end_date) 
        ? scheduleData.start_date 
        : `${scheduleData.start_date} to ${scheduleData.end_date}`;
    
    if (!confirm(`Create schedule for ${dateRange} at ${scheduleData.location} with ${batches.length} batches and ${totalStudents} total students?`)) {
        return false;
    }
    
    // Submit form
    const form = document.createElement('form');
    form.method = 'POST';
    
    // Create hidden input for create_schedule
    const createInput = document.createElement('input');
    createInput.type = 'hidden';
    createInput.name = 'create_schedule';
    createInput.value = '1';
    form.appendChild(createInput);
    
    // Create hidden input for schedule_data
    const dataInput = document.createElement('input');
    dataInput.type = 'hidden';
    dataInput.name = 'schedule_data';
    dataInput.value = JSON.stringify(scheduleData);
    form.appendChild(dataInput);
    
    // Add CSRF token
    const csrfInput = document.createElement('input');
    csrfInput.type = 'hidden';
    csrfInput.name = 'csrf_token';
    csrfInput.value = '<?= htmlspecialchars($csrfToken) ?>';
    form.appendChild(csrfInput);
    
    document.body.appendChild(form);
    console.log('Submitting form with data:', dataInput.value);
    form.submit();
}

function clearSchedule() {
    if (confirm('⚠️ DANGER: This will permanently delete ALL schedule data from the database.\n\nThis action cannot be undone. Are you absolutely sure?')) {
        if (confirm('Last confirmation: This will DELETE all schedules permanently. Continue?')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = '<input type="hidden" name="clear_schedule_data" value="1"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">';
            document.body.appendChild(form);
            form.submit();
        }
    }
}

function debugBatches() {
    const startDateInput = document.getElementById('schedule_date');
    const endDateInput = document.getElementById('end_date');
    const locationInput = document.getElementById('location');
    const numBatchesSelect = document.getElementById('num_batches');
    
    console.log('=== DEBUG INFO ===');
    console.log('Start Date:', startDateInput ? startDateInput.value : 'NOT FOUND');
    console.log('End Date:', endDateInput ? endDateInput.value : 'NOT FOUND');
    console.log('Location:', locationInput ? locationInput.value : 'NOT FOUND');
    console.log('Number of batches selected:', numBatchesSelect ? numBatchesSelect.value : 'NOT FOUND');
    console.log('Batches array:', batches);
    console.log('Batches length:', batches ? batches.length : 'UNDEFINED');
    console.log('Max students:', maxStudents);
    console.log('Used dates:', usedDates);
    
    // Test JSON serialization
    if (batches && batches.length > 0) {
        const testData = {
            start_date: startDateInput ? startDateInput.value : '',
            end_date: endDateInput ? endDateInput.value : '',
            location: locationInput ? locationInput.value : '',
            batches: batches
        };
        console.log('Test JSON serialization:', JSON.stringify(testData));
    }
    
    alert(`Debug Info:\nBatches: ${batches ? batches.length : 'UNDEFINED'}\nStart Date: ${startDateInput ? startDateInput.value : 'NOT FOUND'}\nEnd Date: ${endDateInput ? endDateInput.value : 'NOT FOUND'}\nLocation: ${locationInput ? locationInput.value : 'NOT FOUND'}\n\nCheck console for detailed info.`);
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    // Set minimum date based on document deadline or today
    const today = new Date().toISOString().split('T')[0];
    const minDate = minScheduleDate || today;
    
    const startDateInput = document.getElementById('schedule_date');
    const endDateInput = document.getElementById('end_date');
    
    if (startDateInput) {
        startDateInput.min = minDate;
    }
    
    if (endDateInput) {
        endDateInput.min = minDate;
    }
    
    // Show info message if deadline is set
    if (documentsDeadline && minScheduleDate) {
        console.log(`Document Deadline: ${documentsDeadline}`);
        console.log(`Minimum Schedule Date (Deadline + 5 days): ${minScheduleDate}`);
    }
});
</script>

<!-- Toast Container -->
<div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 1080; gap: 12px;">
    <?php if (!empty($toastNotifications)): ?>
        <?php foreach ($toastNotifications as $index => $toast): ?>
            <div class="toast schedule-toast toast-<?= $toast['type'] ?>" 
                 role="alert" 
                 aria-live="assertive" 
                 aria-atomic="true"
                 data-bs-autohide="<?= $toast['autoHide'] ? 'true' : 'false' ?>"
                 <?= $toast['autoHide'] ? 'data-bs-delay="' . $toast['delay'] . '"' : '' ?>
                 id="scheduleToast<?= $index ?>">
                <div class="toast-header">
                    <i class="bi bi-<?= $toast['icon'] ?> me-2 toast-icon"></i>
                    <strong class="me-auto"><?= htmlspecialchars($toast['title']) ?></strong>
                    <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
                <div class="toast-body">
                    <?= $toast['message'] ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<style>
/* Toast Notification Styles */
.schedule-toast {
    min-width: 350px;
    max-width: 420px;
    border-radius: 10px;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.12);
    border: 1px solid #e0e0e0;
    overflow: hidden;
    background: #ffffff;
    margin-bottom: 12px;
}

.schedule-toast .toast-header {
    padding: 10px 14px;
    background: #f8f9fa;
    border-bottom: 1px solid #e9ecef;
    color: #495057;
}

.schedule-toast .toast-header .btn-close {
    opacity: 0.5;
}

.schedule-toast .toast-header .btn-close:hover {
    opacity: 1;
}

.schedule-toast .toast-body {
    padding: 12px 14px;
    font-size: 0.875rem;
    line-height: 1.5;
    color: #6c757d;
    background: #ffffff;
}

/* Info Toast - Neutral with subtle blue accent */
.toast-info {
    border-left: 3px solid #6c757d;
}

.toast-info .toast-icon {
    color: #6c757d;
}

/* Warning Toast - Neutral with subtle amber accent */
.toast-warning {
    border-left: 3px solid #ffc107;
}

.toast-warning .toast-icon {
    color: #e6a000;
}

/* Toast link styling */
.toast-link {
    color: #495057;
    font-weight: 600;
    text-decoration: underline;
}

.toast-link:hover {
    color: #212529;
}

/* Animation */
.schedule-toast.showing,
.schedule-toast.show {
    animation: slideInRight 0.3s ease-out;
}

@keyframes slideInRight {
    from {
        transform: translateX(100%);
        opacity: 0;
    }
    to {
        transform: translateX(0);
        opacity: 1;
    }
}

/* Mobile responsiveness */
@media (max-width: 576px) {
    .toast-container {
        left: 0 !important;
        right: 0 !important;
        padding: 1rem !important;
    }
    
    .schedule-toast {
        min-width: unset;
        max-width: 100%;
        width: 100%;
    }
}
</style>

<script>
// Initialize and show toasts on page load
document.addEventListener('DOMContentLoaded', function() {
    const toasts = document.querySelectorAll('.schedule-toast');
    toasts.forEach((toastEl, index) => {
        const toast = new bootstrap.Toast(toastEl);
        // Stagger the appearance slightly
        setTimeout(() => {
            toast.show();
        }, index * 200);
    });
});
</script>

</body>
</html>
<?php
if (isset($connection) && $connection instanceof \PgSql\Connection) {
    pg_close($connection);
}
?>