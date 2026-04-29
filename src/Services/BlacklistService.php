<?php

namespace App\Services;

require_once __DIR__ . '/ApiClient.php';

class BlacklistService
{
    private ApiClient $client;

    public function __construct(string $apiBase = null)
    {
        $this->client = new ApiClient($apiBase);
    }

    public function blacklistStudent(string $studentId, string $reason, ?int $adminId = null)
    {
        return $this->client->post('blacklist/blacklist-student', ['student_id' => $studentId, 'reason' => $reason, 'admin_id' => $adminId]);
    }

    public function isBlacklisted(string $studentId)
    {
        return $this->client->get('blacklist/is-blacklisted', ['student_id' => $studentId]);
    }
}
