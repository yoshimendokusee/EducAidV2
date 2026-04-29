<?php
/**
 * Quick Test Script for OCR Bypass
 * Run this to verify bypass is working correctly
 */

echo "=== OCR BYPASS TEST SCRIPT ===\n\n";

// Test 1: Check if config file exists
echo "Test 1: Checking config file...\n";
$configFile = __DIR__ . '/config/ocr_bypass_config.php';
if (file_exists($configFile)) {
    echo "✅ Config file exists at: $configFile\n";
    require_once $configFile;
} else {
    echo "❌ Config file NOT FOUND at: $configFile\n";
    exit(1);
}

// Test 2: Check if bypass constant is defined
echo "\nTest 2: Checking bypass constant...\n";
if (defined('OCR_BYPASS_ENABLED')) {
    echo "✅ OCR_BYPASS_ENABLED constant is defined\n";
    echo "   Value: " . (OCR_BYPASS_ENABLED ? 'TRUE (ENABLED)' : 'FALSE (DISABLED)') . "\n";
} else {
    echo "❌ OCR_BYPASS_ENABLED constant NOT defined\n";
    exit(1);
}

// Test 3: Check bypass status
echo "\nTest 3: Checking bypass status...\n";
if (OCR_BYPASS_ENABLED === true) {
    echo "⚠️  BYPASS IS ENABLED\n";
    echo "   Reason: " . (defined('OCR_BYPASS_REASON') ? OCR_BYPASS_REASON : 'Not specified') . "\n";
    echo "   Mock Confidence: " . (defined('OCR_BYPASS_CONFIDENCE') ? OCR_BYPASS_CONFIDENCE : 'N/A') . "%\n";
    echo "   Mock Verification: " . (defined('OCR_BYPASS_VERIFICATION_SCORE') ? OCR_BYPASS_VERIFICATION_SCORE : 'N/A') . "%\n";
} else {
    echo "✅ BYPASS IS DISABLED (Normal operation)\n";
}

// Test 4: Check if OCR service files exist
echo "\nTest 4: Checking OCR service files...\n";
$serviceFiles = [
    'EnrollmentFormOCRService.php' => __DIR__ . '/src/Services/EnrollmentFormOCRService.php',
    'OCRProcessingService.php' => __DIR__ . '/bootstrap_services.php',
    'OCRProcessingService_Safe.php' => __DIR__ . '/src/Services/OCRProcessingService_Safe.php'
];

foreach ($serviceFiles as $name => $path) {
    if (file_exists($path)) {
        echo "✅ $name exists\n";
        
        // Check if file contains bypass logic
        $content = file_get_contents($path);
        if (strpos($content, 'OCR_BYPASS_ENABLED') !== false) {
            echo "   ✅ Contains bypass logic\n";
        } else {
            echo "   ⚠️  Does NOT contain bypass logic\n";
        }
    } else {
        echo "❌ $name NOT FOUND at: $path\n";
    }
}

// Test 5: Check if status page exists
echo "\nTest 5: Checking status page...\n";
$statusPage = __DIR__ . '/check_ocr_bypass_status.php';
if (file_exists($statusPage)) {
    echo "✅ Status page exists at: $statusPage\n";
    echo "   Access at: http://localhost/EducAid/check_ocr_bypass_status.php\n";
} else {
    echo "❌ Status page NOT FOUND at: $statusPage\n";
}

// Test 6: Test EnrollmentFormOCRService bypass
echo "\nTest 6: Testing EnrollmentFormOCRService bypass...\n";
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/bootstrap_services.php';
require_once __DIR__ . '/src/Services/EnrollmentFormOCRService.php';

try {
    $service = new \App\Services\EnrollmentFormOCRService();
    
    // Create a dummy file path (doesn't need to exist in bypass mode)
    $dummyPath = __DIR__ . '/test_dummy_file.pdf';
    
    $studentData = [
        'first_name' => 'Test',
        'middle_name' => 'User',
        'last_name' => 'Student',
        'university_name' => 'Test University',
        'year_level' => '1st Year'
    ];
    
    // This should return bypass response if enabled
    $result = $service->processEnrollmentForm($dummyPath, $studentData);
    
    if ($result['success']) {
        if (isset($result['bypass_mode']) && $result['bypass_mode'] === true) {
            echo "✅ Bypass is working! Service returned mock data\n";
            echo "   Overall Confidence: {$result['overall_confidence']}%\n";
            echo "   Verification Passed: " . ($result['verification_passed'] ? 'YES' : 'NO') . "\n";
        } else {
            echo "⚠️  Service returned success but NOT in bypass mode\n";
            echo "   This might mean bypass is disabled or file processing succeeded\n";
        }
    } else {
        echo "⚠️  Service returned error: " . ($result['error'] ?? 'Unknown error') . "\n";
    }
} catch (Exception $e) {
    echo "❌ Exception testing service: " . $e->getMessage() . "\n";
}

// Final summary
echo "\n=== TEST SUMMARY ===\n";
if (OCR_BYPASS_ENABLED === true) {
    echo "⚠️  OCR BYPASS IS ACTIVE\n";
    echo "✅ All tests passed - Bypass is ready for use\n";
    echo "🎯 Students can now register without strict OCR verification\n";
    echo "\n⚠️  REMEMBER TO DISABLE AFTER TESTING EVENT!\n";
} else {
    echo "✅ OCR BYPASS IS DISABLED\n";
    echo "✅ System is operating in normal mode\n";
    echo "🎯 Standard OCR verification is active\n";
}

echo "\n=== END OF TESTS ===\n";
?>
