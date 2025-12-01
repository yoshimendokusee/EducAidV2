<?php
/**
 * Fix signup slot - Change Nov 29 to Nov 23 and assign students
 */

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

echo "Connected to database.\n\n";

// Step 1: Update the current active slot (ID: 3) created_at from Nov 29 to Nov 23 at 3:00 AM
echo "=== STEP 1: Update slot created_at date ===\n";
$updateSlotSql = "UPDATE signup_slots 
SET created_at = '2025-11-23 03:00:00' 
WHERE slot_id = 3";

$result = pg_query($connection, $updateSlotSql);
if ($result && pg_affected_rows($result) > 0) {
    echo "SUCCESS: Updated slot ID 3 created_at to November 23, 2025 at 3:00 AM\n";
} else {
    echo "Note: Slot date update - " . (pg_affected_rows($result) == 0 ? "no rows affected" : pg_last_error($connection)) . "\n";
}

// Step 2: Assign all Nov 23 students to slot_id 3
echo "\n=== STEP 2: Assign students to slot ID 3 ===\n";
$updateStudentsSql = "UPDATE students 
SET slot_id = 3 
WHERE application_date >= '2025-11-23 02:59:00' 
AND application_date <= '2025-11-23 03:01:00'";

$result2 = pg_query($connection, $updateStudentsSql);
if ($result2) {
    $count = pg_affected_rows($result2);
    echo "SUCCESS: Assigned $count students to slot ID 3\n";
} else {
    echo "ERROR: " . pg_last_error($connection) . "\n";
}

// Step 3: Verify the changes
echo "\n=== VERIFICATION ===\n";

// Check slot
$slotCheck = pg_query($connection, "SELECT slot_id, created_at, academic_year, semester, is_active FROM signup_slots WHERE slot_id = 3");
if ($slotCheck && $row = pg_fetch_assoc($slotCheck)) {
    echo "Slot ID 3:\n";
    echo "  Created At: {$row['created_at']}\n";
    echo "  Academic Year: {$row['academic_year']}\n";
    echo "  Semester: {$row['semester']}\n";
    echo "  Is Active: " . ($row['is_active'] == 't' ? 'Yes' : 'No') . "\n";
}

// Count students in slot
$studentCount = pg_query($connection, "SELECT COUNT(*) as cnt FROM students WHERE slot_id = 3");
if ($studentCount && $row = pg_fetch_assoc($studentCount)) {
    echo "\nTotal students in slot 3: {$row['cnt']}\n";
}

// Sample students
echo "\nSample students with slot_id = 3:\n";
$sampleRes = pg_query($connection, "SELECT student_id, email, slot_id, application_date FROM students WHERE slot_id = 3 LIMIT 5");
if ($sampleRes) {
    while ($row = pg_fetch_assoc($sampleRes)) {
        echo "  {$row['student_id']} - {$row['email']} (Slot: {$row['slot_id']}, Date: {$row['application_date']})\n";
    }
}

echo "\nDone!\n";
