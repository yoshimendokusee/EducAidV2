<?php
include __DIR__ . '/../../config/database.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['admin_username']) || !isset($_GET['student_id'])) {
    header('HTTP/1.1 403 Forbidden');
    exit('Unauthorized access');
}

$student_id = trim($_GET['student_id']);

// Verify the student is blacklisted
$checkQuery = "SELECT s.student_id, s.first_name, s.last_name 
               FROM students s
               WHERE s.student_id = $1 AND s.status = 'blacklisted'";

$checkResult = pg_query_params($connection, $checkQuery, [$student_id]);

if (!$checkResult || pg_num_rows($checkResult) === 0) {
    header('HTTP/1.1 404 Not Found');
    exit('Student not found or not blacklisted');
}

$student = pg_fetch_assoc($checkResult);

// Check if ZIP file exists
$zipFile = __DIR__ . '/../../assets/uploads/blacklisted_students/' . $student_id . '.zip';

if (!file_exists($zipFile)) {
    header('HTTP/1.1 404 Not Found');
    exit('Archive file not found');
}

// Log the download action
require_once __DIR__ . '/../../bootstrap_services.php';
$auditLogger = new AuditLogger($connection);
$auditLogger->logEvent(
    'blacklist_archive_downloaded',
    'blacklist_management',
    "Downloaded blacklist archive for {$student['first_name']} {$student['last_name']}",
    [
        'user_id' => $_SESSION['admin_id'],
        'user_type' => 'admin',
        'student_id' => $student_id,
        'student_name' => $student['first_name'] . ' ' . $student['last_name'],
        'file_size' => filesize($zipFile)
    ]
);

// Set headers for download
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $student_id . '_blacklisted_archive.zip"');
header('Content-Length: ' . filesize($zipFile));
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: public');

// Clear output buffer
if (ob_get_level()) {
    ob_end_clean();
}

// Stream the file
readfile($zipFile);

pg_close($connection);
exit;
?>
