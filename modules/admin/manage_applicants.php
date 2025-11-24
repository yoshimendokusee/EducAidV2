<?php
require_once __DIR__ . '/../../includes/CSRFProtection.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['admin_username'])) {
    header("Location: ../../unified_login.php");
    exit;
}
include __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/FilePathConfig.php';
require_once __DIR__ . '/../../includes/student_notification_helper.php';

// Get workflow permissions to control approval actions
require_once __DIR__ . '/../../includes/workflow_control.php';
$workflow_status = getWorkflowStatus($connection);

require_once __DIR__ . '/../../phpmailer/vendor/autoload.php';
require_once __DIR__ . '/../../includes/util/student_id.php';

// Lightweight API for sidebar: return pending applicants count as JSON
if (isset($_GET['api']) && $_GET['api'] === 'badge_count') {
    header('Content-Type: application/json');
    $countRes = @pg_query($connection, "SELECT COUNT(*) FROM students WHERE status = 'applicant' AND (is_archived IS NULL OR is_archived = FALSE)");
    $count = 0;
    if ($countRes) {
        $count = (int) pg_fetch_result($countRes, 0, 0);
        pg_free_result($countRes);
    }
    echo json_encode(['count' => $count]);
    exit;
}

// Initialize FilePathConfig for Railway/Localhost compatibility
$pathConfig = FilePathConfig::getInstance();

// Resolve current admin's municipality context
$adminMunicipalityId = null;
$adminMunicipalityName = '';
$adminId = $_SESSION['admin_id'] ?? null;
$adminUsername = $_SESSION['admin_username'] ?? null;
if ($adminId) {
    $admRes = pg_query_params($connection, "SELECT a.municipality_id, a.role, COALESCE(m.name,'') AS municipality_name FROM admins a LEFT JOIN municipalities m ON m.municipality_id = a.municipality_id WHERE a.admin_id = $1 LIMIT 1", [$adminId]);
} elseif ($adminUsername) {
    // Fallback to username if admin_id is not available in session
    $admRes = pg_query_params($connection, "SELECT a.municipality_id, a.role, COALESCE(m.name,'') AS municipality_name FROM admins a LEFT JOIN municipalities m ON m.municipality_id = a.municipality_id WHERE a.username = $1 LIMIT 1", [$adminUsername]);
} else {
    $admRes = false;
}
if ($admRes && pg_num_rows($admRes)) {
    $admRow = pg_fetch_assoc($admRes);
    $adminMunicipalityId = $admRow['municipality_id'] ? intval($admRow['municipality_id']) : null;
    $adminMunicipalityName = $admRow['municipality_name'] ?? '';
    if (empty($_SESSION['admin_role']) && !empty($admRow['role'])) {
        $_SESSION['admin_role'] = $admRow['role'];
    }
}

// --- Migration helpers ---
function rand_password_12() {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $pwd = '';
    for ($i=0; $i<12; $i++) $pwd .= $chars[random_int(0, strlen($chars)-1)];
    return $pwd;
}

function to_bdate_from_age($age) {
    $age = trim((string)$age);
    if ($age === '') return null;
    // If looks like a date string
    if (preg_match('/\\d{4}-\\d{2}-\\d{2}|\\d{1,2}[\\/\\-]\\d{1,2}[\\/\\-]\\d{2,4}/', $age)) {
        $ts = strtotime($age);
        return $ts ? date('Y-m-d', $ts) : null;
    }
    // If looks like Excel serial
    if (ctype_digit($age) && (int)$age > 20000 && (int)$age < 60000) {
        $base = (int)$age;
        $unix = ($base - 25569) * 86400; // Excel to Unix
        return date('Y-m-d', $unix);
    }
    // If numeric years
    if (is_numeric($age)) {
        $years = (int)$age;
        if ($years < 5 || $years > 100) return null;
        $y = (int)date('Y') - $years;
        return sprintf('%04d-06-15', $y); // mid-year default
    }
    return null;
}

function map_gender($g) {
    $g = strtolower(trim((string)$g));
    if (in_array($g, ['m','male'])) return 'Male';
    if (in_array($g, ['f','female'])) return 'Female';
    return null;
}

function normalize_str($s) { return strtolower(trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9 ]/i',' ', (string)$s)))); }

function find_best_match($needle, $rows, $field) {
    $needleN = normalize_str($needle);
    $best = null; $bestScore = 0;
    foreach ($rows as $r) {
        $val = normalize_str($r[$field] ?? '');
        if ($val === $needleN) return $r; // exact
        similar_text($needleN, $val, $pct);
        if ($pct > $bestScore) { $bestScore = $pct; $best = $r; }
    }
    return $bestScore >= 70 ? $best : null;
}

// Barangay-specific matcher: strips common prefixes (brgy, barangay) and uses a slightly lower threshold
function find_best_barangay($needle, $rows) {
    $needle = preg_replace('/\b(brgy|barangay|bgry|bgy)\b\.?/i', ' ', (string)$needle);
    $needle = normalize_str($needle);
    $best = null; $bestScore = 0;
    foreach ($rows as $r) {
        $val = normalize_str($r['name'] ?? '');
        if ($val === $needle) return $r; // exact after cleanup
        // containments
        if ($val && $needle && (str_contains($val, $needle) || str_contains($needle, $val))) {
            // prefer longer match
            $score = 95 - abs(strlen($val) - strlen($needle));
            if ($score > $bestScore) { $bestScore = $score; $best = $r; }
            continue;
        }
        similar_text($needle, $val, $pct);
        if ($pct > $bestScore) { $bestScore = $pct; $best = $r; }
    }
    return $bestScore >= 60 ? $best : null;
}

function generateUniqueStudentId_admin($connection, $year_level_id) {
    // Use the standardized generator: YYYYMMDD-<yearlevel>-<sequence>
    global $adminMunicipalityId;
    $id = generateSystemStudentId($connection, intval($year_level_id), intval($adminMunicipalityId), intval(date('Y')));
    if ($id) return $id;
    // Fallback (should rarely happen): format MUNICIPALITY-YEAR-YEARLEVEL-SEQ
    $code = '0';
    $res = pg_query_params($connection, "SELECT code FROM year_levels WHERE year_level_id = $1", [intval($year_level_id)]);
    if ($res && pg_num_rows($res)) { $row = pg_fetch_assoc($res); $code = preg_replace('/[^0-9]/','',$row['code'] ?? '0'); if ($code==='') $code='0'; }
    $muniPrefix = 'MUNI' . intval($adminMunicipalityId ?: 0);
    $mr = @pg_query_params($connection, "SELECT COALESCE(NULLIF(slug,''), name) AS tag FROM municipalities WHERE municipality_id = $1", [intval($adminMunicipalityId)]);
    if ($mr && pg_num_rows($mr) > 0) { $mrow = pg_fetch_assoc($mr); $muniPrefix = strtoupper(preg_replace('/[^A-Z0-9]/', '', strtoupper((string)($mrow['tag'] ?? $muniPrefix)))); }
    return $muniPrefix . '-' . date('Y') . '-' . $code . '-' . mt_rand(1, 9999);
}

function send_migration_email($toEmail, $toName, $passwordPlain) {
    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        
        // Server settings - using same configuration as OTPService
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'dilucayaka02@gmail.com';
        $mail->Password   = 'jlld eygl hksj flvg';
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        
        // Recipients
        $mail->setFrom('dilucayaka02@gmail.com', 'EducAid System');
        $mail->addAddress($toEmail, $toName ?: $toEmail);
        $mail->isHTML(true);
        $mail->Subject = 'Welcome to EducAid - Your Account Has Been Created';
        
        $loginUrl = (isset($_SERVER['HTTPS'])?'https':'http') . '://' . $_SERVER['HTTP_HOST'] . '/EducAid/unified_login.php';
        
        $mail->Body = '
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; background-color: #f8f9fa;">
            <div style="background-color: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
                <div style="text-align: center; margin-bottom: 30px;">
                    <h1 style="color: #1182FF; margin: 0; font-size: 28px;">Welcome to EducAid!</h1>
                    <p style="color: #6c757d; margin: 10px 0 0 0;">Your educational assistance account is ready</p>
                </div>
                
                <div style="background-color: #e3f2fd; padding: 20px; border-radius: 6px; margin-bottom: 25px;">
                    <h3 style="color: #1976d2; margin: 0 0 15px 0;">📧 Your Login Credentials</h3>
                    <p style="margin: 8px 0;"><strong>Email:</strong> ' . htmlspecialchars($toEmail) . '</p>
                    <p style="margin: 8px 0;"><strong>Temporary Password:</strong> <code style="background: #fff; padding: 4px 8px; border-radius: 4px; color: #d32f2f; font-weight: bold;">' . htmlspecialchars($passwordPlain) . '</code></p>
                </div>
                
                <div style="background-color: #fff3cd; padding: 20px; border-radius: 6px; margin-bottom: 25px; border-left: 4px solid #ffc107;">
                    <h3 style="color: #856404; margin: 0 0 15px 0;">⚠️ Important Security Notice</h3>
                    <ul style="margin: 0; padding-left: 20px; color: #856404;">
                        <li>Keep your password confidential - never share it with anyone</li>
                        <li>You will need to verify with a One-Time Password (OTP) during your first login</li>
                        <li>Please change your password after your first successful login</li>
                    </ul>
                </div>
                
                <div style="background-color: #d4edda; padding: 20px; border-radius: 6px; margin-bottom: 25px; border-left: 4px solid #28a745;">
                    <h3 style="color: #155724; margin: 0 0 15px 0;">📋 Next Steps - Required Documents</h3>
                    <p style="color: #155724; margin: 0 0 10px 0;">After logging in, please upload these required documents:</p>
                    <ul style="margin: 0; padding-left: 20px; color: #155724;">
                        <li><strong>Educational Assistance Form (EAF)</strong> - Completed and signed</li>
                        <li><strong>Letter to Mayor</strong> - Formal request letter</li>
                        <li><strong>Certificate of Indigency</strong> - From your barangay</li>
                        <li><strong>Academic Grades</strong> - Recent transcript or report card</li>
                    </ul>
                    <p style="color: #155724; margin: 15px 0 0 0; font-style: italic;">Your application will be reviewed once all documents are uploaded.</p>
                </div>
                
                <div style="text-align: center; margin: 30px 0;">
                    <a href="' . htmlspecialchars($loginUrl) . '" style="background-color: #1182FF; color: white; padding: 12px 30px; text-decoration: none; border-radius: 6px; font-weight: bold; display: inline-block;">🔐 Login to EducAid</a>
                </div>
                
                <div style="border-top: 1px solid #dee2e6; padding-top: 20px; margin-top: 30px; text-align: center; color: #6c757d; font-size: 14px;">
                    <p>If you have any questions, please contact your local EducAid administrator.</p>
                    <p style="margin: 5px 0 0 0;">This is an automated message. Please do not reply to this email.</p>
                </div>
            </div>
        </div>';
        
        $mail->AltBody = "Welcome to EducAid!\n\n" .
            "Your account has been created successfully.\n\n" .
            "Login Details:\n" .
            "Email: " . $toEmail . "\n" .
            "Temporary Password: " . $passwordPlain . "\n\n" .
            "IMPORTANT SECURITY NOTICE:\n" .
            "- Keep your password confidential\n" .
            "- You will need OTP verification on first login\n" .
            "- Change your password after first login\n\n" .
            "REQUIRED DOCUMENTS TO UPLOAD:\n" .
            "1. Educational Assistance Form (EAF)\n" .
            "2. Letter to Mayor\n" .
            "3. Certificate of Indigency\n" .
            "4. Academic Grades\n\n" .
            "Login here: " . $loginUrl . "\n\n" .
            "Contact your local EducAid administrator for assistance.";
            
        $mail->send();
        return true;
    } catch (Exception $e) { return false; }
}

// Handle Migration POST actions
$migration_preview = $_SESSION['migration_preview'] ?? null;
$migration_result = $_SESSION['migration_result'] ?? null;

// Check if there's an active distribution (required for migration)
// Check both signup_slots (is_active=TRUE) AND config table (current_academic_year set)
$hasActiveDistribution = false;

// First check signup_slots
$activeDistributionQuery = pg_query($connection, "SELECT COUNT(*) as count FROM signup_slots WHERE is_active = TRUE");
if ($activeDistributionQuery && pg_num_rows($activeDistributionQuery) > 0) {
    $distRow = pg_fetch_assoc($activeDistributionQuery);
    $hasActiveDistribution = intval($distRow['count']) > 0;
}

// If not found in signup_slots, also check config table for current_academic_year
if (!$hasActiveDistribution) {
    $configQuery = pg_query($connection, "SELECT value FROM config WHERE key = 'current_academic_year' AND value IS NOT NULL AND value != ''");
    if ($configQuery && pg_num_rows($configQuery) > 0) {
        $configRow = pg_fetch_assoc($configQuery);
        $hasActiveDistribution = !empty($configRow['value']);
    }
}

// Do NOT generate CSRF token for CSV migration here - it will be fetched via AJAX when modal opens
$csrfMigrationToken = ''; // Will be populated by AJAX fetch

// Generate CSRF tokens for applicant approval flows
// Note: reject_documents token is NOT generated here—it's fetched via AJAX when the modal opens
// to avoid rotation issues from the page auto-refresh mechanism.
$csrfApproveApplicantToken = CSRFProtection::generateToken('approve_applicant');
$csrfArchiveStudentToken = CSRFProtection::generateToken('archive_student');
// For reject_documents, set empty placeholder - the actual token will be fetched via AJAX when modal opens
$csrfRejectDocumentsToken = ''; // Will be populated by AJAX fetch
// Reject applicant token (if needed in future)
$csrfRejectApplicantToken = CSRFProtection::generateToken('reject_applicant');

