<?php
/**
 * Dynamic Footer - Modular Component
 * CMS-Controlled footer for all website pages
 * Reads settings from footer_settings table
 * Contact info pulled from municipalities table (centralized source)
 */

// Ensure database connection
if (!isset($connection)) {
    @include_once __DIR__ . '/../../config/database.php';
}

// Default fallback settings
$footer_settings = [
    'footer_bg_color' => '#0051f8',
    'footer_text_color' => '#ffffff',
    'footer_heading_color' => '#ffffff',
    'footer_link_color' => '#ffffff',
    'footer_link_hover_color' => '#fbbf24',
    'footer_divider_color' => '#ffffff',
    'footer_title' => 'EducAid • General Trias',
    'footer_description' => 'Let\'s join forces for a more progressive GenTrias.',
    'contact_address' => 'General Trias City Hall, Cavite',
    'contact_phone' => '(046) 886-4454',
    'contact_email' => 'educaid@generaltrias.gov.ph'
];

// Load settings from database
if (isset($connection)) {
    $footerQuery = "SELECT * FROM footer_settings WHERE is_active = TRUE ORDER BY created_at DESC LIMIT 1";
    $footerResult = @pg_query($connection, $footerQuery);
    
    if ($footerResult && pg_num_rows($footerResult) > 0) {
        $dbFooter = pg_fetch_assoc($footerResult);
        foreach ($dbFooter as $key => $value) {
            if ($value !== null && $value !== '') {
                $footer_settings[$key] = $value;
            }
        }
    }
    
    // Fetch unified contact info and preset_logo from municipalities table (centralized source)
    // This ensures footer displays consistent contact info managed in Municipality Content Hub
    $checkContactQuery = @pg_query($connection, "SELECT column_name FROM information_schema.columns WHERE table_name = 'municipalities' AND column_name = 'contact_phone' LIMIT 1");
    if ($checkContactQuery && pg_num_rows($checkContactQuery) > 0) {
        $contactQuery = @pg_query_params($connection, 
            "SELECT contact_phone, contact_email, contact_address, office_hours, preset_logo_image FROM municipalities WHERE municipality_id = $1 LIMIT 1", 
            [1]
        );
        if ($contactQuery && ($contactRow = pg_fetch_assoc($contactQuery))) {
            if (!empty($contactRow['contact_phone'])) $footer_settings['contact_phone'] = $contactRow['contact_phone'];
            if (!empty($contactRow['contact_email'])) $footer_settings['contact_email'] = $contactRow['contact_email'];
            if (!empty($contactRow['contact_address'])) $footer_settings['contact_address'] = $contactRow['contact_address'];
            
            // Build proper path for preset_logo_image
            if (!empty($contactRow['preset_logo_image'])) {
                $logo_path = trim($contactRow['preset_logo_image']);
                
                // Determine base path based on current script location
                $footer_base_path = '';
                if (strpos($_SERVER['PHP_SELF'], '/website/') !== false) {
                    $footer_base_path = '../';
                } elseif (strpos($_SERVER['PHP_SELF'], '/modules/student/') !== false) {
                    $footer_base_path = '../../';
                } elseif (strpos($_SERVER['PHP_SELF'], '/modules/admin/') !== false) {
                    $footer_base_path = '../../';
                }
                
                // Handle different path formats
                if (preg_match('#^data:image/[^;]+;base64,#i', $logo_path)) {
                    // Base64 data URI - use as-is
                    $footer_settings['preset_logo_image'] = $logo_path;
                } elseif (preg_match('#^(?:https?:)?//#i', $logo_path)) {
                    // External URL - use as-is
                    $footer_settings['preset_logo_image'] = $logo_path;
                } elseif (str_starts_with($logo_path, '/')) {
                    // Absolute web path
                    $footer_settings['preset_logo_image'] = $footer_base_path . ltrim($logo_path, '/');
                } else {
                    // Relative path (e.g., assets/uploads/municipality_logos/...)
                    $footer_settings['preset_logo_image'] = $footer_base_path . str_replace('\\', '/', $logo_path);
                }
            }
        }
    }
}
?>

