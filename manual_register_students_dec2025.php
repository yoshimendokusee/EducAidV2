<?php
/**
 * Manual Student Registration Script
 * December 2025 - Batch registration for 54 students
 * All students attend Cavite State University - General Trias Campus
 * 
 * Usage: php manual_register_students_dec2025.php
 * Or run in browser: http://localhost/EducAid/manual_register_students_dec2025.php
 */

// CLI or Web output handling
$isCli = php_sapi_name() === 'cli';
function out($msg) {
    global $isCli;
    if ($isCli) {
        echo $msg . "\n";
    } else {
        echo htmlspecialchars($msg) . "<br>\n";
        flush();
    }
}

if (!$isCli) {
    echo "<html><head><title>Manual Student Registration</title></head><body>";
    echo "<h1>Manual Student Registration - December 2025</h1>";
    echo "<pre>";
}

// Load DB connection
require_once __DIR__ . '/config/database.php';

if (!isset($connection)) {
    out("ERROR: Database connection not found.");
    exit(1);
}

out("Database connected successfully.");

// Get municipality ID (General Trias = 1)
$municipalityId = 1;

// Get or verify university ID for "Cavite State University - General Trias Campus"
$universityRes = pg_query_params($connection, 
    "SELECT university_id FROM universities WHERE name ILIKE '%General Trias%' OR code = 'CVSU_GENTRI' LIMIT 1", 
    []
);
if ($universityRes && pg_num_rows($universityRes) > 0) {
    $universityId = pg_fetch_result($universityRes, 0, 'university_id');
    out("Found university: Cavite State University - General Trias Campus (ID: $universityId)");
} else {
    // Insert if not exists
    $insertUni = pg_query_params($connection,
        "INSERT INTO universities (name, code) VALUES ($1, $2) RETURNING university_id",
        ['Cavite State University - General Trias Campus', 'CVSU_GENTRI']
    );
    if ($insertUni && pg_num_rows($insertUni) > 0) {
        $universityId = pg_fetch_result($insertUni, 0, 'university_id');
        out("Created university: Cavite State University - General Trias Campus (ID: $universityId)");
    } else {
        out("ERROR: Could not find or create university. Using default ID 1.");
        $universityId = 1;
    }
}

// Get year levels mapping
$yearLevelsRes = pg_query($connection, "SELECT year_level_id, name, code FROM year_levels ORDER BY sort_order");
$yearLevels = [];
while ($row = pg_fetch_assoc($yearLevelsRes)) {
    $yearLevels[$row['name']] = $row['year_level_id'];
    $yearLevels[$row['code']] = $row['year_level_id'];
}
out("Year levels loaded: " . implode(', ', array_keys($yearLevels)));

// Get barangays mapping
$barangaysRes = pg_query_params($connection, "SELECT barangay_id, name FROM barangays WHERE municipality_id = $1", [$municipalityId]);
$barangays = [];
while ($row = pg_fetch_assoc($barangaysRes)) {
    // Normalize name for matching
    $normalizedName = strtolower(trim($row['name']));
    $barangays[$normalizedName] = $row['barangay_id'];
    // Also add without "(Barangay X)" suffix
    $simpleName = preg_replace('/\s*\(barangay\s*\d+\)\s*/i', '', $normalizedName);
    $barangays[$simpleName] = $row['barangay_id'];
}
out("Barangays loaded: " . count($barangays) . " entries");

