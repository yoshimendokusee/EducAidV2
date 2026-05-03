<?php
// Simplified EducAid Landing Page
// Safe to run without database connection issues

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EducAid - Education Assistance Distribution System</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; }
        .navbar { background: rgba(255,255,255,0.95); box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .hero { color: white; padding: 80px 20px; text-align: center; }
        .hero h1 { font-size: 48px; font-weight: bold; margin-bottom: 20px; }
        .hero p { font-size: 20px; margin-bottom: 30px; }
        .btn-custom { background: white; color: #667eea; font-weight: 600; padding: 12px 30px; border-radius: 50px; }
        .btn-custom:hover { background: #f0f0f0; color: #667eea; }
        .feature-card { background: white; border-radius: 10px; padding: 30px; margin: 20px 0; box-shadow: 0 5px 20px rgba(0,0,0,0.1); }
        .feature-icon { font-size: 40px; color: #667eea; margin-bottom: 15px; }
        .section { padding: 60px 20px; }
        .section-title { font-size: 36px; font-weight: bold; margin-bottom: 40px; text-align: center; }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-light sticky-top">
        <div class="container">
            <a class="navbar-brand fw-bold" href="#">
                <i class="bi bi-mortarboard-fill"></i> EducAid
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item"><a class="nav-link" href="#features">Features</a></li>
                    <li class="nav-item"><a class="nav-link" href="#about">About</a></li>
                    <li class="nav-item"><a class="nav-link" href="unified_login.php">Login</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <div class="hero">
        <div class="container">
            <h1>EducAid</h1>
            <p class="lead">Education Assistance Distribution System</p>
            <p class="mb-4">Empowering Dreams Through Education</p>
            <p class="text-muted mb-5">Your gateway to accessible educational financial assistance in General Trias</p>
            <a href="unified_login.php" class="btn btn-custom btn-lg me-3">
                <i class="bi bi-box-arrow-in-right"></i> Sign In
            </a>
            <a href="register.php" class="btn btn-outline-light btn-lg">
                <i class="bi bi-person-plus"></i> Create Account
            </a>
        </div>
    </div>

    <!-- Features Section -->
    <div id="features" class="section bg-white">
        <div class="container">
            <h2 class="section-title">Key Features</h2>
            <div class="row">
                <div class="col-md-4">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="bi bi-file-earmark-check"></i>
                        </div>
                        <h5 class="fw-bold">Easy Application</h5>
                        <p class="text-muted">Simple and straightforward application process for educational assistance.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="bi bi-shield-check"></i>
                        </div>
                        <h5 class="fw-bold">Secure & Safe</h5>
                        <p class="text-muted">Your personal information is protected with advanced security measures.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="bi bi-lightning-charge"></i>
                        </div>
                        <h5 class="fw-bold">Fast Processing</h5>
                        <p class="text-muted">Quick and efficient processing of your scholarship applications.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- About Section -->
    <div id="about" class="section" style="background: rgba(255,255,255,0.1);">
        <div class="container">
            <h2 class="section-title text-white mb-5">About EducAid</h2>
            <div class="row">
                <div class="col-lg-8 mx-auto">
                    <div style="background: white; padding: 40px; border-radius: 10px; box-shadow: 0 10px 30px rgba(0,0,0,0.2);">
                        <p class="lead mb-3">EducAid is a comprehensive education assistance distribution system designed to help students access financial support for their educational pursuits.</p>
                        <p class="mb-3">Our mission is to:</p>
                        <ul>
                            <li>Provide transparent and equitable distribution of scholarships</li>
                            <li>Simplify the application process for students</li>
                            <li>Support educational access for all eligible students</li>
                            <li>Maintain the highest standards of integrity and security</li>
                        </ul>
                        <p class="text-muted mt-4">For inquiries, contact us at <strong>educaid@generaltrias.gov.ph</strong> or call <strong>(046) 886-4454</strong></p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="bg-dark text-white text-center py-4">
        <div class="container">
            <p class="mb-2">&copy; 2026 EducAid • City of General Trias. All rights reserved.</p>
            <p class="small text-muted">Protected by secure authentication</p>
        </div>
    </footer>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
</body>
</html>
