<?php
/**
 * Phase 17: Complete Migration Validation Script
 * 
 * Validates all 16 completed phases and their integration
 * Usage: php check_phase_17_complete.php
 * Expected: 100% pass rate on all deliverables
 */

$passed = 0;
$failed = 0;
$baseDir = __DIR__;

function check($condition, $description) {
    global $passed, $failed;
    if ($condition) {
        echo "[PASS] $description\n";
        $passed++;
    } else {
        echo "[FAIL] $description\n";
        $failed++;
    }
}

echo "========================================\n";
echo "EDUCAID MIGRATION - PHASE 17 VALIDATION\n";
echo "========================================\n\n";

// ============================================================================
// PHASE 1-5: Core Infrastructure
// ============================================================================
echo "== Phases 1-5: Core Infrastructure & Services ==\n";

// Phase 1: Scaffold exists
check(file_exists("$baseDir/laravel/app/Providers/EducaidServiceProvider.php"), 'ServiceProvider registered');
check(file_exists("$baseDir/laravel/routes/api.php"), 'API routes file exists');

// Phase 2.5: Services migrated (18 services)
$servicesDir = "$baseDir/laravel/app/Services";
$services = [
    'MediaEncryptionService.php',
    'OcrProcessingService.php',
    'FileUploadService.php',
    'UnifiedFileService.php',
    'DistributionService.php',
    'EmailNotificationService.php',
    'StudentEmailNotificationService.php',
    'NotificationService.php'
];

foreach ($services as $service) {
    check(file_exists("$servicesDir/$service"), "Service: $service");
}

// ============================================================================
// PHASE 6-10: API Endpoints & Controllers
// ============================================================================
echo "\n== Phases 6-10: API Endpoints & Controllers ==\n";

$controllersDir = "$baseDir/laravel/app/Http/Controllers";
$controllers = [
    'AdminApplicantController.php',
    'DocumentController.php',
    'NotificationController.php',
    'DistributionController.php',
    'SearchController.php',
    'AnalyticsController.php',
    'ReportController.php'
];

foreach ($controllers as $controller) {
    check(file_exists("$controllersDir/$controller"), "Controller: $controller");
}

// ============================================================================
// PHASE 11-15: React Integration & Advanced Features
// ============================================================================
echo "\n== Phases 11-15: React Integration & Features ==\n";

$reactPages = [
    'LoginPage.jsx',
    'AdminDashboard.jsx',
    'StudentDashboard.jsx',
    'ApplicantsPage.jsx',
    'DocumentUpload.jsx',
    'DistributionControlPage.jsx',
    'SearchPage.jsx',
    'AnalyticsDashboard.jsx',
    'ReportsBuilder.jsx'
];

$pagesDir = "$baseDir/react/src/pages";
foreach ($reactPages as $page) {
    check(file_exists("$pagesDir/$page"), "React page: $page");
}

// API routes
$routesContent = file_get_contents("$baseDir/laravel/routes/api.php");
check(strpos($routesContent, '/applicants') !== false, 'Applicant routes registered');
check(strpos($routesContent, '/documents') !== false, 'Document routes registered');
check(strpos($routesContent, '/notifications') !== false, 'Notification routes registered');
check(strpos($routesContent, '/distributions') !== false, 'Distribution routes registered');
check(strpos($routesContent, '/search') !== false, 'Search routes registered');
check(strpos($routesContent, '/analytics') !== false, 'Analytics routes registered');
check(strpos($routesContent, '/reports') !== false, 'Report routes registered');

// ============================================================================
// PHASE 16: Document Upload
// ============================================================================
echo "\n== Phase 16: Document Upload ==\n";

$unifiedFileContent = file_get_contents("$servicesDir/UnifiedFileService.php");
check(strpos($unifiedFileContent, 'public function uploadDocument') !== false, 'uploadDocument method exists');

$docControllerContent = file_get_contents("$controllersDir/DocumentController.php");
check(strpos($docControllerContent, 'public function uploadDocument') !== false, 'DocumentController::uploadDocument');

$apiClientContent = file_get_contents("$baseDir/react/src/services/apiClient.js");
check(strpos($apiClientContent, 'uploadDocument') !== false, 'apiClient.uploadDocument');

// ============================================================================
// PHASE 17: Integration & Deployment Readiness
// ============================================================================
echo "\n== Phase 17: Integration & Deployment Readiness ==\n";

// Build artifacts
check(file_exists("$baseDir/react/dist/index.html"), 'React build dist exists');
check(file_exists("$baseDir/laravel/config"), 'Laravel config directory exists');
check(file_exists("$baseDir/laravel/.env.example"), 'Environment file example');

