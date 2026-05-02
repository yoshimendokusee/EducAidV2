<?php
// Generates a staged routes file with migrated compat routes removed.
// Usage: php tools/generate_staged_routes.php

$routesPath = __DIR__ . '/../laravel/routes/web.php';
$migratedPath = __DIR__ . '/../migrated_compat_paths.txt';
$outPath = __DIR__ . '/../laravel/routes/web.staged.php';

if (!is_file($routesPath)) { echo "routes file not found: $routesPath\n"; exit(1); }
if (!is_file($migratedPath)) { echo "migrated list not found: $migratedPath\n"; exit(1); }

$routes = file($routesPath, FILE_IGNORE_NEW_LINES);
$migrated = file($migratedPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
// Normalize migrated entries (strip comments and whitespace)
$clean = [];
foreach ($migrated as $line) {
    $ln = trim($line);
    if ($ln === '' || str_starts_with($ln, '#')) continue;
    $clean[] = str_replace('\\', '/', ltrim($ln, '/'));
}

$out = [];
foreach ($routes as $line) {
    $keep = true;
    foreach ($clean as $mp) {
        // If the route line references the exact migrated path, skip it.
        if (strpos($line, "'/" . $mp . "'") !== false || strpos($line, '"/' . $mp . '"') !== false) {
            $keep = false;
            break;
        }
        // Also handle double-quoted patterns or without leading slash
        if (strpos($line, "'/modules/" . $mp) !== false) {
            $keep = false; break;
        }
    }
    if ($keep) $out[] = $line;
}

file_put_contents($outPath, implode("\n", $out) . "\n");
echo "Wrote staged routes to: $outPath\n";
