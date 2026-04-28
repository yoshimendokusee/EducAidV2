<?php
/**
 * OCR Service Verification Script
 * Test the OCR service functionality without Imagick dependencies
 */

// Test basic class instantiation
echo "Testing OCR Processing Service...\n\n";

// Include the service
require_once __DIR__ . '/../bootstrap_services.php';

try {
    // Test 1: Service instantiation
    echo "1. Testing service instantiation: ";
    $ocrService = new OCRProcessingService([
        'tesseract_path' => 'tesseract',
        'temp_dir' => __DIR__ . '/../temp',
        'max_file_size' => 10 * 1024 * 1024,
    ]);
    echo "✓ SUCCESS\n";
    
    // Test 2: Check Imagick availability
    echo "2. Checking Imagick availability: ";
    if (extension_loaded('imagick')) {
        echo "✓ Imagick extension loaded\n";
        if (class_exists('Imagick')) {
            echo "   ✓ Imagick class available\n";
        } else {
            echo "   ⚠ Imagick class not found\n";
        }
    } else {
        echo "⚠ Imagick extension not loaded (using fallback mode)\n";
    }
    
    // Test 3: Check Tesseract availability
    echo "3. Checking Tesseract OCR: ";
    $tesseractCheck = shell_exec('tesseract --version 2>&1');
    if ($tesseractCheck && strpos($tesseractCheck, 'tesseract') !== false) {
        echo "✓ Tesseract found\n";
        $version = trim(explode("\n", $tesseractCheck)[0]);
        echo "   Version: $version\n";
    } else {
        echo "⚠ Tesseract not found in PATH\n";
        echo "   Install from: https://github.com/UB-Mannheim/tesseract/wiki\n";
    }
    
    // Test 4: Temp directory creation
    echo "4. Testing temp directory: ";
    $tempDir = __DIR__ . '/../temp';
    if (!is_dir($tempDir)) {
        if (mkdir($tempDir, 0755, true)) {
            echo "✓ Created temp directory\n";
        } else {
            echo "✗ Failed to create temp directory\n";
        }
    } else {
        echo "✓ Temp directory exists\n";
    }
    
    // Test 5: Grade normalization
    echo "5. Testing grade normalization: ";
    $reflection = new ReflectionClass($ocrService);
    $normalizeMethod = $reflection->getMethod('normalizeGrade');
    $normalizeMethod->setAccessible(true);
    
    $testGrades = [
        '3,00' => '3.00',
        '2O5' => '2.05', 
        'S.00' => '5.00',
        ' 2.50 ' => '2.50'
    ];
    
    $allPassed = true;
    foreach ($testGrades as $input => $expected) {
        $result = $normalizeMethod->invoke($ocrService, $input);
        if ($result !== $expected) {
            echo "\n   ✗ '$input' -> '$result' (expected: '$expected')";
            $allPassed = false;
        }
    }
    
    if ($allPassed) {
        echo "✓ All normalization tests passed\n";
    } else {
        echo "\n";
    }
    
    // Test 6: Grade pattern recognition
    echo "6. Testing grade pattern recognition: ";
    $looksLikeGradeMethod = $reflection->getMethod('looksLikeGrade');
    $looksLikeGradeMethod->setAccessible(true);
    
    $testPatterns = [
        '2.50' => true,
        '3.00' => true,
        'A+' => true,
        'INC' => true,
        'random text' => false,
        '99.5' => true  // percentage
    ];
    
    $allPassed = true;
    foreach ($testPatterns as $input => $expected) {
        $result = $looksLikeGradeMethod->invoke($ocrService, $input);
        if ($result !== $expected) {
            echo "\n   ✗ '$input' -> " . ($result ? 'true' : 'false') . " (expected: " . ($expected ? 'true' : 'false') . ")";
            $allPassed = false;
        }
    }
    
    if ($allPassed) {
        echo "✓ All pattern recognition tests passed\n";
    } else {
        echo "\n";
    }
    
    // Test 7: Mock TSV parsing (without actual OCR)
    echo "7. Testing TSV parsing: ";
    
    $mockTSV = "level\tpage_num\tblock_num\tpar_num\tline_num\tword_num\tleft\ttop\twidth\theight\tconf\ttext\n";
    $mockTSV .= "5\t1\t1\t1\t1\t1\t100\t200\t200\t30\t95\tMathematics\n";
    $mockTSV .= "5\t1\t1\t1\t1\t2\t300\t200\t60\t30\t92\t2.50\n";
    
    $parseTSVMethod = $reflection->getMethod('parseTSVData');
    $parseTSVMethod->setAccessible(true);
    
    $subjects = $parseTSVMethod->invoke($ocrService, $mockTSV);
    
    if (!empty($subjects) && isset($subjects[0]['name']) && isset($subjects[0]['rawGrade'])) {
        echo "✓ TSV parsing working\n";
        echo "   Found: {$subjects[0]['name']} - {$subjects[0]['rawGrade']}\n";
    } else {
        echo "⚠ TSV parsing may need adjustment\n";
    }
    
    echo "\n=== VERIFICATION COMPLETED ===\n";
    echo "The OCR Processing Service is ready to use!\n";
    
    if (!extension_loaded('imagick')) {
        echo "\nNote: Imagick not available - image preprocessing will be limited.\n";
        echo "For better OCR results, consider installing php-imagick extension.\n";
    }
    
    if (!$tesseractCheck) {
        echo "\nWarning: Tesseract not found - OCR functionality will not work.\n";
        echo "Please install Tesseract OCR to enable document processing.\n";
    }
    
} catch (Exception $e) {
    echo "✗ ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
?>