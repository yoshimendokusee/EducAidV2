<?php
// Test file to verify toolbar loads
session_start();

// Force edit mode for testing
$IS_EDIT_MODE = true;

$toolbar_config = [
    'page_title' => 'Test Toolbar',
    'exit_url' => 'landingpage.php'
];
?>
<!DOCTYPE html>
<html>
<head>
    <title>Toolbar Test</title>
    <style>
        .test-content { margin-top: 100px; padding: 20px; }
        h1 { color: #333; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../includes/website/edit_toolbar.php'; ?>
    
    <div class="test-content">
        <h1>Toolbar Test Page</h1>
        <p>If toolbar appears at top, it's working!</p>
        <p>Check console for debug messages.</p>
        <p>IS_EDIT_MODE = <?php echo $IS_EDIT_MODE ? 'TRUE' : 'FALSE'; ?></p>
    </div>
</body>
</html>
