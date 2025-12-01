<?php
// Direct distribution date fix
$url = getenv('DATABASE_URL') ?: getenv('DATABASE_PUBLIC_URL');
$p = parse_url($url);
$c = pg_connect("host={$p['host']} port={$p['port']} dbname=".ltrim($p['path'],'/')." user={$p['user']} password={$p['pass']}");

print "Connected: " . ($c ? "YES" : "NO") . "\n";

// Show snapshots
$r = pg_query($c, "SELECT snapshot_id, distribution_date FROM distribution_snapshots WHERE municipality_id = 1");
print "Current snapshots:\n";
while ($row = pg_fetch_assoc($r)) {
    print "  ID {$row['snapshot_id']}: {$row['distribution_date']}\n";
}

// Update to Nov 28
$u = pg_query($c, "UPDATE distribution_snapshots SET distribution_date = '2025-11-28' WHERE municipality_id = 1");
print "Updated: " . pg_affected_rows($u) . " rows\n";

// Verify
$v = pg_query($c, "SELECT snapshot_id, distribution_date FROM distribution_snapshots WHERE municipality_id = 1");
print "After update:\n";
while ($row = pg_fetch_assoc($v)) {
    print "  ID {$row['snapshot_id']}: {$row['distribution_date']}\n";
}
