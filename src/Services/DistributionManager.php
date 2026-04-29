<?php

namespace App\Services;

require_once __DIR__ . '/ApiClient.php';

class DistributionManager
{
    private ApiClient $client;

    public function __construct(string $apiBase = null)
    {
        $this->client = new ApiClient($apiBase);
    }

    public function endDistribution(int $distributionId, ?int $adminId = null, bool $compressNow = true)
    {
        return $this->client->post('distributions/end-distribution', [
            'distribution_id' => $distributionId,
            'compress_now' => $compressNow,
            'admin_id' => $adminId
        ]);
    }

    public function getDistributionStats(int $distributionId)
    {
        return $this->client->get('distributions/stats', ['distribution_id' => $distributionId]);
    }
}
