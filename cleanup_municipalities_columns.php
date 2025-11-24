<?php
/**
 * Script to remove unused columns from municipalities table
 * Removes: banner_image, logo_image, psgc_code
 * Run once via CLI: php cleanup_municipalities_columns.php
 */

require_once __DIR__ . '/config/database.php';

echo "Starting municipalities table cleanup...\n";

try {
    // Start transaction
    pg_query($connection, "BEGIN");
    
    // Drop unused columns
    $sql = "ALTER TABLE municipalities 
            DROP COLUMN IF EXISTS banner_image,
            DROP COLUMN IF EXISTS logo_image,
            DROP COLUMN IF EXISTS psgc_code";
    
    $result = pg_query($connection, $sql);
    
    if ($result) {
        pg_query($connection, "COMMIT");
        echo "✓ Successfully removed columns: banner_image, logo_image, psgc_code\n";
        echo "\nTable structure updated successfully!\n";
    } else {
        pg_query($connection, "ROLLBACK");
        echo "✗ Error: " . pg_last_error($connection) . "\n";
    }
    
} catch (Exception $e) {
    pg_query($connection, "ROLLBACK");
    echo "✗ Exception: " . $e->getMessage() . "\n";
}

pg_close($connection);
?>
