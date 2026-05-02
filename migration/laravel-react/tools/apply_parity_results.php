<?php
// Reads tools/parity_report.json and writes migrated_compat_paths.txt with only identical diffs
$report = __DIR__ . '/parity_report.json';
$out = __DIR__ . '/../migrated_compat_paths.txt';
if (!is_file($report)) {
    echo "Parity report not found: $report\n";
    exit(1);
}
$data = json_decode(file_get_contents($report), true);
if (empty($data['results'])) {
    echo "Parity report has no results\n";
    exit(1);
}
$kept = [];
foreach ($data['results'] as $r) {
    if (!empty($r['diff']) && $r['diff'] === 'identical') {
        $kept[] = $r['path'];
    }
}
$header = "# Auto-generated migrated compat paths (identical parity)\n";
file_put_contents($out, $header . implode("\n", $kept) . "\n");
echo "Wrote " . count($kept) . " migrated paths to $out\n";
