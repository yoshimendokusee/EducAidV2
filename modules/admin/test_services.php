<?php
/**
 * Test Archival & Blacklist Services
 * Quick test endpoint to verify services are working correctly
 */

include __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../src/Services/StudentArchivalService.php';
require_once __DIR__ . '/../../src/Services/BlacklistService.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Require admin authentication
if (!isset($_SESSION['admin_username'])) {
    http_response_code(401);
    die('Unauthorized');
}

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'test_archival_service':
            $archivalService = new \App\Services\StudentArchivalService();
            
            // Test: Get archival history for a student (if exists)
            $testQuery = pg_query($connection, "SELECT student_id FROM students LIMIT 1");
            if ($testQuery && $row = pg_fetch_assoc($testQuery)) {
                $history = $archivalService->getArchivalHistory($row['student_id']);
                
                echo json_encode([
                    'status' => 'success',
                    'service' => 'StudentArchivalService',
                    'test' => 'getArchivalHistory',
                    'student_id' => $row['student_id'],
                    'result' => $history
                ]);
            } else {
                echo json_encode([
                    'status' => 'success',
                    'service' => 'StudentArchivalService',
                    'message' => 'Service loaded successfully (no students to test with)'
                ]);
            }
            break;
            
        case 'test_blacklist_service':
            $blacklistService = new \App\Services\BlacklistService();
            
            // Test: Check if a test email is blacklisted
            $testEmail = 'test@example.com';
            $isBlacklisted = $blacklistService->isEmailBlacklisted($testEmail);
            
            echo json_encode([
                'status' => 'success',
                'service' => 'BlacklistService',
                'test' => 'isEmailBlacklisted',
                'test_email' => $testEmail,
                'is_blacklisted' => $isBlacklisted,
                'message' => 'Service loaded successfully'
            ]);
            break;
            
        case 'check_database_columns':
            // Verify new columns exist
            $columnCheck = pg_query($connection,
                "SELECT column_name, data_type 
                 FROM information_schema.columns 
                 WHERE table_name = 'students' 
                   AND column_name IN (
                       'unarchived_at', 'unarchived_by', 'unarchive_reason',
                       'household_verified', 'household_primary', 'household_group_id',
                       'archival_type'
                   )
                 ORDER BY column_name"
            );
            
            $columns = [];
            while ($row = pg_fetch_assoc($columnCheck)) {
                $columns[] = $row;
            }
            
            echo json_encode([
                'status' => 'success',
                'test' => 'database_schema',
                'columns_found' => count($columns),
                'expected' => 7,
                'columns' => $columns,
                'schema_updated' => count($columns) === 7
            ]);
            break;
            
        case 'service_status':
            // Overall status check
            try {
                $archivalService = new \App\Services\StudentArchivalService();
                $blacklistService = new \App\Services\BlacklistService();
                
                // Check database columns
                $columnCheck = pg_query($connection,
                    "SELECT COUNT(*) as count
                     FROM information_schema.columns 
                     WHERE table_name = 'students' 
                       AND column_name IN (
                           'unarchived_at', 'unarchived_by', 'unarchive_reason',
                           'household_verified', 'household_primary', 'household_group_id',
                           'archival_type'
                       )"
                );
                $columnCount = pg_fetch_assoc($columnCheck)['count'];
                
                echo json_encode([
                    'status' => 'success',
                    'services' => [
                        'StudentArchivalService' => 'loaded',
                        'BlacklistService' => 'loaded'
                    ],
                    'database' => [
                        'new_columns' => (int)$columnCount,
                        'expected_columns' => 7,
                        'schema_ready' => (int)$columnCount === 7
                    ],
                    'files' => [
                        'blacklisted_students_folder' => is_dir(__DIR__ . '/../../assets/uploads/blacklisted_students'),
                        'archived_students_folder' => is_dir(__DIR__ . '/../../assets/uploads/archived_students')
                    ],
                    'ready' => (int)$columnCount === 7
                ]);
            } catch (Exception $e) {
                throw new Exception('Service initialization failed: ' . $e->getMessage());
            }
            break;
            
        default:
            echo json_encode([
                'status' => 'error',
                'message' => 'Invalid action. Available actions: test_archival_service, test_blacklist_service, check_database_columns, service_status'
            ]);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}
?>
