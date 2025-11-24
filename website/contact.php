<?php
// Dedicated Contact & Helpdesk page
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check for Edit Mode (Super Admin only)
$IS_EDIT_MODE = false;
$IS_EDIT_SUPER_ADMIN = false;

// Load database and permissions
@include_once __DIR__ . '/../config/database.php';
@include_once __DIR__ . '/../includes/CSRFProtection.php';
@include_once __DIR__ . '/../includes/permissions.php';

// Check if user is super admin AND in edit mode
if (isset($_GET['edit']) && $_GET['edit'] == '1') {
    if (isset($_SESSION['admin_id']) && function_exists('getCurrentAdminRole')) {
        $role = @getCurrentAdminRole($connection);
        if ($role === 'super_admin') {
            $IS_EDIT_SUPER_ADMIN = true;
            $IS_EDIT_MODE = true;
        }
    }
}

// Load content helper for editable blocks
if (!isset($connection)) {
    require_once __DIR__ . '/../config/database.php';
}
require_once __DIR__ . '/../includes/website/contact_content_helper.php';

// PHPMailer for sending emails to admins
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require_once __DIR__ . '/../phpmailer/vendor/autoload.php';

$errors = [];
$successMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_inquiry'])) {
    // CSRF Protection
    $token = $_POST['csrf_token'] ?? '';
    if (!CSRFProtection::validateToken('contact_form', $token)) {
        $errors[] = 'Security validation failed. Please refresh the page and try again.';
    } else {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $subject = trim($_POST['subject'] ?? '');
        $message = trim($_POST['message'] ?? '');

        if ($name === '' || strlen($name) < 2) $errors[] = 'Name is required.';
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email required.';
        if ($subject === '' || strlen($subject) < 3) $errors[] = 'Subject must be at least 3 characters.';
        if ($message === '' || strlen($message) < 10) $errors[] = 'Message must be at least 10 characters.';

        if (!$errors) {
            // Log the inquiry
            $entry = [
                'ts' => date('c'),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                'ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? '',0,180),
                'name' => $name,
                'email' => $email,
                'subject' => $subject,
                'message' => $message
            ];
            $logFile = __DIR__ . '/../data/contact_messages.log';
            @mkdir(dirname($logFile), 0775, true);
            @file_put_contents($logFile, json_encode($entry, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
            
            // Send email notification to all active admins
            try {
                // Get all active admin emails
                $adminQuery = "SELECT email, first_name, last_name FROM admins WHERE is_active = TRUE AND email IS NOT NULL AND email != ''";
                $adminResult = pg_query($connection, $adminQuery);
                
                if ($adminResult && pg_num_rows($adminResult) > 0) {
                    $mail = new PHPMailer(true);
                    
                    // SMTP Configuration from environment variables
                    $mail->isSMTP();
                    $mail->Host       = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
                    $mail->SMTPAuth   = true;
                    $mail->Username   = getenv('SMTP_USERNAME') ?: 'dilucayaka02@gmail.com';
                    $mail->Password   = getenv('SMTP_PASSWORD') ?: 'jlldeyglhksjflvg';
                    $encryption       = getenv('SMTP_ENCRYPTION') ?: 'tls';
                    
                    if (strtolower($encryption) === 'ssl') {
                        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                    } else {
                        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    }
                    $mail->Port       = (int)(getenv('SMTP_PORT') ?: 587);
                    
                    // From address
                    $fromEmail = getenv('SMTP_FROM_EMAIL') ?: ($mail->Username ?: 'noreply@educaid.local');
                    $fromName  = getenv('SMTP_FROM_NAME')  ?: 'EducAid Contact Form';
                    $mail->setFrom($fromEmail, $fromName);
                    
                    // Add reply-to as the sender's email
                    $mail->addReplyTo($email, $name);
                    
                    // Add all active admins as recipients
                    while ($admin = pg_fetch_assoc($adminResult)) {
                        $adminFullName = trim(($admin['first_name'] ?? '') . ' ' . ($admin['last_name'] ?? ''));
                        $mail->addAddress($admin['email'], $adminFullName);
                    }
                    
                    // Email content
                    $mail->isHTML(true);
                    $mail->Subject = 'EducAid Contact Form: ' . htmlspecialchars($subject);
                    
                    $mail->Body = "
                        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                            <div style='background: #0d6efd; color: white; padding: 20px; border-radius: 5px 5px 0 0;'>
                                <h2 style='margin: 0;'>📬 New Contact Form Submission</h2>
                            </div>
                            
                            <div style='padding: 20px; background: #f8f9fa; border: 1px solid #dee2e6;'>
                                <div style='margin-bottom: 15px;'>
                                    <strong>From:</strong> " . htmlspecialchars($name) . "<br>
                                    <strong>Email:</strong> <a href='mailto:" . htmlspecialchars($email) . "'>" . htmlspecialchars($email) . "</a><br>
                                    <strong>Date:</strong> " . date('F j, Y g:i A') . "
                                </div>
                                
                                <div style='margin-bottom: 15px;'>
                                    <strong>Subject:</strong><br>
                                    <div style='background: white; padding: 10px; border-left: 4px solid #0d6efd; margin-top: 5px;'>
                                        " . nl2br(htmlspecialchars($subject)) . "
                                    </div>
                                </div>
                                
                                <div style='margin-bottom: 15px;'>
                                    <strong>Message:</strong><br>
                                    <div style='background: white; padding: 15px; border-left: 4px solid #0d6efd; margin-top: 5px;'>
                                        " . nl2br(htmlspecialchars($message)) . "
                                    </div>
                                </div>
                                
                                <hr style='border: none; border-top: 1px solid #dee2e6; margin: 20px 0;'>
                                
                                <div style='font-size: 12px; color: #6c757d;'>
                                    <strong>Technical Details:</strong><br>
                                    IP Address: " . htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? 'Unknown') . "<br>
                                    Timestamp: " . date('c') . "
                                </div>
                            </div>
                            
                            <div style='padding: 15px; background: #e9ecef; border-radius: 0 0 5px 5px; text-align: center; font-size: 12px; color: #6c757d;'>
                                This is an automated message from the EducAid contact form.<br>
                                To respond, simply reply to this email.
                            </div>
                        </div>
                    ";
                    
                    $mail->AltBody = "New Contact Form Submission\n\n"
                        . "From: $name\n"
                        . "Email: $email\n"
                        . "Date: " . date('F j, Y g:i A') . "\n\n"
                        . "Subject:\n$subject\n\n"
                        . "Message:\n$message\n\n"
                        . "---\n"
                        . "IP Address: " . ($_SERVER['REMOTE_ADDR'] ?? 'Unknown') . "\n"
                        . "Timestamp: " . date('c');
                    
                    $mail->send();
                    error_log("Contact form email sent successfully to " . pg_num_rows($adminResult) . " admin(s)");
                }
            } catch (Exception $e) {
                error_log("Failed to send contact form email: " . $e->getMessage());
                // Don't show email errors to users, but log them
            }
            
            $successMsg = 'Your inquiry was received. A staff member may reach out via email if needed.';
            $_POST = []; // clear form data
        }
    }
}

function esc($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

// SEO Configuration
require_once __DIR__ . '/../includes/seo_helpers.php';
$seoData = getSEOData('contact');
$pageTitle = $seoData['title'];
$pageDescription = $seoData['description'];
$pageKeywords = $seoData['keywords'];
$pageImage = 'https://www.educ-aid.site' . $seoData['image'];
$pageUrl = 'https://www.educ-aid.site/website/contact.php';
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
  
  <link href="../assets/css/bootstrap.min.css" rel="stylesheet" />
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
        'page_title' => 'Contact Page',
        'exit_url' => 'contact.php'
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
  
  // Custom navigation for contact page
  $custom_nav_links = [
    ['href' => 'landingpage.php', 'label' => 'Home', 'active' => false],
    ['href' => 'about.php', 'label' => 'About', 'active' => false],
    ['href' => 'how-it-works.php', 'label' => 'How it works', 'active' => false],
    ['href' => 'requirements.php', 'label' => 'Requirements', 'active' => false],
    ['href' => 'announcements.php', 'label' => 'Announcements', 'active' => false],
    ['href' => 'contact.php', 'label' => 'Contact', 'active' => true]
  ];
  
  include __DIR__ . '/../includes/website/topbar.php';
  include __DIR__ . '/../includes/website/navbar.php';
  include __DIR__ . '/../includes/website/cookie_consent.php';
  ?>

  <style>
    /* Minimal page-specific tweaks (keep global hero + design consistent) */
    .contact-badge { font-size:.65rem; letter-spacing:.5px; }
    .info-card, .inquiry-card { background:#fff; border:1px solid #e2e8f0; border-radius:1rem; padding:1.2rem 1.25rem; height:100%; box-shadow:0 4px 14px -6px rgba(0,0,0,.06); }
    .info-card h6 { font-weight:600; margin-bottom:.4rem; }
    .inquiry-card { box-shadow:0 8px 22px -10px rgba(0,0,0,.12); }
    .inquiry-card textarea { resize:vertical; min-height:150px; }
    .log-note { font-size:.65rem; color:#64748b; }
    .map-wrapper { border-radius:1rem; overflow:hidden; box-shadow:0 6px 18px -10px rgba(0,0,0,.18); }
    .fade-in { opacity:0; transform:translateY(10px); transition:all .5s ease; }
    .fade-in.visible { opacity:1; transform:none; }
    /* Ensure hero padding mirrors landing page (override earlier custom) */
    header.hero { padding:3rem 0; min-height:55vh; }
    @media (max-width: 768px){ header.hero { min-height:50vh; padding:2.2rem 0 2rem; } }
  </style>

<!-- Hero (centered, clean design for contact page) -->
<header id="home" class="hero">
  <div class="container">
    <div class="row align-items-center justify-content-center">
      <div class="col-12 col-lg-8">
        <div class="hero-card text-center fade-in">
          <?php contact_block('hero_title', 'Contact', 'h1', 'display-4 fw-bold mb-3'); ?>
          <?php contact_block('hero_subtitle', 'We\'re here to assist with application issues, document submission, schedules, QR release, and portal access concerns.', 'p', 'lead mb-4'); ?>
          <div class="d-flex gap-2 justify-content-center flex-wrap">
            <a href="landingpage.php" class="btn btn-outline-custom cta-btn"><i class="bi bi-house me-2"></i>Back to Home</a>
            <a href="#inquiry" class="btn btn-primary-custom cta-btn"><i class="bi bi-chat-dots me-2"></i>Send Inquiry</a>
          </div>
        </div>
      </div>
    </div>
  </div>
</header>

<!-- Contact Information & Location -->
<section class="py-5" id="contact-info">
  <div class="container">
    <!-- Primary Contact Methods -->
    <div class="row g-4 mb-5 fade-in">
      <div class="col-md-4">
        <div class="info-card h-100 text-center">
          <div class="mb-3"><i class="bi bi-geo-alt-fill text-primary" style="font-size:2.5rem;"></i></div>
          <?php contact_block('visit_title', 'Visit Us', 'h5', 'fw-bold mb-2'); ?>
          <?php contact_block('visit_address', 'City Government of General Trias, Cavite', 'p', 'small mb-2'); ?>
          <?php contact_block('visit_hours', 'Mon–Fri • 8:00 AM – 5:00 PM<br/>(excluding holidays)', 'p', 'small text-body-secondary mb-0'); ?>
        </div>
      </div>
      <div class="col-md-4">
        <div class="info-card h-100 text-center">
          <div class="mb-3"><i class="bi bi-telephone-fill text-success" style="font-size:2.5rem;"></i></div>
          <?php contact_block('call_title', 'Call Us', 'h5', 'fw-bold mb-2'); ?>
          <?php contact_block('call_primary', '(046) 886-4454', 'p', 'small mb-1'); ?>
          <?php contact_block('call_secondary', '(046) 509-5555 (Operator)', 'p', 'small text-body-secondary mb-0'); ?>
        </div>
      </div>
      <div class="col-md-4">
        <div class="info-card h-100 text-center">
          <div class="mb-3"><i class="bi bi-envelope-fill text-danger" style="font-size:2.5rem;"></i></div>
          <?php contact_block('email_title', 'Email Us', 'h5', 'fw-bold mb-2'); ?>
          <?php contact_block('email_primary', 'educaid@generaltrias.gov.ph', 'p', 'small mb-1'); ?>
          <?php contact_block('email_secondary', 'support@ (coming soon)', 'p', 'small text-body-secondary mb-0'); ?>
        </div>
      </div>
    </div>

    <!-- Map -->
    <div class="row justify-content-center mb-5 fade-in">
      <div class="col-12 col-lg-10">
        <div class="soft-card overflow-hidden">
          <div class="map-wrapper" style="height:400px;">
            <iframe title="General Trias City Hall Location" width="100%" height="100%" style="border:0" loading="lazy" allowfullscreen referrerpolicy="no-referrer-when-downgrade" src="https://www.google.com/maps?q=9VPJ+F9Q,+General+Trias,+Cavite&output=embed&z=17"></iframe>
          </div>
        </div>
      </div>
    </div>

    <!-- Inquiry Form & Help Information -->
    <div class="row g-4 fade-in" id="inquiry">
      <div class="col-lg-7">
        <div class="inquiry-card h-100">
          <div class="mb-4">
            <div class="d-flex align-items-center gap-3 mb-3">
              <div><i class="bi bi-chat-dots-fill text-primary" style="font-size:2.5rem;"></i></div>
              <div>
                <?php contact_block('form_title', 'Send an Inquiry', 'h3', 'fw-bold mb-1'); ?>
                <?php contact_block('form_subtitle', 'Have a question? Fill out the form below and we\'ll get back to you.', 'p', 'text-body-secondary small mb-0'); ?>
              </div>
            </div>
          </div>
          <?php if ($errors): ?>
            <div class="alert alert-danger py-2 mb-3">
              <ul class="m-0 ps-3 small">
                <?php foreach ($errors as $er): ?>
                  <li><?= esc($er) ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>
          <?php if ($successMsg): ?>
            <div class="alert alert-success py-2 mb-3"><?= esc($successMsg) ?></div>
          <?php endif; ?>
          <form method="POST" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo CSRFProtection::generateToken('contact_form'); ?>">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
                <input type="text" name="name" class="form-control" value="<?= esc($_POST['name'] ?? '') ?>" placeholder="Your full name" required />
              </div>
              <div class="col-md-6">
                <label class="form-label fw-semibold">Email <span class="text-danger">*</span></label>
                <input type="email" name="email" class="form-control" value="<?= esc($_POST['email'] ?? '') ?>" placeholder="your.email@example.com" required />
              </div>
            </div>
            <div class="mb-3 mt-3">
              <label class="form-label fw-semibold">Subject <span class="text-danger">*</span></label>
              <input type="text" name="subject" class="form-control" value="<?= esc($_POST['subject'] ?? '') ?>" placeholder="Brief topic of your inquiry" required />
            </div>
            <div class="mb-3">
              <label class="form-label fw-semibold">Message <span class="text-danger">*</span></label>
              <textarea name="message" class="form-control" rows="7" placeholder="Describe your question or concern..." required><?= esc($_POST['message'] ?? '') ?></textarea>
            </div>
            <div class="d-grid">
              <button type="submit" name="submit_inquiry" class="btn btn-primary btn-lg"><i class="bi bi-send me-2"></i>Send Inquiry</button>
            </div>
            <p class="log-note text-center mt-3 mb-0"><i class="bi bi-shield-check me-1"></i> Your inquiry is logged securely. Email notifications will be enabled soon.</p>
          </form>
        </div>
      </div>
      <div class="col-lg-5">
        <div class="info-card h-100">
          <?php contact_block('help_title', 'Before You Contact', 'h5', 'fw-bold mb-3'); ?>
          <?php contact_block('help_intro', 'Many common questions can be answered quickly through our self-help resources:', 'p', 'small text-body-secondary mb-3'); ?>
          <ul class="list-unstyled mb-4 d-grid gap-2">
            <li>
              <a href="landingpage.php#faq" class="link-primary text-decoration-none d-flex align-items-start gap-2">
                <i class="bi bi-question-circle-fill mt-1"></i>
                <div><strong>Frequently Asked Questions</strong><br/><span class="small text-body-secondary">Common queries about eligibility, slots, and claims</span></div>
              </a>
            </li>
            <li>
              <a href="requirements.php" class="link-primary text-decoration-none d-flex align-items-start gap-2">
                <i class="bi bi-list-check mt-1"></i>
                <div><strong>Requirements Guide</strong><br/><span class="small text-body-secondary">Complete list of documents needed</span></div>
              </a>
            </li>
            <li>
              <a href="how-it-works.php" class="link-primary text-decoration-none d-flex align-items-start gap-2">
                <i class="bi bi-diagram-3 mt-1"></i>
                <div><strong>Application Process</strong><br/><span class="small text-body-secondary">Step-by-step guide from registration to claiming</span></div>
              </a>
            </li>
            <li>
              <a href="announcements.php" class="link-primary text-decoration-none d-flex align-items-start gap-2">
                <i class="bi bi-megaphone mt-1"></i>
                <div><strong>Latest Announcements</strong><br/><span class="small text-body-secondary">Updates on schedules, deadlines, and events</span></div>
              </a>
            </li>
          </ul>
          <div class="p-3 rounded bg-body-tertiary">
            <?php contact_block('response_time_title', 'Response Time', 'h6', 'fw-bold mb-2'); ?>
            <?php contact_block('response_time_text', 'We aim to respond to inquiries within 1-2 business days during office hours (Mon-Fri, 8:00 AM - 5:00 PM).', 'p', 'small mb-0'); ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Additional Information -->
    <div class="row g-4 mt-5 fade-in">
      <div class="col-lg-6">
        <div class="info-card h-100">
          <?php contact_block('offices_title', 'Program Offices', 'h5', 'fw-bold mb-3'); ?>
          <ul class="list-unstyled mb-0 d-grid gap-2">
            <li class="d-flex gap-2">
              <i class="bi bi-dot text-primary" style="font-size:1.5rem;margin-top:-5px;"></i>
              <div><strong>Youth / Scholarship Desk:</strong><br/><span class="small text-body-secondary">Local Youth & Development Office</span></div>
            </li>
            <li class="d-flex gap-2">
              <i class="bi bi-dot text-primary" style="font-size:1.5rem;margin-top:-5px;"></i>
              <div><strong>Document Validation:</strong><br/><span class="small text-body-secondary">Records & Compliance Department</span></div>
            </li>
            <li class="d-flex gap-2">
              <i class="bi bi-dot text-primary" style="font-size:1.5rem;margin-top:-5px;"></i>
              <div><strong>Distribution / Release:</strong><br/><span class="small text-body-secondary">Treasurer's Office • Mayor's Office</span></div>
            </li>
          </ul>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="info-card h-100">
          <?php contact_block('topics_title', 'Common Topics', 'h5', 'fw-bold mb-3'); ?>
          <ul class="list-unstyled mb-3 d-grid gap-2">
            <li><i class="bi bi-check-circle-fill text-success me-2"></i>Registration & slot availability</li>
            <li><i class="bi bi-check-circle-fill text-success me-2"></i>Document upload & verification</li>
            <li><i class="bi bi-check-circle-fill text-success me-2"></i>Schedule announcements</li>
            <li><i class="bi bi-check-circle-fill text-success me-2"></i>QR code release / lost access</li>
            <li><i class="bi bi-check-circle-fill text-success me-2"></i>Account recovery assistance</li>
          </ul>
          <div class="p-3 rounded bg-body-tertiary small">
            <i class="bi bi-info-circle-fill text-primary me-1"></i> <strong>Need immediate help?</strong> Check our <a href="landingpage.php#faq" class="link-primary">FAQs</a> or visit the <a href="requirements.php" class="link-primary">Requirements page</a> for quick answers.
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- Footer - Dynamic CMS Controlled -->
<?php include __DIR__ . '/../includes/website/footer.php'; ?>

<!-- Chatbot (mirroring landing page) -->
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
          👋 Hi! I'm your EducAid Assistant. Ask me about requirements, process steps, announcements, or schedules.
        </div>
      </div>
      <div class="ea-typing" id="eaTyping">EducAid Assistant is typing...</div>
    </div>
    <div class="ea-chat__footer">
      <input class="ea-chat__input" id="eaInput" placeholder="Type your message…" />
      <button class="ea-chat__send" id="eaSend">Send</button>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- Ensure consistent mobile navbar behavior across pages -->
<script src="../assets/js/website/mobile-navbar.js"></script>
<script>
// Newsletter (reused logic simplified)
const nf = document.getElementById('newsletterForm');
if (nf){
  const msg = document.getElementById('newsletterMessage');
  const btn = document.getElementById('subscribeBtn');
  nf.addEventListener('submit', async e => {
    e.preventDefault();
    const email = document.getElementById('emailInput').value.trim();
    if(!email || !email.includes('@')) { msg.textContent='Invalid email'; msg.className='small text-danger'; msg.style.display='block'; return; }
    btn.disabled=true; btn.innerHTML='<span class="spinner-border spinner-border-sm me-2"></span>...';
    setTimeout(()=>{ msg.textContent='Subscribed (placeholder).'; msg.className='small text-success'; msg.style.display='block'; btn.disabled=false; btn.textContent='Join'; nf.reset(); }, 600);
  });
}
document.getElementById('year').textContent = new Date().getFullYear();

// Chatbot (lightweight reuse)
(function(){
  const apiUrl = '../chatbot/gemini_chat_fast.php';
  const t=document.getElementById('eaToggle'),p=document.getElementById('eaPanel'),c=document.getElementById('eaClose'),b=document.getElementById('eaBody'),i=document.getElementById('eaInput'),s=document.getElementById('eaSend'),ty=document.getElementById('eaTyping');
  if(!t) return; let open=false; function toggle(){ open=!open; p.style.display=open?'block':'none'; if(open) i.focus(); }
  t.addEventListener('click',toggle); c.addEventListener('click',toggle);
  async function send(){ const txt=i.value.trim(); if(!txt) return; i.value=''; i.disabled=true; const um=document.createElement('div'); um.className='ea-chat__msg ea-chat__msg--user'; um.innerHTML='<div class="ea-chat__bubble ea-chat__bubble--user"></div>'; um.querySelector('.ea-chat__bubble').textContent=txt; b.appendChild(um); b.scrollTop=b.scrollHeight; ty.style.display='block'; try { const r= await fetch(apiUrl,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({message:txt})}); const d= await r.json(); const reply=d.reply||'Sorry, I could not understand that.'; const bm=document.createElement('div'); bm.className='ea-chat__msg'; bm.innerHTML='<div class="ea-chat__bubble"></div>'; bm.querySelector('.ea-chat__bubble').textContent=reply; b.appendChild(bm);}catch(err){const em=document.createElement('div'); em.className='ea-chat__msg'; em.innerHTML='<div class="ea-chat__bubble">Connection issue. Try again later.</div>'; b.appendChild(em);} finally { ty.style.display='none'; i.disabled=false; i.focus(); b.scrollTop=b.scrollHeight; } }
  s.addEventListener('click',send); i.addEventListener('keydown',e=>{ if(e.key==='Enter'&&!e.shiftKey){ e.preventDefault(); send(); }});
  document.addEventListener('click',e=>{ if(!e.target.closest('.ea-chat') && open){ toggle(); }});
})();

// Simple fade-in scroll (fallback if not on landing page animation script)
const observer=new IntersectionObserver(es=>{es.forEach(en=>{if(en.isIntersecting){en.target.classList.add('visible');observer.unobserve(en.target);}})},{threshold:.15});
document.querySelectorAll('.fade-in').forEach(el=>observer.observe(el));
</script>

<?php if ($IS_EDIT_MODE): ?>
<script src="../assets/js/website/content_editor.js"></script>
<script>
// Initialize Content Editor for Contact page
document.addEventListener('DOMContentLoaded', function() {
  ContentEditor.init({
    page: 'contact',
    pageTitle: 'Contact Page',
    saveEndpoint: 'ajax_save_contact_content.php',
    getEndpoint: 'ajax_get_contact_blocks.php',
    resetAllEndpoint: 'ajax_reset_contact_content.php',
    history: {
      fetchEndpoint: 'ajax_get_contact_history.php',
      rollbackEndpoint: 'ajax_rollback_contact_block.php'
    }
  });
});
</script>
<?php endif; ?>

<?php 
// Anti-FOUC scripts for smooth page transitions
include __DIR__ . '/../includes/website/anti_fouc_scripts.php'; 
?>

</body>
</html>
