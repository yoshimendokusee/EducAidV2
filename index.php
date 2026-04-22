<?php
/**
 * Vercel-compatible front controller.
 * Keeps the existing Railway/Apache layout intact while providing a root entry point.
 */

require_once __DIR__ . '/config/security_headers.php';

if (file_exists(__DIR__ . '/includes/railway_volume_init.php')) {
    require_once __DIR__ . '/includes/railway_volume_init.php';
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$requestPath = rawurldecode(str_replace('\\', '/', $requestPath));

if (strpos($requestPath, '/EducAid/') === 0) {
    $requestPath = substr($requestPath, strlen('/EducAid'));
}

if ($requestPath === '/' || $requestPath === '') {
    require __DIR__ . '/website/index.php';
    return;
}

$publicPages = [
    '/landingpage',
    '/about',
    '/how-it-works',
    '/requirements',
    '/announcements',
    '/contact',
];

if (in_array($requestPath, $publicPages, true)) {
    $pagePath = __DIR__ . '/website' . $requestPath . '.php';
    if (is_file($pagePath)) {
        require $pagePath;
        return;
    }
}

$candidatePaths = [
    __DIR__ . $requestPath,
    __DIR__ . '/website' . $requestPath,
    __DIR__ . '/modules/admin' . $requestPath,
    __DIR__ . '/modules/student' . $requestPath,
    __DIR__ . '/modules/super_admin' . $requestPath,
    __DIR__ . '/api' . $requestPath,
];

foreach ($candidatePaths as $candidatePath) {
    if (!is_file($candidatePath)) {
        continue;
    }

    if (pathinfo($candidatePath, PATHINFO_EXTENSION) === 'php') {
        require $candidatePath;
        return;
    }

    $mimeType = function_exists('mime_content_type') ? @mime_content_type($candidatePath) : false;
    if (!$mimeType) {
        $extension = strtolower(pathinfo($candidatePath, PATHINFO_EXTENSION));
        switch ($extension) {
            case 'css':
                $mimeType = 'text/css; charset=UTF-8';
                break;
            case 'js':
                $mimeType = 'application/javascript; charset=UTF-8';
                break;
            case 'json':
                $mimeType = 'application/json; charset=UTF-8';
                break;
            case 'svg':
                $mimeType = 'image/svg+xml';
                break;
            case 'png':
                $mimeType = 'image/png';
                break;
            case 'jpg':
            case 'jpeg':
                $mimeType = 'image/jpeg';
                break;
            case 'gif':
                $mimeType = 'image/gif';
                break;
            case 'ico':
                $mimeType = 'image/x-icon';
                break;
            case 'woff':
                $mimeType = 'font/woff';
                break;
            case 'woff2':
                $mimeType = 'font/woff2';
                break;
            case 'ttf':
                $mimeType = 'font/ttf';
                break;
            case 'eot':
                $mimeType = 'application/vnd.ms-fontobject';
                break;
            default:
                $mimeType = 'application/octet-stream';
                break;
        }
    }

    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . (string) filesize($candidatePath));
    readfile($candidatePath);
    return;
}

http_response_code(404);
echo '404 Not Found: ' . htmlspecialchars($requestPath, ENT_QUOTES, 'UTF-8');
