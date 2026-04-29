<?php

namespace App\Services;

use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EligibilityCheckService
{
    private OcrProcessingService $ocrService;

    public function __construct(OcrProcessingService $ocrService)
    {
        $this->ocrService = $ocrService;
    }

    private function loadLegacyServices(): void
    {
        $legacyRoot = rtrim((string) config('compat.compat_root'), DIRECTORY_SEPARATOR);
        require_once $legacyRoot . DIRECTORY_SEPARATOR . 'services' . DIRECTORY_SEPARATOR . 'GradeValidationService.php';
    }

    private function buildGradeValidator(): object
    {
        $pdo = DB::connection()->getPdo();
        return new \GradeValidationService($pdo);
    }

    public function validateDirect(array $input): array
    {
        $this->loadLegacyServices();

        $universityKey = (string) ($input['universityKey'] ?? '');
        $subjects = $input['subjects'] ?? [];

        if ($universityKey === '') {
            throw new \InvalidArgumentException('University key is required');
        }
        if (!is_array($subjects) || empty($subjects)) {
            throw new \InvalidArgumentException('Subjects array is required');
        }

        $validator = $this->buildGradeValidator();
        $validation = $validator->validateApplicant($universityKey, $subjects);

        return [
            'success' => true,
            'eligible' => $validation['eligible'],
            'failedSubjects' => $validation['failedSubjects'],
            'totalSubjects' => $validation['totalSubjects'],
            'passedSubjects' => $validation['passedSubjects'],
            'universityKey' => $universityKey,
        ];
    }

    public function validateUploaded(UploadedFile $file, string $universityKey): array
    {
        $this->loadLegacyServices();

        if ($universityKey === '') {
            throw new \InvalidArgumentException('University key is required');
        }

        // Store file temporarily for OCR processing
        $tempDir = storage_path('app/ocr-temp');
        if (!is_dir($tempDir)) {
            @mkdir($tempDir, 0755, true);
        }

        $tempFilePath = $tempDir . DIRECTORY_SEPARATOR . uniqid('eligibility_', true) . '_' . $file->getClientOriginalName();
        $file->move($tempDir, basename($tempFilePath));

        try {
            // Use the new Laravel OCR service
            $ocr = $this->ocrService->processGradeDocument($tempFilePath);
            
            if (empty($ocr['success'])) {
                throw new \RuntimeException('OCR processing failed: ' . ($ocr['error'] ?? 'Unknown error'));
            }

            $validator = $this->buildGradeValidator();
            $validation = $validator->validateApplicant($universityKey, $ocr['subjects']);

            return [
                'success' => true,
                'eligible' => $validation['eligible'],
                'failedSubjects' => $validation['failedSubjects'],
                'totalSubjects' => $validation['totalSubjects'],
                'passedSubjects' => $validation['passedSubjects'],
                'ocrExtractedSubjects' => $ocr['subjects'],
                'universityKey' => $universityKey,
            ];
        } catch (Exception $e) {
            Log::error('EligibilityCheckService::validateUploaded failed', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            if (is_file($tempFilePath)) {
                @unlink($tempFilePath);
            }
        }
    }
}
