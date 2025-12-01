<?php
// Find distribution date tables
$url = getenv('DATABASE_URL') ?: getenv('DATABASE_PUBLIC_URL');
$p = parse_url($url);
$c = pg_connect("host={$p['host']} port={$p['port']} dbname=".ltrim($p['path'],'/')." user={$p['user']} password={$p['pass']}");

print "Looking for distribution date columns...\n\n";

$r = pg_query($c, "SELECT table_name, column_name FROM information_schema.columns WHERE column_name LIKE '%distribution%' OR (table_name LIKE '%distribution%' AND column_name LIKE '%date%') ORDER BY table_name, column_name");
while($row = pg_fetch_assoc($r)) {
    print $row['table_name'] . "." . $row['column_name'] . "\n";
}

print "\n=== Checking distribution_control table ===\n";
$r2 = pg_query($c, "SELECT * FROM information_schema.tables WHERE table_name = 'distribution_control'");
if (pg_num_rows($r2) > 0) {
    print "distribution_control exists!\n";
    $cols = pg_query($c, "SELECT column_name FROM information_schema.columns WHERE table_name = 'distribution_control'");
    while ($col = pg_fetch_assoc($cols)) {
        print "  - " . $col['column_name'] . "\n";
    }
    $data = pg_query($c, "SELECT * FROM distribution_control WHERE municipality_id = 1");
    print "\nData:\n";
    while ($row = pg_fetch_assoc($data)) {
        foreach ($row as $k => $v) {
            print "  $k: $v\n";
        }
    }
} else {
    print "distribution_control does not exist\n";
}
