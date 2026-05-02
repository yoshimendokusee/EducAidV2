<?php
$reportPath = __DIR__ . '/parity_report.json';
$data = json_decode(file_get_contents($reportPath), true);
$results = $data['results'] ?? [];

$counts = [
    'identical' => 0,
    'different' => 0,
    'no-route' => 0,
    'null' => 0,
    'error' => 0,
];

foreach ($results as $entry) {
    $diff = $entry['diff'] ?? null;
    if ($diff === null) {
        $counts['null']++;
    } elseif (array_key_exists($diff, $counts)) {
        $counts[$diff]++;
    } else {
        $counts[$diff] = ($counts[$diff] ?? 0) + 1;
    }

    if (!empty($entry['error'])) {
        $counts['error']++;
    }
}

echo json_encode([
    'processed' => $data['processed'] ?? null,
    'total' => count($results),
    'counts' => $counts,
], JSON_PRETTY_PRINT) . PHP_EOL;
