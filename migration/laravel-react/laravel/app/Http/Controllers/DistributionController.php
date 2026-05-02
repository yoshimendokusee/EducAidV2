<?php

namespace App\Http\Controllers;

use App\Services\DistributionManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DistributionController extends Controller
{
    private DistributionManager $distributionManager;

    public function __construct(DistributionManager $distributionManager)
    {
        $this->distributionManager = $distributionManager;
    }

    private function isAdminAuthenticated(): bool
    {
        return isset($_SESSION['admin_username']);
    }

    /**
     * End distribution and compress files
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function endDistribution(Request $request): JsonResponse
    {
        if (!$this->isAdminAuthenticated()) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $request->validate([
            'distribution_id' => 'required|integer',
            'compress_now' => 'sometimes|boolean'
        ]);

        $adminId = auth('admin')->user()?->id;
        $compressNow = $request->boolean('compress_now', true);

        $result = $this->distributionManager->endDistribution(
            $request->input('distribution_id'),
            $adminId,
            $compressNow
        );

        return response()->json($result);
    }

    /**
     * Get distribution statistics
     * Can return single distribution or all distributions
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getDistributionStats(Request $request): JsonResponse
    {
        if (!$this->isAdminAuthenticated()) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        try {
            $distributionId = $request->query('distribution_id');

            $result = $this->distributionManager->getDistributionStats(
                $distributionId ? (int) $distributionId : null
            );

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to get statistics',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
