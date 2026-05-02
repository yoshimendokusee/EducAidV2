<?php
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

// Check document_id column definition
$result = pg_query($connection, "SELECT column_name, column_default FROM information_schema.columns WHERE table_name = 'documents' AND column_name = 'document_id'");
if ($row = pg_fetch_assoc($result)) {
    echo "Column: {$row['column_name']}, Default: {$row['column_default']}\n";
}

// Check if there's a sequence
$result = pg_query($connection, "SELECT sequencename FROM pg_sequences WHERE schemaname = 'public' AND tablename = 'documents'");
if ($row = pg_fetch_assoc($result)) {
    echo "Sequence: {$row['sequencename']}\n";
}

// Try inserting without document_id
echo "\nTrying insert without document_id:\n";
$now = date('Y-m-d H:i:s');
$result = pg_query_params($connection,
    "INSERT INTO documents (student_id, document_type_code, document_type_name, file_name, file_path, file_extension, file_size_bytes, verification_status, upload_date)
     VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9)
     RETURNING document_id",
    ['TEST-STUDENT-001', 'te', 'Test Document', 'test.pdf', '/storage/test.pdf', 'pd', 50000, 'pending', $now]
);

if ($result) {
    $row = pg_fetch_assoc($result);
    echo "Success! Generated document_id: {$row['document_id']}\n";
} else {
    echo "Failed: " . pg_last_error($connection) . "\n";
}

pg_close($connection);
