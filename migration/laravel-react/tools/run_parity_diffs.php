<?php
// Run parity diffs for compat scripts vs Laravel routes where possible.
// Usage: php tools/run_parity_diffs.php > parity_report.json

require __DIR__ . '/../laravel/vendor/autoload.php';

error_reporting(E_ALL);
ini_set('display_errors', '1');

use App\Services\CompatScriptRunner;
use Illuminate\Http\Request;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Container\Container;

$base = __DIR__ . '/..';
$migratedList = $base . '/migrated_compat_paths.txt';
$mapFile = __DIR__ . '/parity_map.json';

$paths = [];
if (is_file($mapFile)) {
    $data = json_decode(file_get_contents($mapFile), true);
    if (!empty($data['paths'])) $paths = $data['paths'];
}

// fallback: try generate_parity_map.php
if (empty($paths)) {
    $output = shell_exec('php ' . __DIR__ . '/generate_parity_map.php');
    $json = json_decode($output, true);
    if (!empty($json['paths'])) $paths = $json['paths'];
}

try {
    $results = [];
// Bootstrap the Laravel application so helpers (config(), response(), etc.) are available.
$app = require __DIR__ . '/../laravel/bootstrap/app.php';

// Ensure the container and facades are initialized for included legacy scripts.
Container::setInstance($app);
Facade::setFacadeApplication($app);

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();

$runner = $app->make(App\Services\CompatScriptRunner::class);

foreach ($paths as $p) {
    $entry = ['path' => $p, 'legacy' => null, 'laravel' => null, 'diff' => null, 'error' => null];

    try {
        // Create a request that targets the compat file via the runner directly
        $req = Request::create('/' . $p, 'GET');
        $resp = $runner->run($req, $p);
        $legacyBody = (string) $resp->getContent();
        $entry['legacy'] = ['status' => $resp->getStatusCode(), 'body' => $legacyBody];
    } catch (Throwable $e) {
        $entry['error'] = 'Legacy run error: ' . $e->getMessage();
        $results[] = $entry;
        continue;
    }

    // Attempt to find a Laravel route path that mentions this compat path in controllers.
    // Heuristic: search for controller methods that include this path and call them via their route name if possible.
    // Simpler: try to request the same URI under /compat/{path} and let web.php routing map it (if present).
    $compatUri = '/compat/' . ltrim($p, '/');
    try {
        $larReq = Request::create($compatUri, 'GET');
        $larResp = $kernel->handle($larReq);
        $larBody = (string) $larResp->getContent();
        $entry['laravel'] = ['status' => $larResp->getStatusCode(), 'body' => $larBody];

        // Simple normalized diff: strip whitespace and compare
        $legacyNorm = preg_replace('/\s+/', ' ', trim(strip_tags($legacyBody)));
        $larNorm = preg_replace('/\s+/', ' ', trim(strip_tags($larBody)));
        $entry['diff'] = ($legacyNorm === $larNorm) ? 'identical' : 'different';
    } catch (Throwable $e) {
        $entry['laravel'] = null;
        $entry['diff'] = 'no-route';
    }

    $results[] = $entry;
}

    echo json_encode(['generated_at'=>date('c'), 'results'=>$results], JSON_PRETTY_PRINT);
} catch (\Throwable $e) {
    file_put_contents(__DIR__ . '/parity_report_error.log', $e->getMessage() . "\n" . $e->getTraceAsString());
    echo json_encode(['generated_at'=>date('c'), 'error'=>$e->getMessage()], JSON_PRETTY_PRINT);
}
