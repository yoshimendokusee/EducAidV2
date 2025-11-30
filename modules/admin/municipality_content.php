<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/CSRFProtection.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: ../../unified_login.php');
    exit;
}

$adminId = (int) $_SESSION['admin_id'];
$adminRole = getCurrentAdminRole($connection);
if ($adminRole !== 'super_admin') {
    header('Location: homepage.php?error=access_denied');
    exit;
}

function table_exists($connection, string $tableName): bool {
    $res = pg_query_params(
        $connection,
        "SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = $1 LIMIT 1",
        [$tableName]
    );
    $exists = $res && pg_fetch_row($res);
    if ($res) {
        pg_free_result($res);
    }
    return (bool) $exists;
}

function normalize_municipality(array $row): array {
    $row['municipality_id'] = (int) ($row['municipality_id'] ?? 0);
    $row['district_no'] = isset($row['district_no']) ? (int) $row['district_no'] : null;
    $row['primary_color'] = $row['primary_color'] ?: '#2e7d32';
    $row['secondary_color'] = $row['secondary_color'] ?: '#1b5e20';
    $row['preset_logo_image'] = isset($row['preset_logo_image']) ? trim((string) $row['preset_logo_image']) : null;
    $row['custom_logo_image'] = isset($row['custom_logo_image']) ? trim((string) $row['custom_logo_image']) : null;
    $useCustomLogo = in_array(strtolower((string) ($row['use_custom_logo'] ?? '')), ['t', 'true', '1'], true);
    $logo = null;
    if ($useCustomLogo && !empty($row['custom_logo_image'])) {
        $logo = $row['custom_logo_image'];
    } elseif (!empty($row['preset_logo_image'])) {
        $logo = $row['preset_logo_image'];
    }
    $row['active_logo'] = $logo ?: null;
    return $row;
}

function fetch_assigned_municipalities($connection, int $adminId): array {
    $baseSelect = "SELECT m.municipality_id, m.name, m.slug, m.lgu_type, m.district_no, m.preset_logo_image, m.custom_logo_image, m.use_custom_logo, m.primary_color, m.secondary_color FROM municipalities m";

    if (table_exists($connection, 'admin_municipality_access')) {
        $res = pg_query_params(
            $connection,
            $baseSelect . " INNER JOIN admin_municipality_access ama ON ama.municipality_id = m.municipality_id WHERE ama.admin_id = $1 ORDER BY m.name ASC",
            [$adminId]
        );
        $assigned = [];
        if ($res) {
            while ($row = pg_fetch_assoc($res)) {
                $assigned[] = normalize_municipality($row);
            }
            pg_free_result($res);
        }
        if (!empty($assigned)) {
            return $assigned;
        }
    }

    $res = pg_query_params(
        $connection,
        $baseSelect . ' INNER JOIN admins a ON a.municipality_id = m.municipality_id WHERE a.admin_id = $1 LIMIT 1',
        [$adminId]
    );
    $assigned = [];
    if ($res) {
        while ($row = pg_fetch_assoc($res)) {
            $assigned[] = normalize_municipality($row);
        }
        pg_free_result($res);
    }

    return $assigned;
}

function build_logo_src(?string $path): ?string {
    if ($path === null) {
        return null;
    }

    $path = trim((string) $path);
    if ($path === '') {
        return null;
    }

    // Handle base64 data URIs
    if (preg_match('#^data:image/[^;]+;base64,#i', $path)) {
        return $path;
    }

    // Handle external URLs
    if (preg_match('#^(?:https?:)?//#i', $path)) {
        return $path;
    }

    // Normalize path separators and collapse multiple slashes
    $normalizedRaw = str_replace('\\', '/', $path);
    $normalizedRaw = preg_replace('#(?<!:)/{2,}#', '/', $normalizedRaw);

    // URL encode the path while preserving forward slashes
    // This correctly handles spaces and special characters in folder/file names
    $encodedPath = implode('/', array_map('rawurlencode', explode('/', $normalizedRaw)));

    // Handle relative paths that are already correct
    if (str_starts_with($normalizedRaw, '../') || str_starts_with($normalizedRaw, './')) {
        return $encodedPath;
    }

    // Handle absolute paths from web root (starts with /)
    if (str_starts_with($normalizedRaw, '/')) {
        // From modules/admin/, need ../../ to reach project root
        return '../..' . $encodedPath;
    }

    // Handle relative paths without leading slash
    $relativeRaw = ltrim($normalizedRaw, '/');
    $relativeEncoded = ltrim($encodedPath, '/');

    // Try to auto-detect if path should be in assets/ directory
    $docRoot = realpath(__DIR__ . '/../../');
    if ($docRoot) {
        $fsRelative = str_replace('/', DIRECTORY_SEPARATOR, $relativeRaw);
        $candidate = $docRoot . DIRECTORY_SEPARATOR . $fsRelative;
        
        // If file doesn't exist at root, check if it's in assets/
        if (!is_file($candidate)) {
            $assetsCandidate = $docRoot . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . $fsRelative;
            if (is_file($assetsCandidate) && !str_starts_with($relativeRaw, 'assets/')) {
                // Rebuild the path with assets/ prefix
                $relativeRaw = 'assets/' . $relativeRaw;
                $relativeEncoded = implode('/', array_map('rawurlencode', explode('/', $relativeRaw)));
            }
        }
    }

    if ($relativeEncoded === '') {
        return null;
    }

    return '../../' . $relativeEncoded;
}

$assignedMunicipalities = fetch_assigned_municipalities($connection, $adminId);
$_SESSION['allowed_municipalities'] = array_column($assignedMunicipalities, 'municipality_id');

$csrfToken = CSRFProtection::generateToken('municipality-switch');
$feedback = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['select_municipality'])) {
    $token = $_POST['csrf_token'] ?? '';
    if (!CSRFProtection::validateToken('municipality-switch', $token)) {
        $feedback = ['type' => 'danger', 'message' => 'Security token expired. Please try again.'];
    } else {
        $requestedId = (int) ($_POST['municipality_id'] ?? 0);
        if ($requestedId && in_array($requestedId, $_SESSION['allowed_municipalities'], true)) {
            foreach ($assignedMunicipalities as $muni) {
                if ($muni['municipality_id'] === $requestedId) {
                    $_SESSION['active_municipality_id'] = $muni['municipality_id'];
                    $_SESSION['active_municipality_name'] = $muni['name'];
                    $_SESSION['active_municipality_slug'] = $muni['slug'];
                    break;
                }
            }
            header('Location: municipality_content.php?switched=1');
            exit;
        }
        $feedback = ['type' => 'warning', 'message' => 'Selected municipality is not assigned to your account.'];
    }
}

if (isset($_GET['switched'])) {
    $feedback = ['type' => 'success', 'message' => 'Active municipality updated.'];
}

// Handle color update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_colors'])) {
    $token = $_POST['csrf_token'] ?? '';
    if (!CSRFProtection::validateToken('municipality-colors', $token)) {
        $feedback = ['type' => 'danger', 'message' => 'Security token expired. Please try again.'];
    } else {
        $municipalityId = (int) ($_POST['municipality_id'] ?? 0);
        $primaryColor = trim($_POST['primary_color'] ?? '');
        $secondaryColor = trim($_POST['secondary_color'] ?? '');
        
        // Validate hex color format
        $hexPattern = '/^#[0-9A-Fa-f]{6}$/';
        if (!preg_match($hexPattern, $primaryColor)) {
            $feedback = ['type' => 'danger', 'message' => 'Invalid primary color format. Use hex format like #2e7d32'];
        } elseif (!preg_match($hexPattern, $secondaryColor)) {
            $feedback = ['type' => 'danger', 'message' => 'Invalid secondary color format. Use hex format like #1b5e20'];
        } elseif ($municipalityId && in_array($municipalityId, $_SESSION['allowed_municipalities'], true)) {
            $updateResult = pg_query_params(
                $connection,
                'UPDATE municipalities SET primary_color = $1, secondary_color = $2 WHERE municipality_id = $3',
                [$primaryColor, $secondaryColor, $municipalityId]
            );
            
            if ($updateResult) {
                $feedback = ['type' => 'success', 'message' => 'Colors updated successfully!'];
                // Refresh the page to show new colors
                header('Location: municipality_content.php?colors_updated=1');
                exit;
            } else {
                $feedback = ['type' => 'danger', 'message' => 'Failed to update colors. Please try again.'];
            }
        } else {
            $feedback = ['type' => 'warning', 'message' => 'You do not have permission to update this municipality.'];
        }
    }
}

