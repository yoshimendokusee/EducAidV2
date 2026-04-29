<?php
include __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../bootstrap_services.php';
require_once __DIR__ . '/../../src/Services/UnifiedFileService.php';
require_once __DIR__ . '/../../includes/student_notification_helper.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/../../phpmailer/vendor/autoload.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_username'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || $input['action'] !== 'auto_approve_high_confidence') {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$min_confidence = $input['min_confidence'] ?? 80;

try {
    // Begin transaction
    pg_query($connection, "BEGIN");
    
    // Initialize UnifiedFileService
    $fileService = new \App\Services\UnifiedFileService();
    
    // Get high confidence registrations
    $query = "SELECT s.student_id, s.first_name, s.last_name, s.extension_name, s.email,
                     s.school_student_id, s.university_id,
                     COALESCE(s.confidence_score, calculate_confidence_score(s.student_id)) as confidence_score
              FROM students s 
              WHERE s.status = 'under_registration' 
              AND COALESCE(s.confidence_score, calculate_confidence_score(s.student_id)) >= $1";
    
    $result = pg_query_params($connection, $query, [$min_confidence]);
    
    if (!$result) {
        throw new Exception("Database error: " . pg_last_error($connection));
    }
    
    $approved_count = 0;
    $students_to_approve = [];
    
    while ($row = pg_fetch_assoc($result)) {
        $students_to_approve[] = $row;
    }
    
    // Process each student
    foreach ($students_to_approve as $student) {
        // Get slot's academic year first
        $slotQuery = "SELECT ss.academic_year, ss.semester 
                     FROM students s 
                     LEFT JOIN signup_slots ss ON s.slot_id = ss.slot_id 
                     WHERE s.student_id = $1";
        $slotResult = pg_query_params($connection, $slotQuery, [$student['student_id']]);
        $slotData = pg_fetch_assoc($slotResult);
        
        // Update student status to applicant and mark as new registration (no upload needed)
        $updateQuery = "UPDATE students 
                       SET status = 'applicant', 
                           needs_document_upload = FALSE,
                           first_registered_academic_year = $2,
                           current_academic_year = $2
                       WHERE student_id = $1";
        $updateResult = pg_query_params($connection, $updateQuery, [
            $student['student_id'],
            $slotData['academic_year'] ?? null
        ]);
        
        if ($updateResult) {
            // Insert into school_student_ids table if applicable
            if (!empty($student['school_student_id']) && !empty($student['university_id'])) {
                // Get university name
                $uniQuery = "SELECT name FROM universities WHERE university_id = $1";
                $uniResult = pg_query_params($connection, $uniQuery, [$student['university_id']]);
                $uniData = pg_fetch_assoc($uniResult);
                
                if ($uniData) {
                    $schoolIdInsert = "INSERT INTO school_student_ids (
                        student_id, university_id, school_student_id, university_name,
                        first_name, last_name, registered_at, status, notes
                    ) VALUES ($1, $2, $3, $4, $5, $6, NOW(), 'active', 'Auto-approved by system')
                    ON CONFLICT (university_id, school_student_id) DO NOTHING";
                    
                    pg_query_params($connection, $schoolIdInsert, [
                        $student['student_id'],
                        $student['university_id'],
                        $student['school_student_id'],
                        $uniData['name'],
                        $student['first_name'],
                        $student['last_name']
                    ]);
                }
            }
            
            // Course mapping logic removed - no longer needed
            
            // Use UnifiedFileService to move files from temp to permanent storage
            $moveResult = $fileService->moveToPermStorage($student['student_id'], $_SESSION['admin_id'] ?? null);
            
            if ($moveResult['success']) {
                error_log("Auto-approve: Successfully moved " . $moveResult['moved_count'] . " documents for student " . $student['student_id']);
            } else {
                error_log("Auto-approve: Error moving documents for student " . $student['student_id'] . " - " . ($moveResult['errors'][0] ?? 'Unknown error'));
            }
            
            // Send approval email
            sendApprovalEmail($student['email'], $student['first_name'], $student['last_name'], $student['extension_name'], true, 'Auto-approved based on high confidence score (' . number_format($student['confidence_score'], 1) . '%)');
            
            // Add admin notification
            $student_name = trim($student['first_name'] . ' ' . $student['last_name'] . ' ' . $student['extension_name']);
            $notification_msg = "Auto-approved registration for: " . $student_name . " (ID: " . $student['student_id'] . ") - Confidence: " . number_format($student['confidence_score'], 1) . "%";
            pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
            
            // Add student notification
            createStudentNotification(
                $connection,
                $student['student_id'],
                'Registration Auto-Approved!',
                'Great news! Your registration has been automatically approved based on your submitted documents. You can now proceed as an applicant.',
                'success',
                'high',
                'student_dashboard.php'
            );
            
            $approved_count++;
        }
    }
    
    // Commit transaction
    pg_query($connection, "COMMIT");
    
    echo json_encode([
        'success' => true, 
        'count' => $approved_count,
        'message' => "Successfully auto-approved $approved_count high-confidence registrations"
    ]);
    
} catch (Exception $e) {
    // Rollback transaction
    pg_query($connection, "ROLLBACK");
    
    error_log("Auto-approval error: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Error during auto-approval process'
    ]);
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
        
        if ($approved) {
            $loginUrl = getUnifiedLoginUrl();
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
        }

        $mail->send();
    } catch (Exception $e) {
        error_log("Email could not be sent. Mailer Error: {$mail->ErrorInfo}");
    }
}

pg_close($connection);
?>