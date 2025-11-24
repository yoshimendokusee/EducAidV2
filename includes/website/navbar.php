<?php
// Default navigation links for website
$nav_links = [
  ['href' => 'landingpage.php#home', 'label' => 'Home', 'active' => true],
  ['href' => 'about.php', 'label' => 'About', 'active' => false],
  ['href' => 'how-it-works.php', 'label' => 'How it works', 'active' => false],
  ['href' => 'requirements.php', 'label' => 'Requirements', 'active' => false],
  ['href' => 'announcements.php', 'label' => 'Announcements', 'active' => false],
  ['href' => 'contact.php', 'label' => 'Contact', 'active' => false]
];

// Override nav_links if custom ones are provided
if (isset($custom_nav_links)) {
  $nav_links = $custom_nav_links;
}

// Fetch system_name and municipality_name from theme_settings
$navbar_system_name = 'EducAid'; // fallback
$navbar_municipality_name_from_theme = 'City of General Trias'; // fallback

if (isset($connection)) {
    $theme_result = pg_query_params(
        $connection,
        "SELECT system_name, municipality_name FROM theme_settings WHERE municipality_id = $1 AND is_active = TRUE LIMIT 1",
        [1] // Default municipality_id
    );
    
    if ($theme_result && pg_num_rows($theme_result) > 0) {
        $theme_data = pg_fetch_assoc($theme_result);
        if (!empty($theme_data['system_name'])) {
            $navbar_system_name = $theme_data['system_name'];
        }
        if (!empty($theme_data['municipality_name'])) {
            $navbar_municipality_name_from_theme = $theme_data['municipality_name'];
        }
        pg_free_result($theme_result);
    }
}

// Brand configuration (single editable text block; logo image is static, not inline editable)
$brand_config = [
  'name' => $navbar_system_name . ' • ' . $navbar_municipality_name_from_theme,
  'href' => '#',
  'logo' => 'assets/images/educaid-logo.png', // fallback logo path
  'hide_educaid_logo' => false, // whether to hide the EducAid logo
  'show_municipality' => false, // whether to show custom municipality badge
  'municipality_logo' => null,
  'municipality_name' => null
];

// Override brand config if custom one is provided
if (isset($custom_brand_config)) {
  $brand_config = array_merge($brand_config, $custom_brand_config);
}

// Determine if we're in a subfolder and calculate relative path to root
$base_path = '';
if (strpos($_SERVER['PHP_SELF'], '/website/') !== false) {
  $base_path = '../';
} elseif (strpos($_SERVER['PHP_SELF'], '/modules/student/') !== false) {
  $base_path = '../../';
} elseif (strpos($_SERVER['PHP_SELF'], '/modules/admin/') !== false) {
  $base_path = '../../';
}

// Check if we're in edit mode (set by parent page)
$in_edit_mode = isset($edit_mode) && $edit_mode === true;
$is_edit_mode = isset($IS_EDIT_MODE) && $IS_EDIT_MODE === true;
$is_edit_super_admin = isset($IS_EDIT_SUPER_ADMIN) && $IS_EDIT_SUPER_ADMIN === true;
$navbar_edit_mode = $in_edit_mode || $is_edit_mode || $is_edit_super_admin;

// Check if user is super admin - multiple ways depending on how page sets it
$navbar_is_super_admin = false;
if (isset($_SESSION['role']) && $_SESSION['role'] === 'super_admin') {
    $navbar_is_super_admin = true;
} elseif (isset($is_super_admin) && $is_super_admin === true) {
    $navbar_is_super_admin = true;
} elseif (isset($IS_EDIT_SUPER_ADMIN) && $IS_EDIT_SUPER_ADMIN === true) {
    $navbar_is_super_admin = true;
} elseif (isset($_SESSION['admin_id']) && function_exists('getCurrentAdminRole')) {
    // Fallback: check role dynamically
    if (isset($connection)) {
        $role = @getCurrentAdminRole($connection);
        if ($role === 'super_admin') {
            $navbar_is_super_admin = true;
        }
    }
}

// Fetch active municipality logo for public pages and super admin
$navbar_municipality_logo = null;
$navbar_municipality_name = null;

