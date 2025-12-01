<?php
// Fix distribution snapshot 10 to November 28 - handle foreign key constraints
$url = getenv('DATABASE_URL') ?: getenv('DATABASE_PUBLIC_URL');
$p = parse_url($url);
$c = pg_connect("host={$p['host']} port={$p['port']} dbname=".ltrim($p['path'],'/')." user={$p['user']} password={$p['pass']}");

print "=== UPDATING SNAPSHOT 10 TO NOVEMBER 28 ===\n\n";

$oldDistId = 'GENERALTRIAS-DISTR-2025-11-30-221732';
$newDistId = 'GENERALTRIAS-DISTR-2025-11-28-221732';

// Step 1: Update the child table first (distribution_student_snapshot)
print "Step 1: Updating distribution_student_snapshot...\n";
$update1 = pg_query($c, "UPDATE distribution_student_snapshot SET distribution_id = '$newDistId' WHERE distribution_id = '$oldDistId'");
if ($update1) {
    print "  Updated: " . pg_affected_rows($update1) . " row(s) in distribution_student_snapshot\n";
} else {
    print "  Error: " . pg_last_error($c) . "\n";
    exit(1);
}

// Step 2: Now update the parent table (distribution_snapshots)
print "\nStep 2: Updating distribution_snapshots...\n";
$update2 = pg_query($c, "UPDATE distribution_snapshots SET 
    distribution_date = '2025-11-28',
    finalized_at = '2025-11-28 22:17:32',
    distribution_id = '$newDistId',
    archive_filename = 'GENERALTRIAS-DISTR-2025-11-28-221732.zip'
WHERE snapshot_id = 10");

if ($update2) {
    print "  Updated: " . pg_affected_rows($update2) . " row(s) in distribution_snapshots\n";
} else {
    print "  Error: " . pg_last_error($c) . "\n";
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

// Check child records
$childCount = pg_query($c, "SELECT COUNT(*) as cnt FROM distribution_student_snapshot WHERE distribution_id = '$newDistId'");
if ($childCount && $r = pg_fetch_assoc($childCount)) {
    print "\nDistribution student records with new ID: {$r['cnt']}\n";
}

print "\nDone!\n";
