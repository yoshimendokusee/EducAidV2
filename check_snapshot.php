<?php
// Check distribution_snapshots structure and fix date
$url = getenv('DATABASE_URL') ?: getenv('DATABASE_PUBLIC_URL');
$p = parse_url($url);
$c = pg_connect("host={$p['host']} port={$p['port']} dbname=".ltrim($p['path'],'/')." user={$p['user']} password={$p['pass']}");

print "=== DISTRIBUTION_SNAPSHOTS COLUMNS ===\n";
$cols = pg_query($c, "SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'distribution_snapshots' ORDER BY ordinal_position");
while ($col = pg_fetch_assoc($cols)) {
    print "  - {$col['column_name']} ({$col['data_type']})\n";
}

print "\n=== CURRENT DATA ===\n";
$data = pg_query($c, "SELECT * FROM distribution_snapshots ORDER BY snapshot_id DESC LIMIT 3");
while ($row = pg_fetch_assoc($data)) {
    print "Snapshot:\n";
    foreach ($row as $k => $v) {
        if ($v !== null && $v !== '') {
            print "  $k: $v\n";
        }
    }
    print "\n";
}
