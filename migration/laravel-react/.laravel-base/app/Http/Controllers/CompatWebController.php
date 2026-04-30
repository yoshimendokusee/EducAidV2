<?php

namespace App\Http\Controllers;

use App\Services\CompatScriptRunner;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CompatWebController extends Controller
{
    public function __construct(private readonly CompatScriptRunner $runner)
    {
    }

    public function root(Request $request): Response
    {
        return $this->runner->run($request, 'website/index.php');
    }

    public function unifiedLogin(Request $request): Response
    {
        return $this->runner->run($request, 'unified_login.php');
    }

    public function adminIndex(Request $request): Response
    {
        return $this->runner->run($request, 'modules/admin/index.php');
    }

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
}
