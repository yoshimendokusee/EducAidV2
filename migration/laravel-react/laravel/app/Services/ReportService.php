<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ReportService
{
    public function generateReport(array $filters)
    {
        try {
            // Basic example: reports on applicants by municipality and status
            $query = DB::table('students')
                ->select('municipality_id', DB::raw('count(*) as total'))
                ->when(!empty($filters['status']), fn($q) => $q->where('status', $filters['status']))
                ->when(!empty($filters['municipality']), fn($q) => $q->where('municipality_id', $filters['municipality']))
                ->groupBy('municipality_id');

            if (!empty($filters['date_from'])) {
                $from = Carbon::parse($filters['date_from'])->startOfDay();
                $query->where('created_at', '>=', $from);
            }
            if (!empty($filters['date_to'])) {
                $to = Carbon::parse($filters['date_to'])->endOfDay();
                $query->where('created_at', '<=', $to);
            }

            $rows = $query->get();
            return ['ok' => true, 'data' => $rows->toArray(), 'generated_at' => now()];
        } catch (\Throwable $e) {
            Log::error('ReportService::generateReport error', ['error' => $e->getMessage()]);
            return ['ok' => false, 'message' => 'Failed to generate report'];
        }
    }

    public function exportCsv(array $filters)
    {
        // Create CSV content and return as string (controller will stream or provide link)
        $result = $this->generateReport($filters);
        if (!$result['ok']) return $result;

        $rows = $result['data'];
        $csv = "municipality_id,total\n";
        foreach ($rows as $r) {
            $csv .= "$r->municipality_id,$r->total\n";
        }
        return ['ok' => true, 'csv' => $csv];
    }

    public function exportPdf(array $filters)
    {
        // Minimal placeholder — real PDF generation can use dompdf or snappy later
        $result = $this->generateReport($filters);
        if (!$result['ok']) return $result;

        $content = "Report generated at: " . now() . "\n\n";
        foreach ($result['data'] as $r) {
            $content .= "Municipality {$r->municipality_id}: {$r->total}\n";
        }
        return ['ok' => true, 'pdf_content' => $content];
    }

    public function getStatus(string $reportId)
    {
        // Placeholder status - in future track background jobs
        return ['ok' => true, 'status' => 'complete', 'report_id' => $reportId];
    }
}

