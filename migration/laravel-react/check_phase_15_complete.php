#!/usr/bin/env php
<?php
/**
 * Phase 15: Reports & Data Export - Validation Script
 * Non-invasive checks only (no app logic mutation).
 */

$passed = 0;
$failed = 0;

function check($name, $condition, $details = '') {
    global $passed, $failed;
    $status = $condition ? '[PASS]' : '[FAIL]';
    echo $status . ' ' . $name;
    if ($details !== '') {
        echo ' - ' . $details;
    }
    echo PHP_EOL;

    if ($condition) {
        $passed++;
    } else {
        $failed++;
    }
}

$root = __DIR__;
$laravel = $root . '/laravel';
$react = $root . '/react/src';

$servicePath = $laravel . '/app/Services/ReportService.php';
$controllerPath = $laravel . '/app/Http/Controllers/ReportController.php';
$routesPath = $laravel . '/routes/api.php';
$apiClientPath = $react . '/services/apiClient.js';
$builderPath = $react . '/pages/ReportsBuilder.jsx';
$appPath = $react . '/App.jsx';

$service = file_exists($servicePath) ? file_get_contents($servicePath) : '';
$controller = file_exists($controllerPath) ? file_get_contents($controllerPath) : '';
$routes = file_exists($routesPath) ? file_get_contents($routesPath) : '';
$apiClient = file_exists($apiClientPath) ? file_get_contents($apiClientPath) : '';
$builder = file_exists($builderPath) ? file_get_contents($builderPath) : '';
$app = file_exists($appPath) ? file_get_contents($appPath) : '';

echo PHP_EOL . '== Phase 15a/15b Backend ==' . PHP_EOL;
check('ReportService exists', file_exists($servicePath));
check('ReportService::generateReport', strpos($service, 'function generateReport') !== false);
check('ReportService::exportCsv', strpos($service, 'function exportCsv') !== false);
check('ReportService::exportPdf', strpos($service, 'function exportPdf') !== false);
check('ReportService::getStatus', strpos($service, 'function getStatus') !== false);
check('ReportController exists', file_exists($controllerPath));
check('ReportController::generate', strpos($controller, 'function generate') !== false);
check('ReportController::exportCsv', strpos($controller, 'function exportCsv') !== false);
check('ReportController::exportPdf', strpos($controller, 'function exportPdf') !== false);
check('ReportController::status', strpos($controller, 'function status') !== false);
check('ReportController admin guard', strpos($controller, 'function isAdmin') !== false);

echo PHP_EOL . '== Phase 15b Routes ==' . PHP_EOL;
check('ReportController imported', strpos($routes, 'use App\\Http\\Controllers\\ReportController') !== false);
check('/reports/generate route', strpos($routes, "Route::post('/generate', [ReportController::class, 'generate'])") !== false);
check('/reports/export-csv route', strpos($routes, "Route::post('/export-csv', [ReportController::class, 'exportCsv'])") !== false);
check('/reports/export-pdf route', strpos($routes, "Route::post('/export-pdf', [ReportController::class, 'exportPdf'])") !== false);
check('/reports/status/{reportId} route', strpos($routes, "Route::get('/status/{reportId}', [ReportController::class, 'status'])") !== false);

echo PHP_EOL . '== Phase 15c/15d Frontend ==' . PHP_EOL;
check('apiClient reportApi namespace', strpos($apiClient, 'export const reportApi') !== false);
check('reportApi.generateReport', strpos($apiClient, 'generateReport(payload)') !== false);
check('reportApi.exportCsv', strpos($apiClient, 'exportCsv(filters = {})') !== false);
check('reportApi.exportPdf', strpos($apiClient, 'exportPdf(filters = {})') !== false);
check('reportApi.getStatus', strpos($apiClient, 'getStatus(reportId)') !== false);
check('ReportsBuilder exists', file_exists($builderPath));
check('ReportsBuilder generate action', strpos($builder, 'handleGenerate') !== false);
check('ReportsBuilder CSV action', strpos($builder, 'handleExportCsv') !== false);
check('ReportsBuilder PDF action', strpos($builder, 'handleExportPdf') !== false);
check('ReportsBuilder export buttons', strpos($builder, 'Export CSV') !== false && strpos($builder, 'Export PDF') !== false);
check('App route /admin/reports/builder', strpos($app, 'path="/admin/reports/builder"') !== false);

echo PHP_EOL . '== Syntax & Build ==' . PHP_EOL;
$lintService = shell_exec('php -l "' . $servicePath . '" 2>&1');
$lintController = shell_exec('php -l "' . $controllerPath . '" 2>&1');
$lintRoutes = shell_exec('php -l "' . $routesPath . '" 2>&1');
check('PHP lint ReportService', strpos((string)$lintService, 'No syntax errors') !== false);
check('PHP lint ReportController', strpos((string)$lintController, 'No syntax errors') !== false);
check('PHP lint routes/api.php', strpos((string)$lintRoutes, 'No syntax errors') !== false);
check('React dist exists', file_exists($root . '/react/dist/index.html'));

$total = $passed + $failed;
$rate = $total > 0 ? round(($passed / $total) * 100, 1) : 0;

echo PHP_EOL;
echo 'Summary: ' . $passed . ' passed, ' . $failed . ' failed, success rate ' . $rate . '%' . PHP_EOL;

if ($failed > 0) {
    exit(1);
}

exit(0);
