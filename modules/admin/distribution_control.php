<?php
/**
 * Distribution Control Center
 * Main hub for managing distribution lifecycle
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['admin_username'])) {
    header("Location: ../../unified_login.php");
    exit;
}

include __DIR__ . '/../../config/database.php';
include __DIR__ . '/../../includes/permissions.php';
include __DIR__ . '/../../includes/workflow_control.php';
include __DIR__ . '/../../includes/CSRFProtection.php';

// Check if user is super admin
$admin_role = getCurrentAdminRole($connection);
if ($admin_role !== 'super_admin') {
    header("Location: dashboard.php");
    exit;
}

// Auto-migrate existing distribution if needed
$migration_needed = false;
$distribution_status_check = pg_query($connection, "SELECT value FROM config WHERE key = 'distribution_status'");
if ($distribution_status_check && ($status_row = pg_fetch_assoc($distribution_status_check))) {
    $current_status = $status_row['value'];
    if (in_array($current_status, ['preparing', 'active'])) {
        // Check if academic period is missing
        $period_check = pg_query($connection, "SELECT key FROM config WHERE key IN ('current_academic_year', 'current_semester')");
        $existing_keys = [];
        if ($period_check) {
            while ($key_row = pg_fetch_assoc($period_check)) {
                $existing_keys[] = $key_row['key'];
            }
        }
        
        if (!in_array('current_academic_year', $existing_keys) || !in_array('current_semester', $existing_keys)) {
            $migration_needed = true;
            
            // Set default academic period for existing distribution
            $current_year = date('Y');
            $current_month = date('n');
            
            // Determine semester based on current date
            if ($current_month >= 8 || $current_month <= 12) {
                $default_semester = '1st Semester';
                $default_academic_year = $current_year . '-' . ($current_year + 1);
            } else {
                $default_semester = '2nd Semester'; 
                $default_academic_year = ($current_year - 1) . '-' . $current_year;
            }
            
            // Insert missing config keys
            if (!in_array('current_academic_year', $existing_keys)) {
                pg_query_params($connection, "INSERT INTO config (key, value) VALUES ($1, $2)", 
                              ['current_academic_year', $default_academic_year]);
            }
            if (!in_array('current_semester', $existing_keys)) {
                pg_query_params($connection, "INSERT INTO config (key, value) VALUES ($1, $2)", 
                              ['current_semester', $default_semester]);
            }
        }
    }
}

$workflow_status = getWorkflowStatus($connection);
$student_counts = getStudentCounts($connection);

// Ensure student_counts has all required keys
$student_counts = array_merge([
    'total_students' => 0,
    'active_count' => 0,
    'applicant_count' => 0,
    'verified_students' => 0,
    'pending_verification' => 0
], $student_counts);

// Extract status variables for easy access
$distribution_status = $workflow_status['distribution_status'] ?? 'inactive';
$uploads_enabled = $workflow_status['uploads_enabled'] ?? false;

// Get current academic period and documents deadline
$current_academic_year = '';
$current_semester = '';
$documents_deadline = '';
$period_query = "SELECT key, value FROM config WHERE key IN ('current_academic_year', 'current_semester', 'documents_deadline')";
$period_result = pg_query($connection, $period_query);
if ($period_result) {
    while ($row = pg_fetch_assoc($period_result)) {
        if ($row['key'] === 'current_academic_year') {
            $current_academic_year = $row['value'];
        } elseif ($row['key'] === 'current_semester') {
            $current_semester = $row['value'];
        } elseif ($row['key'] === 'documents_deadline') {
            $documents_deadline = $row['value'];
        }
    }
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Validate CSRF token - don't consume it to allow retries if modal is shown
    if (!isset($_POST['csrf_token']) || !CSRFProtection::validateToken('distribution_control', $_POST['csrf_token'], false)) {
        $success = false;
        $message = 'Invalid security token. Please refresh the page and try again.';
    } else {
        $action = $_POST['action'];
        $success = false;
        $message = '';
    
    switch ($action) {
        case 'start_distribution':
            // Validate required fields
            $academic_year = trim($_POST['academic_year'] ?? '');
            $semester = trim($_POST['semester'] ?? '');
            $documents_deadline = trim($_POST['documents_deadline'] ?? '');
            
            if (empty($academic_year) || empty($semester)) {
                $message = 'Academic year and semester are required to start distribution.';
                break;
            }
            
            // Check if graduating students were reviewed (if applicable)
            $graduates_reviewed = isset($_POST['graduates_reviewed']) && $_POST['graduates_reviewed'] === '1';
            $archive_graduates = isset($_POST['archive_graduates']) && $_POST['archive_graduates'] === '1';
            
            // Detect if this is a NEW academic year (not just new semester)
            $previous_academic_year = $current_academic_year;
            $is_new_academic_year = !empty($previous_academic_year) && $previous_academic_year !== $academic_year;
            
            // Check for graduating students even when restarting the same year
            // to catch any graduates who weren't archived yet
            $should_check_graduates = ($is_new_academic_year || empty($previous_academic_year)) && !$graduates_reviewed;
            
            // If starting distribution, check for graduating students from previous years
            if ($should_check_graduates) {
                // Query graduating students from previous academic year OR EARLIER
                // This catches students who graduated 1-2 years ago but are still in the system
                $graduates_query = pg_query_params($connection,
                    "SELECT student_id, first_name, last_name, current_year_level, status_academic_year, email
                     FROM students
                     WHERE is_graduating = TRUE
                       AND status_academic_year < $1
                       AND status IN ('active', 'applicant')
                       AND (is_archived = FALSE OR is_archived IS NULL)
                     ORDER BY status_academic_year DESC, current_year_level, last_name",
                    [$academic_year]
                );
                
                if ($graduates_query && pg_num_rows($graduates_query) > 0) {
                    $graduates = [];
                    while ($grad = pg_fetch_assoc($graduates_query)) {
                        $graduates[] = $grad;
                    }
                    
                    // Store graduates in session for review modal
                    $_SESSION['pending_graduates'] = $graduates;
                    $_SESSION['new_distribution_data'] = [
                        'academic_year' => $academic_year,
                        'semester' => $semester,
                        'documents_deadline' => $documents_deadline
                    ];
                    
                    // Check if this is an AJAX request (from JavaScript)
                    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                        // Return JSON response for AJAX
                        header('Content-Type: application/json');
                        echo json_encode([
                            'requires_review' => true,
                            'graduate_count' => count($graduates),
                            'previous_year' => $previous_academic_year,
                            'new_year' => $academic_year
                        ]);
                        exit;
                    } else {
                        // For non-AJAX, set flag to show modal via JavaScript
                        $_SESSION['show_graduate_modal'] = [
                            'requires_review' => true,
                            'graduate_count' => count($graduates),
                            'previous_year' => $previous_academic_year,
                            'new_year' => $academic_year
                        ];
                        
                        // Redirect to self to show modal
                        header("Location: " . $_SERVER['PHP_SELF']);
                        exit;
                    }
                }
            }
            
            // If graduates were reviewed and admin chose to archive them
            if ($archive_graduates && isset($_SESSION['pending_graduates'])) {
                $graduates = $_SESSION['pending_graduates'];
                $archived_count = 0;
                $admin_id = $_SESSION['admin_id'] ?? null;
                
                foreach ($graduates as $graduate) {
                    $archive_query = "UPDATE students 
                                     SET is_archived = TRUE,
                                         archived_at = NOW(),
                                         archived_by = $1,
                                         archive_reason = $2,
                                         status = 'archived'
                                     WHERE student_id = $3";
                    
                    $archive_reason = "Graduated - Completed {$graduate['current_year_level']} in A.Y. {$graduate['status_academic_year']}";
                    
                    $archive_result = pg_query_params($connection, $archive_query, [
                        $admin_id,
                        $archive_reason,
                        $graduate['student_id']
                    ]);
                    
                    if ($archive_result) {
                        $archived_count++;
                        
                        // Log in student_status_history
                        $history_query = "INSERT INTO student_status_history 
                                         (student_id, year_level, is_graduating, academic_year, updated_at, update_source, notes)
                                         VALUES ($1, $2, $3, $4, NOW(), 'admin_graduate_archive', $5)";
                        
                        pg_query_params($connection, $history_query, [
                            $graduate['student_id'],
                            $graduate['current_year_level'],
                            'true',
                            $graduate['status_academic_year'],
                            "Archived as graduated student when starting new distribution for A.Y. {$academic_year}"
                        ]);
                    }
                }
                
                // Clear session data
                unset($_SESSION['pending_graduates']);
                unset($_SESSION['new_distribution_data']);
                
                error_log("Archived {$archived_count} graduating students before starting distribution for {$academic_year}");
            }
            
            // CONSTRAINT 1: Check if a finalized distribution already exists for this academic year/semester
            $duplicate_check = pg_query_params($connection, 
                "SELECT snapshot_id, finalized_at, distribution_date 
                 FROM distribution_snapshots 
                 WHERE academic_year = $1 AND semester = $2 AND finalized_at IS NOT NULL
                 LIMIT 1",
                [$academic_year, $semester]
            );
            
            if ($duplicate_check && pg_num_rows($duplicate_check) > 0) {
                $existing = pg_fetch_assoc($duplicate_check);
                $finalized_date = date('F j, Y', strtotime($existing['finalized_at']));
                $message = "A distribution for <strong>$semester $academic_year</strong> has already been completed and finalized on $finalized_date. You cannot create a duplicate distribution for the same academic period. Please use a different academic year or semester.";
                break;
            }
            
            // CONSTRAINT 2: Check if year level advancement is needed before starting new distribution
            // Get current academic year from the system
            $current_year_query = pg_query($connection, "SELECT year_code FROM academic_years WHERE is_current = TRUE LIMIT 1");
            $current_sys_year = null;
            if ($current_year_query && pg_num_rows($current_year_query) > 0) {
                $year_row = pg_fetch_assoc($current_year_query);
                $current_sys_year = $year_row['year_code'];
            }
            
            // Check if both semesters of the CURRENT academic year have been finalized
            if ($current_sys_year) {
                $completed_semesters_query = pg_query_params($connection,
                    "SELECT semester, finalized_at 
                     FROM distribution_snapshots 
                     WHERE academic_year = $1 
                     AND finalized_at IS NOT NULL
                     ORDER BY semester",
                    [$current_sys_year]
                );
                
                $completed_semesters = [];
                if ($completed_semesters_query) {
                    while ($sem_row = pg_fetch_assoc($completed_semesters_query)) {
                        $completed_semesters[] = $sem_row['semester'];
                    }
                }
                
            }
            
            // CONSTRAINT 3: Validate documents deadline must be after the last finalized distribution
            if (!empty($documents_deadline)) {
                $d = DateTime::createFromFormat('Y-m-d', $documents_deadline);
                $dateValid = $d && $d->format('Y-m-d') === $documents_deadline;
                if (!$dateValid) {
                    $message = 'Invalid documents deadline date. Use a valid date (YYYY-MM-DD).';
                    break;
                }
                
                // Check against last finalized distribution date
                $last_distribution_query = pg_query($connection,
                    "SELECT distribution_date, academic_year, semester, finalized_at
                     FROM distribution_snapshots 
                     WHERE finalized_at IS NOT NULL 
                     ORDER BY finalized_at DESC 
                     LIMIT 1"
                );
                
                if ($last_distribution_query && pg_num_rows($last_distribution_query) > 0) {
                    $last_dist = pg_fetch_assoc($last_distribution_query);
                    $last_dist_date = $last_dist['distribution_date'];
                    
                    // Document deadline must be AFTER the last distribution date
                    if (strtotime($documents_deadline) <= strtotime($last_dist_date)) {
                        $formatted_last_date = date('F j, Y', strtotime($last_dist_date));
                        $formatted_deadline = date('F j, Y', strtotime($documents_deadline));
                        $message = "Document submission deadline (<strong>$formatted_deadline</strong>) must be after the last finalized distribution date (<strong>$formatted_last_date</strong> for {$last_dist['semester']} {$last_dist['academic_year']}). Please choose a later deadline date.";
                        break;
                    }
                }
            }
            
            // Validate academic year format
            if (!preg_match('/^\d{4}-\d{4}$/', $academic_year)) {
                $message = 'Invalid academic year format. Use YYYY-YYYY (e.g., 2025-2026).';
                break;
            }
            
            // Validate year logic
            $year_parts = explode('-', $academic_year);
            if (intval($year_parts[1]) !== intval($year_parts[0]) + 1) {
                $message = 'Invalid academic year. End year must be exactly one year after start year.';
                break;
            }
            
            // Set distribution status to preparing
            $begin_result = pg_query($connection, "BEGIN");
            if (!$begin_result) {
                $message = 'Failed to start transaction: ' . pg_last_error($connection);
                break;
            }
            
            try {
                // Store academic period for this distribution
                // Set status directly to 'active' for simplified workflow
                $config_settings = [
                    ['distribution_status', 'active'],
                    ['current_academic_year', $academic_year],
                    ['current_semester', $semester],
                    ['uploads_enabled', '1']
                ];
                if (!empty($documents_deadline)) {
                    $config_settings[] = ['documents_deadline', $documents_deadline];
                }
                
                foreach ($config_settings as [$key, $value]) {
                    $query = "INSERT INTO config (key, value) VALUES ($1, $2) ON CONFLICT (key) DO UPDATE SET value = $2";
                    $result = pg_query_params($connection, $query, [$key, $value]);
                    if (!$result) {
                        throw new Exception("Failed to update configuration key '$key': " . pg_last_error($connection));
                    }
                }
                
                // CRITICAL: Flag all existing students for document re-upload
                // Students who registered in previous distributions need to upload fresh documents
                $flag_reupload = pg_query($connection, "
                    UPDATE students 
                    SET needs_document_upload = TRUE,
                        documents_to_reupload = '[\"00\",\"01\",\"02\",\"03\",\"04\"]'::jsonb
                    WHERE status IN ('applicant', 'active')
                    AND (is_archived = FALSE OR is_archived IS NULL)
                ");
                
                if (!$flag_reupload) {
                    throw new Exception("Failed to flag students for re-upload: " . pg_last_error($connection));
                }
                
                $students_flagged = pg_affected_rows($flag_reupload);
                error_log("Distribution Start: Flagged $students_flagged existing students for document re-upload");
                
                // CRITICAL: Unlock student list for new distribution cycle
                // This allows admin to lock the list again and generate new payroll numbers
                pg_query($connection, "
                    INSERT INTO config (key, value) VALUES ('student_list_finalized', '0')
                    ON CONFLICT (key) DO UPDATE SET value = '0'
                ");
                error_log("Distribution Start: Unlocked student list for new distribution cycle");
                
                pg_query($connection, "COMMIT");
                $success = true;
                $message = "Distribution cycle started for $semester $academic_year!"
                    . (!empty($documents_deadline) ? " Document deadline set to $documents_deadline." : "")
                    . " The distribution is now active and all features are unlocked!"
                    . " $students_flagged existing student(s) flagged for document re-upload."
                    . " Student list has been unlocked for new payroll generation.";
                
                // Send email notifications to all applicants
                require_once __DIR__ . '/../../src/Services/DistributionEmailService.php';
                $emailService = new \App\Services\DistributionEmailService();
                $emailResult = $emailService->notifyDistributionOpened($academic_year, $semester, $documents_deadline);
                
                if ($emailResult['success']) {
                    $message .= " Email notifications sent to {$emailResult['sent']} student(s).";
                } else {
                    $message .= " (Note: Email notifications could not be sent)";
                }
                
            } catch (Exception $e) {
                pg_query($connection, "ROLLBACK");
                $message = 'Failed to start distribution: ' . $e->getMessage();
            }
            break;
            
        case 'activate_distribution':
            // Set distribution to active (ready for operations)
            $query = "INSERT INTO config (key, value) VALUES ('distribution_status', 'active') 
                      ON CONFLICT (key) DO UPDATE SET value = 'active'";
            if (pg_query($connection, $query)) {
                $success = true;
                $message = 'Distribution activated! All systems are now operational.';
            } else {
                $message = 'Failed to activate distribution.';
            }
            break;
            

            
        case 'finalize_distribution':
            // Finalize distribution - simplified and robust approach
            try {
                // Start transaction
                $begin_result = pg_query($connection, "BEGIN");
                if (!$begin_result) {
                    throw new Exception('Failed to start transaction: ' . pg_last_error($connection));
                }
                
                $archived_count = 0;
                $snapshot_created = false;
                
                // Get current academic period from config (we know this exists)
                $academic_year = $current_academic_year ?: (date('Y') . '-' . (date('Y') + 1));
                $semester = $current_semester ?: '1st Semester';
                
                // Step 1: Archive documents from documents table
                try {
                    // Check if both documents and document_archives tables exist
                    $table_check = pg_query($connection, "
                        SELECT 
                            EXISTS(SELECT 1 FROM information_schema.tables WHERE table_name='documents') as has_documents,
                            EXISTS(SELECT 1 FROM information_schema.tables WHERE table_name='document_archives') as has_archives
                    ");
                    
                    if ($table_check) {
                        $tables = pg_fetch_assoc($table_check);
                        
                        // Only proceed if both tables exist
                        if ($tables['has_documents'] === 't' && $tables['has_archives'] === 't') {
                            // Check for documents to archive
                            $doc_check = pg_query($connection, "SELECT COUNT(*) as doc_count FROM documents");
                            
                            if ($doc_check) {
                                $doc_data = pg_fetch_assoc($doc_check);
                                $archived_count = intval($doc_data['doc_count']);
                                
                                if ($archived_count > 0) {
                                    // Archive documents from documents table
                                    $archive_result = pg_query_params($connection, "
                                        INSERT INTO document_archives (
                                            student_id, original_document_id, document_type, file_path, 
                                            original_upload_date, academic_year, semester, archived_date
                                        )
                                        SELECT 
                                            student_id, 
                                            document_id as original_document_id,
                                            type as document_type, 
                                            file_path, 
                                            upload_date as original_upload_date,
                                            $1, $2, CURRENT_TIMESTAMP
                                        FROM documents
                                        WHERE student_id IS NOT NULL
                                    ", [$academic_year, $semester]);
                                    
                                    if ($archive_result) {
                                        // Clear current documents after successful archive
                                        $clear_result = pg_query($connection, "DELETE FROM documents");
                                        if (!$clear_result) {
                                            error_log("Warning: Failed to clear documents table after archiving: " . pg_last_error($connection));
                                        }
                                    }
                                } else {
                                    $archived_count = 0; // No documents to archive
                                }
                            }
                        }
                    }
                } catch (Exception $doc_error) {
                    // Document archiving failed - log but continue
                    error_log("Document archiving failed during finalization: " . $doc_error->getMessage());
                    $archived_count = 0;
                }
                
                // Step 2: Create or update distribution snapshot (optional)
                try {
                    // First check if snapshot table exists
                    $snapshot_table_check = pg_query($connection, "
                        SELECT EXISTS(SELECT 1 FROM information_schema.tables WHERE table_name='distribution_snapshots') as has_snapshots
                    ");
                    
                    if ($snapshot_table_check) {
                        $snapshot_table = pg_fetch_assoc($snapshot_table_check);
                        
                        if ($snapshot_table['has_snapshots'] === 't') {
                            // Get student count safely
                            $student_count_query = pg_query($connection, "SELECT COUNT(*) as total FROM students WHERE status = 'active'");
                            $student_count = 0;
                            if ($student_count_query) {
                                $student_data = pg_fetch_assoc($student_count_query);
                                $student_count = intval($student_data['total']);
                            }
                            
                            // Check if snapshot already exists for this academic period
                            $check_snapshot = pg_query_params($connection, 
                                "SELECT snapshot_id FROM distribution_snapshots WHERE academic_year = $1 AND semester = $2",
                                [$academic_year, $semester]
                            );
                            
                            $snapshot_exists = $check_snapshot && pg_num_rows($check_snapshot) > 0;
                            
                            if ($snapshot_exists) {
                                // Update existing snapshot
                                $existing_snapshot = pg_fetch_assoc($check_snapshot);
                                $snapshot_result = pg_query_params($connection, "
                                    UPDATE distribution_snapshots 
                                    SET distribution_date = CURRENT_DATE, 
                                        total_students_count = $1, 
                                        location = $2, 
                                        notes = $3
                                    WHERE snapshot_id = $4
                                ", [
                                    $student_count,
                                    'Main Distribution Center',
                                    'Distribution finalized via Distribution Control Center',
                                    $existing_snapshot['snapshot_id']
                                ]);
                            } else {
                                // Create new snapshot
                                $snapshot_result = pg_query_params($connection, "
                                    INSERT INTO distribution_snapshots (
                                        distribution_date, academic_year, semester, 
                                        total_students_count, location, notes
                                    ) VALUES (
                                        CURRENT_DATE, $1, $2, $3, $4, $5
                                    )
                                ", [
                                    $academic_year, 
                                    $semester, 
                                    $student_count,
                                    'Main Distribution Center',
                                    'Distribution finalized via Distribution Control Center'
                                ]);
                            }
                            
                            $snapshot_created = $snapshot_result !== false;
                        }
                    }
                } catch (Exception $snapshot_error) {
                    // Snapshot creation/update failed - log but continue
                    error_log("Snapshot operation failed during finalization: " . $snapshot_error->getMessage());
                    $snapshot_created = false;
                }
                
                // Step 3: Update configuration (this is critical and must succeed)
                $status_configs = [
                    ['distribution_status', 'finalized'],
                    ['uploads_enabled', '0']
                ];
                
                foreach ($status_configs as [$key, $value]) {
                    $config_result = pg_query_params($connection, "
                        INSERT INTO config (key, value) VALUES ($1, $2) 
                        ON CONFLICT (key) DO UPDATE SET value = $2
                    ", [$key, $value]);
                    
                    if (!$config_result) {
                        throw new Exception("Critical error: Failed to update configuration key '$key': " . pg_last_error($connection));
                    }
                }
                
                // Step 4: Deactivate any open slots (optional but recommended)
                try {
                    pg_query($connection, "UPDATE signup_slots SET is_active = FALSE WHERE is_active = TRUE");
                } catch (Exception $slot_error) {
                    // Non-critical - log but continue
                    error_log("Failed to deactivate slots during finalization: " . $slot_error->getMessage());
                }
                
                // Commit transaction
                $commit_result = pg_query($connection, "COMMIT");
                if (!$commit_result) {
                    throw new Exception('Failed to commit transaction: ' . pg_last_error($connection));
                }
                
                // Build success message
                $message_parts = ["Distribution finalized successfully!"];
                if ($archived_count > 0) {
                    $message_parts[] = "$archived_count documents archived from documents table.";
                } else {
                    $message_parts[] = "No documents found to archive.";
                }
                if ($snapshot_created) {
                    $message_parts[] = "Distribution snapshot created.";
                }
                $message_parts[] = "System ready for next cycle.";
                
                $success = true;
                $message = implode(' ', $message_parts);
                
            } catch (Exception $e) {
                // Rollback transaction
                pg_query($connection, "ROLLBACK");
                
                // Log detailed error information
                $error_details = [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString()
                ];
                error_log("Distribution finalization failed: " . json_encode($error_details));
                
                $message = 'Failed to finalize distribution: ' . $e->getMessage();
                
                // Check if this was a transaction abort error
                if (strpos($e->getMessage(), 'transaction is aborted') !== false) {
                    $message .= ' (This usually indicates a database table or column doesn\'t exist. Please check your database schema.)';
                }
            }
            break;
    }
    
        // Refresh workflow status after action
        $workflow_status = getWorkflowStatus($connection);
        $student_counts = getStudentCounts($connection);
        
        // Ensure student_counts has all required keys
        $student_counts = array_merge([
            'total_students' => 0,
            'active_count' => 0,
            'applicant_count' => 0,
            'verified_students' => 0,
            'pending_verification' => 0
        ], $student_counts);
        
        // Update extracted status variables
        $distribution_status = $workflow_status['distribution_status'] ?? 'inactive';
        $uploads_enabled = $workflow_status['uploads_enabled'] ?? false;
    }
}

// Get distribution history with accurate student counts
// Order by finalized_at (most recent first) to show true chronological order
$history_query = "
    SELECT ds.*, 
           (SELECT COUNT(DISTINCT student_id) 
            FROM distribution_student_records 
            WHERE snapshot_id = ds.snapshot_id) as actual_student_count
    FROM distribution_snapshots ds
    WHERE finalized_at IS NOT NULL
    ORDER BY finalized_at DESC, distribution_date DESC
    LIMIT 5
";
$history_result = pg_query($connection, $history_query);


?>

<!DOCTYPE html>
<html lang="en">
<?php $page_title='Distribution Control Center'; include __DIR__ . '/../../includes/admin/admin_head.php'; ?>
<link rel="stylesheet" href="../../assets/css/admin/table_core.css"/>
<style>
  /* Clean Distribution Control Styling - Matching review_registrations.php */
  .filter-section {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
  }
  
  .quick-actions {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 20px;
    border-radius: 12px;
    margin-bottom: 20px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
  }
  
  .quick-actions h5 {
    color: white;
    margin-bottom: 5px;
  }
  
  .quick-actions small {
    color: rgba(255, 255, 255, 0.8);
  }
  
  .quick-actions .btn-light {
    background: white;
    color: #667eea;
    font-weight: 600;
    border: none;
  }
  
  .quick-actions .btn-light:hover {
    background: #f8f9fa;
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
  }
  
    .metric-card {
    background: white;
    border-radius: 12px;
    border: 1px solid #e3e7ec;
    padding: 1.5rem;
    text-align: center;
    transition: all 0.3s ease;
    height: 100%;
        position: relative;
        overflow: hidden;
  }
  
  .metric-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.08);
    border-color: #d0d7de;
  }
  
    /* Metric icons + accent design */
    .metric-card .metric-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 1.25rem;
            margin-bottom: 0.25rem;
            box-shadow: 0 4px 10px rgba(0,0,0,0.08);
    }
    .metric-card.accent-primary { --metric-accent: #667eea; }
    .metric-card.accent-success { --metric-accent: #38a169; }
    .metric-card.accent-info { --metric-accent: #3182ce; }
    .metric-card.accent-warning { --metric-accent: #ed8936; }
    .metric-card.accent-danger { --metric-accent: #e53e3e; }
    .metric-card.accent-primary .metric-icon { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
    .metric-card.accent-success .metric-icon { background: linear-gradient(135deg, #48bb78 0%, #38a169 100%); }
    .metric-card.accent-info .metric-icon { background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%); }
    .metric-card.accent-warning .metric-icon { background: linear-gradient(135deg, #f6ad55 0%, #ed8936 100%); }
    .metric-card.accent-danger .metric-icon { background: linear-gradient(135deg, #f56565 0%, #e53e3e 100%); }
  
    .metric-value {
        font-size: 2rem;
        font-weight: 800;
        margin-bottom: 0.25rem;
        color: #2c3e50;
    }
  
    .metric-label {
        font-size: 0.95rem;
        color: #475569;
        font-weight: 700;
    }
  
  .action-card {
    background: white;
    border-radius: 14px;
    border: 2px solid #e3e7ec;
    padding: 2rem;
    margin-bottom: 1.5rem;
    transition: all 0.3s ease;
  }
  
  .action-card:hover {
    box-shadow: 0 6px 24px rgba(0,0,0,0.08);
  }
  
  .action-card.border-success {
    border-color: #48bb78;
  }
  
  .action-card.border-primary {
    border-color: #667eea;
  }
  
  .action-card.border-info {
    border-color: #4299e1;
  }
  
  .action-card.border-danger {
    border-color: #f56565;
  }
  
  .action-card.border-warning {
    border-color: #ed8936;
  }
  
  .action-card-title {
    font-size: 1.25rem;
    font-weight: 600;
    margin-bottom: 0.75rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
  }
  
  .action-card-text {
    color: #64748b;
    margin-bottom: 1.5rem;
  }
  
  .btn-modern {
    padding: 0.75rem 1.5rem;
    border-radius: 8px;
    font-weight: 600;
    transition: all 0.2s ease;
    border: none;
  }
  
  .btn-modern:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
  }
  
  .history-table {
    background: white;
    border-radius: 12px;
    overflow: hidden;
    border: 1px solid #e3e7ec;
  }
  
  .history-table thead {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
  }
  
  .history-table th {
    font-weight: 600;
    padding: 1rem;
    border: none;
  }
  
  .history-table td {
    padding: 1rem;
    border-top: 1px solid #e3e7ec;
  }
  
  .info-panel {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border-radius: 12px;
    padding: 1.5rem;
    border: 1px solid #dee2e6;
  }
  
  .info-panel h6 {
    font-weight: 600;
    margin-bottom: 1rem;
    color: #495057;
  }
  
  .form-modern .form-label {
    font-weight: 600;
    color: #374151;
    margin-bottom: 0.5rem;
  }
  
  .form-modern .form-control,
  .form-modern .form-select {
    border-radius: 8px;
    border: 1px solid #d1d5db;
    padding: 0.75rem 1rem;
    transition: all 0.2s ease;
  }
  
  .form-modern .form-control:focus,
  .form-modern .form-select:focus {
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
  }
  
  .alert-modern {
    border-radius: 12px;
    border: none;
    padding: 1.25rem 1.5rem;
    margin-bottom: 1.5rem;
  }
  
  .alert-success {
    background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
    color: #065f46;
  }
  
  .alert-danger {
    background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
    color: #991b1b;
  }
  
  .alert-info {
    background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
    color: #1e40af;
  }

    /* ==================== DESKTOP TABLE CARD (match manage_applicants) ==================== */
    @media (min-width: 768px) {
        .table-card {
            background: #ffffff;
            border-radius: 12px;
            border: 1px solid #e3e7ec;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            overflow: hidden;
        }
        .table-card .table-responsive { margin-top: 0; border-radius: 0; box-shadow: none; background: transparent; }
        .table-card .table { margin: 0; border-collapse: separate; border-spacing: 0; }
        .table-card thead th {
            background: #3a4046 !important; /* dark header like manage_applicants */
            color: #ffffff !important;
            font-weight: 700;
            font-size: 1rem;
            padding: 14px 18px;
            border: none !important;
        }
        .table-card tbody td {
            padding: 18px;
            font-size: 1rem;
            color: #2c3e50;
            border-top: 1px solid #eef1f4 !important;
        }
    }
  
    @media (max-width: 768px) {
    .control-header {
      padding: 1.5rem;
    }
    
    /* Header Section Mobile Optimization */
    .d-flex.justify-content-between.align-items-center {
      flex-direction: column !important;
      align-items: flex-start !important;
      gap: 1rem;
    }
    
    .d-flex.justify-content-between.align-items-center > div {
      width: 100%;
    }
    
    .d-flex.justify-content-between.align-items-center > div:first-child h1 {
      font-size: 2rem;
      margin-bottom: 0.5rem;
    }
    
    .d-flex.justify-content-between.align-items-center > div:first-child p {
      font-size: 1rem;
      margin-bottom: 0.75rem;
    }
    
        /* Badge layout for semester and deadline (scoped) */
        #mainContent .d-flex.gap-2.mt-2 {
            display: grid !important;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.5rem !important;
            margin-top: 0.75rem !important;
        }
        #mainContent .d-flex.gap-2.mt-2 .badge {
            width: 100%;
            display: inline-flex;
            align-items: center;
            justify-content: flex-start;
            gap: .5rem;
            min-height: 42px;
            padding: 8px 12px !important;
        }
    
    .d-flex.gap-2.mt-2 .badge {
      width: 100%;
      text-align: left;
      font-size: 0.9rem !important;
      padding: 8px 12px !important;
    }
    
    /* Status badge and buttons alignment */
    .d-flex.flex-column.align-items-end {
      align-items: flex-start !important;
      width: 100%;
    }
    
    .d-flex.flex-column.align-items-end .badge {
      width: 100%;
      text-align: center;
      font-size: 1rem !important;
      padding: 10px 14px;
    }
    
        /* Manage Slots and Scheduling buttons (scoped) */
        #mainContent .d-flex.flex-column.align-items-end .d-flex.gap-2 {
            width: 100%;
            display: grid !important;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.5rem !important;
            margin-top: 0.75rem !important;
        }
    
        #mainContent .d-flex.flex-column.align-items-end .d-flex.gap-2 a {
            width: 100%;
            text-align: center;
            justify-content: center;
            font-size: 0.95rem;
            padding: 0.6rem 1rem;
            height: 48px;
            display: inline-flex;
            align-items: center;
            gap: .5rem;
        }
        #mainContent .d-flex.flex-column.align-items-end .d-flex.gap-2 a .bi { font-size: 1.1rem; }

        /* Subtitle spacing tweak */
        #mainContent .d-flex.justify-content-between.align-items-center > div:first-child p.text-muted {
            margin-bottom: .5rem !important;
        }
    
        /* Metric cards: compact, two-column grid */
        .metric-card { margin-bottom: 1rem; padding: 1rem; }
    
        .metric-value { font-size: 1.6rem; }
    
        .metric-label { font-size: 0.95rem; }
    
    /* Action cards optimization */
    .action-card {
      padding: 1.25rem;
      margin-bottom: 1rem;
    }
    
    .action-card-title {
      font-size: 1.15rem;
    }
    
    .action-card-text {
      font-size: 0.95rem;
    }
    
    /* Quick actions mobile */
    .quick-actions {
      padding: 1rem;
    }
    
    .quick-actions .d-flex {
      flex-direction: column !important;
      gap: 1rem !important;
    }
    
    .quick-actions h5 {
      font-size: 1.1rem;
    }
    
    .quick-actions small {
      font-size: 0.9rem;
    }
    
    .quick-actions .btn-light {
      width: 100%;
      text-align: center;
      font-size: 0.95rem;
    }
    
    /* Table responsive */
    .history-table {
      font-size: 0.9rem;
    }
    
    .history-table th,
    .history-table td {
      padding: 0.75rem 0.5rem;
      font-size: 0.9rem;
    }
  }
  
  @media (max-width: 576px) {
    /* Extra small devices - slightly smaller but still readable */
    .d-flex.justify-content-between.align-items-center > div:first-child h1 {
      font-size: 1.75rem;
    }
    
    .d-flex.justify-content-between.align-items-center > div:first-child p {
      font-size: 0.95rem;
    }
    
        #mainContent .badge {
      font-size: 0.85rem !important;
    }
    
        #mainContent .btn {
      font-size: 0.9rem;
      padding: 0.55rem 1rem;
    }
    
        /* make metrics 2-up on very small screens */
        .metrics-row .col-sm-6 { flex: 0 0 50%; max-width: 50%; }
        .metric-value { font-size: 1.5rem; }
        .metric-label { font-size: 0.9rem; }

        /* For very narrow devices, fall back to single column for chips/buttons */
        #mainContent .d-flex.gap-2.mt-2,
        #mainContent .d-flex.flex-column.align-items-end .d-flex.gap-2 {
            grid-template-columns: 1fr !important;
        }
  }
</style>
<body>
<?php include __DIR__ . '/../../includes/admin/admin_topbar.php'; ?>

<div id="wrapper" class="admin-wrapper">
    <?php include __DIR__ . '/../../includes/admin/admin_sidebar.php'; ?>
    <?php include __DIR__ . '/../../includes/admin/admin_header.php'; ?>
    
    <section class="home-section" id="mainContent">
        <div class="container-fluid px-4">
            <!-- Status Messages -->
            <?php if (isset($message)): ?>
                <div class="alert alert-modern alert-<?= $success ? 'success' : 'danger' ?> alert-dismissible fade show" role="alert">
                    <i class="bi bi-<?= $success ? 'check-circle' : 'exclamation-triangle' ?> me-2"></i>
                    <?= $message ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-modern alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle me-2"></i>
                    <?php echo htmlspecialchars($_SESSION['success_message']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-modern alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <?php echo htmlspecialchars($_SESSION['error_message']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>
            
            <?php if ($migration_needed): ?>
                <div class="alert alert-modern alert-info alert-dismissible fade show" role="alert">
                    <i class="bi bi-info-circle me-2"></i>
                    <strong>Auto-Migration Applied:</strong> 
                    Existing distribution detected without academic period configuration. 
                    Default academic period has been set: 
                    <strong><?= htmlspecialchars($current_semester) ?> <?= htmlspecialchars($current_academic_year) ?></strong>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <!-- Header - Clean style -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="fw-bold mb-2">Distribution Control Center</h1>
                    <p class="text-muted mb-2">Manage the complete distribution lifecycle</p>
                    <?php if ($current_academic_year && $current_semester): ?>
                        <div class="d-flex gap-2 mt-2">
                            <span class="badge" style="background: #e8f4f8; color: #0c5460; font-size: 0.95rem; padding: 8px 14px; border: 1px solid #bee5eb;">
                                <i class="bi bi-calendar3 me-1"></i>
                                <?= htmlspecialchars($current_semester) ?> <?= htmlspecialchars($current_academic_year) ?>
                            </span>
                            <?php if ($documents_deadline): ?>
                                <span class="badge" style="background: #fff3cd; color: #856404; font-size: 0.95rem; padding: 8px 14px; border: 1px solid #ffeeba;">
                                    <i class="bi bi-clock-history me-1"></i>
                                    Deadline: <?= date('M j, Y', strtotime($documents_deadline)) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="d-flex flex-column align-items-end gap-2">
                    <?php
                    $statusIcons = [
                        'inactive' => 'circle',
                        'preparing' => 'gear-fill',
                        'active' => 'play-circle-fill',
                        'finalizing' => 'hourglass-split',
                        'finalized' => 'check-circle-fill'
                    ];
                    $statusColors = [
                        'inactive' => 'secondary',
                        'preparing' => 'warning',
                        'active' => 'success',
                        'finalizing' => 'info',
                        'finalized' => 'primary'
                    ];
                    $statusColor = $statusColors[$workflow_status['distribution_status']] ?? 'secondary';
                    $statusIcon = $statusIcons[$workflow_status['distribution_status']] ?? 'circle';
                    ?>
                    <span class="badge bg-<?= $statusColor ?> fs-6">
                        <i class="bi bi-<?= $statusIcon ?> me-1"></i>
                        <?= ucfirst($workflow_status['distribution_status']) ?>
                    </span>
                    
                    <?php if ($workflow_status['distribution_status'] === 'active'): ?>
                        <div class="d-flex gap-2">
                            <a href="manage_slots.php" class="btn btn-outline-primary btn-sm">
                                <i class="bi bi-sliders me-1"></i>Manage Slots
                            </a>
                            <a href="manage_schedules.php" class="btn btn-outline-primary btn-sm">
                                <i class="bi bi-calendar me-1"></i>Scheduling
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
                            
            <!-- Metrics Dashboard -->
            <div class="row g-3 mb-4 metrics-row">
                <div class="col-md-3 col-sm-6">
                    <div class="metric-card accent-primary">
                        <div class="metric-icon"><i class="bi bi-people-fill"></i></div>
                        <div class="metric-value"><?= $student_counts['active_count'] ?></div>
                        <div class="metric-label">Active Students</div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <?php $slotsAccent = $workflow_status['slots_open'] ? 'accent-success' : 'accent-danger'; $slotIcon = $workflow_status['slots_open'] ? 'door-open-fill' : 'door-closed-fill'; ?>
                    <div class="metric-card <?= $slotsAccent ?>">
                        <div class="metric-icon"><i class="bi bi-<?= $slotIcon ?>"></i></div>
                        <div class="metric-value"><?= $workflow_status['slots_open'] ? 'Open' : 'Closed' ?></div>
                        <div class="metric-label">Registration Slots</div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <?php $uploadsAccent = $workflow_status['uploads_enabled'] ? 'accent-info' : 'accent-warning'; $uploadIcon = $workflow_status['uploads_enabled'] ? 'cloud-upload-fill' : 'cloud-slash-fill'; ?>
                    <div class="metric-card <?= $uploadsAccent ?>">
                        <div class="metric-icon"><i class="bi bi-<?= $uploadIcon ?>"></i></div>
                        <div class="metric-value"><?= $workflow_status['uploads_enabled'] ? 'Enabled' : 'Disabled' ?></div>
                        <div class="metric-label">Document Uploads</div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="metric-card accent-warning">
                        <div class="metric-icon"><i class="bi bi-person-plus-fill"></i></div>
                        <div class="metric-value"><?= $student_counts['applicant_count'] ?></div>
                        <div class="metric-label">Pending Applicants</div>
                    </div>
                </div>
            </div>
            
            <!-- Distribution Active Alert - Quick Actions style -->
            <?php if ($workflow_status['distribution_status'] === 'active'): ?>
                <div class="quick-actions mb-4">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <h5 class="mb-1"><i class="bi bi-info-circle-fill me-2"></i>Distribution Active</h5>
                            <small>When you're ready to end this distribution cycle, go to the End Distribution page to finalize and archive all data.</small>
                        </div>
                        <div>
                            <a href="end_distribution.php" class="btn btn-light">
                                <i class="bi bi-box-arrow-right me-1"></i>End Distribution
                            </a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Action Cards -->
                            <div class="row g-3">
                                <?php if ($workflow_status['can_start_distribution']): ?>
                                    <div class="col-md-12">
                                        <div class="card border-success">
                                            <div class="card-body">
                                                <h5 class="card-title text-success">
                                                    <i class="bi bi-play-circle me-2"></i>Start New Distribution
                                                </h5>
                                                <p class="card-text">Begin a new distribution cycle. Set the academic period for this distribution.</p>
                                                <form method="POST" id="startDistributionForm">
                                                    <input type="hidden" name="action" value="start_distribution">
                                                    <?= CSRFProtection::getTokenField('distribution_control') ?>
                                                    
                                                    <div class="row g-3 mb-3">
                                                        <div class="col-md-6">
                                                            <label for="academic_year" class="form-label">Academic Year <span class="text-danger">*</span></label>
                                                            <input type="text" class="form-control" name="academic_year" id="academic_year" 
                                                                   placeholder="2025-2026" pattern="\d{4}-\d{4}" required>
                                                            <div class="form-text">Format: YYYY-YYYY (e.g., 2025-2026)</div>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <label for="semester" class="form-label">Semester <span class="text-danger">*</span></label>
                                                            <select class="form-select" name="semester" id="semester" required>
                                                                <option value="">Select semester</option>
                                                                <option value="1st Semester">1st Semester</option>
                                                                <option value="2nd Semester">2nd Semester</option>
                                                            </select>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <label for="documents_deadline" class="form-label">Documents Submission Deadline</label>
                                                            <input type="date" class="form-control" name="documents_deadline" id="documents_deadline">
                                                            <div class="form-text">Students will see this deadline once the distribution is activated. Schedules cannot start before this date.</div>
                                                        </div>
                                                    </div>
                                                    
                                                    <button type="submit" class="btn btn-success" onclick="return confirm('Start a new distribution cycle with the specified academic period?')">
                                                        <i class="bi bi-play-fill me-1"></i>Start Distribution
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Distribution History -->
                            <?php if ($history_result && pg_num_rows($history_result) > 0): ?>
                                <div class="mt-5">
                                    <h4 class="mb-3">
                                        <i class="bi bi-clock-history me-2"></i>Recent Distribution History
                                    </h4>
                                    <div class="table-card">
                                      <div class="table-responsive">
                                        <table class="table align-middle">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Academic Period</th>
                                                    <th>Students</th>
                                                    <th>Location</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php while ($hist = pg_fetch_assoc($history_result)): ?>
                                                    <tr>
                                                        <td data-label="Date"><?= date('M j, Y', strtotime($hist['distribution_date'])) ?></td>
                                                        <td data-label="Academic Period"><?= htmlspecialchars($hist['academic_year']) ?> <?= htmlspecialchars($hist['semester']) ?></td>
                                                        <td data-label="Students"><?= $hist['actual_student_count'] ?? 0 ?></td>
                                                        <td data-label="Location"><?= htmlspecialchars($hist['location']) ?></td>
                                                    </tr>
                                                <?php endwhile; ?>
                                            </tbody>
                                        </table>
                                      </div>
                                    </div>
                            </div>
                        <?php endif; ?>
                        
                        <!-- System Information -->
                        <div class="row g-3 mt-4">
                            <!-- Configuration Status -->
                            <div class="col-md-6">
                                <div class="card h-100 border-0 shadow-sm" style="border-radius: 12px;">
                                    <div class="card-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 1rem;">
                                        <h5 class="mb-0 text-white fw-bold" style="font-size: 1.1rem;">
                                            <i class="bi bi-info-circle me-2"></i>System Information
                                        </h5>
                                    </div>
                                    <div class="card-body" style="background: white; padding: 1.5rem;">
                                        <div class="mb-3">
                                            <small class="text-muted text-uppercase" style="font-size: 0.75rem; font-weight: 600;">Configuration Status:</small>
                                        </div>
                                        <div class="info-list">
                                            <div class="info-row mb-3">
                                                <strong style="color: #495057;">Distribution Status:</strong> 
                                                <span class="text-capitalize"><?= htmlspecialchars($distribution_status) ?></span>
                                            </div>
                                            <div class="info-row mb-3">
                                                <strong style="color: #495057;">Uploads Enabled:</strong> 
                                                <span><?= htmlspecialchars($uploads_enabled ? 'Yes' : 'No') ?></span>
                                            </div>
                                            <div class="info-row mb-3">
                                                <strong style="color: #495057;">Academic Year:</strong> 
                                                <span><?= htmlspecialchars($current_academic_year ?: 'Not Set') ?></span>
                                            </div>
                                            <div class="info-row mb-3">
                                                <strong style="color: #495057;">Semester:</strong> 
                                                <span><?= htmlspecialchars($current_semester ?: 'Not Set') ?></span>
                                            </div>
                                            <div class="info-row">
                                                <strong style="color: #495057;">Documents Deadline:</strong> 
                                                <?php if ($documents_deadline): ?>
                                                    <span style="color: #f39c12; font-weight: 600;"><?= date('F j, Y', strtotime($documents_deadline)) ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">Not Set</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Student Statistics -->
                            <div class="col-md-6">
                                <div class="card h-100 border-0 shadow-sm" style="border-radius: 12px;">
                                    <div class="card-header" style="background: white; padding: 1.5rem; border-bottom: 2px solid #e9ecef;">
                                        <h6 class="fw-bold mb-0" style="color: #28a745; font-size: 1rem; text-transform: uppercase; letter-spacing: 0.5px;">
                                            <i class="bi bi-people-fill me-2"></i>Student Statistics
                                        </h6>
                                    </div>
                                    <div class="card-body" style="background: white; padding: 1.5rem;">
                                        <div class="d-flex justify-content-between align-items-center mb-3 pb-3" style="border-bottom: 1px solid #f0f0f0;">
                                            <span style="color: #6c757d; font-size: 0.95rem;">Total Students:</span>
                                            <span class="badge" style="background: #007bff; color: white; font-size: 1.1rem; padding: 0.5rem 1rem; border-radius: 8px; min-width: 60px; text-align: center;">
                                                <?= number_format($student_counts['total_students']) ?>
                                            </span>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center mb-3 pb-3" style="border-bottom: 1px solid #f0f0f0;">
                                            <span style="color: #6c757d; font-size: 0.95rem;">Verified Students:</span>
                                            <span class="badge" style="background: #28a745; color: white; font-size: 1.1rem; padding: 0.5rem 1rem; border-radius: 8px; min-width: 60px; text-align: center;">
                                                <?= number_format($student_counts['verified_students']) ?>
                                            </span>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span style="color: #6c757d; font-size: 0.95rem;">Pending Verification:</span>
                                            <span class="badge" style="background: #ffc107; color: #212529; font-size: 1.1rem; padding: 0.5rem 1rem; border-radius: 8px; min-width: 60px; text-align: center;">
                                                <?= number_format($student_counts['pending_verification']) ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../../assets/js/admin/sidebar.js"></script>
<script>
// CSRF Token Management for Distribution Control
document.addEventListener('DOMContentLoaded', function() {
    // Add loading states to form submissions for better UX
    const forms = document.querySelectorAll('form[method="POST"]');
    
    forms.forEach(form => {
        form.addEventListener('submit', function() {
            const submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn && !submitBtn.disabled) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Processing...';
                
                // Re-enable after 3 seconds to prevent permanent lock
                setTimeout(() => {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = submitBtn.innerHTML.replace('Processing...', submitBtn.textContent.includes('Start') ? 'Start Distribution' : 
                                                                   submitBtn.textContent.includes('Activate') ? 'Activate' :
                                                                   submitBtn.textContent.includes('Open') ? 'Open Slots' :
                                                                   submitBtn.textContent.includes('Close') ? 'Close Slots' :
                                                                   submitBtn.textContent.includes('Enable') ? 'Enable Uploads' :
                                                                   submitBtn.textContent.includes('Disable') ? 'Disable Uploads' :
                                                                   'Finalize Distribution');
                }, 3000);
            }
        });
    });
    
    // Auto-refresh status every 30 seconds (useful during active distributions)
    let refreshInterval;
    const isActive = <?= json_encode($workflow_status['distribution_status'] === 'active') ?>;
    
    if (isActive) {
        refreshInterval = setInterval(() => {
            // Only refresh if no form submission is in progress
            const hasDisabledButtons = Array.from(forms).some(form => 
                form.querySelector('button[type="submit"]:disabled')
            );
            
            if (!hasDisabledButtons) {
                window.location.reload();
            }
        }, 30000);
    }
    
    // Setup graduate review modal button handlers
    setupGraduateModalButtons();
});

// Setup modal button event handlers
function setupGraduateModalButtons() {
    // Handle skip graduates button
    const skipBtn = document.getElementById('skipGraduatesBtn');
    if (skipBtn) {
        skipBtn.addEventListener('click', function() {
            console.log('Skip button clicked');
            const form = document.getElementById('startDistributionForm');
            if (!form) {
                console.error('Form not found!');
                return;
            }
            
            // Replace the CSRF token with the fresh one from modal
            if (window.graduateArchiveCsrfToken) {
                const csrfInput = form.querySelector('input[name="csrf_token"]');
                if (csrfInput) {
                    console.log('Replacing CSRF token with fresh modal token');
                    csrfInput.value = window.graduateArchiveCsrfToken;
                }
            }
            
            // Add hidden field to indicate graduates were reviewed but not archived
            const reviewedInput = document.createElement('input');
            reviewedInput.type = 'hidden';
            reviewedInput.name = 'graduates_reviewed';
            reviewedInput.value = '1';
            form.appendChild(reviewedInput);
            
            // Close modal and submit form
            const modalElement = document.getElementById('graduateReviewModal');
            if (modalElement) {
                const modalInstance = bootstrap.Modal.getInstance(modalElement);
                if (modalInstance) {
                    modalInstance.hide();
                }
            }
            form.submit();
        });
    }
    
    // Handle archive graduates button
    const archiveBtn = document.getElementById('archiveGraduatesBtn');
    if (archiveBtn) {
        archiveBtn.addEventListener('click', function() {
            console.log('Archive button clicked');
            const form = document.getElementById('startDistributionForm');
            if (!form) {
                console.error('Form not found!');
                return;
            }
            
            // Replace the CSRF token with the fresh one from modal
            if (window.graduateArchiveCsrfToken) {
                const csrfInput = form.querySelector('input[name="csrf_token"]');
                if (csrfInput) {
                    console.log('Replacing CSRF token with fresh modal token');
                    csrfInput.value = window.graduateArchiveCsrfToken;
                }
            }
            
            // Add hidden fields to indicate graduates should be archived
            const reviewedInput = document.createElement('input');
            reviewedInput.type = 'hidden';
            reviewedInput.name = 'graduates_reviewed';
            reviewedInput.value = '1';
            form.appendChild(reviewedInput);
            
            const archiveInput = document.createElement('input');
            archiveInput.type = 'hidden';
            archiveInput.name = 'archive_graduates';
            archiveInput.value = '1';
            form.appendChild(archiveInput);
            
            // Close modal and submit form
            const modalElement = document.getElementById('graduateReviewModal');
            if (modalElement) {
                const modalInstance = bootstrap.Modal.getInstance(modalElement);
                if (modalInstance) {
                    modalInstance.hide();
                }
            }
            form.submit();
        });
    }
    
    // Handle view graduates button - opens in new tab
    const viewBtn = document.getElementById('viewGraduatesBtn');
    if (viewBtn) {
        viewBtn.addEventListener('click', function() {
            console.log('View button clicked');
            window.open('view_graduating_students.php', '_blank');
        });
    } else {
        console.error('View graduates button not found in DOM');
    }
    
    // Handle collapse toggle for graduates list
    const graduatesList = document.getElementById('graduatesList');
    if (graduatesList) {
        graduatesList.addEventListener('show.bs.collapse', function() {
            console.log('Loading graduates list...');
            // Rotate icon
            document.getElementById('graduatesListIcon').classList.replace('bi-chevron-down', 'bi-chevron-up');
            // Load graduates via AJAX
            loadGraduatesList();
        });
        
        graduatesList.addEventListener('hide.bs.collapse', function() {
            // Rotate icon back
            document.getElementById('graduatesListIcon').classList.replace('bi-chevron-up', 'bi-chevron-down');
        });
    }
}

// Load graduates list via AJAX
async function loadGraduatesList() {
    const content = document.getElementById('graduatesContent');
    if (!content) return;
    
    try {
        const response = await fetch('get_graduating_students_list.php');
        if (!response.ok) throw new Error('Failed to fetch graduates');
        
        const graduates = await response.json();
        
        if (graduates.length === 0) {
            content.innerHTML = `
                <div class="alert alert-warning mb-0">
                    <i class="bi bi-info-circle me-2"></i>
                    No graduating students found.
                </div>
            `;
            return;
        }
        
        // Build graduates list HTML
        let html = '<div class="list-group">';
        
        graduates.forEach((student, index) => {
            const yearBadgeColor = student.current_year_level === '5th Year' ? 'danger' : 
                                   student.current_year_level === '4th Year' ? 'warning' : 'info';
            const statusBadge = student.status === 'active' ? 
                '<span class="badge bg-success">Active</span>' : 
                '<span class="badge bg-primary">Applicant</span>';
            
            html += `
                <div class="list-group-item">
                    <div class="d-flex w-100 justify-content-between align-items-start">
                        <div class="flex-grow-1">
                            <h6 class="mb-1">
                                <i class="bi bi-person-circle me-1"></i>
                                ${student.first_name} ${student.last_name}
                            </h6>
                            <p class="mb-1 small text-muted">
                                <i class="bi bi-card-text me-1"></i> ID: <strong>${student.student_id}</strong>
                            </p>
                            <p class="mb-1 small">
                                <i class="bi bi-envelope me-1"></i> ${student.email || 'No email'}
                            </p>
                        </div>
                        <div class="text-end">
                            <span class="badge bg-${yearBadgeColor} mb-1">${student.current_year_level}</span><br>
                            ${statusBadge}<br>
                            <small class="text-muted">A.Y. ${student.status_academic_year}</small>
                        </div>
                    </div>
                </div>
            `;
        });
        
        html += '</div>';
        content.innerHTML = html;
        
    } catch (error) {
        console.error('Error loading graduates:', error);
        content.innerHTML = `
            <div class="alert alert-danger mb-0">
                <i class="bi bi-exclamation-triangle me-2"></i>
                Failed to load graduating students. Please try again.
            </div>
        `;
    }
}

document.getElementById('startDistributionForm')?.addEventListener('submit', async function(e) {
    const academicYear = document.getElementById('academic_year').value;
    const semester = document.getElementById('semester').value;
    const deadline = document.getElementById('documents_deadline').value;
    
    // Only check if not already reviewed
    if (!this.querySelector('input[name="graduates_reviewed"]')) {
        e.preventDefault();
        
        const formData = new FormData(this);
        
        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            });
            
            const data = await response.json();
            
            if (data.requires_review) {
                // Show modal with graduating students
                showGraduateReviewModal(data);
                return;
            }
            
            // If no graduate review needed, reload page to see result
            window.location.reload();
            
        } catch (error) {
            console.error('Error checking graduates:', error);
            // On error, proceed with normal submission
            this.submit();
        }
    }
});

