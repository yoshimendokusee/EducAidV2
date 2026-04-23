<?php

namespace App\Http\Controllers;

use App\Services\LegacyScriptRunner;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LegacyWebController extends Controller
{
    public function __construct(private readonly LegacyScriptRunner $runner)
    {
    }

    // Migrated from old file: website/index.php
    public function root(Request $request): Response
    {
        return $this->runner->run($request, 'website/index.php');
    }

    // Migrated from old file: unified_login.php
    public function unifiedLogin(Request $request): Response
    {
        return $this->runner->run($request, 'unified_login.php');
    }

    // Migrated from old file: modules/admin/index.php
    public function adminIndex(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/index.php');
    }

    // Migrated from old file: modules/student/student_login.php
    public function studentLogin(Request $request): Response
    {
        return $this->runner->run($request, 'modules/student/student_login.php');
    }

    public function fallback(Request $request, string $path): Response
    {
        if (!str_ends_with($path, '.php')) {
            return response('Not Found', 404);
        }

        return $this->runner->run($request, $path);
    }

    public function render(Request $request): Response
    {
        $path = (string) $request->query('path', '');
        if ($path === '' || !str_ends_with($path, '.php')) {
            return response('Invalid legacy path', 422);
        }

        return $this->runner->run($request, $path);
    }
}
