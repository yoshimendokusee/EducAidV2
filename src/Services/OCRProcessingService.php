<?php

namespace App\Services;

use Exception;

/**
 * OCRProcessingService (Laravel Compatible)
 * 
 * Handles document processing using Tesseract OCR with TSV output
 * Safe version that handles missing Imagick extension gracefully
 * 
 * Migrated to Laravel with:
 * - Proper namespacing
 * - Dependency injection support
 * - Laravel Storage compatibility (future-proofed)
 * - Error handling for missing extensions
 * - OCR bypass support for development
 * 
 * @package App\Services
 */
class OCRProcessingService
{
    private $tesseractPath;
    private $tempDir;
    private $maxFileSize;
    private $allowedExtensions;
    private $ocrBypassEnabled = false;
    private $ocrBypassConfidence = 95.0;

    /**
     * Initialize OCR Processing Service
     * 
     * @param array $config Configuration options:
     *   - tesseract_path: Path to Tesseract binary (default: 'tesseract')
     *   - temp_dir: Temporary directory for processing (default: sys_get_temp_dir())
     *   - max_file_size: Maximum file size in bytes (default: 10MB)
     *   - allowed_extensions: Supported file extensions (default: pdf, png, jpg, jpeg, tiff, bmp)
     *   - ocr_bypass: Enable OCR bypass mode for testing (default: false)
     *   - ocr_bypass_confidence: Bypass confidence score (default: 95.0)
     */
    public function __construct($config = [])
    {
        $this->tesseractPath = $config['tesseract_path'] ?? 'tesseract';
        $this->tempDir = $config['temp_dir'] ?? sys_get_temp_dir();
        $this->maxFileSize = $config['max_file_size'] ?? (10 * 1024 * 1024); // 10MB
        $this->allowedExtensions = $config['allowed_extensions'] ?? ['pdf', 'png', 'jpg', 'jpeg', 'tiff', 'bmp'];
        
        // OCR Bypass support for development/testing
        $this->ocrBypassEnabled = $config['ocr_bypass'] ?? defined('OCR_BYPASS_ENABLED') ? OCR_BYPASS_ENABLED : false;
        $this->ocrBypassConfidence = $config['ocr_bypass_confidence'] ?? (defined('OCR_BYPASS_CONFIDENCE') ? OCR_BYPASS_CONFIDENCE : 95.0);
    }

    /**
     * Extract raw text and compute overall confidence for a document
     * Returns combined text plus the average word confidence from Tesseract TSV output
     * 
     * @param string $filePath Path to document file
     * @param array $options OCR options (language, psm, oem)
     * @return array Result array with keys: success, text, confidence, word_count, error
     */
    public function extractTextAndConfidence($filePath, $options = [])
    {
        try {
            // CHECK FOR OCR BYPASS MODE
            if ($this->ocrBypassEnabled) {
                error_log("⚠️  OCR BYPASS ACTIVE - Returning mock data for: " . basename($filePath));
                return [
                    'success' => true,
                    'text' => 'Mock OCR text - Bypass mode enabled',
                    'confidence' => $this->ocrBypassConfidence,
                    'word_count' => 100
                ];
            }

            if (!$this->validateFile($filePath)) {
                throw new Exception("Invalid file format or size");
            }

            $processedFiles = $this->preprocessDocument($filePath);

            $combinedTextParts = [];
            $confidenceSum = 0;
            $confidenceCount = 0;

            foreach ($processedFiles as $processedFile) {
                // Get TSV data for confidence metrics
                $tsvData = $this->runTesseract($processedFile, $options);
                $lines = explode("\n", $tsvData);

                if (!empty($lines)) {
                    array_shift($lines); // remove header
                }

                foreach ($lines as $line) {
                    if (!trim($line)) {
                        continue;
                    }

                    $columns = explode("\t", $line);
                    if (count($columns) < 12) {
                        continue;
                    }

                    $conf = floatval($columns[10]);
                    $text = trim($columns[11]);

                    if ($conf <= 0 || $text === '') {
                        continue;
                    }

                    $combinedTextParts[] = $text;
                    $confidenceSum += $conf;
                    $confidenceCount++;
                }

                if ($processedFile !== $filePath && file_exists($processedFile)) {
                    @unlink($processedFile);
                }
            }

            $combinedText = trim(implode(' ', $combinedTextParts));
            $averageConfidence = $confidenceCount > 0 ? round($confidenceSum / $confidenceCount, 2) : 0;

            return [
                'success' => true,
                'text' => $combinedText,
                'confidence' => $averageConfidence,
                'word_count' => $confidenceCount
            ];

        } catch (Exception $e) {
            error_log("OCR extract error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'text' => '',
                'confidence' => 0,
                'word_count' => 0
            ];
        }
    }

