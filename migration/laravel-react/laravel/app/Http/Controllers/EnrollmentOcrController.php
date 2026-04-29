<?php

namespace App\Http\Controllers;

use App\Services\EnrollmentFormOCRService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EnrollmentOcrController extends Controller
{
    private EnrollmentFormOCRService $ocrService;

    public function __construct(EnrollmentFormOCRService $ocrService)
    {
        $this->ocrService = $ocrService;
    }

    /**
     * Extract form data from enrollment form
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function extractFormData(Request $request): JsonResponse
    {
        $request->validate([
            'file_path' => 'required|string'
        ]);

        $result = $this->ocrService->extractFormData($request->input('file_path'));

        return response()->json($result);
    }

    /**
     * Validate extracted form data
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function validateFormData(Request $request): JsonResponse
    {
        $request->validate([
            'form_data' => 'required|array'
        ]);

        $validation = $this->ocrService->validateFormData($request->input('form_data'));

        return response()->json([
            'success' => true,
            'validation' => $validation
        ]);
    }
}