<style>
    #dynamic-footer {
        background: <?= htmlspecialchars($footer_settings['footer_bg_color']) ?>;
        color: <?= htmlspecialchars($footer_settings['footer_text_color']) ?>;
    }
    #dynamic-footer .footer-logo {
        font-size: 1.2rem;
        font-weight: 600;
        color: <?= htmlspecialchars($footer_settings['footer_heading_color']) ?>;
    }
    #dynamic-footer small {
        color: <?= htmlspecialchars($footer_settings['footer_text_color']) ?>;
        opacity: 0.9;
    }
    #dynamic-footer h6 {
        color: <?= htmlspecialchars($footer_settings['footer_heading_color']) ?>;
        font-weight: 600;
    }
    #dynamic-footer a {
        color: <?= htmlspecialchars($footer_settings['footer_link_color']) ?>;
        text-decoration: none;
        transition: color 0.3s ease;
    }
    #dynamic-footer a:hover {
        color: <?= htmlspecialchars($footer_settings['footer_link_hover_color']) ?>;
    }
    #dynamic-footer hr {
        border-color: <?= htmlspecialchars($footer_settings['footer_divider_color']) ?> !important;
        opacity: 0.25;
    }
    #dynamic-footer .brand-badge {
        width: 64px;
        height: 64px;
        background: <?= htmlspecialchars($footer_settings['footer_link_hover_color']) ?>;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 1.1rem;
        color: <?= htmlspecialchars($footer_settings['footer_bg_color']) ?>;
        overflow: hidden;
    }
    #dynamic-footer .brand-badge img {
        width: 100%;
        height: 100%;
        object-fit: contain;
        padding: 4px;
    }
    #dynamic-footer .btn-light {
        background: #fff;
        border: none;
        padding: 0.5rem 1rem;
        border-radius: 4px;
    }
    #dynamic-footer .form-control {
        border: none;
        padding: 0.5rem 1rem;
        border-radius: 4px;
    }
</style>

<!-- Footer (CMS Controlled) -->
<footer id="dynamic-footer" class="pt-5 pb-4">
  <div class="container">
    <div class="row g-4 align-items-center">
      <div class="col-lg-6">
        <div class="d-flex align-items-center gap-3">
            <div class="brand-badge">
              <?php if (!empty($footer_settings['preset_logo_image'])): ?>
                <img src="<?= htmlspecialchars($footer_settings['preset_logo_image']) ?>" alt="Municipality Logo" onerror="this.style.display='none'; this.parentElement.textContent='EA';">
              <?php else: ?>
                EA
              <?php endif; ?>
            </div>
            <div>
              <div class="footer-logo"><?= htmlspecialchars($footer_settings['footer_title']) ?></div>
              <small><?= htmlspecialchars($footer_settings['footer_description']) ?></small>
            </div>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="row">
          <div class="col-6 col-md-4">
            <h6>Explore</h6>
            <ul class="list-unstyled small">
              <li><a href="landingpage.php#home">Home</a></li>
              <li><a href="about.php">About</a></li>
              <li><a href="how-it-works.php">Process</a></li>
              <li><a href="announcements.php">Announcements</a></li>
            </ul>
          </div>
          <div class="col-6 col-md-4">
            <h6>Resources</h6>
            <ul class="list-unstyled small">
              <li><a href="requirements.php">Requirements</a></li>
              <li><a href="landingpage.php#faq">FAQs</a></li>
              <li><a href="contact.php">Contact</a></li>
            </ul>
          </div>
          <div class="col-12 col-md-4 mt-3 mt-md-0">
            <h6>Contact Info</h6>
            <ul class="list-unstyled small">
              <li class="mb-2"><i class="bi bi-geo-alt me-2"></i><?= htmlspecialchars($footer_settings['contact_address']) ?></li>
              <li class="mb-2"><i class="bi bi-telephone me-2"></i><?= htmlspecialchars($footer_settings['contact_phone']) ?></li>
              <li class="mb-2"><i class="bi bi-envelope me-2"></i><?= htmlspecialchars($footer_settings['contact_email']) ?></li>
            </ul>
          </div>
        </div>
      </div>
    </div>
    <hr class="border-light my-4" />
    <div class="d-flex justify-content-between flex-wrap gap-2 small">
      <span>© <span id="year"><?= date('Y') ?></span> City Government of General Trias • EducAid</span>
      <span>Developed by <strong>CTRL+Solutions</strong></span>
    </div>
  </div>
</footer>