// Clear migration sessions on GET request to prevent resubmission warnings
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET['clear_migration'])) {
    // If a preview still exists, only clear the result so remaining rows persist
    if (!empty($_SESSION['migration_preview'])) {
        unset($_SESSION['migration_result']);
    } else {
        unset($_SESSION['migration_preview']);
        unset($_SESSION['migration_result']);
    }
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// AUTO-CLEANUP: Clear stale migration preview on normal page load (not POST)
// This prevents the modal from auto-opening on every page visit
if ($_SERVER['REQUEST_METHOD'] === 'GET' && empty($_GET['clear_migration']) && !empty($_SESSION['migration_preview'])) {
    // Check if the preview is from a previous session (older than 5 minutes)
    $previewTime = $_SESSION['migration_preview_time'] ?? 0;
    if ((time() - $previewTime) > 300) { // 5 minutes
        error_log("Auto-clearing stale migration preview (older than 5 minutes)");
        unset($_SESSION['migration_preview']);
        unset($_SESSION['migration_preview_time']);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['migration_action'])) {
    // CSRF Protection - validate token first
    $token = $_POST['csrf_token'] ?? '';
    if (!CSRFProtection::validateToken('csv_migration', $token)) {
        $_SESSION['error'] = 'Security validation failed. Please refresh the page.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    
    if ($_POST['migration_action'] === 'preview' && isset($_FILES['csv_file'])) {
        // CRITICAL: Require an active distribution for migration
        // This ensures migrated students are placed into the current academic year context
        // Check both signup_slots AND config table
        $activeDistributionCheck = pg_query($connection, 
            "SELECT COUNT(*) as count, academic_year FROM signup_slots WHERE is_active = TRUE GROUP BY academic_year"
        );
        $activeDistCount = 0;
        $currentAcademicYear = null;
        if ($activeDistributionCheck && pg_num_rows($activeDistributionCheck) > 0) {
            $distRow = pg_fetch_assoc($activeDistributionCheck);
            $activeDistCount = intval($distRow['count']);
            $currentAcademicYear = $distRow['academic_year'];
        }
        
        // If not found in signup_slots, also check config table
        if ($activeDistCount === 0) {
            $configQuery = pg_query($connection, "SELECT value FROM config WHERE key = 'current_academic_year' AND value IS NOT NULL AND value != ''");
            if ($configQuery && pg_num_rows($configQuery) > 0) {
                $configRow = pg_fetch_assoc($configQuery);
                $currentAcademicYear = $configRow['value'];
                $activeDistCount = 1; // Set to 1 to indicate we found an active academic year
            }
        }
        
        if ($activeDistCount === 0) {
            $_SESSION['migration_result'] = [
                'inserted' => 0,
                'errors' => [
                    'Migration blocked: No active distribution is currently open.',
                    'Old students can only be migrated when there is an active distribution.',
                    'This ensures they are properly placed into the current academic year context.',
                    'Please activate a distribution slot before migrating old student records.'
                ],
                'status' => 'error'
            ];
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
        $municipality_id = intval($adminMunicipalityId ?? 0);
        if (!$municipality_id) { $municipality_id = intval($_POST['municipality_id'] ?? 0); }
        $csv = $_FILES['csv_file'];
        if ($csv['error'] === UPLOAD_ERR_OK) {
            $rows = [];
            $fh = fopen($csv['tmp_name'], 'r');
            if ($fh) {
                $header = null; $map = [];
                $headerDetected = false;
                $detectedHeaders = [];
                
                // Comprehensive header synonyms mapping - supporting all student table fields
                $syn = [
                    // Basic Name Fields
                    'last_name' => ['lastname','last name','surname','family name','last','apellido'],
                    'first_name'=> ['firstname','first name','given name','first','given','nombre'],
                    'middle_name'=>['middlename','middle name','mi','m.i.','middle','mname','m name','middle initial'],
                    'extension_name'=>['extension','suffix','name extension','ext','name suffix','jr','sr','iii'],
                    
                    // Personal Information
                    'age' => ['age','years','yrs','yr','edad'],
                    'bdate' => ['birthdate','birth date','bday','date of birth','dob','birthday','birth day'],
                    'sex' => ['sex','gender','sexo'],
                    
                    // Contact Information
                    'email'=>['email','e-mail','email address','e mail','correo'],
                    'mobile'=>['mobile','contact number','phone','number','contact','cellphone','cp number','mobile number','phone number','contact no','contact #','cell','cell phone'],
                    
                    // Address Fields
                    'barangay_name'=>['barangay','brgy','bgry','bgy','village','barangay name','baranggay'],
                    
                    // Academic Information
                    'university_name'=>['university','school','college','univ','institution','campus','university name','school name'],
                    'course'=>['course','program','degree','major','course name','program name'],
                    'year_level_name'=>['year level','year','level','yr level','grade','year lvl','grade level'],
                    'school_student_id'=>['school id','student id','school student id','student number','id number','id no','school number'],
                    
                    // Scholarship Information
                    'application_date'=>['application date','date applied','apply date','registration date','reg date'],
                    'first_registered_academic_year'=>['first registered','first year','initial year','first academic year','year registered'],
                    'previous_academic_year'=>['previous academic year','last academic year','previous year','last year','ay before'],
                    'previous_year_level'=>['previous year level','last year level','previous level','last level','old year level'],
                    
                    // Parent/Guardian Information
                    'mothers_maiden_name'=>['mothers maiden name','mother maiden name','mothers maiden','maiden name','mother\'s maiden name'],
                ];
                
                $normalizeHeader = function($s){ 
                    return strtolower(trim(preg_replace('/\s+|\_|\-/',' ', (string)$s))); 
                };
                
                $recognize = function($label) use ($syn,$normalizeHeader){
                    $l = $normalizeHeader($label);
                    foreach ($syn as $key => $arr) {
                        foreach ($arr as $cand) { 
                            if ($l === $normalizeHeader($cand)) return $key; 
                        }
                    }
                    return null;
                };

                $rowIndex = 0;
                $detectedHeaders = [];
                
                while (($data = fgetcsv($fh)) !== false) {
                    $rowIndex++;
                    
                    // FIRST ROW: MUST be header - detect column mappings
                    if ($rowIndex === 1) {
                        $hdr = [];
                        $recognizedCount = 0;
                        
                        foreach ($data as $i => $col) {
                            $key = $recognize($col);
                            if ($key) { 
                                $hdr[$key] = $i; 
                                $recognizedCount++;
                                $detectedHeaders[] = $col;
                            }
                        }
                        
                        // Require at least 5 recognized columns to consider it a valid header
                        if ($recognizedCount >= 5) {
                            $header = $data; 
                            $map = $hdr; 
                            $headerDetected = true;
                            continue; // Skip to next row (actual data)
                        } else {
                            // Not enough headers detected - show error
                            $_SESSION['migration_result'] = [
                                'inserted'=>0, 
                                'errors'=>['CSV must have a header row. Only ' . $recognizedCount . ' columns were recognized. Please ensure the first row contains column headers like: Last Name, First Name, Email, Mobile, etc.'], 
                                'status'=>'error'
                            ];
                            fclose($fh);
                            header('Location: ' . $_SERVER['PHP_SELF']);
                            exit;
                        }
                    }

                    // Skip empty rows
                    if (empty(array_filter($data))) continue;

                    // Build row using header map
                    $get = function($key) use ($map,$data){ 
                        return isset($map[$key]) ? trim((string)($data[$map[$key]] ?? '')) : ''; 
                    };
                    
                    // Extract all possible fields from CSV
                    $last = $get('last_name');
                    $first = $get('first_name');
                    $mid = $get('middle_name');
                    $ext = $get('extension_name');
                    $ageVal = $get('age');
                    $bdateVal = $get('bdate');
                    $gender = $get('sex');
                    
                    // Contact
                    $email = trim($get('email'));
                    $mobile = preg_replace('/[^0-9]/','', $get('mobile'));
                    
                    // Address
                    $barangayName = $get('barangay_name');
                    
                    // Academic
                    $universityName = $get('university_name');
                    $course = $get('course');
                    $yearLevelName = $get('year_level_name');
                    $schoolStudentId = $get('school_student_id');
                    
                    // Scholarship
                    $applicationDate = $get('application_date');
                    $firstRegisteredYear = $get('first_registered_academic_year');
                    $previousAcademicYear = $get('previous_academic_year');
                    $previousYearLevel = $get('previous_year_level');
                    
                    // Parents/Guardian
                    $mothersMaidenName = $get('mothers_maiden_name');

                    // Normalize mobile number
                    if (strlen($mobile) === 10) $mobile = '0' . $mobile;
                    
                    // Parse birthdate (try bdate first, then age)
                    $bdate = $bdateVal ? to_bdate_from_age($bdateVal) : to_bdate_from_age($ageVal);
                    
                    // Normalize gender
                    $sex = map_gender($gender);

                    $rows[] = [
                        // Basic info
                        'first_name'=>$first, 
                        'middle_name'=>$mid, 
                        'last_name'=>$last, 
                        'extension_name'=>$ext,
                        'bdate'=>$bdate, 
                        'sex'=>$sex,
                        
                        // Contact
                        'email'=>$email, 
                        'mobile'=>$mobile,
                        
                        // Address
                        'barangay_name'=>$barangayName,
                        
                        // Academic
                        'university_name'=>$universityName,
                        'course'=>$course,
                        'year_level_name'=>$yearLevelName,
                        'school_student_id'=>$schoolStudentId,
                        
                        // Scholarship
                        'application_date'=>$applicationDate,
                        'first_registered_academic_year'=>$firstRegisteredYear,
                        'previous_academic_year'=>$previousAcademicYear,
                        'previous_year_level'=>$previousYearLevel,
                        
                        // Parents
                        'mothers_maiden_name'=>$mothersMaidenName,
                        
                        'municipality_id'=>$municipality_id,
                        'include'=>true,
                    ];
                }
                fclose($fh);
            }

            // Prefetch mapping tables
            $universities = pg_fetch_all(pg_query($connection, "SELECT university_id, name, COALESCE(code,'') code FROM universities")) ?: [];
            $yearLevels = pg_fetch_all(pg_query($connection, "SELECT year_level_id, name, COALESCE(code,'') code FROM year_levels")) ?: [];
            $barangays = $municipality_id ? (pg_fetch_all(pg_query_params($connection, "SELECT barangay_id, name FROM barangays WHERE municipality_id = $1", [$municipality_id])) ?: []) : [];

            // Attempt mappings and generate preview
            $preview = [];
            foreach ($rows as $r) {
                $uni = find_best_match($r['university_name'], $universities, 'name');
                if (!$uni) $uni = find_best_match($r['university_name'], $universities, 'code');
                $yl = find_best_match($r['year_level_name'], $yearLevels, 'name');
                if (!$yl) $yl = find_best_match($r['year_level_name'], $yearLevels, 'code');
                $brgy = $barangays ? find_best_barangay($r['barangay_name'], $barangays) : null;

                $conflicts = [];
                
                // Required field validation
                if (empty($r['last_name'])) $conflicts[] = 'Last name is required';
                if (empty($r['first_name'])) $conflicts[] = 'First name is required';
                if (!$r['bdate']) $conflicts[] = 'Birthdate missing/invalid';
                if (!$r['sex']) $conflicts[] = 'Gender unknown';
                // Note: University and Year Level are now entered by student on first login
                if (!$brgy) $conflicts[] = 'Barangay not found: ' . htmlspecialchars($r['barangay_name']);
                if (!filter_var($r['email'], FILTER_VALIDATE_EMAIL)) $conflicts[] = 'Invalid email format';
                if (empty($r['mobile'])) $conflicts[] = 'Mobile number is required';
                
                // NOTE: Previous academic year and year level are optional - students will provide on first login
                // (These fields may not exist in the database schema yet)
                
                // Duplicate checks
                if (!empty($r['email'])) {
                    $dupEmail = pg_fetch_assoc(pg_query_params($connection, "SELECT student_id, CONCAT(first_name, ' ', last_name) as name FROM students WHERE email = $1 AND status != 'archived'", [$r['email']]));
                    if ($dupEmail) $conflicts[] = 'Email already exists (Student: ' . htmlspecialchars($dupEmail['name']) . ')';
                }
                if (!empty($r['mobile'])) {
                    $dupMobile = pg_fetch_assoc(pg_query_params($connection, "SELECT student_id, CONCAT(first_name, ' ', last_name) as name FROM students WHERE mobile = $1 AND status != 'archived'", [$r['mobile']]));
                    if ($dupMobile) $conflicts[] = 'Mobile already exists (Student: ' . htmlspecialchars($dupMobile['name']) . ')';
                }

                $preview[] = [
                    'row'=>$r,
                    'university'=>$uni, 
                    'year_level'=>$yl, 
                    'barangay'=>$brgy,
                    'conflicts'=>$conflicts,
                ];
            }
            
            $_SESSION['migration_preview'] = [
                'municipality_id'=>$municipality_id, 
                'rows'=>$preview,
                'detected_headers'=>$detectedHeaders ?? [],
                'academic_year'=>$currentAcademicYear // Store for use during confirm action
            ];
            $_SESSION['migration_preview_time'] = time(); // Timestamp for auto-cleanup
            
            // Redirect to avoid form re-submission warnings (PRG pattern)
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        } else {
            $_SESSION['migration_result'] = [
                'inserted'=>0, 
                'errors'=>['File upload failed. Please try again.'], 
                'status'=>'error'
            ];
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
    } // end preview action
    if ($_POST['migration_action'] === 'confirm') {
        // CRITICAL: Double-check that an active distribution exists before confirming
        // Check both signup_slots AND config table
        $activeDistributionCheck = pg_query($connection, 
            "SELECT COUNT(*) as count FROM signup_slots WHERE is_active = TRUE"
        );
        $activeDistCount = 0;
        if ($activeDistributionCheck) {
            $distRow = pg_fetch_assoc($activeDistributionCheck);
            $activeDistCount = intval($distRow['count']);
        }
        
        // If not found in signup_slots, also check config table
        if ($activeDistCount === 0) {
            $configQuery = pg_query($connection, "SELECT value FROM config WHERE key = 'current_academic_year' AND value IS NOT NULL AND value != ''");
            if ($configQuery && pg_num_rows($configQuery) > 0) {
                $activeDistCount = 1; // Set to 1 to indicate we found an active academic year
            }
        }
        
        if ($activeDistCount === 0) {
            $_SESSION['migration_result'] = [
                'inserted' => 0,
                'errors' => [
                    'Migration blocked: No active distribution is currently open.',
                    'The migration was canceled because no distribution is active.',
                    'Please activate a distribution slot before migrating old student records.'
                ],
                'status' => 'error'
            ];
            // Clear preview to prevent retry
            unset($_SESSION['migration_preview']);
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
        
        if (!isset($_SESSION['migration_preview'])) {
            // Preview missing – likely session loss; set a clear result so UI shows error
            $_SESSION['migration_result'] = ['inserted'=>0, 'errors'=>['Migration preview expired or session lost. Please re-upload the CSV and try again.'], 'status'=>'error'];
        } else {
        // Verify admin password before migration
        $adminPassword = trim($_POST['admin_password'] ?? '');
        if (empty($adminPassword)) {
            $_SESSION['migration_result'] = ['inserted'=>0, 'errors'=>['Admin password verification required.'], 'status'=>'error'];
        } else {
            // Verify admin password
            $adminId = $_SESSION['admin_id'] ?? null;
            $adminUsername = $_SESSION['admin_username'] ?? null;
            $passwordValid = false;
            if ($adminId) {
                $adminQuery = pg_query_params($connection, "SELECT password FROM admins WHERE admin_id = $1", [$adminId]);
            } elseif ($adminUsername) {
                $adminQuery = pg_query_params($connection, "SELECT password FROM admins WHERE username = $1", [$adminUsername]);
            } else { $adminQuery = false; }
            if ($adminQuery && pg_num_rows($adminQuery)) {
                $adminData = pg_fetch_assoc($adminQuery);
                $passwordValid = password_verify($adminPassword, $adminData['password']);
            } else {
                if (function_exists('error_log')) { error_log('[MIGRATION] Admin lookup failed. Session admin_id=' . ($_SESSION['admin_id'] ?? 'NULL') . ', admin_username=' . ($_SESSION['admin_username'] ?? 'NULL')); }
            }
            
            if (!$passwordValid) {
                $_SESSION['migration_result'] = ['inserted'=>0, 'errors'=>['Invalid admin password.'], 'status'=>'error'];
            } else {
                $selected = $_POST['select'] ?? [];
                if (empty($selected)) {
                    $_SESSION['migration_result'] = ['inserted'=>0, 'errors'=>['No rows selected for migration.'], 'status'=>'warning'];
                } else {
            $preview = $_SESSION['migration_preview'];
            $municipality_id = intval($preview['municipality_id']);
            $academic_year_for_migration = $preview['academic_year'] ?? null; // Retrieve stored academic year
            $inserted = 0; $errors = [];
            // Debug: log session and selection size
            if (function_exists('error_log')) {
                error_log('[MIGRATION] Confirm started: session ok, selected=' . count($selected) . ', muni=' . $municipality_id . ', academic_year=' . $academic_year_for_migration);
            }
            foreach ($preview['rows'] as $idx => $row) {
                if (!isset($selected[(string)$idx])) continue; // not selected
                $r = $row['row']; $brgy = $row['barangay'];
                
                // Validation: Only barangay is required to map now (university and year level selected by student)
                if (!$r['bdate'] || !$r['sex'] || !$brgy || !filter_var($r['email'], FILTER_VALIDATE_EMAIL)) { 
                    $errors[] = "Row #$idx has unresolved required fields"; 
                    continue; 
                }
                
                // generate password
                $plain = rand_password_12(); $hashed = password_hash($plain, PASSWORD_DEFAULT);
                
                // student id - use a temporary year level for ID generation (will be updated by student)
                // Generate with year_level_id = 1 as placeholder
                $stud_id = generateUniqueStudentId_admin($connection, 1);
                if (!$stud_id) { $errors[] = "Row #$idx could not generate student id"; continue; }
                
                // Prepare application date
                $appDate = !empty($r['application_date']) ? $r['application_date'] : null;
                if ($appDate === null) $appDate = date('Y-m-d');
                
                // NOTE: Migrated students get status='applicant' but with admin_review_required=TRUE
                // This allows them to show as "migrated" in the UI while maintaining workflow compatibility
                // Students will provide academic credentials on first login
                
                // insert with all available fields - STATUS = 'applicant', admin_review_required = TRUE (for "migrated" display)
                // NOTE: university_id and year_level_id are set to NULL - student will select on first login
                // status_academic_year is set to the current academic year from the active distribution
                $insert = pg_query_params($connection, "
                    INSERT INTO students (
                        student_id, municipality_id, 
                        first_name, middle_name, last_name, extension_name, 
                        email, mobile, password, 
                        sex, bdate, 
                        barangay_id, university_id, year_level_id, 
                        course, school_student_id,
                        first_registered_academic_year,
                        mothers_maiden_name,
                        status, status_academic_year, application_date, slot_id,
                        needs_document_upload, admin_review_required
                    ) VALUES (
                        $1, $2, 
                        $3, $4, $5, $6, 
                        $7, $8, $9, 
                        $10, $11, 
                        $12, NULL, NULL,
                        $13, $14,
                        $15,
                        $16,
                        'applicant', $17, $18, NULL,
                        TRUE, TRUE
                    )", [
                    // $1-$2: IDs
                    $stud_id, $municipality_id, 
                    // $3-$6: Name
                    $r['first_name'], $r['middle_name'], $r['last_name'], $r['extension_name'], 
                    // $7-$9: Contact & Password
                    $r['email'], $r['mobile'], $hashed, 
                    // $10-$11: Personal Info
                    $r['sex'], $r['bdate'], 
                    // $12: Barangay (university_id and year_level_id are NULL - student will select)
                    $brgy['barangay_id'],
                    // $13-$14: Academic Details (optional/null - collected on first login)
                    $r['course'] ?: null, null,
                    // $15: First Registered Year
                    $r['first_registered_academic_year'] ?: null,
                    // $16: Mother's Maiden Name (NULL - student will provide on first login)
                    null,
                    // $17: Status Academic Year (from active distribution)
                    $academic_year_for_migration,
                    // $18: Application Date
                    $appDate
                ]);
                
                if ($insert) {
                    $inserted++;
                    send_migration_email($r['email'], $r['first_name'] . ' ' . $r['last_name'], $plain);
                } else {
                    $dbErr = pg_last_error($connection);
                    $errors[] = "Row #$idx DB error: " . $dbErr;
                    if (function_exists('error_log')) { error_log('[MIGRATION] Insert failed for row #' . $idx . ': ' . $dbErr); }
                }
            }
            // Rebuild preview with rows that were NOT selected or that failed
            $remaining = [];
            // Build a set of row indices that failed during insert (from error messages)
            $failedIndices = [];
            foreach ($errors as $er) {
                if (preg_match('/Row\s+#(\d+)/', $er, $m)) {
                    $failedIndices[(string)$m[1]] = true;
                }
            }

            foreach ($preview['rows'] as $idx => $row) {
                $wasSelected = isset($selected[(string)$idx]);
                // Consider a row as failed if it was selected but appears in the failedIndices
                $hadError = $wasSelected && isset($failedIndices[(string)$idx]);
                if (!$wasSelected || $hadError) { $remaining[] = $row; }
            }

            if (!empty($remaining)) {
                $_SESSION['migration_preview'] = ['municipality_id'=>$municipality_id, 'rows'=>$remaining];
            } else {
                unset($_SESSION['migration_preview']);
            }

            $_SESSION['migration_result'] = [
                'inserted'=>$inserted, 
                'errors'=>$errors,
                'status' => $inserted > 0 ? 'success' : 'error'
            ];
            // If client expects JSON (AJAX), respond with the result now and exit
            if (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false) {
                header('Content-Type: application/json');
                echo json_encode($_SESSION['migration_result']);
                // Do not unset here; GET will display and then unset
                exit;
            }
            // Don't redirect after confirm - let the modal show results
            // header('Location: ' . $_SERVER['PHP_SELF']);
            // exit;
                }
            }
        }
        } // close else (preview exists)
        // end else preview exists
    }
    // end confirm action
    if ($_POST['migration_action'] === 'cancel') {
        unset($_SESSION['migration_preview']);
        unset($_SESSION['migration_result']);
        // For XHR cancel, return quickly without rendering
        http_response_code(204);
        exit;
    }
} // end POST migration_action handler
// End migration_action POST handler

// Normalize a string for comparison (letters only, lowercase)
function _normalize_token($s) {
    return preg_replace('/[^a-z]/', '', strtolower($s ?? ''));
}

// Find newest file in a folder that matches both first and last name (case-insensitive)
// NOTE: This function is used by check_documents() to verify document completeness
// Searches permanent storage: student/{doc_type}/{student_id}/ and legacy flat structure
// For modal display, use get_applicant_details.php endpoint instead
function find_student_documents($first_name, $last_name, $student_id = null) {
    global $pathConfig; // Use global FilePathConfig instance
    $server_base = $pathConfig->getStudentPath(); // absolute server path with trailing separator
    $web_base    = 'assets/uploads/student/';     // web path (relative from document root)

    $first = _normalize_token($first_name);
    $last  = _normalize_token($last_name);

    $document_types = [
        'eaf' => 'enrollment_forms',
        'letter_to_mayor' => 'letter_mayor', // Fixed: matches DocumentReuploadService folder name
        'certificate_of_indigency' => 'indigency',
        'grades' => 'grades' // Map to 'grades' key for consistency
    ];

    $found = [];
    foreach ($document_types as $type => $folder) {
        $matches = [];
        
        // NEW STRUCTURE: Search student/{doc_type}/{student_id}/ folders first if student_id is provided
        if ($student_id) {
            $studentDir = $server_base . DIRECTORY_SEPARATOR . $folder . DIRECTORY_SEPARATOR . $student_id . DIRECTORY_SEPARATOR;
            if (is_dir($studentDir)) {
                foreach (glob($studentDir . '*.*') as $file) {
                    // Skip associated files
                    if (preg_match('/\.(ocr\.txt|verify\.json|confidence\.json|tsv)$/i', $file)) continue;
                    $matches[filemtime($file)] = [
                        'path' => $file,
                        'web' => $web_base . $folder . '/' . $student_id . '/' . basename($file)
                    ];
                }
            }
        }
        
        // OLD STRUCTURE: Search flat student/{doc_type}/ folder
        $dir = $server_base . DIRECTORY_SEPARATOR . $folder . DIRECTORY_SEPARATOR;
        if (is_dir($dir)) {
            // Scan all files and pick the newest that contains both name tokens
            foreach (glob($dir . '*.*') as $file) {
                // Skip if it's a subdirectory or associated file
                if (is_dir($file)) continue;
                if (preg_match('/\.(ocr\.txt|verify\.json|confidence\.json|tsv)$/i', $file)) continue;
                
                $base = pathinfo($file, PATHINFO_FILENAME);
                $baseNorm = _normalize_token($base);
                if ($first && $last && strpos($baseNorm, $first) !== false && strpos($baseNorm, $last) !== false) {
                    $matches[filemtime($file)] = [
                        'path' => $file,
                        'web' => $web_base . $folder . '/' . basename($file)
                    ];
                }
            }
        }

        if (!empty($matches)) {
            krsort($matches); // newest first
            $picked = reset($matches);
            $found[$type] = $picked['web'];
        }
    }

    return $found;
}

// Helper to find documents by student_id by first fetching the name
// NOTE: This function is still used by check_documents() to verify document completeness
// For modal display, use get_applicant_details.php endpoint instead
function find_student_documents_by_id($connection, $student_id) {
    $res = pg_query_params($connection, "SELECT first_name, last_name FROM students WHERE student_id = $1", [$student_id]);
    if ($res && pg_num_rows($res)) {
        $row = pg_fetch_assoc($res);
        return find_student_documents($row['first_name'] ?? '', $row['last_name'] ?? '', $student_id);
    }
    return [];
}

// Function to check if all required documents are uploaded
function check_documents($connection, $student_id) {
    // CHECK FOR OCR BYPASS MODE - Always return true (documents complete) during bypass
    if (file_exists(__DIR__ . '/../../config/ocr_bypass_config.php')) {
        require_once __DIR__ . '/../../config/ocr_bypass_config.php';
        if (defined('OCR_BYPASS_ENABLED') && OCR_BYPASS_ENABLED === true) {
            error_log("⚠️ BYPASS MODE: check_documents() returning TRUE for student $student_id - admin can verify without documents");
            return true; // Allow admin to verify even without documents
        }
    }
    
    // Required document type codes: EAF, Letter to Mayor, Certificate of Indigency
    $required_codes = ['00', '02', '03'];
    
    // Check if student needs upload tab (existing student) or uses registration docs (new student)
    // Detect if column exists; if not, default to true (existing flow)
    $colCheck = pg_query($connection, "SELECT 1 FROM information_schema.columns WHERE table_name='students' AND column_name='needs_document_upload'");
    $hasNeedsUploadCol = $colCheck ? (pg_num_rows($colCheck) > 0) : false;
    if ($colCheck) { pg_free_result($colCheck); }

    if ($hasNeedsUploadCol) {
        $student_info_query = pg_query_params($connection, 
            "SELECT needs_document_upload, application_date FROM students WHERE student_id = $1", 
            [$student_id]
        );
        $student_info = $student_info_query ? pg_fetch_assoc($student_info_query) : null;
        // Default to FALSE (new registration) if NULL
        // PostgreSQL returns 'f'/'t' strings, not PHP booleans
        $needs_upload_tab = $student_info ? 
                           ($student_info['needs_document_upload'] === 't' || $student_info['needs_document_upload'] === true) : false;
    } else {
        // Column not present, assume existing students require upload tab
        $student_info_query = pg_query_params($connection, 
            "SELECT application_date FROM students WHERE student_id = $1", 
            [$student_id]
        );
        $student_info = $student_info_query ? pg_fetch_assoc($student_info_query) : null;
        $needs_upload_tab = true;
    }
    
    $uploaded_codes = [];
    
    if ($needs_upload_tab) {
        // Existing student: check documents table for document_type_codes
        // IMPORTANT: Only count documents that are NOT rejected (status='temp' or 'approved')
        $query = pg_query_params($connection, 
            "SELECT document_type_code FROM documents 
             WHERE student_id = $1 
             AND (status IS NULL OR status != 'rejected')", 
            [$student_id]);
        while ($row = pg_fetch_assoc($query)) {
            $uploaded_codes[] = $row['document_type_code'];
        }
        
        // Also check file system - convert document names to codes
        $found_documents = find_student_documents_by_id($connection, $student_id);
        $name_to_code_map = [
            'eaf' => '00',
            'letter_to_mayor' => '02',
            'certificate_of_indigency' => '03',
            'id_picture' => '04',
            'grades' => '01'
        ];
        foreach (array_keys($found_documents) as $doc_name) {
            if (isset($name_to_code_map[$doc_name])) {
                $uploaded_codes[] = $name_to_code_map[$doc_name];
            }
        }
        $uploaded_codes = array_unique($uploaded_codes);
        
        // Check if grades are uploaded via documents table (document_type_code = '01')
        $has_grades = in_array('01', $uploaded_codes);
    } else {
        // New student: check BOTH documents table AND file system
        // After approval, documents are moved to permanent storage and recorded in documents table
        
        // 1. Check documents table first - exclude rejected documents
        $query = pg_query_params($connection, 
            "SELECT document_type_code FROM documents 
             WHERE student_id = $1 
             AND (status IS NULL OR status != 'rejected')", 
            [$student_id]);
        while ($row = pg_fetch_assoc($query)) {
            $uploaded_codes[] = $row['document_type_code'];
        }
        
        // 2. Also check file system (in case documents are only in filesystem)
        $registration_docs = find_student_documents_by_id($connection, $student_id);
        
        // Convert document names to codes
        $name_to_code_map = [
            'eaf' => '00',
            'letter_to_mayor' => '02',
            'certificate_of_indigency' => '03',
            'id_picture' => '04',
            'grades' => '01'
        ];
        foreach (array_keys($registration_docs) as $doc_name) {
            if (isset($name_to_code_map[$doc_name])) {
                $uploaded_codes[] = $name_to_code_map[$doc_name];
            }
        }
        
        // Remove duplicates
        $uploaded_codes = array_unique($uploaded_codes);
        
        // For new registrants, check if they have grades in either old or new structure
        $has_grades = in_array('01', $uploaded_codes) || 
                     file_exists("../../assets/uploads/student/grades/" . $student_id . "/") ||
                     file_exists("../../assets/uploads/student/" . $student_id . "/grades/");
    }
    
    // Check if all required document codes are present
    return count(array_diff($required_codes, $uploaded_codes)) === 0 && $has_grades;
}

// Pagination & Filtering logic
$page = max(1, intval($_GET['page'] ?? $_POST['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;
$sort = $_GET['sort'] ?? $_POST['sort'] ?? 'asc';
$search = trim($_GET['search_surname'] ?? $_POST['search_surname'] ?? '');
$filterYearLevel = $_GET['filter_year_level'] ?? $_POST['filter_year_level'] ?? '';
$filterDocStatus = $_GET['filter_doc_status'] ?? $_POST['filter_doc_status'] ?? '';
$filterType = $_GET['filter_type'] ?? $_POST['filter_type'] ?? '';
$filterBeneficiary = $_GET['filter_beneficiary'] ?? $_POST['filter_beneficiary'] ?? '';

// Exclude archived students from applicants list
$where = "s.status = 'applicant' AND (s.is_archived = FALSE OR s.is_archived IS NULL)";
$params = [];
$paramCount = 0;

if ($search) {
    $paramCount++;
    $where .= " AND s.last_name ILIKE $" . $paramCount;
    $params[] = "%$search%";
}

if ($filterYearLevel) {
    $paramCount++;
    $where .= " AND s.current_year_level = $" . $paramCount;
    $params[] = $filterYearLevel;
}

if ($filterType) {
    if ($filterType === 'new') {
        $where .= " AND (s.admin_review_required IS NULL OR s.admin_review_required = FALSE) AND (s.needs_document_upload IS NULL OR s.needs_document_upload = FALSE)";
    } elseif ($filterType === 'migrated') {
        $where .= " AND s.admin_review_required = TRUE";
    } elseif ($filterType === 're-upload') {
        $where .= " AND s.needs_document_upload = TRUE AND (s.admin_review_required IS NULL OR s.admin_review_required = FALSE)";
    }
}

if ($filterBeneficiary === 'yes') {
    $where .= " AND EXISTS (SELECT 1 FROM distribution_student_records dsr WHERE dsr.student_id = s.student_id)";
} elseif ($filterBeneficiary === 'no') {
    $where .= " AND NOT EXISTS (SELECT 1 FROM distribution_student_records dsr WHERE dsr.student_id = s.student_id)";
}

$countQuery = "SELECT COUNT(*) FROM students s WHERE $where";
$totalApplicants = pg_fetch_assoc(pg_query_params($connection, $countQuery, $params))['count'];
$totalPages = max(1, ceil($totalApplicants / $perPage));

$query = "SELECT s.*, 
    (SELECT COUNT(*) FROM distribution_student_records dsr WHERE dsr.student_id = s.student_id) as distribution_count
    FROM students s WHERE $where ORDER BY s.last_name " . ($sort === 'desc' ? 'DESC' : 'ASC') . " LIMIT $perPage OFFSET $offset";
$applicantsResult = $params ? pg_query_params($connection, $query, $params) : pg_query($connection, $query);

// Apply document completion filter client-side (since it requires checking file system)
$filteredApplicants = [];
while ($row = pg_fetch_assoc($applicantsResult)) {
    $isComplete = check_documents($connection, $row['student_id']);
    
    // Apply document status filter
    if ($filterDocStatus === 'complete' && !$isComplete) {
        continue;
    } elseif ($filterDocStatus === 'incomplete' && $isComplete) {
        continue;
    }
    
    $filteredApplicants[] = $row;
}

// Recalculate pagination for filtered results
if ($filterDocStatus) {
    $totalApplicants = count($filteredApplicants);
    $totalPages = max(1, ceil($totalApplicants / $perPage));
    $filteredApplicants = array_slice($filteredApplicants, $offset, $perPage);
}

// Table rendering function with live preview
function render_table($applicants, $connection) {
    global $csrfApproveApplicantToken, $csrfRejectApplicantToken, $csrfOverrideApplicantToken, $csrfArchiveStudentToken, $csrfRejectDocumentsToken, $workflow_status;
    $canApprove = $workflow_status['can_manage_applicants'] ?? false;
    ob_start();
    ?>
    <table class="table table-bordered align-middle">
        <thead>
            <tr>
                <th>Name</th>
                <th>Contact</th>
                <th>Email</th>
                <th>Year Level</th>
                <th>Graduating</th>
                <th>Type</th>
                <th>Past Beneficiary</th>
                <th>Documents</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody id="applicantsTableBody">
        <?php if (empty($applicants)): ?>
            <tr><td colspan="9" class="text-center no-applicants">No applicants found.</td></tr>
        <?php else: ?>
            <?php foreach ($applicants as $applicant) {
                $student_id = $applicant['student_id'];
                $isComplete = check_documents($connection, $student_id);
                
                // Determine applicant type
                // admin_review_required = TRUE = Migrated student (from CSV import)
                // needs_document_upload = TRUE = Existing student requiring re-upload
                // NULL/FALSE = New registrant (from registration system)
                // PostgreSQL returns 'f'/'t' strings, not PHP booleans
                $is_migrated = isset($applicant['admin_review_required']) ? 
                              ($applicant['admin_review_required'] === 't' || $applicant['admin_review_required'] === true) : false;
                $needs_upload = isset($applicant['needs_document_upload']) ? 
                               ($applicant['needs_document_upload'] === 't' || $applicant['needs_document_upload'] === true) : false;
                
                if ($is_migrated) {
                    $applicant_type = 'migrated';
                    $type_label = 'Migrated';
                    $type_icon = 'download';
                    $type_color = 'bg-purple';
                } elseif ($needs_upload) {
                    $applicant_type = 're-upload';
                    $type_label = 'Re-upload';
                    $type_icon = 'arrow-repeat';
                    $type_color = 'bg-warning';
                } else {
                    $applicant_type = 'new';
                    $type_label = 'New Registration';
                    $type_icon = 'person-plus';
                    $type_color = 'bg-info';
                }
                ?>
                <tr>
                    <td data-label="Name">
                        <?= htmlspecialchars("{$applicant['last_name']}, {$applicant['first_name']} {$applicant['middle_name']}") ?>
                    </td>
                    <td data-label="Contact">
                        <?= htmlspecialchars($applicant['mobile']) ?>
                    </td>
                    <td data-label="Email">
                        <?= htmlspecialchars($applicant['email']) ?>
                    </td>
                    <td data-label="Year Level">
                        <?php if (!empty($applicant['current_year_level'])): ?>
                            <span class="badge bg-primary">
                                <?= htmlspecialchars($applicant['current_year_level']) ?>
                            </span>
                        <?php else: ?>
                            <span class="text-muted small">Not Set</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Graduating">
                        <?php if (isset($applicant['is_graduating'])): ?>
                            <?php if ($applicant['is_graduating'] === 't' || $applicant['is_graduating'] === true): ?>
                                <span class="badge bg-success">
                                    <i class="bi bi-mortarboard-fill"></i> Yes
                                </span>
                            <?php else: ?>
                                <span class="badge bg-secondary">
                                    <i class="bi bi-arrow-repeat"></i> No
                                </span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-muted small">Not Set</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Type">
                        <?php
                        $tooltip = $is_migrated ? 'Migrated student from CSV import - needs to complete profile on first login' : 
                                   ($needs_upload ? 'Existing student required to re-upload documents' : 'New applicant from registration system');
                        ?>
                        <span class="badge <?= $type_color ?> text-white" title="<?= $tooltip ?>">
                            <i class="bi bi-<?= $type_icon ?>"></i> <?= $type_label ?>
                        </span>
                    </td>
                    <td data-label="Past Beneficiary">
                        <?php 
                        $distribution_count = intval($applicant['distribution_count'] ?? 0);
                        if ($distribution_count > 0): ?>
                            <span class="badge bg-success" title="Received aid <?= $distribution_count ?> time<?= $distribution_count > 1 ? 's' : '' ?>">
                                <i class="bi bi-check-circle-fill"></i> Yes (<?= $distribution_count ?>x)
                            </span>
                        <?php else: ?>
                            <span class="badge bg-secondary">
                                <i class="bi bi-x-circle"></i> First Time
                            </span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Documents">
                        <span class="badge <?= $isComplete ? 'badge-success' : 'badge-secondary' ?>">
                            <?= $isComplete ? 'Complete' : 'Incomplete' ?>
                        </span>
                    </td>
                    <td data-label="Action">
                        <button class="btn btn-info btn-sm" data-bs-toggle="modal" data-bs-target="#modal<?= $student_id ?>">
                            <i class="bi bi-eye"></i> View
                        </button>
                        <?php if ($_SESSION['admin_role'] === 'super_admin'): ?>
                        <button class="btn btn-warning btn-sm ms-1" 
                                onclick="showArchiveModal('<?= $student_id ?>', '<?= htmlspecialchars($applicant['first_name'] . ' ' . $applicant['last_name'], ENT_QUOTES) ?>', event)"
                                title="Archive Student">
                            <i class="bi bi-archive"></i>
                        </button>
                        <button class="btn btn-danger btn-sm ms-1" 
                                onclick="showBlacklistModal('<?= $student_id ?>', '<?= htmlspecialchars($applicant['first_name'] . ' ' . $applicant['last_name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($applicant['email'], ENT_QUOTES) ?>', {
                                    barangay: '<?= htmlspecialchars($applicant['barangay'] ?? 'N/A', ENT_QUOTES) ?>',
                                    status: 'Applicant'
                                }, event)"
                                title="Blacklist Student">
                            <i class="bi bi-shield-exclamation"></i>
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <!-- Modal -->
                <div class="modal fade" id="modal<?= $student_id ?>" tabindex="-1">
                    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">
                                    Documents for <?= htmlspecialchars($applicant['first_name']) ?> <?= htmlspecialchars($applicant['last_name']) ?>
                                    <span class="badge <?= $type_color ?> ms-2 text-white" style="font-size: 0.75rem;">
                                        <i class="bi bi-<?= $type_icon ?>"></i> <?= $type_label ?>
                                    </span>
                                </h5>
                                <button class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <!-- Loading placeholder - content will be loaded via AJAX from get_applicant_details.php -->
                                <div class="text-center py-5">
                                    <div class="spinner-border text-primary" role="status">
                                        <span class="visually-hidden">Loading...</span>
                                    </div>
                                    <p class="mt-3 text-muted">Loading documents...</p>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <?php if (!$canApprove): ?>
                                    <div class="alert alert-warning mb-0 w-100">
                                        <i class="bi bi-exclamation-triangle me-2"></i>
                                        <strong>Distribution Not Active:</strong> Please start a distribution first to approve or reject applicants.
                                        <a href="distribution_control.php" class="alert-link">Go to Distribution Control</a>
                                    </div>
                                <?php else: ?>
                                    <!-- Verify button form - disabled when documents are incomplete -->
                                    <form method="POST" class="d-inline verify-form" onsubmit="return confirm('Verify this student?');">
                                        <input type="hidden" name="student_id" value="<?= $student_id ?>">
                                        <input type="hidden" name="mark_verified" value="1">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfApproveApplicantToken) ?>">
                                        <button class="btn btn-success btn-sm" <?= !$isComplete ? 'disabled title="Please ensure all required documents are complete"' : '' ?>>
                                            <i class="bi bi-check-circle me-1"></i> Verify
                                        </button>
                                    </form>
                                    
                                    <?php if (!$isComplete): ?>
                                    <!-- Incomplete documents message - shown when documents are not complete -->
                                    <span class="text-muted incomplete-message ms-2">
                                        <i class="bi bi-exclamation-circle me-1"></i>Incomplete documents
                                    </span>
                                    <?php endif; ?>
                                    
                                    <!-- Reject Documents Button - opens modal for selective rejection -->
                                    <button type="button" class="btn btn-danger btn-sm ms-2" 
                                            onclick="showRejectDocumentsModal('<?= $student_id ?>', '<?= htmlspecialchars($applicant['first_name'] . ' ' . $applicant['last_name'], ENT_QUOTES) ?>')"
                                            title="Reject specific documents with reasons">
                                        <i class="bi bi-x-circle me-1"></i> Reject Documents
                                    </button>
                                <?php endif; ?>
                                
                                <?php if ($_SESSION['admin_role'] === 'super_admin'): ?>
                                <div class="ms-auto">
                                    <button class="btn btn-outline-warning btn-sm me-2" 
                                            onclick="showArchiveModal('<?= $student_id ?>', '<?= htmlspecialchars($applicant['first_name'] . ' ' . $applicant['last_name'], ENT_QUOTES) ?>', event)"
                                            data-bs-dismiss="modal">
                                        <i class="bi bi-archive me-1"></i> Archive Student
                                    </button>
                                    <button class="btn btn-outline-danger btn-sm" 
                                            onclick="showBlacklistModal('<?= $student_id ?>', '<?= htmlspecialchars($applicant['first_name'] . ' ' . $applicant['last_name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($applicant['email'], ENT_QUOTES) ?>', {
                                                barangay: '<?= htmlspecialchars($applicant['barangay'] ?? 'N/A', ENT_QUOTES) ?>',
                                                status: 'Applicant'
                                            }, event)"
                                            data-bs-dismiss="modal">
                                        <i class="bi bi-shield-exclamation me-1"></i> Blacklist Student
                                    </button>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php } ?>
        <?php endif; ?>
        </tbody>
    </table>
    <?php
    return ob_get_clean();
}

// Pagination rendering function
function render_pagination($page, $totalPages) {
    if ($totalPages <= 1) return '';
    ?>
    <nav aria-label="Table pagination" class="mt-3">
        <ul class="pagination justify-content-end">
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="#" data-page="<?= $page-1 ?>">&lt;</a>
            </li>
            <li class="page-item">
                <span class="page-link">
                    Page <input type="number" min="1" max="<?= $totalPages ?>" value="<?= $page ?>" id="manualPage" style="width:55px; text-align:center;" /> of <?= $totalPages ?>
                </span>
            </li>
            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                <a class="page-link" href="#" data-page="<?= $page+1 ?>">&gt;</a>
            </li>
        </ul>
    </nav>
    <?php
}

// Handle verify/reject/archive actions before AJAX or page render
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log("=== POST REQUEST to manage_applicants.php ===");
    error_log("POST keys: " . implode(', ', array_keys($_POST)));
    
    $applicantCsrfAction = null;
    if (!empty($_POST['mark_verified']) && isset($_POST['student_id'])) {
        $applicantCsrfAction = 'approve_applicant';
    } elseif (!empty($_POST['archive_student']) && isset($_POST['student_id'])) {
        $applicantCsrfAction = 'archive_student';
    } elseif (!empty($_POST['reject_documents']) && isset($_POST['student_id'])) {
        $applicantCsrfAction = 'reject_documents';
    } elseif (!empty($_POST['reject_applicant']) && isset($_POST['student_id'])) {
        $applicantCsrfAction = 'reject_applicant';
    }

    if ($applicantCsrfAction !== null) {
        $token = $_POST['csrf_token'] ?? '';

        // Extra diagnostics
        $storedTokens = $_SESSION['csrf_tokens'][$applicantCsrfAction] ?? [];
        error_log("CSRF Validation - Action: $applicantCsrfAction");
        error_log("CSRF Validation - Token received (first 20): " . ($token ? substr($token, 0, 20) : 'MISSING') . "...");
        error_log("CSRF Validation - Stored token count: " . (is_array($storedTokens) ? count($storedTokens) : (empty($storedTokens)?0:1)));
        
        // Log all stored token prefixes for comparison
        if (is_array($storedTokens) && !empty($storedTokens)) {
            $prefixes = array_map(function($t) { return substr($t, 0, 16); }, $storedTokens);
            error_log("CSRF Validation - Stored tokens (first 16): " . implode(', ', $prefixes));
        }
        
        error_log("CSRF Validation - POST keys: " . implode(', ', array_keys($_POST)));

        // For reject_documents and reject_applicant allow re-use (do not consume on first attempt)
        $consume = !in_array($applicantCsrfAction, ['reject_documents','reject_applicant'], true);
        $valid = CSRFProtection::validateToken($applicantCsrfAction, $token, $consume);
        if (!$valid) {
            error_log("CSRF Validation FAILED for action: $applicantCsrfAction | Stored tokens: " . json_encode($storedTokens));
            // Regenerate a fresh token to avoid forcing full page refresh
            if ($applicantCsrfAction === 'reject_documents') {
                $newToken = CSRFProtection::generateToken('reject_documents');
                $_SESSION['pending_csrf_refresh'] = $newToken; // flag for front-end (optional)
                error_log("CSRF Validation - Issued replacement token for reject_documents: " . substr($newToken,0,16));
            } elseif ($applicantCsrfAction === 'reject_applicant') {
                $newToken = CSRFProtection::generateToken('reject_applicant');
                $_SESSION['pending_csrf_refresh_reject_applicant'] = $newToken;
                error_log("CSRF Validation - Issued replacement token for reject_applicant: " . substr($newToken,0,16));
            }
            $_SESSION['error'] = 'Security validation failed. Please retry the action.';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }

        error_log("CSRF Validation PASSED for action: $applicantCsrfAction (consume=" . ($consume?'true':'false') . ")");
    }

    // Verify student
    if (!empty($_POST['mark_verified']) && isset($_POST['student_id'])) {
        // Check if approval is allowed
        if (!$workflow_status['can_manage_applicants']) {
            $_SESSION['error_message'] = "Cannot approve applicants. Please start a distribution first.";
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
        
        $sid = trim($_POST['student_id']); // TEXT student_id
        
        // Get student info (including current status before promotion)
        $studentQuery = pg_query_params(
            $connection,
            "SELECT first_name, last_name, email, status FROM students WHERE student_id = $1",
            [$sid]
        );
        $student = pg_fetch_assoc($studentQuery);
        $previousStatus = $student['status'] ?? null;
        
        /** @phpstan-ignore-next-line */
        pg_query_params($connection, 
            "UPDATE students 
             SET status = 'active',
                 needs_document_upload = FALSE,
                 documents_to_reupload = NULL
             WHERE student_id = $1", 
            [$sid]
        );
        
        // Only perform temp -> permanent move if previous status indicates registration flow.
        // Applicants already have their documents in permanent, so skip to avoid creating flat folder.
        $eligibleForMove = $previousStatus && !in_array($previousStatus, ['applicant','active'], true);
        if ($eligibleForMove) {
            $tempDocsCheck = pg_query_params(
                $connection,
                "SELECT COUNT(*) FROM documents WHERE student_id = $1 AND status = 'temp'",
                [$sid]
            );
            $hasTempDocs = pg_fetch_result($tempDocsCheck, 0, 0) > 0;
            if ($hasTempDocs) {
                require_once __DIR__ . '/../../services/UnifiedFileService.php';
                $fileService = new UnifiedFileService($connection);
                $fileMoveResult = $fileService->moveToPermStorage($sid, $_SESSION['admin_id']);
                if (!$fileMoveResult['success']) {
                    error_log("UnifiedFileService: Error moving files for student $sid: " . implode(', ', $fileMoveResult['errors'] ?? []));
                } else {
                    error_log("UnifiedFileService: Successfully moved temp files to permanent for student $sid (previous status: $previousStatus)");
                }
            } else {
                error_log("UnifiedFileService: Skipping file move for student $sid - no temp documents found (previous status: $previousStatus)");
            }
        } else {
            error_log("UnifiedFileService: Skipping file move for student $sid - previous status '$previousStatus' not eligible (already applicant or active)");
        }
        
        // Add admin notification
        if ($student) {
            $student_name = $student['first_name'] . ' ' . $student['last_name'];
            $notification_msg = "Student promoted to active: " . $student_name . " (ID: " . $sid . ")";
            pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
            
            // Add student notification
            createStudentNotification(
                $connection,
                $sid,
                'Application Approved!',
                'Congratulations! Your application has been verified and approved. You are now an active student.',
                'success',
                'high',
                'student_dashboard.php'
            );
            
            // Log applicant approval in audit trail
            require_once __DIR__ . '/../../services/AuditLogger.php';
            $auditLogger = new AuditLogger($connection);
            $auditLogger->logApplicantApproved(
                $_SESSION['admin_id'],
                $_SESSION['admin_username'],
                $sid,
                [
                    'first_name' => $student['first_name'],
                    'last_name' => $student['last_name'],
                    'email' => $student['email']
                ]
            );
        }
        
        // Redirect to refresh list
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    
    // Archive Student
    if (!empty($_POST['archive_student']) && isset($_POST['student_id'], $_POST['archive_reason'])) {
        $sid = trim($_POST['student_id']);
        $archiveReason = trim($_POST['archive_reason']);
        $archiveOtherReason = trim($_POST['archive_other_reason'] ?? '');
        
        // If reason is "other", use the custom reason text
        if ($archiveReason === 'other' && !empty($archiveOtherReason)) {
            $archiveReason = $archiveOtherReason;
        }
        
        // Get student details for logging
        $studentQuery = pg_query_params($connection, 
            "SELECT first_name, last_name, email, status FROM students WHERE student_id = $1", 
            [$sid]
        );
        
        if (!$studentQuery || pg_num_rows($studentQuery) === 0) {
            $_SESSION['error_message'] = "Student not found.";
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
        
        $student = pg_fetch_assoc($studentQuery);
        $fullName = trim($student['first_name'] . ' ' . $student['last_name']);
        
        // Check if already archived
        if ($student['status'] === 'archived') {
            $_SESSION['error_message'] = "Student is already archived.";
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
        
        // Use StudentArchivalService for archival
        require_once __DIR__ . '/../../services/StudentArchivalService.php';
        $archivalService = new StudentArchivalService($connection);
        
        // Archive with manual archival (not household duplicate)
        $archiveResult = $archivalService->archiveStudentManually(
            $sid,
            $archiveReason,
            $adminId
        );
        
        if ($archiveResult['success']) {
            // Log the archival action
            require_once __DIR__ . '/../../services/AuditLogger.php';
            $auditLogger = new AuditLogger($connection);
            $auditLogger->logStudentArchived(
                $adminId,
                $adminUsername,
                $sid,
                [
                    'full_name' => $fullName,
                    'email' => $student['email'],
                    'files_archived' => $archiveResult['files_archived'] ?? 0,
                    'space_saved' => $archiveResult['space_saved'] ?? 0
                ],
                $archiveReason,
                false // Manual archival
            );
            
            $_SESSION['success_message'] = "Student {$fullName} has been archived successfully.";
            
            // Add admin notification
            $notification_msg = "Student archived: {$fullName} (ID: {$sid}) - Reason: {$archiveReason}";
            pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
        } else {
            $error_msg = $archiveResult['error'] ?? 'Unknown error occurred';
            $_SESSION['error_message'] = "Failed to archive student: {$error_msg}";
        }
        
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    
    // Reject Documents - Selective rejection with reasons
    if (!empty($_POST['reject_documents']) && isset($_POST['student_id'])) {
        error_log("=== REJECT DOCUMENTS HANDLER START ===");
        error_log("POST keys: " . implode(', ', array_keys($_POST)));
        error_log("CSRF token from POST: " . substr(($_POST['csrf_token'] ?? 'MISSING'), 0, 20) . "...");
        
        $student_id = trim($_POST['student_id']);
        error_log("Reject documents triggered for student: " . $student_id);
        
        try {
            // Get selected documents to reject and their reasons
            $documentsToReject = $_POST['reject_doc_types'] ?? [];
            $rejectionReasons = [];
            
            // Document type code to folder mapping
            $docTypeToFolder = [
                '00' => 'enrollment_forms',
                '01' => 'grades',
                '02' => 'letter_to_mayor',
                '03' => 'indigency',
                '04' => 'id_pictures'
            ];
            
            $docTypeNames = [
                '00' => 'Enrollment Form (EAF)',
                '01' => 'Academic Grades',
                '02' => 'Letter to Mayor',
                '03' => 'Certificate of Indigency',
                '04' => 'ID Picture'
            ];
            
            // Validate that at least one document is selected
            if (empty($documentsToReject)) {
                $_SESSION['error'] = "Please select at least one document to reject.";
                header('Location: ' . $_SERVER['PHP_SELF']);
                exit;
            }
            
            // Collect rejection reasons for each document
            foreach ($documentsToReject as $docCode) {
                $reasonKey = 'reject_reason_' . $docCode;
                $reason = trim($_POST[$reasonKey] ?? '');
                
                if (empty($reason)) {
                    $reason = 'Document needs to be re-uploaded.'; // Default reason
                }
                
                $rejectionReasons[$docCode] = $reason;
            }
            
            // Get student details for email notification
            $studentQuery = pg_query_params($connection, 
                "SELECT first_name, last_name, email FROM students WHERE student_id = $1", 
                [$student_id]
            );
            $student = $studentQuery ? pg_fetch_assoc($studentQuery) : null;
            
            // Delete selected document files from filesystem
            $uploadsPath = $pathConfig->getStudentPath();
            $deletedCount = 0;
            
            foreach ($documentsToReject as $docCode) {
                $folderName = $docTypeToFolder[$docCode] ?? null;
                if (!$folderName) continue;
                
                $folderPath = $uploadsPath . '/' . $folderName;
                if (is_dir($folderPath)) {
                    // OLD STRUCTURE: Delete files in flat folder
                    $files = glob($folderPath . '/' . $student_id . '_*');
                    foreach ($files as $file) {
                        if (is_file($file)) {
                            @unlink($file);
                            $deletedCount++;
                        }
                    }
                    
                    // NEW STRUCTURE: Delete entire student folder
                    $studentFolderPath = $folderPath . '/' . $student_id;
                    if (is_dir($studentFolderPath)) {
                        $studentFiles = glob($studentFolderPath . '/*');
                        foreach ($studentFiles as $file) {
                            if (is_file($file)) {
                                @unlink($file);
                                $deletedCount++;
                            }
                        }
                        // Remove the student folder itself
                        @rmdir($studentFolderPath);
                    }
                }
            }
            
            // Delete selected document records from database
            $placeholders = implode(',', array_fill(0, count($documentsToReject), '?'));
            $deleteDocsQuery = "DELETE FROM documents WHERE student_id = $1 AND document_type_code = ANY($2::text[])";
            pg_query_params($connection, $deleteDocsQuery, [
                $student_id,
                '{' . implode(',', $documentsToReject) . '}'
            ]);
            
            // Prepare rejection data with reasons for storage
            $rejectionData = [];
            foreach ($documentsToReject as $docCode) {
                $rejectionData[] = [
                    'code' => $docCode,
                    'name' => $docTypeNames[$docCode] ?? 'Unknown',
                    'reason' => $rejectionReasons[$docCode] ?? 'No reason provided'
                ];
            }
            
            // Set needs_document_upload flag, mark documents_to_reupload with reasons
            // CRITICAL: Reset documents_submitted and documents_validated to allow re-upload
            $updateQuery = "UPDATE students 
                           SET needs_document_upload = TRUE,
                               documents_to_reupload = $1,
                               document_rejection_reasons = $2,
                               documents_submitted = FALSE,
                               documents_validated = FALSE,
                               documents_submission_date = NULL
                           WHERE student_id = $3";
            pg_query_params($connection, $updateQuery, [
                json_encode($documentsToReject), // Array of codes
                json_encode($rejectionData), // Array of objects with code, name, reason
                $student_id
            ]);
            
            // Build detailed rejection message for audit log
            $rejectedDocsList = [];
            foreach ($documentsToReject as $docCode) {
                $rejectedDocsList[] = $docTypeNames[$docCode] . ': ' . $rejectionReasons[$docCode];
            }
            $rejectionDetails = implode('; ', $rejectedDocsList);
            
            // Log audit
            $auditQuery = "INSERT INTO audit_log (admin_id, student_id, action, description, ip_address, created_at)
                          VALUES ($1, $2, 'reject_documents', $3, $4, NOW())";
            pg_query_params($connection, $auditQuery, [
                $_SESSION['admin_id'] ?? null,
                $student_id,
                "Admin rejected " . count($documentsToReject) . " document(s). Deleted $deletedCount files. Reasons: $rejectionDetails",
                $_SERVER['REMOTE_ADDR']
            ]);
            
            // Build student notification message with specific documents and reasons
            $notificationMsg = "The following documents have been rejected and need to be re-uploaded:\n\n";
            foreach ($rejectionData as $doc) {
                $notificationMsg .= "• " . $doc['name'] . "\n  Reason: " . $doc['reason'] . "\n\n";
            }
            $notificationMsg .= "Please upload the corrected documents through the Upload Documents page.";
            
            // Send student notification about document rejection
            createStudentNotification(
                $connection,
                $student_id,
                'Documents Rejected - Re-upload Required',
                $notificationMsg,
                'warning',
                'high',
                'upload_document.php'
            );
            
            // Send email notification if student has email
            if ($student && !empty($student['email'])) {
                require_once __DIR__ . '/../../phpmailer/vendor/autoload.php';
                
                try {
                    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                    
                    // Server settings
                    $mail->isSMTP();
                    $mail->Host       = 'smtp.gmail.com';
                    $mail->SMTPAuth   = true;
                    $mail->Username   = 'dilucayaka02@gmail.com';
                    $mail->Password   = 'jlld eygl hksj flvg';
                    $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port       = 587;
                    
                    // Recipients
                    $mail->setFrom('dilucayaka02@gmail.com', 'EducAid System');
                    $mail->addAddress($student['email'], $student['first_name'] . ' ' . $student['last_name']);
                    
                    // Content
                    $mail->isHTML(true);
                    $mail->Subject = 'Document Rejection Notice - EducAid';
                    
                    // Build email body with document list
                    $emailDocs = '';
                    foreach ($rejectionData as $doc) {
                        $emailDocs .= '<tr>
                            <td style="padding: 12px; border: 1px solid #e0e0e0; background: #f9f9f9;">
                                <strong>' . htmlspecialchars($doc['name']) . '</strong>
                            </td>
                            <td style="padding: 12px; border: 1px solid #e0e0e0;">
                                ' . htmlspecialchars($doc['reason']) . '
                            </td>
                        </tr>';
                    }
                    
                    $loginUrl = (isset($_SERVER['HTTPS'])?'https':'http') . '://' . $_SERVER['HTTP_HOST'] . '/EducAid/unified_login.php';
                    
                    $mail->Body = '
                    <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; background-color: #f8f9fa;">
                        <div style="background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                            <div style="text-align: center; margin-bottom: 30px;">
                                <h1 style="color: #dc3545; margin: 0;">Document Rejection Notice</h1>
                            </div>
                            
                            <p>Dear ' . htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) . ',</p>
                            
                            <p>Your application has been reviewed, and the following documents need to be re-uploaded:</p>
                            
                            <table style="width: 100%; border-collapse: collapse; margin: 20px 0;">
                                <thead>
                                    <tr style="background: #dc3545; color: white;">
                                        <th style="padding: 12px; text-align: left; border: 1px solid #c82333;">Document Type</th>
                                        <th style="padding: 12px; text-align: left; border: 1px solid #c82333;">Reason for Rejection</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ' . $emailDocs . '
                                </tbody>
                            </table>
                            
                            <div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0;">
                                <strong>⚠️ Action Required:</strong> Please log in to your account and re-upload the rejected documents as soon as possible.
                            </div>
                            
                            <div style="text-align: center; margin: 30px 0;">
                                <a href="' . $loginUrl . '" style="display: inline-block; padding: 12px 30px; background: #007bff; color: white; text-decoration: none; border-radius: 5px; font-weight: bold;">Login to Upload Documents</a>
                            </div>
                            
                            <p style="color: #666; font-size: 14px; margin-top: 30px;">
                                If you have any questions, please contact your administrator.
                            </p>
                            
                            <hr style="border: none; border-top: 1px solid #eee; margin: 20px 0;">
                            
                            <p style="color: #999; font-size: 12px; text-align: center;">
                                This is an automated message from EducAid System. Please do not reply to this email.
                            </p>
                        </div>
                    </div>';
                    
                    $mail->AltBody = "Dear " . $student['first_name'] . " " . $student['last_name'] . ",\n\n" .
                                     "Your application has been reviewed, and the following documents need to be re-uploaded:\n\n" .
                                     $notificationMsg . "\n\n" .
                                     "Please log in to your account at: " . $loginUrl . "\n\n" .
                                     "If you have any questions, please contact your administrator.\n\n" .
                                     "EducAid System";
                    
                    $mail->send();
                    error_log("Rejection email sent successfully to: " . $student['email']);
                } catch (Exception $e) {
                    error_log("Failed to send rejection email: " . $mail->ErrorInfo);
                }
            }
            
            $_SESSION['success'] = count($documentsToReject) . " document(s) rejected. Student will be notified to re-upload.";
            
        } catch (Exception $e) {
            $_SESSION['error'] = "Error rejecting documents: " . $e->getMessage();
        }
        
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

// --------- AJAX handler for modal content refresh ---------
// NOTE: This endpoint has been replaced by get_applicant_details.php
// which focuses on permanent storage: student/{filetype}/{studentID}/
if (isset($_GET['refresh_modal']) && isset($_GET['student_id'])) {
    // Redirect to new endpoint
    header('Location: get_applicant_details.php?student_id=' . urlencode($_GET['student_id']));
    exit;
}

/* OLD CODE - Kept for reference during migration
if (isset($_GET['refresh_modal']) && isset($_GET['student_id'])) {
    $student_id = trim($_GET['student_id']); // Keep as TEXT for proper student_id lookup
    
    // Fetch student data
    $student_query = pg_query_params($connection, 
        "SELECT * FROM students WHERE student_id = $1", 
        [$student_id]);
    
    if (!$student_query || pg_num_rows($student_query) === 0) {
        error_log("Modal refresh: Student not found for ID: " . $student_id);
        echo json_encode(['error' => 'Student not found', 'student_id' => $student_id]);
        exit;
    }
    
    $applicant = pg_fetch_assoc($student_query);
    $first_name = $applicant['first_name'] ?? '';
    $last_name = $applicant['last_name'] ?? '';
    
    // Determine student type (needs upload vs registered)
    $needs_upload = ($applicant['student_type'] ?? null) === 'existing_student';
    
    // Start output buffering
    ob_start();
    
    // Display type badge info
    if ($needs_upload): ?>
    <div class="alert alert-warning mb-3">
        <i class="bi bi-info-circle"></i> 
        <strong>Re-upload Required:</strong> This student is an existing applicant who needs to upload/re-upload their documents via the Upload Documents tab.
    </div>
    <?php else: ?>
    <div class="alert alert-info mb-3">
        <i class="bi bi-check-circle"></i> 
        <strong>New Registration:</strong> This student registered through the online registration system and submitted documents during registration.
    </div>
    <?php endif;
    
    // Map document type codes to readable names
    $doc_type_map = [
        '04' => 'id_picture',
        '00' => 'eaf',
        '02' => 'letter_to_mayor',
        '03' => 'certificate_of_indigency',
        '01' => 'grades'
    ];
    
    // First, get documents from database (only those with valid file paths that exist)
    $docs = pg_query_params($connection, "SELECT document_type_code, file_path FROM documents WHERE student_id = $1", [$student_id]);
    $db_documents = [];
    while ($doc = pg_fetch_assoc($docs)) {
        // Only include documents where the file actually exists
        $filePath = $doc['file_path'];
        $docTypeCode = $doc['document_type_code'];
        $docTypeName = $doc_type_map[$docTypeCode] ?? 'unknown';
        
        $server_root = dirname(__DIR__, 2);
        
        // Check if path contains 'temp' - replace with 'student' for approved students
        if (strpos($filePath, '/temp/') !== false) {
            $permanentPath = str_replace('/temp/', '/student/', $filePath);
            // Check if permanent file exists
            $relative_from_root = ltrim(str_replace('../../', '', $permanentPath), '/');
            $server_path = $server_root . '/' . $relative_from_root;
            
            if (file_exists($server_path)) {
                $db_documents[$docTypeName] = $permanentPath;
            } else {
                // Fallback to temp path
                $relative_from_root = ltrim(str_replace('../../', '', $filePath), '/');
                $server_path = $server_root . '/' . $relative_from_root;
                if (file_exists($server_path)) {
                    $db_documents[$docTypeName] = $filePath;
                }
            }
        } else {
            // Already permanent path
            $relative_from_root = ltrim(str_replace('../../', '', $filePath), '/');
            $server_path = $server_root . '/' . $relative_from_root;
            if (file_exists($server_path)) {
                $db_documents[$docTypeName] = $filePath;
            }
        }
    }
    
    // Map academic_grades to grades for consistency
    if (isset($db_documents['academic_grades'])) {
        $db_documents['grades'] = $db_documents['academic_grades'];
    }
    
    // Then, search for documents in student directory using student_id pattern with FilePathConfig
    $found_documents = [];
    $server_base = $pathConfig->getStudentPath(); // Use FilePathConfig for base path
    $web_base = 'assets/uploads/student/'; // Web path relative from document root
    
    $document_folders = [
        'id_pictures' => 'id_picture',
        'enrollment_forms' => 'eaf',
        'letter_mayor' => 'letter_to_mayor',
        'indigency' => 'certificate_of_indigency',
        'grades' => 'grades'
    ];
    
    foreach ($document_folders as $folder => $type) {
        $matches = [];
        
        // NEW STRUCTURE: Check student/{doc_type}/{student_id}/ folder first
        $student_subdir = $server_base . DIRECTORY_SEPARATOR . $folder . DIRECTORY_SEPARATOR . $student_id . DIRECTORY_SEPARATOR;
        if (is_dir($student_subdir)) {
            foreach (glob($student_subdir . '*') as $file) {
                // Skip associated files
                if (preg_match('/\.(verify\.json|ocr\.txt|confidence\.json|tsv)$/i', $file)) continue;
                if (is_dir($file)) continue;
                
                $matches[filemtime($file)] = [
                    'server' => $file,
                    'web' => $web_base . $folder . '/' . $student_id . '/' . basename($file)
                ];
            }
        }
        
        // OLD STRUCTURE: Check flat student/{doc_type}/ folder
        $dir = $server_base . DIRECTORY_SEPARATOR . $folder . DIRECTORY_SEPARATOR;
        if (is_dir($dir)) {
            // Look for files starting with student_id OR containing student's name
            $pattern = $dir . $student_id . '_*';
            $id_matches = glob($pattern);
            
            // If no matches by student_id, try searching by name
            if (empty($id_matches)) {
                $all_files = glob($dir . '*');
                foreach ($all_files as $file) {
                    // Skip subdirectories and associated files
                    if (is_dir($file)) continue;
                    if (preg_match('/\.(verify\.json|ocr\.txt|confidence\.json|tsv)$/i', $file)) continue;
                    
                    $basename = strtolower(basename($file));
                    $first_norm = strtolower($first_name);
                    $last_norm = strtolower($last_name);
                    
                    // Check if file contains both first and last name
                    if (strpos($basename, $first_norm) !== false && 
                        strpos($basename, $last_norm) !== false) {
                        $id_matches[] = $file;
                    }
                }
            } else {
                // Filter out subdirectories and associated files from pattern matches
                $id_matches = array_filter($id_matches, function($file) {
                    if (is_dir($file)) return false;
                    return !preg_match('/\.(verify\.json|ocr\.txt|confidence\.json|tsv)$/i', $file);
                });
            }
            
            // Add flat folder matches to the matches array
            foreach ($id_matches as $file) {
                $matches[filemtime($file)] = [
                    'server' => $file,
                    'web' => $web_base . $folder . '/' . basename($file)
                ];
            }
        }
        
        if (!empty($matches)) {
            // Get the newest file
            krsort($matches); // Sort by timestamp, newest first
            $newest = reset($matches);
            $found_documents[$type] = $newest['web'];
        }
    }
    
    // Also search temp folders for documents not yet moved to permanent storage using FilePathConfig
    $temp_base = $pathConfig->getTempPath(); // Use FilePathConfig for temp path
    $temp_web_base = 'assets/uploads/temp/'; // Web path relative from document root
    
    foreach ($document_folders as $folder => $type) {
        // Skip if already found in student directory
        if (isset($found_documents[$type])) {
            continue;
        }
        
        $temp_dir = $temp_base . DIRECTORY_SEPARATOR . $folder . DIRECTORY_SEPARATOR;
        if (is_dir($temp_dir)) {
            // Search by student_id or name
            $all_files = glob($temp_dir . '*');
            $matches = [];
            
            foreach ($all_files as $file) {
                $basename = strtolower(basename($file));
                
                // Skip associated files
                if (preg_match('/\.(verify\.json|ocr\.txt|confidence\.json|tsv)$/', $basename)) {
                    continue;
                }
                
                // Match by student_id or name
                if (strpos($basename, strtolower($student_id)) !== false ||
                    (strpos($basename, strtolower($first_name)) !== false && 
                     strpos($basename, strtolower($last_name)) !== false)) {
                    $matches[filemtime($file)] = $file;
                }
            }
            
            if (!empty($matches)) {
                krsort($matches); // newest first
                $newest = reset($matches);
                $found_documents[$type] = $temp_web_base . $folder . '/' . basename($newest);
            }
        }
    }

    // Merge all sources, prioritizing file system results over DB
    $all_documents = array_merge($db_documents, $found_documents);

    $document_labels = [
        'id_picture' => 'ID Picture',
        'eaf' => 'EAF',
        'letter_to_mayor' => 'Letter to Mayor',
        'certificate_of_indigency' => 'Certificate of Indigency'
    ];

    // Build cards grid
    echo "<div class='doc-grid'>";
    $has_documents = false;
    foreach ($document_labels as $type => $label) {
        $cardTitle = htmlspecialchars($label);
        if (isset($all_documents[$type])) {
            $has_documents = true;
            $filePath = trim($all_documents[$type]);

            // Resolve server path for metadata
            $server_root = dirname(__DIR__, 2);
            $relative_from_root = ltrim(str_replace('../../', '', $filePath), '/');
            $server_path = $server_root . '/' . $relative_from_root;
            
            // Convert to web-root relative path for browser
            $webPath = '../../' . $relative_from_root;

            // Check extension for image vs PDF
            $cleanPath = basename($filePath);
            $is_image = preg_match('/\.(jpg|jpeg|png|gif)$/i', $cleanPath);
            $is_pdf   = preg_match('/\.pdf$/i', $cleanPath);

            $size_str = '';
            $date_str = '';
            if (file_exists($server_path)) {
                $size = filesize($server_path);
                $units = ['B','KB','MB','GB'];
                $pow = $size > 0 ? floor(log($size, 1024)) : 0;
                $size_str = number_format($size / pow(1024, $pow), $pow ? 2 : 0) . ' ' . $units[$pow];
                $date_str = date('M d, Y h:i A', filemtime($server_path));
            }

            // Fetch OCR confidence for this document
            $type_to_code = [
                'id_picture' => '04',
                'eaf' => '00',
                'letter_to_mayor' => '02',
                'certificate_of_indigency' => '03',
                'grades' => '01'
            ];
            $doc_code = $type_to_code[$type] ?? null;
            
            $ocr_confidence_badge = '';
            if ($doc_code) {
                $ocr_query = pg_query_params($connection, 
                    "SELECT ocr_confidence FROM documents WHERE student_id = $1 AND document_type_code = $2 ORDER BY upload_date DESC LIMIT 1", 
                    [$student_id, $doc_code]);
                if ($ocr_query && pg_num_rows($ocr_query) > 0) {
                    $ocr_data = pg_fetch_assoc($ocr_query);
                    if ($ocr_data['ocr_confidence'] !== null && $ocr_data['ocr_confidence'] > 0) {
                        $conf_val = round($ocr_data['ocr_confidence'], 1);
                        $conf_color = $conf_val >= 80 ? 'success' : ($conf_val >= 60 ? 'warning' : 'danger');
                        $ocr_confidence_badge = "<span class='badge bg-{$conf_color} ms-2'><i class='bi bi-robot me-1'></i>{$conf_val}%</span>";
                    }
                }
            }

            $thumbHtml = $is_image
                ? "<img src='" . htmlspecialchars($webPath) . "' class='doc-thumb' alt='$cardTitle' onerror=\"console.error('Failed to load:', this.src); this.parentElement.innerHTML='<div class=\\'doc-thumb doc-thumb-pdf\\'><i class=\\'bi bi-exclamation-triangle\\'></i></div>';\">"
                : "<div class='doc-thumb doc-thumb-pdf'><i class='bi bi-file-earmark-pdf'></i></div>";

            $safeSrc = htmlspecialchars($webPath);
            
            // Get verification status and score from documents table
            $verification_badge = '';
            $verification_btn = '';
            if ($doc_code) {
                $verify_query = pg_query_params($connection, 
                    "SELECT verification_score, verification_status, ocr_confidence FROM documents WHERE student_id = $1 AND document_type_code = $2 ORDER BY upload_date DESC LIMIT 1", 
                    [$student_id, $doc_code]);
                if ($verify_query && pg_num_rows($verify_query) > 0) {
                    $verify_data = pg_fetch_assoc($verify_query);
                    $verify_score = $verify_data['verification_score'];
                    $verify_status = $verify_data['verification_status'];
                    $has_ocr = $verify_data['ocr_confidence'] !== null && $verify_data['ocr_confidence'] > 0;
                    
                    if ($verify_score !== null && $verify_score > 0) {
                        $verify_val = round($verify_score, 1);
                        $verify_color = $verify_val >= 80 ? 'success' : ($verify_val >= 60 ? 'warning' : 'danger');
                        $verify_icon = $verify_val >= 80 ? 'check-circle' : ($verify_val >= 60 ? 'exclamation-triangle' : 'x-circle');
                        $verification_badge = " <span class='badge bg-{$verify_color}'><i class='bi bi-{$verify_icon} me-1'></i>{$verify_val}%</span>";
                    }
                    
                    // Show view validation button if document has OCR data OR verification score
                    if ($has_ocr || ($verify_score !== null && $verify_score > 0)) {
                        $verification_btn = "<button type='button' class='btn btn-sm btn-outline-info w-100' 
                            onclick=\"event.stopPropagation(); loadValidationData('$type', '$student_id'); showValidationModal();\">
                            <i class='bi bi-clipboard-check me-1'></i>View Validation Details
                        </button>";
                    }
                }
            }
            
            echo "<div class='doc-card'>
                    <div class='doc-card-header'>
                        <div class='d-flex justify-content-between align-items-center'>
                            <span>$cardTitle</span>
                            <div class='d-flex gap-1'>
                                $ocr_confidence_badge
                                $verification_badge
                            </div>
                        </div>
                    </div>
                    <div class='doc-card-body' onclick=\"openDocumentViewer('$safeSrc','$cardTitle')\">$thumbHtml</div>
                    <div class='doc-meta'>" .
                        ($date_str ? "<span><i class='bi bi-calendar-event me-1'></i>$date_str</span>" : "") .
                        ($size_str ? "<span><i class='bi bi-hdd me-1'></i>$size_str</span>" : "") .
                    "</div>
                    <div class='doc-actions'>
                        <button type='button' class='btn btn-sm btn-primary' onclick=\"openDocumentViewer('$safeSrc','$cardTitle')\" title='View Document'><i class='bi bi-eye'></i></button>
                        <a class='btn btn-sm btn-outline-secondary' href='$safeSrc' target='_blank' title='Open in New Tab'><i class='bi bi-box-arrow-up-right'></i></a>
                        <a class='btn btn-sm btn-outline-success' href='$safeSrc' download title='Download'><i class='bi bi-download'></i></a>
                    </div>";
            
            // Add validation button if verification data exists
            if ($verification_btn) {
                echo "<div class='doc-actions' style='border-top: 0; padding-top: 0;'>
                      $verification_btn
                      </div>";
            }
            
            echo "</div>";
        } else {
            echo "<div class='doc-card doc-card-missing'>
                    <div class='doc-card-header'>$cardTitle</div>
                    <div class='doc-card-body missing'>
                        <div class='missing-icon'><i class='bi bi-exclamation-triangle'></i></div>
                        <div class='missing-text'>Not uploaded</div>
                    </div>
                    <div class='doc-actions'>
                        <span class='text-muted small'>Awaiting submission</span>
                    </div>
                  </div>";
        }
    }
    echo "</div>"; // end doc-grid

    if (!$has_documents) {
        echo "<p class='text-muted'>No documents uploaded.</p>";
    }

    // Add Academic Grades card
    $cardTitle = 'Academic Grades';
    
    if (isset($all_documents['grades'])) {
        $filePath = trim($all_documents['grades']);
        
        // Resolve server path for metadata
        $server_root = dirname(__DIR__, 2);
        $relative_from_root = ltrim(str_replace('../../', '', $filePath), '/');
        $server_path = $server_root . '/' . $relative_from_root;
        
        // Convert to web-root relative path
        $webPath = '../../' . $relative_from_root;

        // Check extension
        $cleanPath = basename($filePath);
        $is_image = preg_match('/\.(jpg|jpeg|png|gif)$/i', $cleanPath);
        $is_pdf   = preg_match('/\.pdf$/i', $cleanPath);

        $size_str = '';
        $date_str = '';
        if (file_exists($server_path)) {
            $size = filesize($server_path);
            $units = ['B','KB','MB','GB'];
            $pow = $size > 0 ? floor(log($size, 1024)) : 0;
            $size_str = number_format($size / pow(1024, $pow), $pow ? 2 : 0) . ' ' . $units[$pow];
            $date_str = date('M d, Y h:i A', filemtime($server_path));
        }

        $thumbHtml = $is_image
            ? "<img src='" . htmlspecialchars($webPath) . "' class='doc-thumb' alt='$cardTitle' onerror=\"console.error('Failed to load:', this.src); this.parentElement.innerHTML='<div class=\\'doc-thumb doc-thumb-pdf\\'><i class=\\'bi bi-exclamation-triangle\\'></i></div>';\">"
            : "<div class='doc-thumb doc-thumb-pdf'><i class='bi bi-file-earmark-pdf'></i></div>";

        $safeSrc = htmlspecialchars($webPath);
        
        // Check for OCR confidence and verification
        $ocr_confidence = '';
        $verification_badge = '';
        $verification_btn = '';
        
        $docs_query = pg_query_params($connection, 
            "SELECT ocr_confidence, verification_score, verification_status FROM documents WHERE student_id = $1 AND document_type_code = '01' ORDER BY upload_date DESC LIMIT 1", 
            [$student_id]);
        
        if ($docs_query && pg_num_rows($docs_query) > 0) {
            $doc_data = pg_fetch_assoc($docs_query);
            
            // OCR Confidence
            if ($doc_data['ocr_confidence'] !== null && $doc_data['ocr_confidence'] > 0) {
                $conf_val = round($doc_data['ocr_confidence'], 1);
                $conf_color = $conf_val >= 80 ? 'success' : ($conf_val >= 60 ? 'warning' : 'danger');
                $ocr_confidence = "<span class='badge bg-{$conf_color}'><i class='bi bi-robot me-1'></i>{$conf_val}%</span>";
            }
            
            // Verification Score
            $verify_score = $doc_data['verification_score'];
            $has_ocr_data = $doc_data['ocr_confidence'] !== null && $doc_data['ocr_confidence'] > 0;
            
            if ($verify_score !== null && $verify_score > 0) {
                $verify_val = round($verify_score, 1);
                $verify_color = $verify_val >= 80 ? 'success' : ($verify_val >= 60 ? 'warning' : 'danger');
                $verify_icon = $verify_val >= 80 ? 'check-circle' : ($verify_val >= 60 ? 'exclamation-triangle' : 'x-circle');
                $verification_badge = " <span class='badge bg-{$verify_color}'><i class='bi bi-{$verify_icon} me-1'></i>{$verify_val}%</span>";
            }
            
            // Show view validation button if document has OCR data OR verification score
            if ($has_ocr_data || ($verify_score !== null && $verify_score > 0)) {
                $verification_btn = "<button type='button' class='btn btn-sm btn-outline-info w-100' 
                    onclick=\"event.stopPropagation(); loadValidationData('grades', '$student_id'); showValidationModal();\">
                    <i class='bi bi-clipboard-check me-1'></i>View Validation Details
                </button>";
            }
        }
        
        echo "<div class='doc-card'>
                <div class='doc-card-header'>
                    <div class='d-flex justify-content-between align-items-center'>
                        <span>$cardTitle</span>
                        <div class='d-flex gap-1'>
                            $ocr_confidence
                            $verification_badge
                        </div>
                    </div>
                </div>
                <div class='doc-card-body' onclick=\"openDocumentViewer('$safeSrc','$cardTitle')\">$thumbHtml</div>
                <div class='doc-meta'>" .
                    ($date_str ? "<span><i class='bi bi-calendar-event me-1'></i>$date_str</span>" : "") .
                    ($size_str ? "<span><i class='bi bi-hdd me-1'></i>$size_str</span>" : "") .
                "</div>
                <div class='doc-actions'>
                    <button type='button' class='btn btn-sm btn-primary' onclick=\"openDocumentViewer('$safeSrc','$cardTitle')\" title='View Document'><i class='bi bi-eye'></i></button>
                    <a class='btn btn-sm btn-outline-secondary' href='$safeSrc' target='_blank' title='Open in New Tab'><i class='bi bi-box-arrow-up-right'></i></a>
                    <a class='btn btn-sm btn-outline-success' href='$safeSrc' download title='Download'><i class='bi bi-download'></i></a>
                </div>";
        
        // Add validation button if verification data exists
        if ($verification_btn) {
            echo "<div class='doc-actions' style='border-top: 0; padding-top: 0;'>
                  $verification_btn
                  </div>";
        }
        
        echo "</div>";
    } else {
        echo "<div class='doc-card doc-card-missing'>
                <div class='doc-card-header'>$cardTitle</div>
                <div class='doc-card-body missing'>
                    <div class='missing-icon'><i class='bi bi-exclamation-triangle'></i></div>
                    <div class='missing-text'>Not uploaded</div>
                </div>
                <div class='doc-actions'>
                    <span class='text-muted small'>Awaiting submission</span>
                </div>
              </div>";
    }
    
    $modalContent = ob_get_clean();
    echo json_encode(['success' => true, 'html' => $modalContent]);
    exit;
}
*/

// --------- AJAX handler ---------
if ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '' === 'XMLHttpRequest' || (isset($_GET['ajax']) && $_GET['ajax'] === '1')) {
    // Return table content and stats for real-time updates
    ob_start();
    ?>
    <div class="section-header mb-3 d-flex justify-content-between align-items-center">
        <h2 class="fw-bold text-primary mb-0">
            <i class="bi bi-person-vcard"></i>
            Manage Applicants
        </h2>
        <div class="d-flex align-items-center gap-2">
            <?php if ($hasActiveDistribution): ?>
                <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#migrationModal">
                    <i class="bi bi-upload me-1"></i> Migrate from CSV
                </button>
            <?php else: ?>
                <button class="btn btn-outline-secondary btn-sm" disabled title="Migration requires an active distribution">
                    <i class="bi bi-upload me-1"></i> Migrate from CSV <span class="badge bg-warning text-dark ms-1">No Active Distribution</span>
                </button>
            <?php endif; ?>
            <span class="badge bg-info fs-6"><?php echo $totalApplicants; ?> Total Applicants</span>
        </div>
    </div>
    <?php
    echo render_table($filteredApplicants, $connection);
    render_pagination($page, $totalPages);
    echo ob_get_clean();
    exit;
}

// Normal page output below...
?>
<?php $page_title='Manage Applicants'; $extra_css=['../../assets/css/admin/manage_applicants.css']; include __DIR__ . '/../../includes/admin/admin_head.php'; ?>
<body>
<?php include __DIR__ . '/../../includes/admin/admin_topbar.php'; ?>
<div id="wrapper" class="admin-wrapper">
    <?php include __DIR__ . '/../../includes/admin/admin_sidebar.php'; ?>
    <?php include __DIR__ . '/../../includes/admin/admin_header.php'; ?>
    
    <section class="home-section" id="mainContent">
        <div class="container-fluid py-4 px-4">
            <?php if (!empty($_SESSION['error_message']) || !empty($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle me-2"></i>
                <?= htmlspecialchars($_SESSION['error_message'] ?? $_SESSION['error']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['error_message'], $_SESSION['error']); ?>
            <?php endif; ?>

            <?php if (!empty($_SESSION['success_message']) || !empty($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle me-2"></i>
                <?= htmlspecialchars($_SESSION['success_message'] ?? $_SESSION['success']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['success_message'], $_SESSION['success']); ?>
            <?php endif; ?>

                        <!-- Page Header - Responsive structured layout -->
                        <div class="page-header mb-4">
                            <div class="page-header-title">
                                <h1 class="fw-bold mb-1">Manage Applicants</h1>
                                <p class="text-muted mb-0">Review and manage student applicants in the system.</p>
                            </div>
                            <div class="page-header-actions">
                                <span class="badge applicants-badge"><?php echo $totalApplicants; ?> Applicants</span>
                                <?php if ($hasActiveDistribution): ?>
                                    <button class="btn btn-outline-primary btn-sm btn-migrate" data-bs-toggle="modal" data-bs-target="#migrationModal">
                                        <i class="bi bi-upload"></i>
                                        <span>Migrate CSV</span>
                                    </button>
                                <?php else: ?>
                                    <button class="btn btn-outline-secondary btn-sm btn-migrate" disabled title="Migration requires an active distribution">
                                        <i class="bi bi-upload"></i>
                                        <span>Migrate CSV</span>
                                        <span class="badge bg-warning text-dark ms-1">No Active Distribution</span>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>

            <!-- Filter Section - Clean style -->
            <div class="filter-section">
                <form class="row g-3" id="filterForm" method="GET">
                    <div class="col-md-3">
                        <label class="form-label">Sort by Surname</label>
                        <select name="sort" class="form-select">
                            <option value="asc" <?= $sort === 'asc' ? 'selected' : '' ?>>A to Z</option>
                            <option value="desc" <?= $sort === 'desc' ? 'selected' : '' ?>>Z to A</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Search by Surname</label>
                        <input type="text" name="search_surname" class="form-control" value="<?= htmlspecialchars($search) ?>" placeholder="Enter surname...">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Year Level</label>
                        <select name="filter_year_level" class="form-select">
                            <option value="">All Years</option>
                            <option value="2nd Year" <?= $filterYearLevel === '2nd Year' ? 'selected' : '' ?>>2nd Year</option>
                            <option value="3rd Year" <?= $filterYearLevel === '3rd Year' ? 'selected' : '' ?>>3rd Year</option>
                            <option value="4th Year" <?= $filterYearLevel === '4th Year' ? 'selected' : '' ?>>4th Year</option>
                            <option value="5th Year or Higher" <?= $filterYearLevel === '5th Year or Higher' ? 'selected' : '' ?>>5th Year+</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Documents</label>
                        <select name="filter_doc_status" class="form-select" id="filterDocStatus">
                            <option value="">All</option>
                            <option value="complete" <?= $filterDocStatus === 'complete' ? 'selected' : '' ?>>Complete</option>
                            <option value="incomplete" <?= $filterDocStatus === 'incomplete' ? 'selected' : '' ?>>Incomplete</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Type</label>
                        <select name="filter_type" class="form-select">
                            <option value="">All Types</option>
                            <option value="new" <?= $filterType === 'new' ? 'selected' : '' ?>>New Registration</option>
                            <option value="re-upload" <?= $filterType === 're-upload' ? 'selected' : '' ?>>Re-upload</option>
                            <option value="migrated" <?= $filterType === 'migrated' ? 'selected' : '' ?>>Migrated</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Past Beneficiary</label>
                        <select name="filter_beneficiary" class="form-select">
                            <option value="">All Students</option>
                            <option value="yes" <?= $filterBeneficiary === 'yes' ? 'selected' : '' ?>>Yes (Previous Recipient)</option>
                            <option value="no" <?= $filterBeneficiary === 'no' ? 'selected' : '' ?>>No (First Time)</option>
                        </select>
                    </div>
                    <div class="col-md-9">
                        <label class="form-label">&nbsp;</label>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary"><i class="bi bi-search me-1"></i> Apply Filters</button>
                            <button type="button" class="btn btn-outline-secondary" id="clearFiltersBtn">Clear All</button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Applicants Table -->
            <div class="table-responsive" id="tableWrapper">
                <?= render_table($filteredApplicants, $connection) ?>
            </div>
            <div id="pagination">
                <?php render_pagination($page, $totalPages); ?>
            </div>
        </div>
    </section>
</div>

<!-- Include Blacklist Modal -->
<?php include __DIR__ . '/../../includes/admin/blacklist_modal.php'; ?>

<!-- Archive Student Modal -->
<div class="modal fade" id="archiveModal" tabindex="-1" aria-labelledby="archiveModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title" id="archiveModalLabel">
                    <i class="bi bi-archive-fill me-2"></i>Archive Student
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="archiveForm">
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>What happens when you archive a student:</strong>
                        <ul class="mb-0 mt-2">
                            <li>Student account will be deactivated</li>
                            <li>All documents will be compressed into a ZIP file</li>
                            <li>Student will not be able to login</li>
                            <li>Student will be moved to "Archived Students" page</li>
                            <li>You can unarchive the student later if needed</li>
                        </ul>
                    </div>
                    
                    <p class="mb-3">
                        You are about to archive: <strong id="archiveStudentName"></strong>
                    </p>
                    
                    <input type="hidden" name="student_id" id="archiveStudentId">
                    <input type="hidden" name="archive_student" value="1">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfArchiveStudentToken) ?>">
                    
                    <div class="mb-3">
                        <label for="archiveReason" class="form-label">
                            Reason for Archiving <span class="text-danger">*</span>
                        </label>
                        <select class="form-select" id="archiveReason" name="archive_reason" required onchange="handleArchiveReasonChange()">
                            <option value="">-- Select Reason --</option>
                            <option value="graduated">Graduated</option>
                            <option value="ineligible">Ineligible</option>
                            <option value="duplicate">Duplicate Account</option>
                            <option value="inactive">Inactive/No Longer Enrolled</option>
                            <option value="transferred">Transferred to Another Municipality</option>
                            <option value="did_not_attend">Did Not Attend Distribution</option>
                            <option value="other">Other (Please Specify)</option>
                        </select>
                    </div>
                    
                    <div class="mb-3" id="otherReasonContainer" style="display: none;">
                        <label for="archiveOtherReason" class="form-label">
                            Please specify the reason <span class="text-danger">*</span>
                        </label>
                        <textarea class="form-control" id="archiveOtherReason" name="archive_other_reason" rows="3" placeholder="Enter the specific reason for archiving this student..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-1"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-warning" id="confirmArchiveBtn">
                        <i class="bi bi-archive me-1"></i> Archive Student
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reject Documents Modal -->
<div class="modal fade" id="rejectDocumentsModal" tabindex="-1" aria-labelledby="rejectDocumentsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="rejectDocumentsModalLabel">
                    <i class="bi bi-x-circle-fill me-2"></i>Reject Documents
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="rejectDocumentsForm">
                <input type="hidden" name="student_id" id="rejectStudentId">
                <input type="hidden" name="reject_documents" value="1">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfRejectDocumentsToken) ?>">
                
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <strong>Select documents to reject:</strong> Choose which documents need to be re-uploaded and provide a reason for each.
                    </div>
                    
                    <p class="mb-3">Student: <strong id="rejectStudentName"></strong></p>
                    
                    <div class="document-reject-list">
                        <!-- ID Picture -->
                        <div class="card mb-3 reject-card" data-doc="04">
                            <div class="card-body">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="reject_doc_types[]" value="04" id="reject_04">
                                    <label class="form-check-label fw-bold" for="reject_04">
                                        <i class="bi bi-person-badge text-primary me-2"></i>ID Picture
                                    </label>
                                </div>
                                <div class="mt-2 reject-reason-container" id="reason_container_04" style="display: none;">
                                    <label class="form-label small text-muted">Reason for rejection:</label>
                                    <input type="text" class="form-control form-control-sm" name="reject_reason_04" placeholder="e.g., Image is blurry, face not clearly visible" maxlength="200">
                                </div>
                            </div>
                        </div>
                        
                        <!-- EAF -->
                        <div class="card mb-3 reject-card" data-doc="00">
                            <div class="card-body">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="reject_doc_types[]" value="00" id="reject_00">
                                    <label class="form-check-label fw-bold" for="reject_00">
                                        <i class="bi bi-file-earmark-text text-success me-2"></i>Enrollment Assistance Form (EAF)
                                    </label>
                                </div>
                                <div class="mt-2 reject-reason-container" id="reason_container_00" style="display: none;">
                                    <label class="form-label small text-muted">Reason for rejection:</label>
                                    <input type="text" class="form-control form-control-sm" name="reject_reason_00" placeholder="e.g., Form is incomplete, signature missing" maxlength="200">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Grades -->
                        <div class="card mb-3 reject-card" data-doc="01">
                            <div class="card-body">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="reject_doc_types[]" value="01" id="reject_01">
                                    <label class="form-check-label fw-bold" for="reject_01">
                                        <i class="bi bi-file-earmark-bar-graph text-info me-2"></i>Academic Grades
                                    </label>
                                </div>
                                <div class="mt-2 reject-reason-container" id="reason_container_01" style="display: none;">
                                    <label class="form-label small text-muted">Reason for rejection:</label>
                                    <input type="text" class="form-control form-control-sm" name="reject_reason_01" placeholder="e.g., Grades are not from the latest semester" maxlength="200">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Letter to Mayor -->
                        <div class="card mb-3 reject-card" data-doc="02">
                            <div class="card-body">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="reject_doc_types[]" value="02" id="reject_02">
                                    <label class="form-check-label fw-bold" for="reject_02">
                                        <i class="bi bi-envelope text-warning me-2"></i>Letter to Mayor
                                    </label>
                                </div>
                                <div class="mt-2 reject-reason-container" id="reason_container_02" style="display: none;">
                                    <label class="form-label small text-muted">Reason for rejection:</label>
                                    <input type="text" class="form-control form-control-sm" name="reject_reason_02" placeholder="e.g., Letter format is incorrect, missing details" maxlength="200">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Certificate of Indigency -->
                        <div class="card mb-3 reject-card" data-doc="03">
                            <div class="card-body">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="reject_doc_types[]" value="03" id="reject_03">
                                    <label class="form-check-label fw-bold" for="reject_03">
                                        <i class="bi bi-award text-danger me-2"></i>Certificate of Indigency
                                    </label>
                                </div>
                                <div class="mt-2 reject-reason-container" id="reason_container_03" style="display: none;">
                                    <label class="form-label small text-muted">Reason for rejection:</label>
                                    <input type="text" class="form-control form-control-sm" name="reject_reason_03" placeholder="e.g., Certificate is expired or not signed" maxlength="200">
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="alert alert-info mt-3">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>Note:</strong> The student will receive an email and notification with the rejection reasons for each document.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-1"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-danger" id="confirmRejectBtn" disabled>
                        <i class="bi bi-send me-1"></i> Reject Selected Documents
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Password Confirmation Modal for Archive -->
<!-- Removed - Password confirmation disabled for archive -->

<!-- Migration Modal -->
<div class="modal fade modal-mobile-compact" id="migrationModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title mb-0"><i class="bi bi-upload me-2"></i>CSV Migration - Old Students</h5>
                            <small class="text-muted">Upload old student records with their previous academic credentials.</small>
                        </div>
                        <div class="d-flex gap-2 align-items-center ms-auto">
                            <a href="../../assets/uploads/templates/migration_template_old_students.csv" download class="btn btn-sm btn-outline-primary" title="Download CSV Template">
                                <i class="bi bi-download me-1"></i>Download Template
                            </a>
                            <form method="POST" class="" id="migrationCancelForm">
                                <input type="hidden" name="migration_action" value="cancel">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfMigrationToken) ?>">
                            </form>
                            <button class="btn-close" data-bs-dismiss="modal" aria-label="Close" id="migrationCloseBtn"></button>
                        </div>
                    </div>
            <div class="modal-body">
                <!-- Distribution Requirement Alert -->
                <div class="alert alert-warning mb-3">
                    <div class="d-flex align-items-start">
                        <i class="bi bi-exclamation-triangle-fill me-2 mt-1"></i>
                        <div>
                            <strong>Important: Active Distribution Required</strong>
                            <p class="mb-1 mt-2">Old student migration <strong>requires an active distribution</strong> to ensure students are properly placed into the current academic year.</p>
                            <p class="mb-0"><small>Please ensure a distribution slot is active before proceeding with migration. Migrated students will be assigned to the active academic year.</small></p>
                        </div>
                    </div>
                </div>
                
                <!-- Migration Requirements Alert -->
                <div class="alert alert-info mb-3">
                    <div class="d-flex align-items-start">
                        <i class="bi bi-info-circle me-2 mt-1"></i>
                        <div>
                            <strong>Required Fields for Old Students</strong>
                            <ul class="mb-0 mt-2 small">
                                <li>Basic info: Name, Email, Mobile, Birthdate, Gender</li>
                                <li>Location: Barangay</li>
                                <li><strong>Optional:</strong> University, Year Level, Course, Previous Academic Year, Previous Year Level</li>
                            </ul>
                            <p class="mb-0 mt-2"><small class="text-muted">Migrated students will have status "applicant" with a "Migrated" badge and must update their current credentials on first login.</small></p>
                        </div>
                    </div>
                </div>
        
        <?php /* Migration result is now shown via JS alert after reload; avoid unsetting here to prevent race */ ?>

            <form method="POST" enctype="multipart/form-data" class="mb-3" id="migrationUploadForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfMigrationToken) ?>">
                    <input type="hidden" name="migration_action" value="preview">
                                            <div class="row g-3 align-items-end">
                                                <div class="col-12 col-md-6">
                                                    <label class="form-label fw-semibold text-primary">CSV File</label>
                                                    <input type="file" name="csv_file" id="csvFileInput" class="form-control" accept=".csv" required>
                                                    <div class="form-text">
                                                        <strong>Required:</strong> First row must be headers<br>
                                                        <small class="text-muted">
                                                            <strong>Required headers:</strong> Last Name, First Name, Birthdate, Sex/Gender, Email, Mobile, Barangay<br>
                                                            <strong>Optional headers:</strong> Middle Name, Extension Name, University, Year Level, Course, School Student ID, 
                                                            First Registered Academic Year, Previous Academic Year, Previous Year Level, Mother's Maiden Name
                                                        </small>
                                                    </div>
                                                    <div id="csvFilename" class="small text-muted mt-1" aria-live="polite"></div>
                                                </div>
                                                            <div class="col-12 col-md-4">
                                                                <label class="form-label fw-semibold text-primary">Municipality</label>
                                                                <?php if (!empty($adminMunicipalityId)): ?>
                                                                    <div class="form-control bg-light" disabled>
                                                                        <span class="badge bg-secondary-subtle text-dark border"><?= htmlspecialchars($adminMunicipalityName ?: 'Unknown') ?></span>
                                                                    </div>
                                                                    <input type="hidden" name="municipality_id" value="<?= htmlspecialchars((string)$adminMunicipalityId) ?>">
                                                                <?php else: ?>
                                                                    <select name="municipality_id" class="form-select" required>
                                                                        <option value="" disabled selected>Select municipality</option>
                                                                        <?php $munis = pg_fetch_all(pg_query($connection, "SELECT municipality_id,name FROM municipalities ORDER BY name")) ?: [];
                                                                            foreach ($munis as $m) echo '<option value="'.$m['municipality_id'].'">'.htmlspecialchars($m['name']).'</option>'; ?>
                                                                    </select>
                                                                <?php endif; ?>
                                                            </div>
                                                <div class="col-12 col-md-2 text-md-end">
                                                    <button class="btn btn-primary w-100"><i class="bi bi-search me-1"></i> Preview</button>
                                                </div>
                                            </div>
                </form>

                        <?php if (!empty($_SESSION['migration_preview'])): $mp = $_SESSION['migration_preview']; ?>
                    <div class="alert alert-info mb-3">
                        <div class="d-flex align-items-start">
                            <i class="bi bi-info-circle me-2 mt-1"></i>
                            <div>
                                <strong>Headers Detected:</strong>
                                <div class="mt-1">
                                    <?php 
                                    if (!empty($mp['detected_headers'])) {
                                        echo '<small class="text-muted">' . count($mp['detected_headers']) . ' columns recognized: </small>';
                                        echo '<div class="d-flex flex-wrap gap-1 mt-1">';
                                        foreach (array_slice($mp['detected_headers'], 0, 15) as $header) {
                                            echo '<span class="badge bg-light text-dark border">' . htmlspecialchars($header) . '</span>';
                                        }
                                        if (count($mp['detected_headers']) > 15) {
                                            echo '<span class="badge bg-light text-dark border">+' . (count($mp['detected_headers']) - 15) . ' more</span>';
                                        }
                                        echo '</div>';
                                    } else {
                                        echo '<small class="text-warning">No headers detected in CSV</small>';
                                    }
                                    ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <form method="POST" id="migrationForm">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfMigrationToken) ?>">
                        <input type="hidden" name="migration_action" value="confirm">
                                                        <div class="d-flex justify-content-end gap-2 mb-2 preview-scroll-controls">
                                                <button type="button" class="btn btn-outline-secondary btn-sm" id="scrollStartBtn" title="Scroll to start"><i class="bi bi-skip-backward"></i></button>
                                                <button type="button" class="btn btn-outline-secondary btn-sm" id="scrollConflictsBtn" title="Scroll to conflicts"><i class="bi bi-skip-forward"></i></button>
                                            </div>
                                            <div class="table-responsive border rounded migration-preview">
                                    <table class="table table-sm align-middle mb-0 preview-table">
                                <thead class="table-light">
                                    <tr>
                                        <th>Select</th>
                                        <th>Name</th>
                                        <th>Sex</th>
                                        <th>Bdate</th>
                                        <th>Barangay</th>
                                        <th>University</th>
                                        <th>Year Level</th>
                                        <th>Email</th>
                                        <th>Mobile</th>
                                        <th>Conflicts</th>
                                    </tr>
                                </thead>
                                <tbody>
                                        <?php foreach ($mp['rows'] as $idx => $r): $row=$r['row']; $conf=$r['conflicts']; ?>
                                            <tr class="<?= $conf? 'table-warning':'' ?>" data-has-conflict="<?= $conf? '1':'0' ?>">
                                                <td data-label="Select"><input type="checkbox" class="row-select" name="select[<?= $idx ?>]" <?= $conf? '':'checked' ?>></td>
                                                <td data-label="Name"><?= htmlspecialchars(trim(($row['last_name'] ?? '') . ', ' . ($row['first_name'] ?? '') . ' ' . ($row['middle_name'] ?? '') . ' ' . ($row['extension_name'] ?? ''))) ?></td>
                                                <td data-label="Sex"><?= htmlspecialchars($row['sex'] ?: '-') ?></td>
                                                <td data-label="Bdate"><?= htmlspecialchars($row['bdate'] ?: '-') ?></td>
                                                <td data-label="Barangay"><?= htmlspecialchars(($r['barangay']['name'] ?? ($row['barangay_name'] ?? ''))) ?></td>
                                                <td data-label="University"><?= htmlspecialchars(($r['university']['name'] ?? ($row['university_name'] ?? ''))) ?></td>
                                                <td data-label="Year Level"><?= htmlspecialchars(($r['year_level']['name'] ?? ($row['year_level_name'] ?? ''))) ?></td>
                                                <td data-label="Email"><?= htmlspecialchars($row['email'] ?? '') ?></td>
                                                <td data-label="Mobile"><?= htmlspecialchars($row['mobile'] ?? '') ?></td>
                                                <td data-label="Conflicts" class="small">
                                                    <?php if ($conf) { echo '<ul class="mb-0 ps-3">'; foreach ($conf as $c) echo '<li>'.htmlspecialchars($c).'</li>'; echo '</ul>'; } else { echo '<span class="text-success"><i class="bi bi-check2 me-1"></i>Ready</span>'; } ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                                <div class="mt-3 d-flex flex-wrap gap-2 align-items-center justify-content-between">
                                    <div class="d-flex flex-wrap gap-2">
                                        <button type="button" class="btn btn-outline-secondary btn-sm" id="selectAllValidBtn"><i class="bi bi-check2-all me-1"></i> Select All Valid</button>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="showConflictsOnly">
                                            <label class="form-check-label" for="showConflictsOnly">Show conflicts only</label>
                                        </div>
                                    </div>
                                    <div class="ms-auto small text-muted" id="selectedCounter">0 selected</div>
                                </div>

                                            <div class="modal-footer justify-content-end mt-3 sticky-confirm">
                                                <button type="submit" class="btn btn-success" id="confirmMigrateBtn">
                                                  <span class="migration-btn-text"><i class="bi bi-check2-circle me-1"></i> Confirm & Migrate</span>
                                                  <span class="migration-btn-loading d-none">
                                                    <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                                                    Migrating... <span id="migrationProgress">0</span>/<span id="migrationTotal">0</span>
                                                  </span>
                                                </button>
                                </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Password Confirmation Modal -->
<div class="modal fade" id="passwordConfirmModal" tabindex="-1" aria-labelledby="passwordConfirmModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="passwordConfirmModalLabel"><i class="bi bi-shield-lock me-2"></i>Confirm Password</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-3">Please enter your admin password to confirm this migration:</p>
                <div class="form-floating">
                    <input type="password" class="form-control" id="adminPasswordInput" placeholder="Password" required>
                    <label for="adminPasswordInput">Admin Password</label>
                </div>
                <div id="passwordError" class="alert alert-danger mt-2 d-none">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    <span></span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmPasswordBtn">
                    <i class="bi bi-check2-circle me-1"></i>Confirm Migration
                </button>
            </div>
        </div>
    </div>
</div>

<!-- JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../../assets/js/admin/sidebar.js"></script>
<script src="../../assets/js/admin/manage_applicants.js"></script>
<script>
// Image Zoom Functionality
function openImageZoom(imageSrc, imageTitle) {
    // Create modal if it doesn't exist
    let modal = document.getElementById('imageZoomModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'imageZoomModal';
        modal.className = 'image-zoom-modal';
        modal.innerHTML = `
            <span class="image-zoom-close" onclick="closeImageZoom()">&times;</span>
            <div class="image-zoom-content">
                <div class="image-loading">Loading...</div>
                <img id="zoomedImage" style="display: none;" alt="${imageTitle}">
            </div>
        `;
        document.body.appendChild(modal);
    }

    // Show modal
    modal.style.display = 'block';
    
    // Load image
    const img = document.getElementById('zoomedImage');
    const loading = modal.querySelector('.image-loading');
    
    img.onload = function() {
        loading.style.display = 'none';
        img.style.display = 'block';
    };
    
    img.onerror = function() {
        loading.textContent = 'Failed to load image';
    };
    
    img.src = imageSrc;
    
    // Close on background click
    modal.onclick = function(event) {
        if (event.target === modal) {
            closeImageZoom();
        }
    };
}

function closeImageZoom() {
    const modal = document.getElementById('imageZoomModal');
    if (modal) {
        modal.style.display = 'none';
        // Reset image
        const img = document.getElementById('zoomedImage');
        const loading = modal.querySelector('.image-loading');
        img.style.display = 'none';
        loading.style.display = 'block';
        loading.textContent = 'Loading...';
    }
}

// Close zoom on Escape key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeImageZoom();
    }
});

// Real-time updates
let isUpdating = false;
let lastUpdateData = null;
let modalRefreshIntervals = new Map(); // Track refresh intervals for each open modal

// Function to render documents HTML from JSON data
function renderDocumentsHTML(data) {
    if (!data.success || !data.documents) {
        return '<div class="alert alert-danger">Failed to load documents</div>';
    }
    
    const student = data.student;
    const documents = data.documents;
    
    let html = '';
    
    // Student type badge with descriptions for all 4 states
    if (student.type === 'migrated') {
        html += `<div class="alert alert-info mb-3" style="background: linear-gradient(135deg, #a78bfa 0%, #8b5cf6 100%); color: white; border: none;">
            <i class="bi bi-upload"></i> 
            <strong>Migrated Student:</strong> This is an old student who was migrated to the system. They need to upload their documents to complete their profile.
        </div>`;
    } else if (student.type === 'reupload') {
        html += `<div class="alert alert-warning mb-3">
            <i class="bi bi-arrow-repeat"></i> 
            <strong>Re-upload Required:</strong> This student has finished at least one distribution cycle, or their documents were rejected. They need to upload/re-upload documents via the Upload Documents tab.
        </div>`;
    } else if (student.type === 'new_registration') {
        html += `<div class="alert alert-success mb-3">
            <i class="bi bi-check-circle"></i> 
            <strong>New Registration:</strong> This student just registered through the online system and submitted documents during registration. Documents are read-only unless rejected by admin.
        </div>`;
    } else {
        // Fallback for unknown types
        html += `<div class="alert alert-info mb-3">
            <i class="bi bi-info-circle"></i> 
            <strong>Student Documents</strong>
        </div>`;
    }
    
    // Start document grid
    html += '<div class="doc-grid">';
    
    // Define order for document cards (excluding grades which comes last)
    const docOrder = ['id_picture', 'eaf', 'letter_to_mayor', 'certificate_of_indigency'];
    
    let hasDocuments = false;
    
    // Render standard documents
    for (const key of docOrder) {
        const doc = documents[key];
        if (!doc) continue;
        
        html += '<div class="doc-card">';
        html += `<div class="doc-card-header">${doc.label}`;
        
        if (doc.status) {
            const statusBadge = doc.status === 'approved' ? 
                '<span class="badge bg-success ms-2">Approved</span>' : 
                '<span class="badge bg-warning ms-2">Pending</span>';
            html += statusBadge;
        }
        
        html += '</div>';
        
        if (doc.missing) {
            html += `<div class="doc-card-body">
                <div class="missing">
                    <div class="missing-icon"><i class="bi bi-file-earmark-x"></i></div>
                    <div class="text-muted">Not uploaded</div>
                </div>
            </div>`;
        } else {
            hasDocuments = true;
            
            // Document preview
            html += '<div class="doc-card-body" onclick="openDocumentViewer(\'' + doc.path + '\', \'' + doc.label + '\')">';
            
            if (doc.type === 'image') {
                html += `<img src="${doc.path}" class="doc-thumb" alt="${doc.label}">`;
            } else if (doc.type === 'pdf') {
                html += '<div class="doc-thumb-pdf"><i class="bi bi-file-pdf"></i></div>';
            } else {
                html += '<div class="doc-thumb-pdf"><i class="bi bi-file-earmark"></i></div>';
            }
            
            html += '</div>';
            
            // Metadata with OCR confidence if available
            html += '<div class="doc-meta">';
            html += `<span><i class="bi bi-hdd"></i> ${doc.size_formatted}</span>`;
            html += `<span><i class="bi bi-calendar"></i> ${doc.date_formatted}</span>`;
            
            // Add OCR confidence badge if available
            if (doc.ocr_data && doc.ocr_data.confidence) {
                const confidence = parseFloat(doc.ocr_data.confidence);
                const confidenceClass = confidence >= 80 ? 'success' : (confidence >= 60 ? 'warning' : 'danger');
                html += `<span class="badge bg-${confidenceClass} confidence-score">
                    <i class="bi bi-cpu"></i> ${confidence.toFixed(1)}% OCR
                </span>`;
            }
            
            // Add verification score badge if available
            if (doc.ocr_data && doc.ocr_data.verification_score) {
                const verifyScore = parseFloat(doc.ocr_data.verification_score);
                const verifyClass = verifyScore >= 80 ? 'success' : (verifyScore >= 60 ? 'warning' : 'danger');
                const verifyIcon = verifyScore >= 80 ? 'check-circle' : (verifyScore >= 60 ? 'exclamation-triangle' : 'x-circle');
                html += `<span class="badge bg-${verifyClass} confidence-score">
                    <i class="bi bi-${verifyIcon}"></i> ${verifyScore.toFixed(1)}%
                </span>`;
            }
            
            html += '</div>';
            
            // Actions
            html += '<div class="doc-actions">';
            html += `<button class="btn btn-sm btn-primary" onclick="openDocumentViewer('${doc.path}', '${doc.label}')">
                <i class="bi bi-eye"></i> View
            </button>`;
            html += `<a href="${doc.path}" download class="btn btn-sm btn-success">
                <i class="bi bi-download"></i> Download
            </a>`;
            html += '</div>';
            
            // Add validation button if verification data exists (for ALL documents)
            if (doc.ocr_data && doc.ocr_data.verification) {
                html += '<div class="doc-actions" style="border-top: 0; padding-top: 0;">';
                html += `<button class="btn btn-sm btn-info w-100" 
                    onclick="event.stopPropagation(); loadValidationData('${key}', '${student.id}'); showValidationModal();">
                    <i class="bi bi-shield-check"></i> View Validation
                </button>`;
                html += '</div>';
            }
        }
        
        html += '</div>'; // end doc-card
    }
    
    html += '</div>'; // end doc-grid
    
    if (!hasDocuments) {
        html += '<p class="text-muted mt-3">No documents uploaded.</p>';
    }
    
    // Add Academic Grades card (separate from grid for special styling)
    const gradesDoc = documents.grades;
    if (gradesDoc) {
        html += '<div class="doc-card mt-3">';
        html += '<div class="doc-card-header">Academic Grades';
        
        if (gradesDoc.status) {
            const statusBadge = gradesDoc.status === 'approved' ? 
                '<span class="badge bg-success ms-2">Approved</span>' : 
                '<span class="badge bg-warning ms-2">Pending</span>';
            html += statusBadge;
        }
        
        html += '</div>';
        
        if (gradesDoc.missing) {
            html += `<div class="doc-card-body">
                <div class="missing">
                    <div class="missing-icon"><i class="bi bi-file-earmark-x"></i></div>
                    <div class="text-muted">Not uploaded</div>
                </div>
            </div>`;
        } else {
            // Document preview
            html += '<div class="doc-card-body" onclick="openDocumentViewer(\'' + gradesDoc.path + '\', \'Academic Grades\')">';
            
            if (gradesDoc.type === 'image') {
                html += `<img src="${gradesDoc.path}" class="doc-thumb" alt="Academic Grades">`;
            } else if (gradesDoc.type === 'pdf') {
                html += '<div class="doc-thumb-pdf"><i class="bi bi-file-pdf"></i></div>';
            } else {
                html += '<div class="doc-thumb-pdf"><i class="bi bi-file-earmark"></i></div>';
            }
            
            html += '</div>';
            
            // Metadata with OCR confidence and verification score
            html += '<div class="doc-meta">';
            html += `<span><i class="bi bi-hdd"></i> ${gradesDoc.size_formatted}</span>`;
            html += `<span><i class="bi bi-calendar"></i> ${gradesDoc.date_formatted}</span>`;
            
            if (gradesDoc.ocr_data && gradesDoc.ocr_data.confidence) {
                const confidence = parseFloat(gradesDoc.ocr_data.confidence);
                const confidenceClass = confidence >= 80 ? 'success' : (confidence >= 60 ? 'warning' : 'danger');
                html += `<span class="badge bg-${confidenceClass} confidence-score">
                    <i class="bi bi-cpu"></i> ${confidence.toFixed(1)}% OCR
                </span>`;
            }
            
            // Add verification score badge if available
            if (gradesDoc.ocr_data && gradesDoc.ocr_data.verification_score) {
                const verifyScore = parseFloat(gradesDoc.ocr_data.verification_score);
                const verifyClass = verifyScore >= 80 ? 'success' : (verifyScore >= 60 ? 'warning' : 'danger');
                const verifyIcon = verifyScore >= 80 ? 'check-circle' : (verifyScore >= 60 ? 'exclamation-triangle' : 'x-circle');
                html += `<span class="badge bg-${verifyClass} confidence-score">
                    <i class="bi bi-${verifyIcon}"></i> ${verifyScore.toFixed(1)}%
                </span>`;
            }
            
            html += '</div>';
            
            // Actions
            html += '<div class="doc-actions">';
            html += `<button class="btn btn-sm btn-primary" onclick="openDocumentViewer('${gradesDoc.path}', 'Academic Grades')">
                <i class="bi bi-eye"></i> View
            </button>`;
            html += `<a href="${gradesDoc.path}" download class="btn btn-sm btn-success">
                <i class="bi bi-download"></i> Download
            </a>`;
            html += '</div>';
            
            // Validation button if OCR data exists (separate row for consistency)
            if (gradesDoc.ocr_data && gradesDoc.ocr_data.verification) {
                html += '<div class="doc-actions" style="border-top: 0; padding-top: 0;">';
                html += `<button class="btn btn-sm btn-info w-100" 
                    onclick="event.stopPropagation(); loadValidationData('grades', '${student.id}'); showValidationModal();">
                    <i class="bi bi-shield-check"></i> View Validation
                </button>`;
                html += '</div>';
            }
        }
        
        html += '</div>'; // end grades doc-card
    }
    
    return html;
}

// Function to refresh a specific modal's content
function refreshModalContent(modalEl, studentId, silent = false) {
    const modalBody = modalEl.querySelector('.modal-body');
    const modalFooter = modalEl.querySelector('.modal-footer');
    if (!modalBody) return;
    
    const originalContent = modalBody.innerHTML;
    
    // Only show loading indicator on initial load (not silent refreshes)
    if (!silent) {
        modalBody.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div><p class="mt-2">Loading latest documents...</p></div>';
    }
    
    // Add cache-busting parameter to prevent 404 caching
    const cacheBuster = new Date().getTime();
    const refreshUrl = 'get_applicant_details.php?student_id=' + encodeURIComponent(studentId) + '&_=' + cacheBuster;
    
    fetch(refreshUrl)
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            return response.json();
        })
        .then(data => {
            console.log('Received data from get_applicant_details.php:', data);
            
            if (data.success) {
                // Render HTML from JSON data
                const html = renderDocumentsHTML(data);
                
                // Only update if content actually changed (to avoid flickering)
                if (modalBody.innerHTML !== html) {
                    modalBody.innerHTML = html;
                    console.log('Modal content updated for student:', studentId);
                }
                
                // Update footer buttons based on completeness status
                if (modalFooter && data.student) {
                    updateModalFooterButtons(modalFooter, data.student.is_complete, studentId);
                }
            } else {
                if (!silent) {
                    modalBody.innerHTML = `<div class="alert alert-danger m-3">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <strong>Error:</strong> ${data.error || 'Unknown error'}
                    </div>`;
                }
                console.error('Failed to refresh modal content:', data.error || 'Unknown error');
            }
        })
        .catch(error => {
            if (!silent) {
                modalBody.innerHTML = `<div class="alert alert-danger m-3">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <strong>Error loading documents:</strong> ${error.message}
                    <br><small>Check browser console for details</small>
                </div>`;
            }
            console.error('Error refreshing modal content:', error);
        });
}

// Update modal footer buttons based on document completeness
function updateModalFooterButtons(modalFooter, isComplete, studentId) {
    if (!modalFooter) return;
    
    // Find the verify button and incomplete message
    const verifyForm = modalFooter.querySelector('.verify-form');
    const verifyBtn = verifyForm ? verifyForm.querySelector('button') : null;
    const incompleteSpan = modalFooter.querySelector('.incomplete-message');
    
    console.log('Updating footer buttons - Complete:', isComplete, 'Student:', studentId);
    console.log('Found elements:', {
        verifyForm: !!verifyForm,
        verifyBtn: !!verifyBtn,
        incompleteSpan: !!incompleteSpan
    });
    
    if (isComplete) {
        // Documents are complete - enable Verify button
        if (verifyBtn) {
            verifyBtn.disabled = false;
            verifyBtn.removeAttribute('title');
            console.log('✅ Enabled Verify button');
        }
        
        // Hide "Incomplete documents" message
        if (incompleteSpan) {
            incompleteSpan.style.display = 'none';
            console.log('✅ Hidden incomplete message');
        }
        
        console.log('✅ Documents complete - Verify button enabled for student:', studentId);
    } else {
        // Documents are incomplete - disable Verify button
        if (verifyBtn) {
            verifyBtn.disabled = true;
            verifyBtn.setAttribute('title', 'Please ensure all required documents are complete');
            console.log('⚠️ Disabled Verify button');
        }
        
        // Show "Incomplete documents" message
        if (incompleteSpan) {
            incompleteSpan.style.display = 'inline';
            console.log('⚠️ Showing incomplete message');
        }
        
        console.log('⚠️ Documents incomplete - Verify button disabled for student:', studentId);
    }
}

// Function to attach refresh listeners to student document modals
function attachModalRefreshListeners() {
    document.querySelectorAll('.modal').forEach(function(modalEl) {
        // Only target student document modals (they have IDs like "modal123" or "modal20241030-1-001")
        // Skip migration modal, validation modal, etc.
        if (modalEl.id && modalEl.id.startsWith('modal') && 
            !['migrationModal', 'passwordConfirmModal', 'validationModal', 'archiveModal', 'documentViewerModal', 'imageZoomModal'].includes(modalEl.id)) {
            // Skip if listener already attached
            if (modalEl.hasAttribute('data-refresh-listener')) return;
            modalEl.setAttribute('data-refresh-listener', 'true');
            
            console.log('Attaching refresh listener to:', modalEl.id);
            
            // When modal opens
            modalEl.addEventListener('shown.bs.modal', function() {
                const studentId = this.id.replace('modal', '');
                const modalBody = this.querySelector('.modal-body');
                
                console.log('Modal opened for student:', studentId);
                
                if (modalBody && studentId) {
                    // Initial refresh (with loading indicator)
                    refreshModalContent(this, studentId, false);
                    
                    // Set up auto-refresh every 3 seconds while modal is open
                    const intervalId = setInterval(() => {
                        // Only refresh if modal is still visible
                        if (this.classList.contains('show')) {
                            refreshModalContent(this, studentId, true); // Silent refresh
                        }
                    }, 3000); // Refresh every 3 seconds
                    
                    // Store interval ID
                    modalRefreshIntervals.set(this.id, intervalId);
                }
            });
            
            // When modal closes, stop auto-refresh
            modalEl.addEventListener('hidden.bs.modal', function() {
                const intervalId = modalRefreshIntervals.get(this.id);
                if (intervalId) {
                    clearInterval(intervalId);
                    modalRefreshIntervals.delete(this.id);
                    console.log('Stopped auto-refresh for modal:', this.id);
                }
            });
        }
    });
}

function updateTableData() {
    if (isUpdating) return;
    isUpdating = true;

    const currentUrl = new URL(window.location);
    const params = new URLSearchParams(currentUrl.search);
    params.set('ajax', '1');

    fetch(window.location.pathname + '?' + params.toString())
        .then(response => response.text())
        .then(data => {
            if (data !== lastUpdateData) {
                // Parse the response to extract content
                const tempDiv = document.createElement('div');
                tempDiv.innerHTML = data;
                
                // Update section header with total count
                const newHeader = tempDiv.querySelector('.section-header');
                const currentHeader = document.querySelector('.section-header');
                if (newHeader && currentHeader) {
                    currentHeader.innerHTML = newHeader.innerHTML;
                }

                // Update table content
                const newTable = tempDiv.querySelector('table');
                const currentTable = document.querySelector('#tableWrapper table');
                if (newTable && currentTable && newTable.innerHTML !== currentTable.innerHTML) {
                    currentTable.innerHTML = newTable.innerHTML;
                }

                // Update pagination
                const newPagination = tempDiv.querySelector('nav[aria-label="Table pagination"]');
                const currentPagination = document.querySelector('#pagination nav[aria-label="Table pagination"]');
                if (newPagination && currentPagination) {
                    currentPagination.innerHTML = newPagination.innerHTML;
                } else if (newPagination && !currentPagination) {
                    document.getElementById('pagination').innerHTML = newPagination.outerHTML;
                } else if (!newPagination && currentPagination) {
                    document.getElementById('pagination').innerHTML = '';
                }

                lastUpdateData = data;
                
                // Attach refresh listeners to any new modals that were added
                attachModalRefreshListeners();
            }
        })
        .catch(error => {
            console.log('Update failed:', error);
        })
        .finally(() => {
        isUpdating = false;
        // Slow down polling to avoid racing with migrations and reduce load
        setTimeout(updateTableData, 3000); // Update every 3s
        });
}

// Start real-time updates when page loads
document.addEventListener('DOMContentLoaded', function() {
    // Move all modals to be direct children of body to avoid stacking context issues
    // But do this AFTER a delay to let sidebar.js and Bootstrap initialize first
    setTimeout(() => {
        document.querySelectorAll('.modal').forEach(function(modalEl){
            if (modalEl.parentElement !== document.body) {
                document.body.appendChild(modalEl);
            }
        });
    }, 100);
    
    // Dynamic modal stacking: raise z-index incrementally for each shown modal
    (function initDynamicModalStacking(){
        const baseZ = 1050; // Bootstrap default
        let seq = 0;

        function updateStack(){
            // Sort open modals by the sequence they were opened
            const openModals = Array.from(document.querySelectorAll('.modal.show'))
                .sort((a,b) => (parseInt(a.dataset.stackIndex||'0')||0) - (parseInt(b.dataset.stackIndex||'0')||0));
            const backdrops = Array.from(document.querySelectorAll('.modal-backdrop.show'));

            // Normalize all backdrops to base first
            backdrops.forEach((bd, i) => { bd.style.zIndex = (baseZ + i*20).toString(); });

            openModals.forEach((modalEl, idx) => {
                const z = baseZ + idx * 20;
                modalEl.style.zIndex = (z + 10).toString();
                const bd = backdrops[idx];
                if (bd) bd.style.zIndex = z.toString();
            });
        }

        document.addEventListener('shown.bs.modal', function(e){
            const el = e.target;
            if (el && el.classList && el.classList.contains('modal')) {
                el.dataset.stackIndex = (++seq).toString();
                // Allow Bootstrap to insert backdrop before stacking
                setTimeout(updateStack, 10);
            }
        });

        document.addEventListener('hidden.bs.modal', function(e){
            const el = e.target;
            if (el && el.dataset) delete el.dataset.stackIndex;
            setTimeout(updateStack, 10);
        });

        // Initial pass in case any modal is already open
        setTimeout(updateStack, 50);
    })();

    // Attach refresh listeners to all initial modals (after stacking init)
    setTimeout(attachModalRefreshListeners, 200);
    
    setTimeout(updateTableData, 300);
    
    // CRITICAL: Global backdrop cleanup on page load
    // Remove any stale backdrops that might exist from previous page state
    setTimeout(() => {
        const staleBackdrops = document.querySelectorAll('.modal-backdrop');
        if (staleBackdrops.length > 0) {
            console.log('Found', staleBackdrops.length, 'stale backdrop(s) on page load - removing all');
            staleBackdrops.forEach(bd => bd.remove());
        }
        // Also remove modal-open class from body if no modals are actually open
        const openModals = document.querySelectorAll('.modal.show');
        if (openModals.length === 0 && document.body.classList.contains('modal-open')) {
            console.log('Removing modal-open class from body - no modals open');
            document.body.classList.remove('modal-open');
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
        }
    }, 100);
    
    // Fetch fresh CSRF token for migration modal IMMEDIATELY on page load
    // This ensures the token is ready when the modal opens
    const migrationModalEl = document.getElementById('migrationModal');
    let migrationTokenReady = false;

    async function refreshMigrationToken(context = 'manual') {
        if (!migrationModalEl) { return null; }
        console.log(`[${context}] Fetching CSRF token for migration modal`);
        try {
            const response = await fetch('get_csrf_token.php?action=csv_migration', { credentials: 'same-origin' });
            const data = await response.json();
            if (data.success && data.token) {
                const tokenInputs = migrationModalEl.querySelectorAll('input[name="csrf_token"]');
                tokenInputs.forEach(input => {
                    input.value = data.token;
                });
                migrationTokenReady = true;
                console.log('Migration CSRF token updated:', data.token.substring(0, 20) + '...');
                return data.token;
            }
            console.error('Failed to refresh migration CSRF token:', data);
            throw new Error('Token endpoint did not return a token');
        } catch (error) {
            console.error('Error fetching migration CSRF token:', error);
            throw error;
        }
    }

    if (migrationModalEl) {
        // Fetch token immediately
        refreshMigrationToken('initial');

        // Also refresh token every time modal is shown (in case page was idle and token expired)
        migrationModalEl.addEventListener('show.bs.modal', function() {
            refreshMigrationToken('modal-open');
        });

        // Intercept form submission to ensure token is ready
        const uploadForm = document.getElementById('migrationUploadForm');
        if (uploadForm) {
            uploadForm.addEventListener('submit', function(e) {
                const tokenInput = uploadForm.querySelector('input[name="csrf_token"]');
                console.log('Form submission intercepted');
                console.log('Token input found:', !!tokenInput);
                console.log('Token value:', tokenInput ? tokenInput.value.substring(0, 20) + '...' : 'N/A');
                
                if (!tokenInput || !tokenInput.value || tokenInput.value.trim() === '' || !migrationTokenReady) {
                    e.preventDefault();
                    alert('Security token not ready. Please wait a moment and try again.');
                    console.error('Form submission blocked - CSRF token not ready');
                    return false;
                }
                console.log('Form submitting with token:', tokenInput.value.substring(0, 20) + '...');
                // Allow form to submit normally (will cause page reload)
            });
        } else {
            console.error('Migration upload form not found!');
        }
    } else {
        console.error('Migration modal element not found during init!');
    }
    
    // Auto-open migration modal ONLY if preview exists (not just error results)
    <?php if (!empty($_SESSION['migration_preview'])): ?>
    console.log('Migration preview detected - attempting to open modal');
    console.log('Preview exists:', true);
    
    // Wait a bit longer for page to fully settle before opening modal
    setTimeout(() => {
        const migrationModalForOpen = document.getElementById('migrationModal');
        if (migrationModalForOpen) {
            console.log('Migration modal element found, opening modal...');
            
            // CRITICAL FIX: Aggressive backdrop cleanup before opening
            const existingBackdrops = document.querySelectorAll('.modal-backdrop');
            if (existingBackdrops.length > 0) {
                console.log('Removing', existingBackdrops.length, 'existing backdrop(s) before opening migration modal');
                existingBackdrops.forEach(backdrop => backdrop.remove());
            }
            
            // Reset body classes
            document.body.classList.remove('modal-open');
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
            
            // Check if modal is already open
            if (migrationModalForOpen.classList.contains('show')) {
                console.log('Modal already open, skipping show()');
            } else {
                // Get or create Bootstrap modal instance
                let modal = bootstrap.Modal.getInstance(migrationModalForOpen);
                if (!modal) {
                    modal = new bootstrap.Modal(migrationModalForOpen);
                    console.log('Created new Bootstrap modal instance');
                } else {
                    console.log('Using existing Bootstrap modal instance');
                }
                
                // Listen for modal shown event to insert alert AFTER modal is fully visible
                migrationModalForOpen.addEventListener('shown.bs.modal', function onModalShown() {
                    console.log('Modal fully shown - checking for pending migration result');
                    if (window.pendingMigrationResult) {
                        console.log('Showing pending migration alert:', window.pendingMigrationResult);
                        showMigrationAlert(window.pendingMigrationResult);
                        window.pendingMigrationResult = null;
                    }
                    
                    // Final backdrop check after modal is shown
                    setTimeout(() => {
                        const backdrops = document.querySelectorAll('.modal-backdrop');
                        console.log('After modal shown - Number of backdrops:', backdrops.length);
                        if (backdrops.length > 1) {
                            console.warn('Multiple backdrops detected! Removing extras...');
                            // Keep only the last backdrop (newest) and ensure it's behind the modal
                            for (let i = 0; i < backdrops.length - 1; i++) {
                                backdrops[i].remove();
                                console.log('Removed extra backdrop', i);
                            }
                            // Ensure remaining backdrop has correct z-index
                            const lastBackdrop = backdrops[backdrops.length - 1];
                            if (lastBackdrop) {
                                const modalZIndex = parseInt(window.getComputedStyle(migrationModalForOpen).zIndex) || 1055;
                                lastBackdrop.style.zIndex = (modalZIndex - 5).toString();
                                console.log('Set backdrop z-index to', lastBackdrop.style.zIndex, '(modal z-index:', modalZIndex, ')');
                            }
                        } else if (backdrops.length === 1) {
                            // Ensure single backdrop is behind modal
                            const backdrop = backdrops[0];
                            const modalZIndex = parseInt(window.getComputedStyle(migrationModalForOpen).zIndex) || 1055;
                            backdrop.style.zIndex = (modalZIndex - 5).toString();
                            console.log('Single backdrop z-index set to', backdrop.style.zIndex);
                        }
                    }, 100);
                }, { once: true });
                
                modal.show();
                console.log('Modal.show() called');
            }
        } else {
            console.error('Migration modal element not found!');
        }
    }, 200); // Delay opening to ensure page is fully loaded
    <?php endif; ?>

    // Migration UI helpers
    const csvInput = document.getElementById('csvFileInput');
    const csvFilename = document.getElementById('csvFilename');
    if (csvInput && csvFilename) {
        csvInput.addEventListener('change', () => {
            const file = csvInput.files && csvInput.files[0];
            csvFilename.textContent = file ? `Selected: ${file.name} (${Math.round(file.size/1024)} KB)` : '';
        });
    }

    function updateSelectedCounter() {
        const counter = document.getElementById('selectedCounter');
        if (!counter) return;
        const checks = document.querySelectorAll('.migration-preview .row-select');
        let n = 0; checks.forEach(c => { if (c.checked) n++; });
        counter.textContent = `${n} selected`;
    }

    // Initialize selection counter and controls if preview table is present
    const previewTable = document.querySelector('.migration-preview');
    if (previewTable) {
        document.querySelectorAll('.migration-preview .row-select').forEach(cb => cb.addEventListener('change', updateSelectedCounter));
        updateSelectedCounter();

        const selectAllBtn = document.getElementById('selectAllValidBtn');
        if (selectAllBtn) {
            selectAllBtn.addEventListener('click', () => {
                document.querySelectorAll('.migration-preview tbody tr').forEach(tr => {
                    const hasConflict = tr.getAttribute('data-has-conflict') === '1';
                    const cb = tr.querySelector('.row-select');
                    if (cb && !hasConflict) cb.checked = true;
                });
                updateSelectedCounter();
            });
        }

        const conflictToggle = document.getElementById('showConflictsOnly');
        if (conflictToggle) {
            conflictToggle.addEventListener('change', () => {
                const only = conflictToggle.checked;
                document.querySelectorAll('.migration-preview tbody tr').forEach(tr => {
                    const hasConflict = tr.getAttribute('data-has-conflict') === '1';
                    tr.style.display = (!only || hasConflict) ? '' : 'none';
                });
            });
        }

        // Horizontal scroll helpers
        const scrollWrap = document.querySelector('.migration-preview');
        const scrollStartBtn = document.getElementById('scrollStartBtn');
        const scrollConflictsBtn = document.getElementById('scrollConflictsBtn');
        function smoothScrollTo(x) {
            if (!scrollWrap) return;
            scrollWrap.scrollTo({ left: x, behavior: 'smooth' });
        }
        if (scrollStartBtn) scrollStartBtn.addEventListener('click', () => smoothScrollTo(0));
        if (scrollConflictsBtn) scrollConflictsBtn.addEventListener('click', () => smoothScrollTo(scrollWrap.scrollWidth));
    }

    // Cancel migration on modal close with confirmation guard
    const migrationModalEl2 = document.getElementById('migrationModal');
    let _isSubmittingMigration = false;
    let _migrationCloseConfirmed = false;
    let _migrationProgrammaticHide = false;
    // Note: Form submission is now handled by password confirmation modal
    if (migrationModalEl2) {
        migrationModalEl2.addEventListener('hide.bs.modal', function (e) {
            if (_isSubmittingMigration) { return; }

            if (_migrationProgrammaticHide) {
                _migrationProgrammaticHide = false;
                return;
            }

            if (_migrationCloseConfirmed) {
                // Reset flag so future closes will still prompt
                _migrationCloseConfirmed = false;
                return;
            }

            const hasPreviewData = !!document.querySelector('.migration-preview');
            if (!hasPreviewData) { return; }

            const trigger = e.relatedTarget;
            const triggeredByCloseButton = trigger && trigger.id === 'migrationCloseBtn';
            const triggeredByDismissControl = trigger && trigger.getAttribute && trigger.getAttribute('data-bs-dismiss') === 'modal';
            const triggeredByBackdropOrKey = !trigger;

            if (triggeredByCloseButton || triggeredByDismissControl || triggeredByBackdropOrKey) {
                if (!window.confirm('Closing will discard the uploaded CSV preview. Clear it so you can upload a new file?')) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    return;
                }

                _migrationCloseConfirmed = true;
                console.log('Migration modal close confirmed - clearing session');
                submitMigrationCancel({ reloadOnFailure: true });
            }
        });

        migrationModalEl2.addEventListener('hidden.bs.modal', function () {
            _migrationCloseConfirmed = false;
            _migrationProgrammaticHide = false;
        });
    }

    // Password confirmation for migration
    const confirmMigrateBtn = document.getElementById('confirmMigrateBtn');
    const passwordModal = document.getElementById('passwordConfirmModal');
    const adminPasswordInput = document.getElementById('adminPasswordInput');
    const confirmPasswordBtn = document.getElementById('confirmPasswordBtn');
    const passwordError = document.getElementById('passwordError');
    let pendingFormData = null;

    // Debug logging
    console.log('Elements found:', {
        confirmMigrateBtn: !!confirmMigrateBtn,
        passwordModal: !!passwordModal,
        adminPasswordInput: !!adminPasswordInput,
        confirmPasswordBtn: !!confirmPasswordBtn,
        passwordError: !!passwordError
    });

    // Show migration result alerts (only on GET). On POST (fetch), don't clear result yet.
    <?php if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_SESSION['migration_result'])): ?>
        const result = <?= json_encode($_SESSION['migration_result']) ?>;
        console.log('Migration result found:', result);
        
        // Check if there's also a preview - if so, modal will auto-open and show the alert
        // If no preview, show the alert on the main page immediately
        <?php if (!empty($_SESSION['migration_preview'])): ?>
            // Modal will auto-open, save result to show inside modal
            console.log('Preview exists - alert will show inside modal');
            window.pendingMigrationResult = result;
        <?php else: ?>
            // No preview - show alert on main page immediately
            console.log('No preview - showing alert on main page');
            showMigrationAlert(result);
        <?php endif; ?>
        
        <?php unset($_SESSION['migration_result']); ?>
    <?php endif; ?>

    // Intercept confirm migration button click
    if (confirmMigrateBtn) {
        console.log('Migration button found, adding event listener');
        confirmMigrateBtn.addEventListener('click', function(e) {
            console.log('Migration button clicked');
            e.preventDefault();
            
            // Check if any rows are selected
            const selectedCheckboxes = document.querySelectorAll('.migration-preview .row-select:checked');
            console.log('Selected checkboxes:', selectedCheckboxes.length);
            if (selectedCheckboxes.length === 0) {
                alert('Please select at least one row to migrate.');
                return;
            }
            
            // Collect form data
            const form = document.getElementById('migrationForm');
            if (!form) {
                console.error('Migration form not found');
                alert('Error: Migration form not found. Please refresh the page.');
                return;
            }
            console.log('Migration form found:', form);
            
            pendingFormData = new FormData(form);
            pendingFormData.set('migration_action', 'confirm');
            
            // Store selected count for later use
            window.migrationSelectedCount = selectedCheckboxes.length;
            
            // Show password modal
            if (adminPasswordInput) adminPasswordInput.value = '';
            if (passwordError) passwordError.classList.add('d-none');
            
            if (passwordModal) {
                console.log('Showing password modal');
                const passwordModalInstance = new bootstrap.Modal(passwordModal);
                passwordModalInstance.show();
            } else {
                console.error('Password modal not found');
                alert('Error: Password confirmation modal not found. Please refresh the page.');
            }
        });
    } else {
        console.error('Confirm migrate button not found');
    }

    // Handle password confirmation
    if (confirmPasswordBtn) {
        confirmPasswordBtn.addEventListener('click', function() {
            const password = adminPasswordInput ? adminPasswordInput.value.trim() : '';
            if (!password) {
                showPasswordError('Please enter your password.');
                return;
            }

            // Add password to form data
            if (pendingFormData) {
                pendingFormData.set('admin_password', password);
            } else {
                console.error('No pending form data');
                showPasswordError('Error: Please try again.');
                return;
            }
            
            // Show loading state on password button
            const originalText = confirmPasswordBtn.innerHTML;
            confirmPasswordBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Verifying...';
            confirmPasswordBtn.disabled = true;
            
            // Submit the form
            fetch(window.location.href, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' },
                body: pendingFormData
            }).then(response => {
                console.log('Migration response received:', response.status);
                if (response.ok) {
                    // Close password modal
                    if (passwordModal && bootstrap.Modal.getInstance(passwordModal)) {
                        bootstrap.Modal.getInstance(passwordModal).hide();
                    }
                    
                    // Show migration loading in original modal
                    _isSubmittingMigration = true;
                    const btn = document.getElementById('confirmMigrateBtn');
                    if (btn) {
                        btn.disabled = true;
                        const btnText = btn.querySelector('.migration-btn-text');
                        const btnLoading = btn.querySelector('.migration-btn-loading');
                        
                        if (btnText) btnText.classList.add('d-none');
                        if (btnLoading) btnLoading.classList.remove('d-none');
                        
                        const total = window.migrationSelectedCount || 1;
                        const totalEl = document.getElementById('migrationTotal');
                        if (totalEl) totalEl.textContent = total;
                        
                        // Simulate progress
                        let progress = 0;
                        const progressEl = document.getElementById('migrationProgress');
                        if (progressEl) {
                            const interval = setInterval(() => {
                                if (progress < total) {
                                    progress++;
                                    progressEl.textContent = progress;
                                } else {
                                    clearInterval(interval);
                                    console.log('Migration progress simulation complete');
                                }
                            }, 100);
                        }
                    }
                    
                    // Try to parse JSON result and show alert immediately
                    response.clone().json().then(data => {
                        try { showMigrationAlert(data); } catch (_) { /* ignore */ }
                    }).catch(() => { /* not JSON, fallback to reload */ });

                    // Reload page to show results after migration completes (fallback)
                    setTimeout(() => {
                        console.log('Reloading page to show migration results');
                        window.location.reload();
                    }, 2000);
                } else {
                    console.error('Migration request failed:', response.status);
                    throw new Error('Migration request failed with status: ' + response.status);
                }
            }).catch(error => {
                console.error('Migration error:', error);
                showPasswordError(error.message || 'Migration failed. Please try again.');
                confirmPasswordBtn.innerHTML = originalText;
                confirmPasswordBtn.disabled = false;
            });
        });
        
        // Enter key in password field
        if (adminPasswordInput) {
            adminPasswordInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    confirmPasswordBtn.click();
                }
            });
        }
    }

    function showPasswordError(message) {
        console.log('Password error:', message);
        if (passwordError && passwordError.querySelector('span')) {
            passwordError.querySelector('span').textContent = message;
            passwordError.classList.remove('d-none');
        } else {
            console.error('Password error element not found');
            alert('Error: ' + message);
        }
    }

    function showMigrationAlert(result) {
        console.log('Showing migration alert:', result);
        const alertContainer = document.createElement('div');
        alertContainer.className = 'container-fluid mt-3';
        
        let alertType, icon, title;
        if (result.status === 'success') {
            alertType = 'alert-success';
            icon = 'bi-check-circle-fill';
            title = 'Migration Successful';
        } else if (result.status === 'warning') {
            alertType = 'alert-warning';
            icon = 'bi-exclamation-triangle-fill';
            title = 'Migration Warning';
        } else {
            alertType = 'alert-danger';
            icon = 'bi-x-circle-fill';
            title = 'Migration Failed';
        }
        
        let message = '';
        if (result.inserted > 0) {
            message += `<strong>${result.inserted}</strong> student(s) successfully migrated.`;
        }
        if (result.errors && result.errors.length > 0) {
            if (message) message += '<br>';
            message += '<strong>Errors:</strong><ul class="mb-0 mt-2">';
            result.errors.forEach(error => {
                message += `<li>${error}</li>`;
            });
            message += '</ul>';
        }
        
        alertContainer.innerHTML = `
            <div class="alert ${alertType} alert-dismissible fade show" role="alert">
                <i class="bi ${icon} me-2"></i>
                <strong>${title}</strong><br>
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        `;
        
        // Try to insert inside migration modal first (if modal is open), fallback to main page header
        const migrationModal = document.getElementById('migrationModal');
        const isModalOpen = migrationModal && migrationModal.classList.contains('show');
        const modalBody = migrationModal ? migrationModal.querySelector('.modal-body') : null;
        
        if (isModalOpen && modalBody && modalBody.firstChild) {
            // Insert at the top of modal body
            modalBody.insertBefore(alertContainer, modalBody.firstChild);
            console.log('Migration alert inserted in modal body');
            
            // Auto dismiss after 10 seconds for success
            if (result.status === 'success') {
                setTimeout(() => {
                    const alert = alertContainer.querySelector('.alert');
                    if (alert) {
                        bootstrap.Alert.getOrCreateInstance(alert).close();
                    }
                }, 10000);
                
                        // Clear the migration session and close modal after showing success
                setTimeout(() => {
                    submitMigrationCancel({ silent: true, reloadOnFailure: true });
                    if (migrationModal && bootstrap.Modal.getInstance(migrationModal)) {
                        _migrationProgrammaticHide = true;
                        bootstrap.Modal.getInstance(migrationModal).hide();
                    }
                }, 3000);
            }
        } else {
            // Modal not open - Insert after main page header
            const header = document.querySelector('.section-header');
            if (header && header.parentNode) {
                header.parentNode.insertBefore(alertContainer, header.nextSibling);
                console.log('Migration alert inserted after main page header');
                
                // Auto dismiss errors after 15 seconds on main page
                if (result.status !== 'success') {
                    setTimeout(() => {
                        const alert = alertContainer.querySelector('.alert');
                        if (alert) {
                            bootstrap.Alert.getOrCreateInstance(alert).close();
                        }
                    }, 15000);
                }
            } else {
                console.error('Could not find insertion point for alert (neither modal body nor page header)');
            }
        }
    }

    function submitMigrationCancel(options = {}) {
        const { silent = false, reloadOnFailure = false, retrying = false } = options;
        const form = document.getElementById('migrationCancelForm');
        if (!form) { return; }

        const tokenInput = form.querySelector('input[name="csrf_token"]');
        if ((!tokenInput || !tokenInput.value) && !retrying) {
            console.warn('Cancel CSRF token missing - attempting refresh before retry');
            refreshMigrationToken('cancel-refresh').then(() => {
                submitMigrationCancel({ silent, reloadOnFailure, retrying: true });
            }).catch(err => {
                console.error('Token refresh failed for cancel action:', err);
                if (reloadOnFailure) {
                    if (typeof form.requestSubmit === 'function') {
                        form.requestSubmit();
                    } else {
                        form.submit();
                    }
                }
            });
            return;
        }

        const formData = new FormData(form);

        fetch(window.location.pathname, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(response => {
            if (!response.ok && response.status !== 204) {
                throw new Error(`Cancel request failed with status ${response.status}`);
            }
            if (!silent) {
                console.log('Migration session cleared via cancel form');
            }
        }).catch(err => {
            console.error('Cancel form error:', err);
            if (reloadOnFailure) {
                // Fallback to standard form submission to guarantee cleanup
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    form.submit();
                }
            }
        });
    }
});
</script>
<style>
/* Custom badge colors for applicant types */
.bg-purple {
    background-color: #7c3aed !important;
    color: white !important;
}