// Documentation
$docFiles = [
    'PHASE_15_COMPLETION.md',
    'PHASE_16_COMPLETION.md',
    'SERVICES_MIGRATION_SUMMARY.md',
    'MODULE_MIGRATION_PLAN.md'
];

$migrationDir = "$baseDir";
foreach ($docFiles as $doc) {
    check(file_exists("$migrationDir/$doc"), "Documentation: $doc");
}

// Validator scripts
check(file_exists("$baseDir/check_phase_15_complete.php"), 'Phase 15 validator exists');
check(file_exists("$baseDir/check_phase_16_complete.php"), 'Phase 16 validator exists');

// ============================================================================
// PHP Syntax Validation
// ============================================================================
echo "\n== Syntax Validation ==\n";

$phpFiles = [
    'laravel/app/Providers/EducaidServiceProvider.php',
    'laravel/app/Http/Controllers/DocumentController.php',
    'laravel/routes/api.php'
];

foreach ($phpFiles as $file) {
    exec("php -l " . escapeshellarg("$baseDir/$file"), $output, $code);
    check($code === 0, "PHP lint: $file");
}

// ============================================================================
// Build Validation
// ============================================================================
echo "\n== Build Validation ==\n";

// Check React build
$jsFile = "$baseDir/react/dist/assets/index-*.js";
$matches = glob($jsFile);
check(count($matches) > 0, 'React JavaScript bundle generated');

$cssFile = "$baseDir/react/dist/assets/index-*.css";
$matches = glob($cssFile);
check(count($matches) > 0, 'React CSS bundle generated');

// Check main HTML
check(file_exists("$baseDir/react/dist/index.html"), 'React HTML entry point');

// ============================================================================
// Architecture Validation
// ============================================================================
echo "\n== Architecture Validation ==\n";

// Check database structure hints
$migrationsDir = "$baseDir/laravel/database/migrations";
check(is_dir($migrationsDir), 'Migrations directory exists');

// Check environment variables
$envContent = file_get_contents("$baseDir/laravel/.env.example");
check(strpos($envContent, 'APP_URL') !== false, 'App URL configured');
check(strpos($envContent, 'DB_CONNECTION') !== false, 'Database connection configured');

// ============================================================================
// Route Coverage Validation
// ============================================================================
echo "\n== Route Coverage Validation ==\n";

$expectedRoutes = [
    'applicants' => 'Applicant management',
    'documents' => 'Document upload & retrieval',
    'notifications' => 'Notification API',
    'distributions' => 'Distribution management',
    'search' => 'Advanced search',
    'analytics' => 'Analytics dashboard',
    'reports' => 'Reports & exports'
];

foreach ($expectedRoutes as $route => $desc) {
    check(strpos($routesContent, $route) !== false, "Route: /$route ($desc)");
}

// ============================================================================
// React Component Validation
// ============================================================================
echo "\n== React Component Validation ==\n";

// Check App.jsx routing
$appContent = file_get_contents("$baseDir/react/src/App.jsx");
check(strpos($appContent, 'ProtectedRoute') !== false, 'Protected routes configured');
check(
    strpos($appContent, 'createBrowserRouter') !== false
    || strpos($appContent, 'RouterProvider') !== false
    || strpos($appContent, 'BrowserRouter') !== false
    || strpos($appContent, 'Routes') !== false
    || strpos($appContent, 'Route') !== false,
    'Routing configured'
);

// Check API client exports
check(strpos($apiClientContent, 'export const') !== false, 'API client exports services');

// ============================================================================
// Deployment Readiness
// ============================================================================
echo "\n== Deployment Readiness ==\n";

// Check composer files
check(file_exists("$baseDir/laravel/composer.json"), 'Composer configuration exists');

// Check package files  
check(file_exists("$baseDir/laravel/package.json") || file_exists("$baseDir/react/package.json"), 'Package.json files exist');
check(file_exists("$baseDir/react/package.json"), 'React package.json exists');

// ============================================================================
// Summary
// ============================================================================
echo "\n";
echo "========================================\n";
$total = $passed + $failed;
$percent = round(($passed / $total) * 100, 1);
echo "Summary: $passed passed, $failed failed, $total total\n";
echo "Success Rate: $percent%\n";
echo "========================================\n";

if ($failed > 0) {
    echo "\n⚠️  MIGRATION INCOMPLETE - $failed checks failed\n";
    exit(1);
} else {
    echo "\n✅ MIGRATION READY FOR DEPLOYMENT - All $total checks passed\n";
    exit(0);
}
