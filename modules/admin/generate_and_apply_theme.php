<?php
/**
 * generate_and_apply_theme.php
 * AJAX endpoint to generate and apply theme colors from primary/secondary colors
 * Applies to sidebar, topbar, header, and footer themes (universal across all pages)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/CSRFProtection.php';
require_once __DIR__ . '/../../src/Services/ColorGeneratorService.php';
require_once __DIR__ . '/../../src/Services/ThemeGeneratorService.php';
require_once __DIR__ . '/../../src/Services/FooterThemeService.php';
require_once __DIR__ . '/../../src/Services/HeaderThemeService.php';

// Prevent any output before JSON
ob_start();

// Enable error logging
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display, just log
ini_set('log_errors', 1);
error_log("=== THEME GENERATOR START ===");

header('Content-Type: application/json');

// Authentication check
if (!isset($_SESSION['admin_id'])) {
    error_log("THEME GEN: Authentication failed - no admin_id in session");
    ob_end_clean();
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

error_log("THEME GEN: Authenticated - admin_id: " . $_SESSION['admin_id']);

// Check if super admin
$adminRole = getCurrentAdminRole($connection);
if ($adminRole !== 'super_admin') {
    error_log("THEME GEN: Access denied - role: $adminRole");
    ob_end_clean();
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied. Super admin only.']);
    exit;
}

error_log("THEME GEN: Role check passed - super_admin");

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Validate CSRF token (don't consume it so it can be reused if user clicks multiple times)
$token = $_POST['csrf_token'] ?? '';
if (!CSRFProtection::validateToken('generate-theme', $token, false)) {
    ob_end_clean();
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Security token invalid. Please refresh the page and try again.']);
    exit;
}

// Get municipality ID
$municipalityId = (int) ($_POST['municipality_id'] ?? 0);
error_log("THEME GEN: Municipality ID: $municipalityId");

// Get municipality colors from database
$query = "SELECT primary_color, secondary_color, name FROM municipalities WHERE municipality_id = $1";
$result = pg_query_params($connection, $query, [$municipalityId]);
$municipality = pg_fetch_assoc($result);

if (!$municipality) {
    error_log("THEME GEN: Municipality not found - ID: $municipalityId");
    ob_end_clean();
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Municipality not found']);
    exit;
}

error_log("THEME GEN: Municipality found - " . $municipality['name']);
error_log("THEME GEN: Primary color: " . $municipality['primary_color']);
error_log("THEME GEN: Secondary color: " . $municipality['secondary_color']);

// Generate and apply theme
try {
    error_log("THEME GEN: Starting theme generation...");
    
    // Check if required classes exist
    if (!class_exists('ThemeGeneratorService')) {
        throw new Exception('ThemeGeneratorService class not found');
    }
    if (!class_exists('FooterThemeService')) {
        throw new Exception('FooterThemeService class not found');
    }
    
    $generator = new \App\Services\ThemeGeneratorService();
    $result = $generator->generateAndApplyTheme(
        $municipalityId,
        $municipality['primary_color'],
        $municipality['secondary_color']
    );

    error_log("THEME GEN: Generation result - " . json_encode($result));

    if (!$result['success']) {
        error_log("THEME GEN: Generation failed - " . json_encode($result));
        ob_end_clean();
        http_response_code(400);
        echo json_encode($result);
        exit;
    }

    // Also generate and apply header theme colors
    error_log("THEME GEN: Starting header theme generation...");
    $headerService = new \App\Services\HeaderThemeService();
    
    // Generate header colors from primary/secondary
    $primaryLight = ColorGeneratorService::lighten($municipality['primary_color'], 0.95);
    $primaryHover = ColorGeneratorService::lighten($municipality['primary_color'], 0.85);
    $primaryDark = ColorGeneratorService::darken($municipality['primary_color'], 0.15);
    
    $headerColors = [
        'header_bg_color' => '#ffffff',
        'header_border_color' => ColorGeneratorService::lighten($municipality['primary_color'], 0.80),
        'header_text_color' => $municipality['primary_color'],
        'header_icon_color' => $municipality['primary_color'],
        'header_hover_bg' => $primaryLight,
        'header_hover_icon_color' => $primaryDark
    ];
    
    $headerResult = $headerService->save($headerColors, $_SESSION['admin_id']);
    error_log("THEME GEN: Header generation result - " . json_encode($headerResult));

    // Also generate and apply footer theme colors
    error_log("THEME GEN: Starting footer theme generation...");
    $footerService = new \App\Services\FooterThemeService();
    $footerColors = $footerService->generateFromTheme(
        $municipality['primary_color'],
        $municipality['secondary_color']
    );
    
    // Save footer colors
    $footerSaveData = array_merge(
        $footerColors,
        [
            'footer_title' => 'EducAid',
            'footer_description' => 'Making education accessible throughout General Trias City through innovative scholarship solutions.',
            'contact_address' => 'General Trias City Hall, Cavite',
            'contact_phone' => '+63 (046) 123-4567',
            'contact_email' => 'info@educaid-gentrias.gov.ph'
        ]
    );
    
    $footerResult = $footerService->save($footerSaveData, $_SESSION['admin_id'], $municipalityId);
    error_log("THEME GEN: Footer generation result - " . json_encode($footerResult));

    // Return success
    error_log("THEME GEN: Success! Colors applied: " . ($result['colors_applied'] ?? 'unknown'));
    ob_end_clean();
    echo json_encode([
        'success' => true,
        'message' => 'Theme generated and applied successfully!',
        'data' => [
            'municipality_name' => $municipality['name'],
            'sidebar_updated' => true,
            'topbar_updated' => true,
            'header_updated' => isset($headerResult['success']) ? $headerResult['success'] : true,
            'footer_updated' => isset($footerResult['success']) ? $footerResult['success'] : true,
            'colors_applied' => $result['colors_applied'] ?? 19
        ]
    ]);
    error_log("=== THEME GENERATOR END (SUCCESS) ===");
} catch (Exception $e) {
    error_log("THEME GEN: Exception caught - " . $e->getMessage());
    error_log("THEME GEN: Stack trace - " . $e->getTraceAsString());
    ob_end_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error generating theme: ' . $e->getMessage()
    ]);
    error_log("=== THEME GENERATOR END (ERROR) ===");
}
