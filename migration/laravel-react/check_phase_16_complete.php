<?php
/**
 * Phase 16: Student Document Upload & OCR Validation Script
 * Checks all Phase 16 deliverables
 *
 * Usage: php check_phase_16_complete.php
 * Expected: 100% pass rate (all checks pass)
 */

// Test counter
$passed = 0;
$failed = 0;

// Helper function
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

// ============================================================================
// PHASE 16a: UnifiedFileService upload method
// ============================================================================
echo "== Phase 16a Backend Service ==\n";

$unifiedFileServicePath = __DIR__ . '/laravel/app/Services/UnifiedFileService.php';
$serviceContent = file_get_contents($unifiedFileServicePath);

check(file_exists($unifiedFileServicePath), 'UnifiedFileService exists');
check(strpos($serviceContent, 'public function uploadDocument') !== false, 'uploadDocument method exists');
check(strpos($serviceContent, '$documentId = DB::table(\'documents\')->insertGetId') !== false, 'uploadDocument creates DB record');
check(strpos($serviceContent, 'storage_path("app/temp/student_') !== false, 'uploadDocument saves to temp storage');
check(strpos($serviceContent, 'base64_decode') !== false, 'uploadDocument handles base64 encoding');

// ============================================================================
// PHASE 16b: DocumentController upload endpoint
// ============================================================================
echo "\n== Phase 16b Backend Controller ==\n";

$docControllerPath = __DIR__ . '/laravel/app/Http/Controllers/DocumentController.php';
$controllerContent = file_get_contents($docControllerPath);

check(file_exists($docControllerPath), 'DocumentController exists');
check(strpos($controllerContent, 'public function uploadDocument') !== false, 'uploadDocument method exists');
check(strpos($controllerContent, 'Request::validate') !== false || strpos($controllerContent, '$request->validate') !== false, 'uploadDocument validates input');
check(strpos($controllerContent, 'use Exception') !== false, 'Exception use statement added');

// ============================================================================
// PHASE 16c: Routes registration
// ============================================================================
echo "\n== Phase 16c Routes ==\n";

$routesPath = __DIR__ . '/laravel/routes/api.php';
$routesContent = file_get_contents($routesPath);

check(strpos($routesContent, "Route::post('/upload'") !== false, 'POST /documents/upload route exists');
check(strpos($routesContent, "[DocumentController::class, 'uploadDocument']") !== false, 'upload route mapped to controller');

// ============================================================================
// PHASE 16d: React API integration
// ============================================================================
echo "\n== Phase 16d Frontend API ==\n";

$apiClientPath = __DIR__ . '/react/src/services/apiClient.js';
$apiClientContent = file_get_contents($apiClientPath);

check(file_exists($apiClientPath), 'apiClient exists');
check(strpos($apiClientContent, 'uploadDocument(payload)') !== false, 'uploadDocument method in apiClient');
check(strpos($apiClientContent, '/documents/upload') !== false, 'uploadDocument uses correct endpoint');

// ============================================================================
// PHASE 16e: DocumentUpload component
// ============================================================================
echo "\n== Phase 16e Frontend UI ==\n";

$uploadComponentPath = __DIR__ . '/react/src/pages/DocumentUpload.jsx';
$uploadComponentContent = file_exists($uploadComponentPath) ? file_get_contents($uploadComponentPath) : '';

check(file_exists($uploadComponentPath), 'DocumentUpload component exists');
check(strpos($uploadComponentContent, 'documentApi.uploadDocument') !== false, 'Component uses uploadDocument API');
check(strpos($uploadComponentContent, 'handleUpload') !== false, 'Component has handleUpload function');
check(strpos($uploadComponentContent, 'Upload Documents') !== false, 'Component has UI elements');

// ============================================================================
// PHASE 16f: Syntax validation
// ============================================================================
echo "\n== Syntax & Build ==\n";

// PHP lint check
exec('php -l ' . escapeshellarg($unifiedFileServicePath), $unifiedLintOutput, $unifiedLintCode);
check($unifiedLintCode === 0, 'PHP lint UnifiedFileService');

exec('php -l ' . escapeshellarg($docControllerPath), $docLintOutput, $docLintCode);
check($docLintCode === 0, 'PHP lint DocumentController');

exec('php -l ' . escapeshellarg($routesPath), $routesLintOutput, $routesLintCode);
check($routesLintCode === 0, 'PHP lint routes/api.php');

// Check React dist exists
$distPath = __DIR__ . '/react/dist/index.html';
check(file_exists($distPath), 'React dist built');

// ============================================================================
// Summary
// ============================================================================
echo "\n";
echo "Summary: $passed passed, $failed failed, " . ($passed + $failed) . " total, ";
echo "success rate " . round(($passed / ($passed + $failed)) * 100, 1) . "%\n";

exit($failed > 0 ? 1 : 0);
