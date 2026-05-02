<?php
$mapPath = __DIR__ . '/parity_map.json';
$outPath = __DIR__ . '/../migrated_compat_paths.txt';

$map = json_decode(file_get_contents($mapPath), true);
$paths = $map['paths'] ?? [];

file_put_contents(
    $outPath,
    "# Restored migrated compat paths from parity_map.json\n" . implode("\n", $paths) . "\n"
);

echo "Wrote " . count($paths) . " paths to " . $outPath . PHP_EOL;