// Function to find barangay ID
function findBarangayId($name, $barangays, $connection, $municipalityId) {
    $normalized = strtolower(trim($name));
    
    // Direct match
    if (isset($barangays[$normalized])) {
        return $barangays[$normalized];
    }
    
    // Try without "(Barangay X)" suffix
    $simpleName = preg_replace('/\s*\(barangay\s*\d+\)\s*/i', '', $normalized);
    if (isset($barangays[$simpleName])) {
        return $barangays[$simpleName];
    }
    
    // Try database ILIKE search
    $res = pg_query_params($connection, 
        "SELECT barangay_id FROM barangays WHERE municipality_id = $1 AND name ILIKE $2 LIMIT 1",
        [$municipalityId, '%' . $name . '%']
    );
    if ($res && pg_num_rows($res) > 0) {
        return pg_fetch_result($res, 0, 'barangay_id');
    }
    
    // Try with just the main name part
    $parts = explode('(', $name);
    $mainName = trim($parts[0]);
    $res = pg_query_params($connection,
        "SELECT barangay_id FROM barangays WHERE municipality_id = $1 AND name ILIKE $2 LIMIT 1",
        [$municipalityId, '%' . $mainName . '%']
    );
    if ($res && pg_num_rows($res) > 0) {
        return pg_fetch_result($res, 0, 'barangay_id');
    }
    
    return null;
}

// Function to parse year level
function parseYearLevel($yearText) {
    $yearText = trim($yearText);
    
    // Handle special case like "AY 2022–2023, AY 2023–2024, AY 2024–2025"
    if (stripos($yearText, 'AY') !== false) {
        return ['year_level_id' => 4, 'current_year_level' => '4th Year', 'is_graduating' => true];
    }
    
    if (stripos($yearText, '1st') !== false) {
        return ['year_level_id' => 1, 'current_year_level' => '1st Year', 'is_graduating' => false];
    }
    if (stripos($yearText, '2nd') !== false) {
        return ['year_level_id' => 2, 'current_year_level' => '2nd Year', 'is_graduating' => false];
    }
    if (stripos($yearText, '3rd') !== false) {
        return ['year_level_id' => 3, 'current_year_level' => '3rd Year', 'is_graduating' => false];
    }
    if (stripos($yearText, '4th') !== false) {
        return ['year_level_id' => 4, 'current_year_level' => '4th Year', 'is_graduating' => true];
    }
    
    // Default to 2nd year
    return ['year_level_id' => 2, 'current_year_level' => '2nd Year', 'is_graduating' => false];
}

// Function to parse name and extract first, middle, last
function parseName($fullName) {
    $fullName = trim($fullName);
    
    // Handle "Last, First Middle" format
    if (strpos($fullName, ',') !== false) {
        $parts = explode(',', $fullName, 2);
        $lastName = trim($parts[0]);
        $restParts = preg_split('/\s+/', trim($parts[1]));
        $firstName = array_shift($restParts) ?? '';
        $middleName = implode(' ', $restParts);
        return ['first' => $firstName, 'middle' => $middleName, 'last' => $lastName];
    }
    
    // Handle "First Middle Last" format
    $parts = preg_split('/\s+/', $fullName);
    
    // Remove extension names
    $extensions = ['Jr.', 'Jr', 'Sr.', 'Sr', 'III', 'II', 'IV'];
    $parts = array_filter($parts, function($p) use ($extensions) {
        return !in_array($p, $extensions);
    });
    $parts = array_values($parts);
    
    if (count($parts) >= 3) {
        $firstName = $parts[0];
        $lastName = array_pop($parts);
        array_shift($parts); // Remove first name
        $middleName = implode(' ', $parts);
    } elseif (count($parts) == 2) {
        $firstName = $parts[0];
        $lastName = $parts[1];
        $middleName = '';
    } else {
        $firstName = $fullName;
        $lastName = '';
        $middleName = '';
    }
    
    return ['first' => $firstName, 'middle' => $middleName, 'last' => $lastName];
}

// Function to generate mother's maiden name based on surname with random suffix
function generateMaidenName($lastName) {
    $commonSurnames = [
        'Santos', 'Reyes', 'Cruz', 'Garcia', 'Torres', 'Ramos', 'Dela Cruz', 
        'Gonzales', 'Mendoza', 'Lopez', 'Martinez', 'Rodriguez', 'Hernandez',
        'Perez', 'Flores', 'Rivera', 'Castillo', 'Morales', 'Ortiz', 'Villanueva',
        'Bautista', 'Aquino', 'Fernandez', 'Navarro', 'Santiago', 'Pascual'
    ];
    
    // Pick a random surname different from their last name
    $maidenName = $commonSurnames[array_rand($commonSurnames)];
    while (strtolower($maidenName) == strtolower($lastName)) {
        $maidenName = $commonSurnames[array_rand($commonSurnames)];
    }
    
    // Add random suffix to avoid triggering duplicate detection
    $suffix = chr(rand(65, 90)) . rand(10, 99); // e.g., "A23", "K87"
    
    return $maidenName . $suffix;
}

