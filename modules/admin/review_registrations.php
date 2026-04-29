<?php
include __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/CSRFProtection.php';
require_once __DIR__ . '/../../bootstrap_services.php';
require_once __DIR__ . '/../../src/Services/UnifiedFileService.php';
require_once __DIR__ . '/../../includes/student_notification_helper.php';
require_once __DIR__ . '/../../config/FilePathConfig.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/../../phpmailer/vendor/autoload.php';

if (!isset($_SESSION['admin_username'])) {
    header("Location: ../../unified_login.php");
    exit;
}

// Lightweight API for sidebar: return pending registrations count as JSON
if (isset($_GET['api']) && $_GET['api'] === 'badge_count') {
    header('Content-Type: application/json');
    $countRes = @pg_query($connection, "SELECT COUNT(*) FROM students WHERE status = 'under_registration' AND (is_archived IS NULL OR is_archived = FALSE)");
    $count = 0;
    if ($countRes) {
        $count = (int) pg_fetch_result($countRes, 0, 0);
        pg_free_result($countRes);
    }
    echo json_encode(['count' => $count]);
    exit;
}

// Initialize DocumentService
$docService = new DocumentService($connection);

// Initialize UnifiedFileService for file operations
$fileService = new \App\Services\UnifiedFileService();

// Handle approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF Protection - validate token first
    $token = $_POST['csrf_token'] ?? '';
    if (!CSRFProtection::validateToken('review_registrations', $token)) {
        $_SESSION['error_message'] = 'Security validation failed. Please refresh the page.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    
    // Handle bulk actions
    if (isset($_POST['bulk_action']) && isset($_POST['student_ids'])) {
        $action = $_POST['bulk_action'];
        $student_ids = explode(',', $_POST['student_ids']);
        $student_ids = array_map('trim', $student_ids); // Remove whitespace instead of intval
        $student_ids = array_filter($student_ids); // Remove empty values
        
        if (!empty($student_ids) && in_array($action, ['approve', 'reject'])) {
            $success_count = 0;
            
            foreach ($student_ids as $student_id) {
                if ($action === 'approve') {
                    // Get slot's academic year first
                    $slotQuery = "SELECT ss.academic_year, ss.semester 
                                 FROM students s 
                                 LEFT JOIN signup_slots ss ON s.slot_id = ss.slot_id 
                                 WHERE s.student_id = $1";
                    $slotResult = pg_query_params($connection, $slotQuery, [$student_id]);
                    $slotData = pg_fetch_assoc($slotResult);
                    
                    // Update student status to applicant and set first_registered_academic_year
                    $updateQuery = "UPDATE students 
                                   SET status = 'applicant',
                                       first_registered_academic_year = $2,
                                       current_academic_year = $2
                                   WHERE student_id = $1 AND status = 'under_registration'";
                    $result = pg_query_params($connection, $updateQuery, [
                        $student_id,
                        $slotData['academic_year'] ?? null
                    ]);
                    
                    if ($result && pg_affected_rows($result) > 0) {
                        // Update or create course mapping
                        // Course mapping logic removed - no longer needed
                        // Students will declare their year level at enrollment
                        
                        // Use UnifiedFileService to move all documents from temp to permanent storage
                        $moveResult = $fileService->moveToPermStorage($student_id, $_SESSION['admin_id'] ?? null);
                        
                        if ($moveResult['success']) {
                            error_log("UnifiedFileService: Successfully moved " . $moveResult['moved_count'] . " documents for student $student_id");
                        } else {
                            error_log("UnifiedFileService: Error moving documents for student $student_id - " . ($moveResult['errors'][0] ?? 'Unknown error'));
                        }
                        
                        $success_count++;
                        
                        // Get student email for notification
                        $emailQuery = "SELECT email, first_name, last_name, extension_name FROM students WHERE student_id = $1";
                        $emailResult = pg_query_params($connection, $emailQuery, [$student_id]);
                        if ($student = pg_fetch_assoc($emailResult)) {
                            sendApprovalEmail($student['email'], $student['first_name'], $student['last_name'], $student['extension_name'], true, '');
                            
                            // Add admin notification
                            $student_name = trim($student['first_name'] . ' ' . $student['last_name'] . ' ' . $student['extension_name']);
                            $notification_msg = "Registration approved for student: " . $student_name . " (ID: " . $student_id . ")";
                            pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
                            
                            // Add student notification
                            createStudentNotification(
                                $connection,
                                $student_id,
                                'Registration Approved!',
                                'Congratulations! Your registration has been approved. You can now proceed with the next steps.',
                                'success',
                                'high',
                                'student_profile.php'
                            );
                        }
                    }
                } elseif ($action === 'reject') {
                    // Get student information before deletion
                    $studentQuery = "SELECT email, first_name, last_name, extension_name FROM students WHERE student_id = $1 AND status = 'under_registration'";
                    $studentResult = pg_query_params($connection, $studentQuery, [$student_id]);
                    $student = pg_fetch_assoc($studentResult);
                    
                    if ($student) {
                        // Get and delete ALL temporary files before deleting database records
                        
                        // 1. Delete enrollment form file
                        $enrollmentQuery = "SELECT file_path FROM enrollment_forms WHERE student_id = $1";
                        $enrollmentResult = pg_query_params($connection, $enrollmentQuery, [$student_id]);
                        
                        if ($enrollmentRow = pg_fetch_assoc($enrollmentResult)) {
                            $tempFilePath = $enrollmentRow['file_path'];
                            if (file_exists($tempFilePath)) {
                                unlink($tempFilePath);
                            }
                        }
                        
                        // 2. Delete document files (letter to mayor and certificate of indigency)
                        $documentsQuery = "SELECT file_path FROM documents WHERE student_id = $1";
                        $documentsResult = pg_query_params($connection, $documentsQuery, [$student_id]);
                        
                        while ($docRow = pg_fetch_assoc($documentsResult)) {
                            $docFilePath = $docRow['file_path'];
                            if (file_exists($docFilePath)) {
                                unlink($docFilePath);
                            }
                        }
                        
                        // 3. Clean up any remaining files in organized temp directories using FilePathConfig
                        $pathConfig = FilePathConfig::getInstance();
                        $tempFolders = ['enrollment_forms', 'letter_mayor', 'indigency', 'id_pictures', 'grades'];
                        
                        foreach ($tempFolders as $folderName) {
                            $dir = $pathConfig->getTempPath($folderName);
                            if (is_dir($dir)) {
                                $files = glob($dir . $student_id . '_*');
                                foreach ($files as $file) {
                                    if (is_file($file)) {
                                        unlink($file);
                                    }
                                }
                            }
                        }
                        
                        // Delete database records
                        // Note: 'under_registration' students only have records in students and documents tables
                        // and may have household_block_attempts references
                        
                        // OPTION 1 (Default): Nullify references - preserves block history for audit
                        @pg_query_params($connection, 
                            "UPDATE household_block_attempts SET blocked_by_student_id = NULL WHERE blocked_by_student_id = $1", 
                            [$student_id]
                        );
                        
                        // OPTION 2 (Alternative): Delete orphaned attempts - cleaner approach
                        // Uncomment this instead of Option 1 to automatically clean up block attempts
                        // This is useful when the blocked student is no longer relevant
                        // @pg_query_params($connection, 
                        //     "DELETE FROM household_block_attempts WHERE blocked_by_student_id = $1", 
                        //     [$student_id]
                        // );
                        
                        @pg_query_params($connection, "DELETE FROM documents WHERE student_id = $1", [$student_id]);
                        
                        // Delete the student record
                        $deleteResult = pg_query_params($connection, "DELETE FROM students WHERE student_id = $1", [$student_id]);
                        
                        if ($deleteResult) {
                            $success_count++;
                            
                            // Send rejection email
                            sendApprovalEmail($student['email'], $student['first_name'], $student['last_name'], $student['extension_name'], false, '');
                            
                            // Add admin notification
                            $student_name = trim($student['first_name'] . ' ' . $student['last_name'] . ' ' . $student['extension_name']);
                            $notification_msg = "Registration rejected and removed for student: " . $student_name . " (ID: " . $student_id . ") - Slot freed up and files deleted";
                            pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
                        }
                    }
                }
            }
            
            $action_text = $action === 'approve' ? 'approved' : 'rejected';
            $_SESSION['success_message'] = "$success_count registration(s) $action_text successfully!";
        }
        
        header("Location: review_registrations.php?" . http_build_query($_GET));
        exit;
    }
    
    // Handle individual actions
    if (isset($_POST['action']) && isset($_POST['student_id'])) {
        $student_id = trim($_POST['student_id']); // Remove intval for TEXT student_id
        $action = $_POST['action'];
        $remarks = trim($_POST['remarks'] ?? '');
        
        if ($action === 'approve') {
            error_log("=== APPROVAL WORKFLOW START for student: $student_id ===");
            
            // Get slot's academic year first to populate student's first_registered_academic_year
            $slotQuery = "SELECT ss.academic_year, ss.semester 
                         FROM students s 
                         LEFT JOIN signup_slots ss ON s.slot_id = ss.slot_id 
                         WHERE s.student_id = $1";
            $slotResult = pg_query_params($connection, $slotQuery, [$student_id]);
            $slotData = pg_fetch_assoc($slotResult);
            
            error_log("Step 1: Got slot data - Academic Year: " . ($slotData['academic_year'] ?? 'NULL'));
            
            // Update student status to applicant and set first_registered_academic_year from slot
            $updateQuery = "UPDATE students 
                           SET status = 'applicant', 
                               needs_document_upload = FALSE,
                               documents_submitted = TRUE,
                               first_registered_academic_year = $2,
                               current_academic_year = $2
                           WHERE student_id = $1";
            $result = pg_query_params($connection, $updateQuery, [
                $student_id,
                $slotData['academic_year'] ?? null
            ]);
            
            error_log("Step 2: Updated student status to applicant - Result: " . ($result ? 'SUCCESS' : 'FAILED'));
            
            if ($result) {
                // Get student information for school_student_ids tracking
                $studentInfoQuery = "SELECT s.school_student_id, s.university_id, s.first_name, s.last_name, u.name as university_name
                                    FROM students s
                                    LEFT JOIN universities u ON s.university_id = u.university_id
                                    WHERE s.student_id = $1";
                $studentInfoResult = pg_query_params($connection, $studentInfoQuery, [$student_id]);
                $studentInfo = pg_fetch_assoc($studentInfoResult);
                
                // Insert into school_student_ids table now that admin has approved
                if ($studentInfo && !empty($studentInfo['school_student_id'])) {
                    $schoolIdInsert = "INSERT INTO school_student_ids (
                        student_id, 
                        university_id, 
                        school_student_id, 
                        university_name,
                        first_name,
                        last_name,
                        registered_at,
                        status,
                        notes
                    ) VALUES ($1, $2, $3, $4, $5, $6, NOW(), 'active', 'Approved by admin')
                    ON CONFLICT (university_id, school_student_id) DO NOTHING";
                    
                    pg_query_params($connection, $schoolIdInsert, [
                        $student_id,
                        $studentInfo['university_id'],
                        $studentInfo['school_student_id'],
                        $studentInfo['university_name'],
                        $studentInfo['first_name'],
                        $studentInfo['last_name']
                    ]);
                }
                
                // Update or create course mapping after approval
                // Course mapping logic removed - no longer needed
                // Students will declare their year level at enrollment
                
                error_log("Step 3: About to call moveToPermStorage for student: $student_id");
                
                // Use UnifiedFileService to move all documents from temp to permanent storage
                $moveResult = $fileService->moveToPermStorage($student_id, $_SESSION['admin_id'] ?? null);
                
                error_log("Step 4: moveToPermStorage completed - Success: " . ($moveResult['success'] ? 'YES' : 'NO') . ", Moved: " . ($moveResult['moved_count'] ?? 0) . ", Errors: " . count($moveResult['errors'] ?? []));
                
                if ($moveResult['success']) {
                    error_log("UnifiedFileService: Successfully moved " . $moveResult['moved_count'] . " documents for student $student_id");
                } else {
                    error_log("UnifiedFileService: Error moving documents for student $student_id - " . ($moveResult['errors'][0] ?? 'Unknown error'));
                    // Log all errors
                    foreach ($moveResult['errors'] ?? [] as $error) {
                        error_log("  - File move error: $error");
                    }
                }
                
                // Get student email for notification
                $emailQuery = "SELECT email, first_name, last_name, extension_name FROM students WHERE student_id = $1";
                $emailResult = pg_query_params($connection, $emailQuery, [$student_id]);
                $student = pg_fetch_assoc($emailResult);

                // Send approval email
                sendApprovalEmail($student['email'], $student['first_name'], $student['last_name'], $student['extension_name'], true, $remarks);

                // Add admin notification
                $student_name = trim($student['first_name'] . ' ' . $student['last_name'] . ' ' . $student['extension_name']);
                $notification_msg = "Registration approved for student: " . $student_name . " (ID: " . $student_id . ")";
                pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
                
                // Add student notification
                createStudentNotification(
                    $connection,
                    $student_id,
                    'Registration Approved!',
                    'Congratulations! Your registration has been approved by the admin. You can now proceed with your application.',
                    'success',
                    'high',
                    'student_dashboard.php'
                );
                
                // Log to audit trail
                $audit_query = "INSERT INTO audit_logs (
                    user_id, user_type, username, event_type, event_category, 
                    action_description, status, ip_address, affected_table, 
                    affected_record_id, metadata
                ) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11)";
                
                pg_query_params($connection, $audit_query, [
                    $_SESSION['admin_id'] ?? null,
                    'admin',
                    $_SESSION['admin_username'] ?? 'unknown',
                    'applicant_approved',
                    'applicant_management',
                    "Student $student_id registered and approved",
                    'success',
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    'students',
                    null,
                    json_encode([
                        'student_id' => $student_id,
                        'student_name' => $student_name,
                        'remarks' => $remarks,
                        'files_moved' => $moveResult['moved_count'] ?? 0
                    ])
                ]);
                
                $_SESSION['success_message'] = "Registration approved successfully!";
            }
        } elseif ($action === 'reject') {
            // Get student information before deletion
            $studentQuery = "SELECT email, first_name, last_name, extension_name FROM students WHERE student_id = $1";
            $studentResult = pg_query_params($connection, $studentQuery, [$student_id]);
            $student = pg_fetch_assoc($studentResult);
            
            if ($student) {
                $student_name = trim($student['first_name'] . ' ' . $student['last_name'] . ' ' . $student['extension_name']);
                $files_deleted = 0;
                
                // Get all document file paths from documents table
                $documentsQuery = "SELECT file_path FROM documents WHERE student_id = $1";
                $documentsResult = pg_query_params($connection, $documentsQuery, [$student_id]);
                
                while ($docRow = pg_fetch_assoc($documentsResult)) {
                    $mainFilePath = $docRow['file_path'];
                    
                    // Delete main file
                    if (!empty($mainFilePath) && file_exists($mainFilePath)) {
                        unlink($mainFilePath);
                        $files_deleted++;
                    }
                    
                    // Delete associated files (.ocr.txt, .verify.json, .tsv, .confidence.json, .ocr.json)
                    if (!empty($mainFilePath)) {
                        $associatedExtensions = ['.ocr.txt', '.verify.json', '.confidence.json', '.tsv', '.ocr.json'];
                        foreach ($associatedExtensions as $ext) {
                            $associatedFile = $mainFilePath . $ext;
                            if (file_exists($associatedFile)) {
                                unlink($associatedFile);
                                $files_deleted++;
                            }
                        }
                    }
                }
                
                // Clean up any remaining files in temp directories using FilePathConfig
                $pathConfig = FilePathConfig::getInstance();
                $tempFolders = ['id_pictures', 'enrollment_forms', 'letter_mayor', 'indigency', 'grades'];
                
                foreach ($tempFolders as $folderName) {
                    $dir = $pathConfig->getTempPath($folderName);
                    if (is_dir($dir)) {
                        // Get all files matching student_id pattern (including associated files)
                        $files = glob($dir . $student_id . '_*');
                        foreach ($files as $file) {
                            if (is_file($file)) {
                                unlink($file);
                                $files_deleted++;
                            }
                        }
                    }
                }
                
                // NEW: Clean up student-organized permanent directories using FilePathConfig
                $permanentFolders = ['id_pictures', 'enrollment_forms', 'letter_to_mayor', 'indigency', 'grades'];
                
                foreach ($permanentFolders as $folderName) {
                    $dir = $pathConfig->getStudentPath($folderName) . DIRECTORY_SEPARATOR . $student_id . DIRECTORY_SEPARATOR;
                    if (is_dir($dir)) {
                        $files = glob($dir . '*');
                        foreach ($files as $file) {
                            if (is_file($file)) {
                                unlink($file);
                                $files_deleted++;
                            }
                        }
                        // Remove directory if empty
                        @rmdir($dir);
                    }
                }
                
                // Delete database records
                // Note: Students with status 'under_registration' should ONLY have records in:
                // - students table (main record)
                // - documents table (uploaded verification documents)
                // - household_block_attempts (if they blocked other registration attempts)
                // They won't have records in distributions, qr_logs, grade_uploads, etc. since they haven't been approved yet
                
                // OPTION 1 (Default): Nullify references - preserves block history for audit
                @pg_query_params($connection, 
                    "UPDATE household_block_attempts SET blocked_by_student_id = NULL WHERE blocked_by_student_id = $1", 
                    [$student_id]
                );
                
                // OPTION 2 (Alternative): Delete orphaned attempts - cleaner approach
                // Uncomment this instead of Option 1 to automatically clean up block attempts
                // This is useful when the blocked student is no longer relevant
                // @pg_query_params($connection, 
                //     "DELETE FROM household_block_attempts WHERE blocked_by_student_id = $1", 
                //     [$student_id]
                // );
                
                // Delete uploaded documents
                @pg_query_params($connection, "DELETE FROM documents WHERE student_id = $1", [$student_id]);
                
                // Delete the student record - this automatically frees up the slot
                $deleteResult = pg_query_params($connection, "DELETE FROM students WHERE student_id = $1", [$student_id]);
                
                if ($deleteResult) {
                    // Send rejection email
                    sendApprovalEmail($student['email'], $student['first_name'], $student['last_name'], $student['extension_name'], false, $remarks);
                    
                    // Add admin notification
                    $notification_msg = "Registration rejected for student: " . $student_name . " (ID: " . $student_id . ")" . ($remarks ? " - Reason: " . $remarks : "");
                    pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
                    
                    // Log to audit trail
                    $audit_query = "INSERT INTO audit_logs (
                        user_id, user_type, username, event_type, event_category, 
                        action_description, status, ip_address, affected_table, 
                        affected_record_id, metadata
                    ) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11)";
                    
                    pg_query_params($connection, $audit_query, [
                        $_SESSION['admin_id'] ?? null,
                        'admin',
                        $_SESSION['admin_username'] ?? 'unknown',
                        'applicant_rejected',
                        'applicant_management',
                        "Student $student_id registration rejected and deleted",
                        'success',
                        $_SERVER['REMOTE_ADDR'] ?? null,
                        'students',
                        null,
                        json_encode([
                            'student_id' => $student_id,
                            'student_name' => $student_name,
                            'remarks' => $remarks,
                            'files_deleted' => $files_deleted
                        ])
                    ]);
                    
                    $_SESSION['success_message'] = "Registration rejected. Student data removed and $files_deleted files deleted. Slot has been freed up.";
                } else {
                    $_SESSION['error_message'] = "Error rejecting registration.";
                }
            } else {
                $_SESSION['error_message'] = "Student not found.";
            }
        }
        
        header("Location: review_registrations.php");
        exit;
    }
}

