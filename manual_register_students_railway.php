<?php
/**
 * Manual Student Registration Script - RAILWAY VERSION
 * December 2025 - Batch registration for 54 students
 * All students attend Cavite State University - General Trias Campus
 * 
 * SET YOUR RAILWAY DATABASE_PUBLIC_URL BELOW before running!
 * 
 * Usage: php manual_register_students_railway.php
 */

// ============================================
// RAILWAY DATABASE CONNECTION
// ============================================
// Use environment variable when running with `railway run`
$RAILWAY_DATABASE_URL = getenv('DATABASE_URL') ?: getenv('DATABASE_PUBLIC_URL') ?: '';

if (empty($RAILWAY_DATABASE_URL)) {
    // Fallback: try to load from config
    if (file_exists(__DIR__ . '/config/database.php')) {
        require_once __DIR__ . '/config/database.php';
        if (isset($connection) && $connection) {
            out("Using existing database connection from config.");
            goto connected;
        }
    }
    out("ERROR: No DATABASE_URL found. Run with: railway run php manual_register_students_railway.php");
    exit(1);
}
// ============================================

// CLI or Web output handling
$isCli = php_sapi_name() === 'cli';
function out($msg) {
    global $isCli;
    if ($isCli) {
        echo $msg . "\n";
    } else {
        echo htmlspecialchars($msg) . "<br>\n";
        ob_flush();
        flush();
    }
}

if (!$isCli) {
    echo "<html><head><title>Manual Student Registration - Railway</title></head><body>";
    echo "<h1>Manual Student Registration - Railway - December 2025</h1>";
    echo "<pre>";
    ob_implicit_flush(true);
}

out("Connecting to Railway database...");

// Parse Railway DATABASE_URL
$parts = parse_url($RAILWAY_DATABASE_URL);
$dbHost = $parts['host'] ?? 'localhost';
$dbPort = $parts['port'] ?? 5432;
$dbName = ltrim($parts['path'] ?? '/railway', '/');
$dbUser = $parts['user'] ?? 'postgres';
$dbPass = $parts['pass'] ?? '';

$connString = sprintf(
    'host=%s port=%s dbname=%s user=%s password=%s connect_timeout=30',
    $dbHost,
    $dbPort,
    $dbName,
    $dbUser,
    $dbPass
);

$connection = @pg_connect($connString);

if (!$connection) {
    out("ERROR: Could not connect to Railway database!");
    out("Host: $dbHost, Port: $dbPort, DB: $dbName");
    exit(1);
}

connected:
out("Connected to Railway database successfully!");
if (isset($dbHost)) out("Host: $dbHost, Port: $dbPort, DB: $dbName");

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
        out("WARNING: Could not find or create university. Using default ID 1.");
        $universityId = 1;
    }
}

// Find the slot for November 23, 2025 at 3:00 AM or create one
$slotDate = '2025-11-23';
$slotTime = '03:00:00';
$slotDateTime = "$slotDate $slotTime";

$slotRes = pg_query_params($connection,
    "SELECT slot_id FROM slots WHERE municipality_id = $1 AND date::date = $2::date LIMIT 1",
    [$municipalityId, $slotDate]
);

