<?php
// Debug edit mode for landing page
session_start();

echo "<h2>Edit Mode Debug</h2>";

// Check session
echo "<h3>Session Info:</h3>";
echo "<pre>";
echo "admin_id: " . (isset($_SESSION['admin_id']) ? $_SESSION['admin_id'] : 'NOT SET') . "\n";
echo "admin_username: " . (isset($_SESSION['admin_username']) ? $_SESSION['admin_username'] : 'NOT SET') . "\n";
echo "</pre>";

// Check GET params
echo "<h3>GET Parameters:</h3>";
echo "<pre>";
echo "edit: " . (isset($_GET['edit']) ? $_GET['edit'] : 'NOT SET') . "\n";
echo "municipality_id: " . (isset($_GET['municipality_id']) ? $_GET['municipality_id'] : 'NOT SET') . "\n";
echo "</pre>";

// Check database and role
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/permissions.php';

echo "<h3>Database & Permissions:</h3>";
echo "<pre>";
echo "connection: " . (isset($connection) && $connection ? 'CONNECTED' : 'NOT CONNECTED') . "\n";

if (isset($connection) && $connection && function_exists('getCurrentAdminRole') && isset($_SESSION['admin_id'])) {
    $role = getCurrentAdminRole($connection);
    echo "getCurrentAdminRole(): " . $role . "\n";
    echo "is_super_admin: " . ($role === 'super_admin' ? 'YES' : 'NO') . "\n";
} else {
    echo "Cannot determine role - missing connection or session\n";
}
echo "</pre>";

// Determine IS_EDIT_MODE
$IS_EDIT_MODE = false;
$is_super_admin = false;
if (isset($_SESSION['admin_id']) && function_exists('getCurrentAdminRole') && isset($connection)) {
    $role = @getCurrentAdminRole($connection);
    if ($role === 'super_admin') {
        $is_super_admin = true;
    }
}
if ($is_super_admin && isset($_GET['edit']) && $_GET['edit'] == '1') {
    $IS_EDIT_MODE = true;
}

echo "<h3>Final Result:</h3>";
echo "<pre>";
echo "is_super_admin: " . ($is_super_admin ? 'TRUE' : 'FALSE') . "\n";
echo "IS_EDIT_MODE: " . ($IS_EDIT_MODE ? 'TRUE' : 'FALSE') . "\n";
echo "</pre>";

if ($IS_EDIT_MODE) {
    echo "<p style='color: green; font-weight: bold;'>✓ Edit toolbar SHOULD appear</p>";
} else {
    echo "<p style='color: red; font-weight: bold;'>✗ Edit toolbar will NOT appear</p>";
    echo "<p>Reasons:</p><ul>";
    if (!isset($_SESSION['admin_id'])) {
        echo "<li>Not logged in as admin</li>";
    }
    if (!$is_super_admin) {
        echo "<li>Not a super_admin role</li>";
    }
    if (!isset($_GET['edit']) || $_GET['edit'] != '1') {
        echo "<li>Missing ?edit=1 in URL</li>";
    }
    echo "</ul>";
}

echo "<hr>";
echo "<p><a href='modules/admin/login.php'>Go to Admin Login</a></p>";
echo "<p><a href='website/landingpage.php?edit=1&municipality_id=1'>Try Landing Page Edit Mode</a></p>";
