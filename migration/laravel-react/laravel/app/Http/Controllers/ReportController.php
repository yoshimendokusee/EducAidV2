<?php

namespace App\Http\Controllers;

use App\Services\CompatScriptRunner;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ReportController extends Controller
{
    public function __construct(private readonly CompatScriptRunner $runner)
    {
    }

    // Old source: api/reports/generate_report.php
    // Kept as controlled bridge because this endpoint streams PDF/Excel and logs audit side effects.
    public function generate(Request $request): Response
    {
        return $this->runner->run($request, 'api/reports/generate_report.php');
    }
}
