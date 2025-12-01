<?php
// Fix schedules distribution date
$url = getenv('DATABASE_URL') ?: getenv('DATABASE_PUBLIC_URL');
$p = parse_url($url);
$c = pg_connect("host={$p['host']} port={$p['port']} dbname=".ltrim($p['path'],'/')." user={$p['user']} password={$p['pass']}");

print "=== SCHEDULES TABLE ===\n";

print "\nCurrent data:\n";
$data = pg_query($c, "SELECT * FROM schedules ORDER BY schedule_id DESC LIMIT 5");
while ($row = pg_fetch_assoc($data)) {
    foreach ($row as $k => $v) {
        if ($v !== null) print "  $k: $v\n";
    }
    print "\n";
}

// Update to Nov 28
print "=== UPDATING DISTRIBUTION DATE TO NOV 28, 2025 ===\n";
$u = pg_query($c, "UPDATE schedules SET distribution_date = '2025-11-28' WHERE distribution_date = '2025-12-04'");
print "Updated: " . pg_affected_rows($u) . " rows\n";

// Verify
print "\nAfter update:\n";
$v = pg_query($c, "SELECT schedule_id, distribution_date, location FROM schedules ORDER BY schedule_id DESC LIMIT 5");
while ($row = pg_fetch_assoc($v)) {
    print "  Schedule {$row['schedule_id']}: {$row['distribution_date']} at {$row['location']}\n";
}

print "\nDone!\n";
