<?php
include __DIR__ . '/../../config/database.php';
include 'debug_logger.php';
include __DIR__ . '/../../includes/workflow_control.php';
// Lightweight JSON wrappers in case ext/json is unavailable in some environments
if (!function_exists('safe_json_encode')) {
  function safe_json_encode($data) {
    if (extension_loaded('json')) {
      return @call_user_func('json_encode', $data);
    }
    // Fallback: serialize if JSON is unavailable
    return serialize($data);
  }
}
if (!function_exists('safe_json_decode')) {
  function safe_json_decode($str, $assoc = false) {
    if (extension_loaded('json')) {
      return @call_user_func('json_decode', $str, $assoc);
    }
    // Fallback: try to unserialize
    $data = @unserialize($str);
    return $data === false && $str !== 'b:0;' ? null : $data;
  }
}
// Check if student is logged in
if (session_status() === PHP_SESSION_NONE) {
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
}
// Redirect if not logged in
if (!isset($_SESSION['student_username'])) {
    header("Location: ../../unified_login.php");
    exit;
}

// Fetch student info for header dropdown
$studentId = $_SESSION['student_id'];
$student_info_query = "SELECT last_login, first_name, last_name FROM students WHERE student_id = $1";
$student_info_result = pg_query_params($connection, $student_info_query, [$studentId]);
$student_info = pg_fetch_assoc($student_info_result);

// Distribution workflow status determines whether uploads are globally available
$workflow_status = getWorkflowStatus($connection);
$distribution_status = $workflow_status['distribution_status'] ?? 'inactive';
$uploads_feature_enabled = $workflow_status['uploads_enabled'] ?? false;
$uploads_suspended = !$uploads_feature_enabled || $distribution_status !== 'active';
$distribution_status_label = ucwords(str_replace('_', ' ', $distribution_status));
$distribution_status_label_lower = strtolower($distribution_status_label);

// REDIRECT if distribution is not active - uploads page should not be accessible at all
if ($uploads_suspended) {
    $_SESSION['error_message'] = "Document uploads are currently unavailable. The distribution cycle is {$distribution_status_label_lower}. Please check back when a new distribution cycle begins.";
    header("Location: student_homepage.php");
    exit;
}

// Block upload actions when distribution is closed, while informing AJAX callers cleanly
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $uploads_suspended) {
  $ajaxPostKeys = [
    'debug_test',
    'processEnrollmentOcr',
    'processOcr',
    'processIdPictureOcr',
    'processLetterOcr',
    'processCertificateOcr',
    'processGradesOcr',
    'cleanup_temp',
    'check_existing',
    'sendOtp',
    'verifyOtp',
    'test_db'
  ];

  $isAjaxPostAttempt = false;
  foreach ($ajaxPostKeys as $ajaxKey) {
    if (isset($_POST[$ajaxKey])) {
      $isAjaxPostAttempt = true;
      break;
    }
  }

  $closureMessage = 'Document uploads are currently closed because the distribution cycle is ' . $distribution_status_label_lower . '. Please wait until the next cycle opens.';

  if ($isAjaxPostAttempt) {
    header('Content-Type: application/json');
    echo json_encode([
      'status' => 'error',
      'message' => $closureMessage,
      'distribution_status' => $distribution_status
    ]);
    exit;
  }

  $_SESSION['upload_fail'] = $closureMessage;
  header('Location: upload_document.php');
  exit;
}

// Enhanced flash messaging for upload status
$flash_success = false;
$flash_fail = false;
$flash_partial = false;
$success_message = "";
$error_message = "";
$partial_message = "";

if (isset($_SESSION['upload_success'])) {
    $flash_success = true;
    $success_message = $_SESSION['upload_success'];
    unset($_SESSION['upload_success']);
}
if (isset($_SESSION['upload_fail'])) {
    $flash_fail = true;
    $error_message = $_SESSION['upload_fail'];
    unset($_SESSION['upload_fail']);
}
if (isset($_SESSION['upload_partial'])) {
    $flash_partial = true;
    $partial_message = $_SESSION['upload_partial'];
    unset($_SESSION['upload_partial']);
}

// Get student ID and check if they should even see this page
$student_id = $_SESSION['student_id'];

// Helper: check if a column exists (case-insensitive)
if (!function_exists('ea_col_exists')) {
  function ea_col_exists($conn, string $table, string $column, string $schema = 'public'): bool {
    $res = @pg_query_params($conn, "SELECT 1 FROM information_schema.columns WHERE table_schema=$1 AND table_name=$2 AND column_name=$3", [strtolower($schema), strtolower($table), strtolower($column)]);
    return $res && (bool)pg_fetch_row($res);
  }
}

// Determine a safe student registration/application date
$student_application_date = null;
$studentDateCol = null;
if (ea_col_exists($connection, 'students', 'application_date')) {
  $studentDateCol = 'application_date';
} elseif (ea_col_exists($connection, 'students', 'created_at')) {
  $studentDateCol = 'created_at';
}
if ($studentDateCol) {
  $dateRes = @pg_query_params($connection, "SELECT $studentDateCol AS reg_date FROM students WHERE student_id = $1", [$student_id]);
  if ($dateRes && ($dateRow = pg_fetch_assoc($dateRes))) {
    $student_application_date = $dateRow['reg_date'] ?? null;
  }
}

// Check if documents were uploaded through upload_document.php (by checking file path pattern)
// Only count documents uploaded through this interface, not from admin/registration
$query = "SELECT COUNT(*) AS total_uploaded FROM documents 
          WHERE student_id = $1 AND type IN ('id_picture') 
          AND file_path LIKE '%/assets/uploads/student/%'";
// Check if student needs to upload documents (existing students) or redirected here incorrectly (new registrants)
$student_check_query = "SELECT needs_document_upload, application_date, status, first_name, last_name, 
                               uploads_confirmed, uploads_confirmed_at FROM students WHERE student_id = $1";
$student_check_result = pg_query_params($connection, $student_check_query, [$student_id]);
$student_info = pg_fetch_assoc($student_check_result);

// Redirect new registrants who shouldn't see this page
if ($student_info && !$student_info['needs_document_upload']) {
    header("Location: student_homepage.php");
    exit;
}

// Check if uploads are already confirmed (locked)
$uploads_locked = $student_info && $student_info['uploads_confirmed'] === 't';
$uploads_confirmed_at = $student_info['uploads_confirmed_at'] ?? null;

// Check if all required documents are uploaded
$query = "SELECT COUNT(*) AS total_uploaded FROM documents WHERE student_id = $1 AND type IN ('id_picture')";
/** @phpstan-ignore-next-line */
$result = pg_query_params($connection, $query, [$student_id]);
$row = pg_fetch_assoc($result);

// Check if grades are uploaded through upload_document.php
$grades_query = "SELECT COUNT(*) AS grades_uploaded FROM documents 
                WHERE student_id = $1 AND type = 'academic_grades'
                AND file_path LIKE '%/assets/uploads/student/%'";
$grades_result = pg_query_params($connection, $grades_query, [$student_id]);
$grades_row = pg_fetch_assoc($grades_result);

// Check if EAF is uploaded through upload_document.php
$eaf_query = "SELECT COUNT(*) AS eaf_uploaded FROM documents 
              WHERE student_id = $1 AND type = 'eaf'
              AND file_path LIKE '%/assets/uploads/student/%'";
$eaf_result = pg_query_params($connection, $eaf_query, [$student_id]);
$eaf_row = pg_fetch_assoc($eaf_result);

// Get latest grade upload status from grade_uploads (only uploads from this page)
$latest_grades_query = "SELECT * FROM grade_uploads WHERE student_id = $1 
                       AND file_path LIKE '%/assets/uploads/student/%'
                       ORDER BY upload_date DESC LIMIT 1";
$latest_grades_result = pg_query_params($connection, $latest_grades_query, [$student_id]);
$latest_grade_upload = pg_fetch_assoc($latest_grades_result);

// Get uploaded ID picture details (prefer uploads from this page; fallback to any source)
$id_picture_query = "SELECT * FROM documents WHERE student_id = $1 AND type = 'id_picture' 
          AND file_path LIKE '%/assets/uploads/student/%' ORDER BY upload_date DESC LIMIT 1";
$id_picture_result = pg_query_params($connection, $id_picture_query, [$student_id]);
$uploaded_id_picture = pg_fetch_assoc($id_picture_result);
if (!$uploaded_id_picture) {
  $id_picture_any_q = "SELECT * FROM documents WHERE student_id = $1 AND type = 'id_picture' ORDER BY upload_date DESC LIMIT 1";
  $id_picture_any_r = pg_query_params($connection, $id_picture_any_q, [$student_id]);
  $uploaded_id_picture = pg_fetch_assoc($id_picture_any_r);
}

// Get uploaded grades details (prefer uploads from this page; fallback to any source)
$uploaded_grades_query = "SELECT * FROM documents WHERE student_id = $1 AND type = 'academic_grades'
             AND file_path LIKE '%/assets/uploads/student/%' ORDER BY upload_date DESC LIMIT 1";
$uploaded_grades_result = pg_query_params($connection, $uploaded_grades_query, [$student_id]);
$uploaded_grades = pg_fetch_assoc($uploaded_grades_result);
if (!$uploaded_grades) {
  $grades_any_q = "SELECT * FROM documents WHERE student_id = $1 AND type = 'academic_grades' ORDER BY upload_date DESC LIMIT 1";
  $grades_any_r = pg_query_params($connection, $grades_any_q, [$student_id]);
  $uploaded_grades = pg_fetch_assoc($grades_any_r);
}

// Get uploaded EAF details (prefer uploads from this page; fallback to any source)
$uploaded_eaf_query = "SELECT * FROM documents WHERE student_id = $1 AND type = 'eaf'
            AND file_path LIKE '%/assets/uploads/student/%' ORDER BY upload_date DESC LIMIT 1";
$uploaded_eaf_result = pg_query_params($connection, $uploaded_eaf_query, [$student_id]);
$uploaded_eaf = pg_fetch_assoc($uploaded_eaf_result);
if (!$uploaded_eaf) {
  $eaf_any_q = "SELECT * FROM documents WHERE student_id = $1 AND type = 'eaf' ORDER BY upload_date DESC LIMIT 1";
  $eaf_any_r = pg_query_params($connection, $eaf_any_q, [$student_id]);
  $uploaded_eaf = pg_fetch_assoc($eaf_any_r);
}

// Get uploaded Letter to the Mayor (prefer uploads from this page; fallback to any source)
$uploaded_letter_query = "SELECT * FROM documents WHERE student_id = $1 AND type = 'letter_to_mayor' 
            AND file_path LIKE '%/assets/uploads/student/%' ORDER BY upload_date DESC LIMIT 1";
$uploaded_letter_result = pg_query_params($connection, $uploaded_letter_query, [$student_id]);
$uploaded_letter = pg_fetch_assoc($uploaded_letter_result);
if (!$uploaded_letter) {
  $letter_any_q = "SELECT * FROM documents WHERE student_id = $1 AND type = 'letter_to_mayor' ORDER BY upload_date DESC LIMIT 1";
  $letter_any_r = pg_query_params($connection, $letter_any_q, [$student_id]);
  $uploaded_letter = pg_fetch_assoc($letter_any_r);
}

// Get uploaded Certificate of Indigency (prefer uploads from this page; fallback to any source)
$uploaded_cert_query = "SELECT * FROM documents WHERE student_id = $1 AND type = 'certificate_of_indigency' 
            AND file_path LIKE '%/assets/uploads/student/%' ORDER BY upload_date DESC LIMIT 1";
$uploaded_cert_result = pg_query_params($connection, $uploaded_cert_query, [$student_id]);
$uploaded_certificate = pg_fetch_assoc($uploaded_cert_result);
if (!$uploaded_certificate) {
  $cert_any_q = "SELECT * FROM documents WHERE student_id = $1 AND type = 'certificate_of_indigency' ORDER BY upload_date DESC LIMIT 1";
  $cert_any_r = pg_query_params($connection, $cert_any_q, [$student_id]);
  $uploaded_certificate = pg_fetch_assoc($cert_any_r);
}

// Consistent booleans for UI (any source)
$has_id_any = !empty($uploaded_id_picture);
$has_grades_any = !empty($uploaded_grades);
$has_eaf_any = !empty($uploaded_eaf);
// Optional documents (not part of required progress)
$has_letter_any = !empty($uploaded_letter);
$has_certificate_any = !empty($uploaded_certificate);

// All are required now: ID, Academic Grades, EAF, Letter to the Mayor, Certificate of Indigency
$allDocumentsUploaded = (
  $has_id_any &&
  $has_grades_any &&
  $has_eaf_any &&
  $has_letter_any &&
  $has_certificate_any
);

$missing_documents = [];
if (!$has_id_any) { $missing_documents[] = 'ID Picture'; }
if (!$has_grades_any) { $missing_documents[] = 'Academic Grades'; }
if (!$has_eaf_any) { $missing_documents[] = 'Enrollment Assessment Form'; }
if (!$has_letter_any) { $missing_documents[] = 'Letter to the Mayor'; }
if (!$has_certificate_any) { $missing_documents[] = 'Certificate of Indigency'; }

// If documents are not complete, clear any flash so form shows cleanly after a rejection
if (!$allDocumentsUploaded) {
  $flash_success = false;
  $flash_fail = false;
}

// Function to get university grading policy for dynamic display
function getUniversityGradingPolicy($connection, $student_id) {
    try {
        // Get student's university by joining with universities table
        $university_query = "SELECT u.name, u.code FROM students s 
                            JOIN universities u ON s.university_id = u.university_id 
                            WHERE s.student_id = $1";
        $university_result = pg_query_params($connection, $university_query, [$student_id]);
        $university_data = pg_fetch_assoc($university_result);
        
        if (!$university_data) {
            return null;
        }
        
        $university_name = $university_data['name'];
        $university_key = $university_data['code']; // Use the code directly from universities table
        
        if (!$university_key) {
            // Return default Philippine grading system if no specific policy found
            return [
                'university_name' => $university_name,
                'scale_type' => 'gpa',
                'higher_is_better' => false,
                'passing_value' => 3.00,
                'highest_value' => 1.00,
                'passing_description' => '1.00-3.00 (Passing) • 75%-100% (Passing)'
            ];
        }
        
        // Get grading policy from database
        $policy_query = "SELECT * FROM grading.university_passing_policy WHERE university_key = $1 AND is_active = TRUE";
        $policy_result = pg_query_params($connection, $policy_query, [$university_key]);
        $policy = pg_fetch_assoc($policy_result);
        
        if ($policy) {
            $policy['university_name'] = $university_name;
            $policy['passing_description'] = generatePassingDescription($policy);
            return $policy;
        }
        
        return null;
    } catch (Exception $e) {
        error_log("Error fetching grading policy: " . $e->getMessage());
        return null;
    }
}

// Function to generate human-readable passing description
function generatePassingDescription($policy) {
    $scale_type = $policy['scale_type'];
    $higher_is_better = ($policy['higher_is_better'] === 't' || $policy['higher_is_better'] === true);
    
    if ($scale_type === 'NUMERIC_1_TO_5' || $scale_type === 'gpa') {
        if ($higher_is_better) {
            return $policy['passing_value'] . "-" . $policy['highest_value'] . " (Passing) • 75%-100% (Passing)";
        } else {
            return $policy['highest_value'] . "-" . $policy['passing_value'] . " (Passing) • 75%-100% (Passing)";
        }
    } elseif ($scale_type === 'NUMERIC_0_TO_4') {
        if ($higher_is_better) {
            return $policy['passing_value'] . "-" . $policy['highest_value'] . " (Passing)";
        } else {
            return $policy['highest_value'] . "-" . $policy['passing_value'] . " (Passing)";
        }
    } elseif ($scale_type === 'percentage') {
        return $policy['passing_value'] . "%-100% (Passing)";
    } else {
        return "See university grading policy (" . $scale_type . ")";
    }
}

// Get university grading policy for current student
$university_policy = getUniversityGradingPolicy($connection, $student_id);