function sendApprovalEmail($email, $firstName, $lastName, $extensionName, $approved, $remarks = '') {
    $mail = new PHPMailer(true);
    
    try {
        require_once __DIR__ . '/../../includes/env_url_helper.php';
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'dilucayaka02@gmail.com'; // CHANGE for production
        $mail->Password   = 'jlld eygl hksj flvg';    // CHANGE for production
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom('dilucayaka02@gmail.com', 'EducAid');
        $mail->addAddress($email);

        $mail->isHTML(true);
        // Use helper for environment-aware login URL
        $loginUrl = getUnifiedLoginUrl();
        
        if ($approved) {
            $mail->Subject = 'EducAid Registration Approved';
            $fullName = trim($firstName . ' ' . $lastName . ' ' . $extensionName);
            $mail->Body    = "
                <h3>Registration Approved!</h3>
                <p>Dear {$fullName},</p>
                <p>Your EducAid registration has been <strong>approved</strong>. You can now log in to your account and proceed with your application.</p>
                " . (!empty($remarks) ? "<p><strong>Admin Notes:</strong> {$remarks}</p>" : "") . "
                <p><a href='" . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . "' style='background:#28a745;color:#fff;padding:10px 16px;border-radius:6px;text-decoration:none;display:inline-block;font-weight:600;'>Login Now</a></p>
                <p>Best regards,<br>EducAid Admin Team</p>
            ";
        } else {
            $mail->Subject = 'EducAid Registration Update';
            $fullName = trim($firstName . ' ' . $lastName . ' ' . $extensionName);
            $mail->Body    = "
                <h3>Registration Status Update</h3>
                <p>Dear {$fullName},</p>
                <p>Thank you for your interest in EducAid. Unfortunately, your registration could not be approved at this time.</p>
                " . (!empty($remarks) ? "<p><strong>Reason:</strong> {$remarks}</p>" : "") . "
                <p>If you believe this is an error or would like to reapply, please contact our office. When ready, you can attempt a new registration by clicking below.</p>
                <p><a href='" . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . "' style='background:#0d6efd;color:#fff;padding:10px 16px;border-radius:6px;text-decoration:none;display:inline-block;font-weight:600;'>Login Now</a></p>
                <p>Best regards,<br>EducAid Admin Team</p>
            ";
        }

        $mail->send();
    } catch (Exception $e) {
        error_log("Email could not be sent. Mailer Error: {$mail->ErrorInfo}");
    }
}

// Pagination and filtering
$limit = 25;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $limit;

// Search and filter parameters
$search = trim($_GET['search'] ?? '');
$barangay_filter = $_GET['barangay'] ?? '';
$university_filter = $_GET['university'] ?? '';
$year_level_filter = $_GET['year_level'] ?? '';
$confidence_filter = $_GET['confidence'] ?? '';
$sort_by = $_GET['sort'] ?? 'application_date';
$sort_order = $_GET['order'] ?? 'DESC';

// Build WHERE clause - exclude archived students
$whereConditions = ["s.status = 'under_registration'", "(s.is_archived = FALSE OR s.is_archived IS NULL)"];
$params = [];
$paramCount = 1;

if (!empty($search)) {
    $whereConditions[] = "(s.first_name ILIKE $" . $paramCount . " OR s.last_name ILIKE $" . $paramCount . " OR s.email ILIKE $" . $paramCount . ")";
    $params[] = "%$search%";
    $paramCount++;
}

