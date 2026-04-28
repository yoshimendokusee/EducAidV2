<?php

namespace App\Services;

use App\Traits\UsesDatabaseConnection;
use Exception;

/**
 * GradeValidationService (Laravel Compatible)
 * 
 * Handles per-subject grade validation using university-specific grading policies
 * Supports multiple grading scales:
 * - 1-5 scale (pass if ≤ 3.00)
 * - 0-4 scale (pass if ≥ 2.0)
 * - Percentage scale (pass if ≥ passing percentage)
 * - Letter grades (A-D system)
 * 
 * Migrated to Laravel with:
 * - Proper namespacing
 * - Dependency injection support
 * - Database trait for connection management
 * 
 * @package App\Services
 */
class GradeValidationService
{
    use UsesDatabaseConnection;

    /**
     * Initialize Grade Validation Service
     * 
     * @param resource|null $dbConnection Database connection (optional, will use global if null)
     */
    public function __construct($dbConnection = null)
    {
        $this->setConnection($dbConnection);
    }

    /**
     * Check if a subject grade is passing for a specific university
     * 
     * Uses PostgreSQL function if available, falls back to PHP logic
     * 
     * @param string $universityKey University key identifier
     * @param string $rawGrade Raw grade string
     * @return bool
     */
    public function isSubjectPassing($universityKey, $rawGrade)
    {
        try {
            // First try the PostgreSQL function
            $query = "SELECT grading.grading_is_passing($1::TEXT, $2::TEXT) AS is_passing";
            $result = $this->executeQuery($query, [$universityKey, $rawGrade]);

            if ($this->getRowCount($result) > 0) {
                $row = $this->fetchOne($result);
                return $row['is_passing'] ?? false;
            }

        } catch (Exception $e) {
            error_log("Grade validation error (trying fallback): " . $e->getMessage());
        }

        // Fallback: Get policy manually and validate in PHP
        try {
            $query = "SELECT scale_type, higher_is_better, passing_value 
                      FROM university_passing_policy 
                      WHERE university_key = $1 AND is_active = TRUE";
            $result = $this->executeQuery($query, [$universityKey]);

            if ($this->getRowCount($result) === 0) {
                error_log("No policy found for university: " . $universityKey);
                return false;
            }

            $policy = $this->fetchOne($result);

            // Convert grades to numbers
            $gradeNum = floatval($rawGrade);
            $passingNum = floatval($policy['passing_value']);

            // Apply logic based on scale type
            if ($policy['scale_type'] === 'NUMERIC_1_TO_5') {
                // For 1-5 scale: pass if grade <= 3.00
                $result = $gradeNum <= $passingNum;
                error_log("Fallback validation: {$gradeNum} <= {$passingNum} = " . ($result ? 'PASS' : 'FAIL'));
                return $result;
            } elseif ($policy['scale_type'] === 'NUMERIC_0_TO_4') {
                // For 0-4 scale: pass if grade >= passing value
                return $gradeNum >= $passingNum;
            }

            return false;

        } catch (Exception $e) {
            error_log("Fallback validation also failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Validate all subjects for an applicant
     * Returns eligibility status and list of failed subjects
     * 
     * @param string $universityKey University key
     * @param array $subjects Array of subjects with name, rawGrade, units, confidence
     * @return array Result array with keys: eligible, failedSubjects, totalSubjects, passedSubjects
     */
    public function validateApplicant($universityKey, $subjects)
    {
        $failedSubjects = [];
        $eligible = true;

        foreach ($subjects as $subject) {
            $subjectName = $subject['subject'] ?? $subject['name'] ?? 'Unknown Subject';
            $rawGrade = $subject['grade'] ?? $subject['rawGrade'] ?? '';
            $units = $subject['units'] ?? '';
            $confidence = $subject['confidence'] ?? 100;

            // Treat empty, low-confidence, or unrecognized grades as failing
            if (empty($rawGrade) || $confidence < 85) {
                $eligible = false;
                $confidenceNote = $confidence < 85 ? " (low OCR confidence: {$confidence}%)" : " (empty grade)";
                $failedSubjects[] = $subjectName . ': ' . $rawGrade . $confidenceNote;
                continue;
            }

            // Check if subject is passing
            if (!$this->isSubjectPassing($universityKey, $rawGrade)) {
                $eligible = false;
                $failedSubjects[] = $subjectName . ': ' . $rawGrade;
            }
        }

        return [
            'eligible' => $eligible,
            'failedSubjects' => $failedSubjects,
            'totalSubjects' => count($subjects),
            'passedSubjects' => count($subjects) - count($failedSubjects)
        ];
    }

    /**
     * Get university grading policy details
     * 
     * @param string $universityKey University key
     * @return array|null Policy array or null if not found
     */
    public function getUniversityGradingPolicy($universityKey)
    {
        try {
            $query = "SELECT * FROM university_passing_policy 
                      WHERE university_key = $1 AND is_active = TRUE";
            $result = $this->executeQuery($query, [$universityKey]);

            if ($this->getRowCount($result) > 0) {
                return $this->fetchOne($result);
            }

            return null;

        } catch (Exception $e) {
            error_log("Error fetching grading policy: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Normalize grade string to handle common OCR artifacts
     * 
     * @param string $rawGrade Raw grade string
     * @return string Normalized grade
     */
    public function normalizeGrade($rawGrade)
    {
        if (empty($rawGrade)) {
            return '';
        }

        $grade = trim($rawGrade);

        // Common OCR fixes
        $grade = str_replace(',', '.', $grade); // 3,00 → 3.00
        $grade = preg_replace('/O(?=\d)/', '0', $grade); // O3 → 03
        $grade = preg_replace('/(?<=\d)O(?=\d|$)/', '0', $grade); // 3O → 30
        $grade = str_replace('S', '5', $grade); // S → 5 in numeric context
        $grade = rtrim($grade, '°'); // Remove trailing degree symbol

        // Remove extra whitespace
        $grade = preg_replace('/\s+/', ' ', $grade);

        return $grade;
    }

    /**
     * Validate grade format based on expected patterns
     * 
     * @param string $grade Grade to validate
     * @param string $scaleType Grading scale type
     * @return bool
     */
    public function isValidGradeFormat($grade, $scaleType = 'NUMERIC_1_TO_5')
    {
        $grade = $this->normalizeGrade($grade);

        switch ($scaleType) {
            case 'NUMERIC_1_TO_5':
                // 1.00 to 5.00
                return preg_match('/^[1-5](?:\.\d{1,2})?$/', $grade);

            case 'NUMERIC_0_TO_4':
                // 0.00 to 4.00
                return preg_match('/^[0-4](?:\.\d{1,3})?$/', $grade);

            case 'PERCENTAGE':
                // 0 to 100
                return preg_match('/^(?:100|[1-9]?\d)(?:\.\d{1,2})?$/', $grade);

            case 'LETTER':
                // A, B, C, D (with optional +/-)
                return preg_match('/^[A-D][+\-]?$/', $grade);

            default:
                return false;
        }
    }

    /**
     * Get passing percentage for a university
     * 
     * @param string $universityKey University key
     * @return float|null Passing percentage or null if not found
     */
    public function getPassingPercentage($universityKey)
    {
        $policy = $this->getUniversityGradingPolicy($universityKey);
        return $policy ? floatval($policy['passing_value']) : null;
    }

    /**
     * Check if a letter grade is passing
     * 
     * @param string $grade Letter grade (A, B, C, D)
     * @return bool
     */
    public function isLetterGradePassing($grade)
    {
        $grade = strtoupper(trim($grade));

        // Standard: A and B pass, C and D fail
        // Some universities accept C as passing - this is configurable
        return in_array($grade, ['A', 'A+', 'A-', 'B', 'B+', 'B-']);
    }

    /**
     * Convert numerical grade to letter grade
     * 
     * @param float $numericalGrade Numerical grade
     * @param string $scaleType Scale type (NUMERIC_1_TO_5 or NUMERIC_0_TO_4)
     * @return string Letter grade
     */
    public function convertToLetterGrade($numericalGrade, $scaleType = 'NUMERIC_1_TO_5')
    {
        $grade = floatval($numericalGrade);

        if ($scaleType === 'NUMERIC_1_TO_5') {
            // 1-5 scale conversion
            if ($grade <= 1.75) return 'A';
            if ($grade <= 2.25) return 'B';
            if ($grade <= 2.75) return 'C';
            if ($grade <= 3.50) return 'D';
            return 'F';
        } elseif ($scaleType === 'NUMERIC_0_TO_4') {
            // 0-4 scale conversion
            if ($grade >= 3.75) return 'A';
            if ($grade >= 3.00) return 'B';
            if ($grade >= 2.00) return 'C';
            if ($grade >= 1.00) return 'D';
            return 'F';
        }

        return 'F';
    }
}