.badge.bg-purple {
    background-color: #7c3aed !important;
}

/* Archive Modal Responsive Design */
@media (min-width: 768px) {
    #archiveModal .modal-dialog {
        max-width: 600px;
    }
}

@media (max-width: 767.98px) {
    #archiveModal .modal-body {
        padding: 1rem;
    }
    
    #archiveModal .form-control,
    #archiveModal .form-select,
    #archiveModal textarea {
        font-size: 16px; /* Prevent zoom on iOS */
    }
    
    #archiveModal .modal-footer {
        flex-direction: column;
        gap: 0.5rem;
    }
    
    #archiveModal .modal-footer .btn {
        width: 100%;
    }
    
    #archiveModal .alert {
        font-size: 0.9rem;
        padding: 0.75rem;
    }
    
    #archiveModal .alert ul {
        padding-left: 1.25rem;
    }
}

/* Password Confirmation Modal - Wider on Desktop */
@media (min-width: 768px) {
    #passwordConfirmModal .modal-dialog {
        max-width: 500px;
    }
}

@media (max-width: 767.98px) {
    #passwordConfirmModal .modal-body {
        padding: 1rem;
    }
    
    #passwordConfirmModal .form-control {
        font-size: 16px; /* Prevent zoom on iOS */
    }
    
    #passwordConfirmModal .modal-footer {
        flex-direction: column-reverse;
        gap: 0.5rem;
    }
    
    #passwordConfirmModal .modal-footer .btn {
        width: 100%;
    }
}