if (!empty($barangay_filter)) {
    $whereConditions[] = "s.barangay_id = $" . $paramCount;
    $params[] = $barangay_filter;
    $paramCount++;
}

if (!empty($university_filter)) {
    $whereConditions[] = "s.university_id = $" . $paramCount;
    $params[] = $university_filter;
    $paramCount++;
}

if (!empty($year_level_filter)) {
    $whereConditions[] = "s.year_level_id = $" . $paramCount;
    $params[] = $year_level_filter;
    $paramCount++;
}

if (!empty($confidence_filter)) {
    if ($confidence_filter === 'very_high') {
        $whereConditions[] = "COALESCE(s.confidence_score, calculate_confidence_score(s.student_id)) >= 85";
    } elseif ($confidence_filter === 'high') {
        $whereConditions[] = "COALESCE(s.confidence_score, calculate_confidence_score(s.student_id)) >= 70 AND COALESCE(s.confidence_score, calculate_confidence_score(s.student_id)) < 85";
    } elseif ($confidence_filter === 'medium') {
        $whereConditions[] = "COALESCE(s.confidence_score, calculate_confidence_score(s.student_id)) >= 50 AND COALESCE(s.confidence_score, calculate_confidence_score(s.student_id)) < 70";
    } elseif ($confidence_filter === 'low') {
        $whereConditions[] = "COALESCE(s.confidence_score, calculate_confidence_score(s.student_id)) < 50";
    }
}

$whereClause = implode(' AND ', $whereConditions);

// Valid sort columns - add confidence_score
$validSorts = ['application_date', 'first_name', 'last_name', 'confidence_score'];
if (!in_array($sort_by, $validSorts)) $sort_by = 'application_date';
if (!in_array($sort_order, ['ASC', 'DESC'])) $sort_order = 'DESC';

// Count total records
$countQuery = "SELECT COUNT(*) FROM students s
               LEFT JOIN barangays b ON s.barangay_id = b.barangay_id
               LEFT JOIN universities u ON s.university_id = u.university_id
               LEFT JOIN year_levels yl ON s.year_level_id = yl.year_level_id
               WHERE $whereClause";

$countResult = pg_query_params($connection, $countQuery, $params);
$totalRecords = intval(pg_fetch_result($countResult, 0, 0));
$totalPages = ceil($totalRecords / $limit);

// Fetch pending registrations with pagination including confidence scores
$query = "SELECT s.*, b.name as barangay_name, u.name as university_name, yl.name as year_level_name,
                 COALESCE(s.confidence_score, calculate_confidence_score(s.student_id)) as confidence_score,
                 get_confidence_level(COALESCE(s.confidence_score, calculate_confidence_score(s.student_id))) as confidence_level
          FROM students s
          LEFT JOIN barangays b ON s.barangay_id = b.barangay_id
          LEFT JOIN universities u ON s.university_id = u.university_id
          LEFT JOIN year_levels yl ON s.year_level_id = yl.year_level_id
          WHERE $whereClause
          ORDER BY s.student_id
          LIMIT $limit OFFSET $offset";

$result = pg_query_params($connection, $query, $params);
$pendingRegistrations = [];
while ($row = pg_fetch_assoc($result)) {
    $pendingRegistrations[] = $row;
}

// Handle AJAX requests for real-time updates
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    // Return only the table body content for AJAX updates
    ob_start();
    ?>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="fw-bold mb-1">Review Registrations</h1>
            <p class="text-muted mb-0">Review and approve/reject pending student registrations.</p>
        </div>
        <div class="text-end">
            <span class="badge bg-warning fs-6"><?php echo $totalRecords; ?> Pending</span>
        </div>
    </div>
    
    <tbody>
        <?php if (empty($pendingRegistrations)): ?>
            <tr>
                <td colspan="8" class="text-center text-muted py-4">
                    <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                    No pending registrations found.
                </td>
            </tr>
        <?php else: ?>
            <?php foreach ($pendingRegistrations as $registration): ?>
                <tr>
                    <td class="text-center">
                        <input type="checkbox" class="form-check-input row-select" value="<?php echo $registration['student_id']; ?>" onchange="updateSelection()">
                    </td>
                    <td>
                        <?php if ($registration['photo_path']): ?>
                            <img src="<?php echo htmlspecialchars($registration['photo_path']); ?>" 
                                 alt="Student Photo" class="student-photo">
                        <?php else: ?>
                            <div class="student-photo bg-secondary d-flex align-items-center justify-content-center">
                                <i class="bi bi-person text-white"></i>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="fw-bold"><?php echo htmlspecialchars($registration['first_name'] . ' ' . $registration['last_name']); ?></div>
                        <small class="text-muted"><?php echo htmlspecialchars($registration['email']); ?></small>
                    </td>
                    <td>
                        <div class="fw-bold"><?php echo htmlspecialchars($registration['barangay_name'] ?? 'N/A'); ?></div>
                        <small class="text-muted"><?php echo htmlspecialchars($registration['university_name'] ?? 'N/A'); ?></small>
                    </td>
                    <td>
                        <?php
                        $confidence = floatval($registration['confidence_score']);
                        $level = $registration['confidence_level'];
                        $badgeClass = '';
                        switch($level) {
                            case 'Very High': $badgeClass = 'bg-success'; break;
                            case 'High': $badgeClass = 'bg-info'; break;
                            case 'Medium': $badgeClass = 'bg-warning'; break;
                            case 'Low': $badgeClass = 'bg-danger'; break;
                            default: $badgeClass = 'bg-secondary';
                        }
                        ?>
                        <div class="confidence-badge <?php echo $badgeClass; ?>" title="<?php echo $level; ?>">
                            <?php echo round($confidence, 1); ?>%
                        </div>
                        <div>
                            <small class="text-muted"><?php echo $level; ?></small>
                        </div>
                    </td>
                    <td>
                        <small class="text-muted"><?php echo date('M d, Y', strtotime($registration['created_at'])); ?></small>
                    </td>
                    <td>
                        <div class="action-buttons">
                            <button class="btn btn-info btn-sm" 
                                    onclick="viewDetails('<?php echo $registration['student_id']; ?>')"
                                    title="View Details">
                                <i class="bi bi-eye"></i>
                            </button>
                            <button class="btn btn-success btn-sm" 
                                    onclick="showActionModal('<?php echo $registration['student_id']; ?>', 'approve', '<?php echo htmlspecialchars($registration['first_name'] . ' ' . $registration['last_name']); ?>')"
                                    title="Approve">
                                <i class="bi bi-check-lg"></i>
                            </button>
                            <button class="btn btn-danger btn-sm" 
                                    onclick="showActionModal('<?php echo $registration['student_id']; ?>', 'reject', '<?php echo htmlspecialchars($registration['first_name'] . ' ' . $registration['last_name']); ?>')"
                                    title="Reject">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
    
    <div class="pagination-info">
        Showing <?php echo min(($page - 1) * $limit + 1, $totalRecords); ?> to <?php echo min($page * $limit, $totalRecords); ?> of <?php echo $totalRecords; ?> entries
    </div>
    <?php
    echo ob_get_clean();
    exit;
}

// Fetch filter options
$barangays = pg_fetch_all(pg_query($connection, "SELECT barangay_id, name FROM barangays ORDER BY name"));
$universities = pg_fetch_all(pg_query($connection, "SELECT university_id, name FROM universities ORDER BY name"));
$yearLevels = pg_fetch_all(pg_query($connection, "SELECT year_level_id, name FROM year_levels ORDER BY sort_order"));
?>

