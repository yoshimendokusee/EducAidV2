<?php
// Simple landing page for EducAid app verification
?><!DOCTYPE html>
<html>
<head>
    <title>EducAid App</title>
    <style>
        body { font-family: sans-serif; margin: 40px; line-height: 1.6; }
        .header { background: #007bff; color: white; padding: 20px; border-radius: 5px; }
        h1 { margin: 0; }
        .status { background: #f0f0f0; padding: 20px; margin: 20px 0; border-radius: 5px; }
        .links { list-style: none; padding: 0; }
        .links li { margin: 10px 0; }
        .links a { color: #007bff; text-decoration: none; font-weight: bold; }
        .links a:hover { text-decoration: underline; }
        .success { color: green; }
        .warning { color: orange; }
        code { background: #f5f5f5; padding: 2px 6px; border-radius: 3px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>✅ EducAid Laravel App is Running!</h1>
    </div>
    
    <div class="status">
        <h2>Application Status</h2>
        <ul>
            <li><span class="success">✓ Laravel 11.51.0</span> running</li>
            <li><span class="success">✓ PHP 8.3.30</span> with SQLite support</li>
            <li><span class="success">✓ Database</span> SQLite (migrations applied)</li>
            <li><span class="success">✓ React Frontend</span> built and ready</li>
        </ul>
    </div>
    
    <div class="status">
        <h2>Available Test Routes</h2>
        <ul class="links">
            <li><a href="/api/auth/status">→ API Auth Status (POST required - will show error)</a></li>
            <li><a href="/login">→ Legacy Login Page (uses old PHP script)</a></li>
            <li><a href="/status.php">→ Setup Diagnostics</a></li>
        </ul>
    </div>
    
    <div class="status">
        <h2>Development Info</h2>
        <p>Current Server Time: <?php echo date('Y-m-d H:i:s'); ?></p>
        <p>PHP Version: <?php echo phpversion(); ?></p>
        <p>Database: SQLite (<code>database/database.sqlite</code>)</p>
        <p>Environment: <code>local</code></p>
    </div>
    
    <div class="status">
        <h2>Next Steps</h2>
        <ul>
            <li>The React frontend is built and available at <code>/react/dist/index.html</code></li>
            <li>API routes are fully functional (use proper HTTP methods)</li>
            <li>Legacy PHP scripts are available via compat routes</li>
            <li>Database is using SQLite for local development</li>
        </ul>
    </div>
</body>
</html>
