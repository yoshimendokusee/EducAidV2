<?php
/**
 * CLI/cron entry: Send daily student notification digests.
 * 
 * Usage: php send_daily_student_digests.php
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/bootstrap_services.php';

$now = date('Y-m-d H:i:s');

// Get all students with daily digest enabled
$sql = "SELECT s.student_id, p.last_digest_at,
               p.email_announcement, p.email_document, p.email_schedule, p.email_warning,
               p.email_error, p.email_success, p.email_system, p.email_info
        FROM student_notification_preferences p
        JOIN students s ON s.student_id = p.student_id
        WHERE p.email_enabled = TRUE AND p.email_frequency = 'daily'";

$res = @pg_query($connection, $sql);
if (!$res) {
    fwrite(STDERR, "Failed to load daily digest preferences.\n");
    exit(1);
}

$svc = new StudentEmailNotificationService($connection);
$totalStudents = 0; $sentCount = 0;
while ($row = pg_fetch_assoc($res)) {
    $totalStudents++;
    $sid = $row['student_id'];
    $last = $row['last_digest_at'] ?? null;
    if (!$last) {
        // Initialize last_digest_at to now to avoid emailing historical backlog
        @pg_query_params($connection, "UPDATE student_notification_preferences SET last_digest_at = $1 WHERE student_id = $2", [$now, $sid]);
        continue;
    }

    // Build allowed types filter
    $allowed = [];
    foreach (['announcement','document','schedule','warning','error','success','system','info'] as $t) {
        if (($row['email_' . $t] ?? 't') === 't') $allowed[] = $t;
    }
    if (empty($allowed)) continue;

    $placeholders = []; $params = [$sid, $last]; $idx = 3;
    foreach ($allowed as $t) { $placeholders[] = '$' . $idx; $params[] = $t; $idx++; }

    $q = "SELECT title, message, type, action_url, created_at
          FROM student_notifications
          WHERE student_id = $1
            AND created_at > $2
            AND type = ANY(ARRAY[" . implode(',', $placeholders) . "]) 
          ORDER BY created_at ASC
          LIMIT 100"; // reasonable cap

    $nres = @pg_query_params($connection, $q, $params);
    if (!$nres) continue;
    $items = [];
    while ($n = pg_fetch_assoc($nres)) { $items[] = $n; }
    if (empty($items)) {
        // Still advance last_digest_at to avoid re-scanning
        @pg_query_params($connection, "UPDATE student_notification_preferences SET last_digest_at = $1 WHERE student_id = $2", [$now, $sid]);
        continue;
    }

    $ok = $svc->sendDigestEmail($sid, $items);
    if ($ok) {
        $sentCount++;
        @pg_query_params($connection, "UPDATE student_notification_preferences SET last_digest_at = $1 WHERE student_id = $2", [$now, $sid]);
    }
}

echo "Processed students: $totalStudents, digests sent: $sentCount\n";
