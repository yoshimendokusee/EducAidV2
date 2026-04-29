<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * DistributionService
 * Manages aid distribution lifecycle and status tracking
 */
class DistributionService
{
    /**
     * Start a new distribution cycle
     *
     * @param string $academicYear Academic year
     * @param string $semester Semester designation
     * @param int $adminId Admin initiating distribution
     * @return array ['success' => bool, 'distributionId' => string|null, 'message' => string]
     */
    public function startDistribution(
        string $academicYear,
        string $semester,
        int $adminId
    ): array {
        try {
            // Check for active distribution
            $active = DB::table('distributions')
                ->where('status', 'active')
                ->first();

            if ($active) {
                return [
                    'success' => false,
                    'message' => "Active distribution already exists (ID: {$active->distribution_id})",
                ];
            }

            // Create distribution record
            $distributionId = DB::table('distributions')->insertGetId([
                'academic_year' => $academicYear,
                'semester' => $semester,
                'status' => 'active',
                'started_by' => $adminId,
                'started_at' => now(),
            ]);

            // Set global config
            DB::table('config')
                ->updateOrInsert(
                    ['key' => 'distribution_status'],
                    ['value' => 'active', 'updated_at' => now()]
                );

            Log::info("DistributionService: Distribution started", [
                'distribution_id' => $distributionId,
                'year' => $academicYear,
                'semester' => $semester,
            ]);

            return [
                'success' => true,
                'distributionId' => (string)$distributionId,
                'message' => 'Distribution started successfully',
            ];
        } catch (Exception $e) {
            Log::error('DistributionService::startDistribution failed', [
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * End current distribution
     *
     * @param int $adminId Admin ending distribution
     * @return array ['success' => bool, 'message' => string]
     */
    public function endDistribution(int $adminId): array
    {
        try {
            DB::beginTransaction();

            // Get active distribution
            $distribution = DB::table('distributions')
                ->where('status', 'active')
                ->first();

            if (!$distribution) {
                return ['success' => false, 'message' => 'No active distribution'];
            }

            // Update distribution status
            DB::table('distributions')
                ->where('distribution_id', $distribution->distribution_id)
                ->update([
                    'status' => 'ended',
                    'ended_at' => now(),
                    'ended_by' => $adminId,
                ]);

            // Reset students from 'given' status back to 'applicant'
            DB::table('students')
                ->where('status', 'given')
                ->update([
                    'status' => 'applicant',
                    'payroll_no' => null,
                    'payroll_semester' => null,
                ]);

            // Set global distribution status to inactive
            DB::table('config')
                ->updateOrInsert(
                    ['key' => 'distribution_status'],
                    ['value' => 'inactive', 'updated_at' => now()]
                );

            DB::commit();

            Log::info("DistributionService: Distribution ended", [
                'distribution_id' => $distribution->distribution_id,
                'admin_id' => $adminId,
            ]);

            return ['success' => true, 'message' => 'Distribution ended successfully'];
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('DistributionService::endDistribution failed', [
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get active distribution
     *
     * @return array|null Active distribution record
     */
    public function getActiveDistribution(): ?array
    {
        try {
            $dist = DB::table('distributions')
                ->where('status', 'active')
                ->first();

            return $dist ? (array)$dist : null;
        } catch (Exception $e) {
            Log::error('DistributionService::getActiveDistribution failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get distribution history
     *
     * @param int $limit Number of distributions to return
     * @return array Array of distributions
     */
    public function getDistributionHistory(int $limit = 50): array
    {
        try {
            return DB::table('distributions')
                ->orderBy('started_at', 'desc')
                ->limit($limit)
                ->get()
                ->toArray();
        } catch (Exception $e) {
            Log::error('DistributionService::getDistributionHistory failed', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Get distribution statistics
     *
     * @param string|null $distributionId Filter by distribution ID
     * @return array Statistics
     */
    public function getStatistics(?string $distributionId = null): array
    {
        try {
            $query = DB::table('students')->where('status', 'given');

            if ($distributionId) {
                // Students in this distribution snapshot
                $query = DB::table('distribution_student_records')
                    ->where('distribution_id', $distributionId);
            }

            return [
                'total_given' => $query->count(),
                'by_status' => DB::table('students')
                    ->selectRaw('status, COUNT(*) as count')
                    ->groupBy('status')
                    ->get()
                    ->keyBy('status')
                    ->map(fn($row) => $row->count)
                    ->toArray(),
            ];
        } catch (Exception $e) {
            Log::error('DistributionService::getStatistics failed', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }
}
