<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Services\ReportService;

class ReportController extends Controller
{
    protected $service;

    public function __construct(ReportService $service)
    {
        $this->service = $service;
    }

    public function generate(Request $request)
    {
        if (!$this->isAdmin()) {
            return response()->json(['ok' => false, 'message' => 'Unauthorized'], 403);
        }

        try {
            $filters = $request->all();
            $result = $this->service->generateReport($filters);
            return response()->json($result);
        } catch (\Throwable $e) {
            Log::error('ReportController::generate error', ['error' => $e->getMessage()]);
            return response()->json(['ok' => false, 'message' => 'Failed to generate report'], 500);
        }
    }

    public function exportCsv(Request $request)
    {
        if (!$this->isAdmin()) return response()->json(['ok' => false, 'message' => 'Unauthorized'], 403);

        $filters = $request->all();
        $result = $this->service->exportCsv($filters);
        if (!$result['ok']) return response()->json($result, 500);

        $filename = 'report_' . date('Ymd_His') . '.csv';
        return response($result['csv'], 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
        ]);
    }

    public function exportPdf(Request $request)
    {
        if (!$this->isAdmin()) return response()->json(['ok' => false, 'message' => 'Unauthorized'], 403);

        $filters = $request->all();
        $result = $this->service->exportPdf($filters);
        if (!$result['ok']) return response()->json($result, 500);

        $filename = 'report_' . date('Ymd_His') . '.pdf';
        return response($result['pdf_content'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
        ]);
    }

    public function status($reportId)
    {
        if (!$this->isAdmin()) return response()->json(['ok' => false, 'message' => 'Unauthorized'], 403);
        $status = $this->service->getStatus($reportId);
        return response()->json($status);
    }

    /**
     * Simple admin check for local smoke tests.
     * Returns true when app is running in local environment or when request has header X-Admin: 1
     */
    private function isAdmin(): bool
    {
        $req = request();
        if ($req && $req->header('X-Admin') === '1') return true;
        return app()->environment('local');
    }
}

