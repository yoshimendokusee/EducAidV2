<?php
// Add a compat path to migrated_compat_paths.txt
// Usage: php tools/mark_migrated.php modules/admin/manage_applicants.php

if ($argc < 2) {
    echo "Usage: php tools/mark_migrated.php <relative_php_path>\n";
    exit(1);
}

$path = trim($argv[1]);
$base = __DIR__ . '/../migrated_compat_paths.txt';
$lines = [];
if (is_file($base)) $lines = file($base, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

if (in_array($path, $lines)) {
    echo "Already marked: $path\n";
    exit(0);
}

$lines[] = $path;
file_put_contents($base, implode("\n", $lines) . "\n");
echo "Marked migrated: $path\n";
