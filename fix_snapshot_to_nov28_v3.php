<?php
// Fix distribution snapshot 10 to November 28 - only update dates (keep distribution_id same)
$url = getenv('DATABASE_URL') ?: getenv('DATABASE_PUBLIC_URL');
$p = parse_url($url);
$c = pg_connect("host={$p['host']} port={$p['port']} dbname=".ltrim($p['path'],'/')." user={$p['user']} password={$p['pass']}");

print "=== UPDATING SNAPSHOT 10 TO NOVEMBER 28 ===\n\n";

// Only update dates, keep distribution_id the same
$update = pg_query($c, "UPDATE distribution_snapshots SET 
    distribution_date = '2025-11-28',
    finalized_at = '2025-11-28 22:17:32'
WHERE snapshot_id = 10");

if ($update) {
    print "Updated: " . pg_affected_rows($update) . " row(s)\n";
} else {
    print "Error: " . pg_last_error($c) . "\n";
    exit(1);
}

// Verify
print "\n=== VERIFICATION ===\n";
$v = pg_query($c, "SELECT snapshot_id, distribution_id, distribution_date, finalized_at, archive_filename FROM distribution_snapshots WHERE snapshot_id = 10");
if ($v && $row = pg_fetch_assoc($v)) {
    print "Snapshot ID: {$row['snapshot_id']}\n";
    print "  Distribution ID: {$row['distribution_id']}\n";
    print "  Distribution Date: {$row['distribution_date']}\n";
    print "  Finalized At: {$row['finalized_at']}\n";
    print "  Archive Filename: {$row['archive_filename']}\n";
}

print "\nDone!\n";
