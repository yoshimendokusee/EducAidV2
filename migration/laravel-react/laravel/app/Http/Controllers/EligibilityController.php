<?php

namespace App\Http\Controllers;

use App\Services\EligibilityCheckService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EligibilityController extends Controller
{
    public function __construct(private readonly EligibilityCheckService $service)
    {
    }

    // Old source: api/eligibility/subject-check.php
    public function subjectCheck(Request $request): Response
    {
        if ($request->isMethod('options')) {
            return response('', 200, [
                'Access-Control-Allow-Origin' => '*',
                'Access-Control-Allow-Methods' => 'POST, OPTIONS',
                'Access-Control-Allow-Headers' => 'Content-Type',
            ]);
        }

        if (!$request->isMethod('post')) {
            return response()->json(['error' => 'Method not allowed'], 405);
        }

        try {
            if ($request->hasFile('gradeDocument')) {
                $result = $this->service->validateUploaded(
                    $request->file('gradeDocument'),
                    (string) $request->input('universityKey', '')
                );
            } else {
                $result = $this->service->validateDirect((array) $request->json()->all());
            }

            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }
}
