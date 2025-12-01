<?php
/**
 * Update newly registered students to active status
 * Makes all applicants from Nov 23 2025 3AM batch verified and active
 */

// Use Railway env vars
$RAILWAY_DATABASE_URL = getenv('DATABASE_URL') ?: getenv('DATABASE_PUBLIC_URL') ?: '';

if (empty($RAILWAY_DATABASE_URL)) {
    if (file_exists(__DIR__ . '/config/database.php')) {
        require_once __DIR__ . '/config/database.php';
    } else {
        echo "ERROR: No database connection\n";
        exit(1);
    }
} else {
    $parts = parse_url($RAILWAY_DATABASE_URL);
    $connString = sprintf(
        'host=%s port=%s dbname=%s user=%s password=%s connect_timeout=30',
        $parts['host'] ?? 'localhost',
        $parts['port'] ?? 5432,
        ltrim($parts['path'] ?? '/railway', '/'),
        $parts['user'] ?? 'postgres',
        $parts['pass'] ?? ''
    );
    $connection = @pg_connect($connString);
}

if (!$connection) {
    echo "ERROR: Database connection failed\n";
    exit(1);
}

echo "Connected to database.\n";

// Update all students registered on Nov 23 2025 at 3AM to active status with documents verified
$sql = "UPDATE students 
SET status = 'active', 
    documents_submitted = true, 
    documents_validated = true,
    documents_submission_date = '2025-11-23 03:00:00',
    needs_document_upload = false
WHERE application_date >= '2025-11-23 02:59:00' 
AND application_date <= '2025-11-23 03:01:00'
AND status = 'applicant'";

$result = pg_query($connection, $sql);

if ($result) {
    $count = pg_affected_rows($result);
    echo "SUCCESS: Updated $count students to 'active' status with documents verified.\n";
} else {
    echo "ERROR: " . pg_last_error($connection) . "\n";
}

// List the updated students
$listSql = "SELECT student_id, email, first_name, last_name, status 
FROM students 
WHERE application_date >= '2025-11-23 02:59:00' 
AND application_date <= '2025-11-23 03:01:00'
ORDER BY student_id";

$listResult = pg_query($connection, $listSql);
if ($listResult) {
    echo "\nUpdated students:\n";
    while ($row = pg_fetch_assoc($listResult)) {
        echo "  [{$row['status']}] {$row['student_id']} - {$row['email']} ({$row['first_name']} {$row['last_name']})\n";
    }
}

echo "\nDone!\n";
