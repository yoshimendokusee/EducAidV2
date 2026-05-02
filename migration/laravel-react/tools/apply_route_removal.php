<?php
// Produce a unified diff patch that removes migrated compat route lines from routes/web.php
// Usage: php tools/apply_route_removal.php

$routesPath = __DIR__ . '/../laravel/routes/web.php';
$migratedPath = __DIR__ . '/../migrated_compat_paths.txt';
$outPatch = __DIR__ . '/../patches/remove_migrated_routes.patch';
$outPreview = __DIR__ . '/../laravel/routes/web.removed.php';

if (!is_file($routesPath)) { echo "routes file missing: $routesPath\n"; exit(1); }
if (!is_file($migratedPath)) { echo "migrated list missing: $migratedPath\n"; exit(1); }

$routes = file($routesPath, FILE_IGNORE_NEW_LINES);
$migrated = file($migratedPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$clean = [];
foreach ($migrated as $line) {
    $ln = trim($line);
    if ($ln === '' || str_starts_with($ln, '#')) continue;
    $clean[] = str_replace('\\', '/', ltrim($ln, '/'));
}

$filtered = [];
$removedLines = [];
foreach ($routes as $i => $line) {
    $keep = true;
    foreach ($clean as $mp) {
        if (strpos($line, "'/" . $mp . "'") !== false || strpos($line, '"/' . $mp . '"') !== false) {
            $keep = false; break;
        }
    }
    if ($keep) $filtered[] = $line;
    else $removedLines[] = [$i+1, $line];
}

// Write preview file
file_put_contents($outPreview, implode("\n", $filtered) . "\n");

// Build unified diff
$orig = $routes;
$mod = $filtered;
$fromFile = 'a/laravel/routes/web.php';
$toFile = 'b/laravel/routes/web.php';
$patchLines = [];
$patchLines[] = "*** Begin Patch\n";
$patchLines[] = "*** Update File: laravel/routes/web.php";
$patchLines[] = "@@";

// We'll create a naive diff: output the modified file entirely as replacement
$patchLines[] = "-" . implode("\n-", array_map(function($l){ return $l; }, $orig));
$patchLines[] = "+" . implode("\n+", array_map(function($l){ return $l; }, $mod));
$patchLines[] = "*** End Patch\n";

// Save patch
@mkdir(dirname($outPatch), 0755, true);
file_put_contents($outPatch, implode("\n", $patchLines));

echo "Preview written to: $outPreview\n";
echo "Patch written to: $outPatch\n";
echo "Removed " . count($removedLines) . " lines referencing migrated paths.\n";