// Check if we need to show modal from session (after redirect)
<?php if (isset($_SESSION['show_graduate_modal'])): ?>
    <?php $modal_data = $_SESSION['show_graduate_modal']; ?>
    document.addEventListener('DOMContentLoaded', function() {
        showGraduateReviewModal(<?= json_encode($modal_data) ?>);
    });
    <?php unset($_SESSION['show_graduate_modal']); ?>
<?php endif; ?>

// Fetch fresh CSRF token for graduate archive modal specifically
async function refreshGraduateArchiveCsrfToken() {
    try {
        console.log('Fetching fresh CSRF token for graduate archive action...');
        const response = await fetch('get_csrf_token.php?action=distribution_control');
        if (response.ok) {
            const data = await response.json();
            if (data.success && data.token) {
                // Store the fresh token for modal actions
                window.graduateArchiveCsrfToken = data.token;
                console.log('Graduate archive CSRF token refreshed successfully');
                return true;
            }
        }
        console.error('Failed to fetch CSRF token:', response.status);
        return false;
    } catch (error) {
        console.error('Error fetching CSRF token:', error);
        return false;
    }
}

function showGraduateReviewModal(data) {
    const modal = new bootstrap.Modal(document.getElementById('graduateReviewModal'));
    
    // Update modal content
    document.getElementById('graduateCount').textContent = data.graduate_count;
    document.getElementById('previousYear').textContent = data.previous_year;
    document.getElementById('newYear').textContent = data.new_year;
    
    // Refresh CSRF token when modal opens to prevent rotation issues
    // This token will be used when "Archive All" button is clicked
    refreshGraduateArchiveCsrfToken().then(success => {
        if (!success) {
            console.error('Warning: CSRF token refresh failed. Archive action may fail.');
        }
    });
    
    // Re-setup button handlers after modal is shown
    setupGraduateModalButtons();
    
    modal.show();
}

