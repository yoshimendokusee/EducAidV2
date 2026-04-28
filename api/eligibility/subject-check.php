<?php
/**
 * Grade Eligibility Check API Endpoint
 * POST /api/eligibility/subject-check
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../bootstrap_services.php';

// Set JSON response headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

try {
    global $connection;
    $db = $connection;
    if (!$db) {
        throw new Exception('Database connection is not available');
    }
    
    // Initialize services
    $gradeValidator = new GradeValidationService($db);
    $ocrProcessor = new OCRProcessingService([
        'tesseract_path' => 'tesseract', // Adjust path as needed
        'temp_dir' => __DIR__ . '/../../temp',
        'max_file_size' => 10 * 1024 * 1024, // 10MB
    ]);
    
    $response = ['success' => false];
    
    // Check if this is a file upload or direct subject data
    if (isset($_FILES['gradeDocument'])) {
        // Handle file upload and OCR processing
        $uploadedFile = $_FILES['gradeDocument'];
        $universityKey = $_POST['universityKey'] ?? '';
        
        if (empty($universityKey)) {
            throw new Exception('University key is required');
        }
        
        // Validate upload
        if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('File upload error: ' . $uploadedFile['error']);
        }
        
        // Create temp directory if it doesn't exist
        $tempDir = __DIR__ . '/../../temp';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        
        // Move uploaded file to temp location
        $tempFilePath = $tempDir . '/' . uniqid() . '_' . $uploadedFile['name'];
        if (!move_uploaded_file($uploadedFile['tmp_name'], $tempFilePath)) {
            throw new Exception('Failed to process uploaded file');
        }
        
        try {
            // Process the document with OCR
            $ocrResult = $ocrProcessor->processGradeDocument($tempFilePath);
            
            if (!$ocrResult['success']) {
                throw new Exception('OCR processing failed: ' . ($ocrResult['error'] ?? 'Unknown error'));
            }
            
            $subjects = $ocrResult['subjects'];
            
            // Validate subjects against university grading policy
            $validationResult = $gradeValidator->validateApplicant($universityKey, $subjects);
            
            $response = [
                'success' => true,
                'eligible' => $validationResult['eligible'],
                'failedSubjects' => $validationResult['failedSubjects'],
                'totalSubjects' => $validationResult['totalSubjects'],
                'passedSubjects' => $validationResult['passedSubjects'],
                'ocrExtractedSubjects' => $subjects,
                'universityKey' => $universityKey
            ];
            
        } finally {
            // Clean up uploaded file
            if (file_exists($tempFilePath)) {
                unlink($tempFilePath);
            }
        }
        
    } else {
        // Handle direct subject data (for testing or manual input)
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            throw new Exception('Invalid JSON input');
        }
        
        $universityKey = $input['universityKey'] ?? '';
        $subjects = $input['subjects'] ?? [];
        
        if (empty($universityKey)) {
            throw new Exception('University key is required');
        }
        
        if (empty($subjects) || !is_array($subjects)) {
            throw new Exception('Subjects array is required');
        }
        
        // Validate subjects
        $validationResult = $gradeValidator->validateApplicant($universityKey, $subjects);
        
        $response = [
            'success' => true,
            'eligible' => $validationResult['eligible'],
            'failedSubjects' => $validationResult['failedSubjects'],
            'totalSubjects' => $validationResult['totalSubjects'],
            'passedSubjects' => $validationResult['passedSubjects'],
            'universityKey' => $universityKey
        ];
    }
    
} catch (Exception $e) {
    http_response_code(400);
    $response = [
        'success' => false,
        'error' => $e->getMessage()
    ];
    
    // Log error for debugging
    error_log("Grade eligibility check error: " . $e->getMessage());
}

// Return JSON response
echo json_encode($response, JSON_PRETTY_PRINT);
?>