<?php
// Simple test to check database and what data exists

$envPath = __DIR__ . '/.env';
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
            [$key, $value] = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($value);
        }
    }
}

$host = $_ENV['DB_HOST'] ?? 'localhost';
$port = $_ENV['DB_PORT'] ?? '5432';
$database = $_ENV['DB_DATABASE'] ?? 'educaid';
$user = $_ENV['DB_USERNAME'] ?? 'postgres';
$password = $_ENV['DB_PASSWORD'] ?? '';

$conn_str = "host=$host port=$port dbname=$database user=$user password=$password";

try {
    $connection = pg_connect($conn_str);
    if (!$connection) {
        echo "Failed to connect to database\n";
        exit(1);
    }
    
    echo "✓ Connected to PostgreSQL\n";
    echo "Database: $database\n\n";
    
    // Get tables
    $result = pg_query($connection, "SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' ORDER BY table_name");
    $tables = [];
    while ($row = pg_fetch_assoc($result)) {
        $tables[] = $row['table_name'];
    }
    echo "Tables (" . count($tables) . "):\n";
    foreach ($tables as $table) {
        echo "  - $table\n";
    }
    echo "\n";
    
    // Check students table
    echo "=== STUDENTS TABLE ===\n";
    $result = pg_query($connection, "SELECT COUNT(*) as count FROM students");
    if ($result) {
        $row = pg_fetch_assoc($result);
        echo "Total students: " . $row['count'] . "\n";
    }
    
    // Check for applicants
    $result = pg_query($connection, "SELECT COUNT(*) as count FROM students WHERE status = 'applicant'");
    if ($result) {
        $row = pg_fetch_assoc($result);
        echo "Applicants (status='applicant'): " . $row['count'] . "\n";
    }
    
    // Get sample applicant data
    echo "\nSample applicants:\n";
    $result = pg_query($connection, "SELECT student_id, first_name, last_name, status, created_at FROM students WHERE status = 'applicant' LIMIT 5");
    if ($result) {
        while ($row = pg_fetch_assoc($result)) {
            echo "  - [{$row['student_id']}] {$row['first_name']} {$row['last_name']} ({$row['status']})\n";
        }
    }
    
    // Check distributions
    echo "\n=== DISTRIBUTIONS TABLE ===\n";
    $result = pg_query($connection, "SELECT COUNT(*) as count FROM distributions");
    if ($result) {
        $row = pg_fetch_assoc($result);
        echo "Total distributions: " . $row['count'] . "\n";
    }
    
    // Sample distributions
    echo "\nSample distributions:\n";
    $result = pg_query($connection, "SELECT distribution_id, distribution_name, status FROM distributions LIMIT 5");
    if ($result) {
        while ($row = pg_fetch_assoc($result)) {
            echo "  - [{$row['distribution_id']}] {$row['distribution_name']} ({$row['status']})\n";
        }
    }
    
    pg_close($connection);
    echo "\n✓ Test completed successfully\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