</script>

<?php 
// Success modal for distribution completion (from scan_qr redirect)
$distCompleted = $_SESSION['show_distribution_completed'] ?? null;
if ($distCompleted):
        $ay = trim(($distCompleted['academic_year'] ?? '') . ' ' . ($distCompleted['semester'] ?? ''));
        $ts = (int)($distCompleted['total_students'] ?? 0);
        $loc = htmlspecialchars($distCompleted['location'] ?? '');
?>
<div class="modal fade" id="distributionCompletedModal" tabindex="-1" aria-labelledby="distributionCompletedModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title" id="distributionCompletedModalLabel"><i class="bi bi-check2-circle me-2"></i>Distribution Finalized</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2">The distribution has been successfully completed and finalized.</p>
                <ul class="mb-0">
                    <li><strong>Period:</strong> <?php echo htmlspecialchars($ay); ?></li>
                    <li><strong>Students recorded:</strong> <?php echo number_format($ts); ?></li>
                    <?php if ($loc): ?><li><strong>Location:</strong> <?php echo $loc; ?></li><?php endif; ?>
                </ul>
                <hr/>
                <p class="mb-0 small text-muted">Next: Use <em>End Distribution</em> to compress files and reset the system for the next cycle.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            try { new bootstrap.Modal(document.getElementById('distributionCompletedModal')).show(); } catch (e) {}
        });
    </script>
