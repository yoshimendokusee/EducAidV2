<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/CSRFProtection.php';
// Determine super admin edit mode for How It Works page (?edit=1)
$IS_EDIT_MODE = false; $is_super_admin = false;
@include_once __DIR__ . '/../config/database.php';
@include_once __DIR__ . '/../includes/permissions.php';
if (isset($_SESSION['admin_id']) && function_exists('getCurrentAdminRole')) {
  $role = @getCurrentAdminRole($connection);
  if ($role === 'super_admin') { $is_super_admin = true; }
}
if ($is_super_admin && isset($_GET['edit']) && $_GET['edit'] == '1') { $IS_EDIT_MODE = true; }
// Load dedicated how-it-works page content helper (separate storage)
@include_once __DIR__ . '/../includes/website/how_it_works_content_helper.php';

// SEO Configuration
require_once __DIR__ . '/../includes/seo_helpers.php';
$seoData = getSEOData('howitworks');
$pageTitle = $seoData['title'];
$pageDescription = $seoData['description'];
$pageKeywords = $seoData['keywords'];
$pageImage = 'https://www.educ-aid.site' . $seoData['image'];
$pageUrl = 'https://www.educ-aid.site/website/how-it-works.php';
$pageType = $seoData['type'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php include __DIR__ . '/../includes/seo_head.php'; ?>
  
  <?php if ($IS_EDIT_MODE): ?>
  <meta name="csrf-token" content="<?php echo CSRFProtection::generateToken('cms_content'); ?>" />
  <?php endif; ?>

  <?php 
  // Critical CSS to prevent FOUC
  include __DIR__ . '/../includes/website/critical_css.php'; 
  ?>

  <!-- Preload Critical Resources -->
  <link rel="preload" href="../assets/css/bootstrap.min.css" as="style" />
  <link rel="preload" href="../assets/css/website/landing_page.css" as="style" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />

  <!-- Google Fonts (async) -->
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@300;400;500;600;700&display=swap&v=2" rel="stylesheet" media="print" onload="this.media='all'" />
  <noscript><link href="https://fonts.googleapis.com/css2?family=Manrope:wght@300;400;500;600;700&display=swap&v=2" rel="stylesheet" /></noscript>
  
  <!-- Bootstrap 5 -->
  <link href="../assets/css/bootstrap.min.css" rel="stylesheet" />
  <!-- Bootstrap Icons -->
  <link href="../assets/css/bootstrap-icons.css" rel="stylesheet" />
  <link href="../assets/css/website/landing_page.css" rel="stylesheet" />
  <?php if ($IS_EDIT_MODE): ?>
  <link href="../assets/css/content_editor.css" rel="stylesheet" />
  <?php endif; ?>
</head>
<body>
  <?php if ($IS_EDIT_MODE): ?>
    <?php
      $toolbar_config = [
        'page_title' => 'How It Works Page',
        'exit_url' => 'how-it-works.php'
      ];
      include __DIR__ . '/../includes/website/edit_toolbar.php';
    ?>
  <?php endif; ?>

  <?php
  // Custom brand configuration - hide EducAid logo since municipality logo is shown
  $custom_brand_config = [
    'hide_educaid_logo' => true,
    'show_municipality' => false
  ];
  
  // Custom navigation for how-it-works page
  $custom_nav_links = [
    ['href' => 'landingpage.php', 'label' => 'Home', 'active' => false],
    ['href' => 'about.php', 'label' => 'About', 'active' => false],
    ['href' => 'how-it-works.php', 'label' => 'How it works', 'active' => true],
    ['href' => 'requirements.php', 'label' => 'Requirements', 'active' => false],
    ['href' => 'announcements.php', 'label' => 'Announcements', 'active' => false],
    ['href' => 'contact.php', 'label' => 'Contact', 'active' => false]
  ];
  
  include __DIR__ . '/../includes/website/topbar.php';
  include __DIR__ . '/../includes/website/navbar.php';
  include __DIR__ . '/../includes/website/cookie_consent.php';
  ?>

  <!-- Hero Section -->
  <section class="hero py-5" style="min-height: 50vh;">
    <div class="container">
      <div class="row justify-content-center">
        <div class="col-lg-10">
          <div class="hero-card text-center">
            <h1 class="display-4 fw-bold mb-3" data-lp-key="hiw_hero_title"<?php echo hiw_block_style('hiw_hero_title'); ?>><?php echo hiw_block('hiw_hero_title','How <span class="text-primary">EducAid</span> Works'); ?></h1>
            <p class="lead" data-lp-key="hiw_hero_lead"<?php echo hiw_block_style('hiw_hero_lead'); ?>><?php echo hiw_block('hiw_hero_lead','A comprehensive guide to applying for and receiving educational assistance through our digital platform.'); ?></p>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Process Overview -->
  <section class="py-5">
    <div class="container">
      <div class="text-center mb-5">
        <h2 class="section-title" data-lp-key="hiw_overview_title"<?php echo hiw_block_style('hiw_overview_title'); ?>><?php echo hiw_block('hiw_overview_title','Simple 4-Step Process'); ?></h2>
        <p class="section-lead mx-auto" style="max-width: 700px;" data-lp-key="hiw_overview_lead"<?php echo hiw_block_style('hiw_overview_lead'); ?>><?php echo hiw_block('hiw_overview_lead','From registration to claiming your assistance'); ?></p>
      </div>
      <div class="row g-4">
        <div class="col-md-6 col-lg-3">
          <div class="soft-card p-4 text-center h-100 border-primary border-2">
            <div class="bg-primary rounded-circle p-3 d-inline-flex mb-3" style="width: 60px; height: 60px; align-items: center; justify-content: center;">
              <span class="text-white fw-bold fs-4">1</span>
            </div>
            <h5 class="fw-bold text-primary" data-lp-key="hiw_step1_title"<?php echo hiw_block_style('hiw_step1_title'); ?>><?php echo hiw_block('hiw_step1_title','Register & Verify'); ?></h5>
            <p class="text-body-secondary" data-lp-key="hiw_step1_desc"<?php echo hiw_block_style('hiw_step1_desc'); ?>><?php echo hiw_block('hiw_step1_desc','Create your account and verify your identity'); ?></p>
          </div>
        </div>
        <div class="col-md-6 col-lg-3">
          <div class="soft-card p-4 text-center h-100">
            <div class="bg-secondary rounded-circle p-3 d-inline-flex mb-3" style="width: 60px; height: 60px; align-items: center; justify-content: center;">
              <span class="text-white fw-bold fs-4">2</span>
            </div>
            <h5 class="fw-bold" data-lp-key="hiw_step2_title"<?php echo hiw_block_style('hiw_step2_title'); ?>><?php echo hiw_block('hiw_step2_title','Apply & Upload'); ?></h5>
            <p class="text-body-secondary" data-lp-key="hiw_step2_desc"<?php echo hiw_block_style('hiw_step2_desc'); ?>><?php echo hiw_block('hiw_step2_desc','Complete application and submit documents'); ?></p>
          </div>
        </div>
        <div class="col-md-6 col-lg-3">
          <div class="soft-card p-4 text-center h-100">
            <div class="bg-secondary rounded-circle p-3 d-inline-flex mb-3" style="width: 60px; height: 60px; align-items: center; justify-content: center;">
              <span class="text-white fw-bold fs-4">3</span>
            </div>
            <h5 class="fw-bold" data-lp-key="hiw_step3_title"<?php echo hiw_block_style('hiw_step3_title'); ?>><?php echo hiw_block('hiw_step3_title','Get Evaluated'); ?></h5>
            <p class="text-body-secondary" data-lp-key="hiw_step3_desc"<?php echo hiw_block_style('hiw_step3_desc'); ?>><?php echo hiw_block('hiw_step3_desc','Admin reviews and approves your application'); ?></p>
          </div>
        </div>
        <div class="col-md-6 col-lg-3">
          <div class="soft-card p-4 text-center h-100">
            <div class="bg-secondary rounded-circle p-3 d-inline-flex mb-3" style="width: 60px; height: 60px; align-items: center; justify-content: center;">
              <span class="text-white fw-bold fs-4">4</span>
            </div>
            <h5 class="fw-bold" data-lp-key="hiw_step4_title"<?php echo hiw_block_style('hiw_step4_title'); ?>><?php echo hiw_block('hiw_step4_title','Claim with QR'); ?></h5>
            <p class="text-body-secondary" data-lp-key="hiw_step4_desc"<?php echo hiw_block_style('hiw_step4_desc'); ?>><?php echo hiw_block('hiw_step4_desc','Receive QR code and claim on distribution day'); ?></p>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Detailed Steps -->
  <section class="py-5 bg-body-tertiary">
    <div class="container">
      <h2 class="section-title text-center mb-5" data-lp-key="hiw_detailed_title"<?php echo hiw_block_style('hiw_detailed_title'); ?>><?php echo hiw_block('hiw_detailed_title','Detailed Process Guide'); ?></h2>
      
      <!-- Step 1 -->
      <div class="row g-5 align-items-center mb-5">
        <div class="col-lg-6">
          <div class="d-flex gap-3 mb-3">
            <div class="bg-primary rounded-circle p-3 flex-shrink-0" style="width: 60px; height: 60px; display: flex; align-items: center; justify-content: center;">
              <span class="text-white fw-bold fs-4">1</span>
            </div>
            <div>
              <h3 class="fw-bold" data-lp-key="hiw_step1_detail_title"<?php echo hiw_block_style('hiw_step1_detail_title'); ?>><?php echo hiw_block('hiw_step1_detail_title','Registration & Account Verification'); ?></h3>
              <p class="text-primary mb-0" data-lp-key="hiw_step1_detail_subtitle"<?php echo hiw_block_style('hiw_step1_detail_subtitle'); ?>><?php echo hiw_block('hiw_step1_detail_subtitle','Setting up your secure EducAid account'); ?></p>
            </div>
          </div>
          
          <div class="ps-5">
            <h5 class="fw-semibold mb-3" data-lp-key="hiw_step1_needs_title"<?php echo hiw_block_style('hiw_step1_needs_title'); ?>><?php echo hiw_block('hiw_step1_needs_title','What you\'ll need:'); ?></h5>
            <ul class="list-unstyled d-grid gap-2">
              <li data-lp-key="hiw_step1_need1"<?php echo hiw_block_style('hiw_step1_need1'); ?>><?php echo hiw_block('hiw_step1_need1','<i class="bi bi-check2-circle text-success me-2"></i>Valid email address'); ?></li>
              <li data-lp-key="hiw_step1_need2"<?php echo hiw_block_style('hiw_step1_need2'); ?>><?php echo hiw_block('hiw_step1_need2','<i class="bi bi-check2-circle text-success me-2"></i>Active mobile number'); ?></li>
              <li data-lp-key="hiw_step1_need3"<?php echo hiw_block_style('hiw_step1_need3'); ?>><?php echo hiw_block('hiw_step1_need3','<i class="bi bi-check2-circle text-success me-2"></i>Basic personal information'); ?></li>
              <li data-lp-key="hiw_step1_need4"<?php echo hiw_block_style('hiw_step1_need4'); ?>><?php echo hiw_block('hiw_step1_need4','<i class="bi bi-check2-circle text-success me-2"></i>Barangay of residence'); ?></li>
            </ul>
            
            <h5 class="fw-semibold mb-3 mt-4" data-lp-key="hiw_step1_process_title"<?php echo hiw_block_style('hiw_step1_process_title'); ?>><?php echo hiw_block('hiw_step1_process_title','The process:'); ?></h5>
            <div class="d-grid gap-3">
              <div class="d-flex gap-3">
                <div class="bg-light rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 32px; height: 32px;">
                  <i class="bi bi-1-circle text-primary"></i>
                </div>
                <div>
                  <strong data-lp-key="hiw_step1_sub1_title"<?php echo hiw_block_style('hiw_step1_sub1_title'); ?>><?php echo hiw_block('hiw_step1_sub1_title','Visit the Registration Page'); ?></strong>
                  <p class="text-body-secondary mb-0 small" data-lp-key="hiw_step1_sub1_desc"<?php echo hiw_block_style('hiw_step1_sub1_desc'); ?>><?php echo hiw_block('hiw_step1_sub1_desc','Click "Apply Now" from the homepage or go directly to the registration form.'); ?></p>
                </div>
              </div>
              <div class="d-flex gap-3">
                <div class="bg-light rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 32px; height: 32px;">
                  <i class="bi bi-2-circle text-primary"></i>
                </div>
                <div>
                  <strong data-lp-key="hiw_step1_sub2_title"<?php echo hiw_block_style('hiw_step1_sub2_title'); ?>><?php echo hiw_block('hiw_step1_sub2_title','Fill Out Basic Information'); ?></strong>
                  <p class="text-body-secondary mb-0 small" data-lp-key="hiw_step1_sub2_desc"<?php echo hiw_block_style('hiw_step1_sub2_desc'); ?>><?php echo hiw_block('hiw_step1_sub2_desc','Provide your name, contact details, and select your barangay from the dropdown.'); ?></p>
                </div>
              </div>
              <div class="d-flex gap-3">
                <div class="bg-light rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 32px; height: 32px;">
                  <i class="bi bi-3-circle text-primary"></i>
                </div>
                <div>
                  <strong data-lp-key="hiw_step1_sub3_title"<?php echo hiw_block_style('hiw_step1_sub3_title'); ?>><?php echo hiw_block('hiw_step1_sub3_title','Verify Email & Phone'); ?></strong>
                  <p class="text-body-secondary mb-0 small" data-lp-key="hiw_step1_sub3_desc"<?php echo hiw_block_style('hiw_step1_sub3_desc'); ?>><?php echo hiw_block('hiw_step1_sub3_desc','Check your email and SMS for verification codes. Enter them to activate your account.'); ?></p>
                </div>
              </div>
              <div class="d-flex gap-3">
                <div class="bg-light rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 32px; height: 32px;">
                  <i class="bi bi-4-circle text-primary"></i>
                </div>
                <div>
                  <strong data-lp-key="hiw_step1_sub4_title"<?php echo hiw_block_style('hiw_step1_sub4_title'); ?>><?php echo hiw_block('hiw_step1_sub4_title','Set Strong Password'); ?></strong>
                  <p class="text-body-secondary mb-0 small" data-lp-key="hiw_step1_sub4_desc"<?php echo hiw_block_style('hiw_step1_sub4_desc'); ?>><?php echo hiw_block('hiw_step1_sub4_desc','Create a secure password with at least 8 characters, including numbers and symbols.'); ?></p>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="col-lg-6">
          <div class="soft-card p-4">
            <img src="https://images.unsplash.com/photo-1516321318423-f06f85e504b3?q=80&w=1000&auto=format&fit=crop" alt="Registration" class="img-fluid rounded mb-3" />
            <div class="alert alert-info" data-lp-key="hiw_step1_security_note"<?php echo hiw_block_style('hiw_step1_security_note'); ?>><?php echo hiw_block('hiw_step1_security_note','<i class="bi bi-info-circle me-2"></i><strong>Security Note:</strong> Your data is protected with encryption and stored securely according to RA 10173 (Data Privacy Act).'); ?></div>
          </div>
        </div>
      </div>

      <!-- Step 2 -->
      <div class="row g-5 align-items-center mb-5">
        <div class="col-lg-6 order-lg-2">
          <div class="d-flex gap-3 mb-3">
            <div class="bg-warning rounded-circle p-3 flex-shrink-0" style="width: 60px; height: 60px; display: flex; align-items: center; justify-content: center;">
              <span class="text-white fw-bold fs-4">2</span>
            </div>
            <div>
              <h3 class="fw-bold" data-lp-key="hiw_step2_detail_title"<?php echo hiw_block_style('hiw_step2_detail_title'); ?>><?php echo hiw_block('hiw_step2_detail_title','Complete Application & Upload Documents'); ?></h3>
              <p class="text-warning mb-0" data-lp-key="hiw_step2_detail_subtitle"<?php echo hiw_block_style('hiw_step2_detail_subtitle'); ?>><?php echo hiw_block('hiw_step2_detail_subtitle','Providing your academic and financial information'); ?></p>
            </div>
          </div>
          
          <div class="ps-5">
            <h5 class="fw-semibold mb-3" data-lp-key="hiw_step2_sections_title"<?php echo hiw_block_style('hiw_step2_sections_title'); ?>><?php echo hiw_block('hiw_step2_sections_title','Application Form Sections:'); ?></h5>
            <div class="accordion" id="applicationSections">
              <div class="accordion-item">
                <h2 class="accordion-header">
                  <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#section1" data-lp-key="hiw_step2_accord1_title"<?php echo hiw_block_style('hiw_step2_accord1_title'); ?>><?php echo hiw_block('hiw_step2_accord1_title','Personal & Academic Information'); ?></button>
                </h2>
                <div id="section1" class="accordion-collapse collapse show" data-bs-parent="#applicationSections">
                  <div class="accordion-body small" data-lp-key="hiw_step2_accord1_desc"<?php echo hiw_block_style('hiw_step2_accord1_desc'); ?>><?php echo hiw_block('hiw_step2_accord1_desc','School name, course/grade level, year level, student ID, academic year, and semester information.'); ?></div>
                </div>
              </div>
              <div class="accordion-item">
                <h2 class="accordion-header">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#section2" data-lp-key="hiw_step2_accord2_title"<?php echo hiw_block_style('hiw_step2_accord2_title'); ?>><?php echo hiw_block('hiw_step2_accord2_title','Academic Details'); ?></button>
                </h2>
                <div id="section2" class="accordion-collapse collapse" data-bs-parent="#applicationSections">
                  <div class="accordion-body small" data-lp-key="hiw_step2_accord2_desc"<?php echo hiw_block_style('hiw_step2_accord2_desc'); ?>><?php echo hiw_block('hiw_step2_accord2_desc','University/college information, program enrolled, year level, student ID number, and current academic status.'); ?></div>
                </div>
              </div>
              <div class="accordion-item">
                <h2 class="accordion-header">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#section3" data-lp-key="hiw_step2_accord3_title"<?php echo hiw_block_style('hiw_step2_accord3_title'); ?>><?php echo hiw_block('hiw_step2_accord3_title','Document Upload'); ?></button>
                </h2>
                <div id="section3" class="accordion-collapse collapse" data-bs-parent="#applicationSections">
                  <div class="accordion-body small" data-lp-key="hiw_step2_accord3_desc"<?php echo hiw_block_style('hiw_step2_accord3_desc'); ?>><?php echo hiw_block('hiw_step2_accord3_desc','Clear photos or PDFs of required documents. Maximum 5MB per file. Accepted formats: JPG, PNG, PDF.'); ?></div>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="col-lg-6 order-lg-1">
          <div class="soft-card p-4">
            <img src="https://images.unsplash.com/photo-1554224155-8d04cb21cd6c?q=80&w=1000&auto=format&fit=crop" alt="Documents" class="img-fluid rounded mb-3" />
            <div class="alert alert-warning" data-lp-key="hiw_step2_important_note"<?php echo hiw_block_style('hiw_step2_important_note'); ?>><?php echo hiw_block('hiw_step2_important_note','<i class="bi bi-exclamation-triangle me-2"></i><strong>Important:</strong> Ensure all documents are clear, complete, and up-to-date. Blurry or incomplete documents will delay processing.'); ?></div>
          </div>
        </div>
      </div>

      <!-- Step 3 -->
      <div class="row g-5 align-items-center mb-5">
        <div class="col-lg-6">
          <div class="d-flex gap-3 mb-3">
            <div class="bg-info rounded-circle p-3 flex-shrink-0" style="width: 60px; height: 60px; display: flex; align-items: center; justify-content: center;">
              <span class="text-white fw-bold fs-4">3</span>
            </div>
            <div>
              <h3 class="fw-bold" data-lp-key="hiw_step3_detail_title"<?php echo hiw_block_style('hiw_step3_detail_title'); ?>><?php echo hiw_block('hiw_step3_detail_title','Evaluation & Approval Process'); ?></h3>
              <p class="text-info mb-0" data-lp-key="hiw_step3_detail_subtitle"<?php echo hiw_block_style('hiw_step3_detail_subtitle'); ?>><?php echo hiw_block('hiw_step3_detail_subtitle','Admin review and verification of your application'); ?></p>
            </div>
          </div>
          
          <div class="ps-5">
            <h5 class="fw-semibold mb-3" data-lp-key="hiw_step3_during_title"<?php echo hiw_block_style('hiw_step3_during_title'); ?>><?php echo hiw_block('hiw_step3_during_title','What happens during evaluation:'); ?></h5>
            <div class="timeline">
              <div class="d-flex gap-3 mb-3">
                <div class="bg-light rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 40px; height: 40px;">
                  <i class="bi bi-file-earmark-check text-info"></i>
                </div>
                <div>
                  <strong data-lp-key="hiw_step3_timeline1_title"<?php echo hiw_block_style('hiw_step3_timeline1_title'); ?>><?php echo hiw_block('hiw_step3_timeline1_title','Document Review (2-3 days)'); ?></strong>
                  <p class="text-body-secondary mb-0 small" data-lp-key="hiw_step3_timeline1_desc"<?php echo hiw_block_style('hiw_step3_timeline1_desc'); ?>><?php echo hiw_block('hiw_step3_timeline1_desc','Admin staff verify all uploaded documents for completeness and authenticity.'); ?></p>
                </div>
              </div>
              <div class="d-flex gap-3 mb-3">
                <div class="bg-light rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 40px; height: 40px;">
                  <i class="bi bi-search text-info"></i>
                </div>
                <div>
                  <strong data-lp-key="hiw_step3_timeline2_title"<?php echo hiw_block_style('hiw_step3_timeline2_title'); ?>><?php echo hiw_block('hiw_step3_timeline2_title','Eligibility Check (1-2 days)'); ?></strong>
                  <p class="text-body-secondary mb-0 small" data-lp-key="hiw_step3_timeline2_desc"<?php echo hiw_block_style('hiw_step3_timeline2_desc'); ?>><?php echo hiw_block('hiw_step3_timeline2_desc','Cross-reference with eligibility criteria and existing beneficiary database.'); ?></p>
                </div>
              </div>
              <div class="d-flex gap-3 mb-3">
                <div class="bg-light rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 40px; height: 40px;">
                  <i class="bi bi-person-check text-info"></i>
                </div>
                <div>
                  <strong data-lp-key="hiw_step3_timeline3_title"<?php echo hiw_block_style('hiw_step3_timeline3_title'); ?>><?php echo hiw_block('hiw_step3_timeline3_title','Final Approval (1 day)'); ?></strong>
                  <p class="text-body-secondary mb-0 small" data-lp-key="hiw_step3_timeline3_desc"<?php echo hiw_block_style('hiw_step3_timeline3_desc'); ?>><?php echo hiw_block('hiw_step3_timeline3_desc','Supervisor review and final decision on application status.'); ?></p>
                </div>
              </div>
            </div>
            
            <div class="alert alert-info mt-3" data-lp-key="hiw_step3_status_updates"<?php echo hiw_block_style('hiw_step3_status_updates'); ?>><?php echo hiw_block('hiw_step3_status_updates','<strong>Status Updates:</strong> You\'ll receive email notifications at each stage. Log in to your dashboard to see detailed status.'); ?></div>
          </div>
        </div>
        <div class="col-lg-6">
          <div class="soft-card p-4">
            <h5 class="fw-bold mb-3" data-lp-key="hiw_step3_status_title"<?php echo hiw_block_style('hiw_step3_status_title'); ?>><?php echo hiw_block('hiw_step3_status_title','Possible Application Status'); ?></h5>
            <div class="d-grid gap-2">
              <div class="d-flex justify-content-between align-items-center p-2 bg-warning bg-opacity-10 rounded">
                <span data-lp-key="hiw_step3_status1"<?php echo hiw_block_style('hiw_step3_status1'); ?>><?php echo hiw_block('hiw_step3_status1','<i class="bi bi-clock text-warning me-2"></i>Under Review'); ?></span>
                <small class="text-body-secondary" data-lp-key="hiw_step3_status1_label"<?php echo hiw_block_style('hiw_step3_status1_label'); ?>><?php echo hiw_block('hiw_step3_status1_label','In progress'); ?></small>
              </div>
              <div class="d-flex justify-content-between align-items-center p-2 bg-info bg-opacity-10 rounded">
                <span data-lp-key="hiw_step3_status2"<?php echo hiw_block_style('hiw_step3_status2'); ?>><?php echo hiw_block('hiw_step3_status2','<i class="bi bi-question-circle text-info me-2"></i>Needs Clarification'); ?></span>
                <small class="text-body-secondary" data-lp-key="hiw_step3_status2_label"<?php echo hiw_block_style('hiw_step3_status2_label'); ?>><?php echo hiw_block('hiw_step3_status2_label','Action required'); ?></small>
              </div>
              <div class="d-flex justify-content-between align-items-center p-2 bg-success bg-opacity-10 rounded">
                <span data-lp-key="hiw_step3_status3"<?php echo hiw_block_style('hiw_step3_status3'); ?>><?php echo hiw_block('hiw_step3_status3','<i class="bi bi-check-circle text-success me-2"></i>Approved'); ?></span>
                <small class="text-body-secondary" data-lp-key="hiw_step3_status3_label"<?php echo hiw_block_style('hiw_step3_status3_label'); ?>><?php echo hiw_block('hiw_step3_status3_label','Ready for QR'); ?></small>
              </div>
              <div class="d-flex justify-content-between align-items-center p-2 bg-danger bg-opacity-10 rounded">
                <span data-lp-key="hiw_step3_status4"<?php echo hiw_block_style('hiw_step3_status4'); ?>><?php echo hiw_block('hiw_step3_status4','<i class="bi bi-x-circle text-danger me-2"></i>Not Eligible'); ?></span>
                <small class="text-body-secondary" data-lp-key="hiw_step3_status4_label"<?php echo hiw_block_style('hiw_step3_status4_label'); ?>><?php echo hiw_block('hiw_step3_status4_label','Final decision'); ?></small>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Step 4 -->
      <div class="row g-5 align-items-center">
        <div class="col-lg-6 order-lg-2">
          <div class="d-flex gap-3 mb-3">
            <div class="bg-success rounded-circle p-3 flex-shrink-0" style="width: 60px; height: 60px; display: flex; align-items: center; justify-content: center;">
              <span class="text-white fw-bold fs-4">4</span>
            </div>
            <div>
              <h3 class="fw-bold" data-lp-key="hiw_step4_detail_title"<?php echo hiw_block_style('hiw_step4_detail_title'); ?>><?php echo hiw_block('hiw_step4_detail_title','QR Code Generation & Claiming'); ?></h3>
              <p class="text-success mb-0" data-lp-key="hiw_step4_detail_subtitle"<?php echo hiw_block_style('hiw_step4_detail_subtitle'); ?>><?php echo hiw_block('hiw_step4_detail_subtitle','Receiving your assistance on distribution day'); ?></p>
            </div>
          </div>
          
          <div class="ps-5">
            <h5 class="fw-semibold mb-3" data-lp-key="hiw_step4_after_title"<?php echo hiw_block_style('hiw_step4_after_title'); ?>><?php echo hiw_block('hiw_step4_after_title','After approval:'); ?></h5>
            <div class="d-grid gap-3">
              <div class="soft-card p-3">
                <h6 class="fw-bold text-success mb-2" data-lp-key="hiw_step4_card1_title"<?php echo hiw_block_style('hiw_step4_card1_title'); ?>><?php echo hiw_block('hiw_step4_card1_title','<i class="bi bi-qr-code me-2"></i>QR Code Ready'); ?></h6>
                <p class="small mb-0" data-lp-key="hiw_step4_card1_desc"<?php echo hiw_block_style('hiw_step4_card1_desc'); ?>><?php echo hiw_block('hiw_step4_card1_desc','Download your unique QR code from your dashboard. You can also receive it via email.'); ?></p>
              </div>
              <div class="soft-card p-3">
                <h6 class="fw-bold text-primary mb-2" data-lp-key="hiw_step4_card2_title"<?php echo hiw_block_style('hiw_step4_card2_title'); ?>><?php echo hiw_block('hiw_step4_card2_title','<i class="bi bi-calendar-event me-2"></i>Distribution Schedule'); ?></h6>
                <p class="small mb-0" data-lp-key="hiw_step4_card2_desc"<?php echo hiw_block_style('hiw_step4_card2_desc'); ?>><?php echo hiw_block('hiw_step4_card2_desc','You\'ll receive notification about the date, time, and venue for assistance distribution.'); ?></p>
              </div>
              <div class="soft-card p-3">
                <h6 class="fw-bold text-warning mb-2" data-lp-key="hiw_step4_card3_title"<?php echo hiw_block_style('hiw_step4_card3_title'); ?>><?php echo hiw_block('hiw_step4_card3_title','<i class="bi bi-card-checklist me-2"></i>What to Bring'); ?></h6>
                <ul class="small mb-0 ps-3">
                  <li data-lp-key="hiw_step4_bring1"<?php echo hiw_block_style('hiw_step4_bring1'); ?>><?php echo hiw_block('hiw_step4_bring1','Your QR code (printed or on phone)'); ?></li>
                  <li data-lp-key="hiw_step4_bring2"<?php echo hiw_block_style('hiw_step4_bring2'); ?>><?php echo hiw_block('hiw_step4_bring2','Valid school ID'); ?></li>
                  <li data-lp-key="hiw_step4_bring3"<?php echo hiw_block_style('hiw_step4_bring3'); ?>><?php echo hiw_block('hiw_step4_bring3','One government-issued ID'); ?></li>
                </ul>
              </div>
            </div>
          </div>
        </div>
        <div class="col-lg-6 order-lg-1">
          <div class="soft-card p-4 text-center">
            <div class="bg-success bg-opacity-10 rounded p-4 mb-3">
              <i class="bi bi-qr-code display-1 text-success"></i>
            </div>
            <h5 class="fw-bold" data-lp-key="hiw_step4_sample_title"<?php echo hiw_block_style('hiw_step4_sample_title'); ?>><?php echo hiw_block('hiw_step4_sample_title','Sample QR Code'); ?></h5>
            <p class="text-body-secondary small" data-lp-key="hiw_step4_sample_desc"<?php echo hiw_block_style('hiw_step4_sample_desc'); ?>><?php echo hiw_block('hiw_step4_sample_desc','Each student gets a unique, secure QR code linked to their approved application.'); ?></p>
            <div class="alert alert-success" data-lp-key="hiw_step4_secure_note"<?php echo hiw_block_style('hiw_step4_secure_note'); ?>><?php echo hiw_block('hiw_step4_secure_note','<i class="bi bi-shield-check me-2"></i><strong>Secure & Fraud-Proof:</strong> QR codes are encrypted and single-use to prevent duplication or misuse.'); ?></div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Tips & Best Practices -->
  <section class="py-5">
    <div class="container">
      <div class="text-center mb-5">
        <h2 class="section-title" data-lp-key="hiw_tips_title"<?php echo hiw_block_style('hiw_tips_title'); ?>><?php echo hiw_block('hiw_tips_title','Tips for Success'); ?></h2>
        <p class="section-lead mx-auto" style="max-width: 700px;" data-lp-key="hiw_tips_lead"<?php echo hiw_block_style('hiw_tips_lead'); ?>><?php echo hiw_block('hiw_tips_lead','Best practices to ensure smooth application processing'); ?></p>
      </div>
      <div class="row g-4">
        <div class="col-md-6 col-lg-4">
          <div class="soft-card p-4 h-100">
            <div class="text-success mb-3">
              <i class="bi bi-camera fs-2"></i>
            </div>
            <h5 class="fw-bold" data-lp-key="hiw_tips_card1_title"<?php echo hiw_block_style('hiw_tips_card1_title'); ?>><?php echo hiw_block('hiw_tips_card1_title','Document Quality'); ?></h5>
            <ul class="small text-body-secondary">
              <li data-lp-key="hiw_tips_card1_item1"<?php echo hiw_block_style('hiw_tips_card1_item1'); ?>><?php echo hiw_block('hiw_tips_card1_item1','Use good lighting when taking photos'); ?></li>
              <li data-lp-key="hiw_tips_card1_item2"<?php echo hiw_block_style('hiw_tips_card1_item2'); ?>><?php echo hiw_block('hiw_tips_card1_item2','Ensure text is clearly readable'); ?></li>
              <li data-lp-key="hiw_tips_card1_item3"<?php echo hiw_block_style('hiw_tips_card1_item3'); ?>><?php echo hiw_block('hiw_tips_card1_item3','Avoid shadows or glare'); ?></li>
              <li data-lp-key="hiw_tips_card1_item4"<?php echo hiw_block_style('hiw_tips_card1_item4'); ?>><?php echo hiw_block('hiw_tips_card1_item4','Take photos straight-on, not at angles'); ?></li>
            </ul>
          </div>
        </div>
        <div class="col-md-6 col-lg-4">
          <div class="soft-card p-4 h-100">
            <div class="text-primary mb-3">
              <i class="bi bi-clock fs-2"></i>
            </div>
            <h5 class="fw-bold" data-lp-key="hiw_tips_card2_title"<?php echo hiw_block_style('hiw_tips_card2_title'); ?>><?php echo hiw_block('hiw_tips_card2_title','Timing'); ?></h5>
            <ul class="small text-body-secondary">
              <li data-lp-key="hiw_tips_card2_item1"<?php echo hiw_block_style('hiw_tips_card2_item1'); ?>><?php echo hiw_block('hiw_tips_card2_item1','Apply early when slots open'); ?></li>
              <li data-lp-key="hiw_tips_card2_item2"<?php echo hiw_block_style('hiw_tips_card2_item2'); ?>><?php echo hiw_block('hiw_tips_card2_item2','Don\'t wait until deadlines'); ?></li>
              <li data-lp-key="hiw_tips_card2_item3"<?php echo hiw_block_style('hiw_tips_card2_item3'); ?>><?php echo hiw_block('hiw_tips_card2_item3','Check announcements regularly'); ?></li>
              <li data-lp-key="hiw_tips_card2_item4"<?php echo hiw_block_style('hiw_tips_card2_item4'); ?>><?php echo hiw_block('hiw_tips_card2_item4','Respond quickly to admin requests'); ?></li>
            </ul>
          </div>
        </div>
        <div class="col-md-6 col-lg-4">
          <div class="soft-card p-4 h-100">
            <div class="text-warning mb-3">
              <i class="bi bi-shield-check fs-2"></i>
            </div>
            <h5 class="fw-bold" data-lp-key="hiw_tips_card3_title"<?php echo hiw_block_style('hiw_tips_card3_title'); ?>><?php echo hiw_block('hiw_tips_card3_title','Security'); ?></h5>
            <ul class="small text-body-secondary">
              <li data-lp-key="hiw_tips_card3_item1"<?php echo hiw_block_style('hiw_tips_card3_item1'); ?>><?php echo hiw_block('hiw_tips_card3_item1','Keep login credentials secure'); ?></li>
              <li data-lp-key="hiw_tips_card3_item2"<?php echo hiw_block_style('hiw_tips_card3_item2'); ?>><?php echo hiw_block('hiw_tips_card3_item2','Don\'t share QR codes'); ?></li>
              <li data-lp-key="hiw_tips_card3_item3"<?php echo hiw_block_style('hiw_tips_card3_item3'); ?>><?php echo hiw_block('hiw_tips_card3_item3','Log out after using public computers'); ?></li>
              <li data-lp-key="hiw_tips_card3_item4"<?php echo hiw_block_style('hiw_tips_card3_item4'); ?>><?php echo hiw_block('hiw_tips_card3_item4','Report suspicious activity immediately'); ?></li>
            </ul>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- CTA Section -->
  <section class="py-5 bg-primary text-white">
    <div class="container text-center">
      <h2 class="fw-bold mb-3" data-lp-key="hiw_cta_title"<?php echo hiw_block_style('hiw_cta_title'); ?>><?php echo hiw_block('hiw_cta_title','Ready to Get Started?'); ?></h2>
      <p class="lead mb-4" data-lp-key="hiw_cta_lead"<?php echo hiw_block_style('hiw_cta_lead'); ?>><?php echo hiw_block('hiw_cta_lead','Join thousands of General Trias students who have successfully received educational assistance through EducAid.'); ?></p>
      <div class="d-flex gap-3 justify-content-center flex-wrap">
        <a href="landingpage.php#apply" class="btn btn-light btn-lg">
          <span data-lp-key="hiw_cta_btn1"<?php echo hiw_block_style('hiw_cta_btn1'); ?>><?php echo hiw_block('hiw_cta_btn1','<i class="bi bi-journal-text me-2"></i>Start Your Application'); ?></span>
        </a>
        <a href="requirements.php" class="btn btn-outline-light btn-lg">
          <span data-lp-key="hiw_cta_btn2"<?php echo hiw_block_style('hiw_cta_btn2'); ?>><?php echo hiw_block('hiw_cta_btn2','<i class="bi bi-list-check me-2"></i>View Requirements'); ?></span>
        </a>
      </div>
    </div>
  </section>

  <!-- Footer - Dynamic CMS Controlled -->
  <?php include __DIR__ . '/../includes/website/footer.php'; ?>

  <!-- Chatbot Widget -->
<div class="ea-chat">
  <button class="ea-chat__toggle" id="eaToggle">
    <i class="bi bi-chat-dots-fill"></i>
    Chat with EducAid
  </button>
  <div class="ea-chat__panel" id="eaPanel">
    <div class="ea-chat__header">
      <span>🤖 EducAid Assistant</span>
      <button class="ea-chat__close" id="eaClose" aria-label="Close chat">×</button>
    </div>
    <div class="ea-chat__body" id="eaBody">
      <div class="ea-chat__msg">
        <div class="ea-chat__bubble">
          👋 Hi! I can help with EducAid requirements, schedules, and account questions.
        </div>
      </div>
      <div class="ea-typing" id="eaTyping">EducAid Assistant is typing</div>
    </div>
    <div class="ea-chat__footer">
      <input class="ea-chat__input" id="eaInput" placeholder="Type your message…" />
      <button class="ea-chat__send" id="eaSend">Send</button>
    </div>
  </div>
</div>

  <script>
    // Current year
    document.getElementById('year').textContent = new Date().getFullYear();
    
    // Enhanced scroll animations
    const observerOptions = {
      threshold: 0.1,
      rootMargin: '0px 0px -5% 0px'
    };
    
    const observer = new IntersectionObserver((entries) => {
      entries.forEach((entry, index) => {
        if (entry.isIntersecting) {
          setTimeout(() => {
            entry.target.style.opacity = '1';
            entry.target.style.transform = 'translateY(0)';
          }, index * 100);
        }
      });
    }, observerOptions);
    
    // Observe all cards with staggered animation
    document.querySelectorAll('.soft-card').forEach((el, index) => {
      el.style.opacity = '0';
      el.style.transform = 'translateY(30px)';
      el.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
      observer.observe(el);
    });
  </script>

  <!-- Chatbot script -->
<script>
// Enhanced EducAid Chatbot
document.addEventListener('DOMContentLoaded', function() {
  const apiUrl = 'chatbot/gemini_chat_fast.php'; // Fast single-model chatbot
  const toggle = document.getElementById('eaToggle');
  const panel  = document.getElementById('eaPanel');
  const close  = document.getElementById('eaClose');
  const body   = document.getElementById('eaBody');
  const input  = document.getElementById('eaInput');
  const send   = document.getElementById('eaSend');
  const typing = document.getElementById('eaTyping');

  let isOpen = false;

  // Toggle chatbot panel
  function toggleChat() {
    isOpen = !isOpen;
    panel.style.display = isOpen ? 'block' : 'none';
    if (isOpen) {
      input.focus();
    }
  }

  // Event listeners
  toggle.addEventListener('click', toggleChat);
  close.addEventListener('click', toggleChat);

  // Send message function
  async function sendMsg() {
    const text = input.value.trim();
    if (!text) return;
    
    input.value = '';
    input.disabled = true;

    // Add user message
    const userMsg = document.createElement('div');
    userMsg.className = 'ea-chat__msg ea-chat__msg--user';
    userMsg.innerHTML = `<div class="ea-chat__bubble ea-chat__bubble--user"></div>`;
    userMsg.querySelector('.ea-chat__bubble').textContent = text;
    body.appendChild(userMsg);
    body.scrollTop = body.scrollHeight;

    // Show typing indicator
    typing.style.display = 'block';

    try {
      const res = await fetch(apiUrl, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ message: text })
      });

      if (!res.ok) {
        throw new Error(`HTTP ${res.status}`);
      }

      const data = await res.json();
      const reply = data.reply || 'Sorry, I could not understand that.';

      // Add bot response
      const botMsg = document.createElement('div');
      botMsg.className = 'ea-chat__msg';
      botMsg.innerHTML = `<div class="ea-chat__bubble"></div>`;
      const formattedReply = formatChatbotResponse(reply);
      botMsg.querySelector('.ea-chat__bubble').innerHTML = formattedReply;
      body.appendChild(botMsg);

    } catch (error) {
      console.error('Chatbot error:', error);
      
      // Add error message
      const errMsg = document.createElement('div');
      errMsg.className = 'ea-chat__msg';
      errMsg.innerHTML = `<div class="ea-chat__bubble">Sorry, I'm having trouble connecting. Please try again later or contact support at educaid@generaltrias.gov.ph</div>`;
      body.appendChild(errMsg);
      
    } finally {
      // Hide typing indicator and re-enable input
      typing.style.display = 'none';
      input.disabled = false;
      input.focus();
      body.scrollTop = body.scrollHeight;
    }
  }

  // Event listeners for sending messages
  send.addEventListener('click', sendMsg);
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      sendMsg();
    }
  });

  // Close chat when clicking outside
  document.addEventListener('click', (e) => {
    if (!e.target.closest('.ea-chat') && isOpen) {
      toggleChat();
    }
  });
});