if (isset($connection)) {
    // Determine which municipality to show
    $muni_id = null;
    
    if ($navbar_is_super_admin) {
        // Super admin: try to get from session first
        $muni_id = isset($_SESSION['active_municipality_id']) ? (int)$_SESSION['active_municipality_id'] : null;
        
        // If no session, get the admin's municipality
        if (!$muni_id && isset($_SESSION['admin_id'])) {
            $admin_id = (int)$_SESSION['admin_id'];
            $assign_result = pg_query_params(
                $connection,
                "SELECT municipality_id FROM admins WHERE admin_id = $1",
                [$admin_id]
            );
            
            if ($assign_result && pg_num_rows($assign_result) > 0) {
                $assign_data = pg_fetch_assoc($assign_result);
                $muni_id = (int)$assign_data['municipality_id'];
                pg_free_result($assign_result);
            }
        }
    } else {
        // Public pages: always show default municipality (General Trias, ID=1)
        $muni_id = 1;
    }
    
    // Fetch municipality logo if we have an ID
    if ($muni_id) {
        $muni_result = pg_query_params(
            $connection,
            "SELECT name,
                    custom_logo_image,
                    preset_logo_image,
                    use_custom_logo,
                    CASE 
                        WHEN use_custom_logo = TRUE AND custom_logo_image IS NOT NULL AND custom_logo_image != '' 
                        THEN custom_logo_image
                        ELSE preset_logo_image
                    END AS active_logo
             FROM municipalities 
             WHERE municipality_id = $1 
             LIMIT 1",
            [$muni_id]
        );
        
        if ($muni_result && pg_num_rows($muni_result) > 0) {
            $muni_data = pg_fetch_assoc($muni_result);
            $navbar_municipality_name = $muni_data['name'];
            
            // Build logo path using the same base_path logic
            if (!empty($muni_data['active_logo'])) {
                $logo_path = trim($muni_data['active_logo']);
                
                // Handle base64 data URIs
                if (preg_match('#^data:image/[^;]+;base64,#i', $logo_path)) {
                    $navbar_municipality_logo = $logo_path;
                }
                // Handle external URLs
                elseif (preg_match('#^(?:https?:)?//#i', $logo_path)) {
                    $navbar_municipality_logo = $logo_path;
                }
                // Handle absolute web paths (start with /)
                elseif (str_starts_with($logo_path, '/')) {
                    // Absolute paths from web root - just need to make them relative using base_path
                    // Remove leading slash and add base_path
                    $relative = ltrim($logo_path, '/');
                    $encoded = implode('/', array_map('rawurlencode', explode('/', $relative)));
                    $navbar_municipality_logo = $base_path . $encoded;
                }
                // Handle relative paths
                else {
                    // Normalize path
                    $normalized = str_replace('\\', '/', $logo_path);
                    $normalized = preg_replace('#(?<!:)/{2,}#', '/', $normalized);
                    
                    // URL encode each segment while preserving slashes
                    $encoded = implode('/', array_map('rawurlencode', explode('/', $normalized)));
                    
                    // Use base_path to create correct relative path
                    $navbar_municipality_logo = $base_path . $encoded;
                }
                
                // Add cache-busting with file modification time (only updates when file changes)
                if ($navbar_municipality_logo && !preg_match('#^data:image#i', $navbar_municipality_logo)) {
                    // Build absolute file path to check modification time
                    $absolutePath = null;
                    if (strpos($navbar_municipality_logo, 'http') === 0) {
                        // External URL - use current timestamp as fallback
                        $cacheKey = time();
                    } else {
                        // Local file - determine document root relative path
                        $docRoot = $_SERVER['DOCUMENT_ROOT'];
                        // Remove base_path prefix to get document root relative path
                        $cleanPath = $logo_path;
                        if (str_starts_with($cleanPath, 'assets/')) {
                            $absolutePath = $docRoot . '/' . $cleanPath;
                        } elseif (str_starts_with($cleanPath, '/')) {
                            $absolutePath = $docRoot . $cleanPath;
                        } else {
                            $absolutePath = $docRoot . '/assets/' . $cleanPath;
                        }
                        
                        // Use file modification time if file exists, otherwise use a static version
                        $cacheKey = file_exists($absolutePath) ? filemtime($absolutePath) : '1';
                    }
                    
                    $separator = (strpos($navbar_municipality_logo, '?') !== false) ? '&' : '?';
                    $navbar_municipality_logo .= $separator . 'v=' . $cacheKey;
                }
            }
            pg_free_result($muni_result);
        }
    }
}

