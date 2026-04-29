<?php

namespace App\Services;

require_once __DIR__ . '/ApiClient.php';

class MediaEncryption
{
    private ApiClient $client;

    public function __construct(string $apiBase = null)
    {
        $this->client = new ApiClient($apiBase);
    }

    public function verify()
    {
        return $this->client->get('media-encryption/verify');
    }
}
