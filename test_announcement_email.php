<?php
/**
 * Test Announcement Email Functionality
 * Run this to debug why emails aren't being sent
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/bootstrap_services.php';
require_once __DIR__ . '/src/Services/StudentEmailNotificationService.php';

echo "=== Testing Announcement Email Functionality ===\n\n";

// Test 1: Check if student notification preferences table exists and has data
echo "1. Checking student_notification_preferences table:\n";
$pref_check = pg_query($connection, "SELECT COUNT(*) as count FROM student_notification_preferences");
if ($pref_check) {
    $count = pg_fetch_assoc($pref_check)['count'];
    echo "   Found $count preference records\n";
    
    // Get a sample
    $sample = pg_query($connection, "SELECT * FROM student_notification_preferences LIMIT 1");
    if ($sample && pg_num_rows($sample) > 0) {
        echo "   Sample preference:\n";
        print_r(pg_fetch_assoc($sample));
    }
} else {
    echo "   ERROR: " . pg_last_error($connection) . "\n";
}
echo "\n";

// Test 2: Check students table
echo "2. Checking students with email:\n";
$students = pg_query($connection, "SELECT student_id, email, first_name, last_name FROM students LIMIT 3");
if ($students) {
    echo "   Sample students:\n";
    while ($s = pg_fetch_assoc($students)) {
        echo "   - ID: {$s['student_id']}, Email: {$s['email']}, Name: {$s['first_name']} {$s['last_name']}\n";
    }
} else {
    echo "   ERROR: " . pg_last_error($connection) . "\n";
}
echo "\n";

// Test 3: Run the same query from manage_announcements.php
echo "3. Testing announcement email query:\n";
$email_query = "SELECT s.student_id, s.email, s.first_name, s.last_name, 
                       COALESCE(snp.email_enabled, TRUE) as email_enabled,
                       COALESCE(snp.email_frequency, 'immediate') as email_frequency,
                       COALESCE(snp.type_announcement, TRUE) as type_announcement
                FROM students s
                LEFT JOIN student_notification_preferences snp ON s.student_id = snp.student_id
                WHERE COALESCE(snp.email_enabled, TRUE) = TRUE 
                  AND COALESCE(snp.type_announcement, TRUE) = TRUE
                  AND COALESCE(snp.email_frequency, 'immediate') = 'immediate'
                LIMIT 5";

$email_result = pg_query($connection, $email_query);
if ($email_result) {
    $count = pg_num_rows($email_result);
    echo "   Query returned $count students who should receive immediate emails\n";
    while ($student = pg_fetch_assoc($email_result)) {
        echo "   - Student: {$student['first_name']} {$student['last_name']} ({$student['email']})\n";
        echo "     Email Enabled: " . ($student['email_enabled'] ? 'YES' : 'NO') . "\n";
        echo "     Announcement Type: " . ($student['type_announcement'] ? 'YES' : 'NO') . "\n";
        echo "     Frequency: {$student['email_frequency']}\n";
    }
} else {
    echo "   ERROR: " . pg_last_error($connection) . "\n";
}
echo "\n";

// Test 4: Try to send a test email
echo "4. Testing email service:\n";
$emailService = new \App\Services\StudentEmailNotificationService();

// Get first student
$test_student = pg_query($connection, "SELECT student_id FROM students LIMIT 1");
if ($test_student && pg_num_rows($test_student) > 0) {
    $student_id = pg_fetch_assoc($test_student)['student_id'];
    echo "   Attempting to send test email to student ID: $student_id\n";
    
    $result = $emailService->sendImmediateEmail(
        $student_id,
        "Test Announcement",
        "This is a test announcement email to verify the system is working correctly.",
        'announcement',
        '../../website/announcements.php'
    );
    
    if ($result) {
        echo "   ✅ Email sent successfully!\n";
    } else {
        echo "   ❌ Email failed to send. Check error_log for details.\n";
    }
} else {
    echo "   No students found to test with\n";
}

echo "\n=== Test Complete ===\n";