// Define which pages are editable (for red outline indication)
$editable_page_slugs = ['landingpage.php', 'about.php', 'how-it-works.php', 'requirements.php', 'announcements.php', 'contact.php'];

// Helper function to check if a nav link is editable
function is_editable_page($href) {
    global $editable_page_slugs;
    foreach ($editable_page_slugs as $slug) {
        if (strpos($href, $slug) !== false) {
            return true;
        }
    }
    return false;
}

// Helper function to convert regular link to edit link
function make_edit_link($href) {
    // Remove any hash fragments
    $href = strtok($href, '#');
    // Add ?edit=1 parameter
    if (strpos($href, '?') !== false) {
        return $href . '&edit=1';
    } else {
        return $href . '?edit=1';
    }
}
?>

<style>
:root {
  --topbar-height: 0px;
  --navbar-height: 0px;
  --navbar-content-max-width: 1400px; /* Increased for better space distribution */
}

body.has-header-offset {
  padding-top: calc(var(--topbar-height, 0px) + var(--navbar-height, 0px));
}

nav.navbar.fixed-header {
  position: fixed;
  top: var(--topbar-height, 0px);
  left: 0;
  right: 0;
  width: 100%;
  z-index: 1040;
  font-family: 'Manrope', 'Segoe UI', system-ui, -apple-system, Roboto, Arial, sans-serif;
  padding: 0.35rem 0; /* Compact vertical padding */
}

/* Critical: Force container-fluid to respect max-width with better distribution */
nav.navbar.fixed-header .container-fluid {
  max-width: var(--navbar-content-max-width, 1320px) !important;
  width: 100%;
  margin-left: auto;
  margin-right: auto;
  padding-left: 1rem;
  padding-right: 1rem;
  box-sizing: border-box;
}

@media (min-width: 992px) {
  nav.navbar.fixed-header .container-fluid {
    padding-left: 1.25rem;
    padding-right: 1.25rem;
    gap: 1rem; /* Compact gap for better space utilization */
    display: flex;
    flex-wrap: nowrap;
    align-items: center;
    justify-content: space-between;
  }
}

@media (min-width: 1200px) {
  nav.navbar.fixed-header .container-fluid {
    padding-left: 1.5rem;
    padding-right: 1.5rem;
    gap: 1.5rem;
  }
}

@media (min-width: 1400px) {
  nav.navbar.fixed-header .container-fluid {
    padding-left: 2rem;
    padding-right: 2rem;
    gap: 2rem; /* Generous gap only on very large screens */
  }
}