<?php $page_title='Review Registrations'; $extra_css=['../../assets/css/admin/table_core.css']; include __DIR__ . '/../../includes/admin/admin_head.php'; ?>
<style>
    /* existing page styles */
        .filter-section {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        /* Filter section responsive grid */
        .filter-grid {
            display: grid;
            gap: 16px;
            grid-template-columns: 1fr;
        }
        
        /* Tablet: 2 columns */
        @media (min-width: 576px) {
            .filter-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        /* Medium screens: 3 columns */
        @media (min-width: 768px) {
            .filter-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }
        
        /* Large screens: all filters in one row */
        @media (min-width: 1200px) {
            .filter-grid {
                grid-template-columns: 2fr 1.5fr 2fr 1fr 1.5fr auto;
            }
        }
        
        .filter-grid .form-label {
            font-size: 0.875rem;
            font-weight: 500;
            margin-bottom: 6px;
            white-space: nowrap;
        }
        
        .filter-grid .form-control,
        .filter-grid .form-select {
            width: 100%;
            min-width: 0;
        }
        
        .filter-buttons {
            display: flex;
            gap: 8px;
            align-items: flex-end;
        }
        
        @media (max-width: 575.98px) {
            .filter-buttons {
                grid-column: 1 / -1;
            }
        }
        
        /* Image Viewer Enhancements */
        .doc-viewer-wrapper { position: relative; background:#111; border-radius:8px; overflow:hidden; touch-action:none; user-select:none; }
        .doc-viewer-stage { position:relative; width:100%; height:70vh; max-height:800px; display:flex; align-items:center; justify-content:center; background:#111; }
        .doc-viewer-stage img { max-width:100%; max-height:100%; will-change: transform; cursor:grab; transition: filter .2s; z-index:1; }
        .doc-viewer-stage img:active { cursor:grabbing; }
        .doc-controls { position:absolute; top:10px; right:10px; display:flex; gap:6px; z-index:50; pointer-events:auto; }
        .doc-controls button { background:rgba(0,0,0,.55); color:#fff; border:1px solid rgba(255,255,255,.2); padding:6px 10px; border-radius:6px; font-size:14px; backdrop-filter: blur(4px); pointer-events:auto; }
        .doc-controls button:hover { background:rgba(255,255,255,.15); }
        .doc-zoom-indicator { position:absolute; left:12px; top:12px; background:rgba(0,0,0,.55); color:#fff; padding:4px 10px; border-radius:20px; font-size:12px; font-weight:600; letter-spacing:.5px; z-index:50; pointer-events:none; }
        .doc-hint { position:absolute; bottom:10px; left:50%; transform:translateX(-50%); background:rgba(0,0,0,.55); color:#fff; padding:4px 12px; border-radius:20px; font-size:12px; opacity:.85; z-index:50; pointer-events:none; }
        @media (max-width: 768px){ .doc-viewer-stage{ height:60vh; } }
        /* Table layout - scroll-friendly with min-width to prevent cramping */
        .table tbody tr:hover { background-color: #f8f9fa; }
        .action-buttons {
            display: flex;
            gap: 5px;
        }
        .student-photo {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
        }
        .status-badge {
            font-size: 0.75em;
            padding: 4px 8px;
        }
        
        /* ==================== SCROLL-FRIENDLY TABLE STYLES ==================== */
        /* Set min-width to allow horizontal scroll instead of cramping content */
        .registrations-table {
            min-width: 1200px;
            table-layout: fixed;
        }
        
        /* Column widths - properly spaced for readability */
        .registrations-table thead th { 
            font-size: .85rem; 
            letter-spacing: .25px;
            white-space: nowrap;
            background: #f8f9fa;
            color: #374151;
            padding: 12px 14px;
            border: none;
            border-bottom: 2px solid #e2e8f0;
            font-weight: 600;
        }
        .registrations-table th.col-select { width: 50px; }
        .registrations-table th.col-name { width: 180px; }
        .registrations-table th.col-contact { width: 200px; }
        .registrations-table th.col-barangay { width: 120px; }
        .registrations-table th.col-university { width: 200px; }
        .registrations-table th.col-course { width: 180px; }
        .registrations-table th.col-year { width: 80px; }
        .registrations-table th.col-applied { width: 110px; }
        .registrations-table th.col-confidence { width: 120px; }
        .registrations-table th.col-actions { width: 160px; }
        
        /* Cell content styling - allow wrapping within fixed widths */
        .registrations-table td {
            padding: 12px 14px;
            vertical-align: middle;
            white-space: normal;
            word-break: break-word;
            overflow-wrap: break-word;
        }
        
        .registrations-table td small { display: block; font-size: .8rem; line-height: 1.3; }
        .registrations-table td .badge { font-size: .75rem; }
        .registrations-table td .fw-semibold { font-size: .9rem; }
        .registrations-table td .primary-line { font-size: .85rem; font-weight: 600; color: #212529; }
        .registrations-table td .secondary-line { font-size: .8rem; color: #6c757d; }
        
        /* Improve badge contrast */
        .registrations-table td .badge.bg-warning { color: #212529; }
        .registrations-table td .badge.bg-primary { background: #0d6efd; }
        
        /* Sticky first column (Select checkbox) */
        .registrations-table th:first-child,
        .registrations-table td:first-child {
            position: sticky;
            left: 0;
            z-index: 2;
            background: #fff;
            box-shadow: 2px 0 4px rgba(0,0,0,0.05);
        }
        .registrations-table thead th:first-child {
            background: #f8f9fa;
            z-index: 3;
        }
        
        /* Mobile: Let table_core.css handle card layout - just reset table-specific styles */
        @media (max-width: 767.98px){
            /* Reset min-width to allow table_core.css card layout */
            .registrations-table {
                min-width: 100% !important;
                table-layout: auto !important;
            }
            
            /* Reset sticky positioning */
            .registrations-table th:first-child,
            .registrations-table td:first-child {
                position: static !important;
                box-shadow: none !important;
                background: transparent !important;
            }
            
            /* Reset column widths */
            .registrations-table th,
            .registrations-table td { 
                width: auto !important; 
            }
            
            /* Reset thead styles for hiding */
            .registrations-table thead th {
                background: transparent !important;
            }
            
            /* Ensure cell padding works with labels */
            .registrations-table td {
                padding: 12px 15px 12px 130px !important;
            }
            
            /* Fix Select cell - use flexbox layout like table_core.css expects */
            .registrations-table td[data-label="Select"] {
                display: flex !important;
                align-items: center !important;
                justify-content: space-between !important;
                padding: 12px 15px !important;
            }
            .registrations-table td[data-label="Select"]::before {
                position: static !important;
                left: auto !important;
                top: auto !important;
                width: auto !important;
                margin: 0 !important;
            }
        }
                .bulk-actions {
                        background: #ffffff;
                        border: 1px solid #dee2e6;
                        padding: 12px 14px;
                        border-radius: 10px;
                        margin-bottom: 16px;
                        display: none;
                        position: sticky;
                        top: calc(var(--admin-topbar-h, 52px) + var(--admin-header-h, 56px) + 8px);
                        z-index: 1020;
                        box-shadow: 0 6px 14px rgba(0,0,0,.06);
                        backdrop-filter: saturate(1.2) blur(2px);
                    }
                @media (max-width: 767.98px){
                    .bulk-actions { padding: 10px 12px; border-radius: 12px; }
                    .bulk-actions .btn { font-weight: 600; }
                }
        .pagination-info {
            color: #6c757d;
            font-size: 0.9em;
        }
        .sort-link {
            color: white;
            text-decoration: none;
        }
        .sort-link:hover {
            color: #ffc107;
        }
        .sort-active {
            color: #ffc107 !important;
        }
        .confidence-badge {
            font-size: 0.8em;
            font-weight: 600;
            padding: 4px 8px;
            border-radius: 12px;
            display: inline-block;
            min-width: 50px;
            text-align: center;
        }
        .quick-actions {
            background: #f8f9fa;
            color: #1e293b;
            padding: 20px;
            border-radius: 16px;
            margin-bottom: 20px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.06);
        }
        .quick-actions .qa-row {
            display: flex;
            flex-direction: column;
            gap: 16px;
            align-items: stretch;
        }
        .quick-actions .qa-text {
            flex: 1;
            min-width: 0;
        }
        .quick-actions h5 {
            color: #1e293b;
            margin-bottom: 5px;
            font-size: 1.1rem;
            font-weight: 600;
        }
        .quick-actions small {
            color: #64748b;
            display: block;
            line-height: 1.4;
        }
        .qa-actions {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        /* Tablet and up - side by side layout */
        @media (min-width: 992px) {
            .quick-actions .qa-row {
                flex-direction: row;
                align-items: center;
                justify-content: space-between;
            }
            .qa-actions {
                flex-direction: row;
                flex-shrink: 0;
            }
        }
        
        /* Medium screens - stack buttons but keep text/buttons side by side if space */
        @media (min-width: 768px) and (max-width: 991.98px) {
            .quick-actions .qa-row {
                flex-direction: row;
                flex-wrap: wrap;
                align-items: center;
            }
            .quick-actions .qa-text {
                flex: 1 1 100%;
                margin-bottom: 12px;
            }
            .qa-actions {
                flex: 1 1 100%;
                flex-direction: row;
                flex-wrap: wrap;
                justify-content: flex-start;
            }
        }
        
        .auto-approve-btn {
            background: linear-gradient(45deg, #28a745, #20c997);
            border: none;
            color: white;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 14px 16px;
            min-width: 220px;
            border-radius: 12px;
            transition: all 0.3s ease;
        }
        .auto-approve-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(40, 167, 69, 0.3);
            color: white;
        }
        .refresh-btn {
            background: linear-gradient(45deg, #17a2b8, #6f42c1);
            border: none;
            color: white;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 14px 16px;
            min-width: 180px;
            border-radius: 12px;
        }
        .refresh-btn:hover {
            color: white;
            transform: translateY(-1px);
        }
        
                /* Zoom/high-DPI handling, min-widths, and scrollbars are centralized in table_core.css. */

                /* ==================== Modal Sizing (Responsive) ==================== */
                /* General: constrain very tall content */
                #actionModal .modal-body,
                #studentDetailsModal .modal-body,
                #blacklistModal .modal-body {
                    max-height: calc(100vh - 240px);
                    overflow-y: auto;
                }
        
                @media (max-width: 767.98px){
                    #actionModal .modal-dialog,
                    #studentDetailsModal .modal-dialog,
                    #blacklistModal .modal-dialog {
                        max-width: 95% !important;
                        margin: 1.25rem auto !important;
                    }
                    #actionModal .modal-content,
                    #studentDetailsModal .modal-content,
                    #blacklistModal .modal-content {
                        max-height: 85vh !important;
                        border-radius: 1rem !important;
                    }
                    #actionModal .modal-header,
                    #studentDetailsModal .modal-header,
                    #blacklistModal .modal-header { padding: .6rem .9rem; }
                    #actionModal .modal-body,
                    #studentDetailsModal .modal-body,
                    #blacklistModal .modal-body {
                        padding: .75rem .9rem;
                        max-height: calc(85vh - 120px);
                        overflow-y: auto;
                    }
                    #actionModal .modal-footer,
                    #studentDetailsModal .modal-footer,
                    #blacklistModal .modal-footer { padding: .55rem .9rem; }
                    #studentDetailsModal .modal-dialog,
                    #blacklistModal .modal-dialog { width: auto; }
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
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="fw-bold mb-1">Review Registrations</h1>
                        <p class="text-muted mb-0">Review and approve/reject pending student registrations.</p>
                    </div>
                    <div class="text-end">
                        <span class="badge bg-warning fs-6"><?php echo $totalRecords; ?> Pending</span>
                    </div>
                </div>

                <?php 
                // Store success message for toast
                $toast_message = null;
                if (isset($_SESSION['success_message'])) {
                    $toast_message = $_SESSION['success_message'];
                    unset($_SESSION['success_message']);
                }
                ?>

                <!-- Filters Section -->
                <div class="filter-section">
                    <form method="GET" class="filter-grid">
                        <div>
                            <label class="form-label">Search</label>
                            <input type="text" name="search" class="form-control" placeholder="Name or email..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div>
                            <label class="form-label">Barangay</label>
                            <select name="barangay" class="form-select">
                                <option value="">All Barangays</option>
                                <?php foreach ($barangays as $barangay): ?>
                                    <option value="<?php echo $barangay['barangay_id']; ?>" <?php echo $barangay_filter == $barangay['barangay_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($barangay['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="form-label">University</label>
                            <select name="university" class="form-select">
                                <option value="">All Universities</option>
                                <?php foreach ($universities as $university): ?>
                                    <option value="<?php echo $university['university_id']; ?>" <?php echo $university_filter == $university['university_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($university['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="form-label">Year Level</label>
                            <select name="year_level" class="form-select">
                                <option value="">All Years</option>
                                <?php foreach ($yearLevels as $yearLevel): ?>
                                    <option value="<?php echo $yearLevel['year_level_id']; ?>" <?php echo $year_level_filter == $yearLevel['year_level_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($yearLevel['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="form-label">Confidence</label>
                            <select name="confidence" class="form-select">
                                <option value="">All Levels</option>
                                <option value="very_high" <?php echo $confidence_filter == 'very_high' ? 'selected' : ''; ?>>Very High (85%+)</option>
                                <option value="high" <?php echo $confidence_filter == 'high' ? 'selected' : ''; ?>>High (70-84%)</option>
                                <option value="medium" <?php echo $confidence_filter == 'medium' ? 'selected' : ''; ?>>Medium (50-69%)</option>
                                <option value="low" <?php echo $confidence_filter == 'low' ? 'selected' : ''; ?>>Low (&lt;50%)</option>
                            </select>
                        </div>
                        <div class="filter-buttons">
                            <button type="submit" class="btn btn-primary">Filter</button>
                            <a href="review_registrations.php" class="btn btn-outline-secondary">Clear</a>
                        </div>
                    </form>
                </div>

                <!-- Auto-Approve Section -->
                <div class="quick-actions">
                    <div class="qa-row">
                        <div class="qa-text">
                            <h5 class="mb-1"><i class="bi bi-lightning-charge me-2"></i>Quick Actions</h5>
                            <small>Streamline review process for high-confidence registrations (80%+)</small>
                        </div>
                        <div class="qa-actions">
                            <?php
                            // Count high confidence registrations (80%+) - exclude archived
                            $highConfidenceQuery = "SELECT COUNT(*) FROM students s WHERE s.status = 'under_registration' AND (s.is_archived = FALSE OR s.is_archived IS NULL) AND COALESCE(s.confidence_score, calculate_confidence_score(s.student_id)) >= 80";
                            $highConfidenceResult = pg_query($connection, $highConfidenceQuery);
                            $highConfidenceCount = pg_fetch_result($highConfidenceResult, 0, 0);
                            ?>
                            <?php if ($highConfidenceCount > 0): ?>
                                <button type="button" class="btn auto-approve-btn" onclick="autoApproveHighConfidence()">
                                    <i class="bi bi-lightning"></i> Auto-Approve High Confidence (80%+)
                                    <span class="badge bg-white text-success ms-1"><?php echo $highConfidenceCount; ?></span>
                                </button>
                            <?php else: ?>
                                <button type="button" class="btn btn-outline-light" disabled>
                                    <i class="bi bi-lightning"></i> No High Confidence Registrations
                                </button>
                            <?php endif; ?>
                            <button type="button" class="btn refresh-btn" onclick="refreshConfidenceScores()">
                                <i class="bi bi-arrow-clockwise"></i> Refresh Scores
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Bulk Actions (hidden by default) -->
                <div class="bulk-actions" id="bulkActions">
                    <div class="d-flex align-items-center gap-3">
                        <span><strong id="selectedCount">0</strong> selected</span>
                        <button type="button" class="btn btn-success btn-sm" onclick="bulkAction('approve')">
                            <i class="bi bi-check-circle"></i> Approve Selected
                        </button>
                        <button type="button" class="btn btn-danger btn-sm" onclick="bulkAction('reject')">
                            <i class="bi bi-x-circle"></i> Reject Selected
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="clearSelection()">
                            Clear Selection
                        </button>
                    </div>
                </div>

                <?php if (empty($pendingRegistrations)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-clipboard-check display-1 text-muted"></i>
                        <h3 class="mt-3 text-muted">No Pending Registrations</h3>
                        <p class="text-muted">
                            <?php if (!empty($search) || !empty($barangay_filter) || !empty($university_filter) || !empty($year_level_filter)): ?>
                                No registrations match your filter criteria. <a href="review_registrations.php">Clear filters</a>
                            <?php else: ?>
                                All registrations have been reviewed.
                            <?php endif; ?>
                        </p>
                    </div>
                <?php else: ?>
                    <!-- Results Table -->
                    <div class="table-responsive">
                        <table class="table table-bordered align-middle registrations-table">
                            <thead>
                                <tr>
                                    <th class="col-select checkbox-col">
                                        <input type="checkbox" id="selectAll" onchange="toggleSelectAll()">
                                    </th>
                                    <th class="col-name">
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'first_name', 'order' => $sort_by === 'first_name' && $sort_order === 'ASC' ? 'DESC' : 'ASC'])); ?>" class="sort-link <?php echo $sort_by === 'first_name' ? 'sort-active' : ''; ?>">
                                            Name <?php if ($sort_by === 'first_name') echo $sort_order === 'ASC' ? '↑' : '↓'; ?>
                                        </a>
                                    </th>
                                    <th class="col-contact">Contact</th>
                                    <th class="col-barangay">Barangay</th>
                                    <th class="col-university">University</th>
                                    <th class="col-course">Course</th>
                                    <th class="col-year">Year</th>
                                    <th class="col-applied">
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'application_date', 'order' => $sort_by === 'application_date' && $sort_order === 'ASC' ? 'DESC' : 'ASC'])); ?>" class="sort-link <?php echo $sort_by === 'application_date' ? 'sort-active' : ''; ?>">
                                            Applied <?php if ($sort_by === 'application_date') echo $sort_order === 'ASC' ? '↑' : '↓'; ?>
                                        </a>
                                    </th>
                                    <th class="col-confidence">
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'confidence_score', 'order' => $sort_by === 'confidence_score' && $sort_order === 'ASC' ? 'DESC' : 'ASC'])); ?>" class="sort-link <?php echo $sort_by === 'confidence_score' ? 'sort-active' : ''; ?>">
                                            Confidence <?php if ($sort_by === 'confidence_score') echo $sort_order === 'ASC' ? '↑' : '↓'; ?>
                                        </a>
                                    </th>
                                    <th class="col-actions">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pendingRegistrations as $registration): ?>
                                    <tr>
                                        <td class="checkbox-col" data-label="Select">
                                            <input type="checkbox" class="row-select" value="<?php echo $registration['student_id']; ?>" onchange="updateSelection()">
                                        </td>
                                        <td data-label="Name">
                                            <div class="d-flex align-items-center">
                                                <div>
                                                    <div class="fw-semibold">
                                                        <?php echo htmlspecialchars(trim($registration['first_name'] . ' ' . $registration['last_name'] . ' ' . $registration['extension_name'])); ?>
                                                    </div>
                                                    <small class="text-muted"><?php echo htmlspecialchars($registration['student_id']); ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td data-label="Contact" class="col-contact">
                                            <div class="small" title="<?php echo htmlspecialchars($registration['email'] . ' / ' . $registration['mobile']); ?>">
                                                <div class="primary-line"><?php echo htmlspecialchars($registration['email']); ?></div>
                                                <div class="secondary-line"><?php echo htmlspecialchars($registration['mobile']); ?></div>
                                            </div>
                                        </td>
                                        <td data-label="Barangay" class="col-barangay" title="<?php echo htmlspecialchars($registration['barangay_name']); ?>"><?php echo htmlspecialchars($registration['barangay_name']); ?></td>
                                        <td data-label="University" class="col-university">
                                            <div class="small" title="<?php echo htmlspecialchars($registration['university_name']); ?>">
                                                <div class="primary-line"><?php echo htmlspecialchars($registration['university_name']); ?></div>
                                            </div>
                                        </td>
                                        <td data-label="Course" class="col-course">
                                            <?php if (!empty($registration['course'])): ?>
                                                <div class="small" title="<?php echo htmlspecialchars($registration['course']); ?>">
                                                    <div class="primary-line"><?php echo htmlspecialchars($registration['course']); ?></div>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted small">Not specified</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Year"><?php echo htmlspecialchars($registration['year_level_name']); ?></td>
                                        <td data-label="Applied" class="col-applied">
                                            <small class="text-muted">
                                                <?php echo date('M d, Y', strtotime($registration['application_date'])); ?>
                                            </small>
                                            <small class="text-muted">
                                                <?php echo date('g:i A', strtotime($registration['application_date'])); ?>
                                            </small>
                                        </td>
                                        <td data-label="Confidence">
                                            <?php 
                                            $score = $registration['confidence_score'];
                                            $level = $registration['confidence_level'];
                                            $badgeClass = '';
                                            if ($score >= 85) $badgeClass = 'bg-success';
                                            elseif ($score >= 70) $badgeClass = 'bg-primary';
                                            elseif ($score >= 50) $badgeClass = 'bg-warning';
                                            else $badgeClass = 'bg-danger';
                                            ?>
                                            <div class="d-flex align-items-center">
                                                <span class="badge <?php echo $badgeClass; ?> text-white me-1"><?php echo number_format($score, 1); ?>%</span>
                                                <small class="text-muted"><?php echo $level; ?></small>
                                            </div>
                                        </td>
                                        <td data-label="Actions">
                                            <div class="action-buttons">
                                                <button type="button" class="btn btn-success btn-sm" 
                                                        onclick="showActionModal('<?php echo $registration['student_id']; ?>', 'approve', '<?php echo htmlspecialchars(trim($registration['first_name'] . ' ' . $registration['last_name'] . ' ' . $registration['extension_name'])); ?>')">
                                                    <i class="bi bi-check"></i>
                                                </button>
                                                <button type="button" class="btn btn-danger btn-sm" 
                                                        onclick="showActionModal('<?php echo $registration['student_id']; ?>', 'reject', '<?php echo htmlspecialchars(trim($registration['first_name'] . ' ' . $registration['last_name'] . ' ' . $registration['extension_name'])); ?>')">
                                                    <i class="bi bi-x"></i>
                                                </button>
                                                <button type="button" class="btn btn-outline-info btn-sm" 
                                                        onclick="viewDetails('<?php echo $registration['student_id']; ?>')">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                                <?php if ($_SESSION['admin_role'] === 'super_admin'): ?>
                                                <button type="button" class="btn btn-outline-danger btn-sm" 
                                                        onclick="showBlacklistModal('<?php echo $registration['student_id']; ?>', '<?php echo htmlspecialchars($registration['first_name'] . ' ' . $registration['last_name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($registration['email'], ENT_QUOTES); ?>', {
                                                            barangay: '<?php echo htmlspecialchars($registration['barangay'] ?? 'N/A', ENT_QUOTES); ?>',
                                                            university: '<?php echo htmlspecialchars($registration['university'] ?? 'N/A', ENT_QUOTES); ?>',
                                                            status: 'Under Registration'
                                                        })"
                                                        title="Blacklist Student">
                                                    <i class="bi bi-shield-exclamation"></i>
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                        <div class="d-flex justify-content-between align-items-center mt-4">
                            <div class="pagination-info">
                                Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $limit, $totalRecords); ?> of <?php echo $totalRecords; ?> registrations
                            </div>
                            <nav>
                                <ul class="pagination mb-0">
                                    <?php if ($page > 1): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">Previous</a>
                                        </li>
                                    <?php endif; ?>
                                    
                                    <?php
                                    $start = max(1, $page - 2);
                                    $end = min($totalPages, $page + 2);
                                    
                                    if ($start > 1): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>">1</a>
                                        </li>
                                        <?php if ($start > 2): ?>
                                            <li class="page-item disabled"><span class="page-link">...</span></li>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    
                                    <?php for ($i = $start; $i <= $end; $i++): ?>
                                        <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                                        </li>
                                    <?php endfor; ?>
                                    
                                    <?php if ($end < $totalPages): ?>
                                        <?php if ($end < $totalPages - 1): ?>
                                            <li class="page-item disabled"><span class="page-link">...</span></li>
                                        <?php endif; ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $totalPages])); ?>"><?php echo $totalPages; ?></a>
                                        </li>
                                    <?php endif; ?>
                                    
                                    <?php if ($page < $totalPages): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">Next</a>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </nav>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <!-- Action Modal -->
    <div class="modal fade" id="actionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="actionModalTitle">Confirm Action</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo CSRFProtection::generateToken('review_registrations'); ?>">
                    <div class="modal-body">
                        <input type="hidden" name="student_id" id="modal_student_id">
                        <input type="hidden" name="action" id="modal_action">
                        
                        <p id="action_message"></p>
                        
                        <div class="mb-3">
                            <label for="remarks" class="form-label">Remarks (Optional)</label>
                            <textarea class="form-control" name="remarks" id="remarks" rows="3" 
                                      placeholder="Add any comments or reasons for this decision..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn" id="modal_confirm_btn">Confirm</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Registrant Details Modal -->
    <div class="modal fade" id="studentDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Registrant Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="studentDetailsContent">
                    <!-- Content loaded via AJAX -->
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/js/admin/sidebar.js"></script>
    
    <script>
        // Guard to prevent double-click/rapid re-entry on details fetch
        let detailsRequestInFlight = false;
        // Prepare a reusable modal instance and cleanup handlers
        const detailsModalEl = document.getElementById('studentDetailsModal');
        const detailsModal = detailsModalEl ? bootstrap.Modal.getOrCreateInstance(detailsModalEl, { backdrop: true, keyboard: true, focus: true }) : null;

        function cleanupExtraBackdrops() {
            // Keep at most the number of currently shown modals worth of backdrops
            const openModals = document.querySelectorAll('.modal.show').length;
            const backdrops = Array.from(document.querySelectorAll('.modal-backdrop'));
            if (backdrops.length > openModals) {
                // Remove oldest extras first
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
        let selectedStudents = [];
        
        function toggleSelectAll() {
            const selectAll = document.getElementById('selectAll');
            const checkboxes = document.querySelectorAll('.row-select');
            
            checkboxes.forEach(checkbox => {
                checkbox.checked = selectAll.checked;
            });
            
            updateSelection();
        }

        function updateSelection() {
            const checkboxes = document.querySelectorAll('.row-select:checked');
            selectedStudents = Array.from(checkboxes).map(cb => cb.value);
            
            const count = selectedStudents.length;
            document.getElementById('selectedCount').textContent = count;
            document.getElementById('bulkActions').style.display = count > 0 ? 'block' : 'none';
            
            // Update select all checkbox
            const allCheckboxes = document.querySelectorAll('.row-select');
            const selectAll = document.getElementById('selectAll');
            selectAll.indeterminate = count > 0 && count < allCheckboxes.length;
            selectAll.checked = count === allCheckboxes.length && count > 0;
        }

        function clearSelection() {
            document.querySelectorAll('.row-select').forEach(cb => cb.checked = false);
            document.getElementById('selectAll').checked = false;
            updateSelection();
        }

        function bulkAction(action) {
            if (selectedStudents.length === 0) {
                alert('Please select students first.');
                return;
            }

            const actionText = action === 'approve' ? 'approve' : 'reject';
            const message = `Are you sure you want to ${actionText} ${selectedStudents.length} selected registration(s)?`;
            
            if (!confirm(message)) return;

            // Create form and submit
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="bulk_action" value="${action}">
                <input type="hidden" name="student_ids" value="${selectedStudents.join(',')}">
            `;
            document.body.appendChild(form);
            form.submit();
        }

        function showActionModal(studentId, action, studentName) {
            document.getElementById('modal_student_id').value = studentId;
            document.getElementById('modal_action').value = action;
            
            const title = action === 'approve' ? 'Approve Registration' : 'Reject Registration';
            const message = action === 'approve' 
                ? `Are you sure you want to approve the registration for <strong>${studentName}</strong>? This will allow them to log in and proceed with their application.`
                : `Are you sure you want to reject the registration for <strong>${studentName}</strong>? This action cannot be undone and will free up a slot.`;
            
            document.getElementById('actionModalTitle').textContent = title;
            document.getElementById('action_message').innerHTML = message;
            
            const confirmBtn = document.getElementById('modal_confirm_btn');
            confirmBtn.className = action === 'approve' ? 'btn btn-success' : 'btn btn-danger';
            confirmBtn.textContent = action === 'approve' ? 'Approve' : 'Reject';
            
            new bootstrap.Modal(document.getElementById('actionModal')).show();
        }

        function viewDetails(studentId) {
            if (detailsRequestInFlight) return; // prevent rapid double-clicks
            if (detailsModalEl && detailsModalEl.classList.contains('show')) return; // already open

            detailsRequestInFlight = true;
            fetch(`get_registrant_details.php?id=${studentId}`)
                .then(response => response.text())
                .then(html => {
                    const target = document.getElementById('studentDetailsContent');
                    if (target) target.innerHTML = html;
                    if (detailsModal) detailsModal.show();
                })
                .catch(() => {
                    alert('Error loading registrant details. Please try again.');
                })
                .finally(() => {
                    detailsRequestInFlight = false;
                });
        }

        function autoApproveHighConfidence() {
            if (!confirm('Are you sure you want to auto-approve all registrations with High confidence scores (80%+)?\n\nThis action will approve all students who meet the criteria and cannot be undone.')) {
                return;
            }

            // Show loading state
            const btn = event.target;
            const originalText = btn.innerHTML;
            btn.innerHTML = '<i class="bi bi-spinner bi-spin"></i> Processing...';
            btn.disabled = true;

            fetch('auto_approve_high_confidence.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'auto_approve_high_confidence',
                    min_confidence: 80
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(`Successfully auto-approved ${data.count} high-confidence registrations!`);
                    location.reload();
                } else {
                    alert('Error during auto-approval: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(error => {
                alert('Error during auto-approval. Please try again.');
            })
            .finally(() => {
                btn.innerHTML = originalText;
                btn.disabled = false;
            });
        }

        function refreshConfidenceScores() {
            if (!confirm('This will recalculate confidence scores for all pending registrations. Continue?')) {
                return;
            }

            const btn = event.target;
            const originalText = btn.innerHTML;
            btn.innerHTML = '<i class="bi bi-spinner bi-spin"></i> Refreshing...';
            btn.disabled = true;

            fetch('refresh_confidence_scores.php', {
                method: 'POST'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(`Updated confidence scores for ${data.count} registrations!`);
                    location.reload();
                } else {
                    alert('Error refreshing scores: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(error => {
                alert('Error refreshing scores. Please try again.');
            })
            .finally(() => {
                btn.innerHTML = originalText;
                btn.disabled = false;
            });
        }

        // Real-time updates
        let isUpdating = false;
        let lastUpdateData = null;

        function updateTableData() {
            if (isUpdating) return;
            isUpdating = true;

            const currentUrl = new URL(window.location);
            const params = new URLSearchParams(currentUrl.search);
            params.set('ajax', '1');

            fetch(window.location.pathname + '?' + params.toString())
                .then(response => response.text())
                .then(data => {
                    if (data !== lastUpdateData) {
                        // Parse the response to extract table content and stats
                        const tempDiv = document.createElement('div');
                        tempDiv.innerHTML = data;
                        
                        // Update table body
                        const newTableBody = tempDiv.querySelector('tbody');
                        const currentTableBody = document.querySelector('tbody');
                        if (newTableBody && currentTableBody && newTableBody.innerHTML !== currentTableBody.innerHTML) {
                            currentTableBody.innerHTML = newTableBody.innerHTML;
                        }

                        // Update pending count badge (scope to main content to avoid touching sidebar badges)
                        const newBadge = tempDiv.querySelector('#mainContent .badge.bg-warning');
                        const currentBadge = document.querySelector('#mainContent .badge.bg-warning');
                        if (newBadge && currentBadge) {
                            currentBadge.textContent = newBadge.textContent;
                        }

                        // Update pagination info if it exists
                        const newPaginationInfo = tempDiv.querySelector('.pagination-info');
                        const currentPaginationInfo = document.querySelector('.pagination-info');
                        if (newPaginationInfo && currentPaginationInfo) {
                            currentPaginationInfo.textContent = newPaginationInfo.textContent;
                        }

                        lastUpdateData = data;
                    }
                })
                .catch(error => {
                    console.log('Update failed:', error);
                })
                .finally(() => {
                    isUpdating = false;
                    // Poll for updates periodically. Use a conservative 30s interval to avoid excessive requests.
                    setTimeout(updateTableData, 30000);
                });
        }

        // Start real-time updates when page loads
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(updateTableData, 100);
        });
    </script>

    <!-- Document Viewer Modal (lightweight) -->
    <div class="modal fade" id="documentViewerModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="documentViewerTitle">Document</h5>
                    <button type="button" class="btn btn-outline-primary btn-sm me-2" id="docDownloadBtn" style="display:none;">
                        <i class="bi bi-download"></i> Download
                    </button>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="documentViewerBody">
                    <div class="text-center py-5 text-muted small">Loading document...</div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function viewStudentDocument(studentId, docType) {
            const modalEl = document.getElementById('documentViewerModal');
            const body = document.getElementById('documentViewerBody');
            const title = document.getElementById('documentViewerTitle');
            const dlBtn = document.getElementById('docDownloadBtn');
            body.innerHTML = '<div class="text-center py-5 text-muted small">Loading document...</div>';
            dlBtn.style.display = 'none';
            title.textContent = 'Document';
            
            fetch('get_student_document.php?student_id=' + encodeURIComponent(studentId) + '&type=' + encodeURIComponent(docType))
                .then(r => r.json())
                .then(data => {
                    if (!data.success) { 
                        body.innerHTML = '<div class="alert alert-danger m-3">' + (data.message || 'Error loading document') + '</div>'; 
                        return; 
                    }
                    
                    title.textContent = data.documentName || data.filename || 'Document';
                    if (data.downloadUrl) { 
                        dlBtn.href = data.downloadUrl; 
                        dlBtn.style.display = 'inline-block'; 
                    }
                    
                    let filePath = data.filePath || data.file_path || '';
                    
                    // Fix path for module location: if path starts with assets/, prepend ../../
                    if (filePath.startsWith('assets/') || filePath.startsWith('modules/')) {
                        filePath = '../../' + filePath;
                    }
                    
                    const ext = filePath.split('.').pop().toLowerCase();
                    
                    // Debug logging
                    console.log('Document API response:', data);
                    console.log('Using filePath:', filePath);
                    
                    if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext)) {
                        body.innerHTML = `
                            <div class="doc-viewer-wrapper">
                                <div class="doc-zoom-indicator" data-zoom-indicator>100%</div>
                                <div class="doc-controls">
                                    <button type="button" data-zoom-in title="Zoom In"><i class="bi bi-zoom-in"></i></button>
                                    <button type="button" data-zoom-out title="Zoom Out"><i class="bi bi-zoom-out"></i></button>
                                    <button type="button" data-reset title="Reset"><i class="bi bi-arrow-counterclockwise"></i></button>
                                </div>
                                <div class="doc-viewer-stage" data-stage>
                                    <img src="${filePath}" alt="Document" draggable="false" loading="lazy" 
                                         onerror="this.parentElement.parentElement.innerHTML='<div class=\\'alert alert-danger m-3\\'>Image failed to load.<br>Path: <code>${filePath}</code><br>Original: <code>${data.debug_original_path || ''}</code></div>'">
                                </div>
                                <div class="doc-hint">Scroll to zoom • Drag to pan</div>
                            </div>
                        `;
                        setTimeout(() => initImageViewer(body.querySelector('.doc-viewer-wrapper')), 100);
                    } else if (ext === 'pdf') {
                        body.innerHTML = `<iframe src="${filePath}" style="width:100%;height:70vh;border:none;"></iframe>`;
                    } else {
                        body.innerHTML = `<div class="alert alert-info m-3">Cannot preview this file type. <a href="${data.downloadUrl || filePath}" class="alert-link">Download instead</a></div>`;
                    }
                })
                .catch(err => {
                    console.error(err);
                    body.innerHTML = '<div class="alert alert-danger m-3">Network error loading document.</div>';
                });
                
            new bootstrap.Modal(modalEl).show();
        }

        function initImageViewer(wrapper){
            if(!wrapper) return;
            const stage = wrapper.querySelector('[data-stage]');
            const img = stage.querySelector('img');
            const zoomIndicator = wrapper.querySelector('[data-zoom-indicator]');
            const controls = wrapper.querySelector('.doc-controls');
            let scale = 1;
            let minScale = 0.2;
            let maxScale = 8;
            let originX = 0; // translation
            let originY = 0;
            let isDragging = false;
            let startX=0, startY=0;
            let lastX=0, lastY=0;
            let pinchStartDist = 0;
            let pinchStartScale = 1;

            function applyTransform(){
                img.style.transform = `translate(${originX}px, ${originY}px) scale(${scale})`;
                if(zoomIndicator) zoomIndicator.textContent = Math.round(scale*100)+ '%';
            }
            function clamp(val,min,max){ return Math.min(Math.max(val,min),max); }
            function zoom(delta, centerX, centerY){
                const prevScale = scale;
                scale = clamp(scale * (delta>0?1.1:0.9), minScale, maxScale);
                // Adjust translation so zoom centers on pointer (if coordinates provided)
                if(centerX!=null && centerY!=null){
                    const rect = img.getBoundingClientRect();
                    const offsetX = centerX - (rect.left + rect.width/2);
                    const offsetY = centerY - (rect.top + rect.height/2);
                    originX -= offsetX * (scale/prevScale -1);
                    originY -= offsetY * (scale/prevScale -1);
                }
                applyTransform();
            }
            // Wheel zoom
            wrapper.addEventListener('wheel', e=>{
                e.preventDefault();
                zoom(e.deltaY, e.clientX, e.clientY);
            }, { passive:false });
            // Drag
            function startDrag(e){
                isDragging = true;
                startX = (e.touches? e.touches[0].clientX : e.clientX);
                startY = (e.touches? e.touches[0].clientY : e.clientY);
                lastX = originX; lastY = originY;
            }
            function moveDrag(e){
                if(!isDragging) return;
                const x = (e.touches? e.touches[0].clientX : e.clientX);
                const y = (e.touches? e.touches[0].clientY : e.clientY);
                originX = lastX + (x - startX);
                originY = lastY + (y - startY);
                applyTransform();
                e.preventDefault();
            }
            function endDrag(){ isDragging=false; }
            img.addEventListener('mousedown', startDrag);
            window.addEventListener('mousemove', moveDrag);
            window.addEventListener('mouseup', endDrag);

            img.addEventListener('touchstart', e=>{
                if(e.touches.length===1){ startDrag(e); }
                else if(e.touches.length===2){ // pinch start
                    pinchStartDist = Math.hypot(
                        e.touches[0].clientX - e.touches[1].clientX,
                        e.touches[0].clientY - e.touches[1].clientY
                    );
                    pinchStartScale = scale;
                }
            }, { passive:false });
            img.addEventListener('touchmove', e=>{
                if(e.touches.length===1 && isDragging){ moveDrag(e); }
                else if(e.touches.length===2){
                    e.preventDefault();
                    const newDist = Math.hypot(
                        e.touches[0].clientX - e.touches[1].clientX,
                        e.touches[0].clientY - e.touches[1].clientY
                    );
                    const factor = newDist / pinchStartDist;
                    scale = clamp(pinchStartScale * factor, minScale, maxScale);
                    applyTransform();
                }
            }, { passive:false });
            window.addEventListener('touchend', e=>{ if(e.touches.length===0) endDrag(); }, { passive:true });

            // Buttons
            if(controls){
                controls.addEventListener('click', e=>{
                    const btn = e.target.closest('button');
                    if (!btn) return;
                    const action = btn.getAttribute('data-zoom-in') !== null ? 'zoom-in' : 
                                   btn.getAttribute('data-zoom-out') !== null ? 'zoom-out' :
                                   btn.getAttribute('data-reset') !== null ? 'reset' : null;
                    if(action==='zoom-in') zoom(-1); // negative delta => zoom in
                    else if(action==='zoom-out') zoom(1);
                    else if(action==='reset'){ scale=1; originX=originY=0; applyTransform(); }
                });
            }
            // Prevent background scroll while interacting
            ['wheel','touchmove'].forEach(ev=>{
                wrapper.addEventListener(ev, e=>{ e.preventDefault(); }, { passive:false });
            });
            applyTransform();
        }
    </script>

    <!-- Include Blacklist Modal -->
    <?php include __DIR__ . '/../../includes/admin/blacklist_modal.php'; ?>
    
    <script>
    async function loadValidationData(docType, studentId) {
        console.log('loadValidationData called:', docType, studentId);
        
        const modalBody = document.getElementById('validationModalBody');
        const modalTitle = document.getElementById('validationModalLabel');
        
        const docNames = {
            'id_picture': 'ID Picture',
            'eaf': 'Enrollment Assessment Form',
            'letter_to_mayor': 'Letter to Mayor',
            'certificate_of_indigency': 'Certificate of Indigency',
            'grades': 'Academic Grades'
        };
        modalTitle.innerHTML = `<i class="bi bi-clipboard-check me-2"></i>${docNames[docType] || docType} - Validation Results`;
        
        modalBody.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-info"></div><p class="mt-3">Loading...</p></div>';
        
        try {
            const response = await fetch('../student/get_validation_details.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({doc_type: docType, student_id: studentId})
            });
            
            console.log('Response status:', response.status);
            const responseText = await response.text();
            console.log('Raw response:', responseText);
            
            let data;
            try {
                data = JSON.parse(responseText);
                console.log('Parsed data:', data);
            } catch (parseError) {
                console.error('JSON parse error:', parseError);
                modalBody.innerHTML = `<div class="alert alert-danger">
                    <h6>Error parsing response</h6>
                    <pre style="max-height:200px;overflow:auto;">${responseText.substring(0, 500)}</pre>
                </div>`;
                return;
            }
            
            if (data.success) {
                const html = generateValidationHTML(data.validation, docType);
                console.log('Generated HTML length:', html.length);
                modalBody.innerHTML = html;
            } else {
                modalBody.innerHTML = `<div class="alert alert-warning">
                    <h6><i class="bi bi-exclamation-triangle me-2"></i>${data.message || 'No validation data available.'}</h6>
                    <small>Document Type: ${docType}, Student ID: ${studentId}</small>
                </div>`;
            }
        } catch (error) {
            console.error('Validation fetch error:', error);
            modalBody.innerHTML = `<div class="alert alert-danger">
                <h6><i class="bi bi-x-circle me-2"></i>Error loading validation data</h6>
                <p>${error.message}</p>
                <small>Document Type: ${docType}, Student ID: ${studentId}</small>
            </div>`;
        }
    }

    function generateValidationHTML(validation, docType) {
        console.log('=== generateValidationHTML DEBUG ===');
        console.log('docType:', docType);
        console.log('validation object:', validation);
        
        if (!validation || typeof validation !== 'object') {
            return `<div class="alert alert-warning p-4">
                <h6><i class="bi bi-exclamation-triangle me-2"></i>No Validation Data</h6>
                <p>Validation data is not available or malformed for this document.</p>
                <small>Document Type: ${docType}</small>
            </div>`;
        }
        
        let html = '';
        
        // === OCR CONFIDENCE BANNER ===
        if (validation.ocr_confidence !== undefined && validation.ocr_confidence !== null) {
            const conf = parseFloat(validation.ocr_confidence) || 0;
            const confColor = conf >= 80 ? 'success' : (conf >= 60 ? 'warning' : 'danger');
            html += `<div class="alert alert-${confColor} d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h5 class="mb-0"><i class="bi bi-robot me-2"></i>Overall OCR Confidence</h5>
                    <small class="text-muted">How well Tesseract extracted text from the image</small>
                </div>
                <h3 class="mb-0 fw-bold">${conf.toFixed(1)}%</h3>
            </div>`;
        }
        
        // === CHECK IF VERIFICATION DATA EXISTS ===
        const hasVerificationData = validation.identity_verification && 
                                    (parseFloat(validation.identity_verification.first_name_confidence || 0) > 0 ||
                                     parseFloat(validation.identity_verification.last_name_confidence || 0) > 0 ||
                                     parseFloat(validation.identity_verification.school_confidence || 0) > 0 ||
                                     parseInt(validation.identity_verification.passed_checks || 0) > 0);
        
        if (!hasVerificationData && parseFloat(validation.ocr_confidence || 0) > 0) {
            html += `<div class="alert alert-warning mb-4">
                <h6><i class="bi bi-exclamation-triangle me-2"></i>Verification Incomplete</h6>
                <p><strong>Text was successfully extracted (${parseFloat(validation.ocr_confidence || 0).toFixed(1)}% OCR confidence)</strong>, 
                but verification against student data has not been performed yet.</p>
                <p class="mb-0"><small>This usually happens when the .verify.json file is missing or corrupted.</small></p>
            </div>`;
            
            // Show extracted text if available
            if (validation.extracted_text) {
                html += `<div class="card mb-3">
                    <div class="card-header bg-secondary text-white">
                        <h6 class="mb-0"><i class="bi bi-file-text me-2"></i>Extracted Text (${parseFloat(validation.ocr_confidence || 0).toFixed(1)}% confidence)</h6>
                    </div>
                    <div class="card-body">
                        <pre style="max-height: 300px; overflow-y: auto; white-space: pre-wrap; font-size: 0.85rem;">${validation.extracted_text}</pre>
                    </div>
                </div>`;
            }
            
            return html;
        }
        
        // === DETAILED VERIFICATION CHECKLIST ===
        if (validation.identity_verification) {
            const idv = validation.identity_verification;
            const isIdOrEaf = (docType === 'id_picture' || docType === 'eaf');
            const isLetter = (docType === 'letter_to_mayor');
            const isCert = (docType === 'certificate_of_indigency');
            
            html += '<div class="card mb-4"><div class="card-header bg-primary text-white">';
            html += '<h5 class="mb-0"><i class="bi bi-clipboard-check me-2"></i>Verification Checklist</h5>';
            html += '</div><div class="card-body"><div class="verification-checklist">';
            
            // FIRST NAME
            const fnMatch = idv.first_name_match;
            const fnConf = parseFloat(idv.first_name_confidence || 0);
            const fnClass = fnMatch ? 'check-passed' : 'check-failed';
            const fnIcon = fnMatch ? 'check-circle-fill text-success' : 'x-circle-fill text-danger';
            html += `<div class="form-check ${fnClass} d-flex justify-content-between align-items-center">
                <div><i class="bi bi-${fnIcon} me-2" style="font-size:1.2rem;"></i>
                <span><strong>First Name</strong> ${fnMatch ? 'Match' : 'Not Found'}</span></div>
                <span class="badge ${fnMatch ? 'bg-success' : 'bg-danger'} confidence-score">${fnConf.toFixed(0)}%</span>
            </div>`;
            
            // MIDDLE NAME (ID/EAF only)
            if (isIdOrEaf) {
                const mnMatch = idv.middle_name_match;
                const mnConf = parseFloat(idv.middle_name_confidence || 0);
                const mnClass = mnMatch ? 'check-passed' : 'check-failed';
                const mnIcon = mnMatch ? 'check-circle-fill text-success' : 'x-circle-fill text-danger';
                html += `<div class="form-check ${mnClass} d-flex justify-content-between align-items-center">
                    <div><i class="bi bi-${mnIcon} me-2" style="font-size:1.2rem;"></i>
                    <span><strong>Middle Name</strong> ${mnMatch ? 'Match' : 'Not Found'}</span></div>
                    <span class="badge ${mnMatch ? 'bg-success' : 'bg-danger'} confidence-score">${mnConf.toFixed(0)}%</span>
                </div>`;
            }
            
            // LAST NAME
            const lnMatch = idv.last_name_match;
            const lnConf = parseFloat(idv.last_name_confidence || 0);
            const lnClass = lnMatch ? 'check-passed' : 'check-failed';
            const lnIcon = lnMatch ? 'check-circle-fill text-success' : 'x-circle-fill text-danger';
            html += `<div class="form-check ${lnClass} d-flex justify-content-between align-items-center">
                <div><i class="bi bi-${lnIcon} me-2" style="font-size:1.2rem;"></i>
                <span><strong>Last Name</strong> ${lnMatch ? 'Match' : 'Not Found'}</span></div>
                <span class="badge ${lnMatch ? 'bg-success' : 'bg-danger'} confidence-score">${lnConf.toFixed(0)}%</span>
            </div>`;
            
            // YEAR LEVEL or BARANGAY
            if (isIdOrEaf) {
                const ylMatch = idv.year_level_match;
                const ylClass = ylMatch ? 'check-passed' : 'check-failed';
                const ylIcon = ylMatch ? 'check-circle-fill text-success' : 'x-circle-fill text-danger';
                html += `<div class="form-check ${ylClass} d-flex justify-content-between align-items-center">
                    <div><i class="bi bi-${ylIcon} me-2" style="font-size:1.2rem;"></i>
                    <span><strong>Year Level</strong> ${ylMatch ? 'Match' : 'Not Found'}</span></div>
                    <span class="badge ${ylMatch ? 'bg-success' : 'bg-secondary'} confidence-score">${ylMatch ? '✓' : '✗'}</span>
                </div>`;
            } else if (isLetter || isCert) {
                const brgyMatch = idv.barangay_match;
                const brgyConf = parseFloat(idv.barangay_confidence || 0);
                const brgyClass = brgyMatch ? 'check-passed' : 'check-failed';
                const brgyIcon = brgyMatch ? 'check-circle-fill text-success' : 'x-circle-fill text-danger';
                html += `<div class="form-check ${brgyClass} d-flex justify-content-between align-items-center">
                    <div><i class="bi bi-${brgyIcon} me-2" style="font-size:1.2rem;"></i>
                    <span><strong>Barangay</strong> ${brgyMatch ? 'Match' : 'Not Found'}</span></div>
                    <span class="badge ${brgyMatch ? 'bg-success' : 'bg-danger'} confidence-score">${brgyConf.toFixed(0)}%</span>
                </div>`;
            }
            
            // UNIVERSITY/SCHOOL (ID/EAF only)
            if (isIdOrEaf) {
                const schoolMatch = idv.school_match || idv.university_match;
                const schoolConf = parseFloat(idv.school_confidence || idv.university_confidence || 0);
                const schoolClass = schoolMatch ? 'check-passed' : 'check-failed';
                const schoolIcon = schoolMatch ? 'check-circle-fill text-success' : 'x-circle-fill text-danger';
                html += `<div class="form-check ${schoolClass} d-flex justify-content-between align-items-center">
                    <div><i class="bi bi-${schoolIcon} me-2" style="font-size:1.2rem;"></i>
                    <span><strong>University/School</strong> ${schoolMatch ? 'Match' : 'Not Found'}</span></div>
                    <span class="badge ${schoolMatch ? 'bg-success' : 'bg-danger'} confidence-score">${schoolConf.toFixed(0)}%</span>
                </div>`;
            } else if (isLetter) {
                const officeMatch = idv.office_header_found;
                const officeConf = parseFloat(idv.office_header_confidence || 0);
                const officeClass = officeMatch ? 'check-passed' : 'check-warning';
                const officeIcon = officeMatch ? 'check-circle-fill text-success' : 'exclamation-circle-fill text-warning';
                html += `<div class="form-check ${officeClass} d-flex justify-content-between align-items-center">
                    <div><i class="bi bi-${officeIcon} me-2" style="font-size:1.2rem;"></i>
                    <span><strong>Mayor's Office Header</strong> ${officeMatch ? 'Found' : 'Not Found'}</span></div>
                    <span class="badge ${officeMatch ? 'bg-success' : 'bg-warning'} confidence-score">${officeConf.toFixed(0)}%</span>
                </div>`;
            } else if (isCert) {
                const certMatch = idv.certificate_title_found;
                const certConf = parseFloat(idv.certificate_title_confidence || 0);
                const certClass = certMatch ? 'check-passed' : 'check-warning';
                const certIcon = certMatch ? 'check-circle-fill text-success' : 'exclamation-circle-fill text-warning';
                html += `<div class="form-check ${certClass} d-flex justify-content-between align-items-center">
                    <div><i class="bi bi-${certIcon} me-2" style="font-size:1.2rem;"></i>
                    <span><strong>Certificate Title</strong> ${certMatch ? 'Found' : 'Not Found'}</span></div>
                    <span class="badge ${certMatch ? 'bg-success' : 'bg-warning'} confidence-score">${certConf.toFixed(0)}%</span>
                </div>`;
            }
            
            // OFFICIAL KEYWORDS (ID/EAF only)
            if (isIdOrEaf) {
                const kwMatch = idv.official_keywords;
                const kwConf = parseFloat(idv.keywords_confidence || 0);
                const kwClass = kwMatch ? 'check-passed' : 'check-failed';
                const kwIcon = kwMatch ? 'check-circle-fill text-success' : 'x-circle-fill text-danger';
                html += `<div class="form-check ${kwClass} d-flex justify-content-between align-items-center">
                    <div><i class="bi bi-${kwIcon} me-2" style="font-size:1.2rem;"></i>
                    <span><strong>Official Document Keywords</strong> ${kwMatch ? 'Found' : 'Not Found'}</span></div>
                    <span class="badge ${kwMatch ? 'bg-success' : 'bg-danger'} confidence-score">${kwConf.toFixed(0)}%</span>
                </div>`;
            }
            
            html += '</div></div></div>'; // Close checklist, card-body, card
            
            // === OVERALL SUMMARY ===
            const avgConf = parseFloat(idv.average_confidence || validation.ocr_confidence || 0);
            const passedChecks = idv.passed_checks || 0;
            const totalChecks = idv.total_checks || 6;
            const verificationScore = ((passedChecks / totalChecks) * 100);
            
            let statusMessage = '';
            let statusClass = '';
            let statusIcon = '';
            
            if (verificationScore >= 80) {
                statusMessage = 'Document validation successful';
                statusClass = 'alert-success';
                statusIcon = 'check-circle-fill';
            } else if (verificationScore >= 60) {
                statusMessage = 'Document validation passed with warnings';
                statusClass = 'alert-warning';
                statusIcon = 'exclamation-triangle-fill';
            } else {
                statusMessage = 'Document validation failed - manual review required';
                statusClass = 'alert-danger';
                statusIcon = 'x-circle-fill';
            }
            
            html += `<div class="card mb-4"><div class="card-header bg-light"><h6 class="mb-0"><i class="bi bi-bar-chart me-2"></i>Overall Analysis</h6></div><div class="card-body">`;
            html += `<div class="row g-3 mb-3">
                <div class="col-md-4">
                    <div class="text-center p-3 bg-light rounded">
                        <small class="text-muted d-block mb-1">Average Confidence</small>
                        <h4 class="mb-0 fw-bold text-primary">${avgConf.toFixed(1)}%</h4>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="text-center p-3 bg-light rounded">
                        <small class="text-muted d-block mb-1">Passed Checks</small>
                        <h4 class="mb-0 fw-bold text-success">${passedChecks}/${totalChecks}</h4>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="text-center p-3 bg-light rounded">
                        <small class="text-muted d-block mb-1">Verification Score</small>
                        <h4 class="mb-0 fw-bold ${verificationScore >= 80 ? 'text-success' : (verificationScore >= 60 ? 'text-warning' : 'text-danger')}">${verificationScore.toFixed(0)}%</h4>
                    </div>
                </div>
            </div>`;
            
            html += `<div class="alert ${statusClass} mb-0">
                <h6 class="mb-0"><i class="bi bi-${statusIcon} me-2"></i>${statusMessage}</h6>`;
            if (idv.recommendation) {
                html += `<small class="mt-2 d-block"><strong>Recommendation:</strong> ${idv.recommendation}</small>`;
            }
            html += `</div></div></div>`; // Close card-body, card
        }
        
        // === EXTRACTED GRADES (for grades document) ===
        if (docType === 'grades' && validation.extracted_grades) {
            html += '<div class="card mb-4"><div class="card-header bg-success text-white">';
            html += '<h6 class="mb-0"><i class="bi bi-list-check me-2"></i>Extracted Grades</h6>';
            html += '</div><div class="card-body p-0"><div class="table-responsive">';
            html += '<table class="table table-bordered table-hover mb-0"><thead class="table-light"><tr><th>Subject</th><th>Grade</th><th>Confidence</th><th>Status</th></tr></thead><tbody>';
            
            validation.extracted_grades.forEach(grade => {
                const conf = parseFloat(grade.extraction_confidence || 0);
                const confColor = conf >= 80 ? 'success' : (conf >= 60 ? 'warning' : 'danger');
                const statusIcon = grade.is_passing === 't' ? 'check-circle-fill' : 'x-circle-fill';
                const statusColor = grade.is_passing === 't' ? 'success' : 'danger';
                
                html += `<tr>
                    <td>${grade.subject_name || 'N/A'}</td>
                    <td><strong>${grade.grade_value || 'N/A'}</strong></td>
                    <td><span class="badge bg-${confColor}">${conf.toFixed(1)}%</span></td>
                    <td><i class="bi bi-${statusIcon} text-${statusColor}"></i> ${grade.is_passing === 't' ? 'Passing' : 'Failing'}</td>
                </tr>`;
            });
            
            html += '</tbody></table></div></div></div>';
            
            if (validation.validation_status) {
                const statusColors = {'passed': 'success', 'failed': 'danger', 'manual_review': 'warning', 'pending': 'info'};
                const statusColor = statusColors[validation.validation_status] || 'secondary';
                html += `<div class="alert alert-${statusColor}"><strong>Grade Validation Status:</strong> ${validation.validation_status.toUpperCase().replace('_', ' ')}</div>`;
            }
        }
        
        // === EXTRACTED TEXT ===
        if (validation.extracted_text) {
            html += '<div class="card"><div class="card-header bg-secondary text-white">';
            html += '<h6 class="mb-0"><i class="bi bi-file-text me-2"></i>Extracted Text (OCR)</h6>';
            html += '</div><div class="card-body">';
            const textPreview = validation.extracted_text.substring(0, 2000);
            const hasMore = validation.extracted_text.length > 2000;
            html += `<pre style="max-height:400px;overflow-y:auto;font-size:0.85em;white-space:pre-wrap;background:#f8f9fa;padding:15px;border-radius:4px;border:1px solid #dee2e6;">${textPreview}${hasMore ? '\n\n... (text truncated)' : ''}</pre>`;
            html += '</div></div>';
        }
        
        return html;
    }

    // Show validation modal
    function showValidationModal() {
        const validationModalEl = document.getElementById('validationModal');
        let validationModal = bootstrap.Modal.getInstance(validationModalEl);
        
        if (!validationModal) {
            validationModal = new bootstrap.Modal(validationModalEl, {
                backdrop: 'static',
                keyboard: true,
                focus: true
            });
        }
        
        // Create custom backdrop to dim the student info modal
        let backdrop = document.getElementById('validationModalBackdrop');
        if (!backdrop) {
            backdrop = document.createElement('div');
            backdrop.id = 'validationModalBackdrop';
            backdrop.className = 'validation-backdrop';
            document.body.appendChild(backdrop);
        }
        
        // Show backdrop
        backdrop.classList.add('show');
        
        // Show the validation modal (it will appear on top of student info modal)
        validationModal.show();
        
        // Hide backdrop when modal is closed
        validationModalEl.addEventListener('hidden.bs.modal', function() {
            backdrop.classList.remove('show');
        }, { once: true });
    }
    </script>

    <!-- Validation Modal -->
    <div class="modal fade" id="validationModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title" id="validationModalLabel">
                        <i class="bi bi-clipboard-check me-2"></i>Validation Results
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="validationModalBody">
                    <div class="text-center py-5">
                        <div class="spinner-border text-info" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-3 text-muted">Loading validation data...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-1"></i>Close
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Include Blacklist Modal -->
    <?php include __DIR__ . '/../../includes/admin/blacklist_modal.php'; ?>
    
    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer"></div>
    
    <?php if (isset($toast_message)): ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        showToast(<?php echo json_encode($toast_message); ?>);
    });
    
    function showToast(message, type = 'success') {
        const container = document.getElementById('toastContainer');
        
        const icons = {
            success: '\u2713',
            error: '\u2715',
            warning: '\u26a0',
            info: '\u2139'
        };
        
        const toast = document.createElement('div');
        toast.className = `toast-notification toast-${type}`;
        toast.innerHTML = `
            <div class="toast-icon">${icons[type] || icons.success}</div>
            <div class="toast-content">${message}</div>
            <button class="toast-close" onclick="closeToast(this)">&times;</button>
        `;
        
        container.appendChild(toast);
        
        // Auto-dismiss after 5 seconds
        setTimeout(() => {
            closeToast(toast.querySelector('.toast-close'));
        }, 5000);
    }
    
    function closeToast(button) {
        const toast = button.closest('.toast-notification');
        toast.classList.add('hiding');
        setTimeout(() => {
            toast.remove();
        }, 300);
    }
    </script>
    <?php endif; ?>
    
</body>
</html>

<?php pg_close($connection); ?>