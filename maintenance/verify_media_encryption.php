<?php
// maintenance/verify_media_encryption.php
// Diagnostic script to verify media encryption (Version 1 & 2) for a student's profile picture.
// SECURITY: Restrict access. By default allow only localhost. Remove or strengthen this check in production.
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'])) {
    http_response_code(403);
    echo "Forbidden";
    exit;
}

header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Services/MediaEncryption.php';

use App\Services\MediaEncryption;

$studentId = isset($_GET['sid']) ? trim($_GET['sid']) : null;
if (!$studentId) {
    echo json_encode(['error' => 'Provide sid query parameter']);
    exit;
}

$conn = $connection ?? null; // database.php should expose $connection (pgsql)
if (!$conn) {
    echo json_encode(['error' => 'Database connection not available']);
    exit;
}
$res = pg_query_params($conn, 'SELECT student_picture FROM students WHERE student_id = $1', [$studentId]);
$row = pg_fetch_assoc($res);
if (!$row || empty($row['student_picture'])) {
    echo json_encode(['error' => 'No picture path for this student']);
    exit;
}

$relativePath = $row['student_picture'];
$filePath = realpath(__DIR__ . '/../' . $relativePath);
if (!$filePath || !file_exists($filePath)) {
    echo json_encode(['error' => 'Picture file missing on disk', 'expected_path' => $relativePath]);
    exit;
}

$info = [
    'student_id' => $studentId,
    'db_path' => $relativePath,
    'filesystem_path' => $filePath,
    'size_bytes' => filesize($filePath),
    'is_enc_extension' => str_ends_with($filePath, '.enc'),
];

$fh = fopen($filePath, 'rb');
$header = fread($fh, 64); // enough for both formats
fclose($fh);
$info['first_bytes_hex'] = bin2hex(substr($header,0,16));

if (substr($header,0,4) === 'MED1') {
    $version = ord($header[4]);
    $info['detected_magic'] = 'MED1';
    $info['detected_version'] = $version;
    if ($version === 2) {
        $info['key_id'] = ord($header[5]);
        $info['flags'] = ord($header[6]);
        $info['iv_length'] = ord($header[7]);
    } elseif ($version === 1) {
        $info['legacy_v1'] = true;
    } else {
        $info['unknown_version'] = true;
    }
} else {
    $info['detected_magic'] = 'PLAIN_OR_UNKNOWN';
}

$enc = new MediaEncryption();
try {
    $cipher = file_get_contents($filePath);
    $plain = $enc->decrypt($cipher);
    if (is_array($plain)) { $plain = $plain['data'] ?? ''; }
    // Basic mime guess (very light)
    $tmp = tmpfile();
    fwrite($tmp, is_string($plain)?$plain:'');
    $meta = stream_get_meta_data($tmp);
    $mime = null;
    if (function_exists('finfo_open')) {
        $f = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($f, $meta['uri']);
        finfo_close($f);
    }
    $info['decrypt_ok'] = true;
    $info['decrypted_size'] = is_string($plain)?strlen($plain):0;
    $info['decrypted_mime'] = $mime;
    fclose($tmp);
} catch (Throwable $e) {
    $info['decrypt_ok'] = false;
    $info['decrypt_error'] = $e->getMessage();
}

echo json_encode($info, JSON_PRETTY_PRINT);
