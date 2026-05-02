<?php
require __DIR__ . '/../laravel/vendor/autoload.php';
$map = json_decode(file_get_contents(__DIR__.'/parity_map.json'), true);
$paths = $map['paths'] ?? [];
if (empty($paths)) { echo "NO_PATHS\n"; exit(0); }

$app = require __DIR__ . '/../laravel/bootstrap/app.php';
\Illuminate\Container\Container::setInstance($app);
\Illuminate\Support\Facades\Facade::setFacadeApplication($app);
$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
$runner = $app->make(App\Services\CompatScriptRunner::class);

$p = $paths[0];
$req = Illuminate\Http\Request::create('/' . $p, 'GET');
$app->instance('request', $req);
$resp = $runner->run($req, $p);
echo "RUNNING: $p\n";
if ($resp) {
    echo "STATUS: " . $resp->getStatusCode() . "\n";
    $body = (string) $resp->getContent();
    echo "BODY_LEN: " . strlen($body) . "\n";
    echo "BODY_PREVIEW:\n" . substr($body,0,200) . "\n";
} else {
    echo "NO_RESPONSE\n";
}