// Function to generate a unique student ID
function generateStudentId($connection, $municipalityId) {
    $prefix = "GENERALTRIAS";
    $year = date('Y');
    
    // Get next sequence number
    $res = pg_query_params($connection,
        "SELECT COUNT(*) + 1 as next_num FROM students WHERE student_id LIKE $1",
        [$prefix . '-' . $year . '-%']
    );
    $nextNum = pg_fetch_result($res, 0, 'next_num');
    
    // Add random suffix for uniqueness
    $randomSuffix = strtoupper(substr(md5(uniqid()), 0, 6));
    
    return $prefix . '-' . $year . '-' . $nextNum . '-' . $randomSuffix;
}

// Student data to register
$students = [
    ['email' => 'itsadrianalvarez@gmail.com', 'name' => 'Adrian P. Alvarez', 'year' => '3rd Year', 'barangay' => 'Vibora (Barangay 6)'],
    ['email' => 'gtc.aeronxyrelle.arellano@cvsu.edu.ph', 'name' => 'Aeron Xyrelle S. Arellano', 'year' => '2nd Year', 'barangay' => 'Vibora (Barangay 6)'],
    ['email' => 'andreajemei.butor@cvsu.edu.ph', 'name' => 'Andrea Jemei G. Butor', 'year' => '1st Year', 'barangay' => 'Bacao I'],
    ['email' => 'angelsamaniego512@gmail.com', 'name' => 'Angel Mae Mendoza Samaniego', 'year' => '3rd Year', 'barangay' => 'Buenavista II'],
    ['email' => 'angela.noay@cvsu.edu.ph', 'name' => 'Angela Cassandra Noay', 'year' => '2nd Year', 'barangay' => 'Pasong Kawayan II'],
    ['email' => 'mooncollideaika@gmail.com', 'name' => 'Angelika C. Obingayan', 'year' => '3rd Year', 'barangay' => 'Buenavista II'],
    ['email' => 'antonettegrapiza0@gmail.com', 'name' => 'Antonette M. Grapiza', 'year' => '2nd Year', 'barangay' => 'San Francisco'],
    ['email' => 'ataliajezreel@gmail.com', 'name' => 'Atalia Jezreel T. Velasco', 'year' => '2nd Year', 'barangay' => 'Pasong Kawayan II'],
    ['email' => 'buenajhoyce11@gmail.com', 'name' => 'Joyce Ann Buena', 'year' => '3rd Year', 'barangay' => 'Pasong Camachile I'],
    ['email' => 'desunia.camille07@gmail.com', 'name' => 'Camille E. Desunia', 'year' => '2nd Year', 'barangay' => 'Santa Clara'],
    ['email' => 'cranario43@gmail.com', 'name' => 'Catherine Mae R. Lahoy', 'year' => '3rd Year', 'barangay' => 'Manggahan'],
    ['email' => 'charmaineericka.samano@cvsu.edu.ph', 'name' => 'Charmaine Ericka Lorenzo Samano', 'year' => '1st Year', 'barangay' => 'Santiago'],
    ['email' => 'cristhalrobenta@gmail.com', 'name' => 'Cristhalyn B. Robenta', 'year' => '2nd Year', 'barangay' => 'Santiago'],
    ['email' => 'riodequedannjester@gmail.com', 'name' => 'Dann Jester M. Riodeque', 'year' => '3rd Year', 'barangay' => 'San Francisco'],
    ['email' => 'dustinecf24@gmail.com', 'name' => 'Earl Dustine Cenizal Ferrer', 'year' => '3rd Year', 'barangay' => 'Navarro'],
    ['email' => 'egysherc@gmail.com', 'name' => 'Egysher Josiah D. Cueco', 'year' => '2nd Year', 'barangay' => 'Prinza (Barangay 9)'],
    ['email' => 'emchrystelmendoza17@gmail.com', 'name' => 'Em Chrystel T. Mendoza', 'year' => '3rd Year', 'barangay' => 'Vibora (Barangay 6)'],
    ['email' => 'gabrielle.ferraris@cvsu.edu.ph', 'name' => 'Gabrielle Anne Y. Ferraris', 'year' => '2nd Year', 'barangay' => 'Tapia'],
    ['email' => 'gene.colocado@cvsu.edu.ph', 'name' => 'Gene D. Colocado', 'year' => '1st Year', 'barangay' => 'Pinagtipunan'],
    ['email' => 'heartjoy.abainza@cvsu.edu.ph', 'name' => 'Heart Joy N. Abainza', 'year' => '3rd Year', 'barangay' => 'Santiago'],
    ['email' => 'jelyn.balasabas@cvsu.edu.ph', 'name' => 'Jelyn Caloyloy Balasabas', 'year' => '2nd Year', 'barangay' => 'Tejero'],
    ['email' => 'jennineariann.wiedmier@cvsu.edu.ph', 'name' => 'Jennine Ariann L. Wiedmier', 'year' => '3rd Year', 'barangay' => 'Pasong Camachile II'],
    ['email' => 'gtc.jhewelroseantonette.cruz@cvsu.edu.ph', 'name' => 'Jhewel Rose Antonette T. Cruz', 'year' => '2nd Year', 'barangay' => 'Biclatan'],
    ['email' => 'akilejil81@gmail.com', 'name' => 'Jillian L. Oma', 'year' => '3rd Year', 'barangay' => 'Bacao I'],
    ['email' => 'joannacamille.edu.biz@gmail.com', 'name' => 'Joanna Camille S. Lizardo', 'year' => '2nd Year', 'barangay' => 'Buenavista III'],
    ['email' => 'josefalain.valencia@cvsu.edu.ph', 'name' => 'Josef Alain O. Valencia', 'year' => '3rd Year', 'barangay' => 'Ninety Sixth (Barangay 8)'],
    ['email' => 'josefina.calvelo@cvsu.edu.ph', 'name' => 'Josefina C. Calvelo', 'year' => '1st Year', 'barangay' => 'Pasong Camachile I'],
    ['email' => 'maeoliveros07@gmail.com', 'name' => 'Julia Mae Oliveros', 'year' => '2nd Year', 'barangay' => 'Biclatan'],
    ['email' => 'justincarl.parin@cvsu.edu.ph', 'name' => 'Justin Carl S. Parin', 'year' => '3rd Year', 'barangay' => 'San Gabriel (Barangay 4)'],
    ['email' => 'yukineshi1@gmail.com', 'name' => 'Justin Earl Robrigado', 'year' => '1st Year', 'barangay' => 'Pasong Kawayan II'],
    ['email' => 'kimpaulo.sanchez@cvsu.edu.ph', 'name' => 'Kim Paulo Q. Sanchez', 'year' => '2nd Year', 'barangay' => 'Bacao I'],
    ['email' => 'kirstenclariz525@gmail.com', 'name' => 'Kirsten Clariz G. Dela Peña', 'year' => '3rd Year', 'barangay' => 'Navarro'],
    ['email' => 'kirsten.marron@cvsu.edu.ph', 'name' => 'Kirsten G. Marron', 'year' => '2nd Year', 'barangay' => 'Santiago'],
    ['email' => 'kristine.navales@cvsu.edu.ph', 'name' => 'Kristine V. Navales', 'year' => '3rd Year', 'barangay' => 'Sampalucan (Barangay 2)'],
    ['email' => 'levyiii.legaspi@cvsu.edu.ph', 'name' => 'Levy III F. Legaspi', 'year' => '2nd Year', 'barangay' => 'Navarro'],
    ['email' => 'martinvicencio123@gmail.com', 'name' => 'Martin Jorge G. Vicencio', 'year' => '3rd Year', 'barangay' => 'Pasong Kawayan I'],
    ['email' => 'gtc.merlyn.pascual@cvsu.edu.ph', 'name' => 'Merlyn M. Pascual', 'year' => '2nd Year', 'barangay' => 'Pasong Kawayan I'],
    ['email' => 'allysamae.mesina@cvsu.edu.ph', 'name' => 'Allysa Mae C. Mesina', 'year' => '3rd Year', 'barangay' => 'Santiago'],
    ['email' => 'michaindanan2@gmail.com', 'name' => 'Micha B. Indanan', 'year' => '2nd Year', 'barangay' => 'Santiago'],
    ['email' => 'saicymae.oma@cvsu.edu.ph', 'name' => 'Saicy Mae G. Oma', 'year' => '3rd Year', 'barangay' => 'Bacao I'],
    ['email' => 'rachelleann.collado@cvsu.edu.ph', 'name' => 'Rachelle Ann B. Collado', 'year' => '1st Year', 'barangay' => 'Ninety Sixth (Barangay 8)'],
    ['email' => 'darknest12343@gmail.com', 'name' => 'Ralf Vincent A. Reñeva', 'year' => '3rd Year', 'barangay' => 'Pasong Kawayan II'],
    ['email' => 'mendoza.raven08@gmail.com', 'name' => 'Raven T. Mendoza', 'year' => '2nd Year', 'barangay' => 'Corregidor (Barangay 10)'],
    ['email' => 'rellybhel.antonio@cvsu.edu.ph', 'name' => 'Relly Bhel S. Antonio', 'year' => '3rd Year', 'barangay' => 'Biclatan'],
    ['email' => 'eronvidal07@gmail.com', 'name' => 'Rhon-Eric C. Vidal', 'year' => '4th Year', 'barangay' => 'Pasong Kawayan II'],
    ['email' => 'nevaresrovvijay@gmail.com', 'name' => 'Rovvi Jay Socias Nevares', 'year' => '2nd Year', 'barangay' => 'Pasong Kawayan II'],
    ['email' => 'magkasi.sandarapaz010105@gmail.com', 'name' => 'Sandara Paz D. Dela Torre', 'year' => '3rd Year', 'barangay' => 'Santiago'],
    ['email' => 'sandramae.carranza05@gmail.com', 'name' => 'Sandra Mae T. Carranza', 'year' => '2nd Year', 'barangay' => 'Manggahan'],
    ['email' => 'lubigansrh@gmail.com', 'name' => 'Sarah Jessica B. Lubigan', 'year' => '3rd Year', 'barangay' => 'San Juan I'],
    ['email' => 'mendozaseanti@gmail.com', 'name' => 'Sean Jibren Ojeda Mendoza', 'year' => '2nd Year', 'barangay' => 'Pasong Camachile I'],
    ['email' => 'gtc.sweetallana.jaingue@cvsu.edu.ph', 'name' => 'Sweet G. Jaingue', 'year' => '3rd Year', 'barangay' => 'Pinagtipunan'],
    ['email' => 'trishaannedelacuesta001@gmail.com', 'name' => 'Trisha Anne O. Dela Cuesta', 'year' => '1st Year', 'barangay' => 'Pasong Camachile I'],
    ['email' => 'tyronjamesarevalo17@gmail.com', 'name' => 'Tyron James D. Arevalo', 'year' => '2nd Year', 'barangay' => 'Biclatan'],
    ['email' => 'santosmvince@gmail.com', 'name' => 'Vince Makkoy Santos', 'year' => '3rd Year', 'barangay' => 'Sampalucan (Barangay 2)'],
];