if (isset($_GET['colors_updated'])) {
    $feedback = ['type' => 'success', 'message' => 'Municipality colors updated successfully!'];
}

if (isset($_GET['system_info_updated'])) {
    $feedback = ['type' => 'success', 'message' => 'System information updated successfully!'];
}

if (isset($_GET['contact_info_updated'])) {
    $feedback = ['type' => 'success', 'message' => 'Contact information updated successfully! This will be reflected across all pages (topbar, footer, etc.).'];
}

// Handle system information update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_system_info'])) {
    $token = $_POST['csrf_token'] ?? '';
    if (!CSRFProtection::validateToken('municipality-system-info', $token)) {
        $feedback = ['type' => 'danger', 'message' => 'Security token expired. Please try again.'];
    } else {
        $systemName = trim($_POST['system_name'] ?? '');
        $municipalityName = trim($_POST['municipality_name'] ?? '');
        
        if (empty($systemName)) {
            $feedback = ['type' => 'danger', 'message' => 'System name is required.'];
        } elseif (empty($municipalityName)) {
            $feedback = ['type' => 'danger', 'message' => 'Municipality name is required.'];
        } else {
            // Update in theme_settings table
            $updateRes = pg_query_params(
                $connection,
                'UPDATE theme_settings SET system_name = $1, municipality_name = $2, updated_at = CURRENT_TIMESTAMP, updated_by = $3 WHERE municipality_id = $4',
                [$systemName, $municipalityName, $adminId, 1]
            );
            
            if ($updateRes && pg_affected_rows($updateRes) > 0) {
                header("Location: municipality_content.php?system_info_updated=1");
                exit;
            } elseif ($updateRes && pg_affected_rows($updateRes) === 0) {
                // Try insert if no row exists
                $insertRes = pg_query_params(
                    $connection,
                    'INSERT INTO theme_settings (municipality_id, system_name, municipality_name, updated_by) VALUES ($1, $2, $3, $4)',
                    [1, $systemName, $municipalityName, $adminId]
                );
                if ($insertRes) {
                    header("Location: municipality_content.php?system_info_updated=1");
                    exit;
                } else {
                    $feedback = ['type' => 'danger', 'message' => 'Database error updating system information.'];
                }
            } else {
                $feedback = ['type' => 'danger', 'message' => 'Database error updating system information.'];
            }
        }
    }
}

// Handle contact information update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_contact_info'])) {
    $token = $_POST['csrf_token'] ?? '';
    if (!CSRFProtection::validateToken('municipality-contact-info', $token)) {
        $feedback = ['type' => 'danger', 'message' => 'Security token expired. Please try again.'];
    } else {
        $contactPhone = trim($_POST['contact_phone'] ?? '');
        $contactEmail = trim($_POST['contact_email'] ?? '');
        $contactAddress = trim($_POST['contact_address'] ?? '');
        $officeHours = trim($_POST['office_hours'] ?? '');
        $municipalityId = (int) ($_POST['municipality_id'] ?? 0);
        
        if (empty($contactPhone)) {
            $feedback = ['type' => 'danger', 'message' => 'Phone number is required.'];
        } elseif (empty($contactEmail)) {
            $feedback = ['type' => 'danger', 'message' => 'Email address is required.'];
        } elseif (!filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
            $feedback = ['type' => 'danger', 'message' => 'Please enter a valid email address.'];
        } elseif ($municipalityId <= 0) {
            $feedback = ['type' => 'danger', 'message' => 'Invalid municipality selected.'];
        } else {
            // Update contact info in municipalities table
            $updateRes = pg_query_params(
                $connection,
                'UPDATE municipalities SET contact_phone = $1, contact_email = $2, contact_address = $3, office_hours = $4, updated_at = CURRENT_TIMESTAMP WHERE municipality_id = $5',
                [$contactPhone, $contactEmail, $contactAddress, $officeHours, $municipalityId]
            );
            
            if ($updateRes) {
                header("Location: municipality_content.php?contact_info_updated=1");
                exit;
            } else {
                $feedback = ['type' => 'danger', 'message' => 'Database error updating contact information. Make sure the contact columns exist in the municipalities table.'];
            }
        }
    }
}

$activeMunicipalityId = $_SESSION['active_municipality_id'] ?? null;
if (!$activeMunicipalityId && !empty($assignedMunicipalities)) {
    $activeMunicipalityId = $assignedMunicipalities[0]['municipality_id'];
    $_SESSION['active_municipality_id'] = $activeMunicipalityId;
    $_SESSION['active_municipality_name'] = $assignedMunicipalities[0]['name'];
    $_SESSION['active_municipality_slug'] = $assignedMunicipalities[0]['slug'];
}

$activeMunicipality = null;
foreach ($assignedMunicipalities as $muni) {
    if ($muni['municipality_id'] === (int) $activeMunicipalityId) {
        $activeMunicipality = $muni;
        break;
    }
}

if (!$activeMunicipality && !empty($assignedMunicipalities)) {
    $activeMunicipality = $assignedMunicipalities[0];
}

$otherMunicipalities = array_filter(
    $assignedMunicipalities,
    fn($m) => $activeMunicipality && $m['municipality_id'] !== $activeMunicipality['municipality_id']
);

// Fetch system information from theme_settings
$systemInfoSettings = [];
$systemInfoQuery = pg_query_params($connection, "SELECT system_name, municipality_name FROM theme_settings WHERE municipality_id = $1 LIMIT 1", [1]);
if ($systemInfoQuery && ($systemInfoRow = pg_fetch_assoc($systemInfoQuery))) {
    $systemInfoSettings = $systemInfoRow;
} else {
    $systemInfoSettings = ['system_name' => 'EducAid', 'municipality_name' => 'City of General Trias'];
}

function content_block_count($connection, string $table, int $municipalityId): ?int {
    if (!table_exists($connection, $table)) {
        return null;
    }
    $identifier = pg_escape_identifier($connection, $table);
    $res = pg_query_params(
        $connection,
        "SELECT COUNT(*) AS total FROM {$identifier} WHERE municipality_id = $1",
        [$municipalityId]
    );
    if (!$res) {
        return null;
    }
    $row = pg_fetch_assoc($res) ?: [];
    pg_free_result($res);
    return isset($row['total']) ? (int) $row['total'] : 0;
}

// Fetch system information from theme_settings
$systemInfoSettings = [];
$systemInfoQuery = pg_query_params($connection, "SELECT system_name, municipality_name FROM theme_settings WHERE municipality_id = $1 LIMIT 1", [1]);
if ($systemInfoQuery && ($systemInfoRow = pg_fetch_assoc($systemInfoQuery))) {
    $systemInfoSettings = $systemInfoRow;
} else {
    $systemInfoSettings = ['system_name' => 'EducAid', 'municipality_name' => 'City of General Trias'];
}

// Fetch contact information from municipalities table
$contactInfoSettings = [
    'contact_phone' => '(046) 886-4454',
    'contact_email' => 'educaid@generaltrias.gov.ph',
    'contact_address' => 'General Trias City Hall, Cavite',
    'office_hours' => 'Mon–Fri 8:00AM - 5:00PM'
];

