<?php
/**
 * Enrollment Form OCR Service - TSV-based processing
 * Extracts structured data from enrollment/assessment forms
 * Uses Tesseract TSV output for higher accuracy
 */

// Load OCR bypass configuration
require_once __DIR__ . '/../config/ocr_bypass_config.php';

class EnrollmentFormOCRService {
    private $connection;
    private $tesseractPath;
    private $tempDir;
    
    public function __construct($dbConnection) {
        $this->connection = $dbConnection;
        $this->tesseractPath = 'tesseract'; // Adjust if needed
        $this->tempDir = __DIR__ . '/../assets/uploads/temp/';
    }
    
    /**
     * Process enrollment form and extract key information
     * Returns structured data with confidence scores
     */
    public function processEnrollmentForm($filePath, $studentData = []) {
        try {
            // CHECK FOR OCR BYPASS MODE
            if (defined('OCR_BYPASS_ENABLED') && OCR_BYPASS_ENABLED === true) {
                error_log("⚠️ OCR BYPASS ACTIVE - Skipping verification for: " . basename($filePath));
                return $this->createBypassResponse($studentData);
            }
            
            // Validate file
            if (!file_exists($filePath)) {
                return $this->errorResponse('File not found');
            }
            
            // Generate TSV output
            $tsvData = $this->runTesseractTSV($filePath);
            
            if (!$tsvData['success']) {
                return $this->errorResponse($tsvData['error']);
            }
            
            // Extract structured information
            $extracted = $this->extractEnrollmentData($tsvData['words'], $studentData);
            
            // Calculate overall confidence
            $overallConfidence = $this->calculateOverallConfidence($extracted);
            
            // Determine if verification passed
            $verificationPassed = $this->verifyExtractedData($extracted, $studentData);
            
            return [
                'success' => true,
                'data' => $extracted,
                'overall_confidence' => $overallConfidence,
                'verification_passed' => $verificationPassed,
                'tsv_quality' => $tsvData['quality']
            ];
            
        } catch (Exception $e) {
            error_log("Enrollment OCR Error: " . $e->getMessage());
            return $this->errorResponse($e->getMessage());
        }
    }
    