/* Tablet specific adjustments */
@media (min-width: 768px) and (max-width: 991.98px) {
    #archiveModal .modal-dialog,
    #passwordConfirmModal .modal-dialog {
        max-width: 85%;
    }
}

/* ------------------ Mobile Responsiveness Enhancements ------------------ */
@media (max-width: 575.98px) {
    .modal-dialog { margin: 8px auto; }
    #rejectDocumentsModal .modal-dialog { max-width: 100%; }
    #rejectDocumentsModal .card { border: 1px solid #eee; box-shadow: none; }
    #rejectDocumentsModal .card-body { padding: 10px 12px; }
    #rejectDocumentsModal .form-check-label { font-size: .9rem; }
    #rejectDocumentsModal .reject-reason-container input { font-size: .85rem; }
    /* Make content flex so body can scroll while header/footer stay visible */
    #rejectDocumentsModal .modal-content { display: flex; flex-direction: column; max-height: 100vh; }
    #rejectDocumentsModal .modal-header { flex: 0 0 auto; }
    #rejectDocumentsModal .modal-body { flex: 1 1 auto; overflow-y: auto; }
    #rejectDocumentsModal .modal-footer { flex: 0 0 auto; position: sticky; bottom: 0; background: #fff; box-shadow: 0 -2px 6px rgba(0,0,0,.08); }
    .doc-grid { grid-template-columns: 1fr !important; }
    .doc-card-body { min-height: 140px; }
    .doc-actions .btn { padding: 4px 6px; font-size: .75rem; }
    .doc-meta { font-size: .65rem; }
    .modal-title { font-size: 1rem; }
    .badge { font-size: .55rem; }
        .doc-viewer-toolbar { flex-wrap: wrap; gap: 8px; }
        .doc-viewer-toolbar .btn { padding: 6px 10px; font-size: .85rem; }
}
/* CSV Migration modal: compact mobile layout similar to detail modals */
@media (max-width: 576px) {
    #migrationModal .modal-dialog { max-width: 420px; width: 90%; margin: 1rem auto; }
    #migrationModal .modal-content { border-radius: 12px; }
    #migrationModal .modal-header, 
    #migrationModal .modal-footer { padding-top: .55rem; padding-bottom: .55rem; }
    #migrationModal .modal-body { padding: .75rem; }
    #migrationModal .alert { padding: .5rem .6rem; margin-bottom: .5rem; }
    #migrationModal .alert .small, 
    #migrationModal .form-text { font-size: .75rem; }
    #migrationModal h5.modal-title { font-size: 1rem; }
    #migrationModal .preview-scroll-controls { display: none !important; }
    #migrationModal .migration-preview { max-height: 55vh; }
    #migrationModal .migration-preview .preview-table tbody tr { gap: 6px 8px; padding: 10px; }
    #migrationModal .migration-preview .preview-table tbody td { padding: 4px 0; font-size: .9rem; }
    #migrationModal .sticky-confirm { padding: .5rem 0; }
}