// Simple OCR helper for general documents (letter/certificate): extract text and average confidence
function ocr_extract_text_and_conf($filePath, $workDir = null) {
  $result = ['text' => '', 'confidence' => null, 'tsv_data' => [], 'tsv_file' => null];
  
  // Use the same directory as the file for TSV storage
  $workDir = $workDir ?: dirname($filePath);
  $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

  // 1) Try direct text for PDF
  if ($ext === 'pdf') {
    $cmd = "pdftotext " . escapeshellarg($filePath) . " - 2>nul"; // Windows-friendly stderr redirection
    $pdfText = @shell_exec($cmd);
    if (!empty($pdfText)) {
      $result['text'] = $pdfText;
    }
  }

  // 2) Use Tesseract to extract text if empty or not pdf
  if (empty(trim($result['text']))) {
    $outBase = rtrim($workDir, '/\\') . DIRECTORY_SEPARATOR . 'ocr_' . uniqid();
    $txtFile = $outBase . '.txt';
    $cmd = "tesseract " . escapeshellarg($filePath) . " " . escapeshellarg($outBase) . " --oem 1 --psm 6 -l eng 2>&1";
    $tessOut = @shell_exec($cmd);
    if (file_exists($txtFile)) {
      $result['text'] = @file_get_contents($txtFile) ?: '';
      @unlink($txtFile);
    }
  }

  // 3) Generate TSV data using stdout (no temp files created)
  $tsvFile = $filePath . '.tsv';
  
  // Run Tesseract to generate TSV output via stdout
  $cmd = "tesseract " . escapeshellarg($filePath) . " stdout -l eng --oem 1 --psm 6 tsv 2>&1";
  $tsvOutput = @shell_exec($cmd);
  
  // Save TSV directly to final location and parse (no intermediate files)
  if (!empty($tsvOutput)) {
    @file_put_contents($tsvFile, $tsvOutput);
    $result['tsv_file'] = $tsvFile;
    
    // Parse TSV data from stdout output
    $lines = preg_split('/\r?\n/', $tsvOutput);
    if (count($lines) > 1) {
      $header = array_shift($lines); // Remove header
      $sum = 0; $cnt = 0;
      $tsvData = [];
      
      foreach ($lines as $line) {
        if (!trim($line)) continue;
        $cols = explode("\t", $line);
        if (count($cols) >= 12) {
          $conf = is_numeric($cols[10] ?? null) ? (float)$cols[10] : null;
          $text = trim($cols[11] ?? '');
          
          // Store structured TSV data
          $tsvData[] = [
            'level' => (int)($cols[0] ?? 0),
            'page_num' => (int)($cols[1] ?? 0),
            'block_num' => (int)($cols[2] ?? 0),
            'par_num' => (int)($cols[3] ?? 0),
            'line_num' => (int)($cols[4] ?? 0),
            'word_num' => (int)($cols[5] ?? 0),
            'left' => (int)($cols[6] ?? 0),
            'top' => (int)($cols[7] ?? 0),
            'width' => (int)($cols[8] ?? 0),
            'height' => (int)($cols[9] ?? 0),
            'conf' => $conf,
            'text' => $text
          ];
          
          // Calculate average confidence
          if ($conf !== null && $conf >= 0 && $text !== '') { 
            $sum += $conf; 
            $cnt++; 
          }
        }
      }
      
      if ($cnt > 0) { 
        $result['confidence'] = round($sum / $cnt, 2); 
      }
      
      $result['tsv_data'] = $tsvData;
    }
  }

  return $result;
}

// Additional OCR helpers for ID verification
function ocr_tesseract_stdout($filePath, $args = '') {
  $cmd = "tesseract " . escapeshellarg($filePath) . " stdout " . $args . " 2>&1";
  return @shell_exec($cmd) ?: '';
}

function normalize_for_match($s) {
  $s = strtolower($s ?? '');
  $s = preg_replace('/[^a-z0-9\s]/', ' ', $s);
  return preg_replace('/\s+/', ' ', trim($s));
}

function tokenize($s) {
  $n = normalize_for_match($s);
  return $n === '' ? [] : preg_split('/\s+/', $n);
}

function acronym_from_name($s) {
  $tokens = preg_split('/\s+/', trim($s));
  $acr = '';
  foreach ($tokens as $t) {
    if ($t === '') continue;
    $c = strtoupper($t[0]);
    if (ctype_alpha($c)) $acr .= $c;
  }
  return $acr;
}

function fuzzy_contains_name($text, $first, $last, $middle = '') {
  $textN = normalize_for_match($text);
  $firstN = normalize_for_match($first);
  $lastN = normalize_for_match($last);
  $middleN = normalize_for_match($middle);

  $hasFirst = ($firstN !== '' && strpos($textN, $firstN) !== false);
  $hasLast  = ($lastN  !== '' && strpos($textN, $lastN)  !== false);

  if ($hasFirst && $hasLast) return true;

  // Fuzzy fallback: if any token is ~80% similar to first/last
  $tokens = tokenize($textN);
  $simThreshold = 80; // percent
  $bestFirst = 0; $bestLast = 0;
  foreach ($tokens as $tok) {
    if ($firstN) { similar_text($firstN, $tok, $p); if ($p > $bestFirst) $bestFirst = $p; }
    if ($lastN)  { similar_text($lastN,  $tok, $p); if ($p > $bestLast)  $bestLast  = $p; }
  }
  return ($bestFirst >= $simThreshold) && ($bestLast >= $simThreshold);
}

function school_match_tokens($text, $schoolName) {
  $textN = normalize_for_match($text);
  $schoolN = normalize_for_match($schoolName);
  if ($schoolN === '') return false;
  // Token strategy: if 2+ tokens from school or common words (university, campus, cavite) appear OR acronym appears
  $schoolTokens = array_values(array_filter(tokenize($schoolN)));
  $extra = ['university','campus','cavite'];
  $tokens = array_unique(array_merge($schoolTokens, $extra));
  $found = 0;
  foreach ($tokens as $t) {
    if ($t !== '' && strpos($textN, $t) !== false) $found++;
  }
  if ($found >= 2) return true;
  // Acronym check
  $acr = acronym_from_name($schoolName);
  if ($acr) {
    $acrLower = strtolower($acr);
    if (strpos($textN, $acrLower) !== false) return true;
  }
  return false;
}