// Check if contact columns exist in municipalities table
$contactColumnsExist = false;
$checkContactColumns = pg_query($connection, "SELECT column_name FROM information_schema.columns WHERE table_name = 'municipalities' AND column_name = 'contact_phone' LIMIT 1");
if ($checkContactColumns && pg_num_rows($checkContactColumns) > 0) {
    $contactColumnsExist = true;
    if ($activeMunicipality) {
        $contactQuery = pg_query_params($connection, 
            "SELECT contact_phone, contact_email, contact_address, office_hours FROM municipalities WHERE municipality_id = $1", 
            [$activeMunicipality['municipality_id']]
        );
        if ($contactQuery && ($contactRow = pg_fetch_assoc($contactQuery))) {
            $contactInfoSettings['contact_phone'] = $contactRow['contact_phone'] ?? $contactInfoSettings['contact_phone'];
            $contactInfoSettings['contact_email'] = $contactRow['contact_email'] ?? $contactInfoSettings['contact_email'];
            $contactInfoSettings['contact_address'] = $contactRow['contact_address'] ?? $contactInfoSettings['contact_address'];
            $contactInfoSettings['office_hours'] = $contactRow['office_hours'] ?? $contactInfoSettings['office_hours'];
        }
    }
}

$quickActions = [];
if ($activeMunicipality) {
    $mid = $activeMunicipality['municipality_id'];
    $quickActions = [
        [
            'label' => 'Landing Page',
            'description' => 'Hero, highlights, testimonials and calls to action.',
            'icon' => 'bi-stars',
            'table' => 'landing_content_blocks',
            'editor_url' => sprintf('../../website/landingpage.php?edit=1&municipality_id=%d', $mid),
            'view_url' => sprintf('../../website/landingpage.php?municipality_id=%d', $mid)
        ],
        [
            'label' => 'Login Page Info',
            'description' => 'Welcome message, features, and trust indicators.',
            'icon' => 'bi-box-arrow-in-right',
            'table' => 'login_content_blocks',
            'editor_url' => '../../unified_login.php?edit=1',
            'view_url' => '../../unified_login.php'
        ],
        [
            'label' => 'How It Works',
            'description' => 'Step-by-step guidance and program workflow.',
            'icon' => 'bi-diagram-3',
            'table' => 'how_it_works_content_blocks',
            'editor_url' => sprintf('../../website/how-it-works.php?edit=1&municipality_id=%d', $mid),
            'view_url' => sprintf('../../website/how-it-works.php?municipality_id=%d', $mid)
        ],
        [
            'label' => 'Requirements Page',
            'description' => 'Eligibility, documentation and checklist copy.',
            'icon' => 'bi-card-checklist',
            'table' => 'requirements_content_blocks',
            'editor_url' => sprintf('../../website/requirements.php?edit=1&municipality_id=%d', $mid),
            'view_url' => sprintf('../../website/requirements.php?municipality_id=%d', $mid)
        ],
        [
            'label' => 'About Page',
            'description' => 'Mission, vision and program overview sections.',
            'icon' => 'bi-building',
            'table' => 'about_content_blocks',
            'editor_url' => sprintf('../../website/about.php?edit=1&municipality_id=%d', $mid),
            'view_url' => sprintf('../../website/about.php?municipality_id=%d', $mid)
        ],
        [
            'label' => 'Contact Page',
            'description' => 'Office directory, hotline and support details.',
            'icon' => 'bi-telephone',
            'table' => 'contact_content_blocks',
            'editor_url' => sprintf('../../website/contact.php?edit=1&municipality_id=%d', $mid),
            'view_url' => sprintf('../../website/contact.php?municipality_id=%d', $mid)
        ],
        [
            'label' => 'Announcements',
            'description' => 'Manage featured updates and news alerts.',
            'icon' => 'bi-megaphone',
            'table' => 'announcements_content_blocks',
            'editor_url' => 'manage_announcements.php',
            'view_url' => sprintf('../../website/announcements.php?municipality_id=%d', $mid)
        ],
    ];

    foreach ($quickActions as &$action) {
        $action['count'] = $action['table'] ? content_block_count($connection, $action['table'], $mid) : null;
    }
    unset($action);
}

