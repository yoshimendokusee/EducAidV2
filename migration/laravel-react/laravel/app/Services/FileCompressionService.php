<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;
use ZipArchive;

class FileCompressionService
{
    /**
     * Compress distribution files and create archive
     *
     * @param int $distributionId
     * @param int|null $adminId
     * @return array ['success' => bool, 'message' => string, 'archive_size' => int|null]
     */
    public function compressDistribution(int $distributionId, ?int $adminId = null): array
    {
        try {
            Log::info("FileCompressionService::compressDistribution - START for distribution $distributionId");

            // Check if already compressed
            $snapshot = DB::table('distribution_snapshots')
                ->where('distribution_id', $distributionId)
                ->first();

            if (!$snapshot) {
                return [
                    'success' => false,
                    'message' => 'No distribution snapshot found for distribution ID: ' . $distributionId,
                    'archive_size' => null
                ];
            }

            if ($snapshot->files_compressed) {
                return [
                    'success' => false,
                    'message' => 'This distribution has already been compressed and archived.',
                    'archive_size' => null,
                    'already_compressed' => true
                ];
            }

            // Get students from distribution_student_records
            $students = DB::table('students')
                ->join('distribution_student_records', 'students.student_id', '=', 'distribution_student_records.student_id')
                ->leftJoin('distribution_payrolls', function ($join) use ($snapshot) {
                    $join->on('distribution_payrolls.snapshot_id', '=', DB::raw($snapshot->snapshot_id))
                        ->on('distribution_payrolls.student_id', '=', 'students.student_id');
                })
                ->where('distribution_student_records.snapshot_id', $snapshot->snapshot_id)
                ->select('students.student_id', 'students.first_name', 'students.middle_name', 'students.last_name', 'distribution_payrolls.payroll_no')
                ->orderBy('students.student_id')
                ->get();

            if ($students->isEmpty()) {
                return [
                    'success' => false,
                    'message' => 'No students found in distribution snapshot',
                    'archive_size' => null
                ];
            }

            Log::info("FileCompressionService::compressDistribution - Found {$students->count()} students");

            // Create ZIP archive for distribution
            $archiveDir = storage_path('app/distribution-archives');
            if (!file_exists($archiveDir)) {
                mkdir($archiveDir, 0755, true);
            }

            $archivePath = $archiveDir . "/distribution_{$distributionId}_" . time() . '.zip';

            $zip = new ZipArchive();
            if ($zip->open($archivePath, ZipArchive::CREATE) !== true) {
                return [
                    'success' => false,
                    'message' => 'Failed to create ZIP archive',
                    'archive_size' => null
                ];
            }

            $filesCount = 0;

            // Add student documents to ZIP
            foreach ($students as $student) {
                $documents = DB::table('documents')
                    ->where('student_id', $student->student_id)
                    ->where('status', 'permanent')
                    ->get();

                foreach ($documents as $doc) {
                    if (file_exists($doc->file_path)) {
                        $studentFolder = "{$student->last_name}_{$student->first_name}_{$student->student_id}";
                        $arcName = "$studentFolder/" . basename($doc->file_path);
                        $zip->addFile($doc->file_path, $arcName);
                        $filesCount++;
                    }
                }
            }

            $zip->close();

            $archiveSize = filesize($archivePath);

            // Update snapshot as compressed
            DB::table('distribution_snapshots')
                ->where('snapshot_id', $snapshot->snapshot_id)
                ->update([
                    'files_compressed' => true,
                    'compressed_at' => now(),
                    'archive_path' => $archivePath,
                    'archive_size' => $archiveSize
                ]);

            Log::info("FileCompressionService::compressDistribution - Completed: files=$filesCount, size=$archiveSize bytes");

            return [
                'success' => true,
                'message' => "Distribution compressed successfully. Archive size: " . $this->formatBytes($archiveSize),
                'archive_size' => $archiveSize
            ];
        } catch (Exception $e) {
            Log::error("FileCompressionService::compressDistribution - Error: {$e->getMessage()}");

            return [
                'success' => false,
                'message' => 'Compression failed: ' . $e->getMessage(),
                'archive_size' => null
            ];
        }
    }

    /**
     * Decompress distribution archive
     *
     * @param int $distributionId
     * @param string $extractPath
     * @return array ['success' => bool, 'message' => string]
     */
    public function decompressDistribution(int $distributionId, string $extractPath): array
    {
        try {
            $snapshot = DB::table('distribution_snapshots')
                ->where('distribution_id', $distributionId)
                ->first();

            if (!$snapshot || !$snapshot->archive_path) {
                return [
                    'success' => false,
                    'message' => 'No archive found for distribution'
                ];
            }

            if (!file_exists($snapshot->archive_path)) {
                return [
                    'success' => false,
                    'message' => 'Archive file not found at: ' . $snapshot->archive_path
                ];
            }

            if (!file_exists($extractPath)) {
                mkdir($extractPath, 0755, true);
            }

            $zip = new ZipArchive();
            if ($zip->open($snapshot->archive_path) !== true) {
                return [
                    'success' => false,
                    'message' => 'Failed to open ZIP archive'
                ];
            }

            $zip->extractTo($extractPath);
            $zip->close();

            Log::info("FileCompressionService::decompressDistribution - Extracted distribution $distributionId to $extractPath");

            return [
                'success' => true,
                'message' => 'Archive extracted successfully'
            ];
        } catch (Exception $e) {
            Log::error("FileCompressionService::decompressDistribution - Error: {$e->getMessage()}");

            return [
                'success' => false,
                'message' => 'Decompression failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Clean up old compressed archives
     *
     * @param int $daysOld Delete archives older than this
     * @return array ['success' => bool, 'deleted_count' => int]
     */
    public function cleanupOldArchives(int $daysOld = 30): array
    {
        try {
            $archiveDir = storage_path('app/distribution-archives');

            if (!file_exists($archiveDir)) {
                return ['success' => true, 'deleted_count' => 0];
            }

            $deletedCount = 0;
            $now = time();
            $cutoffTime = $now - ($daysOld * 86400);

            foreach (scandir($archiveDir) as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }

                $filePath = $archiveDir . '/' . $file;

                if (is_file($filePath) && filemtime($filePath) < $cutoffTime) {
                    if (unlink($filePath)) {
                        $deletedCount++;
                    }
                }
            }

            Log::info("FileCompressionService::cleanupOldArchives - Deleted $deletedCount old archives");

            return [
                'success' => true,
                'deleted_count' => $deletedCount
            ];
        } catch (Exception $e) {
            Log::error("FileCompressionService::cleanupOldArchives - Error: {$e->getMessage()}");

            return [
                'success' => false,
                'deleted_count' => 0
            ];
        }
    }

    /**
     * Format bytes to human-readable format
     *
     * @param int $bytes
     * @return string
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
