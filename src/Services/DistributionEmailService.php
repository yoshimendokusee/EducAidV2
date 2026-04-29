<?php

namespace App\Services;

require_once __DIR__ . '/ApiClient.php';

class DistributionEmailService
{
    private ApiClient $client;

    public function __construct(string $apiBase = null)
    {
        $this->client = new ApiClient($apiBase);
    }

    public function notifyDistributionOpened($arg1, $arg2 = null, $arg3 = null)
    {
        // Support both signatures: (distributionId, deadline) OR (academic_year, semester, deadline)
        if (is_int($arg1) && $arg2 !== null) {
            return $this->client->post('distribution-email/notify-opened', ['distribution_id' => $arg1, 'deadline' => $arg2]);
        }

        // Treat as academic_year, semester, deadline
        return $this->client->post('distribution-email/notify-opened', ['academic_year' => $arg1, 'semester' => $arg2, 'deadline' => $arg3]);
    }

    public function notifyDistributionClosed(int $distributionId)
    {
        return $this->client->post('distribution-email/notify-closed', ['distribution_id' => $distributionId]);
    }
}
