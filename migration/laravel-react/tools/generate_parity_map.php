<?php
// Generates a parity map from AdminModulesController and StudentModulesController
// Usage: php tools/generate_parity_map.php > parity_map.json

$base = __DIR__ . '/../laravel/app/Http/Controllers/';
$files = [
    $base . 'AdminModulesController.php',
    $base . 'StudentModulesController.php'
];

$map = [];

foreach ($files as $file) {
    if (!is_file($file)) continue;
    $content = file_get_contents($file);
    // Find runner->run(...) and extract the second argument if it's a quoted PHP path
    $offset = 0;
    while (($pos = strpos($content, 'runner->run', $offset)) !== false) {
        $start = strpos($content, '(', $pos);
        if ($start === false) break;
        $end = strpos($content, ')', $start);
        if ($end === false) break;
        $inside = substr($content, $start + 1, $end - $start - 1);
        // split by comma, take second argument if exists
        $parts = array_map('trim', explode(',', $inside, 3));
        if (isset($parts[1])) {
            $arg = $parts[1];
            // strip quotes
            $arg = trim($arg);
            if ((str_starts_with($arg, "'") && str_ends_with($arg, "'")) || (str_starts_with($arg, '"') && str_ends_with($arg, '"'))) {
                $arg = substr($arg, 1, -1);
                if (str_ends_with($arg, '.php')) {
                    $map[] = $arg;
                }
            }
        }
        $offset = $end + 1;
    }
}

// Deduplicate and sort
$map = array_values(array_unique($map));

echo json_encode(['generated_at' => date('c'), 'paths' => $map], JSON_PRETTY_PRINT);
