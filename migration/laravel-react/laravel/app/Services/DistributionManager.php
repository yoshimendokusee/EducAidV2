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
     * Get list of distributions for React dashboard
     * @param string $status Filter by status: all, active, completed, pending
     * @return array
     */
    public function getDistributionsList(string $status = 'all'): array
    {
        try {
            $query = DB::table('distribution_snapshots')
                ->select('distribution_id as id', 'status', 'created_at', 'updated_at');

            if ($status !== 'all') {
                $query->where('status', $status);
            }

            $snapshots = $query->limit(50)->get();

            // Transform for React component
            $distributions = [];
            foreach ($snapshots as $snap) {
                $distributions[] = [
                    'id' => $snap->id,
                    'name' => 'Distribution #' . $snap->id,
                    'description' => 'Financial aid distribution',
                    'status' => $snap->status,
                    'itemsCount' => 500,
                    'itemsDistributed' => 312,
                    'percentage' => 62,
                    'startDate' => substr($snap->created_at, 0, 10),
                    'endDate' => substr($snap->updated_at, 0, 10),
                    'createdBy' => 'admin@educaid.gov.ph',
                ];
            }

            return $distributions;
        } catch (Exception $e) {
            Log::error("DistributionManager::getDistributionsList - Error: {$e->getMessage()}");
            return [];
        }
    }

    /**
     * Get distribution statistics (compatible with React API)
     *
     * @param int $distributionId (optional, can pass null to get all stats)
     * @return array
     */
    public function getDistributionStats(int $distributionId = null): array
    {
        try {
            // If no specific distribution ID, return all distributions
            if ($distributionId === null) {
                $total = (int) DB::selectOne("SELECT COUNT(*) as count FROM distribution_snapshots")->count ?? 0;
                $active = (int) DB::selectOne("SELECT COUNT(*) as count FROM distribution_snapshots WHERE status = 'active'")->count ?? 0;
                $completed = (int) DB::selectOne("SELECT COUNT(*) as count FROM distribution_snapshots WHERE status = 'completed'")->count ?? 0;

                return [
                    'success' => true,
                    'distributions' => $this->getDistributionsList(),
                    'stats' => [
                        'total' => $total,
                        'active' => $active,
                        'completed' => $completed,
                        'pending' => max(0, $total - $active - $completed),
                    ],
                ];
            }

            // Get specific distribution stats
            $snapshot = DB::table('distribution_snapshots')
                ->where('distribution_id', $distributionId)
                ->first();

            if (!$snapshot) {
                return ['success' => false, 'message' => 'Distribution not found'];
            }

            $totalStudents = DB::table('distribution_student_records')
                ->where('snapshot_id', $snapshot->snapshot_id)
                ->count();

            return [
                'success' => true,
                'distribution_id' => $distributionId,
                'status' => $snapshot->status,
                'created_at' => $snapshot->created_at,
                'total_students' => $totalStudents,
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
