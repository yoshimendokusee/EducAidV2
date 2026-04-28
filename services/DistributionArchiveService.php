<?php
/**
 * Enhanced Distribution Archive Service
 * Handles complete distribution lifecycle: tracking, compression, and history
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/FilePathConfig.php';
require_once __DIR__ . '/../bootstrap_services.php';

class DistributionArchiveService {
    private $conn;
    private $compressionService;
    private $pathConfig;
    
    public function __construct($connection = null) {
        global $connection;
        $this->conn = $connection ?? $connection;
        $this->compressionService = new FileCompressionService();
        $this->pathConfig = FilePathConfig::getInstance();
        
        error_log("DistributionArchiveService: Environment=" . ($this->pathConfig->isRailway() ? 'Railway' : 'Localhost'));
    }
    
    /**
     * Create a complete snapshot of the current distribution before ending it
     * This captures ALL metadata and file information for historical records
     */
    public function createDistributionSnapshot($distributionId, $adminId, $compressFiles = true) {
        try {
            pg_query($this->conn, "BEGIN");
            
            // 1. Get current academic period
            $periodQuery = "SELECT key, value FROM config WHERE key IN ('current_academic_year', 'current_semester')";
            $periodResult = pg_query($this->conn, $periodQuery);
            $academicYear = '';
            $semester = '';
            
            while ($row = pg_fetch_assoc($periodResult)) {
                if ($row['key'] === 'current_academic_year') $academicYear = $row['value'];
                if ($row['key'] === 'current_semester') $semester = $row['value'];
            }
            
            // 2. Get municipality info
            $adminQuery = pg_query_params($this->conn,
                "SELECT municipality_id FROM admins WHERE admin_id = $1",
                [$adminId]
            );
            $adminData = pg_fetch_assoc($adminQuery);
            $municipalityId = $adminData['municipality_id'] ?? null;
            
            // 3. Get all students with 'given' status
            $studentsQuery = pg_query($this->conn,
                "SELECT s.student_id, s.first_name, s.last_name, s.middle_name, s.email, s.mobile,
                        yl.name as year_level_name, u.name as university_name, b.name as barangay_name
                 FROM students s
                 LEFT JOIN year_levels yl ON s.year_level_id = yl.year_level_id
                 LEFT JOIN universities u ON s.university_id = u.university_id
                 LEFT JOIN barangays b ON s.barangay_id = b.barangay_id
                 WHERE s.status = 'given'"
            );
            
            $students = [];
            $studentIds = [];
            while ($student = pg_fetch_assoc($studentsQuery)) {
                $students[] = $student;
                $studentIds[] = $student['student_id'];
            }
            
            $totalStudents = count($students);
            
            // 4. Scan and catalog all files for these students
            $fileManifest = $this->catalogStudentFiles($studentIds);
            $totalFiles = count($fileManifest);
            $originalTotalSize = array_sum(array_column($fileManifest, 'file_size'));
            
            // 5. Compress files if requested
            $archiveFilename = null;
            $compressedSize = 0;
            $compressionRatio = 0;
            $spaceSaved = 0;
            $filesCompressed = false;
            $compressionDate = null;
            
            if ($compressFiles && $totalFiles > 0) {
                $compressionResult = $this->compressDistributionFiles($distributionId, $fileManifest);
                
                if ($compressionResult['success']) {
                    $archiveFilename = basename($compressionResult['zip_path']);
                    $compressedSize = $compressionResult['compressed_size'];
                    $compressionRatio = $compressionResult['compression_ratio'];
                    $spaceSaved = $originalTotalSize - $compressedSize;
                    $filesCompressed = true;
                    $compressionDate = date('Y-m-d H:i:s');
                }
            }
            
            // 6. Create snapshot record
            $snapshotQuery = "
                INSERT INTO distribution_snapshots (
                    distribution_id, academic_year, semester, finalized_at, finalized_by,
                    total_students_count, files_compressed, compression_date,
                    original_total_size, compressed_size, compression_ratio, space_saved,
                    total_files_count, archive_filename, archive_path,
                    municipality_id, location, notes, metadata
                ) VALUES ($1, $2, $3, NOW(), $4, $5, $6, $7, $8, $9, $10, $11, $12, $13, $14, $15, $16, $17, $18)
                RETURNING snapshot_id
            ";
            
            $metadata = json_encode([
                'distribution_ended_by' => $adminId,
                'student_count_by_year_level' => $this->getStudentCountsByYearLevel($studentIds),
                'file_count_by_type' => $this->getFileCountsByType($fileManifest),
                'compression_method' => 'ZIP',
                'system_version' => '1.0'
            ]);
            
            $snapshotResult = pg_query_params($this->conn, $snapshotQuery, [
                $distributionId,
                $academicYear,
                $semester,
                $adminId,
                $totalStudents,
                $filesCompressed ? 't' : 'f',
                $compressionDate,
                $originalTotalSize,
                $compressedSize,
                $compressionRatio,
                $spaceSaved,
                $totalFiles,
                $archiveFilename,
                '../../assets/uploads/distributions/' . $archiveFilename,
                $municipalityId,
                'General Trias, Cavite',
                "Distribution ended: $academicYear $semester",
                $metadata
            ]);
            
            $snapshotRow = pg_fetch_assoc($snapshotResult);
            $snapshotId = $snapshotRow['snapshot_id'];
            
            // 7. Insert file manifest records
            if (!empty($fileManifest)) {
                $manifestInsertQuery = "
                    INSERT INTO distribution_file_manifest 
                    (snapshot_id, student_id, document_type_code, original_file_path, file_size, file_hash, archived_path)
                    VALUES ($1, $2, $3, $4, $5, $6, $7)
                ";
                
                foreach ($fileManifest as $file) {
                    pg_query_params($this->conn, $manifestInsertQuery, [
                        $snapshotId,
                        $file['student_id'],
                        $file['document_type_code'],
                        $file['file_path'],
                        $file['file_size'],
                        $file['file_hash'],
                        $file['archived_path']
                    ]);
                }
            }
            
            // 8. Insert student snapshot records
            if (!empty($students)) {
                $studentInsertQuery = "
                    INSERT INTO distribution_student_snapshot 
                    (snapshot_id, student_id, first_name, last_name, middle_name, email, mobile,
                     year_level_name, university_name, barangay_name, distribution_date)
                    VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, CURRENT_DATE)
                ";
                
                foreach ($students as $student) {
                    pg_query_params($this->conn, $studentInsertQuery, [
                        $snapshotId,
                        $student['student_id'],
                        $student['first_name'],
                        $student['last_name'],
                        $student['middle_name'],
                        $student['email'],
                        $student['mobile'],
                        $student['year_level_name'],
                        $student['university_name'],
                        $student['barangay_name']
                    ]);
                }
            }
            
            pg_query($this->conn, "COMMIT");
            
            error_log("Distribution snapshot created: ID=$snapshotId, Students=$totalStudents, Files=$totalFiles, Compressed=" . ($filesCompressed ? 'YES' : 'NO'));
            
            return [
                'success' => true,
                'snapshot_id' => $snapshotId,
                'distribution_id' => $distributionId,
                'students_count' => $totalStudents,
                'files_count' => $totalFiles,
                'original_size' => $originalTotalSize,
                'compressed_size' => $compressedSize,
                'space_saved' => $spaceSaved,
                'compression_ratio' => $compressionRatio,
                'archive_filename' => $archiveFilename
            ];
            
        } catch (Exception $e) {
            pg_query($this->conn, "ROLLBACK");
            error_log("Distribution snapshot failed: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Catalog all files for given students
     */
    private function catalogStudentFiles($studentIds) {
        if (empty($studentIds)) return [];
        
        $manifest = [];
        $uploadsPath = $this->pathConfig->getStudentPath(); // Use pathConfig instead of hardcoded path
        $folders = [
            'enrollment_forms' => '00',
            'grades' => '01',
            'letter_to_mayor' => '02',
            'indigency' => '03',
            'id_pictures' => '04'
        ];
        
        foreach ($folders as $folder => $typeCode) {
            $folderPath = $this->pathConfig->getStudentPath($folder); // Use pathConfig with folder name
            if (!is_dir($folderPath)) continue;
            
            $files = glob($folderPath . '/*.*');
            foreach ($files as $filePath) {
                if (!is_file($filePath)) continue;
                
                $filename = basename($filePath);
                
                // Match file to student
                foreach ($studentIds as $studentId) {
                    if (stripos($filename, $studentId) !== false) {
                        $fileSize = filesize($filePath);
                        $fileHash = md5_file($filePath);
                        
                        $manifest[] = [
                            'student_id' => $studentId,
                            'document_type_code' => $typeCode,
                            'file_path' => $filePath,
                            'filename' => $filename,
                            'file_size' => $fileSize,
                            'file_hash' => $fileHash,
                            'archived_path' => $studentId . '/' . $filename
                        ];
                        break;
                    }
                }
            }
        }
        
        return $manifest;
    }
    
    /**
     * Compress distribution files into ZIP archive
     */
    private function compressDistributionFiles($distributionId, $fileManifest) {
        $archivesPath = $this->pathConfig->getDistributionsPath(); // Use pathConfig instead of hardcoded path
        if (!is_dir($archivesPath)) {
            mkdir($archivesPath, 0755, true);
        }
        
        $zipFilename = $distributionId . '.zip';
        $zipPath = $archivesPath . DIRECTORY_SEPARATOR . $zipFilename; // Use DIRECTORY_SEPARATOR
        
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new Exception("Failed to create ZIP archive: $zipPath");
        }
        
        $filesAdded = 0;
        foreach ($fileManifest as $file) {
            if (file_exists($file['file_path'])) {
                $zip->addFile($file['file_path'], $file['archived_path']);
                $filesAdded++;
            }
        }
        
        $zip->close();
        
        $compressedSize = filesize($zipPath);
        $originalSize = array_sum(array_column($fileManifest, 'file_size'));
        $compressionRatio = $originalSize > 0 ? (($originalSize - $compressedSize) / $originalSize) * 100 : 0;
        
        error_log("Distribution compressed: $filesAdded files, Original: " . number_format($originalSize / 1024 / 1024, 2) . " MB, Compressed: " . number_format($compressedSize / 1024 / 1024, 2) . " MB, Ratio: " . number_format($compressionRatio, 1) . "%");
        
        return [
            'success' => true,
            'zip_path' => $zipPath,
            'files_added' => $filesAdded,
            'compressed_size' => $compressedSize,
            'original_size' => $originalSize,
            'compression_ratio' => $compressionRatio
        ];
    }
    
    /**
     * Get all distribution snapshots with statistics
     */
    public function getAllSnapshots($municipalityId = null, $limit = 50, $offset = 0) {
        $whereClause = $municipalityId ? "WHERE ds.municipality_id = $1" : "";
        $params = $municipalityId ? [$municipalityId, $limit, $offset] : [$limit, $offset];
        $paramPlaceholders = $municipalityId ? '$2 OFFSET $3' : '$1 OFFSET $2';
        
        $query = "
            SELECT 
                ds.*,
                COUNT(DISTINCT dss.student_id) as verified_student_count,
                COUNT(dfm.manifest_id) as verified_file_count
            FROM distribution_snapshots ds
            LEFT JOIN distribution_student_snapshot dss ON ds.snapshot_id = dss.snapshot_id
            LEFT JOIN distribution_file_manifest dfm ON ds.snapshot_id = dfm.snapshot_id
            $whereClause
            GROUP BY ds.snapshot_id
            ORDER BY ds.finalized_at DESC
            LIMIT $paramPlaceholders
        ";
        
        $result = pg_query_params($this->conn, $query, $params);
        return $result ? pg_fetch_all($result) ?: [] : [];
    }
    
    /**
     * Get detailed snapshot information including file manifest
     */
    public function getSnapshotDetails($snapshotId) {
        $snapshotQuery = pg_query_params($this->conn,
            "SELECT * FROM distribution_snapshots WHERE snapshot_id = $1",
            [$snapshotId]
        );
        
        if (!$snapshotQuery || pg_num_rows($snapshotQuery) === 0) {
            return null;
        }
        
        $snapshot = pg_fetch_assoc($snapshotQuery);
        
        // Get students
        $studentsQuery = pg_query_params($this->conn,
            "SELECT * FROM distribution_student_snapshot WHERE snapshot_id = $1 ORDER BY last_name, first_name",
            [$snapshotId]
        );
        $snapshot['students'] = pg_fetch_all($studentsQuery) ?: [];
        
        // Get file manifest
        $filesQuery = pg_query_params($this->conn,
            "SELECT * FROM distribution_file_manifest WHERE snapshot_id = $1 ORDER BY student_id, document_type_code",
            [$snapshotId]
        );
        $snapshot['files'] = pg_fetch_all($filesQuery) ?: [];
        
        return $snapshot;
    }
    
    private function getStudentCountsByYearLevel($studentIds) {
        if (empty($studentIds)) return [];
        
        $placeholders = implode(',', array_map(fn($i) => '$' . ($i + 1), array_keys($studentIds)));
        $query = "SELECT yl.name, COUNT(*) as count 
                  FROM students s 
                  JOIN year_levels yl ON s.year_level_id = yl.year_level_id 
                  WHERE s.student_id IN ($placeholders) 
                  GROUP BY yl.name";
        
        $result = pg_query_params($this->conn, $query, $studentIds);
        $counts = [];
        while ($row = pg_fetch_assoc($result)) {
            $counts[$row['name']] = intval($row['count']);
        }
        return $counts;
    }
    
    private function getFileCountsByType($fileManifest) {
        $counts = [];
        foreach ($fileManifest as $file) {
            $type = $file['document_type_code'];
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }
        return $counts;
    }
}
?>
