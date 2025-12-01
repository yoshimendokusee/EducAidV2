<?php
// Fix distribution snapshot created date to November 28
$url = getenv('DATABASE_URL') ?: getenv('DATABASE_PUBLIC_URL');
$p = parse_url($url);
$c = pg_connect("host={$p['host']} port={$p['port']} dbname=".ltrim($p['path'],'/')." user={$p['user']} password={$p['pass']}");

print "=== DISTRIBUTION SNAPSHOTS ===\n";

// Show current snapshots
$data = pg_query($c, "SELECT snapshot_id, distribution_id, created_at, academic_year, semester FROM distribution_snapshots ORDER BY snapshot_id DESC LIMIT 5");
print "Current snapshots:\n";
while ($row = pg_fetch_assoc($data)) {
    print "  ID {$row['snapshot_id']}: {$row['distribution_id']} - Created: {$row['created_at']} ({$row['academic_year']} {$row['semester']})\n";
}

// Update created_at to Nov 28
print "\n=== UPDATING CREATED DATE TO NOV 28, 2025 ===\n";
$u = pg_query($c, "UPDATE distribution_snapshots SET created_at = '2025-11-28 00:00:00' WHERE distribution_id LIKE '%2025-11-30%'");
print "Updated by distribution_id: " . pg_affected_rows($u) . " rows\n";

// If no rows affected, try the most recent one
if (pg_affected_rows($u) == 0) {
    print "Trying most recent active snapshot...\n";
    $u2 = pg_query($c, "UPDATE distribution_snapshots SET created_at = '2025-11-28 00:00:00' WHERE snapshot_id = (SELECT MAX(snapshot_id) FROM distribution_snapshots)");
    print "Updated latest: " . pg_affected_rows($u2) . " rows\n";
}

// Verify
print "\nAfter update:\n";
$v = pg_query($c, "SELECT snapshot_id, distribution_id, created_at, academic_year, semester FROM distribution_snapshots ORDER BY snapshot_id DESC LIMIT 5");
while ($row = pg_fetch_assoc($v)) {
    print "  ID {$row['snapshot_id']}: {$row['distribution_id']} - Created: {$row['created_at']} ({$row['academic_year']} {$row['semester']})\n";
}

print "\nDone!\n";
