<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * OcrProcessingService
 * Handles document processing using Tesseract OCR with optional image preprocessing.
 * Uses GD or Imagick for image processing (safer than raw commands).
 */
class OcrProcessingService
{
    private string $tesseractPath;
    private string $tempDir;
    private int $maxFileSize;
    private array $allowedExtensions;

    public function __construct()
    {
        $this->tesseractPath = config('ocr.tesseract_path', 'tesseract');
        $this->tempDir = storage_path('app/ocr-temp');
        $this->maxFileSize = config('ocr.max_file_size', 10 * 1024 * 1024); // 10MB
        $this->allowedExtensions = config('ocr.allowed_extensions', ['pdf', 'png', 'jpg', 'jpeg', 'tiff', 'bmp']);
        
        // Ensure temp directory exists
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0755, true);
        }
    }

    /**
     * Process uploaded grade document and extract subjects with grades
     *
     * @param string $filePath Path to the document file
     * @return array['success' => bool, 'subjects' => array, 'totalSubjects' => int, 'error' => string|null]
     */
    public function processGradeDocument(string $filePath): array
    {
        try {
            // Check for OCR bypass mode (for testing)
            if (config('ocr.bypass_enabled', false)) {
                Log::warning("⚠️ OCR BYPASS ACTIVE - Returning mock grade data for: " . basename($filePath));
                return [
                    'success' => true,
                    'subjects' => [],
                    'totalSubjects' => 0,
                ];
            }

            // Validate file
            if (!$this->validateFile($filePath)) {
                throw new Exception("Invalid file format or size");
            }

            // Preprocess the document
            $preprocessedFiles = $this->preprocessDocument($filePath);

            $allSubjects = [];

            // Process each page/file
            foreach ($preprocessedFiles as $processedFile) {
                try {
                    $tsvData = $this->runTesseract($processedFile);
                    $subjects = $this->parseTSVData($tsvData);
                    $allSubjects = array_merge($allSubjects, $subjects);
                } finally {
                    // Clean up temporary processed file
                    if (file_exists($processedFile) && $processedFile !== $filePath) {
                        @unlink($processedFile);
                    }
                }
            }

            return [
                'success' => true,
                'subjects' => $allSubjects,
                'totalSubjects' => count($allSubjects),
            ];
        } catch (Exception $e) {
            Log::error("OCR Processing error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'subjects' => [],
                'totalSubjects' => 0,
            ];
        }
    }

    /**
     * Validate uploaded file
     */
    private function validateFile(string $filePath): bool
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
     * @param string $filePath Path to the document
     * @return array Array of processed file paths
     */
    private function preprocessDocument(string $filePath): array
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
     * Process PDF document
     */
    private function processPDF(string $pdfPath): array
    {
        $processedFiles = [];

        try {
            // Check for Imagick availability
            if ($this->isImagickAvailable()) {
                $this->processPDFWithImagick($pdfPath, $processedFiles);
            } else {
                // Fallback: try alternative PDF processing
                $this->processPDFFallback($pdfPath, $processedFiles);
            }
        } catch (Exception $e) {
            Log::error("PDF processing error: " . $e->getMessage());
            $processedFiles[] = $pdfPath; // Fallback to original
        }

        return $processedFiles;
    }

    /**
     * Check if Imagick is available and working
     */
    private function isImagickAvailable(): bool
    {
        return extension_loaded('imagick') && class_exists('Imagick');
    }

    /**
     * Process PDF with Imagick
     */
    private function processPDFWithImagick(string $pdfPath, array &$processedFiles): void
    {
        try {
            $imagick = new \Imagick();
            $imagick->setResolution(300, 300);
            $imagick->readImage($pdfPath);

            $pageCount = $imagick->getNumberImages();

            for ($i = 0; $i < $pageCount; $i++) {
                $imagick->setIteratorIndex($i);
                $image = clone $imagick;

                // Basic image enhancement
                $this->enhanceImageBasic($image);

                // Save to temporary file
                $tempFile = $this->tempDir . '/page_' . $i . '_' . uniqid() . '.png';
                $image->writeImage($tempFile);
                $processedFiles[] = $tempFile;

                $image->clear();
            }

            $imagick->clear();
        } catch (Exception $e) {
            Log::error("Imagick PDF processing failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Fallback PDF processing without Imagick
     */
    private function processPDFFallback(string $pdfPath, array &$processedFiles): void
    {
        // Try using command-line tools if available
        if ($this->commandExists('pdftoppm')) {
            // Convert PDF pages to images using pdftoppm
            $outputPrefix = 'pdf_page_' . uniqid();

            $command = sprintf(
                'pdftoppm -png -r 300 %s %s/%s 2>&1',
                escapeshellarg($pdfPath),
                escapeshellarg($this->tempDir),
                escapeshellarg($outputPrefix)
            );

            exec($command, $output, $returnCode);

            if ($returnCode === 0) {
                // Find generated PNG files
                $pattern = $this->tempDir . '/' . $outputPrefix . '-*.png';
                $files = glob($pattern);
                if (!empty($files)) {
                    $processedFiles = array_merge($processedFiles, $files);
                    return;
                }
            }
        }

        // Ultimate fallback: use original PDF
        Log::warning("No PDF processing tools available, using original file");
        $processedFiles[] = $pdfPath;
    }

    /**
     * Process image file for better OCR
     */
    private function processImage(string $imagePath): string
    {
        try {
            if ($this->isImagickAvailable()) {
                return $this->processImageWithImagick($imagePath);
            } else {
                return $this->processImageFallback($imagePath);
            }
        } catch (Exception $e) {
            Log::error("Image processing error: " . $e->getMessage());
            return $imagePath; // Return original if processing fails
        }
    }

    /**
     * Process image with Imagick
     */
    private function processImageWithImagick(string $imagePath): string
    {
        try {
            $imagick = new \Imagick($imagePath);
            $this->enhanceImageBasic($imagick);

            // Save enhanced image
            $tempFile = $this->tempDir . '/enhanced_' . uniqid() . '.png';
            $imagick->writeImage($tempFile);
            $imagick->clear();

            return $tempFile;
        } catch (Exception $e) {
            Log::error("Imagick image processing failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Fallback image processing without Imagick
     */
    private function processImageFallback(string $imagePath): string
    {
        // Try using ImageMagick command line if available
        if ($this->commandExists('convert')) {
            $tempFile = $this->tempDir . '/enhanced_' . uniqid() . '.png';

            $command = sprintf(
                'convert %s -density 300 -colorspace Gray -normalize -contrast %s 2>&1',
                escapeshellarg($imagePath),
                escapeshellarg($tempFile)
            );

            exec($command, $output, $returnCode);

            if ($returnCode === 0 && file_exists($tempFile)) {
                return $tempFile;
            }
        }

        // Return original if no processing available
        return $imagePath;
    }

    /**
     * Basic image enhancement (safe operations only)
     */
    private function enhanceImageBasic(\Imagick $imagick): void
    {
        try {
            // Only use basic, safe operations
            $imagick->transformImageColorspace(1); // COLORSPACE_GRAY
            $imagick->setImageResolution(300, 300);
            $imagick->normalizeImage();
        } catch (Exception $e) {
            Log::error("Basic image enhancement error: " . $e->getMessage());
            // Continue without enhancement
        }
    }

    /**
     * Check if command exists in system PATH
     */
    private function commandExists(string $command): bool
    {
        $whereIsCommand = (PHP_OS_FAMILY === 'Windows') ? 'where' : 'which';

        $process = proc_open(
            "$whereIsCommand $command",
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );

        if (is_resource($process)) {
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $returnCode = proc_close($process);

            return $returnCode === 0;
        }

        return false;
    }

    /**
     * Run Tesseract OCR on processed file
     */
    private function runTesseract(string $filePath): string
    {
        // Generate TSV file in the temp directory
        $outputBase = $this->tempDir . '/ocr_' . uniqid();
        $tsvFile = $outputBase . '.tsv';

        // Build Tesseract command for TSV output
        $command = sprintf(
            '%s %s %s -l eng --oem 1 --psm 6 tsv 2>&1',
            escapeshellarg($this->tesseractPath),
            escapeshellarg($filePath),
            escapeshellarg($outputBase)
        );

        // Execute Tesseract
        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            Log::error("Tesseract execution failed: " . implode("\n", $output));
            throw new Exception("OCR processing failed");
        }

        // Read TSV output
        $tsvContent = file_get_contents($tsvFile);

        // Clean up
        if (file_exists($tsvFile)) {
            unlink($tsvFile);
        }

        return $tsvContent;
    }

    /**
     * Parse TSV data and extract subjects with grades
     */
    private function parseTSVData(string $tsvData): array
    {
        $subjects = [];
        $lines = explode("\n", $tsvData);

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
                    'height' => $height,
                ];
            }

            $lineData[$lineKey]['words'][] = [
                'text' => $text,
                'left' => $left,
                'width' => $width,
                'conf' => $conf,
            ];
        }

        // Process lines to extract subject-grade pairs
        foreach ($lineData as $line) {
            $extracted = $this->extractSubjectGradeFromLine($line);
            if ($extracted) {
                $subjects[] = $extracted;
            }
        }

        return $this->consolidateSubjects($subjects);
    }

    /**
     * Extract subject and grade from a line of words
     */
    private function extractSubjectGradeFromLine(array $line): ?array
    {
        // Sort words by horizontal position
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
                    'position' => $word['left'],
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
                    'name' => $subjectName,
                    'rawGrade' => $grade['grade'],
                    'confidence' => min($avgConfidence, $grade['conf']),
                    'originalLine' => $lineText,
                ];
            }
        }

        return null;
    }

    /**
     * Check if text looks like a grade
     */
    private function looksLikeGrade(string $text): bool
    {
        $normalized = $this->normalizeGrade($text);

        // Numeric patterns for common grading systems
        $patterns = [
            '/^[1-5](\.\d{1,2})?$/',          // 1-5 scale
            '/^[0-4](\.\d{1,3})?$/',          // 0-4 scale
            '/^\d{2,3}(\.\d{1,2})?$/',        // Percentage
            '/^[A-D][+-]?$/i',                // Letter grades
            '/^(INC|DRP|W|NG|P|F)$/i',        // Special grades
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
     */
    private function isHeaderLine(string $text): bool
    {
        $text = strtolower($text);
        $headerKeywords = [
            'subject', 'course', 'grade', 'final', 'units', 'credit',
            'semester', 'year', 'name', 'student', 'transcript',
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
     */
    private function cleanSubjectName(string $name): string
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
     */
    private function normalizeGrade(string $grade): string
    {
        $grade = trim($grade);

        // Common OCR fixes
        $grade = str_replace(',', '.', $grade);
        $grade = preg_replace('/O(?=\d)/', '0', $grade);
        $grade = preg_replace('/(?<=\d)O(?=\d|$)/', '0', $grade);
        $grade = str_replace('S', '5', $grade);
        $grade = rtrim($grade, '°');

        return $grade;
    }

    /**
     * Consolidate duplicate subjects
     */
    private function consolidateSubjects(array $subjects): array
    {
        $consolidated = [];
        $seen = [];

        foreach ($subjects as $subject) {
            $nameKey = strtolower(trim($subject['name']));

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
