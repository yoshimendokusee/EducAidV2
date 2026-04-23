<?php

namespace App\Services;

class LegacyOcrBridge
{
    public function legacyServicePath(string $fileName): string
    {
        return rtrim(config('legacy.legacy_root'), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'services'
            . DIRECTORY_SEPARATOR . $fileName;
    }

    public function loadLegacyOcrServices(): void
    {
        $ocr = $this->legacyServicePath('OCRProcessingService.php');
        $enrollment = $this->legacyServicePath('EnrollmentFormOCRService.php');

        if (is_file($ocr)) {
            require_once $ocr;
        }

        if (is_file($enrollment)) {
            require_once $enrollment;
        }
    }
}
