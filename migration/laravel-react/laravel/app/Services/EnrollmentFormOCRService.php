<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Exception;

class EnrollmentFormOCRService
{
    private OcrProcessingService $ocrService;

    public function __construct(OcrProcessingService $ocrService)
    {
        $this->ocrService = $ocrService;
    }

    /**
     * Extract data from enrollment form using OCR
     *
     * @param string $filePath
     * @return array ['success' => bool, 'data' => array, 'error' => string|null]
     */
    public function extractFormData(string $filePath): array
    {
        try {
            Log::info("EnrollmentFormOCRService::extractFormData - Processing form: $filePath");

            if (!file_exists($filePath)) {
                return [
                    'success' => false,
                    'data' => [],
                    'error' => 'File not found'
                ];
            }

            // Process document with OCR
            $ocrResult = $this->ocrService->processGradeDocument($filePath);

            if (!$ocrResult['success']) {
                return [
                    'success' => false,
                    'data' => [],
                    'error' => $ocrResult['error'] ?? 'OCR processing failed'
                ];
            }

            // Parse form data from OCR results
            $formData = $this->parseFormData($ocrResult);

            Log::info("EnrollmentFormOCRService::extractFormData - SUCCESS");

            return [
                'success' => true,
                'data' => $formData,
                'error' => null
            ];
        } catch (Exception $e) {
            Log::error("EnrollmentFormOCRService::extractFormData - Error: {$e->getMessage()}");

            return [
                'success' => false,
                'data' => [],
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Validate extracted form data
     *
     * @param array $formData
     * @return array ['valid' => bool, 'errors' => array]
     */
    public function validateFormData(array $formData): array
    {
        $errors = [];

        // Check required fields
        $requiredFields = ['name', 'student_id', 'email', 'phone'];

        foreach ($requiredFields as $field) {
            if (empty($formData[$field])) {
                $errors[] = "Missing required field: $field";
            }
        }

        // Validate email format
        if (!empty($formData['email']) && !filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Invalid email format";
        }

        // Validate phone format
        if (!empty($formData['phone']) && !preg_match('/^[0-9]{10,15}$/', preg_replace('/[^0-9]/', '', $formData['phone']))) {
            $errors[] = "Invalid phone number format";
        }

        return [
            'valid' => count($errors) === 0,
            'errors' => $errors
        ];
    }

    /**
     * Parse raw OCR text into structured form data
     *
     * @param array $ocrResult
     * @return array
     */
    private function parseFormData(array $ocrResult): array
    {
        $formData = [
            'name' => null,
            'student_id' => null,
            'email' => null,
            'phone' => null,
            'address' => null,
            'date_of_birth' => null,
            'civil_status' => null,
            'mother_name' => null,
            'father_name' => null,
            'guardian_name' => null,
            'grades' => []
        ];

        // Extract grades if available
        if (!empty($ocrResult['subjects'])) {
            $formData['grades'] = $ocrResult['subjects'];
        }

        // Basic extraction from OCR text (simplified)
        // In production, this would use regex patterns to extract specific fields
        if (!empty($ocrResult['text'])) {
            $text = $ocrResult['text'];

            // Try to extract email
            if (preg_match('/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/', $text, $matches)) {
                $formData['email'] = $matches[0];
            }

            // Try to extract phone
            if (preg_match('/(?:\+?63|0)[0-9]{9,10}/', $text, $matches)) {
                $formData['phone'] = $matches[0];
            }
        }

        return $formData;
    }
}