/* Compact view for very small heights (mobile browser chrome visible) */
@media (max-height: 640px) and (max-width: 575.98px) {
    #rejectDocumentsModal .modal-content { max-height: 100vh; }
    #rejectDocumentsModal .modal-body { max-height: none; }
}

/* Improve touch targets */
#rejectDocumentsModal .form-check-input { width: 1.2rem; height: 1.2rem; }
#rejectDocumentsModal .form-check { display: flex; align-items: center; gap: 8px; }

/* Auto-grow textarea pattern for future (if changed from input) */
.auto-grow { resize: none; overflow:hidden; }

/* Modal stacking: use Bootstrap defaults; dynamic z-index handled via JS below. */
/* Avoid hardcoding z-index here to prevent backdrop conflicts when stacking modals. */


/* Reject Documents Modal: rely on dynamic stacking (no fixed z-index here). */

/* Document grid */
.doc-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 16px; }
.doc-card { 
    border: 1px solid #e5e7eb; 
    border-radius: 10px; 
    background: #fff; 
    display: flex; 
    flex-direction: column;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    transition: box-shadow 0.2s, transform 0.2s;
}
.doc-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    transform: translateY(-2px);
}
.doc-card-header { 
    font-weight: 600; 
    font-size: 0.95rem;
    padding: 12px 14px; 
    border-bottom: 1px solid #f0f0f0; 
    background: linear-gradient(to bottom, #f8f9fa, #fff);
}
.doc-card-header .badge {
    font-size: 0.75rem;
    font-weight: 500;
    padding: 0.35em 0.6em;
}
.doc-card-body { 
    padding: 10px; 
    display: flex; 
    align-items: center; 
    justify-content: center; 
    min-height: 180px; 
    cursor: zoom-in; 
    background: #fafafa;
    border-radius: 4px;
    margin: 8px;
}
.doc-card-body:hover {
    background: #f5f5f5;
}
.doc-thumb { 
    max-width: 100%; 
    max-height: 165px; 
    border-radius: 6px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}
.doc-thumb-pdf { 
    font-size: 56px; 
    color: #dc3545; 
    display: flex; 
    align-items: center; 
    justify-content: center; 
    height: 165px; 
    width: 100%; 
}
.doc-meta { 
    display: flex; 
    justify-content: space-between; 
    gap: 10px; 
    padding: 8px 14px; 
    color: #6b7280; 
    font-size: 0.75rem; 
    border-top: 1px dashed #eee; 
    background: #fafbfc;
}
.doc-meta i {
    opacity: 0.7;
}
.doc-actions { 
    display: flex; 
    flex-wrap: wrap; 
    gap: 6px; 
    padding: 10px 12px; 
    border-top: 1px solid #f0f0f0; 
}
.doc-actions .btn { 
    flex: 1 1 auto; 
    min-width: 40px; 
    font-size: 0.8rem; 
    padding: 6px 10px; 
}
.doc-actions .w-100 {
    flex: 1 1 100%;
}
.doc-card-missing .missing { 
    background: #fff7e6; 
    color: #8a6d3b; 
    min-height: 180px; 
    display: flex; 
    flex-direction: column; 
    align-items: center; 
    justify-content: center;
    border-radius: 8px;
    margin: 8px;
    border: 2px dashed #ffc107;
}
.doc-card-missing .missing-icon { 
    font-size: 36px; 
    margin-bottom: 8px; 
    opacity: 0.6;
}

/* Fullscreen viewer - Must appear ABOVE modals (z-index 200000+) */
.doc-viewer-backdrop { 
    position: fixed !important; 
    inset: 0 !important; 
    background: rgba(0,0,0,0.85) !important; 
    display: none; 
    z-index: 999999 !important; 
}
.doc-viewer-backdrop.show {
    display: block !important;
    z-index: 99999 !important;
}
.doc-viewer { 
    position: absolute; 
    top: 50%; 
    left: 50%; 
    transform: translate(-50%, -50%); 
    width: 95vw; 
    max-width: 1280px; 
    height: 85vh; 
    background: #111; 
    border-radius: 8px; 
    overflow: hidden; 
    display: flex; 
    flex-direction: column; 
    box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); 
    z-index: 1000000 !important;
}
.doc-viewer-toolbar { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; justify-content: space-between; padding: 8px 12px; background: #1f2937; color: #fff; }
.doc-viewer-toolbar .btn { padding: 4px 8px; }
.doc-viewer-content { flex: 1; background: #000; display: flex; align-items: center; justify-content: center; overflow: hidden; position: relative; }
.doc-viewer-canvas { touch-action: none; width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; }
.doc-viewer-content img { will-change: transform; transform-origin: center center; user-select: none; -webkit-user-drag: none; }
.doc-viewer-content iframe { width: 100%; height: 100%; border: none; }
.doc-viewer-close { background: transparent; border: 0; color: #fff; font-size: 20px; }

/* Validation modal should appear above student info modal but below document viewer */
#validationModal { z-index: 205000 !important; }
#validationModal + .modal-backdrop { z-index: 204999 !important; }

/* Custom backdrop for validation modal to dim the student info modal behind it */
.validation-backdrop {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.6);
    z-index: 204998 !important;
    display: none;
}

.validation-backdrop.show {
    display: block;
}

/* Verification checklist styling (matching registration page) */
.verification-checklist {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.verification-checklist .form-check {
    padding: 0.75rem 1rem;
    background: #f8f9fa;
    border-radius: 6px;
    border: 1px solid #dee2e6;
    margin: 0;
}

.verification-checklist .form-check.check-passed {
    background: #d1e7dd;
    border-color: #badbcc;
}

.verification-checklist .form-check.check-failed {
    background: #f8d7da;
    border-color: #f5c2c7;
}

.verification-checklist .form-check.check-warning {
    background: #fff3cd;
    border-color: #ffe69c;
}

.confidence-score {
    font-size: 0.875rem;
    padding: 0.25rem 0.5rem;
    min-width: 50px;
    text-align: center;
}

@media (max-width: 576px) {
    .doc-grid { grid-template-columns: 1fr; }
    .doc-viewer { width: 100vw; height: 90vh; border-radius: 0; }
}

/* Migration preview responsive table */
.migration-preview { 
    overflow-x: auto; 
    max-height: 500px;
    overflow-y: auto;
    background: #fff;
    border: 1px solid #dee2e6 !important;
}

.migration-preview .preview-table { 
    min-width: 1100px;
    margin-bottom: 0;
}

.migration-preview .preview-table thead th { 
    position: sticky; 
    top: 0; 
    background: #e7f1ff;
    z-index: 3;
    border-bottom: 2px solid #1182FF;
    font-weight: 600;
    padding: 12px 8px;
    white-space: nowrap;
    color: #0d47a1;
}

.migration-preview .preview-table tbody tr {
    background: #fff;
}

.migration-preview .preview-table tbody tr:hover {
    background: #f8f9fa;
}

.migration-preview .preview-table tbody tr.table-warning {
    background: #fff3cd;
}

.migration-preview .preview-table tbody tr.table-warning:hover {
    background: #ffecb5;
}

.migration-preview .preview-table th, 
.migration-preview .preview-table td { 
    white-space: nowrap;
    padding: 10px 8px;
    border: 1px solid #dee2e6;
    vertical-align: middle;
}

.migration-preview .preview-table td {
    color: #212529;
    font-size: 0.9rem;
}

/* Sticky first column (Select) */
.migration-preview .preview-table th:first-child,
.migration-preview .preview-table td[data-label="Select"] { 
    position: sticky; 
    left: 0; 
    background: #e7f1ff;
    z-index: 2;
    box-shadow: 2px 0 4px rgba(0,0,0,0.1);
}

.migration-preview .preview-table tbody tr:hover td[data-label="Select"] {
    background: #d4e7ff;
}

.migration-preview .preview-table tbody tr.table-warning td[data-label="Select"] {
    background: #ffe69c;
}

.migration-preview .preview-table tbody tr.table-warning:hover td[data-label="Select"] {
    background: #ffd966;
}

/* Sticky last column (Conflicts) */
.migration-preview .preview-table th:last-child,
.migration-preview .preview-table td[data-label="Conflicts"] { 
    position: sticky; 
    right: 0; 
    background: #fff;
    z-index: 2;
    box-shadow: -2px 0 4px rgba(0,0,0,0.1);
    max-width: 250px;
    white-space: normal;
}

.migration-preview .preview-table tbody tr:hover td[data-label="Conflicts"] {
    background: #f8f9fa;
}

.migration-preview .preview-table tbody tr.table-warning td[data-label="Conflicts"] {
    background: #fff3cd;
}

.migration-preview .preview-table tbody tr.table-warning:hover td[data-label="Conflicts"] {
    background: #ffecb5;
}

.migration-preview .preview-table td[data-label="Conflicts"] ul {
    font-size: 0.85rem;
    line-height: 1.4;
}

/* Responsive design for mobile */
@media (max-width: 768px) {
    .migration-preview { 
        max-height: none;
        border: none !important;
    }
    
    .migration-preview .preview-table { 
        min-width: 100%;
    }
    
    .migration-preview .preview-table thead { 
        display: none; 
    }
    
    .migration-preview .preview-table tbody tr { 
        display: grid; 
        grid-template-columns: 1fr 1fr; 
        gap: 6px 12px; 
        padding: 12px; 
        border: 1px solid #dee2e6;
        border-radius: 8px;
        margin-bottom: 12px;
        background: #fff;
    }
    
    .migration-preview .preview-table tbody tr.table-warning {
        background: #fff3cd;
        border-color: #ffc107;
    }
    
    .migration-preview .preview-table tbody td { 
        display: flex; 
        justify-content: space-between; 
        align-items: center; 
        border: none !important; 
        padding: 6px 0;
        position: static !important;
        box-shadow: none !important;
        background: transparent !important;
        white-space: normal;
    }
    
    .migration-preview .preview-table tbody td::before { 
        content: attr(data-label); 
        font-weight: 600; 
        color: #1182FF; 
        margin-right: 8px;
        flex-shrink: 0;
    }
    
    .migration-preview .preview-table tbody td[data-label="Select"] { 
        grid-column: 1 / -1; 
        justify-content: flex-start;
        border-bottom: 1px solid #dee2e6 !important;
        padding-bottom: 8px !important;
        margin-bottom: 4px;
    }
    
    .migration-preview .preview-table tbody td[data-label="Conflicts"] { 
        grid-column: 1 / -1;
        flex-direction: column;
        align-items: flex-start;
    }
    
    .migration-preview .preview-table tbody td[data-label="Conflicts"]::before {
        margin-bottom: 4px;
    }
}

.sticky-confirm { 
    position: sticky; 
    bottom: 0; 
    background: #fff; 
    border-top: 1px solid #eee;
    padding: 12px 0;
    z-index: 10;
}

/* Hide scroll controls on small screens */
@media (max-width: 768px) {
    .preview-scroll-controls { 
        display: none; 
    }
}
</style>

<script>
// Lightweight document viewer (image/pdf)
function ensureDocViewer() {
    let backdrop = document.getElementById('docViewerBackdrop');
    if (!backdrop) {
        backdrop = document.createElement('div');
        backdrop.id = 'docViewerBackdrop';
        backdrop.className = 'doc-viewer-backdrop';
        backdrop.innerHTML = `
            <div class="doc-viewer">
                <div class="doc-viewer-toolbar">
                    <div id="docViewerTitle"></div>
                    <div class="d-flex flex-wrap gap-1">
                        <button id="docZoomOutBtn" class="btn btn-sm btn-outline-light" title="Zoom Out"><i class="bi bi-zoom-out"></i></button>
                        <button id="docZoomInBtn" class="btn btn-sm btn-outline-light" title="Zoom In"><i class="bi bi-zoom-in"></i></button>
                        <button id="docRotateLeftBtn" class="btn btn-sm btn-outline-light" title="Rotate Left"><i class="bi bi-arrow-counterclockwise"></i></button>
                        <button id="docRotateRightBtn" class="btn btn-sm btn-outline-light" title="Rotate Right"><i class="bi bi-arrow-clockwise"></i></button>
                        <button id="docFitWidthBtn" class="btn btn-sm btn-outline-light" title="Fit Width"><i class="bi bi-arrows-expand"></i></button>
                        <button id="docFitScreenBtn" class="btn btn-sm btn-outline-light" title="Fit Screen"><i class="bi bi-arrows-fullscreen"></i></button>
                        <button id="docResetBtn" class="btn btn-sm btn-outline-secondary" title="Reset"><i class="bi bi-arrow-repeat"></i></button>
                        <div class="vr mx-1"></div>
                        <button id="docOpenBtn" class="btn btn-sm btn-outline-light"><i class="bi bi-box-arrow-up-right"></i> Open</button>
                        <button id="docDownloadBtn" class="btn btn-sm btn-success"><i class="bi bi-download"></i> Download</button>
                        <button class="doc-viewer-close ms-1" onclick="closeDocumentViewer()">&times;</button>
                    </div>
                </div>
                <div class="doc-viewer-content">
                    <div class="doc-viewer-canvas">
                        <img id="docViewerImg" alt="preview" style="display:none;" />
                        <iframe id="docViewerPdf" style="display:none;"></iframe>
                    </div>
                </div>
            </div>`;
        document.body.appendChild(backdrop);
        backdrop.addEventListener('click', (e) => { if (e.target === backdrop) closeDocumentViewer(); });
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeDocumentViewer(); });
    }
    return backdrop;
}

// Viewer state
let _viewState = { scale: 1, rotation: 0, originX: 0, originY: 0, panX: 0, panY: 0, isImage: false };

function applyImageTransform(img) {
    img.style.transform = `translate(${_viewState.panX}px, ${_viewState.panY}px) rotate(${_viewState.rotation}deg) scale(${_viewState.scale})`;
}

function resetView(img) {
    _viewState = { scale: 1, rotation: 0, originX: 0, originY: 0, panX: 0, panY: 0, isImage: _viewState.isImage };
    if (img) applyImageTransform(img);
}

function openDocumentViewer(src, title) {
    const backdrop = ensureDocViewer();
    const img = document.getElementById('docViewerImg');
    const pdf = document.getElementById('docViewerPdf');
    const openBtn = document.getElementById('docOpenBtn');
    const downloadBtn = document.getElementById('docDownloadBtn');
    const zoomInBtn = document.getElementById('docZoomInBtn');
    const zoomOutBtn = document.getElementById('docZoomOutBtn');
    const rotateLeftBtn = document.getElementById('docRotateLeftBtn');
    const rotateRightBtn = document.getElementById('docRotateRightBtn');
    const fitWidthBtn = document.getElementById('docFitWidthBtn');
    const fitScreenBtn = document.getElementById('docFitScreenBtn');
    const resetBtn = document.getElementById('docResetBtn');
    document.getElementById('docViewerTitle').textContent = title || 'Document';

    // Reset
    img.style.display = 'none';
    pdf.style.display = 'none';
    img.src = '';
    pdf.src = '';
    resetView(img);

    const isImage = /\.(jpg|jpeg|png|gif)$/i.test(src);
    const isPdf = /\.pdf$/i.test(src);
    _viewState.isImage = isImage;
    if (isImage) {
        img.src = src;
        img.style.display = 'block';
    } else if (isPdf) {
        pdf.src = src;
        pdf.style.display = 'block';
    }

    openBtn.onclick = () => window.open(src, '_blank');
    downloadBtn.onclick = () => { const a = document.createElement('a'); a.href = src; a.download = ''; a.click(); };

    // Controls
    function setScale(mult) { _viewState.scale = Math.min(8, Math.max(0.25, _viewState.scale * mult)); applyImageTransform(img); }
    function rotate(delta) { _viewState.rotation = (_viewState.rotation + delta + 360) % 360; applyImageTransform(img); }
    function fitWidth() {
        const container = document.querySelector('.doc-viewer-content');
        if (!container || !img.naturalWidth) return; 
        _viewState.scale = (container.clientWidth * 0.95) / img.naturalWidth; _viewState.panX = 0; _viewState.panY = 0; applyImageTransform(img);
    }
    function fitScreen() {
        const container = document.querySelector('.doc-viewer-content');
        if (!container || !img.naturalWidth || !img.naturalHeight) return; 
        const scaleX = (container.clientWidth * 0.95) / img.naturalWidth;
        const scaleY = (container.clientHeight * 0.95) / img.naturalHeight;
        _viewState.scale = Math.min(scaleX, scaleY); _viewState.panX = 0; _viewState.panY = 0; applyImageTransform(img);
    }

    zoomInBtn.onclick = () => _viewState.isImage && setScale(1.2);
    zoomOutBtn.onclick = () => _viewState.isImage && setScale(1/1.2);
    rotateLeftBtn.onclick = () => _viewState.isImage && rotate(-90);
    rotateRightBtn.onclick = () => _viewState.isImage && rotate(90);
    fitWidthBtn.onclick = () => _viewState.isImage ? fitWidth() : (pdf.src = src + '#zoom=page-width');
    fitScreenBtn.onclick = () => _viewState.isImage ? fitScreen() : (pdf.src = src + '#zoom=page-fit');
    resetBtn.onclick = () => { resetView(img); if (!isImage) pdf.src = src; };

    // Pan & wheel zoom for images
    const canvas = document.querySelector('.doc-viewer-canvas');
    let dragging = false, lastX = 0, lastY = 0;
    canvas.onpointerdown = (e) => { if (!_viewState.isImage) return; dragging = true; lastX = e.clientX; lastY = e.clientY; canvas.setPointerCapture(e.pointerId); };
    canvas.onpointermove = (e) => { if (!_viewState.isImage || !dragging) return; _viewState.panX += (e.clientX - lastX); _viewState.panY += (e.clientY - lastY); lastX = e.clientX; lastY = e.clientY; applyImageTransform(img); };
    canvas.onpointerup = () => { dragging = false; };
    canvas.onwheel = (e) => { if (!_viewState.isImage) return; e.preventDefault(); setScale(e.deltaY < 0 ? 1.1 : 1/1.1); };

    // Double-tap/double-click to toggle zoom
    let lastTap = 0;
    canvas.ondblclick = () => { if (!_viewState.isImage) return; _viewState.scale = _viewState.scale < 2 ? 2 : 1; _viewState.panX = 0; _viewState.panY = 0; applyImageTransform(img); };
    canvas.ontouchend = () => { const now = Date.now(); if (now - lastTap < 300) { canvas.ondblclick(); } lastTap = now; };

    // Ensure viewer is above any Bootstrap modals/backdrops
    backdrop.style.display = 'block';
    backdrop.classList.add('show');
    backdrop.style.zIndex = '1000000';
    const viewerEl = backdrop.querySelector('.doc-viewer');
    if (viewerEl) viewerEl.style.zIndex = '1000001';
    document.body.classList.add('doc-viewer-open');
}

function closeDocumentViewer() {
    const backdrop = document.getElementById('docViewerBackdrop');
    if (backdrop) {
        backdrop.style.display = 'none';
        backdrop.classList.remove('show');
        document.body.classList.remove('doc-viewer-open');
    }
}

// Archive Student Modal and Functions
let archiveModalLock = false;
function showArchiveModal(studentId, studentName, event) {
    // Get the button that triggered this
    const btn = event?.target?.closest('button');
    
    // Prevent double-click opening multiple modals
    if (archiveModalLock) {
        console.log('Archive modal already opening, ignoring duplicate call');
        return;
    }
    archiveModalLock = true;
    
    // Disable the button temporarily
    if (btn) {
        btn.disabled = true;
        const originalHTML = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-hourglass-split"></i>';
        
        // Re-enable after delay
        setTimeout(() => {
            btn.disabled = false;
            btn.innerHTML = originalHTML;
        }, 1000);
    }
    
    const modal = document.getElementById('archiveModal');
    if (!modal) {
        console.error('Archive modal element not found');
        archiveModalLock = false;
        return;
    }

    const idInput = document.getElementById('archiveStudentId');
    const nameLabel = document.getElementById('archiveStudentName');
    const reasonSelect = document.getElementById('archiveReason');
    const otherReason = document.getElementById('archiveOtherReason');
    const otherContainer = document.getElementById('otherReasonContainer');

    if (idInput) idInput.value = studentId;
    if (nameLabel) nameLabel.textContent = studentName;
    if (reasonSelect) reasonSelect.value = '';
    if (otherReason) otherReason.value = '';
    if (otherContainer) otherContainer.style.display = 'none';

    const bsModal = bootstrap.Modal.getOrCreateInstance(modal);
    bsModal.show();
    
    // Release lock after modal is shown
    setTimeout(() => {
        archiveModalLock = false;
    }, 500);
    
    // Also release lock when modal is closed
    modal.addEventListener('hidden.bs.modal', function() {
        archiveModalLock = false;
    }, { once: true });
}

function handleArchiveReasonChange() {
    const select = document.getElementById('archiveReason');
    const otherContainer = document.getElementById('otherReasonContainer');
    const otherInput = document.getElementById('archiveOtherReason');

    if (!select || !otherContainer || !otherInput) {
        return;
    }

    if (select.value === 'other') {
        otherContainer.style.display = 'block';
        otherInput.focus();
    } else {
        otherContainer.style.display = 'none';
        otherInput.value = '';
    }
}

// Handle archive form submission with confirmation dialog
document.addEventListener('DOMContentLoaded', function() {
    const archiveForm = document.getElementById('archiveForm');
    let archiveFormSubmitting = false;

    if (archiveForm) {
        archiveForm.addEventListener('submit', function(e) {
            e.preventDefault();

            // Prevent double submission
            if (archiveFormSubmitting) {
                console.log('Archive form already submitting, ignoring duplicate submission');
                return;
            }

            // Validate reason selection
            const reasonSelect = document.getElementById('archiveReason');
            const otherReasonText = document.getElementById('archiveOtherReason');
            
            if (!reasonSelect.value) {
                alert('Please select a reason for archiving.');
                return;
            }

            if (reasonSelect.value === 'other' && !otherReasonText.value.trim()) {
                alert('Please specify the reason for archiving.');
                otherReasonText.focus();
                return;
            }

            // Get student name for confirmation
            const studentName = document.getElementById('archiveStudentName').textContent;
            const reason = reasonSelect.value === 'other' ? otherReasonText.value : reasonSelect.options[reasonSelect.selectedIndex].text;

            // Show confirmation dialog
            if (confirm(`⚠️ CONFIRM ARCHIVE\n\nStudent: ${studentName}\nReason: ${reason}\n\nThis will:\n• Deactivate the student account\n• Compress all documents to ZIP\n• Prevent student login\n• Move student to archived list\n\nAre you sure you want to proceed?`)) {
                // Disable submit button to prevent double clicks
                archiveFormSubmitting = true;
                const submitBtn = document.getElementById('confirmArchiveBtn');
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Processing...';
                }
                
                // Submit the form
                archiveForm.submit();
            }
        });
        
        // Reset flag when modal is hidden
        const archiveModal = document.getElementById('archiveModal');
        if (archiveModal) {
            archiveModal.addEventListener('hidden.bs.modal', function() {
                archiveFormSubmitting = false;
                const submitBtn = document.getElementById('confirmArchiveBtn');
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="bi bi-archive me-1"></i> Archive Student';
                }
            });
        }
    }
});

