<?php

namespace App\Services;

class CompatOcrBridge
{
    public function servicePath(string $fileName): string
    {
        return rtrim((string) config('compat.compat_root'), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'services'
            . DIRECTORY_SEPARATOR . $fileName;
    }

    public function loadOcrServices(): void
    {
        $ocr = $this->servicePath('OCRProcessingService.php');
        $enrollment = $this->servicePath('EnrollmentFormOCRService.php');

        if (is_file($ocr)) {
            require_once $ocr;
        }

        if (is_file($enrollment)) {
            require_once $enrollment;
        }
    }
}