$page_title = 'Municipality Content Hub';
$extra_css = ['../../assets/css/admin/municipality_hub.css'];
include __DIR__ . '/../../includes/admin/admin_head.php';
?>
<body class="municipality-hub-page">
<?php include __DIR__ . '/../../includes/admin/admin_topbar.php'; ?>
<div id="wrapper" class="admin-wrapper">
    <?php include __DIR__ . '/../../includes/admin/admin_sidebar.php'; ?>
    <?php include __DIR__ . '/../../includes/admin/admin_header.php'; ?>

    <section class="home-section" id="mainContent">
        <div class="container-fluid py-4 px-4">
            <!-- Page Header -->
            <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-4">
                <div>
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <h2 class="fw-bold mb-0" style="color: #1e293b;">Municipality Content Hub</h2>
                    </div>
                    <p class="text-muted mb-0" style="font-size: 0.95rem;">
                        Review assigned local government units and jump directly into their content editors.
                    </p>
                </div>
            </div>

            <?php if ($feedback): ?>
                <div class="alert alert-<?= htmlspecialchars($feedback['type']) ?> alert-dismissible fade show shadow-sm" role="alert" style="border-radius: 12px;">
                    <i class="bi bi-check-circle-fill me-2"></i>
                    <?= htmlspecialchars($feedback['message']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (!$activeMunicipality): ?>
                <div class="alert alert-warning">
                    No municipality assignments found for your account yet.
                </div>
            <?php else: ?>

            <div class="card muni-hero-card mb-4">
                <div class="card-body">
                    <div class="row g-4 align-items-center">
                        <div class="col-auto">
                            <div class="muni-logo-wrapper position-relative" id="logoContainer">
                                <?php $logo = build_logo_src($activeMunicipality['active_logo']); ?>
                                <?php if ($logo): ?>
                                    <img src="<?= htmlspecialchars($logo) ?>?t=<?= time() ?>" 
                                         alt="<?= htmlspecialchars($activeMunicipality['name']) ?> logo" 
                                         id="municipalityLogo" 
                                         loading="lazy"
                                         onerror="console.error('Logo load error:', this.src); this.onerror=null; this.parentElement.innerHTML='<span class=\'text-danger fw-semibold\'><i class=\'bi bi-exclamation-triangle me-1\'></i>Logo Error</span><small class=\'d-block text-muted mt-1\'>Path: <?= htmlspecialchars($activeMunicipality['active_logo']) ?></small>';">
                                <?php else: ?>
                                    <span class="text-muted fw-semibold"><i class="bi bi-image me-1"></i>No Logo</span>
                                <?php endif; ?>
                                <?php 
                                $hasPreset = !empty($activeMunicipality['preset_logo_image']);
                                $hasCustom = !empty($activeMunicipality['custom_logo_image']);
                                $usingCustom = in_array(strtolower((string) ($activeMunicipality['use_custom_logo'] ?? '')), ['t', 'true', '1'], true);
                                ?>
                                <?php if ($hasCustom): ?>
                                    <span class="position-absolute top-0 end-0 badge bg-primary" style="font-size: 0.7rem;">
                                        <?= $usingCustom ? 'Custom' : 'Preset' ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col">
                            <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                                <span class="badge bg-success-subtle text-success fw-semibold">
                                    <i class="bi bi-check-circle-fill me-1"></i>Assigned
                                </span>
                                <span class="badge badge-soft">
                                    <i class="bi bi-<?= $activeMunicipality['lgu_type'] === 'city' ? 'buildings' : 'house-door' ?> me-1"></i>
                                    <?= $activeMunicipality['lgu_type'] === 'city' ? 'City' : 'Municipality' ?>
                                    <?php if ($activeMunicipality['district_no']): ?>
                                        · District <?= $activeMunicipality['district_no'] ?>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <h3 class="fw-bold mb-2 overflow-wrap-anywhere" style="color: #1e293b;">
                                <?= htmlspecialchars($activeMunicipality['name']) ?>
                            </h3>
                            <?php if (!empty($activeMunicipality['slug'])): ?>
                                <div class="text-muted small mb-3" style="font-family: 'Courier New', monospace;">
                                    <i class="bi bi-link-45deg me-1"></i>
                                    <strong>Slug:</strong> <?= htmlspecialchars($activeMunicipality['slug']) ?>
                                </div>
                            <?php endif; ?>
                            <div class="d-flex align-items-center gap-4 flex-wrap">
                                <div class="d-flex align-items-center gap-2">
                                    <div class="color-chip" style="background: <?= htmlspecialchars($activeMunicipality['primary_color']) ?>;"></div>
                                    <div>
                                        <div class="text-uppercase text-muted small" style="font-size: 0.75rem; letter-spacing: 0.5px;">Primary</div>
                                        <div class="fw-bold" style="font-family: 'Courier New', monospace; font-size: 0.9rem;">
                                            <?= htmlspecialchars($activeMunicipality['primary_color']) ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="color-chip" style="background: <?= htmlspecialchars($activeMunicipality['secondary_color']) ?>;"></div>
                                    <div>
                                        <div class="text-uppercase text-muted small" style="font-size: 0.75rem; letter-spacing: 0.5px;">Secondary</div>
                                        <div class="fw-bold" style="font-family: 'Courier New', monospace; font-size: 0.9rem;">
                                            <?= htmlspecialchars($activeMunicipality['secondary_color']) ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="d-flex gap-2">
                                    <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#editColorsModal">
                                        <i class="bi bi-palette me-1"></i>Edit Colors
                                    </button>
                                    <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#generateThemeModal">
                                        <i class="bi bi-magic me-1"></i>Generate Theme
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="col-auto">
                            <div class="d-flex flex-column gap-2">
                                <button type="button" class="btn btn-primary btn-sm shadow-sm" data-bs-toggle="modal" data-bs-target="#uploadLogoModal">
                                    <i class="bi bi-upload me-1"></i>Upload Logo
                                </button>
                                <a href="topbar_settings.php" class="btn btn-outline-success btn-sm">
                                    <i class="bi bi-layout-text-window me-1"></i>Topbar
                                </a>
                                <a href="sidebar_settings.php" class="btn btn-outline-primary btn-sm">
                                    <i class="bi bi-layout-sidebar me-1"></i>Sidebar
                                </a>
                                <a href="settings.php" class="btn btn-outline-secondary btn-sm">
                                    <i class="bi bi-sliders me-1"></i>Settings
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- System Information Section -->
            <div class="card mb-4 shadow-sm">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0">
                        <i class="bi bi-building-fill me-2 text-primary"></i>System Information
                    </h5>
                </div>
                <div class="card-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">System Name</label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($systemInfoSettings['system_name']) ?>" readonly>
                            <div class="form-text">
                                <i class="bi bi-info-circle"></i> Name of the system/application. 
                                <strong>Displayed in the website navigation bar as the brand name.</strong>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Municipality Name</label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($systemInfoSettings['municipality_name']) ?>" readonly>
                            <div class="form-text">
                                <i class="bi bi-info-circle"></i> Name of the municipality or local government unit. 
                                <strong>Displayed in the website navigation bar after the system name (format: System Name • Municipality Name).</strong>
                            </div>
                        </div>
                        <div class="col-12">
                            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#editSystemInfoModal">
                                <i class="bi bi-pencil-square me-1"></i>Edit System Information
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Contact Information Section -->
            <div class="card mb-4 shadow-sm">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0">
                        <i class="bi bi-telephone-fill me-2 text-info"></i>Contact Information
                        <span class="badge bg-info-subtle text-info ms-2" style="font-size: 0.7rem;">
                            <i class="bi bi-globe me-1"></i>Used across all pages
                        </span>
                    </h5>
                </div>
                <div class="card-body p-4">
                    <?php if (!$contactColumnsExist): ?>
                    <div class="alert alert-warning mb-3">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <strong>Setup Required:</strong> Contact columns not found in database. 
                        <a href="../../add_municipality_contact_fields.php" target="_blank" class="alert-link">Run migration script</a> to enable this feature.
                    </div>
                    <?php endif; ?>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold"><i class="bi bi-telephone me-1"></i>Phone Number</label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($contactInfoSettings['contact_phone']) ?>" readonly>
                            <div class="form-text">Displayed in topbar, footer, and contact pages.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold"><i class="bi bi-envelope me-1"></i>Email Address</label>
                            <input type="email" class="form-control" value="<?= htmlspecialchars($contactInfoSettings['contact_email']) ?>" readonly>
                            <div class="form-text">Displayed in topbar, footer, and contact pages.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold"><i class="bi bi-geo-alt me-1"></i>Address</label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($contactInfoSettings['contact_address']) ?>" readonly>
                            <div class="form-text">Physical office address shown in footer.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold"><i class="bi bi-clock me-1"></i>Office Hours</label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($contactInfoSettings['office_hours']) ?>" readonly>
                            <div class="form-text">Business hours shown in topbar.</div>
                        </div>
                        <div class="col-12">
                            <button type="button" class="btn btn-info btn-sm text-white" data-bs-toggle="modal" data-bs-target="#editContactInfoModal" <?= !$contactColumnsExist ? 'disabled' : '' ?>>
                                <i class="bi bi-pencil-square me-1"></i>Edit Contact Information
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-4 shadow-sm">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0">
                        <i class="bi bi-brush me-2 text-success"></i>Content Areas
                        <span class="badge bg-light text-dark ms-2" style="font-size: 0.75rem;">
                            <?= count($quickActions) ?> sections
                        </span>
                    </h5>
                </div>
                <div class="card-body p-4">
                    <div class="row g-4">
                        <?php foreach ($quickActions as $action): ?>
                            <div class="col-xl-4 col-lg-6">
                                <div class="card quick-action-card h-100 shadow-sm">
                                    <div class="card-body d-flex flex-column">
                                        <div class="d-flex align-items-start gap-3 mb-3">
                                            <div class="p-3 rounded-3 bg-success-subtle text-success">
                                                <i class="bi <?= htmlspecialchars($action['icon']) ?>"></i>
                                            </div>
                                            <div class="flex-grow-1">
                                                <h6 class="mb-1 fw-bold" style="color: #1e293b;">
                                                    <?= htmlspecialchars($action['label']) ?>
                                                </h6>
                                            </div>
                                        </div>
                                        <p class="text-muted mb-3" style="font-size: 0.9rem; line-height: 1.6;">
                                            <?= htmlspecialchars($action['description']) ?>
                                        </p>
                                        <div class="mt-auto">
                                            <div class="d-flex flex-wrap gap-2">
                                                <a href="#" 
                                                   class="btn btn-success btn-sm flex-grow-1 edit-content-trigger" 
                                                   data-editor-url="<?= htmlspecialchars($action['editor_url']) ?>"
                                                   data-label="<?= htmlspecialchars($action['label']) ?>">
                                                    <i class="bi bi-pencil-square me-1"></i>Edit Content
                                                </a>
                                                <a href="<?= htmlspecialchars($action['view_url']) ?>" target="_blank" class="btn btn-outline-secondary btn-sm">
                                                    <i class="bi bi-box-arrow-up-right"></i>
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($quickActions)): ?>
                            <div class="col-12 text-center text-muted py-4">
                                No content areas available for this municipality yet.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if (!empty($otherMunicipalities)): ?>
                <div class="card shadow-sm">
                    <div class="card-header bg-white py-3">
                        <h5 class="mb-0">
                            <i class="bi bi-geo-alt me-2 text-primary"></i>Other Assigned Municipalities
                            <span class="badge bg-light text-dark ms-2" style="font-size: 0.75rem;">
                                <?= count($otherMunicipalities) ?>
                            </span>
                        </h5>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-3">
                            <?php foreach ($otherMunicipalities as $muni): ?>
                                <div class="col-xl-4 col-lg-6">
                                    <div class="other-muni-card h-100 d-flex flex-column">
                                        <div class="d-flex align-items-center gap-3 mb-3">
                                            <div class="muni-logo-wrapper" style="width:72px;height:72px;">
                                                <?php $logo = build_logo_src($muni['active_logo']); ?>
                                                <?php if ($logo): ?>
                                                    <img src="<?= htmlspecialchars($logo) ?>" alt="<?= htmlspecialchars($muni['name']) ?> logo" style="max-width:56px;max-height:56px;">
                                                <?php else: ?>
                                                    <div class="text-muted text-center">
                                                        <i class="bi bi-image" style="font-size: 1.5rem;"></i>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="flex-grow-1">
                                                <div class="fw-bold overflow-wrap-anywhere mb-1" style="color: #1e293b;">
                                                    <?= htmlspecialchars($muni['name']) ?>
                                                </div>
                                                <?php if (!empty($muni['slug'])): ?>
                                                    <div class="text-muted small" style="font-family: 'Courier New', monospace; font-size: 0.8rem;">
                                                        <i class="bi bi-link-45deg"></i><?= htmlspecialchars($muni['slug']) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="mt-auto pt-3 border-top" style="border-color: rgba(226, 232, 240, 0.6) !important;">
                                            <div class="d-flex gap-2">
                                                <form method="post" class="flex-grow-1">
                                                    <input type="hidden" name="select_municipality" value="1">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                                    <input type="hidden" name="municipality_id" value="<?= $muni['municipality_id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-success w-100">
                                                        <i class="bi bi-arrow-repeat me-1"></i>Set Active
                                                    </button>
                                                </form>
                                                <a href="<?= htmlspecialchars(sprintf('../../website/landingpage.php?municipality_id=%d', $muni['municipality_id'])) ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="Preview">
                                                    <i class="bi bi-box-arrow-up-right"></i>
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php endif; ?>
        </div>
    </section>
