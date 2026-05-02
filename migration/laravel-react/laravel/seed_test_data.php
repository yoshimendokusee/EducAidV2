<?php
/**
 * Seed test data for Phase 10 migration testing
 * Populates students table with sample applicants and related data
 */

$envPath = __DIR__ . '/.env';
$lines = file($envPath);
foreach ($lines as $line) {
    if (strpos($line, '=') && strpos(trim($line), '#') !== 0) {
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

$conn_str = "host={$_ENV['DB_HOST']} port={$_ENV['DB_PORT']} dbname={$_ENV['DB_DATABASE']} user={$_ENV['DB_USERNAME']} password={$_ENV['DB_PASSWORD']}";
$connection = pg_connect($conn_str);

if (!$connection) {
    echo "❌ Failed to connect to database\n";
    exit(1);
}

echo "🌱 Seeding test data...\n\n";

// Clear existing students (for clean test)
pg_query($connection, "DELETE FROM students WHERE status IN ('applicant', 'enrolled', 'approved')");
pg_query($connection, "DELETE FROM documents");
pg_query($connection, "DELETE FROM distribution_student_records");
pg_query($connection, "DELETE FROM distribution_snapshots");
echo "✓ Cleared existing test data\n";

// Get a municipality for reference
$result = pg_query($connection, "SELECT municipality_id FROM municipalities LIMIT 1");
$muni = pg_fetch_assoc($result);
$municipality_id = $muni['municipality_id'] ?? 1;

// Get a year level for reference
$result = pg_query($connection, "SELECT year_level_id FROM year_levels LIMIT 1");
$year = pg_fetch_assoc($result);
$year_level_id = $year['year_level_id'] ?? 1;

// Get a barangay
$result = pg_query_params($connection, "SELECT barangay_id FROM barangays WHERE municipality_id = $1 LIMIT 1", [$municipality_id]);
$baran = pg_fetch_assoc($result);
$barangay_id = $baran['barangay_id'] ?? 1;

$applicants = [
    [
        'first_name' => 'Juan',
        'middle_name' => 'de la',
        'last_name' => 'Cruz',
        'email' => 'juan.delacruz@example.com',
        'mobile' => '09171234567',
        'sex' => 'Male',
        'bdate' => '2005-03-15',
        'status' => 'applicant',
        'school_student_id' => 'CSH-2024-001',
    ],
    [
        'first_name' => 'Maria',
        'middle_name' => 'Santos',
        'last_name' => 'Garcia',
        'email' => 'maria.santos@example.com',
        'mobile' => '09171234568',
        'sex' => 'Female',
        'bdate' => '2006-05-22',
        'status' => 'applicant',
        'school_student_id' => 'NVA-2024-045',
    ],
    [
        'first_name' => 'Carlos',
        'middle_name' => 'Reyes',
        'last_name' => 'Lim',
        'email' => 'carlos.lim@example.com',
        'mobile' => '09171234569',
        'sex' => 'Male',
        'bdate' => '2004-11-08',
        'status' => 'applicant',
        'school_student_id' => 'SRH-2024-089',
    ],
    [
        'first_name' => 'Ana',
        'middle_name' => 'Marie',
        'last_name' => 'Reyes',
        'email' => 'ana.reyes@example.com',
        'mobile' => '09171234570',
        'sex' => 'Female',
        'bdate' => '2005-07-30',
        'status' => 'applicant',
        'school_student_id' => 'EPU-2024-156',
    ],
    [
        'first_name' => 'Pedro',
        'middle_name' => 'Andres',
        'last_name' => 'Mercado',
        'email' => 'pedro.mercado@example.com',
        'mobile' => '09171234571',
        'sex' => 'Male',
        'bdate' => '2006-02-14',
        'status' => 'applicant',
        'school_student_id' => 'CSH-2024-234',
    ],
];

$now = date('Y-m-d H:i:s');
$inserted = 0;

foreach ($applicants as $applicant) {
    $student_id = 'EDUCAID-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
    
    $result = pg_query_params($connection,
        "INSERT INTO students (
            municipality_id, first_name, middle_name, last_name, email, mobile, 
            password, sex, status, bdate, barangay_id, year_level_id, 
            student_id, application_date, documents_submitted, status_blacklisted
        ) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13, $14, $15, $16)",
        [
            $municipality_id,
            $applicant['first_name'],
            $applicant['middle_name'] ?? null,
            $applicant['last_name'],
            $applicant['email'],
            $applicant['mobile'],
            password_hash('password123', PASSWORD_DEFAULT),
            $applicant['sex'],
            $applicant['status'],
            $applicant['bdate'],
            $barangay_id,
            $year_level_id,
            $student_id,
            $now,
            null,  // documents_submitted
            null   // status_blacklisted
        ]
    );
    
    if ($result) {
        $inserted++;
        echo "  ✓ Created: {$applicant['first_name']} {$applicant['last_name']} ({$student_id})\n";
    } else {
        echo "  ❌ Failed: {$applicant['first_name']} {$applicant['last_name']}\n";
    }
}

echo "\n✓ Inserted $inserted applicants\n";

// Verify
$result = pg_query($connection, "SELECT COUNT(*) as count FROM students WHERE status = 'applicant'");
$row = pg_fetch_assoc($result);
echo "✓ Total applicants in database: " . $row['count'] . "\n";

pg_close($connection);
echo "\n🎉 Test data seeded successfully!\n";