    /**
     * Process uploaded grade document and extract subjects with grades
     * 
     * @param string $filePath Path to grade document
     * @return array Result array with keys: success, subjects, totalSubjects, error
     */
    public function processGradeDocument($filePath)
    {
        try {
            // Validate file
            if (!$this->validateFile($filePath)) {
                throw new Exception("Invalid file format or size");
            }

            // Preprocess the document
            $preprocessedFiles = $this->preprocessDocument($filePath);

            $allSubjects = [];

            // Process each page/file
            $options = ['language' => 'eng', 'psm' => 6];

            foreach ($preprocessedFiles as $processedFile) {
                $tsvData = $this->runTesseract($processedFile, $options);
                $subjects = $this->parseTSVData($tsvData);
                $allSubjects = array_merge($allSubjects, $subjects);

                // Clean up temporary processed file
                if (file_exists($processedFile) && $processedFile !== $filePath) {
                    @unlink($processedFile);
                }
            }

            return [
                'success' => true,
                'subjects' => $allSubjects,
                'totalSubjects' => count($allSubjects)
            ];

        } catch (Exception $e) {
            error_log("OCR Processing error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'subjects' => []
            ];
        }
    }

    /**
     * Validate uploaded file
     * 
     * @param string $filePath Path to file
     * @return bool
     */
    private function validateFile($filePath)
    {
        if (!file_exists($filePath)) {
            return false;
        }

        // Check file size
        if (filesize($filePath) > $this->maxFileSize) {
            return false;
        }

        // Check file extension
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        return in_array($extension, $this->allowedExtensions);
    }

    /**
     * Preprocess document for better OCR results
     * 
     * @param string $filePath Path to document
     * @return array Array of processed file paths
     */
    private function preprocessDocument($filePath)
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $processedFiles = [];

        if ($extension === 'pdf') {
            // Handle PDF - split pages and process each
            $processedFiles = $this->processPDF($filePath);
        } else {
            // Handle image files
            $processedFile = $this->processImage($filePath);
            $processedFiles[] = $processedFile;
        }

