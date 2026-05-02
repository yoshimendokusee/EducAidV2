<?php
// Robust parity runner that isolates each compat page in a subprocess.
error_reporting(E_ALL);
ini_set('display_errors', '1');

$logFile = __DIR__ . '/parity_debug.log';
$reportFile = __DIR__ . '/parity_report.json';
$singleRunner = __DIR__ . '/run_parity_single.php';

file_put_contents($logFile, "run_parity_all.php start: " . date('c') . "\n", FILE_APPEND);

$map = json_decode(file_get_contents(__DIR__ . '/parity_map.json'), true);
$paths = $map['paths'] ?? [];

if (empty($paths)) {
    file_put_contents($logFile, "no paths found\n", FILE_APPEND);
    file_put_contents($reportFile, json_encode(['generated_at' => date('c'), 'results' => []], JSON_PRETTY_PRINT));
    exit(0);
}

$phpBin = PHP_BINARY ?: 'php';
$results = [];
$total = count($paths);

foreach ($paths as $idx => $path) {
    $n = $idx + 1;
    file_put_contents($logFile, "processing [$n/$total]: $path\n", FILE_APPEND);

    $cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($singleRunner) . ' ' . escapeshellarg($path) . ' 2>&1';
    $output = [];
    $code = 0;
    exec($cmd, $output, $code);
    $raw = trim(implode("\n", $output));

    if ($raw === '') {
        $entry = [
            'path' => $path,
            'legacy' => null,
            'laravel' => null,
            'diff' => null,
            'error' => 'subprocess-empty-output (exit=' . $code . ')',
        ];
        file_put_contents($logFile, "empty output [$n/$total] exit=$code\n", FILE_APPEND);
    } else {
        $pathJsonPos = strpos($raw, '{"path"');
        if ($pathJsonPos === false) {
            $pathJsonPos = strpos($raw, '{');
        }
        $candidate = $pathJsonPos === false ? $raw : substr($raw, $pathJsonPos);
        $decoded = json_decode($candidate, true);

        if (!is_array($decoded) || !isset($decoded['path'])) {
            $entry = [
                'path' => $path,
                'legacy' => null,
                'laravel' => null,
                'diff' => null,
                'error' => 'subprocess-invalid-json (exit=' . $code . '): ' . substr($raw, 0, 800),
            ];
            file_put_contents($logFile, "invalid json [$n/$total] exit=$code\n", FILE_APPEND);
        } else {
            $entry = $decoded;
            if ($code !== 0 && empty($entry['error'])) {
                $entry['error'] = 'subprocess-nonzero-exit=' . $code;
            }
            file_put_contents(
                $logFile,
                "done [$n/$total] diff=" . ($entry['diff'] ?? 'null') . ", exit=$code\n",
                FILE_APPEND
            );
        }
    }

    $results[] = $entry;

    // Persist progressively so partial results survive failures.
    file_put_contents(
        $reportFile,
        json_encode(['generated_at' => date('c'), 'processed' => $n, 'total' => $total, 'results' => $results], JSON_PRETTY_PRINT)
    );
}

file_put_contents($logFile, "completed: wrote parity_report.json (" . count($results) . " entries)\n", FILE_APPEND);
echo "DONE\n";
