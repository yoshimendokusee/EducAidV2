<?php

namespace App\Services;

require_once __DIR__ . '/ApiClient.php';

class OCRProcessingService_Safe
{
    private ApiClient $client;

    public function __construct(string $apiBase = null)
    {
        $this->client = new ApiClient($apiBase);
    }

    public function processGradeDocument(string $filePath)
    {
        return $this->client->post('documents/process-grade-ocr', ['file_path' => $filePath]);
    }

    public function extractText(string $filePath)
    {
        return $this->client->post('enrollment-ocr/extract-data', ['file_path' => $filePath]);
    }
}
