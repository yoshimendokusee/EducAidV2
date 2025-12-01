<?php
/**
 * Fix signup slot - Change Nov 29 to Nov 23 and assign students
 */

$RAILWAY_DATABASE_URL = getenv('DATABASE_URL') ?: getenv('DATABASE_PUBLIC_URL') ?: '';

if (empty($RAILWAY_DATABASE_URL)) {
    if (file_exists(__DIR__ . '/config/database.php')) {
        require_once __DIR__ . '/config/database.php';
    } else {
        echo "ERROR: No database connection\n";
        exit(1);
    }
} else {
    $parts = parse_url($RAILWAY_DATABASE_URL);
    $connString = sprintf(
        'host=%s port=%s dbname=%s user=%s password=%s connect_timeout=30',
        $parts['host'] ?? 'localhost',
        $parts['port'] ?? 5432,
        ltrim($parts['path'] ?? '/railway', '/'),
        $parts['user'] ?? 'postgres',
        $parts['pass'] ?? ''
    );
    $connection = @pg_connect($connString);
}

if (!$connection) {
    echo "ERROR: Database connection failed\n";
    exit(1);
}

echo "Connected to database.\n\n";

// First, let's see the actual structure of signup_slots
echo "=== SIGNUP_SLOTS TABLE STRUCTURE ===\n";
$structRes = pg_query($connection, "SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'signup_slots' ORDER BY ordinal_position");
if ($structRes) {
    while ($row = pg_fetch_assoc($structRes)) {
        echo "  {$row['column_name']} ({$row['data_type']})\n";
    }
}

echo "\n=== ALL SIGNUP SLOTS ===\n";
$slotsRes = pg_query($connection, "SELECT * FROM signup_slots ORDER BY slot_id DESC LIMIT 10");
if ($slotsRes) {
    while ($row = pg_fetch_assoc($slotsRes)) {
        echo "Slot ID: {$row['slot_id']}\n";
        foreach ($row as $key => $value) {
            if ($key !== 'slot_id') {
                echo "  $key: $value\n";
            }
        }
        echo "\n";
    }
} else {
    echo "Error querying slots: " . pg_last_error($connection) . "\n";
}

echo "Done!\n";