</div>

<!-- Upload Logo Modal -->
<div class="modal fade" id="uploadLogoModal" tabindex="-1" aria-labelledby="uploadLogoModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="uploadLogoModalLabel">
                    <i class="bi bi-upload me-2"></i>Upload Custom Logo
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    Upload a custom logo for <strong><?= htmlspecialchars($activeMunicipality['name'] ?? 'this municipality') ?></strong>.
                    Recommended: PNG with transparent background, max 5MB.
                </div>
                
                <?php if ($hasPreset && $hasCustom): ?>
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    You have both preset and custom logos. Currently using: <strong><?= $usingCustom ? 'Custom' : 'Preset' ?></strong>
                    <button type="button" class="btn btn-sm btn-outline-primary ms-2" id="toggleLogoType">
                        <i class="bi bi-arrow-repeat me-1"></i>Switch to <?= $usingCustom ? 'Preset' : 'Custom' ?>
                    </button>
                </div>
                <?php endif; ?>
                
                <div id="uploadFeedback"></div>
                
                <form id="logoUploadForm" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRFProtection::generateToken('municipality-logo-upload')) ?>">
                    <input type="hidden" name="municipality_id" value="<?= $activeMunicipality['municipality_id'] ?? '' ?>">
                    
                    <div class="mb-3">
                        <label for="logoFile" class="form-label">Select Image File</label>
                        <input type="file" class="form-control" id="logoFile" name="logo_file" 
                               accept="image/png,image/jpeg,image/jpg,image/gif,image/webp,image/svg+xml" required>
                        <div class="form-text">Allowed: PNG, JPG, GIF, WebP, SVG. Max size: 5MB</div>
                    </div>
                    
                    <div id="previewContainer" class="mb-3" style="display: none;">
                        <label class="form-label">Preview</label>
                        <div class="border rounded p-3 text-center bg-light">
                            <img id="previewImage" src="" alt="Preview" style="max-width: 100%; max-height: 200px;">
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="uploadLogoBtn">
                    <i class="bi bi-upload me-1"></i>Upload Logo
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const logoFileInput = document.getElementById('logoFile');
    const previewContainer = document.getElementById('previewContainer');
    const previewImage = document.getElementById('previewImage');
    const uploadLogoBtn = document.getElementById('uploadLogoBtn');
    const uploadFeedback = document.getElementById('uploadFeedback');
    const logoUploadForm = document.getElementById('logoUploadForm');
    const toggleLogoTypeBtn = document.getElementById('toggleLogoType');
    
    // Debug: Log current logo info
    const logoImg = document.getElementById('municipalityLogo');
    if (logoImg) {
        console.log('Current logo src:', logoImg.src);
        console.log('Logo load status:', logoImg.complete ? 'loaded' : 'loading');
    }
    
    // Preview selected image
    logoFileInput?.addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            console.log('File selected:', file.name, 'Size:', file.size, 'Type:', file.type);
            
            // Validate file size
            if (file.size > 5 * 1024 * 1024) {
                showFeedback('danger', 'File size exceeds 5MB limit');
                logoFileInput.value = '';
                previewContainer.style.display = 'none';
                return;
            }
            
            // Show preview
            const reader = new FileReader();
            reader.onload = function(e) {
                previewImage.src = e.target.result;
                previewContainer.style.display = 'block';
                console.log('Preview loaded');
            };
            reader.readAsDataURL(file);
        } else {
            previewContainer.style.display = 'none';
        }
    });
    
    // Handle logo type toggle
    toggleLogoTypeBtn?.addEventListener('click', async function() {
        const currentlyUsingCustom = <?= json_encode($usingCustom ?? false) ?>;
        const municipalityId = <?= json_encode($activeMunicipality['municipality_id'] ?? 0) ?>;
        const csrfToken = '<?= htmlspecialchars(CSRFProtection::generateToken('municipality-logo-toggle')) ?>';
        
        toggleLogoTypeBtn.disabled = true;
        const originalHtml = toggleLogoTypeBtn.innerHTML;
        toggleLogoTypeBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
        
        try {
            const formData = new FormData();
            formData.append('municipality_id', municipalityId);
            formData.append('use_custom', currentlyUsingCustom ? 'false' : 'true');
            formData.append('csrf_token', csrfToken);
            
            const response = await fetch('toggle_municipality_logo.php', {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success) {
                window.location.reload();
            } else {
                alert(result.message || 'Failed to toggle logo type');
                toggleLogoTypeBtn.disabled = false;
                toggleLogoTypeBtn.innerHTML = originalHtml;
            }
        } catch (error) {
            alert('Network error: ' + error.message);
            toggleLogoTypeBtn.disabled = false;
            toggleLogoTypeBtn.innerHTML = originalHtml;
        }
    });
    
    // Handle upload
    uploadLogoBtn?.addEventListener('click', async function() {
        const formData = new FormData(logoUploadForm);
        
        if (!logoFileInput.files[0]) {
            showFeedback('warning', 'Please select a file to upload');
            return;
        }
        
        uploadLogoBtn.disabled = true;
        uploadLogoBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Uploading...';
        uploadFeedback.innerHTML = '';
        
        try {
            const response = await fetch('upload_municipality_logo.php', {
                method: 'POST',
                body: formData
            });
            
            // Check if response is ok
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                const text = await response.text();
                console.error('Non-JSON response:', text);
                throw new Error('Server returned non-JSON response');
            }
            
            const result = await response.json();
            console.log('Upload result:', result);
            
            if (result.success) {
                showFeedback('success', result.message || 'Logo uploaded successfully!');
                
                // Update the logo image immediately if present
                const logoImg = document.getElementById('municipalityLogo');
                if (logoImg && result.logo_url) {
                    // Add cache-busting parameter to force reload
                    logoImg.src = result.logo_url + '?t=' + new Date().getTime();
                }
                
                setTimeout(() => {
                    // Force hard reload to clear any cached assets
                    window.location.reload(true);
                }, 1500);
            } else {
                showFeedback('danger', result.message || 'Upload failed');
                uploadLogoBtn.disabled = false;
                uploadLogoBtn.innerHTML = '<i class="bi bi-upload me-1"></i>Upload Logo';
            }
        } catch (error) {
            showFeedback('danger', 'Network error: ' + error.message);
            uploadLogoBtn.disabled = false;
            uploadLogoBtn.innerHTML = '<i class="bi bi-upload me-1"></i>Upload Logo';
        }
    });
    
    function showFeedback(type, message) {
        uploadFeedback.innerHTML = `
            <div class="alert alert-${type} alert-dismissible fade show" role="alert">
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        `;
    }
    
    // Reset form when modal is closed
    document.getElementById('uploadLogoModal')?.addEventListener('hidden.bs.modal', function() {
        logoUploadForm?.reset();
        previewContainer.style.display = 'none';
        uploadFeedback.innerHTML = '';
        uploadLogoBtn.disabled = false;
        uploadLogoBtn.innerHTML = '<i class="bi bi-upload me-1"></i>Upload Logo';
    });

    // Content Editor Warning Modal
    const editContentModal = new bootstrap.Modal(document.getElementById('editContentWarningModal'));
    let currentEditorUrl = '';
    let currentPageLabel = '';

    document.querySelectorAll('.edit-content-trigger').forEach(trigger => {
        trigger.addEventListener('click', function(e) {
            e.preventDefault();
            currentEditorUrl = this.getAttribute('data-editor-url');
            currentPageLabel = this.getAttribute('data-label');
            
            // Update modal content
            document.getElementById('editContentPageName').textContent = currentPageLabel;
            
            // Show modal
            editContentModal.show();
        });
    });

    // Confirm edit button
    document.getElementById('confirmEditContentBtn').addEventListener('click', function() {
        if (currentEditorUrl) {
            window.location.href = currentEditorUrl;
        }
    });
});
</script>

