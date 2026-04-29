<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class DistributionManager
{
    private FileCompressionService $compressionService;

    public function __construct(FileCompressionService $compressionService)
    {
        $this->compressionService = $compressionService;
    }

    /**
     * End active distribution and compress files
     *
     * @param int $distributionId
     * @param int|null $adminId
     * @param bool $compressNow
     * @return array ['success' => bool, 'message' => string]
     */
    public function endDistribution(int $distributionId, ?int $adminId = null, bool $compressNow = true): array
    {
        try {
            DB::beginTransaction();

            Log::info("DistributionManager::endDistribution - START for distribution: $distributionId");

            // Check if distribution exists
            $distribution = DB::table('distributions')
                ->where('distribution_id', $distributionId)
                ->first();

            if (!$distribution) {
                DB::rollBack();
                return [
                    'success' => false,
                    'message' => 'Distribution not found'
                ];
            }

            if ($distribution->status === 'ended') {
                DB::rollBack();
                return [
                    'success' => false,
                    'message' => 'Distribution is already ended'
                ];
            }

            // Compress files BEFORE resetting students
            if ($compressNow) {
                Log::info("DistributionManager::endDistribution - Compressing files for distribution: $distributionId");

                $compressionResult = $this->compressionService->compressDistribution($distributionId, $adminId);

                if (!$compressionResult['success']) {
                    DB::rollBack();
                    return [
                        'success' => false,
                        'message' => 'Compression failed: ' . $compressionResult['message']
                    ];
                }
            }

            // Reset all students in this distribution back to 'applicant'
            $snapshot = DB::table('distribution_snapshots')
                ->where('distribution_id', $distributionId)
                ->first();

            if ($snapshot) {
                DB::table('students')
                    ->whereIn('student_id', function ($query) use ($snapshot) {
                        $query->select('student_id')
                            ->from('distribution_student_records')
                            ->where('snapshot_id', $snapshot->snapshot_id);
                    })
                    ->update([
                        'status' => 'applicant',
                        'distribution_status' => null,
                        'updated_at' => now()
                    ]);

                Log::info("DistributionManager::endDistribution - Reset students to 'applicant'");
            }

            // Update distribution status
            DB::table('distributions')
                ->where('distribution_id', $distributionId)
                ->update([
                    'status' => 'ended',
                    'ended_at' => now(),
                    'ended_by' => $adminId
                ]);

            // Create audit log
            DB::table('audit_logs')->insert([
                'admin_id' => $adminId,
                'action' => 'distribution_ended',
                'details' => json_encode(['distribution_id' => $distributionId]),
                'ip_address' => request()->ip(),
                'created_at' => now()
            ]);

            DB::commit();

            Log::info("DistributionManager::endDistribution - COMPLETED for distribution: $distributionId");

            return [
                'success' => true,
                'message' => 'Distribution ended successfully'
            ];
        } catch (Exception $e) {
            DB::rollBack();
            Log::error("DistributionManager::endDistribution - Error: {$e->getMessage()}");

            return [
                'success' => false,
                'message' => 'Failed to end distribution: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get distribution statistics
     *
     * @param int $distributionId
     * @return array
     */
    public function getDistributionStats(int $distributionId): array
    {
        try {
            $distribution = DB::table('distributions')
                ->where('distribution_id', $distributionId)
                ->first();

            if (!$distribution) {
                return ['success' => false, 'message' => 'Distribution not found'];
            }

            $snapshot = DB::table('distribution_snapshots')
                ->where('distribution_id', $distributionId)
                ->first();

            $totalStudents = 0;
            $documentsProcessed = 0;
            $averagePaymentAmount = 0;

            if ($snapshot) {
                $totalStudents = DB::table('distribution_student_records')
                    ->where('snapshot_id', $snapshot->snapshot_id)
                    ->count();

                $documentsProcessed = DB::table('distribution_payrolls')
                    ->where('snapshot_id', $snapshot->snapshot_id)
                    ->count();

                $avgQuery = DB::table('distribution_payrolls')
                    ->where('snapshot_id', $snapshot->snapshot_id)
                    ->avg('amount');

                $averagePaymentAmount = $avgQuery ?? 0;
            }

            return [
                'success' => true,
                'distribution_id' => $distributionId,
                'status' => $distribution->status,
                'created_at' => $distribution->created_at,
                'ended_at' => $distribution->ended_at,
                'total_students' => $totalStudents,
                'documents_processed' => $documentsProcessed,
                'average_payment' => $averagePaymentAmount,
                'is_compressed' => $snapshot ? $snapshot->files_compressed : false
            ];
        } catch (Exception $e) {
            Log::error("DistributionManager::getDistributionStats - Error: {$e->getMessage()}");

            return [
                'success' => false,
                'message' => 'Failed to get statistics: ' . $e->getMessage()
            ];
        }
    }
}