// Handle the file uploads
// Handle document, grades, and EAF uploads in the same request
if ($_SERVER["REQUEST_METHOD"] === "POST" && (isset($_FILES['documents']) || isset($_FILES['grades_file']) || isset($_FILES['eaf_file']) || isset($_POST['confirm_uploads']))) {
    
    // CRITICAL DEBUG: Log immediately when POST received
    error_log("========================================");
    error_log("UPLOAD_DOCUMENT.PHP: POST REQUEST RECEIVED!");
    error_log("Timestamp: " . date('Y-m-d H:i:s'));
    error_log("documents isset: " . (isset($_FILES['documents']) ? 'YES' : 'NO'));
    error_log("grades_file isset: " . (isset($_FILES['grades_file']) ? 'YES' : 'NO'));
    error_log("eaf_file isset: " . (isset($_FILES['eaf_file']) ? 'YES' : 'NO'));
    error_log("confirm_uploads isset: " . (isset($_POST['confirm_uploads']) ? 'YES' : 'NO'));
    if (isset($_FILES['grades_file'])) {
        error_log("grades_file ERROR CODE: " . $_FILES['grades_file']['error']);
        error_log("grades_file NAME: " . $_FILES['grades_file']['name']);
        error_log("grades_file SIZE: " . $_FILES['grades_file']['size']);
    }
    error_log("========================================");
    
    // Handle upload confirmation (lock uploads)
    if (isset($_POST['confirm_uploads']) && $_POST['confirm_uploads'] === '1') {
        if ($uploads_locked) {
            $_SESSION['upload_fail'] = "Documents already confirmed. Contact admin if changes are needed.";
        } else {
            // Verify all required documents are uploaded before allowing confirmation
            if ($allDocumentsUploaded) {
                $confirm_query = "UPDATE students SET uploads_confirmed = TRUE, uploads_confirmed_at = NOW() WHERE student_id = $1";
                $confirm_result = pg_query_params($connection, $confirm_query, [$student_id]);
                
                if ($confirm_result) {
                    $_SESSION['upload_success'] = "Documents confirmed successfully! You can no longer modify uploads. Contact admin if changes are needed.";
                } else {
                    $_SESSION['upload_fail'] = "Failed to confirm uploads. Please try again.";
                }
            } else {
                $_SESSION['upload_fail'] = "Please upload all required documents before confirming.";
            }
        }
        header("Location: upload_document.php");
        exit;
    }
    
    // Block file uploads if already confirmed
    if ($uploads_locked) {
        $_SESSION['upload_fail'] = "Documents already confirmed. Contact admin to request changes.";
        header("Location: upload_document.php");
        exit;
    }
    
    // Debug: Log what was submitted
    log_debug("=== UPLOAD REQUEST RECEIVED ===");
    log_debug("FILES: " . print_r($_FILES, true));
    log_debug("POST: " . print_r($_POST, true));
    error_log("POST request received");
    error_log("FILES data: " . print_r($_FILES, true));
    error_log("POST data: " . print_r($_POST, true));
    
  $student_name = $_SESSION['student_username']; // Assuming student_username is stored in the session
  $student_id = $_SESSION['student_id']; // Assuming student_id is stored in the session
  // Create a filename-safe version of student_id (keep letters, numbers, dash, underscore)
  $student_id_safe = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$student_id);
  
  // Get student's first and last name for filename
  $first_name = $student_info['first_name'] ?? 'Student';
  $last_name = $student_info['last_name'] ?? 'Name';
  $first_name_safe = preg_replace('/[^A-Za-z]/', '', $first_name);
  $last_name_safe = preg_replace('/[^A-Za-z]/', '', $last_name);

    // Allowed file types and sizes
    $allowedTypes = [
        'image/jpeg', 'image/jpg', 'image/png', 'image/gif',
        'application/pdf', 
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/msword'
    ];
  $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'docx', 'doc'];
  $generalMaxFileSize = 5 * 1024 * 1024; // 5MB limit for standard documents
  $gradesMaxFileSize = 10 * 1024 * 1024; // 10MB limit to match registration flow
  $generalMaxFileSizeMb = intval($generalMaxFileSize / (1024 * 1024));
  $gradesMaxFileSizeMb = intval($gradesMaxFileSize / (1024 * 1024));
    
    // Use shared upload directories organized by document type (NOT per-student)
    $baseUploadDir = "../../assets/uploads/student/";
    
    // Document type to folder mapping
    $documentTypeToFolder = [
        'id_picture' => 'id_pictures',
        'letter_to_mayor' => 'letter_to_mayor',
        'certificate_of_indigency' => 'indigency',
        'eaf' => 'enrollment_forms',
        'academic_grades' => 'grades'
    ];
    
    // Document type to filename token mapping
    $documentTypeToToken = [
        'id_picture' => 'id',
        'letter_to_mayor' => 'lettertomayor',
        'certificate_of_indigency' => 'indigency',
        'eaf' => 'EAF',
        'academic_grades' => 'grades'
    ];
    
    // Ensure base directory exists
    if (!file_exists($baseUploadDir)) {
        mkdir($baseUploadDir, 0755, true);
    }

  $upload_success = false;
    $upload_errors = [];
    $uploaded_count = 0;
  $uploaded_documents_types = [];
    
  // Process regular documents if they exist
    if (isset($_FILES['documents']) && is_array($_FILES['documents']['name'])) {
        log_debug("Processing documents array with " . count($_FILES['documents']['name']) . " files");
        foreach ($_FILES['documents']['name'] as $index => $fileName) {
        // Skip empty file entries
        if (empty($fileName)) {
            log_debug("Skipping empty document at index " . $index);
            continue;
        }
        
        $fileTmpName = $_FILES['documents']['tmp_name'][$index];
        $fileSize = $_FILES['documents']['size'][$index];
        $fileType = $_POST['document_type'][$index];
        
        log_debug("Processing document: $fileName, Size: $fileSize, Type: $fileType");

    // Validate document type
    if (!in_array($fileType, ['id_picture','letter_to_mayor','certificate_of_indigency'])) {
            $upload_errors[] = "Invalid document type: " . htmlspecialchars($fileType);
            continue;
        }
        
        // Validate file size
    if ($fileSize > $generalMaxFileSize) {
      $upload_errors[] = "File too large: " . htmlspecialchars($fileName) . " (max {$generalMaxFileSizeMb}MB)";
            continue;
        }
        
        // Validate file extension  
        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if (!in_array($fileExt, $allowedExtensions)) {
            $upload_errors[] = "Invalid file extension: " . htmlspecialchars($fileName);
            continue;
        }

        // Move the uploaded file to the student’s folder
        // Generate secure standardized filename: {student_id}_{document}_{timestamp}.{ext}
        $timestamp = date('Y-m-d_H-i-s');
        // Get directory for this document type
        $folderName = $documentTypeToFolder[$fileType] ?? 'other';
        $uploadDir = $baseUploadDir . $folderName . "/";
        
        // Ensure directory exists
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        // Generate standardized filename: {STUDENT_ID}_{LastName}_{FirstName}_{doctype}.{ext}
        $docToken = $documentTypeToToken[$fileType] ?? preg_replace('/[^a-zA-Z0-9_-]/', '_', $fileType);
        $secureFileName = $student_id_safe . "_" . $last_name_safe . "_" . $first_name_safe . "_" . $docToken . "." . $fileExt;
        $finalPath = $uploadDir . $secureFileName;
        
        // Delete old file if it exists (before confirmation, students can reupload)
        if (file_exists($finalPath)) {
            unlink($finalPath);
            // Also delete OCR text file if it exists
            if (file_exists($finalPath . '.ocr.txt')) {
                unlink($finalPath . '.ocr.txt');
            }
        }
    if (move_uploaded_file($fileTmpName, $finalPath)) {
            // Normalize path to use forward slashes for database consistency
            $normalizedPath = str_replace('\\', '/', $finalPath);
            
            // Insert into database using prepared statements
            try {
        $stmt = pg_prepare($connection, "insert_document_" . $index, 
          "INSERT INTO documents (student_id, type, file_path, upload_date) 
           VALUES ($1, $2, $3, NOW())");
                
                if ($stmt) {
                    $result = pg_execute($connection, "insert_document_" . $index, [
                        $student_id, 
                        $fileType, 
                        $normalizedPath
                    ]);
                    
                    if ($result) {
                        $upload_success = true;
                        $uploaded_count++;
                        $uploaded_documents_types[] = $fileType;

            // If the document is a letter, certificate, or ID picture, perform OCR and store confidence
            if (in_array($fileType, ['letter_to_mayor','certificate_of_indigency','id_picture'])) {
              try {
                // Base OCR (generic)
                $ocr = ocr_extract_text_and_conf($finalPath, dirname($finalPath));
                $avgConf = $ocr['confidence'];
                // Save extracted text next to file
                $combinedText = $ocr['text'] ?? '';
                                
                // If ID, run dual-pass OCR tuned for name area
                if ($fileType === 'id_picture') {
                  // Pass A: sparse text (psm 11)
                  $passA = ocr_tesseract_stdout($finalPath, "-l eng --oem 1 --psm 11");
                  // Pass B: single text line/region with whitelist for names
                  $passB = ocr_tesseract_stdout($finalPath, "-l eng --oem 1 --psm 7 -c tessedit_char_whitelist=ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz,.- ");
                  // Combine
                  $extra = trim($passA . "\n" . $passB);
                  if ($extra !== '') {
                    $combinedText = trim($combinedText . "\n" . $extra);
                  }
                  // Lightly boost confidence if extra text found
                  if ($avgConf !== null && $extra !== '') {
                    $avgConf = min(100, round($avgConf * 0.9 + 10, 2));
                  }
                }
                if (!empty($combinedText)) {
                  @file_put_contents($finalPath . '.ocr.txt', $combinedText);
                }
                if ($avgConf !== null) {
                  // Update documents row confidence using unique path (use normalized path)
                  @pg_query_params($connection, "UPDATE documents SET ocr_confidence = $1 WHERE student_id = $2 AND type = $3 AND file_path = $4", [
                    $avgConf,
                    $student_id,
                    $fileType,
                    $normalizedPath
                  ]);
                }

                // For ID picture, run detailed identity verification matching student_register.php structure
                if ($fileType === 'id_picture') {
                  $ocrTextLower = strtolower($combinedText);

                  // Fetch complete student profile
                  $first = '';
                  $middle = '';
                  $last = '';
                  $universityName = '';
                  
                  if (!empty($student_info)) {
                    $first = $student_info['first_name'] ?? '';
                    $middle = $student_info['middle_name'] ?? '';
                    $last = $student_info['last_name'] ?? '';
                  } else {
                    $si_res = @pg_query_params($connection, "SELECT first_name, middle_name, last_name FROM students WHERE student_id = $1", [$student_id]);
                    if ($si_res) {
                      $si = pg_fetch_assoc($si_res);
                      $first = $si['first_name'] ?? '';
                      $middle = $si['middle_name'] ?? '';
                      $last = $si['last_name'] ?? '';
                    }
                  }

                  $uni_res = @pg_query_params($connection, "SELECT u.name FROM students s JOIN universities u ON s.university_id = u.university_id WHERE s.student_id = $1", [$student_id]);
                  if ($uni_res) {
                    $uni = pg_fetch_assoc($uni_res);
                    $universityName = $uni['name'] ?? '';
                  }

                  // Enhanced verification with 5 checks (removed year_level)
                  $verification = [
                    'first_name_match' => false,
                    'middle_name_match' => false,
                    'last_name_match' => false,
                    'university_match' => false,
                    'document_keywords_found' => false,
                    'confidence_scores' => [],
                    'found_text_snippets' => []
                  ];
                  
                  // Helper function for similarity
                  function calculateIDSimilarity($needle, $haystack) {
                    $needle = strtolower(trim($needle));
                    $haystack = strtolower(trim($haystack));
                    if (stripos($haystack, $needle) !== false) return 100;
                    $words = explode(' ', $haystack);
                    $maxSimilarity = 0;
                    foreach ($words as $word) {
                      if (strlen($word) >= 3 && strlen($needle) >= 3) {
                        $similarity = 0;
                        similar_text($needle, $word, $similarity);
                        $maxSimilarity = max($maxSimilarity, $similarity);
                      }
                    }
                    return $maxSimilarity;
                  }

                  // Check first name
                  if (!empty($first)) {
                    $similarity = calculateIDSimilarity($first, $ocrTextLower);
                    $verification['confidence_scores']['first_name'] = $similarity;
                    if ($similarity >= 80) {
                      $verification['first_name_match'] = true;
                    }
                  }

                  // Check middle name
                  if (empty($middle)) {
                    $verification['middle_name_match'] = true;
                    $verification['confidence_scores']['middle_name'] = 100;
                  } else {
                    $similarity = calculateIDSimilarity($middle, $ocrTextLower);
                    $verification['confidence_scores']['middle_name'] = $similarity;
                    if ($similarity >= 70) {
                      $verification['middle_name_match'] = true;
                    }
                  }

                  // Check last name
                  if (!empty($last)) {
                    $similarity = calculateIDSimilarity($last, $ocrTextLower);
                    $verification['confidence_scores']['last_name'] = $similarity;
                    if ($similarity >= 80) {
                      $verification['last_name_match'] = true;
                    }
                  }

                  // Check university name
                  if (!empty($universityName)) {
                    $universityWords = array_filter(explode(' ', strtolower($universityName)));
                    $foundWords = 0;
                    $totalWords = count($universityWords);
                    foreach ($universityWords as $word) {
                      if (strlen($word) > 2) {
                        $similarity = calculateIDSimilarity($word, $ocrTextLower);
                        if ($similarity >= 70) $foundWords++;
                      }
                    }
                    $universityScore = ($foundWords / max($totalWords, 1)) * 100;
                    $verification['confidence_scores']['university'] = round($universityScore, 1);
                    if ($universityScore >= 60 || ($totalWords <= 2 && $foundWords >= 1)) {
                      $verification['university_match'] = true;
                    }
                  }

                  // Check document keywords (ID-specific)
                  $documentKeywords = [
                    'student', 'id', 'identification', 'university', 'college', 'school',
                    'name', 'number', 'valid', 'card', 'holder', 'expires'
                  ];
                  $keywordMatches = 0;
                  $keywordScore = 0;
                  foreach ($documentKeywords as $keyword) {
                    $similarity = calculateIDSimilarity($keyword, $ocrTextLower);
                    if ($similarity >= 80) {
                      $keywordMatches++;
                      $keywordScore += $similarity;
                    }
                  }
                  $averageKeywordScore = $keywordMatches > 0 ? ($keywordScore / $keywordMatches) : 0;
                  $verification['confidence_scores']['document_keywords'] = round($averageKeywordScore, 1);
                  if ($keywordMatches >= 2) {
                    $verification['document_keywords_found'] = true;
                  }

                  // Calculate overall success
                  $requiredChecks = ['first_name_match', 'middle_name_match', 'last_name_match', 'university_match', 'document_keywords_found'];
                  $passedChecks = 0;
                  foreach ($requiredChecks as $check) {
                    if ($verification[$check]) $passedChecks++;
                  }
                  
                  $totalConfidence = 0;
                  $confidenceCount = 0;
                  foreach ($verification['confidence_scores'] as $score) {
                    $totalConfidence += $score;
                    $confidenceCount++;
                  }
                  $averageConfidence = $confidenceCount > 0 ? ($totalConfidence / $confidenceCount) : 0;
                  
                  $verification['overall_success'] = ($passedChecks >= 3) || ($passedChecks >= 2 && $averageConfidence >= 80);
                  $verification['summary'] = [
                    'passed_checks' => $passedChecks,
                    'total_checks' => 5,
                    'average_confidence' => round($averageConfidence, 1),
                    'recommendation' => $verification['overall_success'] ? 
                      'Document validation successful' : 
                      'Please ensure the ID clearly shows your name and university'
                  ];

                  @file_put_contents($finalPath . '.verify.json', safe_json_encode($verification));
                }
                
                // For Letter to Mayor - match student_register.php 4-check structure
                elseif ($fileType === 'letter_to_mayor') {
                  $ocrTextLower = strtolower($combinedText);
                  $ocrTextNormalized = strtolower(preg_replace('/[^\w\s]/', ' ', $combinedText));
                  
                  // Fetch student profile
                  $first = '';
                  $last = '';
                  $barangayName = '';
                  
                  if (!empty($student_info)) {
                    $first = $student_info['first_name'] ?? '';
                    $last = $student_info['last_name'] ?? '';
                  } else {
                    $si_res = @pg_query_params($connection, "SELECT first_name, last_name, barangay_id FROM students WHERE student_id = $1", [$student_id]);
                    if ($si_res) {
                      $si = pg_fetch_assoc($si_res);
                      $first = $si['first_name'] ?? '';
                      $last = $si['last_name'] ?? '';
                      
                      // Get barangay name
                      if (!empty($si['barangay_id'])) {
                        $brgy_res = @pg_query_params($connection, "SELECT name FROM barangays WHERE barangay_id = $1", [$si['barangay_id']]);
                        if ($brgy_res) {
                          $brgy = pg_fetch_assoc($brgy_res);
                          $barangayName = $brgy['name'] ?? '';
                        }
                      }
                    }
                  }
                  
                  // Helper function for similarity matching student_register.php
                  function calculateLetterSimilarity($needle, $haystack) {
                    $needle = strtolower(trim($needle));
                    $haystack = strtolower(trim($haystack));
                    if (stripos($haystack, $needle) !== false) return 100;
                    $words = explode(' ', $haystack);
                    $maxSimilarity = 0;
                    foreach ($words as $word) {
                      if (strlen($word) >= 3 && strlen($needle) >= 3) {
                        $similarity = 0;
                        similar_text($needle, $word, $similarity);
                        $maxSimilarity = max($maxSimilarity, $similarity);
                      }
                    }
                    return $maxSimilarity;
                  }
                  
                  // 4-check verification structure matching student_register.php
                  $verification = [
                    'first_name' => false,
                    'last_name' => false,
                    'barangay' => false,
                    'mayor_header' => false,
                    'confidence_scores' => [],
                    'found_text_snippets' => []
                  ];
                  
                  // Check first name
                  if (!empty($first)) {
                    $similarity = calculateLetterSimilarity($first, $ocrTextNormalized);
                    $verification['confidence_scores']['first_name'] = $similarity;
                    if ($similarity >= 80) {
                      $verification['first_name'] = true;
                      $pattern = '/\b\w*' . preg_quote(substr($first, 0, 3), '/') . '\w*\b/i';
                      if (preg_match($pattern, $combinedText, $matches)) {
                        $verification['found_text_snippets']['first_name'] = $matches[0];
                      }
                    }
                  }
                  
                  // Check last name
                  if (!empty($last)) {
                    $similarity = calculateLetterSimilarity($last, $ocrTextNormalized);
                    $verification['confidence_scores']['last_name'] = $similarity;
                    if ($similarity >= 80) {
                      $verification['last_name'] = true;
                      $pattern = '/\b\w*' . preg_quote(substr($last, 0, 3), '/') . '\w*\b/i';
                      if (preg_match($pattern, $combinedText, $matches)) {
                        $verification['found_text_snippets']['last_name'] = $matches[0];
                      }
                    }
                  }
                  
                  // Check barangay
                  if (!empty($barangayName)) {
                    $similarity = calculateLetterSimilarity($barangayName, $ocrTextNormalized);
                    $verification['confidence_scores']['barangay'] = $similarity;
                    if ($similarity >= 70) {
                      $verification['barangay'] = true;
                      $pattern = '/\b\w*' . preg_quote(substr($barangayName, 0, 4), '/') . '\w*\b/i';
                      if (preg_match($pattern, $combinedText, $matches)) {
                        $verification['found_text_snippets']['barangay'] = $matches[0];
                      }
                    }
                  }
                  
                  // Check for mayor header
                  $mayorHeaders = [
                    'office of the mayor', 'mayor\'s office', 'office mayor',
                    'municipal mayor', 'city mayor', 'mayor office',
                    'office of mayor', 'municipal government', 'city government',
                    'local government unit', 'lgu'
                  ];
                  
                  $mayorHeaderFound = false;
                  $mayorConfidence = 0;
                  $foundMayorText = '';
                  
                  foreach ($mayorHeaders as $header) {
                    $similarity = calculateLetterSimilarity($header, $ocrTextNormalized);
                    if ($similarity > $mayorConfidence) {
                      $mayorConfidence = $similarity;
                    }
                    if ($similarity >= 70) {
                      $mayorHeaderFound = true;
                      $pattern = '/[^\n]*' . preg_quote(explode(' ', $header)[0], '/') . '[^\n]*/i';
                      if (preg_match($pattern, $combinedText, $matches)) {
                        $foundMayorText = trim($matches[0]);
                      }
                      break;
                    }
                  }
                  
                  $verification['mayor_header'] = $mayorHeaderFound;
                  $verification['confidence_scores']['mayor_header'] = $mayorConfidence;
                  if (!empty($foundMayorText)) {
                    $verification['found_text_snippets']['mayor_header'] = $foundMayorText;
                  }
                  
                  // Calculate overall success (4 checks)
                  $requiredLetterChecks = ['first_name', 'last_name', 'barangay', 'mayor_header'];
                  $passedLetterChecks = 0;
                  $totalConfidence = 0;
                  
                  foreach ($requiredLetterChecks as $check) {
                    if ($verification[$check]) {
                      $passedLetterChecks++;
                    }
                    $totalConfidence += isset($verification['confidence_scores'][$check]) ? 
                      $verification['confidence_scores'][$check] : 0;
                  }
                  
                  $averageConfidence = $totalConfidence / 4;
                  
                  $verification['overall_success'] = ($passedLetterChecks >= 3) || 
                    ($passedLetterChecks >= 2 && $averageConfidence >= 75);
                  
                  $verification['summary'] = [
                    'passed_checks' => $passedLetterChecks,
                    'total_checks' => 4,
                    'average_confidence' => round($averageConfidence, 1),
                    'recommendation' => $verification['overall_success'] ? 
                      'Document validation successful' : 
                      'Please ensure the document contains your name, barangay, and mayor office header clearly'
                  ];
                  
                  @file_put_contents($finalPath . '.verify.json', safe_json_encode($verification));
                }
                
                // For Certificate of Indigency - match student_register.php 5-check structure
                elseif ($fileType === 'certificate_of_indigency') {
                  $ocrTextLower = strtolower($combinedText);
                  $ocrTextNormalized = strtolower(preg_replace('/[^\w\s]/', ' ', $combinedText));
                  
                  // Fetch student profile
                  $first = '';
                  $last = '';
                  $barangayName = '';
                  
                  if (!empty($student_info)) {
                    $first = $student_info['first_name'] ?? '';
                    $last = $student_info['last_name'] ?? '';
                  } else {
                    $si_res = @pg_query_params($connection, "SELECT first_name, last_name, barangay_id FROM students WHERE student_id = $1", [$student_id]);
                    if ($si_res) {
                      $si = pg_fetch_assoc($si_res);
                      $first = $si['first_name'] ?? '';
                      $last = $si['last_name'] ?? '';
                      
                      // Get barangay name
                      if (!empty($si['barangay_id'])) {
                        $brgy_res = @pg_query_params($connection, "SELECT name FROM barangays WHERE barangay_id = $1", [$si['barangay_id']]);
                        if ($brgy_res) {
                          $brgy = pg_fetch_assoc($brgy_res);
                          $barangayName = $brgy['name'] ?? '';
                        }
                      }
                    }
                  }
                  
                  // Helper function for similarity matching student_register.php
                  function calculateCertSimilarity($needle, $haystack) {
                    $needle = strtolower(trim($needle));
                    $haystack = strtolower(trim($haystack));
                    if (stripos($haystack, $needle) !== false) return 100;
                    $words = explode(' ', $haystack);
                    $maxSimilarity = 0;
                    foreach ($words as $word) {
                      if (strlen($word) >= 3 && strlen($needle) >= 3) {
                        $similarity = 0;
                        similar_text($needle, $word, $similarity);
                        $maxSimilarity = max($maxSimilarity, $similarity);
                      }
                    }
                    return $maxSimilarity;
                  }
                  
                  // 5-check verification structure matching student_register.php
                  $verification = [
                    'certificate_title' => false,
                    'first_name' => false,
                    'last_name' => false,
                    'barangay' => false,
                    'general_trias' => false,
                    'confidence_scores' => [],
                    'found_text_snippets' => []
                  ];
                  
                  // Check for certificate title
                  $certificateTitles = [
                    'certificate of indigency', 'indigency certificate',
                    'certificate indigency', 'katunayan ng kahirapan',
                    'indigent certificate', 'poverty certificate'
                  ];
                  
                  $titleFound = false;
                  $titleConfidence = 0;
                  $foundTitleText = '';
                  
                  foreach ($certificateTitles as $title) {
                    $similarity = calculateCertSimilarity($title, $ocrTextNormalized);
                    if ($similarity > $titleConfidence) {
                      $titleConfidence = $similarity;
                    }
                    if ($similarity >= 70) {
                      $titleFound = true;
                      $pattern = '/[^\n]*' . preg_quote(explode(' ', $title)[0], '/') . '[^\n]*/i';
                      if (preg_match($pattern, $combinedText, $matches)) {
                        $foundTitleText = trim($matches[0]);
                      }
                      break;
                    }
                  }
                  
                  $verification['certificate_title'] = $titleFound;
                  $verification['confidence_scores']['certificate_title'] = $titleConfidence;
                  if (!empty($foundTitleText)) {
                    $verification['found_text_snippets']['certificate_title'] = $foundTitleText;
                  }
                  
                  // Check first name
                  if (!empty($first)) {
                    $similarity = calculateCertSimilarity($first, $ocrTextNormalized);
                    $verification['confidence_scores']['first_name'] = $similarity;
                    if ($similarity >= 80) {
                      $verification['first_name'] = true;
                      $pattern = '/\b\w*' . preg_quote(substr($first, 0, 3), '/') . '\w*\b/i';
                      if (preg_match($pattern, $combinedText, $matches)) {
                        $verification['found_text_snippets']['first_name'] = $matches[0];
                      }
                    }
                  }
                  
                  // Check last name
                  if (!empty($last)) {
                    $similarity = calculateCertSimilarity($last, $ocrTextNormalized);
                    $verification['confidence_scores']['last_name'] = $similarity;
                    if ($similarity >= 80) {
                      $verification['last_name'] = true;
                      $pattern = '/\b\w*' . preg_quote(substr($last, 0, 3), '/') . '\w*\b/i';
                      if (preg_match($pattern, $combinedText, $matches)) {
                        $verification['found_text_snippets']['last_name'] = $matches[0];
                      }
                    }
                  }
                  
                  // Check barangay
                  if (!empty($barangayName)) {
                    $similarity = calculateCertSimilarity($barangayName, $ocrTextNormalized);
                    $verification['confidence_scores']['barangay'] = $similarity;
                    if ($similarity >= 70) {
                      $verification['barangay'] = true;
                      $pattern = '/\b\w*' . preg_quote(substr($barangayName, 0, 4), '/') . '\w*\b/i';
                      if (preg_match($pattern, $combinedText, $matches)) {
                        $verification['found_text_snippets']['barangay'] = $matches[0];
                      }
                    }
                  }
                  
                  // Check for General Trias
                  $generalTriasVariations = [
                    'general trias', 'gen trias', 'general trias city',
                    'municipality of general trias', 'city of general trias'
                  ];
                  
                  $generalTriasFound = false;
                  $generalTriasConfidence = 0;
                  $foundGeneralTriasText = '';
                  
                  foreach ($generalTriasVariations as $variation) {
                    $similarity = calculateCertSimilarity($variation, $ocrTextNormalized);
                    if ($similarity > $generalTriasConfidence) {
                      $generalTriasConfidence = $similarity;
                    }
                    if ($similarity >= 70) {
                      $generalTriasFound = true;
                      $pattern = '/[^\n]*' . preg_quote(explode(' ', $variation)[0], '/') . '[^\n]*/i';
                      if (preg_match($pattern, $combinedText, $matches)) {
                        $foundGeneralTriasText = trim($matches[0]);
                      }
                      break;
                    }
                  }
                  
                  $verification['general_trias'] = $generalTriasFound;
                  $verification['confidence_scores']['general_trias'] = $generalTriasConfidence;
                  if (!empty($foundGeneralTriasText)) {
                    $verification['found_text_snippets']['general_trias'] = $foundGeneralTriasText;
                  }
                  
                  // Calculate overall success (5 checks)
                  $requiredCertificateChecks = ['certificate_title', 'first_name', 'last_name', 'barangay', 'general_trias'];
                  $passedCertificateChecks = 0;
                  $totalConfidence = 0;
                  
                  foreach ($requiredCertificateChecks as $check) {
                    if ($verification[$check]) {
                      $passedCertificateChecks++;
                    }
                    $totalConfidence += isset($verification['confidence_scores'][$check]) ? 
                      $verification['confidence_scores'][$check] : 0;
                  }
                  
                  $averageConfidence = $totalConfidence / 5;
                  
                  $verification['overall_success'] = ($passedCertificateChecks >= 4) || 
                    ($passedCertificateChecks >= 3 && $averageConfidence >= 75);
                  
                  $verification['summary'] = [
                    'passed_checks' => $passedCertificateChecks,
                    'total_checks' => 5,
                    'average_confidence' => round($averageConfidence, 1),
                    'recommendation' => $verification['overall_success'] ? 
                      'Certificate validation successful' : 
                      'Please ensure the certificate contains your name, barangay, "Certificate of Indigency" title, and "General Trias" clearly'
                  ];
                  
                  @file_put_contents($finalPath . '.verify.json', safe_json_encode($verification));
                }
              } catch (Throwable $t) {
                error_log('OCR for ' . $fileType . ' failed: ' . $t->getMessage());
              }
            }
                    } else {
                        $upload_errors[] = "Database error for: " . htmlspecialchars($fileName);
                        // Clean up file if database insert failed
                        if (file_exists($finalPath)) {
                            unlink($finalPath);
                        }
                    }
                } else {
                    $upload_errors[] = "Database preparation error for: " . htmlspecialchars($fileName);
                }
            } catch (Exception $e) {
                $upload_errors[] = "Database error: " . $e->getMessage();
                if (file_exists($finalPath)) {
                    unlink($finalPath);
                }
            }
        } else {
            $upload_errors[] = "Failed to move file: " . htmlspecialchars($fileName);
        }
        }
    }

    // Process grades file if present
    $grades_upload_success = false;
  if (isset($_FILES['grades_file'])) {
        log_debug("Grades file found in FILES array, error code: " . $_FILES['grades_file']['error']);
        error_log("Grades file found in \$_FILES, error code: " . $_FILES['grades_file']['error']);
        
  if ($_FILES['grades_file']['error'] === UPLOAD_ERR_OK) {
            log_debug("Processing grades file upload - no upload errors");
            error_log("=== GRADES UPLOAD DEBUG START ===");
            error_log("Processing grades file upload");
            $gradesFileName = $_FILES['grades_file']['name'];
            $gradesFileTmpName = $_FILES['grades_file']['tmp_name'];
            $gradesFileSize = $_FILES['grades_file']['size'];
            error_log("Student ID: " . $student_id);
            error_log("Grades file: " . $gradesFileName . ", Size: " . $gradesFileSize);
            error_log("Max allowed size: " . $gradesMaxFileSize . " bytes (" . $gradesMaxFileSizeMb . "MB)");
            
            // Validate grades file
            if ($gradesFileSize <= $gradesMaxFileSize) {
                $gradesFileExt = strtolower(pathinfo($gradesFileName, PATHINFO_EXTENSION));
                if (in_array($gradesFileExt, ['jpg', 'jpeg', 'png', 'gif', 'pdf'])) {
                    // Get directory for grades
                    $gradesFolder = $documentTypeToFolder['academic_grades'] ?? 'grades';
                    $gradesUploadDir = $baseUploadDir . $gradesFolder . "/";
                    error_log("Grades folder name: " . $gradesFolder);
                    error_log("Grades upload directory: " . $gradesUploadDir);
                    error_log("Directory exists: " . (file_exists($gradesUploadDir) ? 'YES' : 'NO'));
                    
                    // Ensure directory exists
                    if (!file_exists($gradesUploadDir)) {
                        $created = mkdir($gradesUploadDir, 0755, true);
                        error_log("Created grades directory: " . ($created ? 'SUCCESS' : 'FAILED'));
                    } else {
                        error_log("Directory already exists, checking if writable...");
                        error_log("Directory is writable: " . (is_writable($gradesUploadDir) ? 'YES' : 'NO'));
                    }
                    
                    // Generate standardized filename: {STUDENT_ID}_{LastName}_{FirstName}_grades.{ext}
                    $gradesToken = $documentTypeToToken['academic_grades'] ?? 'grades';
                    $secureGradesFileName = $student_id_safe . "_" . $last_name_safe . "_" . $first_name_safe . "_" . $gradesToken . "." . $gradesFileExt;
                    $gradesFinalPath = $gradesUploadDir . $secureGradesFileName;
                    error_log("Final grades file path: " . $gradesFinalPath);
                    
                    // Delete old file if it exists
                    if (file_exists($gradesFinalPath)) {
                        error_log("Old grades file exists, deleting...");
                        unlink($gradesFinalPath);
                        if (file_exists($gradesFinalPath . '.ocr.txt')) {
                            unlink($gradesFinalPath . '.ocr.txt');
                        }
                    }
                    
                    error_log("Attempting to move uploaded file from temp to final location...");
          if (move_uploaded_file($gradesFileTmpName, $gradesFinalPath)) {
                        error_log("SUCCESS: File moved successfully to: " . $gradesFinalPath);
                        // Delete old grades records for this student before inserting new one
                        try {
                            $delete_old_grades = pg_query_params($connection,
                                "DELETE FROM documents WHERE student_id = $1 AND type = 'academic_grades'",
                                [$student_id]
                            );
                            if ($delete_old_grades) {
                                error_log("Deleted old grades records for student: " . $student_id);
                            }
                        } catch (Exception $e) {
                            error_log("Error deleting old grades: " . $e->getMessage());
                        }
                        
                        // Insert new grades record into database
                        try {
                            // Set default confidence score
                            $gradesConfidence = 75.0;
                            
                            // Normalize path to use forward slashes for database consistency
                            $normalizedGradesPath = str_replace('\\', '/', $gradesFinalPath);
                            
                            // Insert into documents table (direct insert without prepare/execute)
                            $grades_result = pg_query_params($connection,
                                "INSERT INTO documents (student_id, type, file_path, upload_date, is_valid, ocr_confidence) 
                                 VALUES ($1, $2, $3, NOW(), $4, $5)",
                                [
                                    $student_id, 
                                    'academic_grades', 
                                    $normalizedGradesPath,
                                    'false', // Will be validated later
                                    $gradesConfidence
                                ]
                            );
                            
                            if ($grades_result) {
                                error_log("Grades uploaded successfully for student: " . $student_id);
                                $grades_upload_success = true;
                                $uploaded_count++; // Count grades in total uploads

                  // Immediately process OCR and validation for the uploaded grades
                  try {
                    require_once __DIR__ . '/../../bootstrap_services.php';

                    // Prepare OCR service
                    $ocrProcessor = new OCRProcessingService([
                      'tesseract_path' => 'tesseract',
                      'temp_dir' => realpath(__DIR__ . '/../../temp_debug') ?: sys_get_temp_dir(),
                      'max_file_size' => 10 * 1024 * 1024,
                    ]);

                    // Ensure temp dir exists
                    $tmpDir = __DIR__ . '/../../temp_debug';
                    if (!is_dir($tmpDir)) { @mkdir($tmpDir, 0755, true); }

                    $ocrResult = $ocrProcessor->processGradeDocument($gradesFinalPath);

                    $subjects = $ocrResult['success'] ? ($ocrResult['subjects'] ?? []) : [];

                    // Compute average confidence
                    $avgConfidence = 0;
                    if (!empty($subjects)) {
                      $sum = 0; $cnt = 0;
                      foreach ($subjects as $s) { $sum += floatval($s['confidence'] ?? 0); $cnt++; }
                      $avgConfidence = $cnt > 0 ? round($sum / $cnt, 2) : 0;
                    }

                    // Fetch university key for the student
                    $uni_code = null;
                    $uni_res = pg_query_params($connection, "SELECT u.code FROM students s JOIN universities u ON s.university_id = u.university_id WHERE s.student_id = $1", [$student_id]);
                    if ($uni_res && ($uni_row = pg_fetch_assoc($uni_res))) { $uni_code = $uni_row['code']; }

                    // Build PDO connection for GradeValidationService
                    $dbHost = getenv('DB_HOST') ?: 'localhost';
                    $dbName = getenv('DB_NAME') ?: 'educaid';
                    $dbUser = getenv('DB_USER') ?: 'postgres';
                    $dbPass = getenv('DB_PASSWORD') ?: '';
                    $dbPort = getenv('DB_PORT') ?: '5432';
                    $pdoConnection = null;
                    try {
                      $pdoConnection = new PDO("pgsql:host=$dbHost;port=$dbPort;dbname=$dbName", $dbUser, $dbPass);
                      $pdoConnection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    } catch (Exception $e) {
                      error_log('PDO connection failed for grade validation: ' . $e->getMessage());
                    }

                    $validationStatus = 'pending';
                    $eligible = false;
                    $failedSubjects = [];

                    if ($pdoConnection && $uni_code && !empty($subjects)) {
                      $gradeValidator = new GradeValidationService($pdoConnection);
                      $validation = $gradeValidator->validateApplicant($uni_code, $subjects);
                      $eligible = $validation['eligible'] ?? false;
                      $failedSubjects = $validation['failedSubjects'] ?? [];
                      $validationStatus = $eligible ? 'passed' : (!empty($failedSubjects) ? 'failed' : 'manual_review');
                    } else {
                      // If we couldn't validate (no subjects or no PDO), mark as manual review
                      $validationStatus = empty($subjects) ? 'manual_review' : 'pending';
                    }

                    // Persist to grade_uploads
                    $extractedText = '';
                    if (!empty($subjects)) {
                      $lines = [];
                      foreach ($subjects as $subj) {
                        $lines[] = ($subj['name'] ?? 'Subject') . ': ' . ($subj['rawGrade'] ?? '');
                      }
                      $extractedText = implode("\n", $lines);
                    } else if (!$ocrResult['success']) {
                      $extractedText = 'OCR failed: ' . ($ocrResult['error'] ?? 'Unknown error');
                    }

                    $ins_sql = "INSERT INTO grade_uploads (file_path, file_type, upload_date, ocr_processed, ocr_confidence, extracted_text, validation_status, student_id) 
                          VALUES ($1, $2, NOW(), $3, $4, $5, $6, $7) RETURNING upload_id";
                    $ins_params = [
                      $normalizedGradesPath,
                      $gradesFileExt,
                      $ocrResult['success'],
                      !empty($subjects) ? $avgConfidence : null,
                      $extractedText,
                      $validationStatus,
                      strval($student_id)
                    ];
                    $ins_res = pg_query_params($connection, $ins_sql, $ins_params);
                    $upload_id = null;
                    if ($ins_res) {
                      $ret = pg_fetch_assoc($ins_res);
                      if ($ret && isset($ret['upload_id'])) { $upload_id = intval($ret['upload_id']); }
                    }

                    // Persist per-subject extracted grades
                    if ($upload_id && !empty($subjects)) {
                      foreach ($subjects as $subj) {
                        $subj_name = $subj['name'] ?? '';
                        $raw_grade = $subj['rawGrade'] ?? '';
                        $conf_val = floatval($subj['confidence'] ?? 0);

                        // Determine passing per subject if possible
                        $is_passing = null;
                        if ($pdoConnection && $uni_code && !empty($raw_grade)) {
                          try {
                            $gradeValidator = $gradeValidator ?? new GradeValidationService($pdoConnection);
                            $is_passing = $gradeValidator->isSubjectPassing($uni_code, $raw_grade) ? 't' : 'f';
                          } catch (Exception $e) {
                            error_log('Per-subject validation error: ' . $e->getMessage());
                            $is_passing = null;
                          }
                        }

                        $ins_grade_sql = "INSERT INTO extracted_grades (upload_id, subject_name, grade_value, extraction_confidence, is_passing) 
                                  VALUES ($1, $2, $3, $4, $5)";
                        $ins_grade_params = [
                          $upload_id,
                          $subj_name,
                          $raw_grade,
                          $conf_val,
                          $is_passing
                        ];
                        pg_query_params($connection, $ins_grade_sql, $ins_grade_params);
                      }
                    }

                    // Optionally update documents row confidence for quick display (use normalized path)
                    if (!empty($subjects)) {
                      @pg_query_params($connection, "UPDATE documents SET ocr_confidence = $1 WHERE student_id = $2 AND type = 'academic_grades' AND file_path = $3", [
                        $avgConfidence,
                        $student_id,
                        $normalizedGradesPath
                      ]);
                    }
                  } catch (Throwable $ocr_ex) {
                    error_log('Auto OCR processing failed: ' . $ocr_ex->getMessage());
                    // If OCR fails, still record in grade_uploads for manual review
                    $fallback_sql = "INSERT INTO grade_uploads (file_path, file_type, upload_date, ocr_processed, validation_status, student_id) 
                             VALUES ($1, $2, NOW(), $3, $4, $5)";
                    @pg_query_params($connection, $fallback_sql, [
                      $normalizedGradesPath,
                      $gradesFileExt,
                      false,
                      'manual_review',
                      strval($student_id)
                    ]);
                  }
                            } else {
                                error_log("Database insert failed for grades: " . pg_last_error($connection));
                                $upload_errors[] = "Database error for grades file";
                                if (file_exists($gradesFinalPath)) {
                                    unlink($gradesFinalPath);
                                }
                            }
                        } catch (Exception $e) {
                            error_log("Exception during grades upload: " . $e->getMessage());
                            $upload_errors[] = "Database error for grades: " . $e->getMessage();
                            if (file_exists($gradesFinalPath)) {
                                unlink($gradesFinalPath);
                            }
                        }
                    } else {
                        $upload_errors[] = "Failed to upload grades file: " . htmlspecialchars($gradesFileName);
                    }
                } else {
                    $upload_errors[] = "Invalid grades file type: " . htmlspecialchars($gradesFileName);
                }
            } else {
        $upload_errors[] = "Grades file too large (max {$gradesMaxFileSizeMb}MB): " . htmlspecialchars($gradesFileName);
            }
    } else if ($_FILES['grades_file']['error'] !== UPLOAD_ERR_NO_FILE) {
      // Only record a problem if it wasn't simply 'no file selected'
      error_log("Grades file upload error: " . $_FILES['grades_file']['error']);
      $upload_errors[] = "Grades file upload error code: " . $_FILES['grades_file']['error'];
    } // else: ignore UPLOAD_ERR_NO_FILE (4)
    } else {
        log_debug("No grades_file found in FILES array");
        error_log("No grades_file found in \$_FILES array");
    }

    // Process EAF file if present
    $eaf_upload_success = false;
    if (isset($_FILES['eaf_file'])) {
        log_debug("EAF file found in FILES array, error code: " . $_FILES['eaf_file']['error']);
        error_log("EAF file found in \$_FILES, error code: " . $_FILES['eaf_file']['error']);
        
  if ($_FILES['eaf_file']['error'] === UPLOAD_ERR_OK) {
            log_debug("Processing EAF file upload - no upload errors");
            error_log("Processing EAF file upload");
            $eafFileName = $_FILES['eaf_file']['name'];
            $eafFileTmpName = $_FILES['eaf_file']['tmp_name'];
            $eafFileSize = $_FILES['eaf_file']['size'];
            error_log("EAF file: " . $eafFileName . ", Size: " . $eafFileSize);
            
            // Validate EAF file
            if ($eafFileSize <= $generalMaxFileSize) {
                $eafFileExt = strtolower(pathinfo($eafFileName, PATHINFO_EXTENSION));
                if (in_array($eafFileExt, ['jpg', 'jpeg', 'png', 'gif', 'pdf'])) {
                    // Get directory for EAF
                    $eafFolder = $documentTypeToFolder['eaf'] ?? 'enrollment_forms';
                    $eafUploadDir = $baseUploadDir . $eafFolder . "/";
                    
                    // Ensure directory exists
                    if (!file_exists($eafUploadDir)) {
                        mkdir($eafUploadDir, 0755, true);
                    }
                    
                    // Generate standardized filename: {STUDENT_ID}_{LastName}_{FirstName}_EAF.{ext}
                    $eafToken = $documentTypeToToken['eaf'] ?? 'EAF';
                    $secureEafFileName = $student_id_safe . "_" . $last_name_safe . "_" . $first_name_safe . "_" . $eafToken . "." . $eafFileExt;
                    $eafFinalPath = $eafUploadDir . $secureEafFileName;
                    
                    // Delete old file if it exists
                    if (file_exists($eafFinalPath)) {
                        unlink($eafFinalPath);
                        if (file_exists($eafFinalPath . '.ocr.txt')) {
                            unlink($eafFinalPath . '.ocr.txt');
                        }
                    }
                    
                    if (move_uploaded_file($eafFileTmpName, $eafFinalPath)) {
                        // Normalize path to use forward slashes for database consistency
                        $normalizedEafPath = str_replace('\\', '/', $eafFinalPath);
                        
                        // Insert EAF into database
                        try {
                            // For EAF, always create a new record (no conflict resolution)
                            // This ensures resubmits work properly
                            $timestamp_id = uniqid();
                            $eaf_stmt = pg_prepare($connection, "insert_eaf_upload_" . $timestamp_id, 
                                "INSERT INTO documents (student_id, type, file_path, upload_date) 
                                 VALUES ($1, $2, $3, NOW())");
                            
                            if ($eaf_stmt) {
                                $eaf_result = pg_execute($connection, "insert_eaf_upload_" . $timestamp_id, [
                                    $student_id, 
                                    'eaf', 
                                    $normalizedEafPath
                                ]);
                                
                if ($eaf_result) {
                                    error_log("EAF uploaded successfully for student: " . $student_id);
                                    $eaf_upload_success = true;
                                    $uploaded_count++; // Count EAF in total uploads

                  // Run OCR for EAF and store confidence/text (aligned with student_register logic style)
                  try {
                    $ocr = ocr_extract_text_and_conf($eafFinalPath, dirname($eafFinalPath));
                    $avgConf = $ocr['confidence'];
                    if (!empty($ocr['text'])) {
                      @file_put_contents($eafFinalPath . '.ocr.txt', $ocr['text']);
                    }
                    if ($avgConf !== null) {
                      @pg_query_params($connection, "UPDATE documents SET ocr_confidence = $1 WHERE student_id = $2 AND type = 'eaf' AND file_path = $3", [
                        $avgConf,
                        $student_id,
                        $normalizedEafPath
                      ]);
                    }
                  } catch (Throwable $t) {
                    error_log('EAF OCR failed: ' . $t->getMessage());
                  }
                                } else {
                                    error_log("Database execution failed for EAF");
                                    $upload_errors[] = "Database error for EAF file";
                                    if (file_exists($eafFinalPath)) {
                                        unlink($eafFinalPath);
                                    }
                                }
                            } else {
                                error_log("Database preparation failed for EAF");
                                $upload_errors[] = "Database preparation error for EAF";
                            }
                        } catch (Exception $e) {
                            error_log("Exception during EAF upload: " . $e->getMessage());
                            $upload_errors[] = "Database error for EAF: " . $e->getMessage();
                            if (file_exists($eafFinalPath)) {
                                unlink($eafFinalPath);
                            }
                        }
                    } else {
                        $upload_errors[] = "Failed to upload EAF file: " . htmlspecialchars($eafFileName);
                    }
                } else {
                    $upload_errors[] = "Invalid EAF file type: " . htmlspecialchars($eafFileName);
                }
            } else {
        $upload_errors[] = "EAF file too large (max {$generalMaxFileSizeMb}MB): " . htmlspecialchars($eafFileName);
            }
    } else if ($_FILES['eaf_file']['error'] !== UPLOAD_ERR_NO_FILE) {
      // Only record a problem if it wasn't simply 'no file selected'
      error_log("EAF file upload error: " . $_FILES['eaf_file']['error']);
      $upload_errors[] = "EAF file upload error code: " . $_FILES['eaf_file']['error'];
    } // else: ignore UPLOAD_ERR_NO_FILE (4)
    } else {
        log_debug("No eaf_file found in FILES array");
        error_log("No eaf_file found in \$_FILES array");
    }

    // Log final results before flash messaging
    log_debug("=== UPLOAD RESULTS ===");
    log_debug("upload_success: " . ($upload_success ? 'true' : 'false'));
    log_debug("grades_upload_success: " . ($grades_upload_success ? 'true' : 'false'));
    log_debug("uploaded_count: " . $uploaded_count);
    log_debug("upload_errors: " . implode('; ', $upload_errors));

    // Enhanced flash messaging with detailed error reporting
    $total_success = ($upload_success || $grades_upload_success || $eaf_upload_success);
    if ($total_success && $uploaded_count > 0) {
        // Build accurate message listing which documents were uploaded
        $parts = [];
        if (!empty($uploaded_documents_types)) {
          $labels = [
            'id_picture' => 'ID picture',
            'letter_to_mayor' => 'Letter to the Mayor',
            'certificate_of_indigency' => 'Certificate of Indigency',
          ];
          foreach ($uploaded_documents_types as $t) {
            $parts[] = $labels[$t] ?? ucfirst(str_replace('_',' ', $t));
          }
        }
        if ($grades_upload_success) { $parts[] = 'academic grades'; }
        if ($eaf_upload_success) { $parts[] = 'EAF'; }
        if (!empty($parts)) {
          $message = 'Successfully uploaded: ' . implode(', ', $parts) . '.';
        } else {
          $message = "Successfully uploaded $uploaded_count file(s).";
        }
        $_SESSION['upload_success'] = $message;
        if (!empty($upload_errors)) {
            $_SESSION['upload_partial'] = "Some files had issues: " . implode("; ", $upload_errors);
        }
    } else {
        if (!empty($upload_errors)) {
            $_SESSION['upload_fail'] = "Upload failed: " . implode("; ", $upload_errors);
        } else {
            $_SESSION['upload_fail'] = "No files were uploaded successfully.";
        }
    }
    header("Location: upload_document.php");
    exit;
}

// Note: Grades file upload is now handled in the main upload logic above
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Document Upload - EducAid</title>
  <link href="../../assets/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet" />
  <link rel="stylesheet" href="../../assets/css/student/homepage.css" />
  <link rel="stylesheet" href="../../assets/css/student/sidebar.css" />
  <link rel="stylesheet" href="../../assets/css/student/upload.css" />
  <style>
    .grades-analysis-card {
      border: 2px solid #e9ecef;
      border-radius: 10px;
      padding: 20px;
      margin-top: 15px;
      background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
    }
    
    .analysis-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 15px;
    }
    
    .confidence-badge {
      background: #17a2b8;
      color: white;
      padding: 4px 8px;
      border-radius: 15px;
      font-size: 0.85em;
      font-weight: 600;
    }
    
    .overall-status {
      text-align: center;
      padding: 15px;
      border-radius: 8px;
      margin: 15px 0;
      font-weight: 700;
      font-size: 1.1em;
    }
    
    .status-passed {
      background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
      color: #155724;
      border: 2px solid #28a745;
    }
    
    .status-failed {
      background: linear-gradient(135deg, #f8d7da 0%, #f1b0b7 100%);
      color: #721c24;
      border: 2px solid #dc3545;
    }
    
    .status-review {
      background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
      color: #856404;
      border: 2px solid #ffc107;
    }
    
    .grades-summary {
      display: flex;
      justify-content: space-around;
      margin: 15px 0;
      padding: 15px;
      background: rgba(0,123,255,0.1);
      border-radius: 8px;
    }
    
    .summary-item {
      text-align: center;
    }
    
    .summary-label {
      display: block;
      font-size: 0.9em;
      color: #6c757d;
      margin-bottom: 5px;
    }
    
    .summary-value {
      display: block;
      font-size: 1.5em;
      font-weight: 700;
      color: #007bff;
    }
    
    .extracted-grades h6 {
      color: #495057;
      border-bottom: 2px solid #007bff;
      padding-bottom: 5px;
      margin-bottom: 15px;
    }
    
    .grade-item {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 10px;
      margin-bottom: 8px;
      border-radius: 6px;
      border: 1px solid #dee2e6;
    }
    
    .grade-pass {
      background: linear-gradient(90deg, #d4edda 0%, #ffffff 100%);
      border-left: 4px solid #28a745;
    }
    
    .grade-fail {
      background: linear-gradient(90deg, #f8d7da 0%, #ffffff 100%);
      border-left: 4px solid #dc3545;
    }
    
    .subject-name {
      font-weight: 600;
      color: #495057;
      flex: 1;
    }
    
    .grade-value {
      font-weight: 700;
      color: #007bff;
      margin: 0 15px;
    }
    
    .grade-equivalent {
      font-size: 0.9em;
      color: #6c757d;
    }
    
    .grade-status {
      font-size: 1.2em;
    }
    
    .processing-indicator {
      text-align: center;
      padding: 20px;
      background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
      border-radius: 8px;
      margin-top: 15px;
    }
    
    .processing-indicator .spinner-border {
      color: #007bff;
      margin-bottom: 10px;
    }
    
    .gpa-indicator {
      background: #f8f9fa;
      border: 1px solid #dee2e6;
      border-radius: 8px;
      padding: 10px;
      margin-top: 10px;
      text-align: center;
    }
    
    /* Uploaded Document Display Styles */
    .uploaded-document-card {
      background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
      border: 2px solid #28a745;
      border-radius: 12px;
      padding: 20px;
      margin-top: 15px;
      position: relative;
      box-shadow: 0 4px 12px rgba(40, 167, 69, 0.1);
    }
    
    .document-info {
      display: flex;
      align-items: center;
      gap: 15px;
    }
    
    .document-preview {
      flex-shrink: 0;
    }
    
    .document-thumbnail {
      width: 80px;
      height: 80px;
      border-radius: 8px;
      object-fit: cover;
      cursor: pointer;
      border: 2px solid #dee2e6;
      transition: transform 0.2s ease;
    }
    
    .document-thumbnail:hover {
      transform: scale(1.05);
      border-color: #007bff;
    }
    
    .document-icon {
      width: 80px;
      height: 80px;
      display: flex;
      align-items: center;
      justify-content: center;
      background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
      border-radius: 8px;
      cursor: pointer;
      transition: transform 0.2s ease;
    }
    
    .document-icon:hover {
      transform: scale(1.05);
    }
    
    .document-icon i {
      font-size: 2.5rem;
      color: white;
    }
    
    .document-details {
      flex-grow: 1;
    }
    
    .document-details h6 {
      margin: 0 0 8px 0;
      color: #495057;
      font-weight: 600;
      word-break: break-all;
    }
    
    .document-actions {
      display: flex;
      flex-direction: column;
      gap: 8px;
    }
    
    .status-badge {
      position: absolute;
      top: -8px;
      right: -8px;
      padding: 6px 12px;
      border-radius: 20px;
      font-size: 0.85em;
      font-weight: 600;
      display: flex;
      align-items: center;
      gap: 4px;
    }
    
    .status-submitted {
      background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
      color: white;
      box-shadow: 0 2px 8px rgba(40, 167, 69, 0.3);
    }
    
    .resubmit-form {
      margin-top: 15px;
      padding: 15px;
      background: #f8f9fa;
      border-radius: 8px;
      border: 1px solid #dee2e6;
    }
    
    .resubmit-actions {
      display: flex;
      gap: 10px;
    }
    
    /* Ensure consistent sizing and borders for all upload sections */
    .upload-form-item {
      margin-bottom: 25px;
    }
    
    .upload-form-item .uploaded-document-card {
      background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
      border: 2px solid #28a745;
      border-radius: 12px;
      padding: 20px;
      margin-top: 15px;
      position: relative;
      box-shadow: 0 4px 12px rgba(40, 167, 69, 0.1);
      min-height: 120px;
    }
    
    .upload-form-item .document-thumbnail {
      width: 80px;
      height: 80px;
      border-radius: 8px;
      object-fit: cover;
      cursor: pointer;
      border: 2px solid #dee2e6;
    }

    /* Dynamic status updates */
    .upload-form-item.submitted .custom-file-input {
      display: none;
    }
    
    .upload-form-item.submitted .uploaded-document-card {
      display: block;
    }
    
    /* Validation Modal Styles */
    .validation-results {
      padding: 10px 0;
    }
    
    .validation-results .list-group-item {
      border-left: 3px solid transparent;
      transition: all 0.2s ease;
    }
    
    .validation-results .list-group-item:hover {
      background-color: #f8f9fa;
      border-left-color: #007bff;
    }
    
    .validation-results .badge {
      font-size: 0.9em;
      padding: 0.4em 0.8em;
      min-width: 60px;
    }
    
    .validation-results .alert {
      border-left: 4px solid currentColor;
    }
    
    .validation-results .card {
      border: none;
      box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    }
    
    .validation-results pre {
      background: #ffffff;
      border: 1px solid #dee2e6;
      border-radius: 4px;
      padding: 15px;
      font-size: 0.85em;
      line-height: 1.5;
    }
    
    .validation-results table {
      font-size: 0.9em;
    }
    
    .validation-results thead th {
      background-color: #f1f3f5;
      font-weight: 600;
      text-transform: uppercase;
      font-size: 0.75em;
      letter-spacing: 0.5px;
    }
    
    /* Responsive design */
    @media (max-width: 768px) {
      .document-info {
        flex-direction: column;
        text-align: center;
      }
      
      .document-actions {
        flex-direction: row;
        justify-content: center;
      }
      
      .validation-results table {
        font-size: 0.8em;
      }
      
      .validation-results .badge {
        min-width: 50px;
        font-size: 0.8em;
      }
    }
  </style>
</head>
<body>
  <!-- Student Topbar -->
  <?php include __DIR__ . '/../../includes/student/student_topbar.php'; ?>

  <div id="wrapper" style="padding-top: var(--topbar-h);">
    <!-- Sidebar -->
    <?php include __DIR__ . '/../../includes/student/student_sidebar.php'; ?>
    
    <!-- Student Header -->
    <?php include __DIR__ . '/../../includes/student/student_header.php'; ?>
    
    <!-- Main Content Area -->
    <section class="home-section upload-container" id="page-content-wrapper">
      <div class="container-fluid py-4 px-4">
        <div class="upload-card">
          <!-- Header Section -->
          <div class="upload-header">
            <h1>
              <i class="bi bi-cloud-upload-fill me-3"></i>
              Upload Documents
            </h1>
            <p>Complete your application by uploading all required documents</p>
            <?php 
            // Check if this is after a distribution cycle
            // Determine a safe distribution timestamp column
            $distDateCol = null;
            if (ea_col_exists($connection, 'distribution_snapshots', 'finalized_at')) {
              $distDateCol = 'finalized_at';
            } elseif (ea_col_exists($connection, 'distribution_snapshots', 'distribution_date')) {
              $distDateCol = 'distribution_date';
            } elseif (ea_col_exists($connection, 'distribution_snapshots', 'created_at')) {
              $distDateCol = 'created_at';
            }
            $lastDistTs = null;
            if ($distDateCol) {
              $last_distribution_query = "SELECT $distDateCol AS last_ts FROM distribution_snapshots ORDER BY $distDateCol DESC LIMIT 1";
              $last_distribution_result = @pg_query($connection, $last_distribution_query);
              if ($last_distribution_result && ($ldRow = pg_fetch_assoc($last_distribution_result))) {
                $lastDistTs = $ldRow['last_ts'] ?? null;
              }
            }
            
            if ($lastDistTs && $student_application_date && strtotime($student_application_date) < strtotime($lastDistTs)): ?>
            <div class="alert alert-info mt-3">
              <i class="bi bi-info-circle me-2"></i>
              <strong>New Distribution Cycle:</strong> Please upload your documents again for the current academic period. 
              Your previous documents have been archived and you need to submit fresh copies.
            </div>
            <?php endif; ?>
          </div>

          <!-- Enhanced Flash Messages -->
          <?php if (!empty($flash_success)): ?>
            <div class="alert alert-success mx-4 mt-4 success-animation">
              <i class="bi bi-check-circle-fill me-2"></i>
              <strong>Success!</strong> <?= htmlspecialchars($success_message) ?>
            </div>
          <?php endif; ?>
          
          <?php if (!empty($flash_partial)): ?>
            <div class="alert alert-warning mx-4 mt-4">
              <i class="bi bi-exclamation-triangle-fill me-2"></i>
              <strong>Partial Success:</strong> <?= htmlspecialchars($partial_message) ?>
            </div>
          <?php endif; ?>
          
          <?php if (!empty($flash_fail)): ?>
            <div class="alert alert-danger mx-4 mt-4">
              <i class="bi bi-x-circle-fill me-2"></i>
              <strong>Upload Failed:</strong> <?= htmlspecialchars($error_message) ?>
            </div>
          <?php endif; ?>

          <?php if ($uploads_suspended): ?>
            <div class="alert alert-warning mx-4 mt-4">
              <i class="bi bi-pause-circle-fill me-2"></i>
              <strong>Uploads Unavailable:</strong>
              Document uploads are currently closed because the distribution cycle is <?= htmlspecialchars($distribution_status_label_lower) ?>.
              Please wait until the next distribution is opened.
            </div>
          <?php endif; ?>

          <!-- Progress Section -->
          <div class="progress-section">
            <h3 class="progress-title">Upload Progress</h3>
            
            <div class="document-progress">
              <div class="progress-item">
                <div class="progress-icon <?php echo $has_id_any ? 'completed' : 'pending'; ?>">
                  <i class="bi bi-person-badge-fill"></i>
                </div>
                <div class="progress-label">ID Picture</div>
                <div class="progress-status">
                  <?php echo $has_id_any ? 'Submitted' : 'Required'; ?>
                </div>
              
              <div class="progress-item">
                <div class="progress-icon <?php echo $has_grades_any ? 'completed' : 'pending'; ?>">
                  <i class="bi bi-file-earmark-text-fill"></i>
                </div>
                <div class="progress-label">Academic Grades</div>
                <div class="progress-status">
                  <?php echo $has_grades_any ? 'Submitted' : 'Required'; ?>
                </div>
              </div>
              
              <div class="progress-item">
                <div class="progress-icon <?php echo $has_eaf_any ? 'completed' : 'pending'; ?>">
                  <i class="bi bi-file-earmark-pdf"></i>
                </div>
                <div class="progress-label">Enrollment Assessment Form</div>
                <div class="progress-status">
                  <?php echo $has_eaf_any ? 'Submitted' : 'Required'; ?>
                </div>
              </div>
              <div class="progress-item">
                <div class="progress-icon <?php echo $has_letter_any ? 'completed' : 'pending'; ?>">
                  <i class="bi bi-envelope-paper-fill"></i>
                </div>
                <div class="progress-label">Letter to the Mayor</div>
                <div class="progress-status">
                  <?php echo $has_letter_any ? 'Submitted' : 'Required'; ?>
                </div>
              </div>
              <div class="progress-item">
                <div class="progress-icon <?php echo $has_certificate_any ? 'completed' : 'pending'; ?>">
                  <i class="bi bi-file-earmark-medical-fill"></i>
                </div>
                <div class="progress-label">Certificate of Indigency</div>
                <div class="progress-status">
                  <?php echo $has_certificate_any ? 'Submitted' : 'Required'; ?>
                </div>
              </div>
            </div>

            <!-- Overall Progress Bar -->
            <div class="overall-progress">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="fw-bold">Overall Progress</span>
                <?php 
                $completed_docs = 0;
                $total_required = 5;
                if ($has_id_any) $completed_docs++;
                if ($has_grades_any) $completed_docs++;
                if ($has_eaf_any) $completed_docs++;
                if ($has_letter_any) $completed_docs++;
                if ($has_certificate_any) $completed_docs++;
                ?>
                <span class="text-muted"><?php echo $completed_docs; ?> of <?php echo $total_required; ?> completed</span>
              </div>
              <div class="progress-bar-container">
                <div class="progress-bar-fill" style="width: <?php echo max(0, min(100, ($completed_docs / max(1,$total_required)) * 100)); ?>%"></div>
              </div>
            </div>
          </div>

          <?php if ($allDocumentsUploaded): ?>
            <!-- Completion State -->
            <div class="completion-state">
              <div class="completion-icon">
                <i class="bi bi-check-circle-fill"></i>
              </div>
              <h3>All Documents Uploaded!</h3>
              <p>
                Congratulations! You have successfully uploaded all required documents including your ID picture, academic grades, and Enrollment Assessment Form (EAF). 
                Your application is now complete and under review by our administration team.
                <br><br>
                <strong>Next Steps:</strong><br>
                • Wait for admin review<br>
                • Check your notifications for updates<br>
                • You can re-upload if admin requests changes
              </p>
              
              <?php if ($latest_grade_upload): ?>
                <div class="grades-analysis-card">
                  <div class="analysis-header">
                    <h5><i class="bi bi-graph-up me-2"></i>Your Grades Status</h5>
                    <?php if (!empty($latest_grade_upload['ocr_confidence'])): ?>
                      <span class="confidence-badge">OCR Confidence: <?= round($latest_grade_upload['ocr_confidence'], 1) ?>%</span>
                    <?php endif; ?>
                  </div>
                  
                  <div class="overall-status <?php 
                    echo ($latest_grade_upload['validation_status'] ?? 'pending') === 'passed' ? 'status-passed' : 
                         (($latest_grade_upload['validation_status'] ?? 'pending') === 'failed' ? 'status-failed' : 'status-review'); 
                  ?>">
                    <i class="bi <?php 
                      echo ($latest_grade_upload['validation_status'] ?? 'pending') === 'passed' ? 'bi-check-circle' : 
                           (($latest_grade_upload['validation_status'] ?? 'pending') === 'failed' ? 'bi-x-circle' : 'bi-clock'); 
                    ?> me-2"></i>
                    <?php 
                      $vs = $latest_grade_upload['validation_status'] ?? 'pending';
                      echo $vs === 'passed' ? 'GRADES MEET REQUIREMENTS' : 
                           ($vs === 'failed' ? 'GRADES BELOW MINIMUM (75% / 3.00)' : 
                            ($vs === 'manual_review' ? 'UNDER MANUAL REVIEW' : 'PENDING PROCESSING')); 
                    ?>
                  </div>
                  
                  <?php if (!empty($latest_grade_upload['admin_reviewed']) && !empty($latest_grade_upload['admin_notes'])): ?>
                    <div class="alert alert-info">
                      <strong>Admin Notes:</strong> <?= htmlspecialchars($latest_grade_upload['admin_notes']) ?>
                    </div>
                  <?php endif; ?>
                  
                  <div class="text-center mt-3">
                    <small class="text-muted">
                      <i class="bi bi-info-circle me-1"></i>
                      <?php if ($university_policy): ?>
                        <?= htmlspecialchars($university_policy['university_name']) ?>: <?= htmlspecialchars($university_policy['passing_description']) ?>
                      <?php else: ?>
                        Philippine grading system: 1.00-3.00 (Passing) | 75%-100% (Passing)
                      <?php endif; ?>
                    </small>
                  </div>
                </div>
              <?php endif; ?>
            </div>
          <?php else: ?>
            <?php if ($uploads_locked): ?>
              <!-- Uploads Locked Message -->
              <div class="alert alert-info">
                <i class="bi bi-lock-fill me-2"></i>
                <h5>Documents Confirmed & Locked</h5>
                <p class="mb-0">Your documents were confirmed on <strong><?= date('F d, Y \a\t g:i A', strtotime($uploads_confirmed_at)) ?></strong>.</p>
                <p class="mb-0">You can no longer modify or resubmit your documents.</p>
                <p class="mb-0 mt-2"><small>If you need to make changes, please contact the administrator through the chat support.</small></p>
              </div>
            <?php else: ?>
            <!-- Upload Form -->
            <form method="POST" enctype="multipart/form-data" id="uploadForm" novalidate>
              <div class="upload-form-section">
                <!-- ID Picture -->
                <div class="upload-form-item" data-document="id_picture">
                  <div class="upload-item-header">
                    <div class="upload-item-icon">
                      <i class="bi bi-person-badge-fill"></i>
                    </div>
                    <div class="upload-item-info">
                      <h4>ID Picture</h4>
                      <p>Upload a clear photo of your government-issued ID</p>
                    </div>
                  </div>
                  
                  <?php if ($uploaded_id_picture): ?>
                    <!-- Uploaded Document Display -->
                    <div class="uploaded-document-card">
                      <div class="document-info">
                        <div class="document-preview">
                          <?php 
                          $file_ext = strtolower(pathinfo($uploaded_id_picture['file_path'], PATHINFO_EXTENSION));
                          if (in_array($file_ext, ['jpg', 'jpeg', 'png', 'gif'])): ?>
                            <img src="<?php echo htmlspecialchars($uploaded_id_picture['file_path']); ?>" 
                                 alt="ID Picture" class="document-thumbnail" onclick="viewDocument('<?php echo htmlspecialchars($uploaded_id_picture['file_path']); ?>', 'ID Picture')">
                          <?php else: ?>
                            <div class="document-icon" onclick="viewDocument('<?php echo htmlspecialchars($uploaded_id_picture['file_path']); ?>', 'ID Picture')">
                              <i class="bi bi-file-pdf-fill"></i>
                            </div>
                          <?php endif; ?>
                        </div>
                        <div class="document-details">
                          <h6><?php echo htmlspecialchars(basename($uploaded_id_picture['file_path'])); ?></h6>
                          <p class="text-muted mb-1">
                            <i class="bi bi-calendar3"></i> 
                            Uploaded: <?php echo date('M j, Y \a\t g:i A', strtotime($uploaded_id_picture['upload_date'])); ?>
                          </p>
                          <p class="text-muted mb-0">
                            <i class="bi bi-file-earmark"></i> 
                            Type: <?php echo strtoupper($file_ext); ?> file
                          </p>
                          <?php 
                            $id_ocr_path = $uploaded_id_picture['file_path'] . '.ocr.txt';
                            $id_verify_path = $uploaded_id_picture['file_path'] . '.verify.json';
                            $has_extra = file_exists($id_ocr_path) || file_exists($id_verify_path);
                            if ($has_extra): ?>
                            <div class="mt-2">
                              <?php if (file_exists($id_ocr_path)): ?>
                                <a class="btn btn-link btn-sm" href="<?php echo htmlspecialchars($id_ocr_path); ?>" target="_blank">View OCR Text</a>
                              <?php endif; ?>
                              <?php 
                                if (file_exists($id_verify_path)) {
                                  $idv = safe_json_decode(@file_get_contents($id_verify_path), true);
                                  if (is_array($idv)) {
                                    $nm = !empty($idv['name_match']); $sm = !empty($idv['school_match']); $vs = intval($idv['verification_score'] ?? 0);
                                    echo '<div class="small text-muted">Identity check: ' . ($nm ? 'Name ✓' : 'Name ✗') . ' • ' . ($sm ? 'School ✓' : 'School ✗') . ' • Score: ' . $vs . '%</div>';
                                  }
                                }
                              ?>
                            </div>
                          <?php endif; ?>
                        </div>
                        <div class="document-actions">
                          <button type="button" class="btn btn-outline-primary btn-sm" 
                                  onclick="viewDocument('<?php echo htmlspecialchars($uploaded_id_picture['file_path']); ?>', 'ID Picture')">
                            <i class="bi bi-eye"></i> View
                          </button>
                          <?php if (!$uploads_suspended): ?>
                            <button type="button" class="btn btn-outline-warning btn-sm" onclick="enableResubmit('id_picture')">
                              <i class="bi bi-arrow-repeat"></i> Resubmit
                            </button>
                          <?php endif; ?>
                        </div>
                      </div>
                      <div class="status-badge status-submitted">
                        <i class="bi bi-check-circle"></i> Submitted
                      </div>
                    </div>
                    
                    <!-- Hidden resubmit form -->
                    <?php if (!$uploads_suspended): ?>
                      <div class="resubmit-form" id="resubmit_id_picture" style="display: none;">
                        <div class="custom-file-input">
                          <input type="file" name="documents[]" id="id_picture_input" accept=".pdf,.jpg,.jpeg,.png">
                          <input type="hidden" name="document_type[]" value="id_picture">
                          <div class="file-input-label">
                            <i class="bi bi-cloud-upload"></i>
                            <span>Choose new file or drag and drop</span>
                          </div>
                        </div>
                        <div class="resubmit-actions mt-2">
                          <button type="button" class="btn btn-secondary btn-sm" onclick="cancelResubmit('id_picture')">Cancel</button>
                        </div>
                        <div class="file-preview" id="preview_id_picture"></div>
                      </div>
                    <?php endif; ?>
                  <?php else: ?>
                    <!-- Upload Form -->
                    <?php if (!$uploads_suspended): ?>
                      <div class="custom-file-input">
                        <input type="file" name="documents[]" id="id_picture_input" accept=".pdf,.jpg,.jpeg,.png">
                        <input type="hidden" name="document_type[]" value="id_picture">
                        <div class="file-input-label">
                          <i class="bi bi-cloud-upload"></i>
                          <span>Choose file or drag and drop</span>
                        </div>
                      </div>
                      <div class="file-preview" id="preview_id_picture"></div>
                    <?php else: ?>
                      <p class="text-muted small mb-0">Uploads are currently closed for this document.</p>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>

                <!-- Academic Grades Upload Section -->
                <div class="upload-form-item" data-document="grades">
                  <div class="upload-item-header">
                    <div class="upload-item-icon">
                      <i class="bi bi-file-earmark-text-fill"></i>
                    </div>
                    <div class="upload-item-info">
                      <h4>Academic Grades</h4>
                      <p>Upload your latest report card or transcript (PDF or Image)</p>
                      <small class="text-muted">
                        <strong><?php echo $university_policy ? htmlspecialchars($university_policy['university_name']) . ' Requirements:' : 'Philippine Grading Requirements:'; ?></strong><br>
                        • <?php echo $university_policy ? htmlspecialchars($university_policy['passing_description']) : '1.00 - 3.00 GPA (Passing) | 75% - 100% (Passing)'; ?><br>
                        • System supports multiple university grading scales
                      </small>
                    </div>
                  </div>
                  
                  <?php if ($uploaded_grades): ?>
                    <!-- Uploaded Document Display -->
                    <div class="uploaded-document-card">
                      <div class="document-info">
                        <div class="document-preview">
                          <?php 
                          $grades_file_ext = strtolower(pathinfo($uploaded_grades['file_path'], PATHINFO_EXTENSION));
                          if (in_array($grades_file_ext, ['jpg', 'jpeg', 'png', 'gif'])): ?>
                            <img src="<?php echo htmlspecialchars($uploaded_grades['file_path']); ?>" 
                                 alt="Academic Grades" class="document-thumbnail" onclick="viewDocument('<?php echo htmlspecialchars($uploaded_grades['file_path']); ?>', 'Academic Grades')">
                          <?php else: ?>
                            <div class="document-icon" onclick="viewDocument('<?php echo htmlspecialchars($uploaded_grades['file_path']); ?>', 'Academic Grades')">
                              <i class="bi bi-file-pdf-fill"></i>
                            </div>
                          <?php endif; ?>
                        </div>
                        <div class="document-details">
                          <h6><?php echo htmlspecialchars(basename($uploaded_grades['file_path'])); ?></h6>
                          <p class="text-muted mb-1">
                            <i class="bi bi-calendar3"></i> 
                            Uploaded: <?php echo date('M j, Y \a\t g:i A', strtotime($uploaded_grades['upload_date'])); ?>
                          </p>
                          <p class="text-muted mb-0">
                            <i class="bi bi-file-earmark"></i> 
                            Type: <?php echo strtoupper($grades_file_ext); ?> file
                          </p>
                        </div>
                        <div class="document-actions">
                          <button type="button" class="btn btn-outline-primary btn-sm" 
                                  onclick="viewDocument('<?php echo htmlspecialchars($uploaded_grades['file_path']); ?>', 'Academic Grades')">
                            <i class="bi bi-eye"></i> View
                          </button>
                          <?php if (!$uploads_suspended): ?>
                            <button type="button" class="btn btn-outline-warning btn-sm" onclick="enableResubmit('grades')">
                              <i class="bi bi-arrow-repeat"></i> Resubmit
                            </button>
                          <?php endif; ?>
                        </div>
                      </div>
                      <div class="status-badge status-submitted">
                        <i class="bi bi-check-circle"></i> Submitted
                      </div>
                    </div>
                    
                    <!-- Hidden resubmit form -->
                    <?php if (!$uploads_suspended): ?>
                      <div class="resubmit-form" id="resubmit_grades" style="display: none;">
                        <form method="POST" enctype="multipart/form-data" id="gradesResubmitForm" action="upload_document.php">
                          <div class="custom-file-input">
                            <input type="file" name="grades_file" id="grades_resubmit_input" accept=".pdf,.jpg,.jpeg,.png" data-max-mb="10" required>
                            <div class="file-input-label">
                              <i class="bi bi-cloud-upload"></i>
                              <span>Choose new grades file or drag and drop</span>
                            </div>
                          </div>
                          <div class="resubmit-actions mt-2">
                            <button type="button" class="btn btn-secondary btn-sm" onclick="cancelResubmit('grades')">Cancel</button>
                            <button type="submit" class="btn btn-primary btn-sm">Upload New Grades</button>
                          </div>
                          <div class="file-preview" id="preview_grades_resubmit"></div>
                        </form>
                      </div>
                    <?php endif; ?>
                  <?php else: ?>
                    <!-- Upload Form -->
                    <?php if (!$uploads_suspended): ?>
                      <div class="custom-file-input">
                        <input type="file" name="grades_file" id="grades_input" accept=".pdf,.jpg,.jpeg,.png" data-max-mb="10">
                        <div class="file-input-label">
                          <i class="bi bi-cloud-upload"></i>
                          <span>Choose file or drag and drop</span>
                        </div>
                      </div>
                      <div class="file-preview" id="preview_grades"></div>
                    <?php else: ?>
                      <p class="text-muted small mb-0">Uploads are currently closed for this document.</p>
                    <?php endif; ?>
                  <?php endif; ?>
                  
                  <?php if (!$uploads_suspended): ?>
                    <div id="grades_processing" style="display: none;" class="processing-indicator">
                      <div class="spinner-border" role="status"></div>
                      <div><strong>Processing grades with OCR...</strong></div>
                      <small class="text-muted">Analyzing Philippine grading system formats</small>
                    </div>
                  <?php endif; ?>
                  
                  <div id="grades_results" style="display: none;"></div>
                </div>

              <!-- EAF Upload Section -->
                <div class="upload-form-item" data-document="eaf">
                  <div class="upload-item-header">
                    <div class="upload-item-icon">
                      <i class="bi bi-file-earmark-pdf-fill"></i>
                    </div>
                    <div class="upload-item-info">
                      <h4>Enrollment Assessment Form (EAF)</h4>
                      <p>Upload your school's Enrollment Assessment Form (PDF or Image)</p>
                      <small class="text-muted">
                        <strong>Required Document:</strong><br>
                        • Official EAF from your educational institution<br>
                        • Clear and readable document format
                      </small>
                    </div>
                  </div>
                  
                  <?php if ($uploaded_eaf): ?>
                    <!-- Uploaded Document Display -->
                    <div class="uploaded-document-card">
                      <div class="document-info">
                        <div class="document-preview">
                          <?php 
                          $eaf_file_ext = strtolower(pathinfo($uploaded_eaf['file_path'], PATHINFO_EXTENSION));
                          if (in_array($eaf_file_ext, ['jpg', 'jpeg', 'png', 'gif'])): ?>
                            <img src="<?php echo htmlspecialchars($uploaded_eaf['file_path']); ?>" 
                                 alt="EAF" class="document-thumbnail" onclick="viewDocument('<?php echo htmlspecialchars($uploaded_eaf['file_path']); ?>', 'Enrollment Assessment Form')">
                          <?php else: ?>
                            <div class="document-icon" onclick="viewDocument('<?php echo htmlspecialchars($uploaded_eaf['file_path']); ?>', 'Enrollment Assessment Form')">
                              <i class="bi bi-file-pdf-fill"></i>
                            </div>
                          <?php endif; ?>
                        </div>
                        <div class="document-details">
                          <h6><?php echo htmlspecialchars(basename($uploaded_eaf['file_path'])); ?></h6>
                          <p class="text-muted mb-1">
                            <i class="bi bi-calendar3"></i> 
                            Uploaded: <?php echo date('M j, Y \a\t g:i A', strtotime($uploaded_eaf['upload_date'])); ?>
                          </p>
                          <p class="text-muted mb-0">
                            <i class="bi bi-file-earmark"></i> 
                            Type: <?php echo strtoupper($eaf_file_ext); ?> file
                          </p>
                          <?php $eaf_ocr_path = $uploaded_eaf['file_path'] . '.ocr.txt'; ?>
                          <div class="mt-2">
                            <?php if (!empty($uploaded_eaf['ocr_confidence'])): ?>
                              <span class="badge bg-info"><i class="bi bi-robot me-1"></i>OCR Confidence: <?php echo round($uploaded_eaf['ocr_confidence'], 1); ?>%</span>
                            <?php endif; ?>
                            <?php if (file_exists($eaf_ocr_path)): ?>
                              <a class="btn btn-link btn-sm" href="<?php echo htmlspecialchars($eaf_ocr_path); ?>" target="_blank">View OCR Text</a>
                            <?php endif; ?>
                          </div>
                        </div>
                        <div class="document-actions">
                          <button type="button" class="btn btn-outline-primary btn-sm" 
                                  onclick="viewDocument('<?php echo htmlspecialchars($uploaded_eaf['file_path']); ?>', 'Enrollment Assessment Form')">
                            <i class="bi bi-eye"></i> View
                          </button>
                          <button type="button" class="btn btn-outline-warning btn-sm" onclick="showResubmitForm('eaf')">
                            <i class="bi bi-arrow-repeat"></i> Resubmit
                          </button>
                        </div>
                      </div>
                      
                      <!-- Status Badge (consistent green pill) -->
                      <div class="status-badge status-submitted">
                        <i class="bi bi-check-circle"></i> Submitted
                      </div>
                    </div>

                    <!-- Hidden Resubmit Form -->
                    <div id="resubmit_eaf" class="resubmit-form" style="display: none;">
                      <form method="post" enctype="multipart/form-data" class="resubmit-form-content">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <div class="custom-file-input">
                          <input type="file" name="eaf_file" id="eaf_resubmit_input" accept=".pdf,.jpg,.jpeg,.png" required>
                          <div class="file-input-label">
                            <i class="bi bi-cloud-upload"></i>
                            <span>Choose new EAF file</span>
                          </div>
                        </div>
                        <div class="resubmit-actions mt-2">
                          <button type="button" class="btn btn-secondary btn-sm" onclick="cancelResubmit('eaf')">Cancel</button>
                          <button type="submit" class="btn btn-primary btn-sm">Upload New EAF</button>
                        </div>
                        <div class="file-preview" id="preview_eaf_resubmit"></div>
                      </form>
                    </div>
                  <?php else: ?>
                    <!-- Upload Form -->
                    <div class="custom-file-input">
                      <input type="file" name="eaf_file" id="eaf_input" accept=".pdf,.jpg,.jpeg,.png">
                      <div class="file-input-label">
                        <i class="bi bi-cloud-upload"></i>
                        <span>Choose file or drag and drop</span>
                      </div>
                    </div>
                    <div class="file-preview" id="preview_eaf"></div>
                  <?php endif; ?>
                </div>

                <!-- Letter to the Mayor Upload Section -->
                <div class="upload-form-item" data-document="letter_to_mayor">
                  <div class="upload-item-header">
                    <div class="upload-item-icon">
                      <i class="bi bi-envelope-paper-fill"></i>
                    </div>
                    <div class="upload-item-info">
                      <h4>Letter to the Mayor</h4>
                      <p>Upload your signed letter to the Mayor (PDF or Image)</p>
                    </div>
                  </div>
                  <?php if ($uploaded_letter): ?>
                    <div class="uploaded-document-card">
                      <div class="document-info">
                        <div class="document-preview">
                          <?php 
                          $letter_ext = strtolower(pathinfo($uploaded_letter['file_path'], PATHINFO_EXTENSION));
                          if (in_array($letter_ext, ['jpg','jpeg','png','gif'])): ?>
                            <img src="<?php echo htmlspecialchars($uploaded_letter['file_path']); ?>" 
                                 alt="Letter to the Mayor" class="document-thumbnail" onclick="viewDocument('<?php echo htmlspecialchars($uploaded_letter['file_path']); ?>', 'Letter to the Mayor')">
                          <?php else: ?>
                            <div class="document-icon" onclick="viewDocument('<?php echo htmlspecialchars($uploaded_letter['file_path']); ?>', 'Letter to the Mayor')">
                              <i class="bi bi-file-pdf-fill"></i>
                            </div>
                          <?php endif; ?>
                        </div>
                        <div class="document-details">
                          <h6><?php echo htmlspecialchars(basename($uploaded_letter['file_path'])); ?></h6>
                          <p class="text-muted mb-1">
                            <i class="bi bi-calendar3"></i> 
                            Uploaded: <?php echo date('M j, Y \\a\\t g:i A', strtotime($uploaded_letter['upload_date'])); ?>
                          </p>
                          <p class="text-muted mb-0">
                            <i class="bi bi-file-earmark"></i> 
                            Type: <?php echo strtoupper($letter_ext); ?> file
                          </p>
                          <?php $letter_ocr_path = $uploaded_letter['file_path'] . '.ocr.txt';
                            if (file_exists($letter_ocr_path)): ?>
                            <div class="mt-2">
                              <a class="btn btn-link btn-sm" href="<?php echo htmlspecialchars($letter_ocr_path); ?>" target="_blank">View OCR Text</a>
                            </div>
                          <?php endif; ?>
                        </div>
                        <div class="document-actions">
                          <button type="button" class="btn btn-outline-primary btn-sm" 
                                  onclick="viewDocument('<?php echo htmlspecialchars($uploaded_letter['file_path']); ?>', 'Letter to the Mayor')">
                            <i class="bi bi-eye"></i> View
                          </button>
                          <button type="button" class="btn btn-outline-warning btn-sm" onclick="enableResubmit('letter_to_mayor')">
                            <i class="bi bi-arrow-repeat"></i> Resubmit
                          </button>
                        </div>
                      </div>
                      <div class="status-badge status-submitted">
                        <i class="bi bi-check-circle"></i> Submitted
                      </div>
                    </div>

                    <div class="resubmit-form" id="resubmit_letter_to_mayor" style="display: none;">
                      <div class="custom-file-input">
                        <input type="file" name="documents[]" accept=".pdf,.jpg,.jpeg,.png">
                        <input type="hidden" name="document_type[]" value="letter_to_mayor">
                        <div class="file-input-label">
                          <i class="bi bi-cloud-upload"></i>
                          <span>Choose new file or drag and drop</span>
                        </div>
                      </div>
                      <div class="resubmit-actions mt-2">
                        <button type="button" class="btn btn-secondary btn-sm" onclick="cancelResubmit('letter_to_mayor')">Cancel</button>
                      </div>
                      <div class="file-preview"></div>
                    </div>
                  <?php else: ?>
                    <div class="custom-file-input">
                      <input type="file" name="documents[]" accept=".pdf,.jpg,.jpeg,.png">
                      <input type="hidden" name="document_type[]" value="letter_to_mayor">
                      <div class="file-input-label">
                        <i class="bi bi-cloud-upload"></i>
                        <span>Choose file or drag and drop</span>
                      </div>
                    </div>
                    <div class="file-preview"></div>
                  <?php endif; ?>
                </div>

                <!-- Certificate of Indigency Upload Section -->
                <div class="upload-form-item" data-document="certificate_of_indigency">
                  <div class="upload-item-header">
                    <div class="upload-item-icon">
                      <i class="bi bi-file-earmark-medical-fill"></i>
                    </div>
                    <div class="upload-item-info">
                      <h4>Certificate of Indigency</h4>
                      <p>Upload your official certificate of indigency (PDF or Image)</p>
                    </div>
                  </div>
                  <?php if ($uploaded_certificate): ?>
                    <div class="uploaded-document-card">
                      <div class="document-info">
                        <div class="document-preview">
                          <?php 
                          $cert_ext = strtolower(pathinfo($uploaded_certificate['file_path'], PATHINFO_EXTENSION));
                          if (in_array($cert_ext, ['jpg','jpeg','png','gif'])): ?>
                            <img src="<?php echo htmlspecialchars($uploaded_certificate['file_path']); ?>" 
                                 alt="Certificate of Indigency" class="document-thumbnail" onclick="viewDocument('<?php echo htmlspecialchars($uploaded_certificate['file_path']); ?>', 'Certificate of Indigency')">
                          <?php else: ?>
                            <div class="document-icon" onclick="viewDocument('<?php echo htmlspecialchars($uploaded_certificate['file_path']); ?>', 'Certificate of Indigency')">
                              <i class="bi bi-file-pdf-fill"></i>
                            </div>
                          <?php endif; ?>
                        </div>
                        <div class="document-details">
                          <h6><?php echo htmlspecialchars(basename($uploaded_certificate['file_path'])); ?></h6>
                          <p class="text-muted mb-1">
                            <i class="bi bi-calendar3"></i> 
                            Uploaded: <?php echo date('M j, Y \\a\\t g:i A', strtotime($uploaded_certificate['upload_date'])); ?>
                          </p>
                          <p class="text-muted mb-0">
                            <i class="bi bi-file-earmark"></i> 
                            Type: <?php echo strtoupper($cert_ext); ?> file
                          </p>
                          <?php $cert_ocr_path = $uploaded_certificate['file_path'] . '.ocr.txt';
                            if (file_exists($cert_ocr_path)): ?>
                            <div class="mt-2">
                              <a class="btn btn-link btn-sm" href="<?php echo htmlspecialchars($cert_ocr_path); ?>" target="_blank">View OCR Text</a>
                            </div>
                          <?php endif; ?>
                        </div>
                        <div class="document-actions">
                          <button type="button" class="btn btn-outline-primary btn-sm" 
                                  onclick="viewDocument('<?php echo htmlspecialchars($uploaded_certificate['file_path']); ?>', 'Certificate of Indigency')">
                            <i class="bi bi-eye"></i> View
                          </button>
                          <button type="button" class="btn btn-outline-warning btn-sm" onclick="enableResubmit('certificate_of_indigency')">
                            <i class="bi bi-arrow-repeat"></i> Resubmit
                          </button>
                        </div>
                      </div>
                      <div class="status-badge status-submitted">
                        <i class="bi bi-check-circle"></i> Submitted
                      </div>
                    </div>

                    <div class="resubmit-form" id="resubmit_certificate_of_indigency" style="display: none;">
                      <div class="custom-file-input">
                        <input type="file" name="documents[]" accept=".pdf,.jpg,.jpeg,.png">
                        <input type="hidden" name="document_type[]" value="certificate_of_indigency">
                        <div class="file-input-label">
                          <i class="bi bi-cloud-upload"></i>
                          <span>Choose new file or drag and drop</span>
                        </div>
                      </div>
                      <div class="resubmit-actions mt-2">
                        <button type="button" class="btn btn-secondary btn-sm" onclick="cancelResubmit('certificate_of_indigency')">Cancel</button>
                      </div>
                      <div class="file-preview"></div>
                    </div>
                  <?php else: ?>
                    <div class="custom-file-input">
                      <input type="file" name="documents[]" accept=".pdf,.jpg,.jpeg,.png">
                      <input type="hidden" name="document_type[]" value="certificate_of_indigency">
                      <div class="file-input-label">
                        <i class="bi bi-cloud-upload"></i>
                        <span>Choose file or drag and drop</span>
                      </div>
                    </div>
                    <div class="file-preview"></div>
                  <?php endif; ?>
                </div>
              </div>

              <!-- Submit Section -->
              <div class="submit-section">
                <?php 
                $has_id_picture = $has_id_any;
                $has_grades = $has_grades_any;
                $has_eaf = $has_eaf_any;
                $has_letter = $has_letter_any;
                $has_certificate = $has_certificate_any;
                $can_submit = true; // Always allow submission if not all documents are uploaded
                ?>
                
                <?php if ($uploads_locked): ?>
                  <!-- Uploads are locked -->
                  <div class="alert alert-info">
                    <i class="bi bi-lock-fill me-2"></i>
                    <strong>Documents Confirmed</strong><br>
                    Your documents were confirmed on <?= date('F d, Y \a\t g:i A', strtotime($uploads_confirmed_at)) ?>.<br>
                    You can no longer modify your uploads. If you need to make changes, please contact the admin.
                  </div>
                <?php elseif ($has_id_picture && $has_grades && $has_eaf && $has_letter && $has_certificate): ?>
                  <!-- All documents uploaded, show confirmation options -->
                  <div class="alert alert-warning mb-3">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <strong>Important:</strong> Please review all your uploaded documents carefully. Once you confirm, you will not be able to resubmit or modify your documents without admin assistance.
                  </div>
                  <button type="submit" name="confirm_uploads" value="1" class="submit-btn btn-success" onclick="return confirm('Are you sure you want to confirm all uploads? You will NOT be able to modify your documents after this.');">
                    <i class="bi bi-check-circle-fill me-2"></i>
                    Confirm All Uploads & Lock Submission
                  </button>
                  <p class="mt-2 mb-0 text-muted small text-center">
                    <i class="bi bi-info-circle me-1"></i>
                    After confirmation, only administrators can reset your uploads if changes are needed.
                  </p>
                  <div class="alert alert-success mt-3">
                    <i class="bi bi-check-circle-fill me-2"></i>
                    <strong>All documents submitted!</strong> You can still resubmit individual documents using the buttons above before confirming.
                  </div>
                <?php else: ?>
                  <button type="submit" class="submit-btn" id="submit-documents">
                      <i class="bi bi-cloud-upload me-2"></i>
                      <?php 
                        $missing = [];
                        if (!$has_id_picture) $missing[] = 'ID Picture';
                        if (!$has_grades) $missing[] = 'Academic Grades';
                        if (!$has_eaf) $missing[] = 'EAF';
                        if (!$has_letter) $missing[] = 'Letter to the Mayor';
                        if (!$has_certificate) $missing[] = 'Certificate of Indigency';
                        if (count($missing) === 0) {
                          echo 'Submit Documents';
                        } elseif (count($missing) === 1) {
                          echo 'Submit ' . htmlspecialchars($missing[0]);
                        } else {
                          echo 'Submit Missing Documents';
                        }
                      ?>
                  </button>
                  <p class="mt-2 mb-0 text-muted small">
                      <?php 
                        if (!empty($missing)) {
                          echo 'Missing: ' . htmlspecialchars(implode(', ', $missing)) . '.';
                        } else {
                          echo 'Please upload all required documents before confirming.';
                        }
                      ?>
                  </p>
                <?php endif; ?>
              </div>
            </form>
            <?php endif; // End uploads_locked check ?>
          <?php endif; ?>
        </div>
      </div>
    </section>
  </div>

  <!-- Enhanced Modal -->
  <div class="modal fade" id="previewModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="previewModalLabel">
            <i class="bi bi-eye-fill me-2"></i>
            Document Preview
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body" id="previewContent">
          <div class="text-center">
            <div class="spinner-border text-primary" role="status">
              <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-3 text-muted">Loading preview...</p>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script src="../../assets/js/bootstrap.bundle.min.js"></script>
  <script src="../../assets/js/student/sidebar.js"></script>
  <script src="../../assets/js/student/upload.js"></script>
  
  <script>
    // CRITICAL DEBUG: Check if JavaScript is loading
    console.log('============================================');
    console.log('UPLOAD_DOCUMENT.PHP: JavaScript file loaded!');
    console.log('Timestamp:', new Date().toISOString());
    console.log('⚠️ DEBUGGING MODE ACTIVE - Check console carefully!');
    console.log('============================================');
    
    // Initialize upload manager when page loads
    document.addEventListener('DOMContentLoaded', function() {
        console.log('============================================');
        console.log('DOMContentLoaded event fired!');
        console.log('Checking form elements...');
        
        const form = document.getElementById('uploadForm');
        const gradesInput = document.getElementById('grades_input');
        const submitBtn = document.getElementById('submit-documents');
        
        console.log('Form found:', !!form);
        console.log('Grades input found:', !!gradesInput);
        console.log('Submit button found:', !!submitBtn);
        
        if (form) {
            console.log('Form ID:', form.id);
            console.log('Form action:', form.action);
            console.log('Form method:', form.method);
            console.log('Form enctype:', form.enctype);
        }
        
        if (gradesInput) {
            console.log('Grades input ID:', gradesInput.id);
            console.log('Grades input name:', gradesInput.name);
            console.log('Grades input type:', gradesInput.type);
            console.log('Grades input is inside form:', form && form.contains(gradesInput));
        }
        console.log('============================================');
        
        // Add click handler to submit button for debugging
        if (submitBtn) {
            console.log('★★★ ATTACHING CLICK HANDLER TO SUBMIT BUTTON ★★★');
            submitBtn.addEventListener('click', function(e) {
                console.log('╔════════════════════════════════════════╗');
                console.log('║   SUBMIT BUTTON CLICKED!!!            ║');
                console.log('╚════════════════════════════════════════╝');
                console.log('Button element:', this);
                console.log('Button type:', this.type);
                console.log('Button disabled:', this.disabled);
                console.log('Event defaultPrevented:', e.defaultPrevented);
                
                // Check grades file at the moment of click
                if (gradesInput) {
                    console.log('Grades files at click time:', gradesInput.files.length);
                    if (gradesInput.files.length > 0) {
                        console.log('Grades file name:', gradesInput.files[0].name);
                    }
                }
                
                // CRITICAL DEBUG: Check if something is preventing submission
                console.log('★★★ DIAGNOSING SUBMISSION BLOCK ★★★');
                console.log('Button form attribute:', this.form ? 'has form' : 'NO FORM');
                console.log('Form ID:', form ? form.id : 'NO FORM');
                console.log('Button is inside form DOM:', form && form.contains(this));
                
                // Check if there are any validation attributes preventing submission
                console.log('Form noValidate:', form ? form.noValidate : 'N/A');
                console.log('Button formNoValidate:', this.formNoValidate);
                
                // CRITICAL FIX: If submit event doesn't fire within 50ms, FORCE it
                let submitFired = false;
                window.addEventListener('submit', function tempListener(e) {
                    if (e.target === form) {
                        submitFired = true;
                        console.log('✅ Submit event DID fire!');
                        window.removeEventListener('submit', tempListener);
                    }
                }, { once: true, capture: true });
                
                setTimeout(function() {
                    if (!submitFired) {
                        console.error('❌ CRITICAL: Submit event did NOT fire after button click!');
                        console.error('This means the button click is not triggering form submission.');
                        console.error('Attempting to manually submit form...');
                        if (form && confirm('DEBUG: Form did not submit automatically. Force submit now?')) {
                            form.submit();
                        }
                    }
                }, 50);
            });
        }
        
        // The upload.js file automatically handles the grades file preview
        // since it binds to all .upload-form-item elements
        console.log('Upload manager initialized for all file inputs');
        
        // Initialize document view functionality
        initializeDocumentViewer();

        // Failsafe: robust submit fallback if any handler blocks
        try {
          const form = document.getElementById('uploadForm');
          const submitBtn = document.getElementById('submit-documents');
          if (form && submitBtn) {
            window.__uploadSubmitting = false;
            form.addEventListener('submit', function() {
              window.__uploadSubmitting = true;
            });
            submitBtn.addEventListener('click', function() {
              // If submit event didn't fire within a short window, force submit
              setTimeout(function() {
                if (!window.__uploadSubmitting) {
                  form.submit();
                }
              }, 150);
            });
          }

          // Failsafe for grades resubmit form
          const gradesForm = document.getElementById('gradesResubmitForm');
          if (gradesForm) {
            let gradesSubmitting = false;
            gradesForm.addEventListener('submit', function() { gradesSubmitting = true; });
            const gradesBtn = gradesForm.querySelector('button[type="submit"]');
            if (gradesBtn) {
              gradesBtn.addEventListener('click', function() {
                setTimeout(function(){ if (!gradesSubmitting) { gradesForm.submit(); } }, 150);
              });
            }
          }

          // Failsafe for EAF resubmit form
          const eafForm = document.querySelector('#resubmit_eaf form');
          if (eafForm) {
            let eafSubmitting = false;
            eafForm.addEventListener('submit', function() { eafSubmitting = true; });
            const eafBtn = eafForm.querySelector('button[type="submit"]');
            if (eafBtn) {
              eafBtn.addEventListener('click', function() {
                setTimeout(function(){ if (!eafSubmitting) { eafForm.submit(); } }, 150);
              });
            }
          }
        } catch (e) { console.warn('Submit fallback init failed', e); }
    });
    
    // Document viewing functionality
    function viewDocument(filePath, title) {
      const modal = new bootstrap.Modal(document.getElementById('previewModal'));
      const modalTitle = document.getElementById('previewModalLabel');
      const previewContent = document.getElementById('previewContent');
      
      modalTitle.innerHTML = '<i class="bi bi-eye-fill me-2"></i>' + title;
      
      const fileExt = filePath.split('.').pop().toLowerCase();
      
      if (['jpg', 'jpeg', 'png', 'gif'].includes(fileExt)) {
        previewContent.innerHTML = `
          <div class="text-center">
            <img src="${filePath}" class="img-fluid" alt="${title}" style="max-height: 70vh; border-radius: 8px;">
          </div>
        `;
      } else if (fileExt === 'pdf') {
        previewContent.innerHTML = `
          <div class="text-center">
            <iframe src="${filePath}" width="100%" height="500px" style="border: none; border-radius: 8px;"></iframe>
            <p class="mt-2"><a href="${filePath}" target="_blank" class="btn btn-outline-primary">Open in new tab</a></p>
          </div>
        `;
      } else {
        previewContent.innerHTML = `
          <div class="text-center">
            <i class="bi bi-file-earmark-text" style="font-size: 4rem; color: #6c757d;"></i>
            <h5 class="mt-3">Document Preview</h5>
            <p class="text-muted">Preview not available for this file type.</p>
            <a href="${filePath}" target="_blank" class="btn btn-primary">Download File</a>
          </div>
        `;
      }
      
      modal.show();
    }
    
    // Resubmit functionality
    function enableResubmit(documentType) {
      console.log('enableResubmit called for:', documentType);
      
      const uploadedCard = document.querySelector(`[data-document="${documentType}"] .uploaded-document-card`);
      const resubmitForm = document.querySelector(`#resubmit_${documentType}`);
      
      console.log('uploadedCard found:', !!uploadedCard);
      console.log('resubmitForm found:', !!resubmitForm);
      
      if (uploadedCard && resubmitForm) {
        uploadedCard.style.display = 'none';
        resubmitForm.style.display = 'block';
        console.log('Resubmit form shown for:', documentType);
        
        // Initialize the form after it's shown if it's grades
        if (documentType === 'grades') {
          // Wait a moment for the form to be visible, then initialize
          setTimeout(() => {
            initializeGradesResubmitForm();
          }, 100);
        }
      } else {
        console.error('Could not find elements for resubmit:', documentType);
      }
    }
    
    function cancelResubmit(documentType) {
      const uploadedCard = document.querySelector(`[data-document="${documentType}"] .uploaded-document-card`);
      const resubmitForm = document.querySelector(`#resubmit_${documentType}`);
      
      if (uploadedCard && resubmitForm) {
        uploadedCard.style.display = 'block';
        resubmitForm.style.display = 'none';
        
        // Clear file input - handle both regular and resubmit inputs
        const fileInput = resubmitForm.querySelector('input[type="file"]');
        if (fileInput) {
          fileInput.value = '';
        }
        
        // Clear preview if exists
        const preview = resubmitForm.querySelector('.file-preview');
        if (preview) {
          preview.innerHTML = '';
        }
        
        // Reset submit button if it exists
        const submitBtn = resubmitForm.querySelector('button[type="submit"]');
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.innerHTML = 'Upload New Grades';
        }
      }
    }
    
    // Handle grades resubmit form
    function initializeGradesResubmitForm() {
      const gradesForm = document.getElementById('gradesResubmitForm');
      if (gradesForm) {
        console.log('Grades resubmit form found, adding event listener');
        
        // Remove any existing listeners to prevent duplicates
        gradesForm.removeEventListener('submit', gradesFormSubmitHandler);
        gradesForm.addEventListener('submit', gradesFormSubmitHandler);
      } else {
        console.log('Grades resubmit form NOT found - this is normal if not in resubmit mode');
      }
    }
    
    // Separate function for the grades form submit handler
    function gradesFormSubmitHandler(e) {
      console.log('Grades resubmit form submitted');
      
      const fileInput = document.getElementById('grades_resubmit_input');
      if (!fileInput || !fileInput.files.length) {
        e.preventDefault();
        console.log('No file selected');
        showToast('Please select a grades file first', 'error');
        return;
      }
      
      console.log('File selected:', fileInput.files[0].name);
      
      // Show loading state
      const gradesForm = document.getElementById('gradesResubmitForm');
      const submitBtn = gradesForm.querySelector('button[type="submit"]');
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Uploading...';
      }
      
      // Show processing indicator
      const processingDiv = document.getElementById('grades_processing');
      if (processingDiv) {
        processingDiv.style.display = 'block';
      }
      
      // Form will submit normally to PHP
    }
    
    // Toast notification system
    function showToast(message, type = 'info') {
      // Create toast element
      const toast = document.createElement('div');
      toast.className = `alert alert-${type === 'error' ? 'danger' : (type === 'success' ? 'success' : 'info')} position-fixed`;
      toast.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
      toast.innerHTML = `
        <div class="d-flex align-items-center">
          <i class="bi bi-${type === 'error' ? 'exclamation-triangle' : (type === 'success' ? 'check-circle' : 'info-circle')} me-2"></i>
          ${message}
          <button type="button" class="btn-close ms-auto" onclick="this.parentElement.parentElement.remove()"></button>
        </div>
      `;
      
      document.body.appendChild(toast);
      
      // Auto-remove after 5 seconds
      setTimeout(() => {
        if (toast.parentNode) {
          toast.remove();
        }
      }, 5000);
    }
    
    function initializeDocumentViewer() {
      // Add click handlers for existing documents
      console.log('Document viewer initialized');
      
      // Don't initialize grades resubmit form on page load - it's hidden
      // It will be initialized when the resubmit button is clicked
      
      // Add form submission handler to show loading state
      const form = document.getElementById('uploadForm');
      if (form) {
        console.log('★★★ ATTACHING SUBMIT HANDLER TO FORM ★★★');
        
        // CRITICAL: Add submit handler as CAPTURING (runs first!)
        form.addEventListener('submit', function(e) {
          console.log('╔════════════════════════════════════════╗');
          console.log('║   FORM SUBMIT EVENT TRIGGERED!!!      ║');
          console.log('╚════════════════════════════════════════╝');
          console.log('Event object:', e);
          console.log('Form being submitted:', this);
          console.log('Event defaultPrevented:', e.defaultPrevented);
          console.log('Form action:', this.action);
          console.log('Form method:', this.method);
          
          // SUPER VISIBLE DEBUG - Show alert
          alert('🔍 DEBUG: Form submit handler triggered!\ndefaultPrevented: ' + e.defaultPrevented);
          
          // Check what files are selected
          const gradesInput = document.getElementById('grades_input');
          const documentInputs = Array.from(document.querySelectorAll('input[name="documents[]"]'));
          const eafInput = document.getElementById('eaf_input');
          
          console.log('Grades input found:', !!gradesInput);
          console.log('Grades files selected:', gradesInput ? gradesInput.files.length : 0);
          
          if (gradesInput && gradesInput.files.length > 0) {
            console.log('★★★ GRADES FILE DETAILS ★★★');
            console.log('File name:', gradesInput.files[0].name);
            console.log('File size:', gradesInput.files[0].size);
            console.log('File type:', gradesInput.files[0].type);
          }
          console.log('Document inputs found:', documentInputs.length);
          const docsSelectedCount = documentInputs.reduce((acc, inp) => acc + (inp.files ? inp.files.length : 0), 0);
          console.log('Documents files selected total:', docsSelectedCount);
          console.log('EAF input found:', !!eafInput);
          console.log('EAF files selected:', eafInput ? eafInput.files.length : 0);
          
          // Check if any file is selected
          const hasGrades = gradesInput && gradesInput.files.length > 0;
          const hasDocuments = docsSelectedCount > 0;
          const hasEaf = eafInput && eafInput.files.length > 0;
          
          console.log('Form validation - hasGrades:', hasGrades, 'hasDocuments:', hasDocuments, 'hasEaf:', hasEaf);
          
          // Allow submitting if at least one file is selected
          if (!hasGrades && !hasDocuments && !hasEaf) {
            console.log('No files selected - preventing submission');
            e.preventDefault();
            alert('Please select at least one document (ID Picture, Academic Grades, or EAF) to submit.');
            return false;
          }
          
          console.log('Form validation passed, submitting...');
          
          const submitBtn = document.getElementById('submit-documents');
          if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Uploading...';
          }
          
          // Show processing for grades if grades file is selected
          if (hasGrades) {
            const processingDiv = document.getElementById('grades_processing');
            if (processingDiv) {
              processingDiv.style.display = 'block';
            }
          }
        });
      }
    }
  </script>
</body>
</html>