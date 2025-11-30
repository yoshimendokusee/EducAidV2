<?php
/**
 * Railway CLI Migration: Add contact fields to municipalities table
 * Run with: railway run php migrations/run_contact_migration.php
 */

// Connect using DATABASE_URL environment variable (Railway)
$databaseUrl = getenv('DATABASE_URL');
if (!$databaseUrl) {
    die("ERROR: DATABASE_URL not set. Run this with: railway run php migrations/run_contact_migration.php\n");
}

$dbParams = parse_url($databaseUrl);
$host = $dbParams['host'];
$port = $dbParams['port'] ?? 5432;
$user = $dbParams['user'];
$pass = $dbParams['pass'];
$dbname = ltrim($dbParams['path'], '/');

$connection = pg_connect("host=$host port=$port dbname=$dbname user=$user password=$pass");
if (!$connection) {
    die("ERROR: Could not connect to database\n");
}

echo "=== Municipality Contact Fields Migration ===\n\n";

$migrations = [
    [
        'column' => 'contact_phone',
        'sql' => "ALTER TABLE municipalities ADD COLUMN IF NOT EXISTS contact_phone VARCHAR(50) DEFAULT '(046) 886-4454'"
    ],
    [
        'column' => 'contact_email', 
        'sql' => "ALTER TABLE municipalities ADD COLUMN IF NOT EXISTS contact_email VARCHAR(100) DEFAULT 'educaid@generaltrias.gov.ph'"
    ],
    [
        'column' => 'contact_address',
        'sql' => "ALTER TABLE municipalities ADD COLUMN IF NOT EXISTS contact_address TEXT DEFAULT 'General Trias City Hall, Cavite'"
    ],
    [
        'column' => 'office_hours',
        'sql' => "ALTER TABLE municipalities ADD COLUMN IF NOT EXISTS office_hours VARCHAR(100) DEFAULT 'Mon–Fri 8:00AM - 5:00PM'"
    ]
];

foreach ($migrations as $migration) {
    $result = @pg_query($connection, $migration['sql']);
    if ($result) {
        echo "✓ {$migration['column']} - OK\n";
    } else {
        echo "✗ {$migration['column']} - " . pg_last_error($connection) . "\n";
    }
}

echo "\n=== Current Municipality Data ===\n\n";

$query = "SELECT municipality_id, name, contact_phone, contact_email, contact_address, office_hours FROM municipalities ORDER BY municipality_id";
$result = pg_query($connection, $query);

if ($result) {
    while ($row = pg_fetch_assoc($result)) {
        echo "ID: {$row['municipality_id']} - {$row['name']}\n";
        echo "  Phone: " . ($row['contact_phone'] ?? 'Not set') . "\n";
        echo "  Email: " . ($row['contact_email'] ?? 'Not set') . "\n";
        echo "  Address: " . ($row['contact_address'] ?? 'Not set') . "\n";
        echo "  Hours: " . ($row['office_hours'] ?? 'Not set') . "\n\n";
    }
} else {
    echo "Error fetching data: " . pg_last_error($connection) . "\n";
}

echo "=== Migration Complete ===\n";
pg_close($connection);
