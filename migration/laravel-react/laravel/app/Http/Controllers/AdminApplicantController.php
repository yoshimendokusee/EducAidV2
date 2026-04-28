<?php

namespace App\Http\Controllers;

use App\Services\AdminApplicantService;
use App\Services\CompatScriptRunner;
use Illuminate\Http\Request;
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

    // Old source: modules/admin/get_applicant_details.php
    // Kept bridged to preserve file-path/document-resolution behavior exactly.
    public function details(Request $request): Response
    {
        if (!$this->isAdminAuthenticated()) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return $this->runner->run($request, 'modules/admin/get_applicant_details.php');
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
