<?php

namespace App\Http\Controllers;

use App\Services\AdminApplicantService;
use App\Services\CompatScriptRunner;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class AdminApplicantController extends Controller
{
    public function __construct(
        private readonly AdminApplicantService $service,
        private readonly CompatScriptRunner $runner
    ) {
    }

    private function isAdminAuthenticated(): bool
    {
        return isset($_SESSION['admin_username']);
    }

    // Old source: modules/admin/manage_applicants.php?api=badge_count
    public function badgeCount(): Response
    {
        if (!$this->isAdminAuthenticated()) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return response()->json(['count' => $this->service->getApplicantBadgeCount()]);
    }

    /**
     * Get applicants list with filters
     * Native implementation - returns real database data
     */
    public function details(Request $request): JsonResponse
    {
        if (!$this->isAdminAuthenticated()) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        try {
            $filters = [
                'status' => $request->query('status'),
                'search_term' => $request->query('search'),
            ];

            $applicants = $this->service->getApplicantsList($filters);
            $overview = $this->service->getApplicantsOverview();

            return response()->json([
                'success' => true,
                'applicants' => $applicants,
                'overview' => $overview,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to fetch applicants',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // Old source: modules/admin/manage_applicants.php POST action handling
    // Kept bridged because approve/reject/archive/bulk/migration flows are tightly coupled.
    public function actions(Request $request): Response
    {
        if (!$this->isAdminAuthenticated()) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return $this->runner->run($request, 'modules/admin/manage_applicants.php');
    }
}