<!-- Edit Content Warning Modal -->
<div class="modal fade" id="editContentWarningModal" tabindex="-1" aria-labelledby="editContentWarningModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title" id="editContentWarningModalLabel">
          <i class="bi bi-exclamation-triangle text-warning me-2"></i>
          Proceed to <span id="editContentPageName" class="fw-bold">Content</span> Editor?
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body pt-1">
        <p class="mb-2">You are about to enter the <strong>live content editor</strong> for this page.</p>
        <ul class="small ps-3 mb-3">
          <li>Changes you save will <strong>immediately affect</strong> what visitors see.</li>
          <li>Please review text for <strong>accuracy and professionalism</strong>.</li>
          <li>Avoid adding <strong>sensitive or internal-only information</strong>.</li>
          <li>Be mindful of <strong>formatting, grammar, and spelling</strong>.</li>
        </ul>
        <div class="alert alert-info small mb-0 d-flex align-items-start gap-2">
          <i class="bi bi-info-circle flex-shrink-0 mt-1"></i>
          <div>
            <strong>Tip:</strong> Edits are logged per block. You can review change history in the database if needed.
          </div>
        </div>
      </div>
      <div class="modal-footer d-flex justify-content-between">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
          <i class="bi bi-x-circle me-1"></i>Cancel
        </button>
        <button type="button" class="btn btn-primary" id="confirmEditContentBtn">
          <i class="bi bi-pencil-square me-1"></i>Continue & Edit
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Edit Colors Modal -->
<div class="modal fade" id="editColorsModal" tabindex="-1" aria-labelledby="editColorsModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="editColorsModalLabel">
          <i class="bi bi-palette me-2"></i>Edit Municipality Colors
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="colorUpdateFeedback"></div>
        
        <div class="alert alert-info small d-flex align-items-start gap-2 mb-3">
          <i class="bi bi-info-circle flex-shrink-0 mt-1"></i>
          <div>
            Choose your primary and secondary colors. These will be used for the municipality theme throughout the system.
          </div>
        </div>
        
        <input type="hidden" id="colorCsrfToken" value="<?= htmlspecialchars(CSRFProtection::generateToken('municipality-colors')) ?>">
        <input type="hidden" id="colorMunicipalityId" value="<?= $activeMunicipality['municipality_id'] ?? '' ?>">
        <input type="hidden" id="generateThemeCsrfToken" value="<?= htmlspecialchars(CSRFProtection::generateToken('generate-theme')) ?>">
        
        <div class="mb-4">
          <label for="primaryColorInput" class="form-label fw-bold">
            <i class="bi bi-circle-fill me-1" id="primaryColorIcon" style="color: <?= htmlspecialchars($activeMunicipality['primary_color'] ?? '#2e7d32') ?>;"></i>
            Primary Color
          </label>
          <div class="d-flex gap-3 align-items-center">
            <input 
              type="color" 
              class="form-control form-control-color" 
              id="primaryColorInput" 
              value="<?= htmlspecialchars($activeMunicipality['primary_color'] ?? '#2e7d32') ?>"
              style="width: 80px; height: 50px;"
              title="Click to open color picker">
            <input 
              type="text" 
              class="form-control font-monospace" 
              id="primaryColorText" 
              value="<?= htmlspecialchars($activeMunicipality['primary_color'] ?? '#2e7d32') ?>"
              placeholder="#2e7d32"
              maxlength="7"
              style="max-width: 120px;"
              title="Type or paste hex color (e.g., #4caf50)">
            <div 
              id="primaryColorPreview" 
              class="border rounded" 
              style="width: 50px; height: 50px; background: <?= htmlspecialchars($activeMunicipality['primary_color'] ?? '#2e7d32') ?>;"></div>
          </div>
          <small class="text-muted">Used for main buttons, headers, and primary UI elements</small>
        </div>
        
        <div class="mb-3">
          <label for="secondaryColorInput" class="form-label fw-bold">
            <i class="bi bi-circle-fill me-1" id="secondaryColorIcon" style="color: <?= htmlspecialchars($activeMunicipality['secondary_color'] ?? '#1b5e20') ?>;"></i>
            Secondary Color
          </label>
          <div class="d-flex gap-3 align-items-center">
            <input 
              type="color" 
              class="form-control form-control-color" 
              id="secondaryColorInput" 
              value="<?= htmlspecialchars($activeMunicipality['secondary_color'] ?? '#1b5e20') ?>"
              style="width: 80px; height: 50px;"
              title="Click to open color picker">
            <input 
              type="text" 
              class="form-control font-monospace" 
              id="secondaryColorText" 
              value="<?= htmlspecialchars($activeMunicipality['secondary_color'] ?? '#1b5e20') ?>"
              placeholder="#1b5e20"
              maxlength="7"
              style="max-width: 120px;"
              title="Type or paste hex color (e.g., #1b5e20)">
            <div 
              id="secondaryColorPreview" 
              class="border rounded" 
              style="width: 50px; height: 50px; background: <?= htmlspecialchars($activeMunicipality['secondary_color'] ?? '#1b5e20') ?>;"></div>
          </div>
          <small class="text-muted">Used for accents, hover states, and secondary UI elements</small>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
          <i class="bi bi-x-circle me-1"></i>Cancel
        </button>
        <button type="button" class="btn btn-primary" id="saveColorsBtn">
          <i class="bi bi-check-circle me-1"></i>Save Colors
        </button>
      </div>
    </div>
  </div>
</div>

<script>
// Update color preview and hex text when color picker changes
document.getElementById('primaryColorInput')?.addEventListener('input', function(e) {
    const color = e.target.value;
    document.getElementById('primaryColorText').value = color;
    document.getElementById('primaryColorPreview').style.background = color;
    document.getElementById('primaryColorIcon').style.color = color;
});

