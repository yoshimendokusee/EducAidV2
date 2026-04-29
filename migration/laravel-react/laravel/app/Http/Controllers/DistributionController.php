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

    /**
     * End distribution and compress files
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function endDistribution(Request $request): JsonResponse
    {
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
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getDistributionStats(Request $request): JsonResponse
    {
        $request->validate([
            'distribution_id' => 'required|integer'
        ]);

        $result = $this->distributionManager->getDistributionStats(
            $request->input('distribution_id')
        );

        return response()->json($result);
    }
}
