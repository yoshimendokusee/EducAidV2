<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LegacySessionBridge
{
    public function handle(Request $request, Closure $next): Response
    {
        // Ensure PHP session exists for legacy scripts that depend on $_SESSION.
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        return $next($request);
    }
}