document.getElementById('secondaryColorInput')?.addEventListener('input', function(e) {
    const color = e.target.value;
    document.getElementById('secondaryColorText').value = color;
    document.getElementById('secondaryColorPreview').style.background = color;
    document.getElementById('secondaryColorIcon').style.color = color;
});

// Update color picker when text input changes (bi-directional sync)
document.getElementById('primaryColorText')?.addEventListener('input', function(e) {
    let color = e.target.value.trim();
    
    // Auto-add # if missing
    if (color && !color.startsWith('#')) {
        color = '#' + color;
        e.target.value = color;
    }
    
    // Validate hex format (allow partial input)
    const hexPattern = /^#[0-9A-Fa-f]{0,6}$/;
    if (hexPattern.test(color)) {
        // Only update if we have a complete hex color
        if (color.length === 7) {
            document.getElementById('primaryColorInput').value = color;
            document.getElementById('primaryColorPreview').style.background = color;
            document.getElementById('primaryColorIcon').style.color = color;
            e.target.classList.remove('is-invalid');
        }
    } else {
        // Invalid format - show validation
        e.target.classList.add('is-invalid');
    }
});

document.getElementById('secondaryColorText')?.addEventListener('input', function(e) {
    let color = e.target.value.trim();
    
    // Auto-add # if missing
    if (color && !color.startsWith('#')) {
        color = '#' + color;
        e.target.value = color;
    }
    
    // Validate hex format (allow partial input)
    const hexPattern = /^#[0-9A-Fa-f]{0,6}$/;
    if (hexPattern.test(color)) {
        // Only update if we have a complete hex color
        if (color.length === 7) {
            document.getElementById('secondaryColorInput').value = color;
            document.getElementById('secondaryColorPreview').style.background = color;
            document.getElementById('secondaryColorIcon').style.color = color;
            e.target.classList.remove('is-invalid');
        }
    } else {
        // Invalid format - show validation
        e.target.classList.add('is-invalid');
    }
});

// Validate on blur (when user leaves the field)
document.getElementById('primaryColorText')?.addEventListener('blur', function(e) {
    const color = e.target.value.trim();
    const hexPattern = /^#[0-9A-Fa-f]{6}$/;
    
    if (color && !hexPattern.test(color)) {
        // Invalid - revert to last valid color
        const lastValid = document.getElementById('primaryColorInput').value;
        e.target.value = lastValid;
        e.target.classList.remove('is-invalid');
        
        // Show tooltip or alert
        e.target.setCustomValidity('Please enter a valid hex color (e.g., #4caf50)');
        e.target.reportValidity();
        setTimeout(() => e.target.setCustomValidity(''), 3000);
    }
});

document.getElementById('secondaryColorText')?.addEventListener('blur', function(e) {
    const color = e.target.value.trim();
    const hexPattern = /^#[0-9A-Fa-f]{6}$/;
    
    if (color && !hexPattern.test(color)) {
        // Invalid - revert to last valid color
        const lastValid = document.getElementById('secondaryColorInput').value;
        e.target.value = lastValid;
        e.target.classList.remove('is-invalid');
        
        // Show tooltip or alert
        e.target.setCustomValidity('Please enter a valid hex color (e.g., #1b5e20)');
        e.target.reportValidity();
        setTimeout(() => e.target.setCustomValidity(''), 3000);
    }
});

// AJAX save colors without page refresh
document.getElementById('saveColorsBtn')?.addEventListener('click', async function() {
    const btn = this;
    const originalHTML = btn.innerHTML;
    const feedbackDiv = document.getElementById('colorUpdateFeedback');
    
    // Disable button and show loading
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving...';
    feedbackDiv.innerHTML = '';
    
    const primaryColor = document.getElementById('primaryColorInput').value;
    const secondaryColor = document.getElementById('secondaryColorInput').value;
    const csrfToken = document.getElementById('colorCsrfToken').value;
    const municipalityId = document.getElementById('colorMunicipalityId').value;
    
    try {
        const formData = new FormData();
        formData.append('update_colors', '1');
        formData.append('csrf_token', csrfToken);
        formData.append('municipality_id', municipalityId);
        formData.append('primary_color', primaryColor);
        formData.append('secondary_color', secondaryColor);
        
        const response = await fetch('update_municipality_colors.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            // Show success message
            feedbackDiv.innerHTML = `
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle me-2"></i>${result.message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            `;
            
            // Update the color chips on the main page WITHOUT refreshing
            const mainPrimaryChip = document.querySelector('.color-chip');
            const mainSecondaryChip = document.querySelectorAll('.color-chip')[1];
            const mainPrimaryText = document.querySelector('.color-chip + div .fw-bold');
            const mainSecondaryText = document.querySelectorAll('.color-chip + div .fw-bold')[1];
            
            if (mainPrimaryChip) {
                mainPrimaryChip.style.background = primaryColor;
            }
            if (mainSecondaryChip) {
                mainSecondaryChip.style.background = secondaryColor;
            }
            if (mainPrimaryText) {
                mainPrimaryText.textContent = primaryColor;
            }
            if (mainSecondaryText) {
                mainSecondaryText.textContent = secondaryColor;
            }
            
            // Re-enable button
            btn.disabled = false;
            btn.innerHTML = originalHTML;
            
            // Auto-close modal after 1.5 seconds
            setTimeout(() => {
                const modal = bootstrap.Modal.getInstance(document.getElementById('editColorsModal'));
                if (modal) {
                    modal.hide();
                }
            }, 1500);
            
        } else {
            throw new Error(result.message || 'Unknown error');
        }
        
    } catch (error) {
        console.error('Error saving colors:', error);
        feedbackDiv.innerHTML = `
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle me-2"></i>${error.message || 'Failed to save colors. Please try again.'}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        `;
        btn.disabled = false;
        btn.innerHTML = originalHTML;
    }
});
</script>

