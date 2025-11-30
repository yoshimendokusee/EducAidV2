<?php
/**
 * Web-Based Footer Settings Table Installation
 * Access this file via browser on Railway to create the footer_settings table
 * URL: https://your-railway-app.up.railway.app/install_footer_table.php
 * 
 * SECURITY: Remove this file after installation!
 */

// Simple authentication - change this password!
$INSTALL_PASSWORD = "educaid2025"; // CHANGE THIS!

session_start();

// Check if password is required
$requirePassword = true;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['password']) && $_POST['password'] === $INSTALL_PASSWORD) {
        $_SESSION['install_authenticated'] = true;
    }
    
    if (isset($_POST['install']) && isset($_SESSION['install_authenticated'])) {
        // Proceed with installation
        require_once __DIR__ . '/config/database.php';
        
        $results = [];
        $hasError = false;
        
        // SQL to create table
        $createTableSQL = "
CREATE TABLE IF NOT EXISTS footer_settings (
    footer_id        SERIAL PRIMARY KEY,
    municipality_id  INTEGER NOT NULL DEFAULT 1,
    footer_bg_color  VARCHAR(7)  NOT NULL DEFAULT '#1e3a8a',
    footer_text_color VARCHAR(7) NOT NULL DEFAULT '#cbd5e1',
    footer_heading_color VARCHAR(7) NOT NULL DEFAULT '#ffffff',
    footer_link_color VARCHAR(7) NOT NULL DEFAULT '#e2e8f0',
    footer_link_hover_color VARCHAR(7) NOT NULL DEFAULT '#fbbf24',
    footer_divider_color VARCHAR(7) NOT NULL DEFAULT '#fbbf24',
    footer_title     VARCHAR(100) NOT NULL DEFAULT 'EducAid',
    footer_description TEXT DEFAULT 'Making education accessible throughout General Trias City through innovative scholarship solutions.',
    contact_address  TEXT DEFAULT 'General Trias City Hall, Cavite',
    contact_phone    VARCHAR(50)  DEFAULT '(046) 886-4454',
    contact_email    VARCHAR(100) DEFAULT 'educaid@generaltrias.gov.ph',
    is_active        BOOLEAN NOT NULL DEFAULT TRUE,
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
        ";
        
        // Execute table creation
        $result = pg_query($connection, $createTableSQL);
        if ($result) {
            $results[] = ['success' => true, 'message' => 'Table footer_settings created successfully'];
        } else {
            $results[] = ['success' => false, 'message' => 'Failed to create table: ' . pg_last_error($connection)];
            $hasError = true;
        }
        
        // Insert default data if table was created
        if (!$hasError) {
            $insertSQL = "
INSERT INTO footer_settings (municipality_id)
SELECT 1
WHERE NOT EXISTS (
    SELECT 1 FROM footer_settings WHERE municipality_id = 1 AND is_active = TRUE
);
            ";
            
            $result = pg_query($connection, $insertSQL);
            if ($result) {
                $affected = pg_affected_rows($result);
                if ($affected > 0) {
                    $results[] = ['success' => true, 'message' => 'Default footer settings inserted'];
                } else {
                    $results[] = ['success' => true, 'message' => 'Default settings already exist (no new rows inserted)'];
                }
            } else {
                $results[] = ['success' => false, 'message' => 'Failed to insert default data: ' . pg_last_error($connection)];
                $hasError = true;
            }
        }
        
        // Verify installation
        if (!$hasError) {
            $verifySQL = "SELECT COUNT(*) as count FROM footer_settings";
            $result = pg_query($connection, $verifySQL);
            if ($result) {
                $row = pg_fetch_assoc($result);
                $results[] = ['success' => true, 'message' => "Verification: Found {$row['count']} row(s) in footer_settings table"];
            }
        }
        
        pg_close($connection);
    }
}

$isAuthenticated = isset($_SESSION['install_authenticated']) && $_SESSION['install_authenticated'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Install Footer Settings Table</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .install-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.2);
            max-width: 600px;
            width: 100%;
            padding: 32px;
        }
        .success-box {
            background: #d1fae5;
            border-left: 4px solid #10b981;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 12px;
        }
        .error-box {
            background: #fee2e2;
            border-left: 4px solid #ef4444;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 12px;
        }
        .warning-box {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
        }
        h1 {
            color: #667eea;
            margin-bottom: 24px;
        }
        .btn-install {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            color: white;
            padding: 12px 32px;
            font-weight: 600;
            border-radius: 8px;
            transition: transform 0.2s;
        }
        .btn-install:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
    </style>
</head>
<body>
    <div class="install-card">
        <h1><i class="bi bi-database"></i> Footer Settings Installation</h1>
        
        <?php if (!$isAuthenticated): ?>
            <!-- Authentication Form -->
            <div class="warning-box">
                <strong>⚠️ Security Check</strong><br>
                Enter the installation password to proceed.
            </div>
            <form method="POST">
                <div class="mb-3">
                    <label class="form-label">Installation Password</label>
                    <input type="password" name="password" class="form-control" required autofocus>
                    <small class="text-muted">Default: educaid2025 (change in code)</small>
                </div>
                <button type="submit" class="btn btn-install">Authenticate</button>
            </form>
        <?php else: ?>
            <!-- Installation Form -->
            <?php if (isset($results)): ?>
                <div class="mb-4">
                    <h5>Installation Results:</h5>
                    <?php foreach ($results as $result): ?>
                        <div class="<?php echo $result['success'] ? 'success-box' : 'error-box'; ?>">
                            <?php echo $result['success'] ? '✓' : '✗'; ?> <?php echo htmlspecialchars($result['message']); ?>
                        </div>
                    <?php endforeach; ?>
                    
                    <?php if (!$hasError): ?>
                        <div class="alert alert-success mt-3">
                            <strong>✓ Installation Complete!</strong><br>
                            The footer_settings table has been created successfully.<br>
                            <strong>⚠️ IMPORTANT: Delete this file (install_footer_table.php) now for security!</strong>
                        </div>
                        <a href="modules/admin/footer_settings.php" class="btn btn-install">
                            Go to Footer Settings Page
                        </a>
                    <?php else: ?>
                        <div class="alert alert-danger mt-3">
                            <strong>Installation encountered errors.</strong><br>
                            Please check the error messages above.
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="warning-box">
                    <strong>⚠️ Before Installing</strong><br>
                    This will create the <code>footer_settings</code> table in your database.<br>
                    Make sure you have a database backup before proceeding.
                </div>
                
                <div class="alert alert-info">
                    <strong>What will be created:</strong>
                    <ul class="mb-0">
                        <li>Table: <code>footer_settings</code></li>
                        <li>Columns: 16 fields for footer customization</li>
                        <li>Default row with General Trias branding</li>
                    </ul>
                </div>
                
                <form method="POST">
                    <input type="hidden" name="install" value="1">
                    <button type="submit" class="btn btn-install" onclick="return confirm('Are you sure you want to install the footer_settings table?')">
                        <i class="bi bi-download"></i> Install Footer Settings Table
                    </button>
                </form>
                
                <div class="mt-3">
                    <small class="text-muted">
                        <strong>Security Reminder:</strong> Delete this file after installation!
                    </small>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>
