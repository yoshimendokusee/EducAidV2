<?php
// includes/website/topbar.php
// Landing page topbar adopting the super admin theme logic with minimal content.

require_once __DIR__ . '/../../config/database.php';

$topbar_settings = [
  'topbar_email' => 'educaid@generaltrias.gov.ph',
  'topbar_phone' => '(046) 886-4454',
  'topbar_office_hours' => 'Mon–Fri 8:00AM - 5:00PM',
  'topbar_bg_color' => '#1565c0',
  'topbar_bg_gradient' => '#0d47a1',
  'topbar_text_color' => '#ffffff',
  'topbar_link_color' => '#e3f2fd'
];

if (isset($connection)) {
  $muni_id = isset($_SESSION['active_municipality_id']) ? (int)$_SESSION['active_municipality_id'] : 1;
  // Only query theme_settings if the table exists
  $tblCheck = pg_query($connection, "SELECT to_regclass('public.theme_settings') AS tbl");
  $exists = $tblCheck ? (pg_fetch_assoc($tblCheck)['tbl'] !== null) : false;
  if ($tblCheck) { pg_free_result($tblCheck); }

  if ($exists) {
    $result = pg_query_params(
      $connection,
      "SELECT topbar_email, topbar_phone, topbar_bg_color, topbar_bg_gradient, topbar_text_color, topbar_link_color
       FROM theme_settings
       WHERE municipality_id = $1 AND is_active = TRUE
       LIMIT 1",
      [$muni_id]
    );
  
    if ($result && pg_num_rows($result) > 0) {
      $db_settings = pg_fetch_assoc($result);
      foreach ($db_settings as $key => $value) {
        if ($key === 'topbar_bg_gradient') {
          $topbar_settings[$key] = $value; // allow null to disable gradient
          continue;
        }
        if ($value !== null && $value !== '') {
          $topbar_settings[$key] = $value;
        }
      }
      pg_free_result($result);
    }
  }
  
  // Fetch unified contact info from municipalities table (centralized source)
  $checkContactQuery = @pg_query($connection, "SELECT column_name FROM information_schema.columns WHERE table_name = 'municipalities' AND column_name = 'contact_phone' LIMIT 1");
  if ($checkContactQuery && pg_num_rows($checkContactQuery) > 0) {
      $contactQuery = @pg_query_params($connection, 
          "SELECT contact_phone, contact_email, office_hours FROM municipalities WHERE municipality_id = $1 LIMIT 1", 
          [$muni_id]
      );
      if ($contactQuery && ($contactRow = pg_fetch_assoc($contactQuery))) {
          if (!empty($contactRow['contact_phone'])) $topbar_settings['topbar_phone'] = $contactRow['contact_phone'];
          if (!empty($contactRow['contact_email'])) $topbar_settings['topbar_email'] = $contactRow['contact_email'];
          if (!empty($contactRow['office_hours'])) $topbar_settings['topbar_office_hours'] = $contactRow['office_hours'];
      }
  }
}

$bg_color = $topbar_settings['topbar_bg_color'] ?? '#1565c0';
$bg_gradient = $topbar_settings['topbar_bg_gradient'] ?? null;
$topbar_background_css = ($bg_gradient && trim($bg_gradient) !== '')
  ? sprintf('linear-gradient(135deg, %s 0%%, %s 100%%)', $bg_color, $bg_gradient)
  : $bg_color;
?>
<div class="landing-topbar">
  <div class="container-fluid d-flex align-items-center justify-content-center justify-content-md-between gap-3 flex-wrap">
    <div class="d-flex align-items-center gap-3 small flex-wrap justify-content-center">
      <span class="d-flex align-items-center gap-1">
        <i class="bi bi-envelope"></i>
        <a href="mailto:<?= htmlspecialchars($topbar_settings['topbar_email']) ?>">
          <?= htmlspecialchars($topbar_settings['topbar_email']) ?>
        </a>
      </span>
      <span class="vr mx-1 d-none d-md-inline"></span>
      <span class="d-flex align-items-center gap-1">
        <i class="bi bi-telephone"></i>
        <span><?= htmlspecialchars($topbar_settings['topbar_phone']) ?></span>
      </span>
      <span class="vr mx-1 d-none d-md-inline"></span>
      <span class="d-none d-md-flex align-items-center gap-1">
        <i class="bi bi-clock"></i>
        <span><?= htmlspecialchars($topbar_settings['topbar_office_hours']) ?></span>
      </span>
    </div>
  </div>
</div>

<style>
.landing-topbar {
  background: <?= htmlspecialchars($topbar_background_css, ENT_QUOTES) ?>;
  color: <?= htmlspecialchars($topbar_settings['topbar_text_color']) ?>;
  font-size: 0.775rem;
  z-index: 1050;
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  box-shadow: 0 2px 4px rgba(0, 0, 0, .15);
}

.landing-topbar .container-fluid {
  display: flex;
  align-items: center;
  padding-top: 0.5rem;
  padding-bottom: 0.5rem;
}

.landing-topbar a {
  color: <?= htmlspecialchars($topbar_settings['topbar_link_color']) ?>;
  text-decoration: none;
}

.landing-topbar a:hover {
  color: <?= htmlspecialchars($topbar_settings['topbar_text_color']) ?>;
  opacity: 0.85;
}

.landing-topbar .bi {
  color: <?= htmlspecialchars($topbar_settings['topbar_text_color']) ?>;
  opacity: 0.9;
}

@media (max-width: 767.98px) {
  .landing-topbar {
    font-size: 0.7rem;
  }

  .landing-topbar .container-fluid {
    justify-content: center !important;
    row-gap: 0.5rem;
  }

  .landing-topbar .d-flex.align-items-center.gap-3 {
    gap: 0.5rem !important;
    text-align: center;
  }

  .landing-topbar a {
    word-break: break-word;
  }
}
</style>