<?php
/**
 * Student Notification Helper Functions
 * 
 * Use these functions to easily create notifications for students throughout your application
 */

/**
 * Send notification to a single student
 * 
 * @param mixed $connection PostgreSQL connection
 * @param string $student_id Student ID
 * @param string $title Notification title
 * @param string $message Notification message
 * @param string $type Type: announcement|document|schedule|warning|error|success|system|info
 * @param string $priority Priority: low|medium|high
 * @param string|null $action_url Optional URL to navigate when clicked
 * @param bool $is_priority Set to TRUE to show as modal
 * @param string|null $expires_at Optional expiration timestamp
 * @return bool Success status
 */
function createStudentNotification($connection, $student_id, $title, $message, $type = 'info', $priority = 'low', $action_url = null, $is_priority = false, $expires_at = null) {
    if (!$connection) return false;

    // Insert and capture notification_id for potential email delivery
    $query = "INSERT INTO student_notifications 
              (student_id, title, message, type, priority, action_url, is_priority, expires_at)
              VALUES ($1, $2, $3, $4, $5, $6, $7, $8)
              RETURNING notification_id";

    $res = @pg_query_params($connection, $query, [
        $student_id,
        $title,
        $message,
        $type,
        $priority,
        $action_url,
        $is_priority ? 'true' : 'false',
        $expires_at
    ]);
    if ($res === false) return false;

    $row = pg_fetch_assoc($res);
    $notification_id = $row ? $row['notification_id'] : null;

    // Attempt email delivery based on preferences (email-only feature)
    if ($notification_id) {
        student_handle_email_delivery($connection, $student_id, $title, $message, $type, $action_url);
    }
    return true;
}

/**
 * Send notification to all students
 * 
 * @param mixed $connection PostgreSQL connection
 * @param string $title Notification title
 * @param string $message Notification message
 * @param string $type Type: announcement|document|schedule|warning|error|success|system|info
 * @param string $priority Priority: low|medium|high
 * @param string|null $action_url Optional URL to navigate when clicked
 * @param string|null $where_clause Optional WHERE clause to filter students (e.g., "WHERE status = 'active'")
 * @return bool Success status
 */
function createBulkStudentNotification($connection, $title, $message, $type = 'info', $priority = 'low', $action_url = null, $where_clause = '') {
    if (!$connection) return false;

    // Insert for selected students and return rows so we can attempt email delivery per preferences
    $query = "INSERT INTO student_notifications (student_id, title, message, type, priority, action_url)
              SELECT student_id, $1, $2, $3, $4, $5 FROM students " . $where_clause . "
              RETURNING notification_id, student_id";

    $result = @pg_query_params($connection, $query, [
        $title,
        $message,
        $type,
        $priority,
        $action_url
    ]);
    if ($result === false) return false;

    // Try email delivery for each row (best-effort; ignore failures per-row)
    while ($row = pg_fetch_assoc($result)) {
        $sid = $row['student_id'];
        student_handle_email_delivery($connection, $sid, $title, $message, $type, $action_url);
    }
    return true;
}

/**
 * Notify student about document status
 * 
 * @param mixed $connection PostgreSQL connection
 * @param string $student_id Student ID
 * @param string $document_type Type of document (e.g., "Certificate of Indigency")
 * @param string $status Status: approved|rejected|under_review
 * @param string|null $rejection_reason Reason for rejection (if rejected)
 * @return bool Success status
 */
function notifyStudentDocumentStatus($connection, $student_id, $document_type, $status, $rejection_reason = null) {
    switch ($status) {
        case 'approved':
            return createStudentNotification(
                $connection,
                $student_id,
                'Document Approved',
                "Your $document_type has been approved.",
                'success',
                'medium',
                'student_documents.php'
            );
            
        case 'rejected':
            $message = "Your $document_type was rejected.";
            if ($rejection_reason) {
                $message .= "\n\nReason: " . $rejection_reason . "\n\nPlease re-upload a corrected version.";
            }
            return createStudentNotification(
                $connection,
                $student_id,
                'Document Rejected',
                $message,
                'error',
                'high',
                // Direct students to login so they can access dashboard (env-aware URL built in email service)
                'unified_login.php',
                true // is_priority - shows as modal
            );
            
        case 'under_review':
            return createStudentNotification(
                $connection,
                $student_id,
                'Document Under Review',
                "Your $document_type is currently being reviewed. You will be notified once the review is complete.",
                'document',
                'low',
                'student_documents.php'
            );
            
        default:
            return false;
    }
}

/**
 * Notify student about application status
 * 
 * @param mixed $connection PostgreSQL connection
 * @param string $student_id Student ID
 * @param string $status Status: submitted|approved|rejected|pending
 * @param string|null $additional_info Additional information
 * @return bool Success status
 */
