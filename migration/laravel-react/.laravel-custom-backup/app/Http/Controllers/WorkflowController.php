<?php

namespace App\Http\Controllers;

use App\Services\WorkflowControlService;
use Illuminate\Http\JsonResponse;

class WorkflowController extends Controller
{
    public function __construct(private readonly WorkflowControlService $service)
    {
    }

    // Old source: includes/workflow_control.php::getWorkflowStatus
    public function status(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->service->getWorkflowStatus(),
        ]);
    }

    // Old source: includes/workflow_control.php::getStudentCounts
    public function studentCounts(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->service->getStudentCounts(),
        ]);
    }
}
