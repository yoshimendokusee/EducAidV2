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
        $result = $this->generateReport($filters);
        if (!$result['ok']) {
            return $result;
        }

        $lines = [
            'EducAid Report',
            'Generated at: ' . now()->toDateTimeString(),
            '',
            'Municipality ID    Total',
            '------------------- -----',
        ];

        foreach ($result['data'] as $r) {
            $municipality = $r->municipality_id ?? 'N/A';
            $lines[] = str_pad((string) $municipality, 19, ' ', STR_PAD_RIGHT) . ' ' . $r->total;
        }

        return ['ok' => true, 'pdf_content' => $this->buildSimplePdf($lines)];
    }

    public function getStatus(string $reportId)
    {
        // Placeholder status - in future track background jobs
        return ['ok' => true, 'status' => 'complete', 'report_id' => $reportId];
    }

    private function buildSimplePdf(array $lines): string
    {
        $escaped = array_map(function ($line) {
            $line = str_replace('\\', '\\\\', $line);
            $line = str_replace('(', '\\(', $line);
            $line = str_replace(')', '\\)', $line);
            return $line;
        }, $lines);

        $text = "BT\n/F1 12 Tf\n50 780 Td\n";
        $first = true;
        foreach ($escaped as $line) {
            if ($first) {
                $text .= "(" . $line . ") Tj\n";
                $first = false;
                continue;
            }
            $text .= "0 -16 Td\n(" . $line . ") Tj\n";
        }
        $text .= "ET";

        $objects = [];
        $objects[] = "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
        $objects[] = "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";
        $objects[] = "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>\nendobj\n";
        $objects[] = "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n";
        $objects[] = "5 0 obj\n<< /Length " . strlen($text) . " >>\nstream\n" . $text . "\nendstream\nendobj\n";

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $obj) {
            $offsets[] = strlen($pdf);
            $pdf .= $obj;
        }

        $xrefPos = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n" . $xrefPos . "\n%%EOF";

        return $pdf;
    }
}