// Load validation data into modal (modal will be shown automatically by Bootstrap data attributes)
async function loadValidationData(docType, studentId) {
    console.log('loadValidationData called:', docType, studentId);
    
    const modalBody = document.getElementById('validationModalBody');
    const modalTitle = document.getElementById('validationModalLabel');
    
    const docNames = {
        'id_picture': 'ID Picture',
        'eaf': 'EAF',
        'letter_to_mayor': 'Letter to Mayor',
        'certificate_of_indigency': 'Certificate of Indigency',
        'grades': 'Academic Grades'
    };
    modalTitle.textContent = `${docNames[docType] || docType} - Validation Results`;
    
    modalBody.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-info"></div><p class="mt-3">Loading...</p></div>';
    
    try {
        // Use the NEW get_applicant_details.php endpoint which has the verification data
        const response = await fetch(`get_applicant_details.php?student_id=${encodeURIComponent(studentId)}`);
        
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        
        const data = await response.json();
        console.log('Applicant details response:', data);
        
        if (data.success && data.documents && data.documents[docType]) {
            const doc = data.documents[docType];
            console.log('Document data for', docType, ':', doc);
            console.log('OCR data:', doc.ocr_data);
            
            if (doc.ocr_data) {
                // The verification data is in ocr_data.verification object
                // Build validation object from ocr_data
                const validation = {
                    ocr_confidence: doc.ocr_data.confidence || 0,
                    verification_score: doc.ocr_data.verification_score || 0,
                    verification_status: doc.ocr_data.verification_status || 'pending',
                    // Spread the entire verification object which contains all the extracted data
                    ...(doc.ocr_data.verification || {})
                };
                
                console.log('Built validation object:', validation);
                
                const html = generateValidationHTML(validation, docType);
                modalBody.innerHTML = html;
            } else {
                console.log('No ocr_data found for document');
                modalBody.innerHTML = `<div class="alert alert-warning">
                    <h6><i class="bi bi-exclamation-triangle me-2"></i>No validation data available</h6>
                    <p>This document has not been validated yet or validation data is missing.</p>
                    <small>Document Type: ${docType}, Student ID: ${studentId}</small>
                </div>`;
            }
        } else {
            modalBody.innerHTML = `<div class="alert alert-warning">
                <h6><i class="bi bi-exclamation-triangle me-2"></i>${data.error || 'Document not found'}</h6>
                <small>Document Type: ${docType}, Student ID: ${studentId}</small>
            </div>`;
        }
    } catch (error) {
        console.error('Validation fetch error:', error);
        modalBody.innerHTML = `<div class="alert alert-danger">
            <h6><i class="bi bi-x-circle me-2"></i>Error loading validation data</h6>
            <p>${error.message}</p>
            <small>Document Type: ${docType}, Student ID: ${studentId}</small>
        </div>`;
    }
}