    /**
     * Run Tesseract with TSV output
     */
    private function runTesseractTSV($filePath) {
        try {
            // Handle PDF conversion if needed
            $processedFile = $this->preprocessFile($filePath);
            
            // Generate unique output filename in the SAME directory as the input file
            $fileDir = dirname($filePath);
            $outputBase = $fileDir . '/enrollment_ocr_' . uniqid();
            $tsvFile = $outputBase . '.tsv';
            
            // Run Tesseract for TSV output
            $command = sprintf(
                '%s %s %s -l eng --oem 1 --psm 6 tsv 2>&1',
                escapeshellarg($this->tesseractPath),
                escapeshellarg($processedFile),
                escapeshellarg($outputBase)
            );
            
            exec($command, $output, $returnCode);
            
            // Log command and output for debugging
            error_log("Tesseract command: " . $command);
            error_log("Tesseract return code: " . $returnCode);
            error_log("Tesseract output: " . implode("\n", $output));
            
            if ($returnCode !== 0) {
                throw new Exception("Tesseract execution failed with code $returnCode: " . implode(' ', $output));
            }
            
            if (!file_exists($tsvFile)) {
                throw new Exception("TSV file not created at: $tsvFile");
            }
            
            // Parse TSV data
            $tsvContent = file_get_contents($tsvFile);
            $words = $this->parseTSV($tsvContent);
            
            // DEBUG: Save TSV file for inspection (DON'T delete yet)
            // Keep it in the enrollment_forms folder alongside the uploaded file
            $debugTsvPath = str_replace('.tsv', '_debug.tsv', str_replace('enrollment_ocr_', '', $tsvFile));
            $debugTsvPath = dirname($filePath) . '/' . basename($filePath) . '.tsv';
            @copy($tsvFile, $debugTsvPath);
            
            // Also save extracted text for easy reading
            $extractedText = implode(' ', array_column($words, 'text'));
            $debugTxtPath = $filePath . '.ocr.txt';
            @file_put_contents($debugTxtPath, $extractedText);
            
            // Clean up temporary TSV
            @unlink($tsvFile);
            if ($processedFile !== $filePath) {
                @unlink($processedFile);
            }
            
            // Calculate quality metrics
            $quality = $this->calculateQuality($words);
            
            return [
                'success' => true,
                'words' => $words,
                'quality' => $quality,
                'extracted_text' => $extractedText // Include for debugging
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Preprocess file for OCR (handle PDFs, enhance images)
     */
    private function preprocessFile($filePath) {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        
        // If PDF, convert first page to image
        if ($extension === 'pdf') {
            if (extension_loaded('imagick') && class_exists('Imagick')) {
                try {
                    $imagick = new Imagick();
                    $imagick->setResolution(300, 300);
                    $imagick->readImage($filePath . '[0]'); // First page only
                    $imagick->setImageFormat('png');
                    
                    $tempFile = $this->tempDir . 'enrollment_temp_' . uniqid() . '.png';
                    $imagick->writeImage($tempFile);
                    $imagick->clear();
                    
                    return $tempFile;
                } catch (Exception $e) {
                    error_log("PDF conversion failed: " . $e->getMessage());
                    return $filePath;
                }
            }
        }
        
        return $filePath;
    }
    
    /**
     * Parse TSV content into structured word data
     */
    private function parseTSV($tsvContent) {
        $lines = explode("\n", $tsvContent);
        array_shift($lines); // Remove header
        
        $words = [];
        foreach ($lines as $line) {
            if (empty(trim($line))) continue;
            
            $cols = explode("\t", $line);
            if (count($cols) < 12) continue;
            
            $level = (int)$cols[0];
            $conf = is_numeric($cols[10]) ? (float)$cols[10] : 0;
            $text = trim($cols[11]);
            
            // Only keep word-level entries with text
            if ($level === 5 && !empty($text) && $conf > 30) {
                $words[] = [
                    'page_num' => (int)$cols[1],
                    'block_num' => (int)$cols[2],
                    'par_num' => (int)$cols[3],
                    'line_num' => (int)$cols[4],
                    'word_num' => (int)$cols[5],
                    'left' => (int)$cols[6],
                    'top' => (int)$cols[7],
                    'width' => (int)$cols[8],
                    'height' => (int)$cols[9],
                    'conf' => $conf,
                    'text' => $text
                ];
            }
        }
        
        return $words;
    }
    
    /**
     * Calculate OCR quality metrics
     */
    private function calculateQuality($words) {
        if (empty($words)) {
            return [
                'total_words' => 0,
                'avg_confidence' => 0,
                'quality_score' => 0
            ];
        }
        
        $totalConf = 0;
        $lowConfCount = 0;
        
        foreach ($words as $word) {
            $totalConf += $word['conf'];
            if ($word['conf'] < 70) {
                $lowConfCount++;
            }
        }
        
        $avgConf = round($totalConf / count($words), 2);
        $qualityScore = 100 - (($lowConfCount / count($words)) * 100);
        
        return [
            'total_words' => count($words),
            'avg_confidence' => $avgConf,
            'quality_score' => round($qualityScore, 1)
        ];
    }
    
    /**
     * Extract enrollment data from TSV words
     */
    private function extractEnrollmentData($words, $studentData) {
        $fullText = implode(' ', array_column($words, 'text'));
        $fullTextLower = strtolower($fullText);
        
        $extracted = [
            'student_name' => $this->extractStudentName($words, $studentData),
            // Course removed - students will input their course manually
            // 'course' => $this->extractCourse($words),
            'course' => ['raw' => null, 'normalized' => null, 'confidence' => 0, 'found' => false],
            'year_level' => $this->extractYearLevel($words),
            'university' => $this->extractUniversity($words, $studentData),
            'academic_year' => $this->extractAcademicYear($words),
            'student_id' => $this->extractStudentId($words),
            'document_type' => $this->verifyDocumentType($fullTextLower)
        ];
        
        return $extracted;
    }
    
    /**
     * Extract student name and verify
     * IMPROVED: Better handling of long/compound names
     */
    private function extractStudentName($words, $studentData) {
        $firstName = $studentData['first_name'] ?? '';
        $middleName = $studentData['middle_name'] ?? '';
        $lastName = $studentData['last_name'] ?? '';
        
        $result = [
            'first_name' => $firstName,
            'middle_name' => $middleName,
            'last_name' => $lastName,
            'first_name_found' => false,
            'middle_name_found' => false,
            'last_name_found' => false,
            'confidence' => 0
        ];
        
        $fullText = strtolower(implode(' ', array_column($words, 'text')));
        
        error_log("=== NAME EXTRACTION DEBUG ===");
        error_log("Looking for: First='{$firstName}', Middle='{$middleName}', Last='{$lastName}'");
        error_log("Total words in array: " . count($words));
        error_log("Sample words: " . json_encode(array_slice(array_column($words, 'text'), 0, 50)));
        error_log("OCR Text: " . substr($fullText, 0, 500));
        
        // Check first name - Use improved matching for compound/long names
        if (!empty($firstName)) {
            $similarity = $this->fuzzyMatchName($firstName, $fullText);
            // STRICTER: 60% threshold for first names (at least 2/3 of words must match)
            // This prevents completely different names from passing validation
            $result['first_name_found'] = $similarity >= 60;
            $result['first_name_similarity'] = $similarity;
            error_log("First name '{$firstName}' similarity: {$similarity}% - " . ($result['first_name_found'] ? '✓ FOUND' : '✗ NOT FOUND'));
        }
        
        // Check middle name (optional)
        if (!empty($middleName)) {
            $similarity = $this->fuzzyMatchName($middleName, $fullText);
            // MODERATE: 50% threshold for middle names (often abbreviated or missing)
            $result['middle_name_found'] = $similarity >= 50;
            $result['middle_name_similarity'] = $similarity;
            error_log("Middle name '{$middleName}' similarity: {$similarity}% - " . ($result['middle_name_found'] ? '✓ FOUND' : '✗ NOT FOUND'));
        } else {
            $result['middle_name_found'] = true; // Not required
            $result['middle_name_similarity'] = 100;
            error_log("Middle name: Not provided (skipped)");
        }
        
        // Check last name - Use improved matching
        if (!empty($lastName)) {
            $similarity = $this->fuzzyMatchName($lastName, $fullText);
            // STRICTER: 70% threshold for last names (usually single/compound word)
            // Last names are critical - must have strong match
            $result['last_name_found'] = $similarity >= 70;
            $result['last_name_similarity'] = $similarity;
            error_log("Last name '{$lastName}' similarity: {$similarity}% - " . ($result['last_name_found'] ? '✓ FOUND' : '✗ NOT FOUND'));
        }
        
        // Calculate overall confidence
        $confidences = [
            $result['first_name_similarity'] ?? 0,
            $result['middle_name_similarity'] ?? 0,
            $result['last_name_similarity'] ?? 0
        ];
        $result['confidence'] = round(array_sum($confidences) / 3, 1);
        
        error_log("Overall name confidence: {$result['confidence']}%");
        error_log("=============================");
        
        return $result;
    }
    
    /**
     * Extract course/program from enrollment form
     */
    private function extractCourse($words) {
        $fullText = implode(' ', array_column($words, 'text'));
        
        // DEBUG: Log what text we're searching
        error_log("=== ENROLLMENT COURSE EXTRACTION DEBUG ===");
        error_log("Full OCR Text: " . substr($fullText, 0, 500));
        
        // PRIORITY 1: Look for "PROGRAM:" field (most reliable)
        // Pattern: "PROGRAM: IT", "PROGRAM: BSCS", etc.
        if (preg_match('/PROGRAM\s*[:;]\s*([A-Z]{2,6})\b/i', $fullText, $matches)) {
            $programCode = strtoupper(trim($matches[1]));
            error_log("Found PROGRAM field: '{$programCode}'");
            
            // Map common program codes
            $programCodeMap = [
                'IT' => 'BS Information Technology',
                'CS' => 'BS Computer Science',
                'BSIT' => 'BS Information Technology',
                'BSCS' => 'BS Computer Science',
                'CE' => 'BS Civil Engineering',
                'EE' => 'BS Electrical Engineering',
                'ME' => 'BS Mechanical Engineering',
                'ECE' => 'BS Electronics and Communications Engineering',
                'CPE' => 'BS Computer Engineering',
                'BSCE' => 'BS Civil Engineering',
                'BSEE' => 'BS Electrical Engineering',
                'BSME' => 'BS Mechanical Engineering',
                'BSECE' => 'BS Electronics and Communications Engineering',
                'ARCH' => 'BS Architecture',
                'ARCHI' => 'BS Architecture',
                'BSA' => 'BS Accountancy',
                'BSBA' => 'BS Business Administration',
                'BSN' => 'BS Nursing',
                'BSPSYCH' => 'BS Psychology',
                'ABPSYCH' => 'AB Psychology',
                'ABCOMM' => 'AB Communication',
                'BEED' => 'Bachelor of Elementary Education',
                'BSED' => 'Bachelor of Secondary Education'
            ];
            
            if (isset($programCodeMap[$programCode])) {
                $extractedCourse = $programCodeMap[$programCode];
                error_log("Mapped '{$programCode}' to '{$extractedCourse}'");
                $normalized = $this->normalizeCourse($extractedCourse);
                return [
                    'raw' => $programCode,
                    'normalized' => $normalized,
                    'confidence' => 95, // Very high confidence for PROGRAM field
                    'found' => true
                ];
            }
        }
        
        // PRIORITY 2: Full course patterns (if PROGRAM field not found)
        error_log("PROGRAM field not found, trying full patterns...");
        
        // IMPROVED: More specific patterns with word boundaries and better matching
        $coursePatterns = [
            // Information Technology (check this FIRST as it's very common)
            '/\b(?:BS|B\.S\.|Bachelor.*?Science.*?in)?\s*Information\s+Technology\b/i',
            '/\b(?:BS|B\.S\.)\s*IT\b/i',
            '/\bBSIT\b/i',
            
            // Computer Science (check AFTER IT to avoid confusion)
            '/\b(?:BS|B\.S\.|Bachelor.*?Science.*?in)?\s*Computer\s+Science\b/i',
            '/\b(?:BS|B\.S\.)\s*CompSci\b/i',
            '/\b(?:BS|B\.S\.)\s*CS\b(?!\w)/i', // Negative lookahead to avoid matching "CSomething"
            '/\bBSCS\b/i',
            
            // Engineering courses
            '/\b(?:BS|B\.S\.)\s*Civil\s+Engineering\b/i',
            '/\b(?:BS|B\.S\.)\s*Electrical\s+Engineering\b/i',
            '/\b(?:BS|B\.S\.)\s*Mechanical\s+Engineering\b/i',
            '/\b(?:BS|B\.S\.)\s*Electronics?\s+(?:and\s+)?Communications?\s+Engineering\b/i',
            '/\b(?:BS|B\.S\.)\s*Chemical\s+Engineering\b/i',
            '/\b(?:BS|B\.S\.)\s*Industrial\s+Engineering\b/i',
            '/\bBSCE\b/i',
            '/\bBSEE\b/i',
            '/\bBSME\b/i',
            '/\bBSECE\b/i',
            
            // Architecture
            '/\b(?:BS|B\.S\.)\s*Architecture\b/i',
            '/\bBSArch\b/i',
            
            // Business courses
            '/\b(?:BS|B\.S\.)\s*Accountancy\b/i',
            '/\b(?:BS|B\.S\.)\s*Accounting\b/i',
            '/\b(?:BS|B\.S\.)\s*Business\s+Administration\b/i',
            '/\b(?:BS|B\.S\.)\s*Management\b/i',
            '/\bBSA\b(?!\w)/i', // Accountancy
            '/\bBSBA\b/i',
            '/\bBSBM\b/i',
            
            // Medical/Health
            '/\b(?:BS|B\.S\.)\s*Nursing\b/i',
            '/\b(?:BS|B\.S\.)\s*Pharmacy\b/i',
            '/\b(?:BS|B\.S\.)\s*Physical\s+Therapy\b/i',
            '/\b(?:BS|B\.S\.)\s*Medical\s+Technology\b/i',
            '/\bBSN\b(?!\w)/i',
            
            // Psychology
            '/\b(?:BS|B\.S\.)\s*Psychology\b/i',
            '/\b(?:AB|A\.B\.)\s*Psychology\b/i',
            '/\bBSPsych\b/i',
            '/\bABPsych\b/i',
            
            // Communication
            '/\b(?:AB|A\.B\.)\s*Communication\b/i',
            '/\b(?:AB|A\.B\.)\s*Mass\s+Communication\b/i',
            
            // Political Science
            '/\b(?:AB|A\.B\.)\s*Political\s+Science\b/i',
            '/\bABPolSci\b/i',
            
            // Education
            '/\b(?:B\.?Ed|Bachelor.*?Education)\s*(?:Elementary|Secondary)?\b/i',
            '/\bBEED\b/i',
            '/\bBSED\b/i',
            '/\bBECEd\b/i'
        ];
        
        $extractedCourse = null;
        $confidence = 0;
        $bestMatch = '';
        
        // Try each pattern and find the BEST match (longest match wins)
        foreach ($coursePatterns as $idx => $pattern) {
            if (preg_match($pattern, $fullText, $matches)) {
                $match = trim($matches[0]);
                error_log("Pattern #{$idx} MATCHED: '{$match}' using pattern: {$pattern}");
                // Prefer longer, more specific matches
                if (strlen($match) > strlen($bestMatch)) {
                    $bestMatch = $match;
                    $extractedCourse = $match;
                    $confidence = 85; // High confidence for pattern match
                    error_log("  -> New best match (length " . strlen($match) . ")");
                }
            }
        }
        
        error_log("Final extracted course: " . ($extractedCourse ?? 'NONE'));
        error_log("==========================================");
        
        // If no pattern match, try to find course keywords with context
        if (!$extractedCourse) {
            $courseKeywords = [
                'Information Technology' => 'BS Information Technology',
                'Computer Science' => 'BS Computer Science',
                'Civil Engineering' => 'BS Civil Engineering',
                'Electrical Engineering' => 'BS Electrical Engineering',
                'Mechanical Engineering' => 'BS Mechanical Engineering',
                'Electronics and Communications Engineering' => 'BS Electronics and Communications Engineering',
                'Architecture' => 'BS Architecture',
                'Accountancy' => 'BS Accountancy',
                'Business Administration' => 'BS Business Administration',
                'Nursing' => 'BS Nursing',
                'Psychology' => 'BS Psychology',
                'Education' => 'Bachelor of Education',
                'Communication' => 'AB Communication',
                'Political Science' => 'AB Political Science'
            ];
            
            $bestSimilarity = 0;
            foreach ($courseKeywords as $keyword => $fullCourseName) {
                $similarity = $this->fuzzyMatch($keyword, $fullText);
                if ($similarity >= 70 && $similarity > $bestSimilarity) {
                    $bestSimilarity = $similarity;
                    $extractedCourse = $keyword;
                    $confidence = $similarity;
                }
            }
        }
        
        // Normalize course name
        if ($extractedCourse) {
            $normalized = $this->normalizeCourse($extractedCourse);
            return [
                'raw' => $extractedCourse,
                'normalized' => $normalized,
                'confidence' => $confidence,
                'found' => true
            ];
        }
        
        return [
            'raw' => null,
            'normalized' => null,
            'confidence' => 0,
            'found' => false
        ];
    }
    
    /**
     * Normalize course name for database lookup
     */
    private function normalizeCourse($rawCourse) {
        // Remove degree type prefixes (BS, AB, etc.)
        $normalized = preg_replace('/^(BS|B\.S\.|AB|A\.B\.|Bachelor\s+of\s+Science\s+in|Bachelor\s+of\s+Arts\s+in)\s+/i', '', $rawCourse);
        
        // Common abbreviation expansions - ONLY for STANDALONE abbreviations
        // Use word boundaries to avoid matching abbreviations inside words
        $expansions = [
            '/\bCS\b/i' => 'Computer Science',
            '/\bIT\b/i' => 'Information Technology',
            '/\bCE\b/i' => 'Civil Engineering',
            '/\bEE\b/i' => 'Electrical Engineering',
            '/\bME\b/i' => 'Mechanical Engineering',
            '/\bECE\b/i' => 'Electronics and Communications Engineering',
            '/\bArchi\b/i' => 'Architecture',
            '/\bBA\b/i' => 'Business Administration',
            '/\bCompSci\b/i' => 'Computer Science'
        ];
        
        foreach ($expansions as $pattern => $fullName) {
            $normalized = preg_replace($pattern, $fullName, $normalized);
        }
        
        // Add BS prefix back for consistency (if not already present)
        if (!preg_match('/^(BS|AB|B\.Ed)/i', $normalized)) {
            $normalized = 'BS ' . $normalized;
        }
        
        return trim($normalized);
    }
    
    /**
     * Extract year level
     */
    private function extractYearLevel($words) {
        $fullText = implode(' ', array_column($words, 'text'));
        
        $yearPatterns = [
            '/\b(1st|First|I)\s*Year\b/i' => '1st Year College',
            '/\b(2nd|Second|II)\s*Year\b/i' => '2nd Year College',
            '/\b(3rd|Third|III)\s*Year\b/i' => '3rd Year College',
            '/\b(4th|Fourth|IV)\s*Year\b/i' => '4th Year College',
            '/\b(5th|Fifth|V)\s*Year\b/i' => '5th Year College'
        ];
        
        foreach ($yearPatterns as $pattern => $yearLevel) {
            if (preg_match($pattern, $fullText)) {
                return [
                    'raw' => $yearLevel,
                    'normalized' => $yearLevel,
                    'confidence' => 85,
                    'found' => true
                ];
            }
        }
        
        return [
            'raw' => null,
            'normalized' => null,
            'confidence' => 0,
            'found' => false
        ];
    }
    
    /**
     * Extract university name
     */
    private function extractUniversity($words, $studentData) {
        $declaredUniversity = $studentData['university_name'] ?? '';
        $fullText = implode(' ', array_column($words, 'text'));
        
        if (empty($declaredUniversity)) {
            return [
                'found' => false,
                'confidence' => 0,
                'matched' => false
            ];
        }
        
        // Check if university name appears in OCR text
        $similarity = $this->fuzzyMatch($declaredUniversity, $fullText);
        
        return [
            'found' => $similarity >= 60,
            'confidence' => $similarity,
            'matched' => $similarity >= 70
        ];
    }
    
    /**
     * Extract academic year
     */
    private function extractAcademicYear($words) {
        $fullText = implode(' ', array_column($words, 'text'));
        
        // Pattern: 2024-2025, SY 2024-2025, A.Y. 2024-2025
        if (preg_match('/\b(\d{4})[-–]\s*(\d{4})\b/', $fullText, $matches)) {
            $year1 = $matches[1];
            $year2 = $matches[2];
            
            return [
                'raw' => "$year1-$year2",
                'confidence' => 90,
                'found' => true
            ];
        }
        
        return [
            'raw' => null,
            'confidence' => 0,
            'found' => false
        ];
    }
    
    /**
     * Extract student ID number
     */
    private function extractStudentId($words) {
        $fullText = implode(' ', array_column($words, 'text'));
        
        // Common patterns: ID No., Student No., etc.
        $patterns = [
            '/(?:ID|Student|Stud\.)\s*(?:No\.?|Number|#)?\s*:?\s*([A-Z0-9]{8,15})/i',
            '/\b([0-9]{4,6}[-\s]?[0-9]{4,6})\b/', // Dash or space separated
            '/\b(\d{8,15})\b/' // Pure numeric
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $fullText, $matches)) {
                return [
                    'raw' => trim($matches[1]),
                    'confidence' => 75,
                    'found' => true
                ];
            }
        }
        
        return [
            'raw' => null,
            'confidence' => 0,
            'found' => false
        ];
    }
    
    /**
     * Verify document type is enrollment/assessment form
     * IMPROVED: More specific keywords and stricter matching
     */
    private function verifyDocumentType($fullTextLower) {
        // CRITICAL KEYWORDS: Must have at least ONE of these
        $criticalKeywords = [
            'enrollment', 'assessment', 'eaf', 'tuition fee', 'billing statement',
            'certificate of registration', 'registration form'
        ];
        
        // SUPPORTING KEYWORDS: General education-related terms
        $supportingKeywords = [
            'semester', 'academic year', 'course', 'subject', 'units',
            'matriculation', 'student number', 'assessment form'
        ];
        
        // EXCLUDE if these grade-specific keywords are dominant
        $gradeKeywords = [
            'final grade', 'prelim', 'midterm', 'finals', 'general average',
            'grade point', 'remarks', 'passed', 'failed'
        ];
        
        $criticalFound = 0;
        $supportingFound = 0;
        $gradeFound = 0;
        
        foreach ($criticalKeywords as $keyword) {
            if (stripos($fullTextLower, $keyword) !== false) {
                $criticalFound++;
            }
        }
        
        foreach ($supportingKeywords as $keyword) {
            if (stripos($fullTextLower, $keyword) !== false) {
                $supportingFound++;
            }
        }
        
        foreach ($gradeKeywords as $keyword) {
            if (stripos($fullTextLower, $keyword) !== false) {
                $gradeFound++;
            }
        }
        
        // STRICTER LOGIC:
        // 1. Must have at least 1 critical keyword
        // 2. Must have at least 2 supporting keywords
        // 3. Must NOT have more grade keywords than enrollment keywords
        $isEnrollmentForm = ($criticalFound >= 1) && 
                           ($supportingFound >= 2) && 
                           ($gradeFound < ($criticalFound + $supportingFound));
        
        $totalFound = $criticalFound + $supportingFound;
        
        return [
            'is_enrollment_form' => $isEnrollmentForm,
            'keywords_found' => $totalFound,
            'critical_found' => $criticalFound,
            'supporting_found' => $supportingFound,
            'grade_keywords_found' => $gradeFound,
            'confidence' => $isEnrollmentForm ? min(100, ($totalFound * 15) + ($criticalFound * 20)) : 0
        ];
    }
    
    /**
     * Fuzzy text matching with similarity score
     */
    private function fuzzyMatch($needle, $haystack) {
        $needle = strtolower(trim($needle));
        $haystack = strtolower($haystack);
        
        // Normalize both strings by removing punctuation and extra spaces
        // This helps match "University - Cavite" with "University Cavite"
        $needleNormalized = preg_replace('/[^\w\s]/u', ' ', $needle); // Remove punctuation
        $needleNormalized = preg_replace('/\s+/', ' ', $needleNormalized); // Collapse spaces
        $needleNormalized = trim($needleNormalized);
        
        $haystackNormalized = preg_replace('/[^\w\s]/u', ' ', $haystack);
        $haystackNormalized = preg_replace('/\s+/', ' ', $haystackNormalized);
        $haystackNormalized = trim($haystackNormalized);
        
        // Exact match on normalized strings
        if (strpos($haystackNormalized, $needleNormalized) !== false) {
            return 100;
        }
        
        // Also check original strings for backward compatibility
        if (strpos($haystack, $needle) !== false) {
            return 100;
        }
        
        // Split into words and check each
        $needleWords = preg_split('/\s+/', $needleNormalized);
        $haystackWords = preg_split('/\s+/', $haystackNormalized);
        $matchedWords = 0;
        
        // Count exact word matches
        foreach ($needleWords as $nWord) {
            if (strlen($nWord) >= 3) {
                foreach ($haystackWords as $hWord) {
                    if ($nWord === $hWord) {
                        $matchedWords++;
                        break; // Don't count the same haystack word multiple times
                    }
                }
            }
        }
        
        $wordMatchPercent = count($needleWords) > 0 ? ($matchedWords / count($needleWords)) * 100 : 0;
        
        // If we got a good word match, return it
        if ($wordMatchPercent >= 70) {
            return $wordMatchPercent;
        }
        
        // Levenshtein for fuzzy word matching (to catch typos)
        $fuzzyMatchedWords = 0;
        foreach ($needleWords as $nWord) {
            if (strlen($nWord) >= 3) {
                $bestSimilarity = 0;
                foreach ($haystackWords as $hWord) {
                    if (strlen($hWord) >= 3) {
                        $distance = levenshtein($nWord, $hWord);
                        $maxLen = max(strlen($nWord), strlen($hWord));
                        $similarity = max(0, 100 - ($distance / $maxLen) * 100);
                        $bestSimilarity = max($bestSimilarity, $similarity);
                    }
                }
                // Consider it a match if similarity is >= 80%
                if ($bestSimilarity >= 80) {
                    $fuzzyMatchedWords++;
                }
            }
        }
        
        $fuzzyMatchPercent = count($needleWords) > 0 ? ($fuzzyMatchedWords / count($needleWords)) * 100 : 0;
        
        return max($wordMatchPercent, $fuzzyMatchPercent);
    }
    
    /**
     * Improved fuzzy matching specifically for names (handles long compound names better)
     * - Checks partial matches (any word from name appears in text)
     * - Handles 2-character words (common in Filipino names like "DY", "GO", "TY")
     * - More lenient scoring for compound names
     */
    private function fuzzyMatchName($needle, $haystack) {
        $needle = strtolower(trim($needle));
        $haystack = strtolower($haystack);
        
        // Normalize both strings - remove ALL punctuation (commas, periods, etc.)
        $needleNormalized = preg_replace('/[^\w\s]/u', ' ', $needle);
        $needleNormalized = preg_replace('/\s+/', ' ', $needleNormalized);
        $needleNormalized = trim($needleNormalized);
        
        $haystackNormalized = preg_replace('/[^\w\s]/u', ' ', $haystack);
        $haystackNormalized = preg_replace('/\s+/', ' ', $haystackNormalized);
        $haystackNormalized = trim($haystackNormalized);
        
        error_log("  → Searching for: '{$needleNormalized}' in '{$haystackNormalized}'");
        
        // IMPROVEMENT 1: Exact substring match (100% confidence)
        if (strpos($haystackNormalized, $needleNormalized) !== false) {
            error_log("  → EXACT MATCH FOUND!");
            return 100;
        }
        
        // IMPROVEMENT 2: Split into words and count matches (handles compound names)
        $needleWords = preg_split('/\s+/', $needleNormalized);
        $haystackWords = preg_split('/\s+/', $haystackNormalized);
        
        error_log("  → Needle words: " . implode(', ', $needleWords));
        error_log("  → Haystack words: " . implode(', ', $haystackWords));
        
        $matchedWords = 0;
        $partialMatches = 0;
        $matchDetails = [];
        
        foreach ($needleWords as $nWord) {
            // IMPROVEMENT 3: Allow 2-character words (for "DY", "GO", "TY", etc.)
            if (strlen($nWord) >= 2) {
                $bestMatch = 0;
                $matchedWith = '';
                
                foreach ($haystackWords as $hWord) {
                    // Exact word match
                    if ($nWord === $hWord) {
                        $matchedWords++;
                        $bestMatch = 100;
                        $matchedWith = $hWord;
                        break;
                    }
                    
                    // Partial match (word starts with or contains needle)
                    if (strlen($hWord) >= 2 && strpos($hWord, $nWord) !== false) {
                        if ($bestMatch < 75) {
                            $partialMatches++;
                            $bestMatch = 75;
                            $matchedWith = $hWord . ' (partial)';
                        }
                    }
                    
                    // Levenshtein distance for typos (only for words >= 3 chars)
                    if (strlen($nWord) >= 3 && strlen($hWord) >= 3) {
                        $distance = levenshtein($nWord, $hWord);
                        $maxLen = max(strlen($nWord), strlen($hWord));
                        $similarity = max(0, 100 - ($distance / $maxLen) * 100);
                        
                        if ($similarity >= 70 && $similarity > $bestMatch) {
                            $bestMatch = $similarity;
                            $matchedWith = $hWord . ' (fuzzy ' . round($similarity) . '%)';
                        }
                    }
                }
                
                // RELAXED: Lower threshold from 75 to 70 for counting matches
                if ($bestMatch >= 70) {
                    $matchedWords++;
                    $matchDetails[] = "'{$nWord}' → '{$matchedWith}' ({$bestMatch}%)";
                } else {
                    $matchDetails[] = "'{$nWord}' → NOT FOUND";
                }
            }
        }
        
        error_log("  → Match details: " . implode('; ', $matchDetails));
        
        // IMPROVEMENT 4: Calculate percentage based on matched words
        $totalWords = count($needleWords);
        if ($totalWords === 0) return 0;
        
        $exactMatchPercent = ($matchedWords / $totalWords) * 100;
        
        // IMPROVEMENT 5: Bonus for partial matches (helps with OCR errors)
        $bonusPercent = min(20, ($partialMatches / $totalWords) * 50);
        
        $finalScore = min(100, $exactMatchPercent + $bonusPercent);
        
        error_log("  → FINAL: {$finalScore}% (matched: {$matchedWords}/{$totalWords} words, partials: {$partialMatches})");
        
        return $finalScore;
    }
    
    /**
     * Calculate overall confidence score
     */
    private function calculateOverallConfidence($extracted) {
        $scores = [];
        
        if (isset($extracted['student_name']['confidence'])) {
            $scores[] = $extracted['student_name']['confidence'];
        }
        
        if (isset($extracted['course']['confidence'])) {
            $scores[] = $extracted['course']['confidence'];
        }
        
        if (isset($extracted['year_level']['confidence'])) {
            $scores[] = $extracted['year_level']['confidence'];
        }
        
        if (isset($extracted['university']['confidence'])) {
            $scores[] = $extracted['university']['confidence'];
        }
        
        if (isset($extracted['document_type']['confidence'])) {
            $scores[] = $extracted['document_type']['confidence'];
        }
        
        return empty($scores) ? 0 : round(array_sum($scores) / count($scores), 1);
    }
    
    /**
     * Verify if extracted data passes validation
     * STRICTER: Name validation is now MANDATORY - cannot be bypassed
     */
    private function verifyExtractedData($extracted, $studentData) {
        // CRITICAL CHECKS: These MUST pass (cannot be bypassed)
        $firstNameFound = $extracted['student_name']['first_name_found'] ?? false;
        $lastNameFound = $extracted['student_name']['last_name_found'] ?? false;
        $isEnrollmentForm = $extracted['document_type']['is_enrollment_form'] ?? false;
        
        // SUPPORTING CHECKS: At least 2 out of 3 must pass
        $courseFound = $extracted['course']['found'] ?? false;
        $yearLevelFound = $extracted['year_level']['found'] ?? false;
        $universityFound = ($extracted['university']['found'] ?? false);
        
        $supportingPassed = ($courseFound ? 1 : 0) + ($yearLevelFound ? 1 : 0) + ($universityFound ? 1 : 0);
        
        // STRICT VALIDATION LOGIC:
        // 1. BOTH first name AND last name MUST be found (no exceptions)
        // 2. Document MUST be verified as enrollment form
        // 3. At least 2 out of 3 supporting checks must pass
        $passed = $firstNameFound && 
                  $lastNameFound && 
                  $isEnrollmentForm && 
                  ($supportingPassed >= 2);
        
        error_log("=== ENROLLMENT FORM VALIDATION ===");
        error_log("First Name Found: " . ($firstNameFound ? 'YES' : 'NO'));
        error_log("Last Name Found: " . ($lastNameFound ? 'YES' : 'NO'));
        error_log("Is Enrollment Form: " . ($isEnrollmentForm ? 'YES' : 'NO'));
        error_log("Supporting Checks Passed: $supportingPassed/3 (Course: " . ($courseFound ? 'Y' : 'N') . 
                  ", Year: " . ($yearLevelFound ? 'Y' : 'N') . ", University: " . ($universityFound ? 'Y' : 'N') . ")");
        error_log("OVERALL VALIDATION: " . ($passed ? 'PASSED ✓' : 'FAILED ✗'));
        error_log("===================================");
        
        return $passed;
    }
    
    /**
     * Create bypass response when OCR bypass is enabled
     * Returns mock successful verification with high confidence scores
     */
    private function createBypassResponse($studentData) {
        error_log("🔓 CREATING BYPASS RESPONSE - All verifications passed (bypass mode)");
        
        // Create mock extracted data that matches student input
        $mockExtracted = [
            'student_name' => [
                'first_name' => $studentData['first_name'] ?? '',
                'middle_name' => $studentData['middle_name'] ?? '',
                'last_name' => $studentData['last_name'] ?? '',
                'first_name_found' => true,
                'middle_name_found' => true,
                'last_name_found' => true,
                'confidence' => OCR_BYPASS_CONFIDENCE
            ],
            'university' => [
                'name' => $studentData['university_name'] ?? '',
                'found' => true,
                'confidence' => OCR_BYPASS_CONFIDENCE
            ],
            'course' => [
                'raw' => 'N/A',
                'normalized' => 'N/A',
                'found' => true,
                'confidence' => OCR_BYPASS_CONFIDENCE
            ],
            'year_level' => [
                'year_level' => $studentData['year_level'] ?? '',
                'found' => true,
                'confidence' => OCR_BYPASS_CONFIDENCE
            ],
            'document_type' => [
                'is_enrollment_form' => true,
                'keywords_found' => 10,
                'confidence' => OCR_BYPASS_CONFIDENCE
            ]
        ];
        
        return [
            'success' => true,
            'data' => $mockExtracted,
            'overall_confidence' => OCR_BYPASS_CONFIDENCE,
            'verification_passed' => true,
            'tsv_quality' => [
                'total_words' => 100,
                'high_confidence_words' => 95,
                'avg_confidence' => OCR_BYPASS_CONFIDENCE
            ],
            'bypass_mode' => true,
            'bypass_reason' => OCR_BYPASS_REASON
        ];
    }
    
    /**
     * Error response helper
     */
    private function errorResponse($message) {
        return [
            'success' => false,
            'error' => $message,
            'data' => null
        ];
    }
}
?>
