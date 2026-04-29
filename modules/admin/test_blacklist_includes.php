<?php
/**
 * Diagnostic script to test if blacklist service includes work
 * Run this to see where the 500 error is coming from
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Load PHPMailer namespace at the top
require_once '../../phpmailer/vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;

echo "=== Blacklist Service Diagnostic ===\n\n";

echo "1. Testing database connection...\n";
try {
    include __DIR__ . '/../../config/database.php';
    if (isset($connection) && $connection) {
        echo "   ✓ Database connection OK\n\n";
    } else {
        echo "   ✗ Database connection FAILED\n\n";
        exit;
    }
} catch (Exception $e) {
    echo "   ✗ Database connection error: " . $e->getMessage() . "\n\n";
    exit;
}

echo "2. Testing CSRFProtection...\n";
try {
    require_once __DIR__ . '/../../includes/CSRFProtection.php';
    echo "   ✓ CSRFProtection loaded OK\n\n";
} catch (Exception $e) {
    echo "   ✗ CSRFProtection error: " . $e->getMessage() . "\n\n";
    exit;
}

echo "3. Testing BlacklistService...\n";
try {
    require_once __DIR__ . '/../../src/Services/BlacklistService.php';
    echo "   ✓ BlacklistService loaded OK\n\n";
} catch (Exception $e) {
    echo "   ✗ BlacklistService error: " . $e->getMessage() . "\n\n";
    exit;
}

echo "4. Testing BlacklistService instantiation...\n";
try {
    $testService = new \App\Services\BlacklistService();
    echo "   ✓ BlacklistService instantiated OK\n\n";
} catch (Exception $e) {
    echo "   ✗ BlacklistService instantiation error: " . $e->getMessage() . "\n\n";
    exit;
}

echo "5. Testing PHPMailer...\n";
try {
    $testMail = new PHPMailer(true);
    echo "   ✓ PHPMailer loaded OK\n\n";
} catch (Exception $e) {
    echo "   ✗ PHPMailer error: " . $e->getMessage() . "\n\n";
    exit;
}

echo "=== All components loaded successfully ===\n";
echo "The 500 error is likely in the request handling logic, not the includes.\n";
echo "\nCheck:\n";
echo "- Session is started\n";
echo "- Admin is logged in\n";
echo "- POST data is being sent correctly\n";
echo "- Database has admin_blacklist_verifications table\n";
?>
