<?php
// Runs parity for a single compat path and returns one JSON object.
error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/../laravel/vendor/autoload.php';

use Illuminate\Http\Request;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;

$path = $argv[1] ?? '';
$path = ltrim(str_replace('\\', '/', $path), '/');

$entry = [
    'path' => $path,
    'legacy' => null,
    'laravel' => null,
    'diff' => null,
    'error' => null,
];

if ($path === '') {
    $entry['error'] = 'missing-path';
    echo json_encode($entry);
    exit(1);
}

try {
    $app = require __DIR__ . '/../laravel/bootstrap/app.php';
    Container::setInstance($app);
    Facade::setFacadeApplication($app);
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $kernel->bootstrap();
    $runner = $app->make(App\Services\CompatScriptRunner::class);
} catch (Throwable $e) {
    $entry['error'] = 'bootstrap: ' . $e->getMessage();
    echo json_encode($entry);
    exit(2);
}

try {
    $legacyReq = Request::create('/' . $path, 'GET');
    $app->instance('request', $legacyReq);
    $legacyResp = $runner->run($legacyReq, $path);
    $legacyBody = (string) $legacyResp->getContent();
    $entry['legacy'] = [
        'status' => $legacyResp->getStatusCode(),
        'body' => $legacyBody,
    ];
} catch (Throwable $e) {
    $entry['error'] = 'legacy: ' . $e->getMessage();
    echo json_encode($entry);
    exit(3);
}

try {
    $compatUri = '/compat/' . $path;
    $larReq = Request::create($compatUri, 'GET');
    $app->instance('request', $larReq);
    $larResp = $kernel->handle($larReq);
    $larBody = (string) $larResp->getContent();
    $entry['laravel'] = [
        'status' => $larResp->getStatusCode(),
        'body' => $larBody,
    ];

    $legacyNorm = preg_replace('/\s+/', ' ', trim(strip_tags((string) $legacyBody)));
    $larNorm = preg_replace('/\s+/', ' ', trim(strip_tags((string) $larBody)));
    $entry['diff'] = ($legacyNorm === $larNorm) ? 'identical' : 'different';
} catch (Throwable $e) {
    $entry['diff'] = 'no-route';
    if ($entry['error'] === null) {
        $entry['error'] = 'laravel: ' . $e->getMessage();
    }
}

echo json_encode($entry);
