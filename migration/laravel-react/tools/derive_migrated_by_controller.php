<?php
// Derive migrated compat paths by checking if mapped controller methods exist.
// Usage: php tools/derive_migrated_by_controller.php
$routes = __DIR__ . '/../laravel/routes/web.php';
$adminController = __DIR__ . '/../laravel/app/Http/Controllers/AdminModulesController.php';
$studentController = __DIR__ . '/../laravel/app/Http/Controllers/StudentModulesController.php';
$out = __DIR__ . '/../migrated_compat_paths.txt';

if (!is_file($routes)) { echo "routes/web.php not found\n"; exit(1); }
$web = file_get_contents($routes);
$lines = explode("\n", $web);
$kept = [];
foreach ($lines as $line) {
    $line = trim($line);
    if (strpos($line, "Route::match(['GET', 'POST'], '/modules/admin/") === 0 || strpos($line, "Route::match(['GET', 'POST'], '/modules/student/") === 0) {
        // extract path and controller::method
        if (preg_match("@'/modules/(admin|student)/([^']+)', \s*\[([^:]+)::class, '\\s*([^']+)'\s*\]@", $line)) {
            // fallback to different regex below
        }
        if (preg_match("@/modules/(admin|student)/([^']+)', \s*\[([^:]+)::class,\s*'([^']+)'\]@", $line, $m)) {
            $area = $m[1];
            $path = 'modules/' . $area . '/' . $m[2];
            $method = $m[4];
            $controllerFile = ($area === 'admin') ? $adminController : $studentController;
            if (is_file($controllerFile)) {
                $content = file_get_contents($controllerFile);
                if (preg_match("@function\s+" . preg_quote($method, '@') . "\s*\(@", $content)) {
                    $kept[] = $path;
                }
            }
        }
    }
}
$header = "# Derived migrated compat paths based on existing controller methods\n";
file_put_contents($out, $header . implode("\n", array_values(array_unique($kept))) . "\n");
echo "Wrote " . count($kept) . " paths to $out\n";
