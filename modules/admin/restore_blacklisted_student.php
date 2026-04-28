<?php
/**
 * DEVELOPMENT TOOL: Restore Blacklisted Student
 * 
 * This completely reverses a blacklist action:
 * 1. Removes student from blacklisted_students table
 * 2. Resets student status to 'applicant'
 * 3. Clears all archive flags
 * 4. Extracts files from ZIP archive back to student folders
 * 5. Deletes the blacklist ZIP file
 * 
 * WARNING: Use only for testing/development purposes
 */

include __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/FilePathConfig.php';
$pathConfig = FilePathConfig::getInstance();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

if (!isset($_SESSION['admin_username']) || !isset($_POST['student_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

$student_id = trim($_POST['student_id']);
$admin_id = $_SESSION['admin_id'];

try {
    pg_query($connection, "BEGIN");
    
    // 1. Verify student is blacklisted
    $checkQuery = "SELECT s.student_id, s.first_name, s.last_name, s.status 
                   FROM students s 
                   WHERE s.student_id = $1 AND s.status = 'blacklisted'";
    
    $checkResult = pg_query_params($connection, $checkQuery, [$student_id]);
    
    if (!$checkResult || pg_num_rows($checkResult) === 0) {
        throw new Exception("Student not found or not blacklisted");
    }
    
    $student = pg_fetch_assoc($checkResult);
    
    // 2. Delete from blacklisted_students table
    $deleteBlacklist = "DELETE FROM blacklisted_students WHERE student_id = $1";
    $deleteResult = pg_query_params($connection, $deleteBlacklist, [$student_id]);
    
    if (!$deleteResult) {
        throw new Exception("Failed to remove from blacklist table");
    }
    
    // 3. Restore student status
    $updateStudent = "UPDATE students 
                      SET status = 'applicant',
                          is_archived = FALSE,
                          archived_at = NULL,
                          archived_by = NULL,
                          archive_reason = NULL,
                          archival_type = NULL
                      WHERE student_id = $1";
    
    $updateResult = pg_query_params($connection, $updateStudent, [$student_id]);
    
    if (!$updateResult) {
        throw new Exception("Failed to update student status");
    }
    
    pg_query($connection, "COMMIT");
    
    // 4. Restore files from ZIP archive
    $zipFile = $pathConfig->getArchivedStudentsPath() . DIRECTORY_SEPARATOR . $student_id . '.zip';
    $filesRestored = 0;
    $restoreErrors = [];
    
    if (file_exists($zipFile)) {
        $zip = new ZipArchive();
        
        if ($zip->open($zipFile) === TRUE) {
            // Extract files
            $basePath = $pathConfig->getStudentPath();
            
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $filename = $zip->getNameIndex($i);
                
                // Skip metadata file
                if ($filename === 'BLACKLIST_INFO.txt') {
                    continue;
                }
                
                // Parse the path: permanent/enrollment_forms/filename.ext or temp/enrollment_forms/filename.ext
                $pathParts = explode('/', $filename);
                
                if (count($pathParts) >= 3) {
                    $location = $pathParts[0]; // 'permanent' or 'temp'
                    $fileType = $pathParts[1]; // 'enrollment_forms', 'grades', etc.
                    $file = $pathParts[2]; // actual filename
                    
                    if ($location === 'permanent') {
                        // Restore to assets/uploads/student/[filetype]/[student_id]/
                        $targetDir = $basePath . DIRECTORY_SEPARATOR . $fileType . DIRECTORY_SEPARATOR . $student_id;
                        
                        if (!is_dir($targetDir)) {
                            mkdir($targetDir, 0755, true);
                        }
                        
                        $targetFile = $targetDir . DIRECTORY_SEPARATOR . $file;
                        
                        // Extract the file
                        $fileContent = $zip->getFromIndex($i);
                        if ($fileContent !== false) {
                            if (file_put_contents($targetFile, $fileContent)) {
                                $filesRestored++;
                            } else {
                                $restoreErrors[] = "Failed to write: $targetFile";
                            }
                        } else {
                            $restoreErrors[] = "Failed to read from ZIP: $filename";
                        }
                    }
                    // We're skipping temp files restoration as they're only for pre-approval students
                }
            }
            
            $zip->close();
            
            // 5. Delete the ZIP file after successful restoration
            if ($filesRestored > 0 && empty($restoreErrors)) {
                unlink($zipFile);
            }
        } else {
            $restoreErrors[] = "Failed to open ZIP file";
        }
    } else {
        $restoreErrors[] = "ZIP file not found";
    }
    
    // Log the restoration
    require_once __DIR__ . '/../../bootstrap_services.php';
    $auditLogger = new AuditLogger($connection);
    $auditLogger->logEvent(
        'blacklist_restored_dev',
        'blacklist_management',
        "DEV: Restored blacklisted student {$student['first_name']} {$student['last_name']}",
        [
            'user_id' => $admin_id,
            'user_type' => 'admin',
            'student_id' => $student_id,
            'student_name' => $student['first_name'] . ' ' . $student['last_name'],
            'files_restored' => $filesRestored,
            'restore_errors' => $restoreErrors
        ]
    );
    
    $message = "Student restored successfully!\n\n";
    $message .= "• Removed from blacklist\n";
    $message .= "• Status reset to 'applicant'\n";
    $message .= "• Archive flags cleared\n";
    
    if ($filesRestored > 0) {
        $message .= "• {$filesRestored} files restored from ZIP\n";
        $message .= "• ZIP archive deleted\n";
    } else if (!empty($restoreErrors)) {
        $message .= "• Warning: Some files could not be restored:\n";
        foreach ($restoreErrors as $error) {
            $message .= "  - {$error}\n";
        }
    } else {
        $message .= "• No files to restore (ZIP not found)\n";
    }
    
    echo json_encode([
        'success' => true,
        'message' => $message,
        'files_restored' => $filesRestored,
        'errors' => $restoreErrors
    ]);
    
} catch (Exception $e) {
    pg_query($connection, "ROLLBACK");
    
    error_log("Restore Blacklist Error: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

pg_close($connection);
?>