/* Municipality logo styling - Simple and clean like generaltrias.gov.ph */
.municipality-badge-navbar {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.municipality-logo-navbar {
  max-height: 42px; /* Compact size */
  width: auto;
  object-fit: contain;
}

@media (min-width: 992px) {
  .municipality-logo-navbar {
    max-height: 46px;
  }
  
  /* Larger logo when no navigation links */
  nav.navbar.fixed-header.no-nav-links .municipality-logo-navbar {
    max-height: 52px;
  }
}

@media (min-width: 1400px) {
  .municipality-logo-navbar {
    max-height: 50px; /* Larger only on XL screens */
  }
  
  nav.navbar.fixed-header.no-nav-links .municipality-logo-navbar {
    max-height: 56px; /* Even larger when there's space */
  }
}

nav.navbar.fixed-header .navbar-brand {
  gap: 0.65rem; /* Compact gap for space efficiency */
  flex-wrap: nowrap;
  flex: 0 0 auto; /* Don't shrink, maintain natural size */
  max-width: calc(100% - 56px); /* Mobile: space for toggler */
  overflow: visible !important; /* Allow content to overflow if needed */
}

/* On desktop, allow brand to be flexible with generous space */
@media (min-width: 992px) {
  nav.navbar.fixed-header .navbar-brand {
    max-width: none !important; /* Remove all constraints - let content determine size */
    margin-right: 1rem; /* Add spacing from navigation */
    flex: 0 0 auto; /* Don't shrink */
    overflow: visible !important; /* Ensure overflow is visible */
  }
  
  /* If no navigation links, allow brand to expand freely */
  nav.navbar.fixed-header.no-nav-links .navbar-brand {
    max-width: none !important; /* Remove constraint when there's plenty of space */
    flex: 1 1 auto; /* Allow brand to take available space */
  }
}

@media (min-width: 1200px) {
  nav.navbar.fixed-header .navbar-brand {
    margin-right: 1.5rem; /* More spacing on larger screens */
  }
}

nav.navbar.fixed-header .navbar-brand .brand-logo {
  height: 42px; /* Compact size for better space management */
  width: auto;
  object-fit: contain;
  flex-shrink: 0;
}

nav.navbar.fixed-header .navbar-brand .brand-text {
  font-size: 1rem; /* Larger base size for better visibility */
  font-weight: 500; /* Lighter weight to match landing page consistency */
  line-height: 1.3;
  white-space: nowrap;
  overflow: visible !important; /* Force visible overflow */
  text-overflow: clip !important; /* Prevent ellipsis */
  max-width: none !important; /* Remove any max-width constraints */
}

/* When no navigation links, allow full brand text display */
nav.navbar.fixed-header.no-nav-links .navbar-brand .brand-text {
  white-space: normal; /* Allow wrapping if needed on small screens */
}

/* On desktop, increase size for prominence */
@media (min-width: 992px) {
  nav.navbar.fixed-header .navbar-brand .brand-text {
    font-size: 1.05rem; /* Slightly larger on desktop */
  }
  
  nav.navbar.fixed-header.no-nav-links .navbar-brand .brand-text {
    font-size: 1.15rem; /* Larger when there's space */
    white-space: nowrap; /* Keep single line on desktop */
  }
}

@media (min-width: 1200px) {
  nav.navbar.fixed-header .navbar-brand .brand-text {
    font-size: 1.1rem; /* Even larger on large screens */
  }
}

/* Ensure the hamburger doesn't shrink oddly under pressure */
nav.navbar.fixed-header .navbar-toggler {
  flex: 0 0 auto;
}

/* Slightly reduce brand sizing on extra-narrow widths to avoid wrap on Android */
@media (max-width: 430px) {
  nav.navbar.fixed-header .navbar-brand .brand-logo { height: 40px; }
  nav.navbar.fixed-header .navbar-brand .brand-text { font-size: 1rem; }
}

nav.navbar.fixed-header .navbar-nav.spread-nav {
  gap: 0.5rem;
  flex-wrap: wrap; /* Allow wrapping at high zoom */
  min-width: 0;
}

nav.navbar.fixed-header .navbar-nav.spread-nav .nav-link {
  white-space: nowrap;
  font-size: 0.85rem; /* Smaller base for better fit */
  padding: 0.5rem 0.6rem;
  transition: all 0.2s ease;
}

@media (min-width: 992px) {
  nav.navbar.fixed-header .navbar-nav.spread-nav {
    flex: 1 1 auto; /* Allow nav to grow and take available space */
    justify-content: center;
    gap: 0.4rem; /* Very compact gap to prevent wrapping */
    margin: 0;
    flex-wrap: nowrap; /* CHANGED: Prevent wrapping */
  }

  nav.navbar.fixed-header .navbar-nav.spread-nav .nav-item {
    display: flex;
    align-items: center;
    flex-shrink: 1; /* Allow items to shrink if needed */
  }

  nav.navbar.fixed-header .navbar-nav.spread-nav .nav-link {
    font-size: 0.85rem; /* Smaller to fit better */
    font-weight: 500;
    padding: 0.5rem 0.65rem; /* More compact padding */
    border-radius: 0.375rem;
  }
  
  nav.navbar.fixed-header .navbar-nav.spread-nav .nav-link:hover {
    background-color: rgba(0, 0, 0, 0.05);
  }
}

@media (min-width: 1200px) {
  nav.navbar.fixed-header .navbar-nav.spread-nav {
    gap: 0.6rem; /* Slightly more spacing */
  }

  nav.navbar.fixed-header .navbar-nav.spread-nav .nav-link {
    font-size: 0.9rem;
    padding: 0.55rem 0.75rem;
  }
}

@media (min-width: 1400px) {
  nav.navbar.fixed-header .navbar-nav.spread-nav {
    gap: 0.8rem; /* More spacing on XL screens */
  }

  nav.navbar.fixed-header .navbar-nav.spread-nav .nav-link {
    font-size: 0.95rem;
    padding: 0.6rem 0.85rem;
  }
}

@media (min-width: 1600px) {
  nav.navbar.fixed-header .navbar-nav.spread-nav {
    gap: 1rem; /* Generous spacing only on very large screens */
  }

  nav.navbar.fixed-header .navbar-nav.spread-nav .nav-link {
    padding: 0.65rem 1rem;
  }
}

nav.navbar.fixed-header .navbar-collapse {
  width: 100%;
  align-items: center;
  gap: 0.75rem;
}

@media (min-width: 992px) {
  nav.navbar.fixed-header .navbar-collapse {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: nowrap;
  }
}

/* On narrow screens, hide the municipality badge to save width */
@media (max-width: 430px) {
  nav.navbar.fixed-header .municipality-badge-navbar { display: none; }
}

.navbar-actions {
  flex-shrink: 0; /* Never shrink buttons */
  white-space: nowrap;
  gap: 0.5rem; /* Compact gap between buttons */
}

@media (min-width: 992px) {
  .navbar-actions {
    margin-left: 0.5rem; /* Small left margin */
  }
  
  .w-lg-auto {
    width: auto !important;
  }
}

/* Make buttons more compact */
.navbar-actions .btn {
  padding: 0.375rem 0.75rem; /* Compact button padding */
  font-size: 0.875rem; /* Smaller font for better fit */
  white-space: nowrap;
}

@media (min-width: 992px) {
  .navbar-actions .btn {
    padding: 0.4rem 0.85rem;
  }
}

@media (min-width: 1200px) {
  .navbar-actions .btn {
    padding: 0.45rem 1rem;
    font-size: 0.9rem;
  }
}

/* Icon-only mode for very tight spaces (high zoom) */
@media (min-width: 992px) and (max-width: 1199px) {
  .navbar-actions .btn span.d-none.d-sm-inline {
    display: none !important; /* Hide text on tight desktop screens */
  }
  .navbar-actions .btn i {
    margin: 0 !important; /* Remove icon margin when text is hidden */
  }
}

/* Hamburger to X animation */
.navbar-toggler {
  border: none;
  padding: 0.5rem;
  position: relative;
  width: 40px;
  height: 40px;
  transition: all 0.3s ease;
}

.navbar-toggler:focus {
  box-shadow: none;
  outline: none;
}

.navbar-toggler-icon {
  display: block;
  position: relative;
  width: 24px;
  height: 2px;
  background-color: currentColor;
  transition: all 0.3s ease;
  margin: auto;
}

.navbar-toggler-icon::before,
.navbar-toggler-icon::after {
  content: '';
  position: absolute;
  left: 0;
  width: 100%;
  height: 2px;
  background-color: currentColor;
  transition: all 0.3s ease;
}

.navbar-toggler-icon::before {
  top: -8px;
}

.navbar-toggler-icon::after {
  bottom: -8px;
}

/* Active state (X) */
.navbar-toggler.active .navbar-toggler-icon {
  background-color: transparent;
}

.navbar-toggler.active .navbar-toggler-icon::before {
  top: 0;
  transform: rotate(45deg);
}

.navbar-toggler.active .navbar-toggler-icon::after {
  bottom: 0;
  transform: rotate(-45deg);
}
</style>

<?php if ($navbar_edit_mode && $navbar_is_super_admin): ?>
<style>
  /* Red outline for editable navigation items */
  .nav-link.editable-page {
    position: relative;
    padding-bottom: 0.5rem !important;
  }
  
  .nav-link.editable-page::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 50%;
    transform: translateX(-50%);
    width: 80%;
    height: 3px;
    background: linear-gradient(90deg, transparent, #dc2626, transparent);
    border-radius: 2px;
    animation: pulse-underline 2s ease-in-out infinite;
  }
  
  .nav-link.editable-page:hover::after {
    background: linear-gradient(90deg, transparent, #991b1b, transparent);
    animation: none;
  }
  
  @keyframes pulse-underline {
    0%, 100% {
      opacity: 0.7;
      height: 3px;
    }
    50% {
      opacity: 1;
      height: 4px;
    }
  }
  
  /* Tooltip to show it's editable */
  .nav-link.editable-page {
    cursor: pointer;
  }
  
  .nav-link.editable-page:hover {
    color: #dc2626 !important;
  }
  
  /* Small edit icon indicator */
  .nav-link.editable-page .edit-indicator {
    font-size: 0.7rem;
    margin-left: 0.25rem;
    color: #dc2626;
    opacity: 0.6;
  }
  
  .nav-link.editable-page:hover .edit-indicator {
    opacity: 1;
  }
</style>
<?php endif; ?>

<!-- Navbar -->
<nav class="navbar navbar-expand-lg bg-white fixed-header<?php echo empty($nav_links) ? ' no-nav-links' : ''; ?>">
  <div class="container-fluid">
    <?php
      // Unified brand: static, non-editable brand text
      $brandDefault = htmlspecialchars($brand_config['name']);
      $brandText = $brandDefault;
      $logoPath = $brand_config['logo'];
    ?>
    <a class="navbar-brand d-flex align-items-center gap-2" href="<?php echo $brand_config['href']; ?>">
      <?php if (!$brand_config['hide_educaid_logo']): ?>
        <img src="<?php echo htmlspecialchars($logoPath); ?>" alt="EducAid Logo" class="brand-logo" style="height:48px;width:auto;object-fit:contain;" onerror="this.style.display='none';">
      <?php endif; ?>
      
      <?php 
      // Show municipality badge - either from custom config or from super admin session
      $show_muni_badge = false;
      $muni_logo_src = null;
      $muni_name = null;
      
      if ($brand_config['show_municipality'] && $brand_config['municipality_logo']) {
          // Custom municipality from page config
          $show_muni_badge = true;
          $muni_logo_src = $brand_config['municipality_logo'];
          $muni_name = $brand_config['municipality_name'];
      } elseif ($navbar_municipality_logo && $navbar_municipality_name) {
          // Super admin municipality
          $show_muni_badge = true;
          $muni_logo_src = $navbar_municipality_logo;
          $muni_name = $navbar_municipality_name;
      }
      
      if ($show_muni_badge && $muni_logo_src):
      ?>
      <div class="municipality-badge-navbar" title="<?php echo htmlspecialchars($muni_name); ?>">
        <img src="<?php echo htmlspecialchars($muni_logo_src); ?>" 
             alt="<?php echo htmlspecialchars($muni_name); ?>" 
             class="municipality-logo-navbar"
             onerror="this.style.display='none';">
      </div>
      <?php endif; ?>
      <span class="brand-text m-0 p-0"><?php echo $brandText; ?></span>
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav" aria-controls="nav" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse align-items-lg-center justify-content-lg-center" id="nav">
      <ul class="navbar-nav spread-nav mx-lg-auto mb-2 mb-lg-0">
        <?php foreach ($nav_links as $link): ?>
        <li class="nav-item">
          <?php
          // Check if this page is editable and we're in super admin edit mode
          $is_editable = is_editable_page($link['href']);
          $link_href = ($navbar_edit_mode && $navbar_is_super_admin && $is_editable) ? make_edit_link($link['href']) : $link['href'];
          $editable_class = ($navbar_edit_mode && $navbar_is_super_admin && $is_editable) ? ' editable-page' : '';
          ?>
          <a class="nav-link<?php echo $link['active'] ? ' active' : ''; ?><?php echo $editable_class; ?>" 
             href="<?php echo $link_href; ?>"
             <?php if ($navbar_edit_mode && $navbar_is_super_admin && $is_editable): ?>
             title="Click to edit this page"
             <?php endif; ?>>
            <?php echo $link['label']; ?>
            <?php if ($navbar_edit_mode && $navbar_is_super_admin && $is_editable): ?>
            <i class="bi bi-pencil-fill edit-indicator"></i>
            <?php endif; ?>
          </a>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php if (!isset($hide_auth_buttons) || !$hide_auth_buttons): ?>
      <div class="navbar-actions d-flex flex-column flex-lg-row align-items-center gap-2 ms-lg-4 mt-2 mt-lg-0 ms-lg-auto">
        <?php if (isset($prepend_navbar_actions) && is_array($prepend_navbar_actions)): ?>
          <?php foreach ($prepend_navbar_actions as $action):
            $actionHref = htmlspecialchars($action['href'] ?? '#');
            $actionLabel = htmlspecialchars($action['label'] ?? 'Action');
            $actionClass = trim($action['class'] ?? 'btn btn-outline-secondary btn-sm');
            $actionIcon = trim($action['icon'] ?? '');
            $actionTarget = isset($action['target']) ? htmlspecialchars($action['target']) : '';
            $actionRel = isset($action['rel']) ? htmlspecialchars($action['rel']) : '';
          ?>
          <a href="<?php echo $actionHref; ?>"
             class="<?php echo $actionClass; ?> d-flex align-items-center justify-content-center gap-2 w-100 w-lg-auto"
             <?php if ($actionTarget !== ''): ?> target="<?php echo $actionTarget; ?>"<?php endif; ?>
             <?php if ($actionRel !== ''): ?> rel="<?php echo $actionRel; ?>"<?php endif; ?>>
            <?php if ($actionIcon !== ''): ?><i class="bi <?php echo htmlspecialchars($actionIcon); ?>"></i><?php endif; ?>
            <span class="d-none d-sm-inline ms-1"><?php echo $actionLabel; ?></span>
          </a>
          <?php endforeach; ?>
        <?php endif; ?>
        <a href="<?php echo $base_path; ?>unified_login.php" class="btn btn-outline-primary btn-sm d-flex align-items-center justify-content-center gap-2 w-100 w-lg-auto">
          <i class="bi bi-box-arrow-in-right"></i><span class="d-none d-sm-inline ms-1">Sign In</span>
        </a>
        <a href="<?php echo $base_path; ?>register.php" class="btn btn-primary btn-sm d-flex align-items-center justify-content-center gap-2 w-100 w-lg-auto">
          <i class="bi bi-journal-text"></i><span class="d-none d-sm-inline ms-1">Apply</span>
        </a>
      </div>
      <?php endif; ?>
    </div>
  </div>
</nav>
<script>
(function () {
  const root = document.documentElement;

  function getTopbar() {
    return document.querySelector('.landing-topbar, .student-topbar, .admin-topbar, .topbar');
  }

  function getNavbar() {
    return document.querySelector('nav.navbar.fixed-header');
  }

  function updateOffsets() {
    const topbar = getTopbar();
    const navbar = getNavbar();
    const topbarHeight = topbar ? topbar.offsetHeight : 0;
    const navbarHeight = navbar ? navbar.offsetHeight : 0;

    root.style.setProperty('--topbar-height', `${topbarHeight}px`);
    root.style.setProperty('--navbar-height', `${navbarHeight}px`);

    if (topbarHeight || navbarHeight) {
      document.body.classList.add('has-header-offset');
    } else {
      document.body.classList.remove('has-header-offset');
    }
  }

  let resizeObserver;
  const supportsResizeObserver = typeof ResizeObserver !== 'undefined';

  function observeElements() {
    if (!supportsResizeObserver) {
      return;
    }

    if (resizeObserver) {
      resizeObserver.disconnect();
    }

    resizeObserver = new ResizeObserver(updateOffsets);

    const topbar = getTopbar();
    const navbar = getNavbar();

    if (topbar) {
      resizeObserver.observe(topbar);
    }

    if (navbar) {
      resizeObserver.observe(navbar);
    }
  }

  document.addEventListener('DOMContentLoaded', () => {
    updateOffsets();
    observeElements();

    const navbarCollapse = document.getElementById('nav');
    if (navbarCollapse) {
      ['shown.bs.collapse', 'hidden.bs.collapse'].forEach(eventName => {
        navbarCollapse.addEventListener(eventName, updateOffsets);
      });
    }
  });

  // Perform an immediate calculation as soon as the script runs
  // This reduces initial layout shift before DOMContentLoaded
  try { updateOffsets(); } catch (e) {}

  window.addEventListener('load', updateOffsets);
  window.addEventListener('resize', updateOffsets);
})();

// Hamburger menu animation - Toggle between hamburger and X
(function() {
  'use strict';
  
  document.addEventListener('DOMContentLoaded', () => {
    const navbarToggler = document.querySelector('.navbar-toggler');
    const navbarCollapse = document.getElementById('nav');
    
    if (navbarToggler && navbarCollapse) {
      // Listen for Bootstrap collapse events
      navbarCollapse.addEventListener('show.bs.collapse', () => {
        navbarToggler.classList.add('active');
      });
      
      navbarCollapse.addEventListener('hide.bs.collapse', () => {
        navbarToggler.classList.remove('active');
      });
      
      // Also handle initial state if menu is already open
      if (navbarCollapse.classList.contains('show')) {
        navbarToggler.classList.add('active');
      }
    }
  });
})();
</script>