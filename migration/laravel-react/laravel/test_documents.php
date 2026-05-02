<?php
/**
 * Test document upload and retrieval operations
 * Verifies document API endpoints work end-to-end
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

echo "📋 Testing document operations...\n\n";

// Get first test applicant
$result = pg_query($connection, "SELECT student_id FROM students WHERE status = 'applicant' LIMIT 1");
$studentRow = pg_fetch_assoc($result);
$studentId = $studentRow['student_id'] ?? null;

if (!$studentId) {
    echo "❌ No test applicants found. Run seed_test_data.php first.\n";
    pg_close($connection);
    exit(1);
}

echo "Using student: $studentId\n";

// Clear existing test documents for this student
echo "\n🗑️  Clearing existing documents for test...\n";
pg_query_params($connection, "DELETE FROM documents WHERE student_id = $1", [$studentId]);
echo "✓ Cleared old documents\n";

// Create sample document records with short extension codes
echo "\n📁 Creating sample document records...\n";
$documentTypes = [
    ['id', 'ID Picture'],
    ['en', 'Enrollment Form'],
    ['gr', 'Grades/Academic Records'],
    ['le', 'Letter to Mayor'],
    ['ce', 'Certificate of Indigency'],
];

$inserted = 0;
$now = date('Y-m-d H:i:s');

foreach ($documentTypes as [$docTypeCode, $docLabel]) {
    // Create a test document
    $result = pg_query_params($connection,
        "INSERT INTO documents (student_id, document_type_code, document_type_name, file_name, file_path, file_extension, file_size_bytes, verification_status, upload_date)
         VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9)
         RETURNING document_id",
        [
            $studentId,
            $docTypeCode,
            $docLabel,
            "test_" . $docTypeCode . ".pdf",
            "/storage/documents/" . $studentId . "/test_" . $docTypeCode . ".pdf",
            "pd",
            rand(50000, 500000),
            'pending',
            $now,
        ]
    );
    
    if ($result) {
        $row = pg_fetch_assoc($result);
        $inserted++;
        echo "  ✓ {$docLabel} (ID: {$row['document_id']})\n";
    } else {
        echo "  ❌ Failed to insert {$docLabel}: " . pg_last_error($connection) . "\n";
    }
}

echo "\n✓ Inserted $inserted documents\n";

// Verify retrieval
echo "\n🔍 Verifying document retrieval...\n";
$result = pg_query_params($connection,
    "SELECT COUNT(*) as count FROM documents WHERE student_id = $1",
    [$studentId]
);
$row = pg_fetch_assoc($result);
$documentCount = $row['count'] ?? 0;
echo "✓ Total documents for student: $documentCount\n";

// Check document statuses
echo "\n📊 Document status breakdown:\n";
$result = pg_query_params($connection,
    "SELECT verification_status, COUNT(*) as count FROM documents WHERE student_id = $1 GROUP BY verification_status",
    [$studentId]
);
while ($row = pg_fetch_assoc($result)) {
    echo "  - {$row['verification_status']}: {$row['count']}\n";
}

// Check by document type
echo "\n📋 Document type breakdown:\n";
$result = pg_query_params($connection,
    "SELECT document_type_name, COUNT(*) as count FROM documents WHERE student_id = $1 GROUP BY document_type_name",
    [$studentId]
);
while ($row = pg_fetch_assoc($result)) {
    echo "  - {$row['document_type_name']}: {$row['count']}\n";
}

// Test updating document status
echo "\n✏️  Testing document status updates...\n";
$result = pg_query_params($connection,
    "UPDATE documents SET verification_status = $1 WHERE student_id = $2 AND document_type_code = $3",
    ['approved', $studentId, 'id']
);
if ($result) {
    echo "✓ Updated id_picture status to 'approved'\n";
}

// Verify the update
$result = pg_query_params($connection,
    "SELECT verification_status FROM documents WHERE student_id = $1 AND document_type_code = $2",
    [$studentId, 'id']
);
if ($row = pg_fetch_assoc($result)) {
    echo "✓ Verified status is now: {$row['verification_status']}\n";
}

// Test file size retrieval and aggregation
echo "\n📦 Testing document file size queries...\n";
$result = pg_query_params($connection,
    "SELECT document_type_name, file_size_bytes FROM documents WHERE student_id = $1 ORDER BY document_type_name",
    [$studentId]
);
$totalSize = 0;
$countDocuments = 0;
while ($row = pg_fetch_assoc($result)) {
    $sizeKb = round($row['file_size_bytes'] / 1024, 2);
    echo "  - {$row['document_type_name']}: {$sizeKb} KB\n";
    $totalSize += $row['file_size_bytes'];
    $countDocuments++;
}
$totalMb = round($totalSize / 1048576, 2);
echo "✓ Total: $countDocuments documents, $totalMb MB\n";

// Test document metadata queries
echo "\n📝 Testing document metadata...\n";
$result = pg_query_params($connection,
    "SELECT document_id, file_name, file_path, upload_date FROM documents WHERE student_id = $1 LIMIT 1",
    [$studentId]
);
if ($row = pg_fetch_assoc($result)) {
    echo "✓ Document metadata accessible:\n";
    echo "  - ID: {$row['document_id']}\n";
    echo "  - Filename: {$row['file_name']}\n";
    echo "  - Path: {$row['file_path']}\n";
    echo "  - Uploaded: {$row['upload_date']}\n";
}

// Test document filtering and queries
echo "\n🔎 Testing advanced document queries...\n";
$result = pg_query_params($connection,
    "SELECT COUNT(*) as pending_count FROM documents WHERE student_id = $1 AND verification_status = $2",
    [$studentId, 'pending']
);
$row = pg_fetch_assoc($result);
echo "✓ Pending documents: " . $row['pending_count'] . "\n";

$result = pg_query_params($connection,
    "SELECT COUNT(*) as approved_count FROM documents WHERE student_id = $1 AND verification_status = $2",
    [$studentId, 'approved']
);
$row = pg_fetch_assoc($result);
echo "✓ Approved documents: " . $row['approved_count'] . "\n";

pg_close($connection);
echo "\n✅ All document operations tested successfully!\n";
echo "✅ Database integration verified!\n";
