<?php

namespace App\Services;

require_once __DIR__ . '/ApiClient.php';

class StudentArchivalService
{
    private ApiClient $client;

    public function __construct(string $apiBase = null)
    {
        $this->client = new ApiClient($apiBase);
    }

    public function compressArchivedStudents()
    {
        return $this->client->post('archival/compress-archived-students', []);
    }
}
