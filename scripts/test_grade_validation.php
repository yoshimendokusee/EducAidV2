<?php
/**
 * OCR-Driven Per-Subject Grade Validation Test Script
 * Test the complete pipeline from document upload to eligibility determination
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../bootstrap_services.php';

class GradeValidationTester {
    private $db;
    private $gradeValidator;
    private $ocrProcessor;
    
    public function __construct() {
        global $connection;
        $this->db = $connection;
        if (!$this->db) {
            throw new Exception('Database connection is not available');
        }
        $this->gradeValidator = new GradeValidationService($this->db);
        $this->ocrProcessor = new OCRProcessingService([
            'tesseract_path' => 'tesseract',
            'temp_dir' => __DIR__ . '/../temp',
            'max_file_size' => 10 * 1024 * 1024,
        ]);
    }
    
    /**
     * Test university grading policies
     */
    public function testGradingPolicies() {
        echo "=== TESTING UNIVERSITY GRADING POLICIES ===\n";
        
        $testCases = [
            // 1-5 scale (lower is better)
            ['BSU_MAIN', '2.50', true],   // Should pass
            ['BSU_MAIN', '3.00', true],   // Should pass (exactly at threshold)
            ['BSU_MAIN', '3.25', false],  // Should fail
            ['CVSU_MAIN', '1.00', true],  // Should pass (highest grade)
            ['CVSU_MAIN', '5.00', false], // Should fail (lowest grade)
            
            // 0-4 scale (higher is better)
            ['DLSU_DASMARINAS', '2.50', true],  // Should pass
            ['DLSU_DASMARINAS', '1.00', true],  // Should pass (exactly at threshold)
            ['DLSU_DASMARINAS', '0.75', false], // Should fail
            ['ADMU_NUVALI', '4.00', true],      // Should pass (highest grade)
            ['ADMU_NUVALI', '0.50', false],     // Should fail
        ];
        
        foreach ($testCases as $i => $case) {
            [$universityKey, $grade, $expected] = $case;
            $result = $this->gradeValidator->isSubjectPassing($universityKey, $grade);
            
            $status = ($result === $expected) ? "✓ PASS" : "✗ FAIL";
            echo sprintf("%d. %s grade %s -> %s (expected: %s) %s\n", 
                $i + 1, $universityKey, $grade, 
                $result ? 'PASS' : 'FAIL', 
                $expected ? 'PASS' : 'FAIL', 
                $status
            );
        }
        echo "\n";
    }
    
    /**
     * Test complete applicant validation
     */
    public function testApplicantValidation() {
        echo "=== TESTING APPLICANT VALIDATION ===\n";
        
        $testScenarios = [
            [
                'name' => 'All Passing Grades (BSU)',
                'university' => 'BSU_MAIN',
                'subjects' => [
                    ['name' => 'Mathematics 1', 'rawGrade' => '2.00', 'confidence' => 95],
                    ['name' => 'English 1', 'rawGrade' => '2.50', 'confidence' => 90],
                    ['name' => 'Science 1', 'rawGrade' => '3.00', 'confidence' => 88],
                ],
                'expected' => true
            ],
            [
                'name' => 'One Failing Grade (BSU)',
                'university' => 'BSU_MAIN',
                'subjects' => [
                    ['name' => 'Mathematics 1', 'rawGrade' => '2.00', 'confidence' => 95],
                    ['name' => 'English 1', 'rawGrade' => '3.25', 'confidence' => 90], // Failing
                    ['name' => 'Science 1', 'rawGrade' => '2.75', 'confidence' => 88],
                ],
                'expected' => false
            ],
            [
                'name' => 'All Passing Grades (DLSU)',
                'university' => 'DLSU_DASMARINAS',
                'subjects' => [
                    ['name' => 'Calculus', 'rawGrade' => '3.50', 'confidence' => 95],
                    ['name' => 'Physics', 'rawGrade' => '2.75', 'confidence' => 90],
                    ['name' => 'Chemistry', 'rawGrade' => '1.00', 'confidence' => 88], // Exactly at threshold
                ],
                'expected' => true
            ],
            [
                'name' => 'Low Confidence Grade',
                'university' => 'BSU_MAIN',
                'subjects' => [
                    ['name' => 'Mathematics 1', 'rawGrade' => '2.00', 'confidence' => 95],
                    ['name' => 'English 1', 'rawGrade' => '2.50', 'confidence' => 80], // Low confidence - should fail
                ],
                'expected' => false
            ],
            [
                'name' => 'Empty Grade',
                'university' => 'BSU_MAIN',
                'subjects' => [
                    ['name' => 'Mathematics 1', 'rawGrade' => '2.00', 'confidence' => 95],
                    ['name' => 'English 1', 'rawGrade' => '', 'confidence' => 95], // Empty grade
                ],
                'expected' => false
            ]
        ];
        
        foreach ($testScenarios as $i => $scenario) {
            echo sprintf("%d. Testing: %s\n", $i + 1, $scenario['name']);
            
            $result = $this->gradeValidator->validateApplicant(
                $scenario['university'], 
                $scenario['subjects']
            );
            
            $status = ($result['eligible'] === $scenario['expected']) ? "✓ PASS" : "✗ FAIL";
            echo sprintf("   Result: %s (expected: %s) %s\n", 
                $result['eligible'] ? 'ELIGIBLE' : 'INELIGIBLE',
                $scenario['expected'] ? 'ELIGIBLE' : 'INELIGIBLE',
                $status
            );
            
            if (!$result['eligible']) {
                echo "   Failed Subjects: " . implode(', ', $result['failedSubjects']) . "\n";
            }
            
            echo sprintf("   Subjects: %d total, %d passed, %d failed\n\n", 
                $result['totalSubjects'], 
                $result['passedSubjects'], 
                count($result['failedSubjects'])
            );
        }
    }
    
    /**
     * Test OCR processing (if test images available)
     */
    public function testOCRProcessing($imagePath = null) {
        echo "=== TESTING OCR PROCESSING ===\n";
        
        if (!$imagePath || !file_exists($imagePath)) {
            echo "No test image provided or image not found.\n";
            echo "To test OCR: php test_grade_validation.php <image_path>\n\n";
            return;
        }
        
        echo "Processing image: $imagePath\n";
        
        $result = $this->ocrProcessor->processGradeDocument($imagePath);
        
        if ($result['success']) {
            echo "✓ OCR Processing successful\n";
            echo sprintf("Extracted %d subjects:\n", count($result['subjects']));
            
            foreach ($result['subjects'] as $i => $subject) {
                echo sprintf("  %d. %s: %s (conf: %d%%)\n", 
                    $i + 1, 
                    $subject['name'], 
                    $subject['rawGrade'], 
                    $subject['confidence']
                );
            }
            
            // Test validation with extracted subjects
            if (!empty($result['subjects'])) {
                echo "\nTesting with BSU_MAIN grading policy:\n";
                $validation = $this->gradeValidator->validateApplicant('BSU_MAIN', $result['subjects']);
                
                echo sprintf("Eligibility: %s\n", $validation['eligible'] ? 'ELIGIBLE' : 'INELIGIBLE');
                if (!$validation['eligible']) {
                    echo "Failed Subjects: " . implode(', ', $validation['failedSubjects']) . "\n";
                }
            }
        } else {
            echo "✗ OCR Processing failed: " . ($result['error'] ?? 'Unknown error') . "\n";
        }
        
        echo "\n";
    }
    
    /**
     * Test grade normalization
     */
    public function testGradeNormalization() {
        echo "=== TESTING GRADE NORMALIZATION ===\n";
        
        $testGrades = [
            '3,00' => '3.00',    // Comma to decimal
            '2O5' => '2.05',     // O to 0
            'S.00' => '5.00',    // S to 5
            '1.75°' => '1.75',   // Remove degree symbol
            ' 2.50 ' => '2.50',  // Trim spaces
            '3' => '3',          // Keep as is
            'A+' => 'A+',        // Letter grade
            'INC' => 'INC',      // Special grade
        ];
        
        foreach ($testGrades as $input => $expected) {
            $normalized = $this->gradeValidator->normalizeGrade($input);
            $status = ($normalized === $expected) ? "✓ PASS" : "✗ FAIL";
            echo sprintf("'%s' -> '%s' (expected: '%s') %s\n", 
                $input, $normalized, $expected, $status
            );
        }
        
        echo "\n";
    }
    
    /**
     * Test database connectivity and schema
     */
    public function testDatabase() {
        echo "=== TESTING DATABASE CONNECTIVITY ===\n";
        
        try {
            // Test grading policy table
            $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM grading.university_passing_policy WHERE is_active = TRUE");
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            echo "✓ Database connection successful\n";
            echo sprintf("✓ Found %d active grading policies\n", $result['count']);
            
            // Test grading function
            $stmt = $this->db->prepare("SELECT grading.grading_is_passing('BSU_MAIN', '2.50') as is_passing");
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            echo "✓ Grading function working: BSU_MAIN grade 2.50 -> " . 
                 ($result['is_passing'] ? 'PASS' : 'FAIL') . "\n";
            
        } catch (Exception $e) {
            echo "✗ Database error: " . $e->getMessage() . "\n";
        }
        
        echo "\n";
    }
    
    /**
     * Run all tests
     */
    public function runAllTests($imagePath = null) {
        echo "OCR-DRIVEN PER-SUBJECT GRADE VALIDATION TEST SUITE\n";
        echo str_repeat("=", 60) . "\n\n";
        
        $this->testDatabase();
        $this->testGradingPolicies();
        $this->testGradeNormalization();
        $this->testApplicantValidation();
        $this->testOCRProcessing($imagePath);
        
        echo "TEST SUITE COMPLETED\n";
        echo str_repeat("=", 60) . "\n";
    }
}

// Run tests
$tester = new GradeValidationTester();

// Check for command line arguments
$imagePath = $argv[1] ?? null;

if (php_sapi_name() === 'cli') {
    $tester->runAllTests($imagePath);
} else {
    // Web interface
    header('Content-Type: text/plain');
    $tester->runAllTests();
}
?>