<?php

namespace App\Services;

require_once __DIR__ . '/ApiClient.php';

class StudentEmailNotificationService
{
    private ApiClient $client;

    public function __construct(string $apiBase = null)
    {
        $this->client = new ApiClient($apiBase);
    }

    public function sendApprovalEmail(string $studentId)
    {
        return $this->client->post('student-email/send-approval', ['student_id' => $studentId]);
    }

    public function sendRejectionEmail(string $studentId, ?string $reason = null)
    {
        return $this->client->post('student-email/send-rejection', ['student_id' => $studentId, 'reason' => $reason]);
    }

    public function sendDistributionNotificationEmail(string $studentId, string $distributionName, ?float $amount = null)
    {
        return $this->client->post('student-email/send-distribution', ['student_id' => $studentId, 'distribution_name' => $distributionName, 'amount' => $amount]);
    }
}
