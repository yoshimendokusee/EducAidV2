<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class EligibilityCheckService
{
    private function loadLegacyServices(): void
    {
        $legacyRoot = rtrim((string) config('compat.compat_root'), DIRECTORY_SEPARATOR);
        require_once $legacyRoot . DIRECTORY_SEPARATOR . 'services' . DIRECTORY_SEPARATOR . 'GradeValidationService.php';
        require_once $legacyRoot . DIRECTORY_SEPARATOR . 'services' . DIRECTORY_SEPARATOR . 'OCRProcessingService.php';
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

        $legacyRoot = rtrim((string) config('compat.compat_root'), DIRECTORY_SEPARATOR);
        $tempDir = $legacyRoot . DIRECTORY_SEPARATOR . 'temp';
        if (!is_dir($tempDir)) {
            @mkdir($tempDir, 0755, true);
        }

        $tempFilePath = $tempDir . DIRECTORY_SEPARATOR . uniqid('eligibility_', true) . '_' . $file->getClientOriginalName();
        $file->move($tempDir, basename($tempFilePath));

        try {
            $ocrProcessor = new \OCRProcessingService([
                'tesseract_path' => 'tesseract',
                'temp_dir' => $tempDir,
                'max_file_size' => 10 * 1024 * 1024,
            ]);

            $ocr = $ocrProcessor->processGradeDocument($tempFilePath);
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
        } finally {
            if (is_file($tempFilePath)) {
                @unlink($tempFilePath);
            }
        }
    }
}
