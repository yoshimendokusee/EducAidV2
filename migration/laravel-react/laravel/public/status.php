<?php
echo "<!DOCTYPE html><html><head><title>EducAid Test</title></head><body>";
echo "<h1>EducAid Laravel 11 Setup Status</h1>";
echo "<p><strong>PHP Version:</strong> " . phpversion() . "</p>";
echo "<p><strong>Current Directory:</strong> " . __DIR__ . "</p>";

$vendorPath = __DIR__ . '/../vendor/autoload.php';
echo "<p><strong>Vendor autoload.php:</strong> ";
if (file_exists($vendorPath)) {
    echo "✓ EXISTS (size: " . filesize($vendorPath) . " bytes)";
} else {
    echo "✗ MISSING - Composer install incomplete";
    echo "<br/><details><summary>Files in vendor/</summary>";
    $vendor_dir = __DIR__ . '/../vendor';
    if (is_dir($vendor_dir)) {
        $items = scandir($vendor_dir);
        echo "<pre>";
        foreach ($items as $item) {
            if ($item !== '.' && $item !== '..') {
                echo $item . "\n";
            }
        }
        echo "</pre>";
    }
    echo "</details>";
}
echo "</p>";

echo "<p><strong>Composer.lock:</strong> ";
$lockFile = __DIR__ . '/../composer.lock';
if (file_exists($lockFile)) {
    echo "✓ EXISTS";
    $lock = json_decode(file_get_contents($lockFile), true);
    echo " (" . count($lock['packages'] ?? []) . " packages locked)";
} else {
    echo "✗ MISSING";
}
echo "</p>";

echo "<hr />";
echo "<p><strong>Diagnostics:</strong></p>";
echo "<ul>";
echo "<li>Laravel app is located at: " . dirname(__DIR__) . "</li>";
echo "<li>Public directory (document root): " . __DIR__ . "</li>";
echo "<li>Next step: Wait for <code>composer install</code> to complete, then reload this page</li>";
echo "</ul>";

echo "</body></html>";
?>
