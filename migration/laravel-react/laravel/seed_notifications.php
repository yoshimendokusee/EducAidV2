<?php
/**
 * Seed notification data for test students
 * Adds realistic notifications to demonstrate StudentNotifications page
 */

$envPath = __DIR__ . '/.env';
$lines = file($envPath);
foreach ($lines as $line) {
    if (strpos($line, '=') && strpos(trim($line), '#') !== 0) {
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

$conn_str = "host={$_ENV['DB_HOST']} port={$_ENV['DB_PORT']} dbname={$_ENV['DB_DATABASE']} user={$_ENV['DB_USERNAME']} password={$_ENV['DB_PASSWORD']}";
$connection = pg_connect($conn_str);

if (!$connection) {
    echo "❌ Failed to connect to database\n";
    exit(1);
}

echo "🌱 Seeding notification data...\n\n";

// Get first test applicant
$result = pg_query($connection, "SELECT student_id FROM students WHERE status = 'applicant' LIMIT 1");
$studentRow = pg_fetch_assoc($result);
$studentId = $studentRow['student_id'] ?? null;

if (!$studentId) {
    echo "❌ No test applicants found. Run seed_test_data.php first.\n";
    pg_close($connection);
    exit(1);
}

echo "Using student: $studentId\n";

// Clear existing notifications for this student
pg_query_params($connection, "DELETE FROM student_notifications WHERE student_id = $1", [$studentId]);

// Insert sample notifications
$notifications = [
    [
        'type' => 'system',
        'title' => 'Application Received',
        'message' => 'Your application has been received and is under review.',
        'read' => false,
    ],
    [
        'type' => 'document',
        'title' => 'Documents Uploaded Successfully',
        'message' => 'All your required documents have been uploaded successfully.',
        'read' => false,
    ],
    [
        'type' => 'announcement',
        'title' => 'Important Update on Assistance Distribution',
        'message' => 'We have updated our criteria for determining eligibility. Please review the requirements.',
        'read' => true,
    ],
    [
        'type' => 'system',
        'title' => 'Your Profile is Incomplete',
        'message' => 'Please complete your profile information to proceed with your application.',
        'read' => false,
    ],
    [
        'type' => 'approval',
        'title' => 'Application Status Changed',
        'message' => 'Your application has been moved to the next stage of review.',
        'read' => true,
    ],
];

$inserted = 0;
$now = date('Y-m-d H:i:s');

foreach ($notifications as $idx => $notif) {
    $createdAt = date('Y-m-d H:i:s', strtotime("-" . (count($notifications) - $idx - 1) . " days"));
    
    $result = pg_query_params($connection,
        "INSERT INTO student_notifications (student_id, type, title, message, is_read, created_at) 
         VALUES ($1, $2, $3, $4, $5::boolean, $6)",
        [
            $studentId,
            $notif['type'],
            $notif['title'],
            $notif['message'],
            $notif['read'] ? 'true' : 'false',
            $createdAt,
        ]
    );
    
    if ($result) {
        $inserted++;
        echo "  ✓ {$notif['title']}\n";
    } else {
        echo "  ❌ Failed to insert: {$notif['title']}\n";
    }
}

echo "\n✓ Inserted $inserted notifications\n";

// Verify
$result = pg_query_params($connection, "SELECT COUNT(*) as count FROM student_notifications WHERE student_id = $1", [$studentId]);
$row = pg_fetch_assoc($result);
echo "✓ Total notifications for student: " . $row['count'] . "\n";

// Get unread count
$result = pg_query_params($connection, "SELECT COUNT(*) as count FROM student_notifications WHERE student_id = $1 AND is_read = false", [$studentId]);
$row = pg_fetch_assoc($result);
echo "✓ Unread notifications: " . $row['count'] . "\n";

pg_close($connection);
echo "\n🎉 Notification data seeded successfully!\n";