out("\n" . str_repeat("=", 60));
out("Starting registration of " . count($students) . " students...");
out(str_repeat("=", 60) . "\n");

$successCount = 0;
$errorCount = 0;
$errors = [];

foreach ($students as $index => $student) {
    $num = $index + 1;
    
    // Parse name
    $nameParts = parseName($student['name']);
    $firstName = $nameParts['first'];
    $middleName = $nameParts['middle'];
    $lastName = $nameParts['last'];
    
    // Parse year level
    $yearInfo = parseYearLevel($student['year']);
    
    // Find barangay
    $barangayId = findBarangayId($student['barangay'], $barangays, $connection, $municipalityId);
    if (!$barangayId) {
        $errors[] = "[$num] {$student['email']}: Barangay not found: {$student['barangay']}";
        $errorCount++;
        continue;
    }
    
    // Check if email already exists
    $existsCheck = pg_query_params($connection, "SELECT student_id FROM students WHERE email = $1", [$student['email']]);
    if ($existsCheck && pg_num_rows($existsCheck) > 0) {
        $existingId = pg_fetch_result($existsCheck, 0, 'student_id');
        out("[$num] SKIP: {$student['email']} - Already registered (ID: $existingId)");
        continue;
    }
    
    // Generate student ID
    $studentId = generateStudentId($connection, $municipalityId);
    
    // Generate mother's maiden name with random suffix
    $mothersMaidenName = generateMaidenName($lastName);
    
    // Generate random phone number (09XX format)
    $mobile = '09' . rand(10, 99) . rand(1000000, 9999999);
    
    // Random birthdate (18-25 years old)
    $age = rand(18, 25);
    $birthYear = date('Y') - $age;
    $birthMonth = rand(1, 12);
    $birthDay = rand(1, 28);
    $bdate = sprintf('%04d-%02d-%02d', $birthYear, $birthMonth, $birthDay);
    
    // Random sex
    $sex = rand(0, 1) ? 'Male' : 'Female';
    
    // Password hash (random password - they'll need to reset)
    $randomPassword = bin2hex(random_bytes(8)); // 16 char random password
    $passwordHash = password_hash($randomPassword, PASSWORD_DEFAULT);
    
    // Current academic year
    $currentAcademicYear = '2025-2026';
    $firstRegisteredAcademicYear = '2024-2025';
    
    // Insert student
    $sql = "INSERT INTO students (
        student_id, first_name, middle_name, last_name, email, mobile, sex, bdate, password,
        municipality_id, barangay_id, university_id, year_level_id, status, current_year_level,
        first_registered_academic_year, current_academic_year, is_graduating, course,
        mothers_maiden_name, needs_document_upload
    ) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13, $14, $15, $16, $17, $18, $19, $20, $21)";
    
    $params = [
        $studentId,
        $firstName,
        $middleName ?: null,
        $lastName,
        $student['email'],
        $mobile,
        $sex,
        $bdate,
        $passwordHash,
        $municipalityId,
        $barangayId,
        $universityId,
        $yearInfo['year_level_id'],
        'applicant', // status
        $yearInfo['current_year_level'],
        $firstRegisteredAcademicYear,
        $currentAcademicYear,
        $yearInfo['is_graduating'] ? 'true' : 'false',
        'BS ', // Generic course
        $mothersMaidenName,
        true // needs_document_upload
    ];
    
    $result = pg_query_params($connection, $sql, $params);
    
    if ($result) {
        out("[$num] SUCCESS: {$student['email']} -> $studentId ({$yearInfo['current_year_level']}, {$student['barangay']})");
        $successCount++;
    } else {
        $error = pg_last_error($connection);
        $errors[] = "[$num] {$student['email']}: $error";
        $errorCount++;
        out("[$num] ERROR: {$student['email']} - $error");
    }
}

out("\n" . str_repeat("=", 60));
out("REGISTRATION COMPLETE");
out(str_repeat("=", 60));
out("Total students: " . count($students));
out("Successfully registered: $successCount");
out("Errors: $errorCount");

if (!empty($errors)) {
    out("\nErrors encountered:");
    foreach ($errors as $error) {
        out("  - $error");
    }
}

out("\nNote: All students have been registered with status 'applicant'.");
out("They will need to upload their documents and may need password resets.");
out("University: Cavite State University - General Trias Campus");

if (!$isCli) {
    echo "</pre>";
    echo "</body></html>";
}
?>
