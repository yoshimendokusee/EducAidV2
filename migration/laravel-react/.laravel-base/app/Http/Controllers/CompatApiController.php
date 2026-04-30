<?php

namespace App\Http\Controllers;

use App\Services\CompatScriptRunner;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CompatApiController extends Controller
{
    public function __construct(private readonly CompatScriptRunner $runner)
    {
    }

    public function ajax(Request $request, string $path): Response
    {
        return $this->runner->run($request, $path);
    }
}
