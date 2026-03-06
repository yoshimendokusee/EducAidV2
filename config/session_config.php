<?php
// Session configuration - must be included BEFORE session_start()
// Detects HTTPS vs HTTP and adjusts settings for localhost compatibility

if (session_status() === PHP_SESSION_NONE) {
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
             || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
             || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.use_strict_mode', 1);
    ini_set('session.use_only_cookies', 1);

    // Only set cookie_secure when actually serving over HTTPS
    // Setting this to 1 on plain HTTP (localhost) will silently break sessions
    ini_set('session.cookie_secure', $isSecure ? 1 : 0);
}
