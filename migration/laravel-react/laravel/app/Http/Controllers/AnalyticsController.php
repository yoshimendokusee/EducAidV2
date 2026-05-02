<?php

namespace App\Http\Controllers;

use App\Services\AnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * AnalyticsController
 * Provides analytics and metrics endpoints for admin dashboard
 * All endpoints require admin authentication
 */
class AnalyticsController extends Controller
{
    private AnalyticsService $analyticsService;

    public function __construct(AnalyticsService $analyticsService)
    {
        $this->analyticsService = $analyticsService;
    }

    /**
     * Check if user is admin
     *
     * @return bool
     */
    private function isAdmin(): bool
    {
        return !empty($_SESSION['admin_username']);
    }

    /**
     * Get system metrics
     * GET /api/analytics/system-metrics
     *
     * @return JsonResponse
     */
    public function getSystemMetrics(): JsonResponse
    {
        if (!$this->isAdmin()) {
            return response()->json(['ok' => false, 'message' => 'Unauthorized'], 403);
        }

        try {
            $metrics = $this->analyticsService->getSystemMetrics();

            return response()->json([
                'ok' => true,
                'data' => $metrics,
                'timestamp' => now(),
            ]);
        } catch (Exception $e) {
            Log::error('AnalyticsController::getSystemMetrics - Error', ['error' => $e->getMessage()]);
            return response()->json([
                'ok' => false,
                'message' => 'Failed to retrieve system metrics',
            ], 500);
        }
    }

    /**
     * Get application distribution
     * GET /api/analytics/applications
     *
     * @return JsonResponse
     */
    public function getApplications(): JsonResponse
    {
        if (!$this->isAdmin()) {
            return response()->json(['ok' => false, 'message' => 'Unauthorized'], 403);
        }

        try {
            $data = $this->analyticsService->getApplicationDistribution();

            return response()->json([
                'ok' => true,
                'data' => $data,
                'timestamp' => now(),
            ]);
        } catch (Exception $e) {
            Log::error('AnalyticsController::getApplications - Error', ['error' => $e->getMessage()]);
            return response()->json([
                'ok' => false,
                'message' => 'Failed to retrieve application distribution',
            ], 500);
        }
    }

    /**
     * Get document statistics
     * GET /api/analytics/documents
     *
     * @return JsonResponse
     */
    public function getDocuments(): JsonResponse
    {
        if (!$this->isAdmin()) {
            return response()->json(['ok' => false, 'message' => 'Unauthorized'], 403);
        }

        try {
            $data = $this->analyticsService->getDocumentStatus();

            return response()->json([
                'ok' => true,
                'data' => $data,
                'timestamp' => now(),
            ]);
        } catch (Exception $e) {
            Log::error('AnalyticsController::getDocuments - Error', ['error' => $e->getMessage()]);
            return response()->json([
                'ok' => false,
                'message' => 'Failed to retrieve document statistics',
            ], 500);
        }
    }

    /**
     * Get distribution statistics
     * GET /api/analytics/distributions
     *
     * @return JsonResponse
     */
    public function getDistributions(): JsonResponse
    {
        if (!$this->isAdmin()) {
            return response()->json(['ok' => false, 'message' => 'Unauthorized'], 403);
        }

        try {
            $data = $this->analyticsService->getDistributionStats();

            return response()->json([
                'ok' => true,
                'data' => $data,
                'timestamp' => now(),
            ]);
        } catch (Exception $e) {
            Log::error('AnalyticsController::getDistributions - Error', ['error' => $e->getMessage()]);
            return response()->json([
                'ok' => false,
                'message' => 'Failed to retrieve distribution statistics',
            ], 500);
        }
    }

    /**
     * Get top municipalities
     * GET /api/analytics/municipalities
     *
     * @return JsonResponse
     */
    public function getMunicipalities(): JsonResponse
    {
        if (!$this->isAdmin()) {
            return response()->json(['ok' => false, 'message' => 'Unauthorized'], 403);
        }

        try {
            $data = $this->analyticsService->getTopMunicipalities(10);

            return response()->json([
                'ok' => true,
                'data' => $data,
                'timestamp' => now(),
            ]);
        } catch (Exception $e) {
            Log::error('AnalyticsController::getMunicipalities - Error', ['error' => $e->getMessage()]);
            return response()->json([
                'ok' => false,
                'message' => 'Failed to retrieve municipality data',
            ], 500);
        }
    }

    /**
     * Get performance metrics
     * GET /api/analytics/performance
     *
     * @return JsonResponse
     */
    public function getPerformance(): JsonResponse
    {
        if (!$this->isAdmin()) {
            return response()->json(['ok' => false, 'message' => 'Unauthorized'], 403);
        }

        try {
            $data = $this->analyticsService->getPerformanceMetrics();

            return response()->json([
                'ok' => true,
                'data' => $data,
                'timestamp' => now(),
            ]);
        } catch (Exception $e) {
            Log::error('AnalyticsController::getPerformance - Error', ['error' => $e->getMessage()]);
            return response()->json([
                'ok' => false,
                'message' => 'Failed to retrieve performance metrics',
            ], 500);
        }
    }

    /**
     * Get activity summary
     * GET /api/analytics/activity
     *
     * @return JsonResponse
     */
    public function getActivity(): JsonResponse
    {
        if (!$this->isAdmin()) {
            return response()->json(['ok' => false, 'message' => 'Unauthorized'], 403);
        }

        try {
            $data = $this->analyticsService->getActivitySummary();

            return response()->json([
                'ok' => true,
                'data' => $data,
                'timestamp' => now(),
            ]);
        } catch (Exception $e) {
            Log::error('AnalyticsController::getActivity - Error', ['error' => $e->getMessage()]);
            return response()->json([
                'ok' => false,
                'message' => 'Failed to retrieve activity summary',
            ], 500);
        }
    }

    /**
     * Get time series data for charts
     * GET /api/analytics/timeseries?metric=applications&days=30
     *
     * @return JsonResponse
     */
    public function getTimeSeries(): JsonResponse
    {
        if (!$this->isAdmin()) {
            return response()->json(['ok' => false, 'message' => 'Unauthorized'], 403);
        }

        try {
            $metric = request()->input('metric', 'applications');
            $days = max(1, min(365, (int) request()->input('days', 30)));

            $data = $this->analyticsService->getTimeSeriesData($metric, $days);

            return response()->json([
                'ok' => true,
                'data' => $data,
                'metric' => $metric,
                'days' => $days,
                'timestamp' => now(),
            ]);
        } catch (Exception $e) {
            Log::error('AnalyticsController::getTimeSeries - Error', ['error' => $e->getMessage()]);
            return response()->json([
                'ok' => false,
                'message' => 'Failed to retrieve time series data',
            ], 500);
        }
    }

    /**
     * Get complete dashboard data (all metrics)
     * GET /api/analytics/dashboard
     *
     * @return JsonResponse
     */
    public function getDashboard(): JsonResponse
    {
        if (!$this->isAdmin()) {
            return response()->json(['ok' => false, 'message' => 'Unauthorized'], 403);
        }

        try {
            $data = $this->analyticsService->getDashboardData();

            return response()->json([
                'ok' => true,
                'data' => $data,
                'timestamp' => now(),
            ]);
        } catch (Exception $e) {
            Log::error('AnalyticsController::getDashboard - Error', ['error' => $e->getMessage()]);
            return response()->json([
                'ok' => false,
                'message' => 'Failed to retrieve dashboard data',
            ], 500);
        }
    }
}