function generateValidationHTML(validation, docType) {
    console.log('=== generateValidationHTML DEBUG ===');
    console.log('docType:', docType);
    console.log('validation object:', validation);
    console.log('ocr_confidence type:', typeof validation.ocr_confidence);
    console.log('ocr_confidence value:', validation.ocr_confidence);
    console.log('Has identity_verification?', !!validation.identity_verification);
    if (validation.identity_verification) {
        console.log('identity_verification keys:', Object.keys(validation.identity_verification));
        console.log('identity_verification data:', validation.identity_verification);
    }
    
    if (!validation || typeof validation !== 'object') {
        return `<div class="alert alert-warning p-4">
            <h6><i class="bi bi-exclamation-triangle me-2"></i>No Validation Data</h6>
            <p>Validation data is not available or malformed for this document.</p>
            <small>Document Type: ${docType}</small>
        </div>`;
    }
    
    let html = '';
    
    // === OCR CONFIDENCE BANNER ===
    if (validation.ocr_confidence !== undefined && validation.ocr_confidence !== null) {
        const conf = parseFloat(validation.ocr_confidence) || 0;
        const confColor = conf >= 80 ? 'success' : (conf >= 60 ? 'warning' : 'danger');
        html += `<div class="alert alert-${confColor} d-flex justify-content-between align-items-center mb-4">
            <div>
                <h5 class="mb-0"><i class="bi bi-robot me-2"></i>Overall OCR Confidence</h5>
                <small class="text-muted">How well Tesseract extracted text from the image</small>
            </div>
            <h3 class="mb-0 fw-bold">${conf.toFixed(1)}%</h3>
        </div>`;
    }
    
    // === CHECK IF VERIFICATION DATA EXISTS ===
    // For grades, check for enhanced_grade_validation or grades array
    const isGrades = (docType === 'grades');
    const hasGradesVerification = isGrades && (validation.enhanced_grade_validation || validation.grades || validation.all_grades_passing !== undefined);
    
    // For EAF (enrollment forms), check for TSV-based verification structure
    const isEAF = (docType === 'eaf');
    const hasEAFVerification = isEAF && (
        validation.extracted_data !== undefined ||
        validation.tsv_quality !== undefined ||
        validation.course_data !== undefined ||
        validation.success !== undefined
    );
    
    // For other documents, check for identity_verification or direct verification fields
    const hasIdentityVerification = validation.identity_verification && 
                                (parseFloat(validation.identity_verification.first_name_confidence || 0) > 0 ||
                                 parseFloat(validation.identity_verification.last_name_confidence || 0) > 0 ||
                                 parseFloat(validation.identity_verification.school_confidence || 0) > 0 ||
                                 parseInt(validation.identity_verification.passed_checks || 0) > 0);
    
    // Check if we have direct verification fields (not nested under identity_verification)
    const hasDirectVerification = !validation.identity_verification && !isEAF && (
        validation.year_level_match !== undefined ||
        validation.semester_match !== undefined ||
        validation.first_name_match !== undefined ||
        validation.university_match !== undefined ||
        validation.summary !== undefined
    );
    
    const hasVerificationData = hasGradesVerification || hasEAFVerification || hasIdentityVerification || hasDirectVerification;
    
    console.log('Verification checks:', {
        isEAF,
        hasEAFVerification,
        hasGradesVerification,
        hasIdentityVerification,
        hasDirectVerification,
        hasVerificationData
    });
    
    if (!hasVerificationData && parseFloat(validation.ocr_confidence || 0) > 0) {
        html += `<div class="alert alert-warning mb-4">
            <h6><i class="bi bi-exclamation-triangle me-2"></i>Verification Incomplete</h6>
            <p><strong>Text was successfully extracted (${parseFloat(validation.ocr_confidence || 0).toFixed(1)}% OCR confidence)</strong>, 
            but verification against student data has not been performed yet.</p>
            <p class="mb-0"><small>This usually happens when:</small></p>
            <ul class="mb-0">
                <li><small>The document was uploaded but not processed for verification</small></li>
                <li><small>The .verify.json file is missing or corrupted</small></li>
                <li><small>The student needs to click "Process OCR" button to complete verification</small></li>
            </ul>
        </div>`;
        
        // Show extracted text if available
        if (validation.extracted_text) {
            html += `<div class="card mb-3">
                <div class="card-header bg-secondary text-white">
                    <h6 class="mb-0"><i class="bi bi-file-text me-2"></i>Extracted Text (${parseFloat(validation.ocr_confidence || 0).toFixed(1)}% confidence)</h6>
                </div>
                <div class="card-body">
                    <pre style="max-height: 300px; overflow-y: auto; white-space: pre-wrap; font-size: 0.85rem;">${validation.extracted_text}</pre>
                </div>
            </div>`;
        }
        
        return html;
    }
    
    // === HANDLE TSV-BASED EAF VERIFICATION (NEW FORMAT) ===
    if (docType === 'eaf' && validation.extracted_data) {
        const extracted = validation.extracted_data;
        const tsv_quality = validation.tsv_quality || {};
        const course_data = validation.course_data || null;
        
        html += '<div class="card mb-4"><div class="card-header bg-primary text-white">';
        html += '<h5 class="mb-0"><i class="bi bi-clipboard-check me-2"></i>TSV Verification Results</h5>';
        html += '</div><div class="card-body"><div class="verification-checklist">';
        
        // Student Name - First Name
        if (extracted.student_name) {
            const fnFound = extracted.student_name.first_name_found;
            const fnConf = parseFloat(extracted.student_name.first_name_similarity || extracted.student_name.first_name_confidence || 0);
            const fnClass = fnFound ? 'check-passed' : 'check-failed';
            const fnIcon = fnFound ? 'check-circle-fill text-success' : 'x-circle-fill text-danger';
            html += `<div class="form-check ${fnClass} d-flex justify-content-between align-items-center">
                <div><i class="bi bi-${fnIcon} me-2" style="font-size:1.2rem;"></i>
                <span><strong>First Name</strong> ${fnFound ? extracted.student_name.first_name : 'Not Found'}</span></div>
                <span class="badge ${fnFound ? 'bg-success' : 'bg-danger'} confidence-score">${fnConf.toFixed(1)}%</span>
            </div>`;
            
            // Last Name
            const lnFound = extracted.student_name.last_name_found;
            const lnConf = parseFloat(extracted.student_name.last_name_similarity || extracted.student_name.last_name_confidence || 0);
            const lnClass = lnFound ? 'check-passed' : 'check-failed';
            const lnIcon = lnFound ? 'check-circle-fill text-success' : 'x-circle-fill text-danger';
            html += `<div class="form-check ${lnClass} d-flex justify-content-between align-items-center">
                <div><i class="bi bi-${lnIcon} me-2" style="font-size:1.2rem;"></i>
                <span><strong>Last Name</strong> ${lnFound ? extracted.student_name.last_name : 'Not Found'}</span></div>
                <span class="badge ${lnFound ? 'bg-success' : 'bg-danger'} confidence-score">${lnConf.toFixed(1)}%</span>
            </div>`;
        }
        
        // Course
        if (course_data) {
            const courseFound = course_data.matched;
            const courseClass = courseFound ? 'check-passed' : 'check-warning';
            const courseIcon = courseFound ? 'check-circle-fill text-success' : 'exclamation-triangle-fill text-warning';
            html += `<div class="form-check ${courseClass} d-flex justify-content-between align-items-center">
                <div><i class="bi bi-${courseIcon} me-2" style="font-size:1.2rem;"></i>
                <span><strong>Course</strong> ${course_data.normalized_course || 'Not Found'}</span></div>
                <span class="badge ${courseFound ? 'bg-success' : 'bg-warning'}">
                    ${courseFound ? 'Matched' : 'Unmatched'}
                </span>
            </div>`;
        } else if (extracted.course) {
            const courseFound = extracted.course.found;
            const courseConf = parseFloat(extracted.course.confidence || 0);
            const courseClass = courseFound ? 'check-passed' : 'check-failed';
            const courseIcon = courseFound ? 'check-circle-fill text-success' : 'x-circle-fill text-danger';
            html += `<div class="form-check ${courseClass} d-flex justify-content-between align-items-center">
                <div><i class="bi bi-${courseIcon} me-2" style="font-size:1.2rem;"></i>
                <span><strong>Course</strong> ${courseFound ? extracted.course.normalized : 'Not Found'}</span></div>
                <span class="badge ${courseFound ? 'bg-success' : 'bg-danger'} confidence-score">${courseConf.toFixed(1)}%</span>
            </div>`;
        }
        
        // University
        if (extracted.university) {
            const uniMatched = extracted.university.matched;
            const uniConf = parseFloat(extracted.university.confidence || 0);
            const uniClass = uniMatched ? 'check-passed' : 'check-failed';
            const uniIcon = uniMatched ? 'check-circle-fill text-success' : 'x-circle-fill text-danger';
            html += `<div class="form-check ${uniClass} d-flex justify-content-between align-items-center">
                <div><i class="bi bi-${uniIcon} me-2" style="font-size:1.2rem;"></i>
                <span><strong>University</strong> ${uniMatched ? 'Matched' : 'Not Matched'}</span></div>
                <span class="badge ${uniMatched ? 'bg-success' : 'bg-danger'} confidence-score">${uniConf.toFixed(1)}%</span>
            </div>`;
        }
        
        // Year Level
        if (extracted.year_level) {
            const ylFound = extracted.year_level.found;
            const ylConf = parseFloat(extracted.year_level.confidence || 0);
            const ylClass = ylFound ? 'check-passed' : 'check-failed';
            const ylIcon = ylFound ? 'check-circle-fill text-success' : 'x-circle-fill text-danger';
            html += `<div class="form-check ${ylClass} d-flex justify-content-between align-items-center">
                <div><i class="bi bi-${ylIcon} me-2" style="font-size:1.2rem;"></i>
                <span><strong>Year Level</strong> ${ylFound ? extracted.year_level.raw : 'Not Found'}</span></div>
                <span class="badge ${ylFound ? 'bg-success' : 'bg-danger'} confidence-score">${ylConf.toFixed(1)}%</span>
            </div>`;
        }
        
        html += '</div></div></div>'; // close checklist, card-body, card
        
        // TSV Quality Metrics
        if (tsv_quality.total_cells !== undefined) {
            html += '<div class="card mb-4"><div class="card-header bg-info text-white">';
            html += '<h5 class="mb-0"><i class="bi bi-table me-2"></i>TSV Data Quality</h5>';
            html += '</div><div class="card-body">';
            html += '<div class="row g-3">';
            html += `<div class="col-md-3"><div class="text-center p-3 bg-light rounded">
                <h6 class="text-muted mb-1">Total Cells</h6>
                <h4 class="mb-0">${tsv_quality.total_cells || 0}</h4>
            </div></div>`;
            html += `<div class="col-md-3"><div class="text-center p-3 bg-light rounded">
                <h6 class="text-muted mb-1">Valid Cells</h6>
                <h4 class="mb-0 text-success">${tsv_quality.valid_cells || 0}</h4>
            </div></div>`;
            html += `<div class="col-md-3"><div class="text-center p-3 bg-light rounded">
                <h6 class="text-muted mb-1">Empty Cells</h6>
                <h4 class="mb-0 text-warning">${tsv_quality.empty_cells || 0}</h4>
            </div></div>`;
            html += `<div class="col-md-3"><div class="text-center p-3 bg-light rounded">
                <h6 class="text-muted mb-1">Quality Score</h6>
                <h4 class="mb-0 text-primary">${(tsv_quality.quality_score || 0).toFixed(1)}%</h4>
            </div></div>`;
            html += '</div></div></div>'; // close row, card-body, card
        }
        
        // Show extracted text if available
        if (validation.extracted_text) {
            html += `<div class="card mb-3">
                <div class="card-header bg-secondary text-white">
                    <h6 class="mb-0"><i class="bi bi-file-text me-2"></i>Extracted Text</h6>
                </div>
                <div class="card-body">
                    <pre style="max-height: 300px; overflow-y: auto; white-space: pre-wrap; font-size: 0.85rem;">${validation.extracted_text}</pre>
                </div>
            </div>`;
        }
        
        return html;
    }
    
    // === DETAILED VERIFICATION CHECKLIST ===
    // Handle both nested (identity_verification) and root-level verification data
    let idv = validation.identity_verification || validation;
    
    // Handle ID Picture's "checks" structure
    if (validation.checks && docType === 'id_picture') {
        // Convert checks structure to flat structure
        idv = {
            first_name_match: validation.checks.first_name_match?.passed,
            first_name_confidence: validation.checks.first_name_match?.similarity || 0,
            middle_name_match: validation.checks.middle_name_match?.passed,
            middle_name_confidence: validation.checks.middle_name_match?.similarity || 0,
            last_name_match: validation.checks.last_name_match?.passed,
            last_name_confidence: validation.checks.last_name_match?.similarity || 0,
            university_match: validation.checks.university_match?.passed,
            school_confidence: validation.checks.university_match?.similarity || 0,
            official_keywords: validation.checks.document_keywords_found?.passed,
            keywords_confidence: 100,
            ...validation
        };
    }
    
    // Handle EAF's structure (boolean matches + confidence_scores object)
    if (docType === 'eaf' && validation.confidence_scores) {
        idv = {
            first_name_match: validation.first_name_match,
            first_name_confidence: validation.confidence_scores.first_name || 0,
            middle_name_match: validation.middle_name_match,
            middle_name_confidence: validation.confidence_scores.middle_name || 0,
            last_name_match: validation.last_name_match,
            last_name_confidence: validation.confidence_scores.last_name || 0,
            year_level_match: validation.year_level_match,
            year_level_confidence: validation.confidence_scores.year_level || 0,
            university_match: validation.university_match,
            university_confidence: validation.confidence_scores.university || 0,
            official_keywords: validation.document_keywords_found,
            keywords_confidence: validation.confidence_scores.document_keywords || 0,
            ...validation
        };
    }
    
    // Check for identity data - handle different field naming conventions
    const hasIdentityData = idv.first_name_match !== undefined || 
                           idv.last_name_match !== undefined ||
                           idv.first_name !== undefined || // Letter/Certificate format
                           idv.last_name !== undefined;
    
    if (hasIdentityData && docType !== 'grades') {
        const isIdOrEaf = (docType === 'id_picture' || docType === 'eaf');
        const isLetter = (docType === 'letter_to_mayor');
        const isCert = (docType === 'certificate_of_indigency');
        
        // Convert letter/certificate simple boolean format to match format
        if (isLetter || isCert) {
            idv.first_name_match = idv.first_name;
            idv.first_name_confidence = idv.confidence_scores?.first_name || 0;
            idv.last_name_match = idv.last_name;
            idv.last_name_confidence = idv.confidence_scores?.last_name || 0;
            if (isLetter) {
                idv.barangay_match = idv.barangay;
                idv.barangay_confidence = idv.confidence_scores?.barangay || 0;
                idv.mayor_header_match = idv.mayor_header;
                idv.mayor_confidence = idv.confidence_scores?.mayor_header || 0;
                idv.municipality_match = idv.municipality;
                idv.municipality_confidence = idv.confidence_scores?.municipality || 0;
            }
            if (isCert) {
                idv.certificate_title_match = idv.certificate_title;
                idv.certificate_confidence = idv.confidence_scores?.certificate_title || 0;
                idv.barangay_match = idv.barangay;
                idv.barangay_confidence = idv.confidence_scores?.barangay || 0;
                idv.municipality_match = idv.municipality;
                idv.municipality_confidence = idv.confidence_scores?.municipality || 0;
            }
        }
        
        html += '<div class="card mb-4"><div class="card-header bg-primary text-white">';
        html += '<h5 class="mb-0"><i class="bi bi-clipboard-check me-2"></i>Verification Checklist</h5>';
        html += '</div><div class="card-body"><div class="verification-checklist">';
        
        // FIRST NAME
        const fnMatch = idv.first_name_match;
        const fnConf = parseFloat(idv.first_name_confidence || 0);
        const fnClass = fnMatch ? 'check-passed' : 'check-failed';
        const fnIcon = fnMatch ? 'check-circle-fill text-success' : 'x-circle-fill text-danger';
        html += `<div class="form-check ${fnClass} d-flex justify-content-between align-items-center">
            <div><i class="bi bi-${fnIcon} me-2" style="font-size:1.2rem;"></i>
            <span><strong>First Name</strong> ${fnMatch ? 'Match' : 'Not Found'}</span></div>
            <span class="badge ${fnMatch ? 'bg-success' : 'bg-danger'} confidence-score">${fnConf.toFixed(0)}%</span>
        </div>`;
        
        // MIDDLE NAME (ID/EAF only)
        if (isIdOrEaf) {
            const mnMatch = idv.middle_name_match;
            const mnConf = parseFloat(idv.middle_name_confidence || 0);
            const mnClass = mnMatch ? 'check-passed' : 'check-failed';
            const mnIcon = mnMatch ? 'check-circle-fill text-success' : 'x-circle-fill text-danger';
            html += `<div class="form-check ${mnClass} d-flex justify-content-between align-items-center">
                <div><i class="bi bi-${mnIcon} me-2" style="font-size:1.2rem;"></i>
                <span><strong>Middle Name</strong> ${mnMatch ? 'Match' : 'Not Found'}</span></div>
                <span class="badge ${mnMatch ? 'bg-success' : 'bg-danger'} confidence-score">${mnConf.toFixed(0)}%</span>
            </div>`;
        }
        
        // LAST NAME
        const lnMatch = idv.last_name_match;
        const lnConf = parseFloat(idv.last_name_confidence || 0);
        const lnClass = lnMatch ? 'check-passed' : 'check-failed';
        const lnIcon = lnMatch ? 'check-circle-fill text-success' : 'x-circle-fill text-danger';
        html += `<div class="form-check ${lnClass} d-flex justify-content-between align-items-center">
            <div><i class="bi bi-${lnIcon} me-2" style="font-size:1.2rem;"></i>
            <span><strong>Last Name</strong> ${lnMatch ? 'Match' : 'Not Found'}</span></div>
            <span class="badge ${lnMatch ? 'bg-success' : 'bg-danger'} confidence-score">${lnConf.toFixed(0)}%</span>
        </div>`;
        
        // YEAR LEVEL or BARANGAY
        if (isIdOrEaf) {
            const ylMatch = idv.year_level_match;
            const ylConf = parseFloat(idv.year_level_confidence || 0);
            const ylClass = ylMatch ? 'check-passed' : 'check-failed';
            const ylIcon = ylMatch ? 'check-circle-fill text-success' : 'x-circle-fill text-danger';
            html += `<div class="form-check ${ylClass} d-flex justify-content-between align-items-center">
                <div><i class="bi bi-${ylIcon} me-2" style="font-size:1.2rem;"></i>
                <span><strong>Year Level</strong> ${ylMatch ? 'Match' : 'Not Found'}</span></div>
                <span class="badge ${ylMatch ? 'bg-success' : 'bg-danger'} confidence-score">${ylConf > 0 ? ylConf.toFixed(0) + '%' : 'N/A'}</span>
            </div>`;
        } else if (isLetter || isCert) {
            const brgyMatch = idv.barangay_match;
            const brgyConf = parseFloat(idv.barangay_confidence || 0);
            const brgyClass = brgyMatch ? 'check-passed' : 'check-failed';
            const brgyIcon = brgyMatch ? 'check-circle-fill text-success' : 'x-circle-fill text-danger';
            html += `<div class="form-check ${brgyClass} d-flex justify-content-between align-items-center">
                <div><i class="bi bi-${brgyIcon} me-2" style="font-size:1.2rem;"></i>
                <span><strong>Barangay</strong> ${brgyMatch ? 'Match' : 'Not Found'}</span></div>
                <span class="badge ${brgyMatch ? 'bg-success' : 'bg-danger'} confidence-score">${brgyConf.toFixed(0)}%</span>
            </div>`;
        }
        
        // UNIVERSITY/SCHOOL (ID/EAF only)
        if (isIdOrEaf) {
            const schoolMatch = idv.school_match || idv.university_match;
            const schoolConf = parseFloat(idv.school_confidence || idv.university_confidence || 0);
            const schoolClass = schoolMatch ? 'check-passed' : 'check-failed';
            const schoolIcon = schoolMatch ? 'check-circle-fill text-success' : 'x-circle-fill text-danger';
            html += `<div class="form-check ${schoolClass} d-flex justify-content-between align-items-center">
                <div><i class="bi bi-${schoolIcon} me-2" style="font-size:1.2rem;"></i>
                <span><strong>University/School</strong> ${schoolMatch ? 'Match' : 'Not Found'}</span></div>
                <span class="badge ${schoolMatch ? 'bg-success' : 'bg-danger'} confidence-score">${schoolConf.toFixed(0)}%</span>
            </div>`;
        } else if (isLetter) {
            // Barangay
            const barangayMatch = idv.barangay_match;
            const barangayConf = parseFloat(idv.barangay_confidence || 0);
            const barangayClass = barangayMatch ? 'check-passed' : 'check-failed';
            const barangayIcon = barangayMatch ? 'check-circle-fill text-success' : 'x-circle-fill text-danger';
            html += `<div class="form-check ${barangayClass} d-flex justify-content-between align-items-center">
                <div><i class="bi bi-${barangayIcon} me-2" style="font-size:1.2rem;"></i>
                <span><strong>Barangay</strong> ${barangayMatch ? 'Match' : 'Not Found'}</span></div>
                <span class="badge ${barangayMatch ? 'bg-success' : 'bg-danger'} confidence-score">${barangayConf.toFixed(0)}%</span>
            </div>`;
            
            // Mayor Header
            const mayorMatch = idv.mayor_header_match;
            const mayorConf = parseFloat(idv.mayor_confidence || 0);
            const mayorClass = mayorMatch ? 'check-passed' : 'check-failed';
            const mayorIcon = mayorMatch ? 'check-circle-fill text-success' : 'x-circle-fill text-danger';
            html += `<div class="form-check ${mayorClass} d-flex justify-content-between align-items-center">
                <div><i class="bi bi-${mayorIcon} me-2" style="font-size:1.2rem;"></i>
                <span><strong>Mayor's Office Header</strong> ${mayorMatch ? 'Found' : 'Not Found'}</span></div>
                <span class="badge ${mayorMatch ? 'bg-success' : 'bg-danger'} confidence-score">${mayorConf.toFixed(0)}%</span>
            </div>`;
            
            // Municipality (only show if municipality check exists in validation data)
            if (idv.municipality !== undefined || idv.general_trias !== undefined) {
                const muniMatch = idv.municipality || idv.general_trias;
                const muniConf = parseFloat(idv.confidence_scores?.municipality || idv.confidence_scores?.general_trias || 0);
                const muniClass = muniMatch ? 'check-passed' : 'check-failed';
                const muniIcon = muniMatch ? 'check-circle-fill text-success' : 'x-circle-fill text-danger';
                html += `<div class="form-check ${muniClass} d-flex justify-content-between align-items-center">
                    <div><i class="bi bi-${muniIcon} me-2" style="font-size:1.2rem;"></i>
                    <span><strong>Municipality</strong> ${muniMatch ? 'Match' : 'Not Found'}</span></div>
                    <span class="badge ${muniMatch ? 'bg-success' : 'bg-danger'} confidence-score">${muniConf.toFixed(0)}%</span>
                </div>`;
            }
        } else if (isCert) {
            // Certificate Title
            const certMatch = idv.certificate_title_match;
            const certConf = parseFloat(idv.certificate_confidence || 0);
            const certClass = certMatch ? 'check-passed' : 'check-failed';
            const certIcon = certMatch ? 'check-circle-fill text-success' : 'x-circle-fill text-danger';
            html += `<div class="form-check ${certClass} d-flex justify-content-between align-items-center">
                <div><i class="bi bi-${certIcon} me-2" style="font-size:1.2rem;"></i>
                <span><strong>Certificate Title</strong> ${certMatch ? 'Found' : 'Not Found'}</span></div>
                <span class="badge ${certMatch ? 'bg-success' : 'bg-danger'} confidence-score">${certConf.toFixed(0)}%</span>
            </div>`;
            
            // Barangay
            const barangayMatch = idv.barangay_match;
            const barangayConf = parseFloat(idv.barangay_confidence || 0);
            const barangayClass = barangayMatch ? 'check-passed' : 'check-failed';
            const barangayIcon = barangayMatch ? 'check-circle-fill text-success' : 'x-circle-fill text-danger';
            html += `<div class="form-check ${barangayClass} d-flex justify-content-between align-items-center">
                <div><i class="bi bi-${barangayIcon} me-2" style="font-size:1.2rem;"></i>
                <span><strong>Barangay</strong> ${barangayMatch ? 'Match' : 'Not Found'}</span></div>
                <span class="badge ${barangayMatch ? 'bg-success' : 'bg-danger'} confidence-score">${barangayConf.toFixed(0)}%</span>
            </div>`;
            
            // Municipality (only show if municipality check exists in validation data)
            if (idv.municipality !== undefined || idv.general_trias !== undefined) {
                const muniMatch = idv.municipality || idv.general_trias;
                const muniConf = parseFloat(idv.confidence_scores?.municipality || idv.confidence_scores?.general_trias || 0);
                const muniClass = muniMatch ? 'check-passed' : 'check-failed';
                const muniIcon = muniMatch ? 'check-circle-fill text-success' : 'x-circle-fill text-danger';
                html += `<div class="form-check ${muniClass} d-flex justify-content-between align-items-center">
                    <div><i class="bi bi-${muniIcon} me-2" style="font-size:1.2rem;"></i>
                    <span><strong>Municipality</strong> ${muniMatch ? 'Match' : 'Not Found'}</span></div>
                    <span class="badge ${muniMatch ? 'bg-success' : 'bg-danger'} confidence-score">${muniConf.toFixed(0)}%</span>
                </div>`;
            }
        }
        
        // OFFICIAL KEYWORDS (ID/EAF only)
        if (isIdOrEaf) {
            const kwMatch = idv.official_keywords;
            const kwConf = parseFloat(idv.keywords_confidence || 0);
            const kwClass = kwMatch ? 'check-passed' : 'check-failed';
            const kwIcon = kwMatch ? 'check-circle-fill text-success' : 'x-circle-fill text-danger';
            html += `<div class="form-check ${kwClass} d-flex justify-content-between align-items-center">
                <div><i class="bi bi-${kwIcon} me-2" style="font-size:1.2rem;"></i>
                <span><strong>Official Document Keywords</strong> ${kwMatch ? 'Found' : 'Not Found'}</span></div>
                <span class="badge ${kwMatch ? 'bg-success' : 'bg-danger'} confidence-score">${kwConf.toFixed(0)}%</span>
            </div>`;
        }
        
        html += '</div></div></div>'; // Close checklist, card-body, card
        
        // === OVERALL SUMMARY ===
        const avgConf = parseFloat(idv.average_confidence || validation.summary?.average_confidence || validation.ocr_confidence || 0);
        const passedChecks = idv.passed_checks || validation.summary?.passed_checks || 0;
        const totalChecks = idv.total_checks || validation.summary?.total_checks || 6;
        const verificationScore = ((passedChecks / totalChecks) * 100);
        
        let statusMessage = '';
        let statusClass = '';
        let statusIcon = '';
        
        if (verificationScore >= 80) {
            statusMessage = 'Document validation successful';
            statusClass = 'alert-success';
            statusIcon = 'check-circle-fill';
        } else if (verificationScore >= 60) {
            statusMessage = 'Document validation passed with warnings';
            statusClass = 'alert-warning';
            statusIcon = 'exclamation-triangle-fill';
        } else {
            statusMessage = 'Document validation failed - manual review required';
            statusClass = 'alert-danger';
            statusIcon = 'x-circle-fill';
        }
        
        html += `<div class="card mb-4"><div class="card-header bg-light"><h6 class="mb-0"><i class="bi bi-bar-chart me-2"></i>Overall Analysis</h6></div><div class="card-body">`;
        html += `<div class="row g-3 mb-3">
            <div class="col-md-4">
                <div class="text-center p-3 bg-light rounded">
                    <small class="text-muted d-block mb-1">Average Confidence</small>
                    <h4 class="mb-0 fw-bold text-primary">${avgConf.toFixed(1)}%</h4>
                </div>
            </div>
            <div class="col-md-4">
                <div class="text-center p-3 bg-light rounded">
                    <small class="text-muted d-block mb-1">Passed Checks</small>
                    <h4 class="mb-0 fw-bold text-success">${passedChecks}/${totalChecks}</h4>
                </div>
            </div>
            <div class="col-md-4">
                <div class="text-center p-3 bg-light rounded">
                    <small class="text-muted d-block mb-1">Verification Score</small>
                    <h4 class="mb-0 fw-bold ${verificationScore >= 80 ? 'text-success' : (verificationScore >= 60 ? 'text-warning' : 'text-danger')}">${verificationScore.toFixed(0)}%</h4>
                </div>
            </div>
        </div>`;
        
        html += `<div class="alert ${statusClass} mb-0">
            <h6 class="mb-0"><i class="bi bi-${statusIcon} me-2"></i>${statusMessage}</h6>`;
        const recommendation = idv.recommendation || validation.summary?.recommendation;
        if (recommendation) {
            html += `<small class="mt-2 d-block"><strong>Recommendation:</strong> ${recommendation}</small>`;
        }
        html += `</div></div></div>`; // Close card-body, card
    }
    
    // === EXTRACTED GRADES (for grades document) ===
    // Support both old format (extracted_grades) and new format (enhanced_grade_validation.extracted_subjects)
    let gradesArray = null;
    if (docType === 'grades') {
        if (validation.extracted_grades) {
            gradesArray = validation.extracted_grades;
        } else if (validation.enhanced_grade_validation && validation.enhanced_grade_validation.extracted_subjects) {
            // Convert new format to old format for display
            gradesArray = validation.enhanced_grade_validation.extracted_subjects.map(subject => ({
                subject_name: subject.name,
                grade_value: subject.rawGrade || subject.grade,
                extraction_confidence: subject.confidence || 95,
                is_passing: (parseFloat(subject.rawGrade || subject.grade || 0) <= 3.0 && parseFloat(subject.rawGrade || subject.grade || 0) > 0) ? 't' : 'f'
            }));
        } else if (validation.grades) {
            // Old simple format
            gradesArray = validation.grades.map(g => ({
                subject_name: g.subject,
                grade_value: g.grade,
                extraction_confidence: 90,
                is_passing: (parseFloat(g.grade || 0) <= 3.0 && parseFloat(g.grade || 0) > 0) ? 't' : 'f'
            }));
        }
    }
    
    if (gradesArray && gradesArray.length > 0) {
        // Show grades verification checklist first
        if (validation.summary) {
            html += '<div class="card mb-4"><div class="card-header bg-primary text-white">';
            html += '<h5 class="mb-0"><i class="bi bi-clipboard-check me-2"></i>Grades Verification Summary</h5>';
            html += '</div><div class="card-body"><div class="verification-checklist">';
            
            // First Name Match
            if (validation.first_name_match !== undefined) {
                const match = validation.first_name_match;
                const conf = parseFloat(validation.confidence_scores?.first_name || 0);
                const checkClass = match ? 'check-passed' : 'check-failed';
                const icon = match ? 'check-circle-fill text-success' : 'x-circle-fill text-danger';
                html += `<div class="form-check ${checkClass} d-flex justify-content-between align-items-center">
                    <div><i class="bi bi-${icon} me-2" style="font-size:1.2rem;"></i>
                    <span><strong>First Name</strong> ${match ? 'Match' : 'Not Found'}</span></div>
                    <span class="badge ${match ? 'bg-success' : 'bg-danger'} confidence-score">${conf.toFixed(0)}%</span>
                </div>`;
            }
            
            // Last Name Match
            if (validation.last_name_match !== undefined) {
                const match = validation.last_name_match;
                const conf = parseFloat(validation.confidence_scores?.last_name || 0);
                const checkClass = match ? 'check-passed' : 'check-failed';
                const icon = match ? 'check-circle-fill text-success' : 'x-circle-fill text-danger';
                html += `<div class="form-check ${checkClass} d-flex justify-content-between align-items-center">
                    <div><i class="bi bi-${icon} me-2" style="font-size:1.2rem;"></i>
                    <span><strong>Last Name</strong> ${match ? 'Match' : 'Not Found'}</span></div>
                    <span class="badge ${match ? 'bg-success' : 'bg-danger'} confidence-score">${conf.toFixed(0)}%</span>
                </div>`;
            }
            
            // Year Level Match
            if (validation.year_level_match !== undefined) {
                const match = validation.year_level_match;
                const conf = parseFloat(validation.confidence_scores?.year_level || 0);
                const checkClass = match ? 'check-passed' : 'check-failed';
                const icon = match ? 'check-circle-fill text-success' : 'x-circle-fill text-danger';
                html += `<div class="form-check ${checkClass} d-flex justify-content-between align-items-center">
                    <div><i class="bi bi-${icon} me-2" style="font-size:1.2rem;"></i>
                    <span><strong>Year Level</strong> ${match ? 'Match' : 'Not Found'}</span></div>
                    <span class="badge ${match ? 'bg-success' : 'bg-danger'} confidence-score">${conf.toFixed(0)}%</span>
                </div>`;
            }
            
            // Semester Match
            if (validation.semester_match !== undefined) {
                const match = validation.semester_match;
                const conf = parseFloat(validation.confidence_scores?.semester || 0);
                const checkClass = match ? 'check-passed' : 'check-failed';
                const icon = match ? 'check-circle-fill text-success' : 'x-circle-fill text-danger';
                html += `<div class="form-check ${checkClass} d-flex justify-content-between align-items-center">
                    <div><i class="bi bi-${icon} me-2" style="font-size:1.2rem;"></i>
                    <span><strong>Semester</strong> ${match ? 'Match' : 'Not Found'}</span></div>
                    <span class="badge ${match ? 'bg-success' : 'bg-danger'} confidence-score">${conf.toFixed(0)}%</span>
                </div>`;
            }
            
            // University Match
            if (validation.university_match !== undefined) {
                const match = validation.university_match;
                const conf = parseFloat(validation.confidence_scores?.university || 0);
                const checkClass = match ? 'check-passed' : 'check-failed';
                const icon = match ? 'check-circle-fill text-success' : 'x-circle-fill text-danger';
                html += `<div class="form-check ${checkClass} d-flex justify-content-between align-items-center">
                    <div><i class="bi bi-${icon} me-2" style="font-size:1.2rem;"></i>
                    <span><strong>University</strong> ${match ? 'Match' : 'Not Found'}</span></div>
                    <span class="badge ${match ? 'bg-success' : 'bg-danger'} confidence-score">${conf.toFixed(0)}%</span>
                </div>`;
            }
            
            // Student Name Match
            if (validation.name_match !== undefined) {
                const match = validation.name_match;
                const conf = parseFloat(validation.confidence_scores?.name || 0);
                const checkClass = match ? 'check-passed' : 'check-failed';
                const icon = match ? 'check-circle-fill text-success' : 'x-circle-fill text-danger';
                html += `<div class="form-check ${checkClass} d-flex justify-content-between align-items-center">
                    <div><i class="bi bi-${icon} me-2" style="font-size:1.2rem;"></i>
                    <span><strong>Student Name</strong> ${match ? 'Match' : 'Not Found'}</span></div>
                    <span class="badge ${match ? 'bg-success' : 'bg-danger'} confidence-score">${conf.toFixed(0)}%</span>
                </div>`;
            }
            
            // All Grades Passing
            if (validation.all_grades_passing !== undefined) {
                const match = validation.all_grades_passing;
                const conf = parseFloat(validation.confidence_scores?.grades || 90);
                const checkClass = match ? 'check-passed' : 'check-failed';
                const icon = match ? 'check-circle-fill text-success' : 'x-circle-fill text-danger';
                html += `<div class="form-check ${checkClass} d-flex justify-content-between align-items-center">
                    <div><i class="bi bi-${icon} me-2" style="font-size:1.2rem;"></i>
                    <span><strong>All Grades Passing</strong> ${match ? 'Yes' : 'No'}</span></div>
                    <span class="badge ${match ? 'bg-success' : 'bg-danger'} confidence-score">${conf.toFixed(0)}%</span>
                </div>`;
            }
            
            html += '</div>'; // Close verification-checklist
            
            // Summary Banner
            const eligibility = validation.summary.eligibility_status || 'UNKNOWN';
            const statusColors = {'ELIGIBLE': 'success', 'NOT_ELIGIBLE': 'danger', 'MANUAL_REVIEW': 'warning', 'UNKNOWN': 'secondary'};
            const statusColor = statusColors[eligibility] || 'secondary';
            const statusIcon = eligibility === 'ELIGIBLE' ? 'check-circle-fill' : (eligibility === 'NOT_ELIGIBLE' ? 'x-circle-fill' : 'exclamation-triangle-fill');
            
            html += `<div class="alert alert-${statusColor} mt-3 mb-0">`;
            html += `<h6 class="mb-0"><i class="bi bi-${statusIcon} me-2"></i>${validation.summary.eligibility_status || 'Status Unknown'}</h6>`;
            if (validation.summary.recommendation) {
                html += `<small class="mt-2 d-block"><strong>Recommendation:</strong> ${validation.summary.recommendation}</small>`;
            }
            html += `</div></div></div>`; // Close alert, card-body, card
        }
        
        // Now show the extracted grades table
        html += '<div class="card mb-4"><div class="card-header bg-success text-white">';
        html += '<h6 class="mb-0"><i class="bi bi-list-check me-2"></i>Extracted Grades</h6>';
        html += '</div><div class="card-body p-0"><div class="table-responsive">';
        html += '<table class="table table-bordered table-hover mb-0"><thead class="table-light"><tr><th>Subject</th><th>Grade</th><th>Confidence</th><th>Status</th></tr></thead><tbody>';
        
        gradesArray.forEach(grade => {
            const conf = parseFloat(grade.extraction_confidence || 0);
            const confColor = conf >= 80 ? 'success' : (conf >= 60 ? 'warning' : 'danger');
            const statusIcon = grade.is_passing === 't' ? 'check-circle-fill' : 'x-circle-fill';
            const statusColor = grade.is_passing === 't' ? 'success' : 'danger';
            
            html += `<tr>
                <td>${grade.subject_name || 'N/A'}</td>
                <td><strong>${grade.grade_value || 'N/A'}</strong></td>
                <td><span class="badge bg-${confColor}">${conf.toFixed(1)}%</span></td>
                <td><i class="bi bi-${statusIcon} text-${statusColor}"></i> ${grade.is_passing === 't' ? 'Passing' : 'Failing'}</td>
            </tr>`;
        });
        
        html += '</tbody></table></div></div></div>';
        
        if (validation.validation_status) {
            const statusColors = {'passed': 'success', 'failed': 'danger', 'manual_review': 'warning', 'pending': 'info'};
            const statusColor = statusColors[validation.validation_status] || 'secondary';
            html += `<div class="alert alert-${statusColor}"><strong>Grade Validation Status:</strong> ${validation.validation_status.toUpperCase().replace('_', ' ')}</div>`;
        }
    }
    
    // === EXTRACTED TEXT ===
    if (validation.extracted_text) {
        html += '<div class="card"><div class="card-header bg-secondary text-white">';
        html += '<h6 class="mb-0"><i class="bi bi-file-text me-2"></i>Extracted Text (OCR)</h6>';
        html += '</div><div class="card-body">';
        const textPreview = validation.extracted_text.substring(0, 2000);
        const hasMore = validation.extracted_text.length > 2000;
        html += `<pre style="max-height:400px;overflow-y:auto;font-size:0.85em;white-space:pre-wrap;background:#f8f9fa;padding:15px;border-radius:4px;border:1px solid #dee2e6;">${textPreview}${hasMore ? '\n\n... (text truncated)' : ''}</pre>`;
        html += '</div></div>';
    }
    
    return html;
}