<!-- Edit System Information Modal -->
<div class="modal fade" id="editSystemInfoModal" tabindex="-1" aria-labelledby="editSystemInfoModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="POST" action="">
        <input type="hidden" name="update_system_info" value="1">
        <input type="hidden" name="csrf_token" value="<?= CSRFProtection::generateToken('municipality-system-info') ?>">
        <div class="modal-header bg-primary bg-opacity-10">
          <h5 class="modal-title" id="editSystemInfoModalLabel">
            <i class="bi bi-building-fill me-2"></i>Edit System Information
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label for="systemNameInput" class="form-label fw-semibold">System Name</label>
            <input type="text" class="form-control" id="systemNameInput" name="system_name" value="<?= htmlspecialchars($systemInfoSettings['system_name']) ?>" required>
            <div class="form-text">
              <i class="bi bi-info-circle"></i> Name of the system/application. <strong>Displayed in the website navigation bar as the brand name.</strong>
            </div>
          </div>
          <div class="mb-0">
            <label for="municipalityNameInput" class="form-label fw-semibold">Municipality Name</label>
            <input type="text" class="form-control" id="municipalityNameInput" name="municipality_name" value="<?= htmlspecialchars($systemInfoSettings['municipality_name']) ?>" required>
            <div class="form-text">
              <i class="bi bi-info-circle"></i> Name of the municipality or local government unit. <strong>Displayed in the website navigation bar after the system name (format: System Name • Municipality Name).</strong>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
            <i class="bi bi-x-circle me-1"></i>Cancel
          </button>
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-check-circle me-1"></i>Save Changes
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Contact Information Modal -->
<div class="modal fade" id="editContactInfoModal" tabindex="-1" aria-labelledby="editContactInfoModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <form method="POST" action="">
        <input type="hidden" name="update_contact_info" value="1">
        <input type="hidden" name="municipality_id" value="<?= $activeMunicipality ? $activeMunicipality['municipality_id'] : 0 ?>">
        <input type="hidden" name="csrf_token" value="<?= CSRFProtection::generateToken('municipality-contact-info') ?>">
        <div class="modal-header bg-info bg-opacity-10">
          <h5 class="modal-title" id="editContactInfoModalLabel">
            <i class="bi bi-telephone-fill me-2"></i>Edit Contact Information
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="alert alert-info mb-3">
            <i class="bi bi-info-circle me-2"></i>
            <strong>Unified Contact Info:</strong> These details will be displayed consistently across the topbar, footer, and all contact pages.
          </div>
          <div class="row g-3">
            <div class="col-md-6">
              <label for="contactPhoneInput" class="form-label fw-semibold">
                <i class="bi bi-telephone me-1"></i>Phone Number <span class="text-danger">*</span>
              </label>
              <input type="text" class="form-control" id="contactPhoneInput" name="contact_phone" 
                     value="<?= htmlspecialchars($contactInfoSettings['contact_phone']) ?>" required 
                     placeholder="(046) 886-4454">
              <div class="form-text">Main contact number for inquiries.</div>
            </div>
            <div class="col-md-6">
              <label for="contactEmailInput" class="form-label fw-semibold">
                <i class="bi bi-envelope me-1"></i>Email Address <span class="text-danger">*</span>
              </label>
              <input type="email" class="form-control" id="contactEmailInput" name="contact_email" 
                     value="<?= htmlspecialchars($contactInfoSettings['contact_email']) ?>" required 
                     placeholder="educaid@generaltrias.gov.ph">
              <div class="form-text">Primary email for correspondence.</div>
            </div>
            <div class="col-md-6">
              <label for="contactAddressInput" class="form-label fw-semibold">
                <i class="bi bi-geo-alt me-1"></i>Office Address
              </label>
              <input type="text" class="form-control" id="contactAddressInput" name="contact_address" 
                     value="<?= htmlspecialchars($contactInfoSettings['contact_address']) ?>" 
                     placeholder="City Hall, Address">
              <div class="form-text">Physical office location.</div>
            </div>
            <div class="col-md-6">
              <label for="officeHoursInput" class="form-label fw-semibold">
                <i class="bi bi-clock me-1"></i>Office Hours
              </label>
              <input type="text" class="form-control" id="officeHoursInput" name="office_hours" 
                     value="<?= htmlspecialchars($contactInfoSettings['office_hours']) ?>" 
                     placeholder="Mon–Fri 8:00AM - 5:00PM">
              <div class="form-text">Business hours displayed in topbar.</div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
            <i class="bi bi-x-circle me-1"></i>Cancel
          </button>
          <button type="submit" class="btn btn-info text-white">
            <i class="bi bi-check-circle me-1"></i>Save Contact Info
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Generate Theme Confirmation Modal -->
<div class="modal fade" id="generateThemeModal" tabindex="-1" aria-labelledby="generateThemeModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-warning bg-opacity-10">
        <h5 class="modal-title" id="generateThemeModalLabel">
          <i class="bi bi-exclamation-triangle text-warning me-2"></i>Generate Theme Automatically
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-info mb-3">
          <i class="bi bi-info-circle me-2"></i>
          <strong>What this does:</strong> This will automatically generate all sidebar, topbar, and footer colors based on your Primary and Secondary colors.
        </div>
        
        <p class="mb-3"><strong>⚠️ Important:</strong> This will:</p>
        <ul class="mb-3">
          <li>Generate 19+ colors from your Primary Color (<code><?= htmlspecialchars($activeMunicipality['primary_color'] ?? '#2e7d32') ?></code>) and Secondary Color (<code><?= htmlspecialchars($activeMunicipality['secondary_color'] ?? '#1b5e20') ?></code>)</li>
          <li>Apply these colors to the <strong>Sidebar Theme</strong> (all pages)</li>
          <li>Apply these colors to the <strong>Topbar Theme</strong> (all pages)</li>
          <li>Apply these colors to the <strong>Footer Theme</strong> (all pages)</li>
          <li>Override any existing theme colors you've set</li>
          <li>Follow WCAG accessibility standards for contrast</li>
        </ul>
        
        <div class="alert alert-warning mb-0">
          <i class="bi bi-exclamation-triangle me-2"></i>
          <strong>This action cannot be undone.</strong> Make sure your Primary and Secondary colors are correct before proceeding.
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
          <i class="bi bi-x-circle me-1"></i>Cancel
        </button>
        <button type="button" class="btn btn-success" id="confirmGenerateThemeBtn" 
                data-municipality-id="<?= $activeMunicipality['municipality_id'] ?? '' ?>">
          <i class="bi bi-magic me-1"></i>Generate Theme
        </button>
      </div>
    </div>
  </div>
</div>

<script>
// Confirm Generate Theme Button Click Handler
document.getElementById('confirmGenerateThemeBtn')?.addEventListener('click', async function() {
    const btn = this;
    const municipalityId = btn.dataset.municipalityId;
    const originalHTML = btn.innerHTML;
    
    // Prevent multiple clicks
    if (btn.disabled) {
        return;
    }
    
    if (!municipalityId) {
        alert('Municipality ID not found. Please refresh the page and try again.');
        return;
    }
    
    // Disable button and show loading
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Generating...';
    
    try {
        const csrfToken = document.getElementById('generateThemeCsrfToken')?.value;
        if (!csrfToken) {
            throw new Error('Security token not found. Please refresh the page.');
        }
        
        const formData = new FormData();
        formData.append('csrf_token', csrfToken);
        formData.append('municipality_id', municipalityId);
        
        const response = await fetch('generate_and_apply_theme.php', {
            method: 'POST',
            body: formData
        });
        
        // Try to get the response as text first to see what we received
        const responseText = await response.text();
        
        if (!response.ok) {
            console.error('Server response:', responseText);
            
            // Try to parse as JSON for error message
            try {
                const errorData = JSON.parse(responseText);
                throw new Error(errorData.message || `Server error: ${response.status}`);
            } catch (parseError) {
                throw new Error(`Server error: ${response.status}`);
            }
        }
        
        // Parse successful response
        const result = JSON.parse(responseText);
        
        if (result.success) {
            // Close modal
            const modal = bootstrap.Modal.getInstance(document.getElementById('generateThemeModal'));
            if (modal) {
                modal.hide();
            }
            
            // Apply theme live by reloading CSS files
            const timestamp = new Date().getTime();
            
            // Reload sidebar theme CSS
            let sidebarLink = document.querySelector('link[href*="sidebar_theme.css"]');
            if (sidebarLink) {
                const newHref = sidebarLink.href.split('?')[0] + '?v=' + timestamp;
                sidebarLink.href = newHref;
            }
            
            // Reload topbar theme CSS
            let topbarLink = document.querySelector('link[href*="topbar_theme.css"]');
            if (topbarLink) {
                const newHref = topbarLink.href.split('?')[0] + '?v=' + timestamp;
                topbarLink.href = newHref;
            }
            
            // Reload footer theme CSS
            let footerLink = document.querySelector('link[href*="footer_theme.css"]');
            if (footerLink) {
                const newHref = footerLink.href.split('?')[0] + '?v=' + timestamp;
                footerLink.href = newHref;
            }
            
            // Show success message
            alert('✅ Theme generated and applied successfully!\n\n' +
                  `• ${result.data.colors_applied || 19} colors applied\n` +
                  '• Sidebar theme updated\n' +
                  '• Topbar theme updated\n' +
                  '• Footer theme updated\n\n' +
                  'Changes applied live! No page refresh needed.');
            
        } else {
            throw new Error(result.message || 'Failed to generate theme');
        }
        
    } catch (error) {
        console.error('Error generating theme:', error);
        alert('❌ Failed to generate theme: ' + error.message);
        btn.disabled = false;
        btn.innerHTML = originalHTML;
    }
});
</script>

</body>
</html>
