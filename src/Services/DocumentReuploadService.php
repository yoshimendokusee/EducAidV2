<?php

namespace App\Services;

require_once __DIR__ . '/ApiClient.php';

class DocumentReuploadService
{
    private ApiClient $client;

    public function __construct(string $apiBase = null)
    {
        $this->client = new ApiClient($apiBase);
    }

    public function reuploadDocument(string $studentId, string $documentTypeCode, string $filePath, ?int $adminId = null)
    {
        return $this->client->post('documents/reupload', [
            'student_id' => $studentId,
            'document_type_code' => $documentTypeCode,
            'file_path' => $filePath,
            'admin_id' => $adminId
        ]);
    }

    public function getReuploadStatus(string $studentId)
    {
        return $this->client->get('documents/reupload-status', ['student_id' => $studentId]);
    }

    public function completeReupload(string $studentId)
    {
        return $this->client->post('documents/complete-reupload', ['student_id' => $studentId]);
    }
}
