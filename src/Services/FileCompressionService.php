<?php

namespace App\Services;

use Exception;

class FileCompressionService
{
	private $conn;
	private $fileArchiveSupportsDistribution = null;
	private $pathConfig;

	public function __construct()
	{
		global $connection;
		$this->conn = $connection;
		$this->pathConfig = \FilePathConfig::getInstance();

		error_log("FileCompressionService: Environment=" . ($this->pathConfig->isRailway() ? 'Railway' : 'Localhost'));
	}

	public function compressDistribution($distributionId, $adminId)
	{
		try {
			// DO NOT start a new transaction here - caller should manage transactions

			$distribution = null;
			$distQuery = "SELECT d.*
						 FROM distributions d
						 WHERE d.distribution_id = $1";
			$distResult = @pg_query_params($this->conn, $distQuery, [$distributionId]);

			if ($distResult && pg_num_rows($distResult) > 0) {
				$distribution = pg_fetch_assoc($distResult);
			} else {
				// Fallback for config-driven distributions: synthesize basic info
				$distribution = [
					'distribution_id' => $distributionId,
					'status' => 'active',
					'created_at' => date('Y-m-d H:i:s'),
				];
			}

			error_log("FileCompressionService: Looking for snapshot with distribution_id = '$distributionId'");
			$snapshotQuery = "SELECT snapshot_id, files_compressed FROM distribution_snapshots WHERE distribution_id = $1 LIMIT 1";
			$snapshotResult = pg_query_params($this->conn, $snapshotQuery, [$distributionId]);
			$snapshotId = null;
			$alreadyCompressed = false;

			if (!$snapshotResult) {
				error_log("FileCompressionService ERROR: Query failed - " . pg_last_error($this->conn));
				throw new Exception("Database error while looking for distribution snapshot");
			}

			$rowCount = pg_num_rows($snapshotResult);
			error_log("FileCompressionService: Query returned $rowCount rows");

			if ($rowCount > 0) {
				$snapshotRow = pg_fetch_assoc($snapshotResult);
				$snapshotId = $snapshotRow['snapshot_id'];
				$alreadyCompressed = ($snapshotRow['files_compressed'] === 't' || $snapshotRow['files_compressed'] === true);
				error_log("FileCompressionService: Found snapshot_id = $snapshotId, compressed = " . ($alreadyCompressed ? 'YES' : 'NO'));
			}

			if (!$snapshotId) {
				error_log("FileCompressionService ERROR: No snapshot found for distribution_id = '$distributionId'");
				throw new Exception("No distribution snapshot found for distribution ID: $distributionId");
			}

			if ($alreadyCompressed) {
				return [
					'success' => false,
					'message' => 'This distribution has already been compressed and archived. Files have been deleted.',
					'already_compressed' => true,
				];
			}

			$studentsQuery = "SELECT s.student_id, s.first_name, s.middle_name, s.last_name, dp.payroll_no
							 FROM students s
							 INNER JOIN distribution_student_records dsr ON s.student_id = dsr.student_id
							 LEFT JOIN distribution_payrolls dp ON dp.snapshot_id = $1 AND dp.student_id = s.student_id
							 WHERE dsr.snapshot_id = $1
							 ORDER BY s.student_id";
			$studentsResult = pg_query_params($this->conn, $studentsQuery, [$snapshotId]);

			if (!$studentsResult || pg_num_rows($studentsResult) === 0) {
				throw new Exception("No students found in distribution snapshot $snapshotId");
			}

			$students = pg_fetch_all($studentsResult);

			error_log("=== Distribution Compression Started ===");
			error_log("Distribution ID: $distributionId");
			error_log("Snapshot ID: $snapshotId");
			error_log("Students in snapshot: " . count($students));
			foreach ($students as $student) {
				error_log("  - Student ID: " . $student['student_id'] . " | Name: " . $student['first_name'] . " " . $student['last_name']);
			}

			$studentFiles = [];
			foreach ($students as $student) {
				$studentId = $student['student_id'];
				$studentFiles[$studentId] = [
					'info' => $student,
					'files' => [],
				];
			}

			$folders = [
				'enrollment_forms' => 'enrollment_forms',
				'grades' => 'grades',
				'id_pictures' => 'id_pictures',
				'indigency' => 'indigency',
				'letter_to_mayor' => 'letter_to_mayor',
			];

			$totalFilesFound = 0;
			$totalFilesMatched = 0;

			foreach ($folders as $folderName => $folderType) {
				$candidatePaths = $this->resolveStudentFolderCandidates($folderName);
				$fullPath = null;
				foreach ($candidatePaths as $cand) {
					if (is_dir($cand)) {
						$fullPath = $cand;
						break;
					}
				}

				error_log("Scanning folder candidates: " . implode(' | ', $candidatePaths));
				if ($fullPath) {
					error_log("Using folder: $fullPath");
					$items = scandir($fullPath);
					$hasStudentFolders = false;

					foreach ($items as $item) {
						if ($item !== '.' && $item !== '..' && is_dir($fullPath . '/' . $item)) {
							$hasStudentFolders = true;
							break;
						}
					}

					$files = [];

					if ($hasStudentFolders) {
						error_log("  Using new student-organized structure");
						foreach ($items as $item) {
							if ($item !== '.' && $item !== '..') {
								$studentFolder = $fullPath . '/' . $item;
								if (is_dir($studentFolder)) {
									$studentFilesScan = scandir($studentFolder);
									foreach ($studentFilesScan as $file) {
										if ($file !== '.' && $file !== '..' && is_file($studentFolder . '/' . $file)) {
											if (!preg_match('/\.(ocr\.(txt|json)|verify\.json|confidence\.json|tsv)$/i', $file)) {
												$files[] = $studentFolder . '/' . $file;
											}
										}
									}
								}
							}
						}
					} else {
						error_log("  Using legacy flat structure");
						foreach ($items as $file) {
							if ($file !== '.' && $file !== '..' && is_file($fullPath . DIRECTORY_SEPARATOR . $file)) {
								if (!preg_match('/\.(ocr\.(txt|json)|verify\.json|confidence\.json|tsv)$/i', $file)) {
									$files[] = $fullPath . DIRECTORY_SEPARATOR . $file;
								}
							}
						}
					}

					error_log("  Found " . count($files) . " files in $folderType");
					$totalFilesFound += count($files);

					foreach ($files as $file) {
						if (is_file($file)) {
							$filename = basename($file);
							$filenameLower = strtolower($filename);
							$matched = false;

							foreach ($students as $student) {
								$studentId = $student['student_id'];
								$studentIdLower = strtolower($studentId);
								$parentFolder = basename(dirname($file));

								if (strpos($filenameLower, $studentIdLower) !== false || strtolower($parentFolder) === $studentIdLower) {
									$studentFiles[$studentId]['files'][] = [
										'path' => $file,
										'type' => $folderType,
										'size' => filesize($file),
										'name' => $filename,
									];
									$matched = true;
									$totalFilesMatched++;
									error_log("  ✓ Matched: $filename -> Student $studentId");
									break;
								}
							}

							if (!$matched) {
								error_log("  ✗ NO MATCH: $filename (file not linked to any student with 'given' status)");
							}
						}
					}
				} else {
					error_log("  No matching directory found for '$folderName' (checked variants)");
				}
			}

			error_log("=== File Scan Summary ===");
			error_log("Total files found: $totalFilesFound");
			error_log("Total files matched to students: $totalFilesMatched");
			error_log("Unmatched files: " . ($totalFilesFound - $totalFilesMatched));

			$studentFiles = array_filter($studentFiles, function ($data) {
				return !empty($data['files']);
			});

			if (empty($studentFiles)) {
				throw new Exception("No files found to compress");
			}

			$archiveBaseDir = $this->pathConfig->getDistributionsPath();
			if (!file_exists($archiveBaseDir)) {
				mkdir($archiveBaseDir, 0755, true);
			}

			$zipFilename = $distributionId . '.zip';
			$zipPath = $archiveBaseDir . DIRECTORY_SEPARATOR . $zipFilename;

			$zip = new \ZipArchive();
			if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
				throw new Exception("Cannot create ZIP file: $zipPath");
			}

			$totalOriginalSize = 0;
			$totalCompressedSize = 0;
			$filesCompressed = 0;
			$studentsProcessed = 0;
			$compressionLog = [];
			$filesToDelete = [];

			foreach ($studentFiles as $studentId => $data) {
				$studentInfo = $data['info'];
				$studentFilesList = $data['files'];

				if (empty($studentFilesList)) {
					continue;
				}

				$lastName = trim($studentInfo['last_name'] ?? '');
				$firstName = trim($studentInfo['first_name'] ?? '');
				$middleName = trim($studentInfo['middle_name'] ?? '');
				$middleInitial = !empty($middleName) ? strtoupper(substr($middleName, 0, 1)) . '.' : '';

				$fullName = $lastName;
				if (!empty($firstName)) {
					$fullName .= ', ' . $firstName;
					if (!empty($middleInitial)) {
						$fullName .= ' ' . $middleInitial;
					}
				}

				$fullName = preg_replace('/[<>:"\/\\|?*]/', '', $fullName);
				$payrollNo = trim((string) ($studentInfo['payroll_no'] ?? ''));
				if ($payrollNo === '' || strtolower($payrollNo) === 'null') {
					$payrollNo = $studentId;
				}
				$payrollNo = preg_replace('/[<>:"\/\\|?*]/', '', $payrollNo);

				$studentFolderName = $payrollNo . ' - ' . $fullName;
				$studentOriginalSize = 0;

				foreach ($studentFilesList as $file) {
					if (file_exists($file['path'])) {
						$zipEntryName = $studentFolderName . '/' . $file['name'];
						$zip->addFile($file['path'], $zipEntryName);
						$studentOriginalSize += $file['size'];
						$totalOriginalSize += $file['size'];
						$filesCompressed++;

						$filesToDelete[] = [
							'path' => $file['path'],
							'student_id' => $studentId,
							'type' => $file['type'],
							'name' => $file['name'],
							'size' => $file['size'],
							'archived_path' => $zipEntryName,
						];
					}
				}

				$studentsProcessed++;
				$compressionLog[] = sprintf(
					'Student %s (Payroll: %s | %s %s %s): %d files, %.2f KB -> Folder: %s',
					$studentId,
					$payrollNo,
					$studentInfo['first_name'],
					$middleName ? substr($middleName, 0, 1) . '.' : '',
					$studentInfo['last_name'],
					count($studentFilesList),
					$studentOriginalSize / 1024,
					$studentFolderName
				);
			}

			if (!$zip->close()) {
				throw new Exception('Failed to close ZIP archive - compression may have failed');
			}

			if (!file_exists($zipPath)) {
				throw new Exception("ZIP file was not created at: $zipPath");
			}

			$totalCompressedSize = filesize($zipPath);

			if ($totalCompressedSize === 0) {
				throw new Exception('ZIP file is empty - aborting to preserve original files');
			}

			$zipCheck = new \ZipArchive();
			if ($zipCheck->open($zipPath, \ZipArchive::CHECKCONS) !== true) {
				throw new Exception('ZIP file integrity check failed - archive may be corrupted');
			}
			$zipCheck->close();

			error_log('=== Populating distribution_file_manifest ===');
			$manifestInserted = 0;

			foreach ($filesToDelete as $fileData) {
				$fileHash = file_exists($fileData['path']) ? md5_file($fileData['path']) : null;

				$manifestInsert = @pg_query_params(
					$this->conn,
					'INSERT INTO distribution_file_manifest
					 (snapshot_id, student_id, document_type_code, original_file_path,
					  file_size, file_hash, archived_path)
					 VALUES ($1, $2, $3, $4, $5, $6, $7)',
					[
						$snapshotId,
						$fileData['student_id'],
						$fileData['type'],
						$fileData['path'],
						$fileData['size'],
						$fileHash,
						$fileData['archived_path'],
					]
				);

				if ($manifestInsert) {
					$manifestInserted++;
				} else {
					error_log('Warning: Failed to insert manifest for file: ' . $fileData['path']);
				}
			}

			error_log("Inserted $manifestInserted file manifest record(s)");
			$compressionLog[] = "Recorded $manifestInserted files in distribution_file_manifest";

			$filesDeleted = 0;
			$associatedFilesDeleted = 0;
			foreach ($filesToDelete as $fileData) {
				$filePath = $fileData['path'];

				if (file_exists($filePath) && unlink($filePath)) {
					$filesDeleted++;

					@pg_query_params(
						$this->conn,
						'UPDATE distribution_file_manifest
						 SET deleted_at = NOW()
						 WHERE snapshot_id = $1
						 AND student_id = $2
						 AND original_file_path = $3',
						[$snapshotId, $fileData['student_id'], $fileData['path']]
					);

					$pathInfo = pathinfo($filePath);
					$fileDir = $pathInfo['dirname'];
					$fileBasename = $pathInfo['basename'];
					$fileWithoutExt = $pathInfo['filename'];

					$associatedExtensions = ['.ocr.txt', '.verify.json', '.confidence.json', '.tsv', '.ocr.json'];

					foreach ($associatedExtensions as $ext) {
						$associatedFile1 = $fileDir . '/' . $fileBasename . $ext;
						$associatedFile2 = $fileDir . '/' . $fileWithoutExt . $ext;

						$deleted = false;

						if (file_exists($associatedFile1)) {
							if (unlink($associatedFile1)) {
								$associatedFilesDeleted++;
								$deleted = true;
								error_log('  Deleted associated file: ' . basename($associatedFile1));
							} else {
								error_log('  WARNING: Failed to delete associated file: ' . basename($associatedFile1));
							}
						} elseif (file_exists($associatedFile2)) {
							if (unlink($associatedFile2)) {
								$associatedFilesDeleted++;
								$deleted = true;
								error_log('  Deleted associated file: ' . basename($associatedFile2));
							} else {
								error_log('  WARNING: Failed to delete associated file: ' . basename($associatedFile2));
							}
						}

						if (!$deleted) {
							error_log('  Associated file not found (tried both patterns): ' . $fileBasename . $ext);
						}
					}
				} else {
					error_log('  WARNING: Failed to delete main file: ' . basename($filePath));
				}
			}
			$compressionLog[] = "Deleted $filesDeleted original files from uploads";
			$compressionLog[] = "Deleted $associatedFilesDeleted associated files (OCR/JSON data)";
			error_log("Deleted $filesDeleted main files and $associatedFilesDeleted associated files");

			@pg_query_params(
				$this->conn,
				'UPDATE distributions
				 SET files_compressed = true, compression_date = NOW()
				 WHERE distribution_id = $1',
				[$distributionId]
			);

			@pg_query_params(
				$this->conn,
				'UPDATE distribution_snapshots
				 SET files_compressed = true,
					 compression_date = NOW(),
					 archive_filename = $2
				 WHERE distribution_id = $1 OR archive_filename = $2',
				[$distributionId, $zipFilename]
			);

			$spaceSaved = $totalOriginalSize - $totalCompressedSize;

			try {
				$this->logOperation(
					'compress_distribution',
					$adminId,
					$distributionId,
					null,
					$filesCompressed,
					$totalOriginalSize,
					$totalCompressedSize,
					$spaceSaved,
					'success',
					null
				);
			} catch (Exception $e) {
				error_log('Failed to log operation: ' . $e->getMessage());
			}

			return [
				'success' => true,
				'message' => 'Distribution compressed successfully. Student uploads have been archived and deleted.',
				'archive_path' => $zipPath,
				'size' => $totalCompressedSize,
				'file_count' => $filesCompressed,
				'compression_ratio' => $totalOriginalSize > 0 ? round(($totalCompressedSize / $totalOriginalSize * 100), 2) : 0,
				'statistics' => [
					'students_processed' => $studentsProcessed,
					'files_compressed' => $filesCompressed,
					'original_size' => $totalOriginalSize,
					'compressed_size' => $totalCompressedSize,
					'space_saved' => $spaceSaved,
					'compression_ratio' => $totalOriginalSize > 0 ? round(($totalCompressedSize / $totalOriginalSize * 100), 2) : 0,
					'archive_location' => 'assets/uploads/distributions/' . $zipFilename,
				],
				'log' => $compressionLog,
			];
		} catch (Exception $e) {
			pg_query($this->conn, 'ROLLBACK');

			try {
				$this->logOperation(
					'compress_distribution',
					$adminId,
					$distributionId,
					null,
					0,
					0,
					0,
					0,
					'failed',
					$e->getMessage()
				);
			} catch (Exception $e2) {
				error_log('Failed to log error: ' . $e2->getMessage());
			}

			return [
				'success' => false,
				'message' => $e->getMessage(),
			];
		}
	}

	private function resolveStudentFolderCandidates($standardName)
	{
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
			ucfirst(strtolower($standardName)),
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
			if (!isset($seen[$p])) {
				$uniq[] = $p;
				$seen[$p] = true;
			}
		}
		return $uniq;
	}

	private function deleteDirectory($dir)
	{
		if (!file_exists($dir)) {
			return true;
		}

		if (!is_dir($dir)) {
			return unlink($dir);
		}

		foreach (scandir($dir) as $item) {
			if ($item == '.' || $item == '..') {
				continue;
			}

			if (!$this->deleteDirectory($dir . DIRECTORY_SEPARATOR . $item)) {
				return false;
			}
		}

		return rmdir($dir);
	}

	private function logOperation(
		$operationType,
		$adminId,
		$distributionId,
		$studentId,
		$fileCount,
		$originalSize,
		$compressedSize,
		$spaceSaved,
		$status,
		$errorMessage
	) {
		try {
			$logQuery = 'INSERT INTO file_archive_log
						(operation, performed_by, distribution_id, student_id,
						 file_count, total_size_before, total_size_after, space_saved,
						 operation_status, error_message)
						VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10)';

			if (!$this->fileArchiveLogSupportsDistributionId()) {
				return;
			}

			$result = @pg_query_params($this->conn, $logQuery, [
				$operationType,
				$adminId,
				$distributionId,
				$studentId,
				$fileCount,
				$originalSize,
				$compressedSize,
				$spaceSaved,
				$status,
				$errorMessage,
			]);
			if (!$result) {
				throw new Exception(pg_last_error($this->conn) ?: 'Failed to log operation');
			}
		} catch (Exception $e) {
			error_log('Failed to log file operation: ' . $e->getMessage());
		}
	}

	private function fileArchiveLogSupportsDistributionId()
	{
		if ($this->fileArchiveSupportsDistribution !== null) {
			return $this->fileArchiveSupportsDistribution;
		}
		$query = "SELECT 1 FROM information_schema.columns WHERE table_name = 'file_archive_log' AND column_name = 'distribution_id'";
		$result = @pg_query($this->conn, $query);
		if ($result && pg_num_rows($result) > 0) {
			$this->fileArchiveSupportsDistribution = true;
		} else {
			$this->fileArchiveSupportsDistribution = false;
		}
		return $this->fileArchiveSupportsDistribution;
	}
}