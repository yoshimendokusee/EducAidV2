<?php

namespace App\Services;

require_once __DIR__ . '/ApiClient.php';

class EnrollmentFormOCRService
{
    private ApiClient $client;

    public function __construct(string $apiBase = null)
    {
        $this->client = new ApiClient($apiBase);
    }

    public function extractFormData(string $filePath)
    {
        return $this->client->post('enrollment-ocr/extract-data', ['file_path' => $filePath]);
    }

    public function validateFormData(array $formData)
    {
        return $this->client->post('enrollment-ocr/validate-data', ['form_data' => json_encode($formData)]);
    }
}