        return $processedFiles;
    }

    /**
     * Process PDF document - split into pages
     * 
     * @param string $pdfPath Path to PDF file
     * @return array Array of processed page file paths
     */
    private function processPDF($pdfPath)
    {
        $processedFiles = [];

        try {
            // Check if Imagick extension is available
            if (extension_loaded('imagick') && class_exists('Imagick')) {
                // Try Imagick processing in a safe way
                $processedFiles = $this->processPDFWithImagick($pdfPath);
            } else {
                // Fallback: use original file if Imagick not available
                error_log("Imagick not available, processing PDF as single file");
                $processedFiles[] = $pdfPath;
            }

        } catch (Exception $e) {
            error_log("PDF processing error: " . $e->getMessage());
            $processedFiles[] = $pdfPath; // Fallback to original
        }

        return $processedFiles;
    }

    /**
     * Process PDF with Imagick in a safe manner
     * 
     * @param string $pdfPath Path to PDF file
     * @return array Array of processed page file paths
     * @throws Exception
     */
    private function processPDFWithImagick($pdfPath)
    {
        $processedFiles = [];

        try {
            // Dynamic creation to avoid type errors
            $imagickClass = '\Imagick';
            $imagick = new $imagickClass();
            $imagick->setResolution(300, 300);
            $imagick->readImage($pdfPath);

            $pageCount = $imagick->getNumberImages();

            for ($i = 0; $i < $pageCount; $i++) {
                $imagick->setIteratorIndex($i);
                $image = clone $imagick;

                // Basic enhancement
                $this->enhanceImageForOCR($image);

                // Save to temporary file
                $tempFile = $this->tempDir . '/page_' . $i . '_' . uniqid() . '.png';
                $image->writeImage($tempFile);
                $processedFiles[] = $tempFile;

                $image->clear();
            }

            $imagick->clear();

        } catch (Exception $e) {
            error_log("Imagick PDF processing failed: " . $e->getMessage());
            throw $e;
        }

        return $processedFiles;
    }

    /**
     * Process image file for better OCR
     * 
     * @param string $imagePath Path to image file
     * @return string Processed image path (or original if processing fails)
     */
    private function processImage($imagePath)
    {
        try {
            if (extension_loaded('imagick') && class_exists('Imagick')) {
                return $this->processImageWithImagick($imagePath);
            } else {
                error_log("Imagick not available, using original image");
            }
        } catch (Exception $e) {
            error_log("Image processing error: " . $e->getMessage());
        }

        return $imagePath; // Return original if processing fails
    }

    /**
     * Process image with Imagick safely
     * 
     * @param string $imagePath Path to image file
     * @return string Path to processed image
     * @throws Exception
     */
    private function processImageWithImagick($imagePath)
    {
        try {
            // Dynamic creation to avoid type errors
            $imagickClass = '\Imagick';
            $imagick = new $imagickClass($imagePath);

            $this->enhanceImageForOCR($imagick);

            // Save enhanced image
            $tempFile = $this->tempDir . '/enhanced_' . uniqid() . '.png';
            $imagick->writeImage($tempFile);
            $imagick->clear();

            return $tempFile;

        } catch (Exception $e) {
            error_log("Imagick image processing failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Enhance image for better OCR results
     * 
     * @param object $imagick Imagick object
     * @return void
     */
    private function enhanceImageForOCR($imagick)
    {
        try {
            // Only proceed if we have a valid Imagick object
            if (!$imagick || !is_object($imagick)) {
                return;
            }

            // Convert to grayscale
            $imagick->transformImageColorspace(1); // COLORSPACE_GRAY = 1

            // Set resolution for better OCR (300-400 DPI)
            $imagick->setImageResolution(350, 350);
            $imagick->setResolution(350, 350);

            // Deskew if needed (auto-rotate)
            $imagick->deskewImage(40);

            // Enhance contrast and brightness
            $imagick->normalizeImage();
            $imagick->contrastImage(1);

            // Apply unsharp mask to improve text clarity
            $imagick->unsharpMaskImage(0, 0.5, 1, 0.05);

            // Binarize (convert to black and white)
            // Use numeric value instead of constant to avoid undefined constant error
            $quantum = method_exists($imagick, 'getQuantum') ? $imagick->getQuantum() : 65535;
            $imagick->thresholdImage(0.5 * $quantum);

        } catch (Exception $e) {
            error_log("Image enhancement error: " . $e->getMessage());
        }
    }

    /**
     * Run Tesseract OCR on processed file
     * 
     * @param string $filePath Path to file to process
     * @param array $options OCR options
     * @return string TSV output from Tesseract
     * @throws Exception
     */
    private function runTesseract($filePath, $options = [])
    {
        $language = $options['language'] ?? 'eng';
        $psm = intval($options['psm'] ?? 6);
        $oem = intval($options['oem'] ?? 1);

        // Generate output base name in the SAME directory as the input file
        $fileDir = dirname($filePath);
        $outputBase = $fileDir . '/ocr_' . uniqid();
        $tsvFile = $outputBase . '.tsv'; // This is where Tesseract will create the file

        // Build Tesseract command for TSV output
        // Note: outputBase should NOT include extension - Tesseract adds it
        $command = sprintf(
            '%s %s %s -l %s --oem %d --psm %d tsv 2>&1',
            escapeshellarg($this->tesseractPath),
            escapeshellarg($filePath),
            escapeshellarg($outputBase),
            escapeshellarg($language),
            (int)$oem,
            (int)$psm
        );

        error_log("Tesseract command: " . $command);

        // Execute Tesseract
        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            error_log("Tesseract execution failed: " . implode("\n", $output));
            throw new Exception("OCR processing failed: " . implode(' ', $output));
        }

        // Check if TSV file was created
        if (!file_exists($tsvFile)) {
            error_log("TSV file not found at: " . $tsvFile);
            error_log("Command output: " . implode("\n", $output));
            throw new Exception("Tesseract did not create expected output file");
        }

        // Read TSV output
        $tsvContent = file_get_contents($tsvFile);

        if ($tsvContent === false) {
            error_log("Failed to read TSV file: " . $tsvFile);
            throw new Exception("Failed to read OCR output file");
        }

        // Clean up
        if (file_exists($tsvFile)) {
            @unlink($tsvFile);
        }

        return $tsvContent;
    }

    /**
     * Parse TSV data and extract subjects with grades
     * 
     * @param string $tsvData TSV data from Tesseract
     * @return array Array of extracted subjects
     */
    private function parseTSVData($tsvData)
    {
        $subjects = [];
        $lines = explode("\n", $tsvData);

        error_log("OCR TSV Data - Total lines: " . count($lines));

        // Skip header line
        array_shift($lines);

        $lineData = [];

        foreach ($lines as $line) {
            if (empty(trim($line))) {
                continue;
            }

            $columns = explode("\t", $line);
            if (count($columns) < 12) {
                continue;
            } // TSV should have 12 columns

            $pageNum = $columns[1];
            $blockNum = $columns[2];
            $parNum = $columns[3];
            $lineNum = $columns[4];
            $left = intval($columns[6]);
            $top = intval($columns[7]);
            $width = intval($columns[8]);
            $height = intval($columns[9]);
            $conf = intval($columns[10]);
            $text = trim($columns[11]);

            if (empty($text) || $conf < 30) {
                continue;
            } // Skip low confidence or empty text

            $lineKey = "{$pageNum}-{$blockNum}-{$parNum}-{$lineNum}";

            // Group words by line
            if (!isset($lineData[$lineKey])) {
                $lineData[$lineKey] = [
                    'words' => [],
                    'top' => $top,
                    'height' => $height
                ];
            }

            $lineData[$lineKey]['words'][] = [
                'text' => $text,
                'left' => $left,
                'width' => $width,
                'conf' => $conf
            ];
        }

        // Process lines to extract subject-grade pairs
        error_log("OCR Processing - Lines grouped: " . count($lineData));

        foreach ($lineData as $lineKey => $line) {
            $extracted = $this->extractSubjectGradeFromLine($line);
            if ($extracted && isset($extracted['subject']) && isset($extracted['grade'])) {
                $subjects[] = $extracted;
                error_log("OCR Found subject: " . $extracted['subject'] . " Grade: " . $extracted['grade']);
            }
        }

        error_log("OCR Final subjects found: " . count($subjects));
        return $this->consolidateSubjects($subjects);
    }

    /**
     * Extract subject and grade from a line of words
     * 
     * @param array $line Array of words with positions
     * @return array|null Extracted subject-grade pair or null
     */
    private function extractSubjectGradeFromLine($line)
    {
        // Sort words by horizontal position (left coordinate)
        usort($line['words'], function ($a, $b) {
            return $a['left'] - $b['left'];
        });

        $lineText = '';
        $grades = [];
        $avgConfidence = 0;
        $wordCount = 0;

        foreach ($line['words'] as $word) {
            $lineText .= $word['text'] . ' ';
            $avgConfidence += $word['conf'];
            $wordCount++;

            // Check if word looks like a grade
            if ($this->looksLikeGrade($word['text'])) {
                $grades[] = [
                    'grade' => $this->normalizeGrade($word['text']),
                    'conf' => $word['conf'],
                    'position' => $word['left']
                ];
            }
        }

        $avgConfidence = $wordCount > 0 ? $avgConfidence / $wordCount : 0;
        $lineText = trim($lineText);

        // Skip header-like lines
        if ($this->isHeaderLine($lineText)) {
            return null;
        }

        // Look for subject name and grade
        if (!empty($grades) && !empty($lineText)) {
            // Use the rightmost grade (usually final grade)
            $grade = end($grades);

            // Extract subject name (remove the grade from line text)
            $subjectName = trim(str_replace($grade['grade'], '', $lineText));
            $subjectName = $this->cleanSubjectName($subjectName);

            if (!empty($subjectName) && strlen($subjectName) > 2) {
                return [
                    'subject' => $subjectName,
                    'grade' => $grade['grade'],
                    'confidence' => min($avgConfidence, $grade['conf']),
                    'originalLine' => $lineText
                ];
            }
        }

        return null;
    }

    /**
     * Check if text looks like a grade
     * 
     * @param string $text Text to check
     * @return bool
     */
    private function looksLikeGrade($text)
    {
        $normalized = $this->normalizeGrade($text);

        // Numeric patterns for common grading systems
        $patterns = [
            '/^[1-5](\.\d{1,2})?$/',           // 1-5 scale
            '/^[0-4](\.\d{1,3})?$/',           // 0-4 scale
            '/^\d{2,3}(\.\d{1,2})?$/',         // Percentage
            '/^[A-D][+-]?$/i',                 // Letter grades
            '/^(INC|DRP|W|NG|P|F)$/i'          // Special grades
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalized)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if line is a header
     * 
     * @param string $text Text to check
     * @return bool
     */
    private function isHeaderLine($text)
    {
        $text = strtolower($text);
        $headerKeywords = [
            'subject', 'course', 'grade', 'final', 'units', 'credit',
            'semester', 'year', 'name', 'student', 'transcript'
        ];

        foreach ($headerKeywords as $keyword) {
            if (strpos($text, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Clean and normalize subject name
     * 
     * @param string $name Subject name
     * @return string Cleaned subject name
     */
    private function cleanSubjectName($name)
    {
        // Remove course codes (like "CS101", "MATH201")
        $name = preg_replace('/\b[A-Z]{2,4}\s*\d{3,4}\b/i', '', $name);

        // Remove common prefixes/suffixes
        $name = preg_replace('/\b(lecture|lab|laboratory|lec|unit|units|credit|credits)\b/i', '', $name);

        // Remove extra whitespace and punctuation
        $name = preg_replace('/[^\w\s&()-]/', ' ', $name);
        $name = preg_replace('/\s+/', ' ', $name);

        return trim($name);
    }

    /**
     * Normalize grade text
     * 
     * @param string $grade Grade text to normalize
     * @return string Normalized grade
     */
    private function normalizeGrade($grade)
    {
        $grade = trim($grade);

        // Common OCR fixes
        $grade = str_replace(',', '.', $grade);

        // Fix O to 0 - handle cases like "2O5" -> "2.05"
        if (strpos($grade, 'O') !== false) {
            // If it looks like a decimal with O instead of 0
            if (preg_match('/^(\d+)O(\d+)$/', $grade, $matches)) {
                $grade = $matches[1] . '.0' . $matches[2];
            } else {
                // General O to 0 replacement
                $grade = str_replace('O', '0', $grade);
            }
        }

        // Fix S to 5 - handle cases like "S.00" -> "5.00"
        if (strpos($grade, 'S') !== false) {
            // If it starts with S and looks like a grade
            if (preg_match('/^S\.?\d*$/', $grade)) {
                $grade = str_replace('S', '5', $grade);
            }
        }

        // Remove degree symbol
        $grade = rtrim($grade, '°');

        return $grade;
    }

    /**
     * Consolidate duplicate subjects
     * 
     * @param array $subjects Array of subjects
     * @return array Consolidated subjects
     */
    private function consolidateSubjects($subjects)
    {
        $consolidated = [];
        $seen = [];

        foreach ($subjects as $subject) {
            $nameKey = strtolower(trim($subject['subject']));

            // Skip very short or generic names
            if (strlen($nameKey) < 3 || in_array($nameKey, ['', 'total', 'gpa', 'average'])) {
                continue;
            }

            if (!isset($seen[$nameKey])) {
                $consolidated[] = $subject;
                $seen[$nameKey] = count($consolidated) - 1;
            } else {
                // Keep the one with higher confidence
                $existingIndex = $seen[$nameKey];
                if ($subject['confidence'] > $consolidated[$existingIndex]['confidence']) {
                    $consolidated[$existingIndex] = $subject;
                }
            }
        }

        return $consolidated;
    }
}