function notifyStudentApplicationStatus($connection, $student_id, $status, $additional_info = null) {
    switch ($status) {
        case 'submitted':
            return createStudentNotification(
                $connection,
                $student_id,
                'Application Submitted',
                'Your application has been successfully submitted and is awaiting review.',
                'info',
                'low'
            );
            
        case 'approved':
            $message = 'Congratulations! Your application has been approved.';
            if ($additional_info) $message .= ' ' . $additional_info;
            return createStudentNotification(
                $connection,
                $student_id,
                'Application Approved!',
                $message,
                'success',
                'high'
            );
            
        case 'rejected':
            $message = 'Your application has been rejected.';
            if ($additional_info) $message .= ' Reason: ' . $additional_info;
            return createStudentNotification(
                $connection,
                $student_id,
                'Application Status Update',
                $message,
                'error',
                'high'
            );
            
        case 'pending':
            return createStudentNotification(
                $connection,
                $student_id,
                'Application Under Review',
                'Your application is currently being reviewed by our team.',
                'info',
                'medium'
            );
            
        default:
            return false;
    }
}

/**
 * Send deadline reminder to students
 * 
 * @param mixed $connection PostgreSQL connection
 * @param string $deadline_date Deadline date (Y-m-d format)
 * @param string $what What is the deadline for
 * @param string|null $where_clause Optional WHERE clause to filter students
 * @return bool Success status
 */
function sendDeadlineReminder($connection, $deadline_date, $what, $where_clause = '') {
    if (!$connection) return false;
    
    $formatted_date = date('F j, Y', strtotime($deadline_date));
    $expires_at = $deadline_date . ' 23:59:59';
    
    $query = "INSERT INTO student_notifications (student_id, title, message, type, priority, expires_at)
              SELECT student_id, $1, $2, 'warning', 'medium', $3
              FROM students " . $where_clause;
    
    $result = @pg_query_params($connection, $query, [
        'Deadline Reminder',
        "Reminder: $what by $formatted_date. Please ensure you complete this before the deadline.",
        $expires_at
    ]);
    
    return $result !== false;
}

/**
 * Notify student about schedule update
 * 
 * @param mixed $connection PostgreSQL connection
 * @param string $student_id Student ID
 * @param string $schedule_details Schedule details
 * @param string $action_url URL to view schedule
 * @return bool Success status
 */
function notifyStudentSchedule($connection, $student_id, $schedule_details, $action_url = 'student_schedule.php') {
    return createStudentNotification(
        $connection,
        $student_id,
        'Schedule Update',
        $schedule_details,
        'schedule',
        'medium',
        $action_url
    );
}

/**
 * Send system announcement to all students
 * 
 * @param mixed $connection PostgreSQL connection
 * @param string $title Announcement title
 * @param string $message Announcement message
 * @param string|null $action_url Optional URL
 * @return bool Success status
 */
function sendSystemAnnouncement($connection, $title, $message, $action_url = null) {
    return createBulkStudentNotification(
        $connection,
        $title,
        $message,
        'announcement',
        'low',
        $action_url
    );
}

/**
 * Internal: Decide whether to send an email now based on student preferences.
 * 
 * CRITICAL TYPES (error, warning) are ALWAYS sent immediately to ensure students
 * don't miss document rejections or urgent issues.
 * 
 * NON-CRITICAL types respect the frequency preference (immediate or daily digest).
 */
function student_handle_email_delivery($connection, $student_id, $title, $message, $type, $action_url) {
    // Fetch or initialize preferences
    $pref = student_get_or_create_email_prefs($connection, $student_id);
    if (!$pref) return;

    // Email is always enabled in the new system, no need to check email_enabled
    // Type-specific preferences are ignored - all types are sent

    // Define critical notification types that MUST be sent immediately
    $critical_types = ['error', 'warning'];
    
    if (in_array(strtolower($type), $critical_types)) {
        // CRITICAL: Always send immediately regardless of preference
        require_once __DIR__ . '/../src/Services/StudentEmailNotificationService.php';
        $svc = new \App\Services\StudentEmailNotificationService();
        $svc->sendImmediateEmail($student_id, $title, $message, $type, $action_url);
    } else {
        // NON-CRITICAL: Respect frequency preference
        if (($pref['email_frequency'] ?? 'immediate') === 'immediate') {
            // Send immediately
            require_once __DIR__ . '/../src/Services/StudentEmailNotificationService.php';
            $svc = new \App\Services\StudentEmailNotificationService();
            $svc->sendImmediateEmail($student_id, $title, $message, $type, $action_url);
        }
        // If frequency is 'daily', notification will be queued for daily digest
        // (Daily digest implementation would go here if needed)
    }
}

/**
 * Get preferences or create with defaults.
 * @return array|null
 */
function student_get_or_create_email_prefs($connection, $student_id) {
    $res = @pg_query_params($connection, "SELECT * FROM student_notification_preferences WHERE student_id = $1", [$student_id]);
    if ($res && ($row = pg_fetch_assoc($res))) return $row;
    // Create defaults
    $ins = @pg_query_params($connection, "INSERT INTO student_notification_preferences (student_id) VALUES ($1) RETURNING *", [$student_id]);
    if ($ins && ($n = pg_fetch_assoc($ins))) return $n;
    return null;
}
