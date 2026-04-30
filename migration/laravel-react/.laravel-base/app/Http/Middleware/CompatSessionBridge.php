<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CompatSessionBridge
{
    public function handle(Request $request, Closure $next): Response
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        return $next($request);
    }
}
