<?php
/**
 * Migration: Add contact information fields to municipalities table
 * This centralizes contact info so it can be used across topbar, footer, and all pages
 * 
 * Run this file once to add the columns
 */

require_once __DIR__ . '/config/database.php';

echo "<h2>Adding Contact Fields to Municipalities Table</h2>";
echo "<pre>";

$results = [];

// Check if columns already exist
$checkQuery = "
    SELECT column_name 
    FROM information_schema.columns 
    WHERE table_name = 'municipalities' 
    AND column_name IN ('contact_phone', 'contact_email', 'contact_address', 'office_hours')
";

$checkResult = pg_query($connection, $checkQuery);
$existingColumns = [];
while ($row = pg_fetch_assoc($checkResult)) {
    $existingColumns[] = $row['column_name'];
}

echo "Existing contact columns: " . (empty($existingColumns) ? "None" : implode(", ", $existingColumns)) . "\n\n";

// Add contact_phone if not exists
if (!in_array('contact_phone', $existingColumns)) {
    $sql = "ALTER TABLE municipalities ADD COLUMN contact_phone VARCHAR(50) DEFAULT '(046) 886-4454'";
    $result = pg_query($connection, $sql);
    if ($result) {
        echo "✓ Added contact_phone column\n";
    } else {
        echo "✗ Failed to add contact_phone: " . pg_last_error($connection) . "\n";
    }
} else {
    echo "- contact_phone already exists\n";
}

// Add contact_email if not exists
if (!in_array('contact_email', $existingColumns)) {
    $sql = "ALTER TABLE municipalities ADD COLUMN contact_email VARCHAR(100) DEFAULT 'educaid@generaltrias.gov.ph'";
    $result = pg_query($connection, $sql);
    if ($result) {
        echo "✓ Added contact_email column\n";
    } else {
        echo "✗ Failed to add contact_email: " . pg_last_error($connection) . "\n";
    }
} else {
    echo "- contact_email already exists\n";
}

// Add contact_address if not exists
if (!in_array('contact_address', $existingColumns)) {
    $sql = "ALTER TABLE municipalities ADD COLUMN contact_address TEXT DEFAULT 'General Trias City Hall, Cavite'";
    $result = pg_query($connection, $sql);
    if ($result) {
        echo "✓ Added contact_address column\n";
    } else {
        echo "✗ Failed to add contact_address: " . pg_last_error($connection) . "\n";
    }
} else {
    echo "- contact_address already exists\n";
}

// Add office_hours if not exists
if (!in_array('office_hours', $existingColumns)) {
    $sql = "ALTER TABLE municipalities ADD COLUMN office_hours VARCHAR(100) DEFAULT 'Mon–Fri 8:00AM - 5:00PM'";
    $result = pg_query($connection, $sql);
    if ($result) {
        echo "✓ Added office_hours column\n";
    } else {
        echo "✗ Failed to add office_hours: " . pg_last_error($connection) . "\n";
    }
} else {
    echo "- office_hours already exists\n";
}

echo "\n<strong>Migration complete!</strong>\n";
echo "\nContact information is now stored in the municipalities table.\n";
echo "Update the municipality settings to change contact info for topbar, footer, and all pages.\n";

echo "</pre>";

// Show current data
echo "<h3>Current Municipality Contact Data:</h3>";
echo "<pre>";
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
}
echo "</pre>";

pg_close($connection);
?>
