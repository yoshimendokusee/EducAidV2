<?php
/**
 * Compress Archived Students Files
 * 
 * Called after year advancement to compress and archive graduated students' files
 * Can be called via AJAX or as a standalone endpoint
 */

include __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../src/Services/StudentArchivalService.php';
require_once __DIR__ . '/../../src/Services/UnifiedFileService.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Ensure admin is authenticated
if (!isset($_SESSION['admin_username'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

try {
    // Get list of students to compress
    // Accept either POST (AJAX) or GET (direct call)
    $studentIds = [];
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        
        if (isset($data['student_ids']) && is_array($data['student_ids'])) {
            $studentIds = $data['student_ids'];
        }
    } elseif (isset($_GET['student_ids'])) {
        $studentIds = explode(',', $_GET['student_ids']);
    }
    
    // If no specific students provided, compress ALL archived students
    if (empty($studentIds)) {
        $query = "SELECT student_id FROM students WHERE is_archived = TRUE AND status = 'active'";
        $result = pg_query($connection, $query);
        
        if ($result) {
            while ($row = pg_fetch_assoc($result)) {
                $studentIds[] = $row['student_id'];
            }
        }
    }
    
    if (empty($studentIds)) {
        echo json_encode([
            'success' => false,
            'message' => 'No archived students found to compress'
        ]);
        exit;
    }
    
    // Initialize file service
    $fileService = new \App\Services\UnifiedFileService();
    
    $results = [
        'success' => true,
        'total_students' => count($studentIds),
        'compressed' => 0,
        'failed' => 0,
        'total_files' => 0,
        'total_space_saved' => 0,
        'details' => []
    ];
    
    // Compress each student's files
    foreach ($studentIds as $studentId) {
        $studentId = trim($studentId);
        
        try {
            $compressionResult = $fileService->compressArchivedStudent($studentId);
            
            if ($compressionResult['success']) {
                $results['compressed']++;
                $results['total_files'] += $compressionResult['files_added'];
                $results['total_space_saved'] += $compressionResult['space_saved'] ?? 0;
                
                $results['details'][] = [
                    'student_id' => $studentId,
                    'success' => true,
                    'files' => $compressionResult['files_added'],
                    'space_saved' => $compressionResult['space_saved'] ?? 0
                ];
                
                error_log("Archived student compression: $studentId - {$compressionResult['files_added']} files");
            } else {
                $results['failed']++;
                $results['details'][] = [
                    'student_id' => $studentId,
                    'success' => false,
                    'message' => $compressionResult['message'] ?? 'Unknown error'
                ];
                
                error_log("Archived student compression FAILED: $studentId - {$compressionResult['message']}");
            }
        } catch (Exception $e) {
            $results['failed']++;
            $results['details'][] = [
                'student_id' => $studentId,
                'success' => false,
                'message' => $e->getMessage()
            ];
            error_log("Archived student compression ERROR: $studentId - " . $e->getMessage());
        }
    }
    
    // Format space saved for display
    $results['space_saved_mb'] = round($results['total_space_saved'] / (1024 * 1024), 2);
    
    // Log summary
    error_log("=== Archived Students Compression Complete ===");
    error_log("Total: {$results['total_students']} | Compressed: {$results['compressed']} | Failed: {$results['failed']}");
    error_log("Files archived: {$results['total_files']} | Space saved: {$results['space_saved_mb']} MB");
    
    echo json_encode($results);
    
} catch (Exception $e) {
    error_log("Archived student compression - Fatal error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'System error: ' . $e->getMessage()
    ]);
}
?>