if ($slotRes && pg_num_rows($slotRes) > 0) {
    $slotId = pg_fetch_result($slotRes, 0, 'slot_id');
    out("Found existing slot for $slotDate (ID: $slotId)");
} else {
    // Create a new slot
    $insertSlot = pg_query_params($connection,
        "INSERT INTO slots (municipality_id, date, max_registrations, is_active) VALUES ($1, $2, 500, true) RETURNING slot_id",
        [$municipalityId, $slotDateTime]
    );
    if ($insertSlot && pg_num_rows($insertSlot) > 0) {
        $slotId = pg_fetch_result($insertSlot, 0, 'slot_id');
        out("Created new slot for $slotDateTime (ID: $slotId)");
    } else {
        out("WARNING: Could not create slot. Students will be registered without slot_id.");
        $slotId = null;
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
    $normalizedName = strtolower(trim($row['name']));
    $barangays[$normalizedName] = $row['barangay_id'];
    $simpleName = preg_replace('/\s*\(barangay\s*\d+\)\s*/i', '', $normalizedName);
    $barangays[$simpleName] = $row['barangay_id'];
}
out("Barangays loaded: " . count($barangays) . " entries");

// List barangays for debugging
out("\nAvailable barangays:");
$barangaysListRes = pg_query_params($connection, "SELECT barangay_id, name FROM barangays WHERE municipality_id = $1 ORDER BY name", [$municipalityId]);
while ($row = pg_fetch_assoc($barangaysListRes)) {
    out("  [{$row['barangay_id']}] {$row['name']}");
}

// Function to find barangay ID - includes "1896" mapping
function findBarangayId($name, $barangays, $connection, $municipalityId) {
    $normalized = strtolower(trim($name));
    
    // Special mapping for Ninety Sixth / 1896
    if (stripos($name, 'Ninety Sixth') !== false || stripos($name, '96') !== false || $name === '1896') {
        // Search for 1896 barangay
        $res = pg_query_params($connection,
            "SELECT barangay_id FROM barangays WHERE municipality_id = $1 AND (name ILIKE '%1896%' OR name ILIKE '%ninety%sixth%') LIMIT 1",
            [$municipalityId]
        );
        if ($res && pg_num_rows($res) > 0) {
            return pg_fetch_result($res, 0, 'barangay_id');
        }
    }
    
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
    
    return ['year_level_id' => 2, 'current_year_level' => '2nd Year', 'is_graduating' => false];
}

// Function to parse name
function parseName($fullName) {
    $fullName = trim($fullName);
    
    if (strpos($fullName, ',') !== false) {
        $parts = explode(',', $fullName, 2);
        $lastName = trim($parts[0]);
        $restParts = preg_split('/\s+/', trim($parts[1]));
        $firstName = array_shift($restParts) ?? '';
        $middleName = implode(' ', $restParts);
        return ['first' => $firstName, 'middle' => $middleName, 'last' => $lastName];
    }
    
    $parts = preg_split('/\s+/', $fullName);
    $extensions = ['Jr.', 'Jr', 'Sr.', 'Sr', 'III', 'II', 'IV'];
    $parts = array_filter($parts, function($p) use ($extensions) {
        return !in_array($p, $extensions);
    });
    $parts = array_values($parts);
    
    if (count($parts) >= 3) {
        $firstName = $parts[0];
        $lastName = array_pop($parts);
        array_shift($parts);
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

// Function to generate mother's maiden name
function generateMaidenName($lastName) {
    $commonSurnames = [
        'Santos', 'Reyes', 'Cruz', 'Garcia', 'Torres', 'Ramos', 'Dela Cruz', 
        'Gonzales', 'Mendoza', 'Lopez', 'Martinez', 'Rodriguez', 'Hernandez',
        'Perez', 'Flores', 'Rivera', 'Castillo', 'Morales', 'Ortiz', 'Villanueva',
        'Bautista', 'Aquino', 'Fernandez', 'Navarro', 'Santiago', 'Pascual'
    ];
    
    $maidenName = $commonSurnames[array_rand($commonSurnames)];
    while (strtolower($maidenName) == strtolower($lastName)) {
        $maidenName = $commonSurnames[array_rand($commonSurnames)];
    }
    
    $suffix = chr(rand(65, 90)) . rand(10, 99);
    return $maidenName . $suffix;
}

// Function to generate student ID
function generateStudentId($connection, $municipalityId) {
    $prefix = "GENERALTRIAS";
    $year = date('Y');
    
    $res = pg_query_params($connection,
        "SELECT COUNT(*) + 1 as next_num FROM students WHERE student_id LIKE $1",
        [$prefix . '-' . $year . '-%']
    );
    $nextNum = pg_fetch_result($res, 0, 'next_num');
    $randomSuffix = strtoupper(substr(md5(uniqid()), 0, 6));
    
    return $prefix . '-' . $year . '-' . $nextNum . '-' . $randomSuffix;
}

// Student data - ALL 54 students (barangay "Ninety Sixth" mapped to "1896")
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
    ['email' => 'josefalain.valencia@cvsu.edu.ph', 'name' => 'Josef Alain O. Valencia', 'year' => '3rd Year', 'barangay' => '1896'],
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
    ['email' => 'rachelleann.collado@cvsu.edu.ph', 'name' => 'Rachelle Ann B. Collado', 'year' => '1st Year', 'barangay' => '1896'],
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
out("Slot: November 23, 2025 at 3:00 AM" . ($slotId ? " (ID: $slotId)" : " (No slot)"));
out(str_repeat("=", 60) . "\n");

$successCount = 0;
$skipCount = 0;
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
        out("[$num] ERROR: {$student['email']} - Barangay not found: {$student['barangay']}");
        continue;
    }
    
    // Check if email already exists
    $existsCheck = pg_query_params($connection, "SELECT student_id FROM students WHERE email = $1", [$student['email']]);
    if ($existsCheck && pg_num_rows($existsCheck) > 0) {
        $existingId = pg_fetch_result($existsCheck, 0, 'student_id');
        out("[$num] SKIP: {$student['email']} - Already registered (ID: $existingId)");
        $skipCount++;
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
    
    // Password hash (random password)
    $randomPassword = bin2hex(random_bytes(8));
    $passwordHash = password_hash($randomPassword, PASSWORD_DEFAULT);
    
    // Academic years
    $currentAcademicYear = '2025-2026';
    $firstRegisteredAcademicYear = '2024-2025';
    
    // Application date = November 23, 2025 at 3:00 AM
    $applicationDate = '2025-11-23 03:00:00';
    
    // Insert student
    $sql = "INSERT INTO students (
        student_id, first_name, middle_name, last_name, email, mobile, sex, bdate, password,
        municipality_id, barangay_id, university_id, year_level_id, status, current_year_level,
        first_registered_academic_year, current_academic_year, is_graduating, course,
        mothers_maiden_name, needs_document_upload, slot_id, application_date
    ) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13, $14, $15, $16, $17, $18, $19, $20, $21, $22, $23)";
    
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
        'applicant',
        $yearInfo['current_year_level'],
        $firstRegisteredAcademicYear,
        $currentAcademicYear,
        $yearInfo['is_graduating'] ? 'true' : 'false',
        'Bachelor of Science',
        $mothersMaidenName,
        true,
        $slotId,
        $applicationDate
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
out("REGISTRATION COMPLETE - RAILWAY DATABASE");
out(str_repeat("=", 60));
out("Total students: " . count($students));
out("Successfully registered: $successCount");
out("Skipped (already exists): $skipCount");
out("Errors: $errorCount");

if (!empty($errors)) {
    out("\nErrors encountered:");
    foreach ($errors as $error) {
        out("  - $error");
    }
}

out("\nAll students registered with:");
out("  - Status: 'applicant'");
out("  - Slot: November 23, 2025 at 3:00 AM");
out("  - University: Cavite State University - General Trias Campus");
out("  - needs_document_upload: true");

pg_close($connection);

if (!$isCli) {
    echo "</pre>";
    echo "</body></html>";
}
?>
