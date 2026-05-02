<?php
require __DIR__ . '/../laravel/vendor/autoload.php';

try {
    $app = require __DIR__ . '/../laravel/bootstrap/app.php';
    echo "APP_LOADED\n";

    \Illuminate\Container\Container::setInstance($app);
    \Illuminate\Support\Facades\Facade::setFacadeApplication($app);

    $kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
    echo "KERNEL_CREATED\n";
    $kernel->bootstrap();
    echo "KERNEL_BOOTSTRAPPED\n";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
