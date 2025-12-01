<?php
/**
 * Check and fix students slot association
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

// Check signup_slots table
echo "=== SIGNUP SLOTS (Recent) ===\n";
$res = pg_query($connection, "SELECT slot_id, municipality_id, academic_year, semester, status, max_registrations, current_registrations, created_at FROM signup_slots WHERE municipality_id = 1 ORDER BY created_at DESC LIMIT 5");
if ($res) {
    while ($row = pg_fetch_assoc($res)) {
        echo "Slot ID: {$row['slot_id']}\n";
        echo "  Academic Year: {$row['academic_year']}, Semester: {$row['semester']}\n";
        echo "  Status: {$row['status']}\n";
        echo "  Registrations: {$row['current_registrations']} / {$row['max_registrations']}\n";
        echo "  Created: {$row['created_at']}\n\n";
    }
} else {
    echo "Error: " . pg_last_error($connection) . "\n";
}

// Find the current active slot
echo "=== CURRENT ACTIVE SLOT ===\n";
$activeRes = pg_query($connection, "SELECT slot_id, academic_year, semester, status, max_registrations, current_registrations FROM signup_slots WHERE municipality_id = 1 AND status = 'active' LIMIT 1");
$activeSlot = null;
if ($activeRes && pg_num_rows($activeRes) > 0) {
    $activeSlot = pg_fetch_assoc($activeRes);
    echo "Active Slot ID: {$activeSlot['slot_id']}\n";
    echo "  {$activeSlot['semester']} | {$activeSlot['academic_year']}\n";
    echo "  Status: {$activeSlot['status']}\n";
    echo "  Registrations: {$activeSlot['current_registrations']} / {$activeSlot['max_registrations']}\n\n";
} else {
    echo "No active slot found!\n\n";
}

// Check our registered students
echo "=== NEWLY REGISTERED STUDENTS (Sample) ===\n";
$studentsRes = pg_query($connection, "SELECT student_id, email, slot_id, status, application_date FROM students WHERE application_date >= '2025-11-23 02:59:00' AND application_date <= '2025-11-23 03:01:00' LIMIT 5");
if ($studentsRes) {
    while ($row = pg_fetch_assoc($studentsRes)) {
        echo "ID: {$row['student_id']}\n";
        echo "  Email: {$row['email']}\n";
        echo "  Slot ID: " . ($row['slot_id'] ?? 'NULL') . "\n";
        echo "  Status: {$row['status']}\n";
        echo "  Application Date: {$row['application_date']}\n\n";
    }
}

// Count students without slot
$countRes = pg_query($connection, "SELECT COUNT(*) as cnt FROM students WHERE application_date >= '2025-11-23 02:59:00' AND application_date <= '2025-11-23 03:01:00'");
$count = pg_fetch_result($countRes, 0, 'cnt');
echo "Total students from Nov 23 batch: $count\n\n";

// Ask to fix
if ($activeSlot) {
    echo "=== FIXING: Assigning students to active slot {$activeSlot['slot_id']} ===\n";
    
    $updateSql = "UPDATE students SET slot_id = $1 WHERE application_date >= '2025-11-23 02:59:00' AND application_date <= '2025-11-23 03:01:00'";
    $updateRes = pg_query_params($connection, $updateSql, [$activeSlot['slot_id']]);
    
    if ($updateRes) {
        $affected = pg_affected_rows($updateRes);
        echo "SUCCESS: Updated $affected students with slot_id = {$activeSlot['slot_id']}\n";
        
        // Update slot registration count
        $updateSlotSql = "UPDATE signup_slots SET current_registrations = current_registrations + $1 WHERE slot_id = $2";
        pg_query_params($connection, $updateSlotSql, [$affected, $activeSlot['slot_id']]);
        echo "Updated slot registration count.\n";
    } else {
        echo "ERROR: " . pg_last_error($connection) . "\n";
    }
} else {
    echo "Cannot fix - no active slot found.\n";
}

echo "\nDone!\n";
