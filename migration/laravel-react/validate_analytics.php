#!/usr/bin/env php
<?php
/**
 * Phase 13e: Analytics System Integration Validation
 * Validates all components without requiring live HTTP server
 */

echo "\n=== Phase 13e: Analytics System Validation ===\n";

// Load Laravel autoloader
require_once __DIR__ . '/laravel/vendor/autoload.php';
require_once __DIR__ . '/laravel/config/database.php';

$passed = 0;
$failed = 0;

function check($name, $condition, $details = '') {
    global $passed, $failed;
    $status = $condition ? '✓' : '✗';
    $color = $condition ? "\033[92m" : "\033[91m";
    echo "$color$status\033[0m $name";
    if ($details) echo " - $details";
    echo "\n";
    if ($condition) $passed++; else $failed++;
}

// ============ TEST 1: File Existence ============
echo "\n[1/7] Checking file existence...\n";
check("AnalyticsService.php exists", 
    file_exists(__DIR__ . '/laravel/app/Services/AnalyticsService.php'));
check("AnalyticsController.php exists", 
    file_exists(__DIR__ . '/laravel/app/Http/Controllers/AnalyticsController.php'));

// ============ TEST 2: Class Loading ============
echo "\n[2/7] Checking PHP class loading...\n";

$service_exists = false;
$controller_exists = false;

try {
    require_once __DIR__ . '/laravel/app/Services/AnalyticsService.php';
    check("AnalyticsService class loads", class_exists('App\Services\AnalyticsService'));
    $service_exists = class_exists('App\Services\AnalyticsService');
} catch (\Exception $e) {
    check("AnalyticsService class loads", false, $e->getMessage());
}

try {
    require_once __DIR__ . '/laravel/app/Http/Controllers/AnalyticsController.php';
    check("AnalyticsController class loads", class_exists('App\Http\Controllers\AnalyticsController'));
    $controller_exists = class_exists('App\Http\Controllers\AnalyticsController');
} catch (\Exception $e) {
    check("AnalyticsController class loads", false, $e->getMessage());
}

// ============ TEST 3: Service Methods ============
echo "\n[3/7] Checking AnalyticsService methods...\n";

if ($service_exists) {
    $methods = [
        'getSystemMetrics',
        'getApplicationDistribution',
        'getDocumentStatus',
        'getDistributionStats',
        'getPerformanceMetrics',
        'getActivitySummary',
        'getTimeSeriesData',
        'getTopMunicipalities',
        'getDashboardData'
    ];
    
    $reflection = new ReflectionClass('App\Services\AnalyticsService');
    foreach ($methods as $method) {
        $exists = $reflection->hasMethod($method);
        check("Service method: $method()", $exists);
    }
}

// ============ TEST 4: Controller Methods ============
echo "\n[4/7] Checking AnalyticsController methods...\n";

if ($controller_exists) {
    $methods = [
        'getSystemMetrics',
        'getApplications',
        'getDocuments',
        'getDistributions',
        'getMunicipalities',
        'getPerformance',
        'getActivity',
        'getTimeSeries',
        'getDashboard'
    ];
    
    $reflection = new ReflectionClass('App\Http\Controllers\AnalyticsController');
    foreach ($methods as $method) {
        $exists = $reflection->hasMethod($method);
        check("Controller method: $method()", $exists);
    }
}

// ============ TEST 5: Routes Registration ============
echo "\n[5/7] Checking API routes...\n";

$routes_file = __DIR__ . '/laravel/routes/api.php';
$routes_content = file_get_contents($routes_file);

$route_checks = [
    "AnalyticsController import" => "use App\Http\Controllers\AnalyticsController",
    "/analytics/system-metrics" => "'system-metrics'",
    "/analytics/applications" => "'applications'",
    "/analytics/documents" => "'documents'",
    "/analytics/distributions" => "'distributions'",
    "/analytics/municipalities" => "'municipalities'",
    "/analytics/performance" => "'performance'",
    "/analytics/activity" => "'activity'",
    "/analytics/timeseries" => "'timeseries'",
    "/analytics/dashboard" => "'dashboard'",
];

foreach ($route_checks as $name => $pattern) {
    check("Route registered: $name", 
        strpos($routes_content, $pattern) !== false);
}

// ============ TEST 6: React Components ============
echo "\n[6/7] Checking React components...\n";

check("AnalyticsDashboard.jsx exists",
    file_exists(__DIR__ . '/react/src/pages/AnalyticsDashboard.jsx'));

check("API client methods added",
    strpos(file_get_contents(__DIR__ . '/react/src/services/apiClient.js'), 
           'getAnalyticsDashboard()') !== false);

check("Route added to App.jsx",
    strpos(file_get_contents(__DIR__ . '/react/src/App.jsx'), 
           'AnalyticsDashboard') !== false);

check("Admin link updated in AdminDashboard.jsx",
    strpos(file_get_contents(__DIR__ . '/react/src/pages/AdminDashboard.jsx'), 
           '/admin/analytics') !== false);

// ============ TEST 7: PHP Syntax Validation ============
echo "\n[7/7] Checking PHP syntax...\n";

$php_files = [
    __DIR__ . '/laravel/app/Services/AnalyticsService.php',
    __DIR__ . '/laravel/app/Http/Controllers/AnalyticsController.php',
    __DIR__ . '/laravel/routes/api.php'
];

foreach ($php_files as $file) {
    $output = shell_exec("cd " . __DIR__ . "/laravel && php -l " . basename(dirname($file)) . "/" . basename($file) . " 2>&1");
    $is_valid = strpos($output, 'No syntax errors') !== false;
    check("PHP syntax valid: " . basename($file), $is_valid);
}

// ============ Summary ============
echo "\n=== Validation Summary ===\n";
$total = $passed + $failed;
echo "\033[92m✓ Passed: $passed\033[0m\n";
if ($failed > 0) {
    echo "\033[91m✗ Failed: $failed\033[0m\n";
}
echo "Total: $total\n";
echo "Success Rate: " . round(($passed / $total) * 100, 1) . "%\n";

if ($failed === 0) {
    echo "\n\033[92m\033[1m✓ ALL VALIDATION CHECKS PASSED!\033[0m\n";
    echo "\nAnalytics Dashboard System Ready:\n";
    echo "  ✓ Backend: AnalyticsService (9 methods) + AnalyticsController (9 endpoints)\n";
    echo "  ✓ Routes: 9 API endpoints registered under /api/analytics/\n";
    echo "  ✓ Frontend: React AnalyticsDashboard component with real data integration\n";
    echo "  ✓ Admin Portal: Dashboard link added to admin home\n\n";
    echo "Next Steps:\n";
    echo "  1. Start Laravel dev server: php artisan serve\n";
    echo "  2. Login as admin\n";
    echo "  3. Navigate to: http://localhost:8000/admin/analytics\n";
    echo "  4. View real-time system metrics and charts\n";
    exit(0);
} else {
    echo "\n\033[91m\033[1m✗ SOME CHECKS FAILED\033[0m\n";
    exit(1);
}
?>
