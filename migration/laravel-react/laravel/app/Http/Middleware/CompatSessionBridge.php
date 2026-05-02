<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CompatSessionBridge
{
    public function handle(Request $request, Closure $next): Response
    {
        // Optionally start native PHP session for legacy compatibility.
        // Disabled by default to avoid forcing DB-backed session handlers in local smoke tests.
        if ((bool) env('COMPAT_USE_PHP_SESSION', false)) {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
        }

        return $next($request);
    }
}