// Show validation modal without closing parent student info modal
function showValidationModal() {
    // Get or create the validation modal instance
    const validationModalEl = document.getElementById('validationModal');
    let validationModal = bootstrap.Modal.getInstance(validationModalEl);
    
    if (!validationModal) {
        validationModal = new bootstrap.Modal(validationModalEl, {
            backdrop: 'static',
            keyboard: true,
            focus: true
        });
    }
    
    // Create custom backdrop to dim the student info modal
    let backdrop = document.getElementById('validationModalBackdrop');
    if (!backdrop) {
        backdrop = document.createElement('div');
        backdrop.id = 'validationModalBackdrop';
        backdrop.className = 'validation-backdrop';
        document.body.appendChild(backdrop);
    }
    
    // Show backdrop
    backdrop.classList.add('show');
    
    // Show the validation modal (it will appear on top of student info modal)
    validationModal.show();
    
    // Hide backdrop when modal is closed
    validationModalEl.addEventListener('hidden.bs.modal', function() {
        backdrop.classList.remove('show');
    }, { once: true });
}

// Reject Documents Modal Functions
async function showRejectDocumentsModal(studentId, studentName) {
    // If the fullscreen Document Viewer is open, close it first so this modal isn't hidden behind it
    try {
        const dv = document.getElementById('docViewerBackdrop');
        if (dv && dv.classList.contains('show')) {
            closeDocumentViewer();
        }
    } catch (e) { /* no-op */ }

    // If Validation Modal is open, hide it first (this keeps stacking predictable)
    try {
        const validationModalEl = document.getElementById('validationModal');
        const vm = validationModalEl ? bootstrap.Modal.getInstance(validationModalEl) : null;
        if (vm) {
            vm.hide();
        }
        const vBackdrop = document.getElementById('validationModalBackdrop');
        if (vBackdrop) vBackdrop.classList.remove('show');
    } catch (e) { /* no-op */ }

    // Set values in modal
    document.getElementById('rejectStudentId').value = studentId;
    document.getElementById('rejectStudentName').textContent = studentName;
    
    // Reset form FIRST (before token refresh, as reset will clear the token input)
    document.getElementById('rejectDocumentsForm').reset();
    
    // Refresh CSRF token AFTER reset to ensure token is always fresh and not cleared by reset()
    try {
        console.log('[RejectDocuments] Fetching fresh CSRF token...');
        const response = await fetch('get_csrf_token.php?action=reject_documents');
        if (response.ok) {
            const data = await response.json();
            console.log('[RejectDocuments] Token response:', data);
            if (data && data.success && data.token) {
                const tokenInput = document.querySelector('#rejectDocumentsForm input[name="csrf_token"]');
                if (tokenInput) {
                    const oldToken = tokenInput.value;
                    tokenInput.value = data.token;
                    console.log('[RejectDocuments] CSRF token updated');
                    console.log('[RejectDocuments] Old token (first 16):', oldToken.substring(0, 16));
                    console.log('[RejectDocuments] New token (first 16):', data.token.substring(0, 16));
                } else {
                    console.error('[RejectDocuments] Token input field not found!');
                }
            } else {
                console.error('[RejectDocuments] Invalid token response:', data);
            }
        } else {
            console.warn('[RejectDocuments] CSRF refresh failed: HTTP', response.status);
        }
    } catch(e) {
        console.error('[RejectDocuments] CSRF refresh exception:', e);
    }
    
    // Hide all reason containers and uncheck all checkboxes
    const reasonContainers = document.querySelectorAll('.reject-reason-container');
    reasonContainers.forEach(container => {
        container.style.display = 'none';
    });
    
    const checkboxes = document.querySelectorAll('input[name="reject_doc_types[]"]');
    checkboxes.forEach(checkbox => {
        checkbox.checked = false;
        // Re-enable by default; availability will adjust below
        checkbox.disabled = false;
        const label = document.querySelector('label[for="' + checkbox.id + '"]');
        if (label) label.removeAttribute('title');
    });
    
    // Disable submit button
    document.getElementById('confirmRejectBtn').disabled = true;
    
    // Show modal with higher z-index to appear above student info modal
    const modalEl = document.getElementById('rejectDocumentsModal');
    const modal = new bootstrap.Modal(modalEl, {
        backdrop: 'static',
        keyboard: false
    });
    
    // Use small timeout to ensure other overlays are fully hidden before showing this modal
    setTimeout(() => modal.show(), 30);

    // After showing, fetch current doc statuses and disable unavailable or already-rejected types
    await updateRejectDocumentsAvailability(studentId);
}

// Map reject modal codes to document keys returned by get_applicant_details.php
const _rejectDocCodeToKey = {
    '04': 'id_picture',
    '00': 'eaf',
    '01': 'grades',
    '02': 'letter_to_mayor',
    '03': 'certificate_of_indigency'
};

// Adjust availability for rejection:
//  - Disable documents that are already approved OR already rejected (cannot re-reject / no action needed)
//  - Enable documents that are missing (allow admin to flag them with a reason: "Not uploaded" etc.)
//  - Enable documents that exist but are still in temp/unverified state
async function updateRejectDocumentsAvailability(studentId) {
    try {
        const url = 'get_applicant_details.php?student_id=' + encodeURIComponent(studentId) + '&_=' + Date.now();
        const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
        if (!res.ok) return; // fallback: leave all enabled
        const data = await res.json();
        if (!data || !data.success || !data.documents) return;

        Object.entries(_rejectDocCodeToKey).forEach(([code, key]) => {
            const cb = document.querySelector('input[name="reject_doc_types[]"][value="' + code + '"]');
            if (!cb) return;
            const label = document.querySelector('label[for="' + cb.id + '"]');
            const reasonContainer = document.getElementById('reason_container_' + code);
            const doc = data.documents[key];

            // Check if document is missing (endpoint sets missing:true when not found)
            const isMissing = !doc || doc.missing === true;
            
            // Extract status with fallback field names
            const status = (doc && (doc.status || doc.document_status || doc.state || doc.verdict)) ? String(doc.status || doc.document_status || doc.state || doc.verdict).toLowerCase() : null;
            const isRejected = status === 'rejected';

            // Rule: Only allow selecting documents that EXIST (not missing) and are not already rejected
            if (isMissing) {
                cb.disabled = true;
                if (reasonContainer) reasonContainer.style.display = 'none';
                if (label) label.title = 'Disabled: not uploaded';
                return;
            }
            if (isRejected) {
                cb.disabled = true;
                if (reasonContainer) reasonContainer.style.display = 'none';
                if (label) label.title = 'Disabled: already rejected';
                return;
            }

            // Has a file and not rejected: allow selection regardless of approved/pending status
            cb.disabled = false;
            if (label) label.title = 'Uploaded: you can request re-upload';
        });

        // Re-evaluate submit button enabled state after applying disables
        const enabledChecked = Array.from(document.querySelectorAll('input[name="reject_doc_types[]"]')).some(cb => cb.checked && !cb.disabled);
        const submitBtn = document.getElementById('confirmRejectBtn');
        if (submitBtn) submitBtn.disabled = !enabledChecked;
    } catch (e) {
        // Silent fail; modal still usable with all options enabled
        console.warn('updateRejectDocumentsAvailability failed', e);
    }
}

// Handle checkbox changes to show/hide reason inputs
document.addEventListener('DOMContentLoaded', function() {
    const checkboxes = document.querySelectorAll('input[name="reject_doc_types[]"]');
    const submitBtn = document.getElementById('confirmRejectBtn');
    
    checkboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            const docCode = this.value;
            const reasonContainer = document.getElementById('reason_container_' + docCode);
            const reasonInput = reasonContainer.querySelector('input');
            
            if (this.checked) {
                // Show reason input when checkbox is checked
                reasonContainer.style.display = 'block';
                reasonInput.required = true;
            } else {
                // Hide reason input when checkbox is unchecked
                reasonContainer.style.display = 'none';
                reasonInput.required = false;
                reasonInput.value = '';
            }
            
            // Enable/disable submit button based on whether any non-disabled checkbox is checked
            const anyChecked = Array.from(checkboxes).some(cb => cb.checked && !cb.disabled);
            submitBtn.disabled = !anyChecked;
        });
    });
    
    // Validate form before submission
    document.getElementById('rejectDocumentsForm').addEventListener('submit', function(e) {
        const checkedBoxes = Array.from(checkboxes).filter(cb => cb.checked && !cb.disabled);
        
        if (checkedBoxes.length === 0) {
            e.preventDefault();
            alert('Please select at least one document to reject.');
            return false;
        }
        
        // Log token being submitted for debugging
        const tokenInput = document.querySelector('#rejectDocumentsForm input[name="csrf_token"]');
        if (tokenInput) {
            console.log('[RejectDocuments] Submitting with token (first 16):', tokenInput.value.substring(0, 16));
        } else {
            console.error('[RejectDocuments] No token input found at submission!');
        }
        
        // Check that all checked documents have reasons
        let allHaveReasons = true;
        checkedBoxes.forEach(checkbox => {
            const docCode = checkbox.value;
            const reasonInput = document.querySelector('input[name="reject_reason_' + docCode + '"]');
            if (!reasonInput.value.trim()) {
                allHaveReasons = false;
            }
        });
        
        if (!allHaveReasons) {
            e.preventDefault();
            alert('Please provide a reason for each rejected document.');
            return false;
        }
        
        // Confirm before submitting
        const studentName = document.getElementById('rejectStudentName').textContent;
        const docCount = checkedBoxes.length;
        const message = `Are you sure you want to reject ${docCount} document(s) for ${studentName}?\n\nThe student will be notified via email and the system.`;
        
        if (!confirm(message)) {
            e.preventDefault();
            return false;
        }
    });

    // Optional: auto-grow any future textarea with class auto-grow
    document.querySelectorAll('textarea.auto-grow').forEach(tx => {
        const resize = () => { tx.style.height = 'auto'; tx.style.height = (tx.scrollHeight) + 'px'; };
        tx.addEventListener('input', resize);
        resize();
    });
});
</script>

<!-- Validation Modal -->
<div class="modal fade" id="validationModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title" id="validationModalLabel">
                    <i class="bi bi-clipboard-check me-2"></i>Validation Results
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="validationModalBody">
                <div class="text-center py-5">
                    <div class="spinner-border text-info" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-3 text-muted">Loading validation data...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle me-1"></i>Close
                </button>
            </div>
        </div>
    </div>
 </div>

<script>
// Migration modal CSRF handling and UX hardening
document.addEventListener('DOMContentLoaded', function() {
    const migrationModalEl = document.getElementById('migrationModal');
    const migrationUploadForm = document.getElementById('migrationUploadForm');
    const migrationConfirmForm = document.getElementById('migrationForm');
    const migrationCancelForm = document.getElementById('migrationCancelForm');
    const csvFileInput = document.getElementById('csvFileInput');
    const csvFilename = document.getElementById('csvFilename');

    const getPreviewBtn = () => (migrationUploadForm ? migrationUploadForm.querySelector('button[type="submit"], .btn.btn-primary') : null);

    // Show selected file name
    if (csvFileInput && csvFilename) {
        csvFileInput.addEventListener('change', function() {
            const f = this.files && this.files[0] ? this.files[0].name : '';
            csvFilename.textContent = f ? `Selected: ${f}` : '';
        });
    }

    let _migTokenPromise = null;
    async function fetchMigrationCsrfToken(retries = 2, delayMs = 400) {
        if (_migTokenPromise) return _migTokenPromise; // de-dup concurrent fetches
        const btn = getPreviewBtn();
        let originalHtml = null;
        if (btn) {
            originalHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Preparing…';
        }
        const attempt = async (remaining, wait) => {
            try {
                const res = await fetch('get_csrf_token.php?action=csv_migration', {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    cache: 'no-store'
                });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                const ct = (res.headers.get('content-type') || '').toLowerCase();
                if (!ct.includes('application/json')) {
                    const text = await res.text();
                    throw new Error('Non-JSON response: ' + text.slice(0, 160));
                }
                const data = await res.json();
                if (!data || !data.success || !data.token) throw new Error('Invalid token payload');
                // Populate hidden inputs in all relevant forms
                if (migrationUploadForm) {
                    const inp = migrationUploadForm.querySelector('input[name="csrf_token"]');
                    if (inp) inp.value = data.token;
                }
                if (migrationConfirmForm) {
                    const inp2 = migrationConfirmForm.querySelector('input[name="csrf_token"]');
                    if (inp2) inp2.value = data.token;
                }
                if (migrationCancelForm) {
                    const inp3 = migrationCancelForm.querySelector('input[name="csrf_token"]');
                    if (inp3) inp3.value = data.token;
                }
                if (btn) { btn.disabled = false; btn.innerHTML = originalHtml; }
                return true;
            } catch (e) {
                if (remaining > 0) {
                    await new Promise(r => setTimeout(r, wait));
                    return attempt(remaining - 1, Math.min(wait * 2, 2000));
                }
                console.warn('[Migration] CSRF fetch failed after retries:', e);
                if (btn) { btn.disabled = true; btn.innerHTML = originalHtml || btn.innerHTML; }
                try {
                    const noticeId = 'migrationTokenNotice';
                    let n = document.getElementById(noticeId);
                    if (!n && migrationModalEl) {
                        n = document.createElement('div');
                        n.id = noticeId;
                        n.className = 'alert alert-warning my-2';
                        n.innerHTML = '<strong>Security token not ready.</strong> Please reload the page or try again.';
                        const body = migrationModalEl.querySelector('.modal-body');
                        if (body) body.prepend(n);
                    }
                } catch(_) {}
                return false;
            }
        };
        _migTokenPromise = attempt(retries, delayMs).finally(() => { _migTokenPromise = null; });
        return _migTokenPromise;
    }

    // Guard form submits if token missing
    function guardFormSubmit(form) {
        if (!form) return;
        form.addEventListener('submit', async function(e) {
            const tokenVal = (form.querySelector('input[name="csrf_token"]') || { value: '' }).value;
            if (!tokenVal) {
                e.preventDefault();
                alert('Security token not ready. Please wait a moment, then try again.');
                const ok = await fetchMigrationCsrfToken();
                const btn = getPreviewBtn();
                if (btn) btn.disabled = !ok;
                return false;
            }
        });
    }

    guardFormSubmit(migrationUploadForm);
    guardFormSubmit(migrationConfirmForm);

    // Refresh token when modal is shown and on file selection
    if (migrationModalEl) {
        migrationModalEl.addEventListener('shown.bs.modal', function() {
            fetchMigrationCsrfToken();
        });
    }
    if (csvFileInput) {
        csvFileInput.addEventListener('change', function() {
            fetchMigrationCsrfToken();
        });
    }
});
</script>

</body>
</html>
<?php pg_close($connection); ?>