<?php unset($_SESSION['show_distribution_completed']); endif; ?>

<!-- Graduating Students Review Modal -->
<div class="modal fade" id="graduateReviewModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title">
                    <i class="bi bi-mortarboard-fill me-2"></i>
                    Review Graduating Students
                </h5>
            </div>
            <div class="modal-body">
                <div class="alert alert-info mb-4">
                    <i class="bi bi-info-circle-fill me-2"></i>
                    <strong>New Academic Year Detected!</strong><br>
                    You are starting a distribution for <strong id="newYear">A.Y. 2025-2026</strong>, 
                    but there are <strong id="graduateCount">0</strong> student(s) who marked themselves as 
                    graduating in <strong id="previousYear">A.Y. 2024-2025</strong>.
                </div>
                
                <h6 class="mb-3"><i class="bi bi-question-circle me-2"></i>What would you like to do?</h6>
                
                <div class="d-grid gap-3">
                    <button type="button" class="btn btn-outline-primary d-flex align-items-center justify-content-between" 
                            data-bs-toggle="collapse" data-bs-target="#graduatesList" aria-expanded="false">
                        <div>
                            <i class="bi bi-eye me-2"></i>
                            <strong>View List of Graduating Students</strong>
                            <p class="mb-0 small text-muted">Review student details before deciding</p>
                        </div>
                        <i class="bi bi-chevron-down" id="graduatesListIcon"></i>
                    </button>
                    
                    <!-- Collapsible Graduates List -->
                    <div class="collapse" id="graduatesList">
                        <div class="card card-body" style="max-height: 400px; overflow-y: auto;">
                            <div id="graduatesContent">
                                <div class="text-center py-3">
                                    <div class="spinner-border text-primary" role="status">
                                        <span class="visually-hidden">Loading...</span>
                                    </div>
                                    <p class="mt-2 mb-0 text-muted">Loading graduating students...</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <button type="button" class="btn btn-success d-flex align-items-center justify-content-between" id="archiveGraduatesBtn">
                        <div>
                            <i class="bi bi-archive-fill me-2"></i>
                            <strong>Archive All Graduating Students</strong>
                            <p class="mb-0 small text-muted">Recommended: Automatically archive all graduates from previous year</p>
                        </div>
                        <i class="bi bi-check-circle"></i>
                    </button>
                    
                    <button type="button" class="btn btn-outline-secondary d-flex align-items-center justify-content-between" id="skipGraduatesBtn">
                        <div>
                            <i class="bi bi-skip-forward me-2"></i>
                            <strong>Skip for Now</strong>
                            <p class="mb-0 small text-muted">Continue without archiving (you can do this manually later)</p>
                        </div>
                    </button>
                </div>
                
                <div class="alert alert-light mt-4 mb-0">
                    <small>
                        <strong>Note:</strong> Archiving graduating students will:
                        <ul class="mb-0 mt-2">
                            <li>Move them to the archived students list</li>
                            <li>Prevent them from appearing in future distributions</li>
                            <li>Preserve their records for historical purposes</li>
                            <li>Mark their accounts as "Graduated"</li>
                        </ul>
                    </small>
                </div>
            </div>
        </div>
    </div>
</div>

</body>
</html>

<?php 
if ($history_result) pg_free_result($history_result);
pg_close($connection); 
?>