<?php

if (php_sapi_name() !== 'cli-server') {
    return false;
}

// Get the requested URI and clean it
$requestUri = $_SERVER['REQUEST_URI'];
if (strpos($requestUri, '?') !== false) {
    $requestUri = substr($requestUri, 0, strpos($requestUri, '?'));
}

// Check if the file exists in the public directory
$file = __DIR__ . '/public' . $requestUri;

// If it's a file that exists, serve it
if (is_file($file)) {
    return false;
}

// If it's a directory, check for index.php
if (is_dir($file)) {
    if (is_file($file . '/index.php')) {
        $_SERVER['SCRIPT_FILENAME'] = $file . '/index.php';
        return false;
    }
}

// Otherwise, route everything to the Laravel index.php
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/public/index.php';
require_once __DIR__ . '/public/index.php';
