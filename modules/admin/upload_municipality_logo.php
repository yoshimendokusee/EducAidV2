<?php
/**
 * Municipality Logo Upload Handler
 * Handles custom logo uploads for municipalities
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/FilePathConfig.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/CSRFProtection.php';

$pathConfig = FilePathConfig::getInstance();

// Security checks
if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$adminRole = getCurrentAdminRole($connection);
if ($adminRole !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

header('Content-Type: application/json');

// Validate request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// CSRF validation
$token = $_POST['csrf_token'] ?? '';
if (!CSRFProtection::validateToken('municipality-logo-upload', $token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid security token']);
    exit;
}

$municipalityId = (int) ($_POST['municipality_id'] ?? 0);
if (!$municipalityId) {
    echo json_encode(['success' => false, 'message' => 'Municipality ID is required']);
    exit;
}

// Determine which logo type is being uploaded (custom = EducAid logo, preset = Municipality logo)
$logoType = $_POST['logo_type'] ?? 'custom';
if (!in_array($logoType, ['custom', 'preset'], true)) {
    $logoType = 'custom';
}

// Verify municipality exists and admin has access
$checkQuery = "SELECT municipality_id, name FROM municipalities WHERE municipality_id = $1";
$checkResult = pg_query_params($connection, $checkQuery, [$municipalityId]);
if (!$checkResult || pg_num_rows($checkResult) === 0) {
    echo json_encode(['success' => false, 'message' => 'Municipality not found']);
    exit;
}
$municipality = pg_fetch_assoc($checkResult);
pg_free_result($checkResult);

// Validate file upload
if (!isset($_FILES['logo_file']) || $_FILES['logo_file']['error'] === UPLOAD_ERR_NO_FILE) {
    echo json_encode(['success' => false, 'message' => 'No file uploaded']);
    exit;
}

$file = $_FILES['logo_file'];

// Check for upload errors
if ($file['error'] !== UPLOAD_ERR_OK) {
    $errorMessages = [
        UPLOAD_ERR_INI_SIZE => 'File exceeds maximum size allowed by server',
        UPLOAD_ERR_FORM_SIZE => 'File exceeds maximum size specified in form',
        UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
        UPLOAD_ERR_EXTENSION => 'Upload stopped by PHP extension'
    ];
    // Add server limits when it is a size error for clearer guidance
    $message = $errorMessages[$file['error']] ?? 'Unknown upload error';
    if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
        $uploadMax = ini_get('upload_max_filesize');
        $postMax = ini_get('post_max_size');
        $message .= sprintf(' (server upload_max_filesize=%s, post_max_size=%s). Please raise these limits or upload a smaller file.', $uploadMax ?: 'n/a', $postMax ?: 'n/a');
    }
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

// Validate file type
$allowedMimeTypes = ['image/png', 'image/jpeg', 'image/jpg', 'image/gif', 'image/webp', 'image/svg+xml'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!in_array($mimeType, $allowedMimeTypes, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid file type. Only images (PNG, JPG, GIF, WebP, SVG) are allowed']);
    exit;
}

// Validate file size considering both application and server limits
$configuredMax = 5 * 1024 * 1024; // 5MB
// Helper: convert shorthand php.ini sizes (e.g., 2M) to bytes
$toBytes = function($val) {
    $val = trim((string)$val);
    if ($val === '' || !preg_match('/^[0-9]+[KMG]?$/i', $val)) return null;
    $num = (int)$val;
    $unit = strtoupper(substr($val, -1));
    switch ($unit) {
        case 'G': return $num * 1024 * 1024 * 1024;
        case 'M': return $num * 1024 * 1024;
        case 'K': return $num * 1024;
        default: return (int)$val;
    }
};
$serverUploadMax = $toBytes(ini_get('upload_max_filesize')) ?: PHP_INT_MAX;
$serverPostMax   = $toBytes(ini_get('post_max_size')) ?: PHP_INT_MAX;
$effectiveMax    = min($configuredMax, $serverUploadMax, $serverPostMax);
if ($file['size'] > $effectiveMax) {
    $human = function($bytes){
        if ($bytes >= 1024*1024) return number_format($bytes/1024/1024, 2) . ' MB';
        if ($bytes >= 1024) return number_format($bytes/1024, 2) . ' KB';
        return $bytes . ' B';
    };
    echo json_encode([
        'success' => false,
        'message' => sprintf(
            'File too large. Max allowed is %s (server upload_max_filesize=%s, post_max_size=%s).',
            $human($effectiveMax), $human($serverUploadMax), $human($serverPostMax)
        )
    ]);
    exit;
}

// Validate image dimensions and create proper image resource
$imageInfo = getimagesize($file['tmp_name']);
if (!$imageInfo && $mimeType !== 'image/svg+xml') {
    echo json_encode(['success' => false, 'message' => 'Invalid image file']);
    exit;
}

// Create upload directory if it doesn't exist
$uploadDir = $pathConfig->getMunicipalLogosPath();
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true)) {
        echo json_encode(['success' => false, 'message' => 'Failed to create upload directory']);
        exit;
    }
}

// Generate safe filename
$extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (empty($extension)) {
    $extensionMap = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/svg+xml' => 'svg'
    ];
    $extension = $extensionMap[$mimeType] ?? 'png';
}

// Create safe filename: municipality_slug_logotype_timestamp.ext
$municipalitySlug = $municipality['slug'] ?? preg_replace('/[^a-z0-9]+/', '-', strtolower($municipality['name']));
$timestamp = time();
$filename = sprintf('%s_%s_%d.%s', $municipalitySlug, $logoType, $timestamp, $extension);
$filepath = $uploadDir . DIRECTORY_SEPARATOR . $filename;

// Move uploaded file
if (!move_uploaded_file($file['tmp_name'], $filepath)) {
    echo json_encode(['success' => false, 'message' => 'Failed to save uploaded file']);
    exit;
}

// Store normalized relative path in database (consistent on Railway and Localhost)
// Ensures value like: assets/uploads/municipality_logos/<file>
$dbPath = $pathConfig->getRelativePath($filepath);

// Update database - custom_logo_image for EducAid logo, preset_logo_image for Municipality logo
$columnToUpdate = ($logoType === 'custom') ? 'custom_logo_image' : 'preset_logo_image';
$updateQuery = "UPDATE municipalities 
                SET {$columnToUpdate} = $1, 
                    updated_at = NOW()
                WHERE municipality_id = $2";

$updateResult = pg_query_params($connection, $updateQuery, [$dbPath, $municipalityId]);

if (!$updateResult) {
    // Delete uploaded file if database update fails
    @unlink($filepath);
    echo json_encode(['success' => false, 'message' => 'Failed to update database: ' . pg_last_error($connection)]);
    exit;
}

// Success response
// Generate the URL for the uploaded logo (relative from modules/admin/)
$logoUrl = '../../' . str_replace('\\', '/', $dbPath);
$logoLabel = ($logoType === 'custom') ? 'EducAid' : 'Municipality';

echo json_encode([
    'success' => true,
    'message' => $logoLabel . ' logo uploaded successfully',
    'logo_url' => $logoUrl,
    'logo_type' => $logoType,
    'data' => [
        'municipality_id' => $municipalityId,
        'municipality_name' => $municipality['name'],
        'logo_path' => $dbPath,
        'filename' => $filename,
        'file_size' => $file['size'],
        'mime_type' => $mimeType
    ]
]);

// Log the upload
error_log(sprintf(
    'Municipality logo uploaded: Municipality #%d (%s), Type: %s (%s), File: %s, Size: %d bytes, Admin: #%d',
    $municipalityId,
    $municipality['name'],
    $logoType,
    $logoLabel,
    $filename,
    $file['size'],
    $_SESSION['admin_id']
));
?>
