<?php

namespace App\Services;

class ApiClient
{
    private string $baseUrl;

    public function __construct(string $baseUrl = null)
    {
        // Default to localhost Laravel dev server on port 8090
        $this->baseUrl = $baseUrl ?? 'http://127.0.0.1:8090/api';
    }

    public function post(string $path, array $data = []): array
    {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            return ['success' => false, 'error' => $err];
        }

        $decoded = json_decode($resp, true);
        return $decoded ?? ['success' => false, 'error' => 'Invalid JSON response', 'raw' => $resp];
    }

    public function get(string $path, array $query = []): array
    {
        $queryString = $query ? ('?' . http_build_query($query)) : '';
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/') . $queryString;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            return ['success' => false, 'error' => $err];
        }

        $decoded = json_decode($resp, true);
        return $decoded ?? ['success' => false, 'error' => 'Invalid JSON response', 'raw' => $resp];
    }
}
