<?php
$endpoints = [
    ['method' => 'POST', 'url' => 'http://127.0.0.1:8000/api/reports/generate', 'body' => json_encode(['report_type' => 'overview'])],
    ['method' => 'POST', 'url' => 'http://127.0.0.1:8000/api/reports/export-csv', 'body' => json_encode([])],
    ['method' => 'GET', 'url' => 'http://127.0.0.1:8000/api/reports/status/fake-id', 'body' => null],
];

foreach ($endpoints as $e) {
    echo "=== {$e['url']} ===\n";
    $options = [
        'http' => [
            'method' => $e['method'],
            'header' => "Content-Type: application/json\r\n",
            'content' => $e['body'] ?? ''
        ],
    ];

    $ctx = stream_context_create($options);
    $res = @file_get_contents($e['url'], false, $ctx);

    if (isset($http_response_header)) {
        echo implode("\n", $http_response_header) . "\n";
    }

    echo "Body:\n";
    if ($res === false) {
        echo "(no body or request failed)\n";
    } else {
        echo $res . "\n";
    }

    echo "\n-----\n\n";
}