// ALTERNATIVE: Simpler formatting function
function formatChatbotResponse(text) {
  return text
    // Clean up single asterisks first (remove them)
    .replace(/(?<!\*)\*(?!\*)/g, '')
    
    // Convert bold headers with colons - add spacing class
    .replace(/\*\*([^:]+):\*\*/g, '<div class="req-header-spaced"><strong>$1:</strong></div>')
    .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
    
    // Convert bullet points/dashes to list items
    .replace(/^[-•]\s*(.+)$/gm, '<div class="req-item">$1</div>')
    
    // Handle line breaks - keep double breaks as section separators
    .replace(/\n\n+/g, '<div class="req-spacer"></div>')
    .replace(/\n/g, '<br>')
    
    // Clean up any remaining asterisks
    .replace(/\*/g, '');
}
</script>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <!-- Mobile Navbar JS -->
  <script src="../assets/js/website/mobile-navbar.js"></script>
  <?php if($IS_EDIT_MODE): ?>
  <script src="../assets/js/website/content_editor.js"></script>
  <script>
  // Initialize shared ContentEditor for How It Works page
  ContentEditor.init({
    page: 'how-it-works',
    pageTitle: 'How It Works Page',
    saveEndpoint: 'ajax_save_hiw_content.php',
    resetAllEndpoint: 'ajax_reset_hiw_content.php',
    history: { fetchEndpoint: 'ajax_get_hiw_history.php', rollbackEndpoint: 'ajax_rollback_hiw_block.php' },
    refreshAfterSave: async (keys)=>{
      try {
        const r = await fetch('ajax_get_hiw_blocks.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({keys})});
        const d = await r.json(); if(!d.success) return;
        (d.blocks||[]).forEach(b=>{ const el=document.querySelector('[data-lp-key="'+CSS.escape(b.block_key)+'"]'); if(!el) return; el.innerHTML=b.html; if(b.text_color) el.style.color=b.text_color; else el.style.removeProperty('color'); if(b.bg_color) el.style.backgroundColor=b.bg_color; else el.style.removeProperty('background-color'); });
      } catch(err){ console.error('Refresh error', err); }
    }
  });
  </script>
  <?php endif; ?>

  <?php 
  // Anti-FOUC scripts for smooth page transitions
  include __DIR__ . '/../includes/website/anti_fouc_scripts.php'; 
  ?>
</body>
</html>