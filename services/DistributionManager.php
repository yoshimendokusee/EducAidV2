<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/FilePathConfig.php';

class DistributionManager {
    private $conn;
    private $studentResetColumnCache = null;
    private $pathConfig;
    
    public function __construct() {
        global $connection;
        $this->conn = $connection;
        $this->pathConfig = FilePathConfig::getInstance();
        
        error_log("DistributionManager: Environment=" . ($this->pathConfig->isRailway() ? 'Railway' : 'Localhost'));
    }
    
    public function endDistribution($distributionId, $adminId, $compressNow = true) {
        try {
            pg_query($this->conn, "BEGIN");
            
            $checkResult = pg_query_params($this->conn, 
                "SELECT * FROM distributions WHERE distribution_id = $1", 
                [$distributionId]);
            
            if (!$checkResult || pg_num_rows($checkResult) === 0) {
                throw new Exception("Distribution not found");
            }
            
            $distribution = pg_fetch_assoc($checkResult);
            
            if (isset($distribution['status']) && $distribution['status'] === 'ended') {
                throw new Exception("Distribution is already ended");
            }
            
            // FIRST: Compress files BEFORE resetting students (compression needs status='given')
            $compressionResult = null;
            if ($compressNow) {
                require_once __DIR__ . '/../bootstrap_services.php';
                $compressionService = new FileCompressionService();
                $compressionResult = $compressionService->compressDistribution($distributionId, $adminId);
                
                if (!$compressionResult['success']) {
                    throw new Exception("Compression failed: " . $compressionResult['message']);
                }
            }
            
            // Update distribution status to ended
            pg_query_params($this->conn,
                "UPDATE distributions SET status = 'ended', ended_at = NOW() WHERE distribution_id = $1",
                [$distributionId]);
            
            // Reset all students with 'given' status back to 'applicant'
            // Clear their payroll numbers and QR codes for the next cycle
            $studentsReset = $this->resetGivenStudents();
            
            error_log("DistributionManager: Reset $studentsReset students from 'given' to 'applicant' and cleared payroll/QR codes");

            // Clear schedule data so the next distribution starts clean
            $this->clearScheduleData();
            
            // Set global distribution status to inactive
            pg_query($this->conn, "
                INSERT INTO config (key, value) VALUES ('distribution_status', 'inactive')
                ON CONFLICT (key) DO UPDATE SET value = 'inactive'
            ");
            
            pg_query($this->conn, "COMMIT");
            
            $result = [
                'success' => true,
                'message' => 'Distribution ended successfully and status set to inactive',
                'distribution_id' => $distributionId,
                'students_reset' => $studentsReset,
                'compression' => $compressionResult
            ];
            
            return $result;
            
        } catch (Exception $e) {
            pg_query($this->conn, "ROLLBACK");
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    public function getActiveDistributions() {
        // Check global distribution status from config
        $statusQuery = "SELECT value FROM config WHERE key = 'distribution_status'";
        $statusResult = pg_query($this->conn, $statusQuery);
        $statusRow = pg_fetch_assoc($statusResult);
        $globalStatus = $statusRow ? $statusRow['value'] : 'inactive';
        
        // If distribution is not active globally, return empty
        if (!in_array($globalStatus, ['preparing', 'active'])) {
            return [];
        }
        
        // Get current academic period from config or active slot
        $slotQuery = "SELECT academic_year, semester FROM signup_slots WHERE is_active = true LIMIT 1";
        $slotResult = pg_query($this->conn, $slotQuery);
        $slot = $slotResult ? pg_fetch_assoc($slotResult) : null;
        
        $academicYear = $slot['academic_year'] ?? null;
        $semester = $slot['semester'] ?? null;
        
        if (!$academicYear || !$semester) {
            $periodQuery = "SELECT key, value FROM config WHERE key IN ('current_academic_year', 'current_semester')";
            $periodResult = pg_query($this->conn, $periodQuery);
            while ($row = pg_fetch_assoc($periodResult)) {
                if ($row['key'] === 'current_academic_year' && !$academicYear) $academicYear = $row['value'];
                if ($row['key'] === 'current_semester' && !$semester) $semester = $row['value'];
            }
        }
        
        // Get all students with 'given' status (distributed aid)
        $studentsQuery = "SELECT student_id FROM students WHERE status = 'given'";
        $studentsResult = pg_query($this->conn, $studentsQuery);
        $studentIds = [];
        while ($row = pg_fetch_assoc($studentsResult)) {
            $studentIds[] = $row['student_id'];
        }
        
        if (empty($studentIds)) {
            return []; // No distributed students yet
        }
        
        // Resilient scan of student document folders (Railway vs Localhost differences)
        $totalFiles = 0;
        $totalSize = 0;

        // Standard folder names to scan; environment-specific variants resolved per folder
        $docFolders = [
            'enrollment_forms',
            'grades',
            'id_pictures',
            'indigency',
            'letter_to_mayor'
        ];

        error_log("DistributionManager: Scanning student folders for " . count($studentIds) . " 'given' students");

        foreach ($docFolders as $folderName) {
            $candidatePaths = $this->resolveStudentFolderCandidates($folderName);
            $fullPath = null;
            foreach ($candidatePaths as $cand) {
                if (is_dir($cand)) { $fullPath = $cand; break; }
            }

            if (!$fullPath) {
                error_log("DistributionManager: No matching directory for '$folderName' (checked variants)");
                continue;
            }

            // Detect structure: student-organized subfolders or legacy flat listing
            $items = scandir($fullPath);
            $hasStudentFolders = false;
            foreach ($items as $item) {
                if ($item !== '.' && $item !== '..' && is_dir($fullPath . DIRECTORY_SEPARATOR . $item)) {
                    $hasStudentFolders = true; break;
                }
            }

            $files = [];
            if ($hasStudentFolders) {
                foreach ($items as $item) {
                    if ($item === '.' || $item === '..') continue;
                    $studentFolder = $fullPath . DIRECTORY_SEPARATOR . $item;
                    if (!is_dir($studentFolder)) continue;
                    foreach (scandir($studentFolder) as $f) {
                        if ($f === '.' || $f === '..') continue;
                        $p = $studentFolder . DIRECTORY_SEPARATOR . $f;
                        if (is_file($p)) {
                            if (!preg_match('/\.(ocr\.(txt|json)|verify\.json|confidence\.json|tsv)$/i', $f)) {
                                $files[] = $p;
                            }
                        }
                    }
                }
            } else {
                foreach ($items as $f) {
                    if ($f === '.' || $f === '..') continue;
                    $p = $fullPath . DIRECTORY_SEPARATOR . $f;
                    if (is_file($p)) {
                        if (!preg_match('/\.(ocr\.(txt|json)|verify\.json|confidence\.json|tsv)$/i', $f)) {
                            $files[] = $p;
                        }
                    }
                }
            }

            foreach ($files as $file) {
                $filename = basename($file);
                $filenameLower = strtolower($filename);
                $parentFolder = strtolower(basename(dirname($file)));
                foreach ($studentIds as $studentId) {
                    $sid = strtolower($studentId);
                    if (strpos($filenameLower, $sid) !== false || $parentFolder === $sid) {
                        $totalFiles++;
                        $size = @filesize($file);
                        if ($size !== false) { $totalSize += (int)$size; }
                        break;
                    }
                }
            }
        }
        error_log("DistributionManager: Total matched files: $totalFiles, size: $totalSize bytes");

        
        // Get a unique distribution ID (or create one if needed)
        // Check if there's an existing active distribution record
        $distQuery = "SELECT distribution_id FROM distributions WHERE status = 'active' LIMIT 1";
        $distResult = pg_query($this->conn, $distQuery);
        
        if ($distResult && pg_num_rows($distResult) > 0) {
            $distRow = pg_fetch_assoc($distResult);
            $distributionId = $distRow['distribution_id'];
        } else {
            // Create a new distribution record for this cycle
            require_once __DIR__ . '/DistributionIdGenerator.php';
            $idGenerator = new DistributionIdGenerator($this->conn, 'GENERALTRIAS');
            $distributionId = $idGenerator->generateDistributionId();
            
            // Insert the distribution record
            $insertQuery = "INSERT INTO distributions (distribution_id, status, date_given) VALUES ($1, 'active', NOW())";
            pg_query_params($this->conn, $insertQuery, [$distributionId]);
        }
        
        // Return ONE distribution representing the current active cycle
        return [[
            'id' => $distributionId,
            'created_at' => date('Y-m-d H:i:s'),
            'status' => 'active',
            'year_level' => $academicYear,
            'semester' => $semester,
            'student_count' => count($studentIds),
            'file_count' => $totalFiles,
            'total_size' => $totalSize
        ]];
    }
    
    public function getEndedDistributions($includeArchived = true) {
        $archivedCondition = $includeArchived ? "" : "AND df.is_archived = false";
        
        $query = "SELECT 
                    d.distribution_id as id,
                    d.date_given as created_at,
                    d.status,
                    d.ended_at,
                    COALESCE(d.files_compressed, false) as files_compressed,
                    d.compression_date,
                    NULL::integer as year_level,
                    NULL::integer as semester,
                    COUNT(DISTINCT df.student_id) as student_count,
                    COUNT(df.file_id) as file_count,
                    COALESCE(SUM(df.file_size), 0) as original_size,
                    COALESCE(SUM(df.file_size), 0) as current_size,
                    0 as avg_compression_ratio
                 FROM distributions d
                 LEFT JOIN distribution_files df ON d.distribution_id = df.distribution_id
                 WHERE d.status = 'ended' $archivedCondition
                 GROUP BY d.distribution_id, d.date_given, d.status, d.ended_at, 
                          d.files_compressed, d.compression_date
                 ORDER BY d.ended_at DESC";
        
        $result = pg_query($this->conn, $query);
        return $result ? pg_fetch_all($result) ?: [] : [];
    }
    
    public function getAllDistributions() {
        // Query distribution_snapshots for historical records with full metadata
        $query = "SELECT 
                    ds.snapshot_id as id,
                    ds.distribution_id,
                    ds.finalized_at as created_at,
                    'ended' as status,
                    ds.finalized_at as ended_at,
                    COALESCE(ds.files_compressed, false) as files_compressed,
                    ds.compression_date,
                    ds.academic_year as year_level,
                    ds.semester,
                    ds.total_students_count as student_count,
                    0 as file_count,
                    0 as original_size,
                    0 as current_size,
                    0 as avg_compression_ratio,
                    0 as archived_files_count,
                    ds.location,
                    ds.notes,
                    ds.archive_filename
                 FROM distribution_snapshots ds
                 ORDER BY ds.finalized_at DESC";
        
        $result = pg_query($this->conn, $query);
        $distributions = $result ? pg_fetch_all($result) ?: [] : [];
        
        // Enhance with actual file data from ZIP archives using pathConfig
        $distributionsPath = $this->pathConfig->getDistributionsPath();
        
        foreach ($distributions as &$dist) {
            // Primary: Use archive_filename if stored
            $zipFile = null;
            
            if (!empty($dist['archive_filename'])) {
                $primaryZip = $distributionsPath . DIRECTORY_SEPARATOR . $dist['archive_filename'];
                if (file_exists($primaryZip) && is_file($primaryZip)) {
                    $zipFile = $primaryZip;
                }
            }
            
            // Secondary: Use distribution_id
            if (!$zipFile && !empty($dist['distribution_id'])) {
                $distIdZip = $distributionsPath . '/' . $dist['distribution_id'] . '.zip';
                if (file_exists($distIdZip) && is_file($distIdZip)) {
                    $zipFile = $distIdZip;
                }
            }
            
            // Fallback: Try pattern matching
            if (!$zipFile && !empty($dist['created_at'])) {
                $dateStamp = date('Y-m-d', strtotime($dist['created_at']));
                $pattern = $distributionsPath . '/*DISTR*' . $dateStamp . '*.zip';
                $matches = glob($pattern);
                if (!empty($matches)) {
                    $zipFile = $matches[0];
                }
            }
            
            if ($zipFile) {
                $dist['files_compressed'] = true;
                $dist['current_size'] = filesize($zipFile);
                
                // Try to get file count from ZIP
                $zip = new ZipArchive();
                if ($zip->open($zipFile) === true) {
                    $dist['file_count'] = $zip->numFiles;
                    $dist['archived_files_count'] = $zip->numFiles;
                    $zip->close();
                }
                
                // Estimate original size (compressed size * 2 for typical compression ratio)
                $dist['original_size'] = $dist['current_size'] * 2;
                $spaceSaved = $dist['original_size'] - $dist['current_size'];
                $dist['avg_compression_ratio'] = $dist['original_size'] > 0 
                    ? round(($spaceSaved / $dist['original_size']) * 100, 1) 
                    : 0;
            } else {
                // No ZIP found - check if we can get file info from snapshot JSON data
                $snapshotQuery = "SELECT students_data FROM distribution_snapshots WHERE snapshot_id = $1";
                $snapshotResult = pg_query_params($this->conn, $snapshotQuery, [$dist['id']]);
                if ($snapshotResult && $snapshotRow = pg_fetch_assoc($snapshotResult)) {
                    $studentsData = json_decode($snapshotRow['students_data'], true);
                    if (is_array($studentsData)) {
                        $dist['student_count'] = count($studentsData);
                        // Estimate file count (typically 5 files per student: ID, grades, EAF, letter, certificate)
                        $dist['file_count'] = count($studentsData) * 5;
                    }
                }
            }
        }
        
        return $distributions;
    }
    
    public function getCompressionStatistics() {
        // Get distribution counts from distribution_snapshots (actual historical records)
        $distQuery = pg_query($this->conn, "
            SELECT 
                COUNT(*) as total_distributions
            FROM distribution_snapshots
        ");
        
        $distStats = pg_fetch_assoc($distQuery);
        
        // Scan actual distribution ZIP files to calculate space using pathConfig
        $distributionsPath = $this->pathConfig->getDistributionsPath();
        $totalCompressedSize = 0;
        $distributionCount = 0;
        
        if (is_dir($distributionsPath)) {
            $zipFiles = glob($distributionsPath . DIRECTORY_SEPARATOR . '*.zip');
            foreach ($zipFiles as $zipFile) {
                if (is_file($zipFile)) {
                    $totalCompressedSize += filesize($zipFile);
                    $distributionCount++;
                }
            }
        }
        
        // Estimate original size (before compression)
        // Assumption: ZIP compression typically achieves 30-60% compression
        // For calculation, we'll estimate original was 2x compressed size
        $estimatedOriginalSize = $totalCompressedSize * 2;
        $spaceSaved = $estimatedOriginalSize - $totalCompressedSize;
        
        $avgCompressionRatio = $estimatedOriginalSize > 0 
            ? (($spaceSaved / $estimatedOriginalSize) * 100) 
            : 0;
        
        return [
            'total_distributions' => (int)($distStats['total_distributions'] ?? 0),
            'compressed_distributions' => $distributionCount,
            'total_original_size' => $estimatedOriginalSize,
            'total_current_size' => $totalCompressedSize,
            'total_space_saved' => $spaceSaved,
            'avg_compression_ratio' => round($avgCompressionRatio, 1),
            'compression_percentage' => round($avgCompressionRatio, 1)
        ];
    }
    
    public function getRecentArchiveLog($limit = 10) {
        $query = "SELECT 
                    fal.*,
                    TRIM(CONCAT(COALESCE(a.first_name,''),' ',COALESCE(a.last_name,''))) as admin_name,
                    s.student_id as lrn,
                    s.first_name || ' ' || s.last_name as student_name,
                    d.date_given as distribution_date,
                    NULL::integer as year_level,
                    NULL::integer as semester
                 FROM file_archive_log fal
                 LEFT JOIN admins a ON fal.performed_by = a.admin_id
                 LEFT JOIN students s ON fal.student_id = s.student_id
                 LEFT JOIN distributions d ON fal.distribution_id = d.distribution_id
                 ORDER BY fal.performed_at DESC
                 LIMIT $1";
        
        $result = pg_query_params($this->conn, $query, [$limit]);
        return $result ? pg_fetch_all($result) ?: [] : [];
    }
    
    public function getStorageStatistics() {
        $stats = [];
        
        // 1. ACTIVE STUDENTS - Query documents table for file paths and calculate sizes
        $activeFiles = 0;
        $activeSize = 0;
        
        // Get count of active students
        $activeStudentsQuery = pg_query($this->conn, "SELECT COUNT(DISTINCT student_id) as count FROM students WHERE status IN ('active', 'given')");
        $activeStudentCount = pg_fetch_assoc($activeStudentsQuery)['count'] ?? 0;
        
        // Get documents for active students from database
        $activeDocsQuery = pg_query($this->conn, "
            SELECT d.file_path, d.file_size_bytes, d.ocr_text_path, d.verification_data_path 
            FROM documents d
            INNER JOIN students s ON d.student_id = s.student_id
            WHERE s.status IN ('active', 'given') 
            AND d.status = 'approved'
        ");
        
        if ($activeDocsQuery) {
            while ($doc = pg_fetch_assoc($activeDocsQuery)) {
                $activeFiles++;
                // Use file_size_bytes from DB if available, otherwise check file
                if (!empty($doc['file_size_bytes'])) {
                    $activeSize += (int)$doc['file_size_bytes'];
                } elseif (!empty($doc['file_path'])) {
                    $fullPath = $this->resolveFilePath($doc['file_path']);
                    if ($fullPath && file_exists($fullPath)) {
                        $sz = @filesize($fullPath);
                        if ($sz !== false) { $activeSize += (int)$sz; }
                    }
                }

                // Include sidecar files if present (OCR text and verification JSON)
                foreach (['ocr_text_path', 'verification_data_path'] as $sidecarKey) {
                    if (!empty($doc[$sidecarKey])) {
                        $sidecarPath = $this->resolveFilePath($doc[$sidecarKey]);
                        if ($sidecarPath && file_exists($sidecarPath)) {
                            $sz = @filesize($sidecarPath);
                            if ($sz !== false) { $activeSize += (int)$sz; }
                        }
                    }
                }
            }
        }
        
        $stats[] = [
            'category' => 'active',
            'student_count' => (int)$activeStudentCount,
            'file_count' => $activeFiles,
            'total_size' => $activeSize
        ];
        
        // 2. PAST DISTRIBUTIONS - Scan ZIP files using pathConfig
        $distributionsPath = $this->pathConfig->getDistributionsPath();
        $distFiles = 0;
        $distSize = 0;
        $distCount = 0;
        $uniqueStudentsInDistributions = 0;
        
        // Count unique students across all distributions from distribution_student_snapshot
        $uniqueStudentsQuery = pg_query($this->conn, "
            SELECT COUNT(DISTINCT student_id) as unique_count
            FROM distribution_student_snapshot
        ");
        
        if ($uniqueStudentsQuery && pg_num_rows($uniqueStudentsQuery) > 0) {
            $uniqueData = pg_fetch_assoc($uniqueStudentsQuery);
            $uniqueStudentsInDistributions = (int)($uniqueData['unique_count'] ?? 0);
        }
        
        if (is_dir($distributionsPath)) {
            $zipFiles = glob($distributionsPath . '/*.zip');
            $distFiles = count($zipFiles);
            foreach ($zipFiles as $zipFile) {
                if (is_file($zipFile)) {
                    $distSize += filesize($zipFile);
                    $distCount++;
                }
            }
        }
        
        $stats[] = [
            'category' => 'distributions',
            'student_count' => $uniqueStudentsInDistributions, // Unique students across all distributions
            'file_count' => $distFiles,
            'total_size' => $distSize
        ];
        
        // 3. ARCHIVED STUDENTS - Query documents table for archived students
        $archivedFiles = 0;
        $archivedSize = 0;
        
        $archivedStudentsQuery = pg_query($this->conn, "SELECT COUNT(*) as count FROM students WHERE is_archived = true");
        $archivedStudentCount = $archivedStudentsQuery ? (pg_fetch_assoc($archivedStudentsQuery)['count'] ?? 0) : 0;
        
        // Get documents for archived students from database
        $archivedDocsQuery = pg_query($this->conn, "
            SELECT d.file_path, d.file_size_bytes, d.ocr_text_path, d.verification_data_path 
            FROM documents d
            INNER JOIN students s ON d.student_id = s.student_id
            WHERE s.is_archived = true
        ");
        
        if ($archivedDocsQuery) {
            while ($doc = pg_fetch_assoc($archivedDocsQuery)) {
                $archivedFiles++;
                if (!empty($doc['file_size_bytes'])) {
                    $archivedSize += (int)$doc['file_size_bytes'];
                } elseif (!empty($doc['file_path'])) {
                    $fullPath = $this->resolveFilePath($doc['file_path']);
                    if ($fullPath && file_exists($fullPath)) {
                        $sz = @filesize($fullPath);
                        if ($sz !== false) { $archivedSize += (int)$sz; }
                    }
                }

                foreach (['ocr_text_path', 'verification_data_path'] as $sidecarKey) {
                    if (!empty($doc[$sidecarKey])) {
                        $sidecarPath = $this->resolveFilePath($doc[$sidecarKey]);
                        if ($sidecarPath && file_exists($sidecarPath)) {
                            $sz = @filesize($sidecarPath);
                            if ($sz !== false) { $archivedSize += (int)$sz; }
                        }
                    }
                }
            }
        }
        
        $stats[] = [
            'category' => 'archived',
            'student_count' => (int)$archivedStudentCount,
            'file_count' => $archivedFiles,
            'total_size' => $archivedSize
        ];

        // 4. MUNICIPALITY LOGOS
        $muniLogosPath = $this->pathConfig->getMunicipalLogosPath();
        $muniTotals = $this->scanDirectoryTotals($muniLogosPath);
        $stats[] = [
            'category' => 'municipality_logos',
            'student_count' => 0,
            'file_count' => $muniTotals['files'],
            'total_size' => $muniTotals['size']
        ];

        // 5. ANNOUNCEMENTS (images, attachments)
        $annPath = $this->pathConfig->getAnnouncementsPath();
        $annTotals = $this->scanDirectoryTotals($annPath);
        $stats[] = [
            'category' => 'announcements',
            'student_count' => 0,
            'file_count' => $annTotals['files'],
            'total_size' => $annTotals['size']
        ];

        // 6. TEMP FOLDERS (all doc types – includes JSON sidecars)
        $tempPath = $this->pathConfig->getTempPath();
        $tempTotals = $this->scanDirectoryTotals($tempPath);
        $stats[] = [
            'category' => 'temp',
            'student_count' => 0,
            'file_count' => $tempTotals['files'],
            'total_size' => $tempTotals['size']
        ];

        // 7. BLACKLISTED AND LOST+FOUND if present
        $blacklistedPath = rtrim($this->pathConfig->getUploadsDir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'blacklisted_students';
        $blackTotals = $this->scanDirectoryTotals($blacklistedPath);
        if ($blackTotals['files'] > 0) {
            $stats[] = [
                'category' => 'blacklisted',
                'student_count' => 0,
                'file_count' => $blackTotals['files'],
                'total_size' => $blackTotals['size']
            ];
        }

        $lostFoundPath = rtrim($this->pathConfig->getUploadsDir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'lost+found';
        $lostTotals = $this->scanDirectoryTotals($lostFoundPath);
        if ($lostTotals['files'] > 0) {
            $stats[] = [
                'category' => 'lost_found',
                'student_count' => 0,
                'file_count' => $lostTotals['files'],
                'total_size' => $lostTotals['size']
            ];
        }
        
        return $stats;
    }
    
    /**
     * Resolve file path from database to actual filesystem path
     * Handles both relative paths (/assets/uploads/...) and absolute paths
     */
    private function resolveFilePath($dbPath) {
        if (empty($dbPath)) {
            return '';
        }
        
        // Normalize to an absolute path under uploads
        $uploadsDir = rtrim($this->pathConfig->getUploadsDir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $candidate = '';

        if (preg_match('#^/?assets/uploads/(.+)$#', $dbPath, $m)) {
            $candidate = $uploadsDir . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $m[1]);
        } elseif (strpos($dbPath, $uploadsDir) === 0) {
            $candidate = $dbPath;
        } elseif (strpos($dbPath, '/mnt/assets/uploads/') === 0) {
            $candidate = str_replace('/', DIRECTORY_SEPARATOR, $dbPath);
        } else {
            $candidate = $uploadsDir . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $dbPath), DIRECTORY_SEPARATOR);
        }

        if ($candidate && file_exists($candidate)) {
            return $candidate;
        }

        // Try flexible resolution: handle student/students and Railway folder name variants
        $relative = preg_replace('#^' . preg_quote($uploadsDir, '#') . '#', '', $candidate);
        $relative = ltrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $relative), DIRECTORY_SEPARATOR);
        $flex = $this->findExistingFileVariant($relative);
        return $flex ?: $candidate;
    }

    /**
     * Recursively scan a directory and return total files and size.
     * Safely handles missing paths and permission issues.
     * @param string $path
     * @return array{files:int,size:int}
     */
    private function scanDirectoryTotals($path) {
        $totalFiles = 0;
        $totalSize = 0;

        if (!is_dir($path)) {
            return ['files' => 0, 'size' => 0];
        }

        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iter as $file) {
            try {
                if ($file->isFile()) {
                    $totalFiles++;
                    $size = @filesize($file->getPathname());
                    if ($size !== false) {
                        $totalSize += (int)$size;
                    }
                }
            } catch (Throwable $t) {
                // Ignore unreadable files
            }
        }

        return ['files' => $totalFiles, 'size' => $totalSize];
    }

    // Build candidate absolute paths for a standard student document folder name
    private function resolveStudentFolderCandidates($standardName) {
        $uploadsDir = rtrim($this->pathConfig->getUploadsDir(), DIRECTORY_SEPARATOR);
        $studentBases = ['student', 'students', 'Student', 'Students'];
        $mapped = $this->pathConfig->getFolderName($standardName);
        $variants = array_unique([
            $mapped,
            strtolower($mapped),
            strtoupper($mapped),
            ucfirst(strtolower($mapped)),
            $standardName,
            strtolower($standardName),
            strtoupper($standardName),
            ucfirst(strtolower($standardName))
        ]);
        $candidates = [];
        foreach ($studentBases as $base) {
            foreach ($variants as $v) {
                $candidates[] = $uploadsDir . DIRECTORY_SEPARATOR . $base . DIRECTORY_SEPARATOR . $v;
            }
        }
        foreach ($variants as $v) {
            $candidates[] = $uploadsDir . DIRECTORY_SEPARATOR . $v;
        }
        $seen = [];
        $uniq = [];
        foreach ($candidates as $p) {
            if (!isset($seen[$p])) { $uniq[] = $p; $seen[$p] = true; }
        }
        return $uniq;
    }

    // Attempt to find an existing file when folder names differ between environments
    private function findExistingFileVariant($relativeUnderUploads) {
        $uploadsDir = rtrim($this->pathConfig->getUploadsDir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $parts = array_values(array_filter(explode(DIRECTORY_SEPARATOR, $relativeUnderUploads), function($p){ return $p !== ''; }));
        if (count($parts) < 3) {
            $path = $uploadsDir . $relativeUnderUploads;
            return file_exists($path) ? $path : '';
        }

        // Locate the base segment (student/students)
        $baseIdx = -1;
        for ($i=0; $i<count($parts); $i++) {
            $segLower = strtolower($parts[$i]);
            if (in_array($segLower, ['student','students'])) { $baseIdx = $i; break; }
        }
        if ($baseIdx === -1 || !isset($parts[$baseIdx+1])) {
            $path = $uploadsDir . $relativeUnderUploads;
            return file_exists($path) ? $path : '';
        }

        $docTypeSeg = $parts[$baseIdx+1];
        $standardDoc = $this->pathConfig->getStandardFolderName($docTypeSeg);
        $docVariants = $this->pathConfig->getFolderNameVariations($standardDoc);
        $baseVariants = ['student','students','Student','Students'];

        foreach ($baseVariants as $b) {
            foreach ($docVariants as $d) {
                $mut = $parts;
                $mut[$baseIdx] = $b;
                $mut[$baseIdx+1] = $d;
                $candidate = $uploadsDir . implode(DIRECTORY_SEPARATOR, $mut);
                if (file_exists($candidate)) { return $candidate; }
            }
        }

        return '';
    }

    private function resetGivenStudents() {
        if ($this->studentResetColumnCache === null) {
            $this->studentResetColumnCache = [
                'payroll_no' => false,
                'payroll_number' => false,
                'qr_code_path' => false,
                'qr_code' => false,
            ];

            $columnQuery = "SELECT column_name FROM information_schema.columns WHERE table_name = 'students' AND column_name IN ('payroll_no','payroll_number','qr_code_path','qr_code')";
            $columnResult = pg_query($this->conn, $columnQuery);
            if ($columnResult) {
                while ($row = pg_fetch_assoc($columnResult)) {
                    $name = $row['column_name'];
                    if (array_key_exists($name, $this->studentResetColumnCache)) {
                        $this->studentResetColumnCache[$name] = true;
                    }
                }
            }
        }

        $setParts = ["status = 'applicant'"];

        if ($this->studentResetColumnCache['payroll_no']) {
            $setParts[] = 'payroll_no = NULL';
        } elseif ($this->studentResetColumnCache['payroll_number']) {
            $setParts[] = 'payroll_number = NULL';
        }

        if ($this->studentResetColumnCache['qr_code_path']) {
            $setParts[] = 'qr_code_path = NULL';
        } elseif ($this->studentResetColumnCache['qr_code']) {
            $setParts[] = 'qr_code = NULL';
        }

        $query = "UPDATE students SET " . implode(', ', $setParts) . " WHERE status = 'given'";
        $result = pg_query($this->conn, $query);

        if (!$result) {
            throw new Exception('Failed to reset students: ' . pg_last_error($this->conn));
        }

        $deleteQr = pg_query($this->conn, "DELETE FROM qr_codes");
        if ($deleteQr === false) {
            throw new Exception('Failed to clear QR codes: ' . pg_last_error($this->conn));
        }

        return pg_affected_rows($result);
    }

    private function clearScheduleData() {
        $deleteResult = pg_query($this->conn, "DELETE FROM schedules");
        if ($deleteResult === false) {
            throw new Exception('Failed to clear schedules: ' . pg_last_error($this->conn));
        }

        $settingsPath = __DIR__ . '/../data/municipal_settings.json';
        $settings = [];
        if (file_exists($settingsPath)) {
            $decoded = json_decode(file_get_contents($settingsPath), true);
            if (is_array($decoded)) {
                $settings = $decoded;
            }
        }

        $settings['schedule_published'] = false;
        if (isset($settings['schedule_meta'])) {
            unset($settings['schedule_meta']);
        }

        $encoded = json_encode($settings, JSON_PRETTY_PRINT);
        if ($encoded === false) {
            throw new Exception('Failed to encode schedule settings to JSON');
        }

        if (file_put_contents($settingsPath, $encoded) === false) {
            throw new Exception('Failed to update schedule settings file');
        }
    }
}
