<?php

namespace App\Services;

class FileManagementService
{
	private $conn;
	private $basePath;
	private $pathConfig;

	private $folders = [
		'enrollment_forms' => 'enrollment_forms',
		'grades' => 'grades',
		'id_pictures' => 'id_pictures',
		'indigency' => 'indigency',
		'letter_to_mayor' => 'letter_to_mayor',
	];

	public function __construct($dbConnection = null)
	{
		global $connection;
		$this->conn = $dbConnection ?? $connection;
		$this->pathConfig = \FilePathConfig::getInstance();
		$this->basePath = $this->pathConfig->getUploadsPath();

		error_log(
			'FileManagementService: Environment=' . ($this->pathConfig->isRailway() ? 'Railway' : 'Localhost') .
			', BasePath=' . $this->basePath
		);
	}

	public function moveTemporaryFilesToPermanent($studentId, $adminId = null)
	{
		error_log("FileManagement: Moving temp files to permanent for student: $studentId");

		if ($adminId === null && isset($_SESSION['admin_id'])) {
			$adminId = $_SESSION['admin_id'];
		}

		$studentQuery = pg_query_params(
			$this->conn,
			'SELECT first_name, last_name, middle_name FROM students WHERE student_id = $1',
			[$studentId]
		);

		if (!$studentQuery || pg_num_rows($studentQuery) === 0) {
			error_log("FileManagement: Student not found: $studentId");
			return ['success' => false, 'message' => 'Student not found'];
		}

		$student = pg_fetch_assoc($studentQuery);
		$lastName = preg_replace('/[^a-zA-Z0-9]/', '', $student['last_name']);
		$firstName = preg_replace('/[^a-zA-Z0-9]/', '', $student['first_name']);

		$movedFiles = [];
		$errors = [];
		$documentIdsToUpdate = [];

		foreach ($this->folders as $tempFolder => $permanentFolder) {
			$tempPath = $this->pathConfig->getTempPath($tempFolder);
			$permanentPath = $this->pathConfig->getStudentPath($permanentFolder);

			if (!is_dir($tempPath)) {
				error_log("FileManagement: Temp path does not exist: $tempPath");
				continue;
			}

			if (!is_dir($permanentPath)) {
				error_log("FileManagement: Creating permanent path: $permanentPath");
				mkdir($permanentPath, 0755, true);
			}

			$files = glob($tempPath . DIRECTORY_SEPARATOR . $studentId . '_*');

			foreach ($files as $file) {
				if (!is_file($file)) {
					continue;
				}

				if (preg_match('/\.(verify\.json|ocr\.txt|confidence\.json)$/', $file)) {
					continue;
				}

				$filename = basename($file);
				$extension = pathinfo($file, PATHINFO_EXTENSION);

				$docType = '';
				switch ($permanentFolder) {
					case 'enrollment_forms':
						$docType = 'EAF';
						break;
					case 'grades':
						$docType = 'grades';
						break;
					case 'id_pictures':
						$docType = 'id';
						break;
					case 'indigency':
						$docType = 'indigency';
						break;
					case 'letter_to_mayor':
						$docType = 'lettertomayor';
						break;
				}

				$newFilename = $studentId . '_' . $lastName . '_' . $firstName . '_' . $docType . '.' . $extension;
				$newPath = $permanentPath . DIRECTORY_SEPARATOR . $newFilename;

				if (rename($file, $newPath)) {
					$movedFiles[] = $newFilename;
					error_log("FileManagement: Moved $filename -> $newFilename");

					$documentIdsToUpdate[] = [
						'old_path' => $file,
						'new_path' => $newPath,
					];

					$associatedFiles = glob($file . '.*');
					foreach ($associatedFiles as $assocFile) {
						$assocExt = substr($assocFile, strlen($file));
						rename($assocFile, $newPath . $assocExt);
					}
				} else {
					$errors[] = "Failed to move: $filename";
					error_log("FileManagement: Failed to move $filename");
				}
			}
		}

		if (!empty($documentIdsToUpdate)) {
			foreach ($documentIdsToUpdate as $pathInfo) {
				$oldPathForDb = $this->pathConfig->getRelativePath($pathInfo['old_path']);
				$newPathForDb = $this->pathConfig->getRelativePath($pathInfo['new_path']);

				$updateQuery = 'UPDATE documents
							   SET file_path = $1,
								   status = \'approved\',
								   approved_by = $2,
								   approved_date = NOW(),
								   last_modified = NOW()
							   WHERE student_id = $3
							   AND (file_path = $4 OR file_path LIKE $5)';

				$result = pg_query_params($this->conn, $updateQuery, [
					$newPathForDb,
					$adminId,
					$studentId,
					$oldPathForDb,
					'%' . basename($pathInfo['old_path']),
				]);

				if ($result) {
					$rowsUpdated = pg_affected_rows($result);
					if ($rowsUpdated > 0) {
						error_log('FileManagement: Updated documents table for ' . basename($pathInfo['new_path']) . " (status=approved, approved_by=$adminId)");
					}
				} else {
					error_log('FileManagement: Failed to update documents table for ' . basename($pathInfo['new_path']) . ': ' . pg_last_error($this->conn));
				}
			}
		}

		$result = [
			'success' => count($errors) === 0,
			'files_moved' => count($movedFiles),
			'files' => $movedFiles,
			'errors' => $errors,
		];

		error_log('FileManagement: Moved ' . count($movedFiles) . " files for $studentId");

		return $result;
	}

	public function compressArchivedStudent($studentId)
	{
		error_log("FileManagement: Compressing files for archived student: $studentId");

		$studentQuery = pg_query_params(
			$this->conn,
			'SELECT first_name, last_name, middle_name FROM students WHERE student_id = $1',
			[$studentId]
		);

		if (!$studentQuery || pg_num_rows($studentQuery) === 0) {
			return ['success' => false, 'message' => 'Student not found'];
		}

		$student = pg_fetch_assoc($studentQuery);
		$fullName = trim($student['first_name'] . ' ' . ($student['middle_name'] ?? '') . ' ' . $student['last_name']);

		$archivePath = $this->pathConfig->getArchivedStudentsPath();
		if (!is_dir($archivePath)) {
			mkdir($archivePath, 0755, true);
		}

		$zipFile = $archivePath . DIRECTORY_SEPARATOR . $studentId . '.zip';
		$zip = new \ZipArchive();

		if ($zip->open($zipFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
			return ['success' => false, 'message' => 'Failed to create ZIP file'];
		}

		$filesAdded = 0;
		$filesToDelete = [];
		$totalOriginalSize = 0;

		$folderPairs = [
			['temp' => 'enrollment_forms', 'permanent' => 'enrollment_forms'],
			['temp' => 'grades', 'permanent' => 'grades'],
			['temp' => 'id_pictures', 'permanent' => 'id_pictures'],
			['temp' => 'indigency', 'permanent' => 'indigency'],
			['temp' => 'letter_to_mayor', 'permanent' => 'letter_to_mayor'],
		];

		foreach ($folderPairs as $folderPair) {
			$tempPath = $this->pathConfig->getTempPath($folderPair['temp']);

			if (is_dir($tempPath)) {
				$files = glob($tempPath . DIRECTORY_SEPARATOR . '*.*');

				foreach ($files as $file) {
					if (!is_file($file)) {
						continue;
					}

					$filename = basename($file);

					if (strpos($filename, $studentId . '_') === 0) {
						$zipPath = $folderPair['permanent'] . '/' . $filename;

						if ($zip->addFile($file, $zipPath)) {
							$filesAdded++;
							$filesToDelete[] = $file;
							$totalOriginalSize += filesize($file);
							error_log("FileManagement: Added temp file to ZIP: $zipPath");
						}
					}
				}
			}
		}

		foreach ($folderPairs as $folderPair) {
			$folderPath = $this->pathConfig->getStudentPath($folderPair['permanent']);

			if (!is_dir($folderPath)) {
				continue;
			}

			$files = glob($folderPath . DIRECTORY_SEPARATOR . '*.*');

			foreach ($files as $file) {
				if (!is_file($file)) {
					continue;
				}

				$filename = basename($file);
				if (strpos($filename, $studentId) === 0 || stripos($filename, strtolower($studentId)) === 0) {
					$zipPath = $folderPair['permanent'] . '/' . $filename;

					if ($zip->addFile($file, $zipPath)) {
						$filesAdded++;
						$filesToDelete[] = $file;
						$totalOriginalSize += filesize($file);
						error_log("FileManagement: Added permanent file to ZIP: $zipPath");
					}
				}
			}
		}

		$zip->close();

		if ($filesAdded === 0) {
			@unlink($zipFile);
			error_log("FileManagement: No files found for $studentId");
			return [
				'success' => true,
				'files_archived' => 0,
				'message' => 'No files found to archive',
			];
		}

		$filesDeleted = 0;
		foreach ($filesToDelete as $file) {
			if (@unlink($file)) {
				$filesDeleted++;
				$associatedFiles = glob($file . '.*');
				foreach ($associatedFiles as $assocFile) {
					@unlink($assocFile);
				}
			}
		}

		$compressedSize = filesize($zipFile);
		$spaceSaved = $totalOriginalSize - $compressedSize;
		$compressionRatio = $totalOriginalSize > 0 ? round(($spaceSaved / $totalOriginalSize) * 100, 1) : 0;

		error_log('FileManagement: Archived ' . $filesAdded . ' files for ' . $studentId . ', saved ' . ($spaceSaved / 1024 / 1024) . ' MB');

		return [
			'success' => true,
			'files_archived' => $filesAdded,
			'files_deleted' => $filesDeleted,
			'original_size' => $totalOriginalSize,
			'compressed_size' => $compressedSize,
			'space_saved' => $spaceSaved,
			'compression_ratio' => $compressionRatio,
			'zip_file' => $zipFile,
		];
	}

	public function cleanupTemporaryFiles($olderThanDays = 7)
	{
		error_log("FileManagement: Cleaning up temp files older than $olderThanDays days");

		$cutoffTime = time() - ($olderThanDays * 24 * 60 * 60);
		$deletedCount = 0;
		$deletedSize = 0;

		$tempPath = $this->basePath . '/temp';
		$folders = ['enrollment_forms', 'grades', 'id_pictures', 'indigency', 'letter_to_mayor'];

		foreach ($folders as $folder) {
			$folderPath = $tempPath . '/' . $folder;

			if (!is_dir($folderPath)) {
				continue;
			}

			$files = glob($folderPath . '/*');
			foreach ($files as $file) {
				if (!is_file($file)) {
					continue;
				}

				if (filemtime($file) < $cutoffTime) {
					$size = filesize($file);
					if (@unlink($file)) {
						$deletedCount++;
						$deletedSize += $size;
					}
				}
			}
		}

		error_log('FileManagement: Deleted ' . $deletedCount . ' temp files, freed ' . ($deletedSize / 1024 / 1024) . ' MB');

		return [
			'success' => true,
			'files_deleted' => $deletedCount,
			'space_freed' => $deletedSize,
		];
	}

	public function getArchivedStudentZip($studentId)
	{
		$zipFile = dirname(__DIR__, 2) . '/assets/uploads/archived_students/' . $studentId . '.zip';
		return file_exists($zipFile) ? $zipFile : null;
	}

	public function extractArchivedStudent($studentId, $extractPath = null)
	{
		$zipFile = $this->getArchivedStudentZip($studentId);

		if (!$zipFile) {
			return ['success' => false, 'message' => 'Archive not found'];
		}

		if (!$extractPath) {
			$extractPath = $this->basePath . '/student';
		}

		$zip = new \ZipArchive();
		if ($zip->open($zipFile) !== true) {
			return ['success' => false, 'message' => 'Failed to open ZIP file'];
		}

		$extractedFiles = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$filename = $zip->getNameIndex($i);
			$fileInfo = pathinfo($filename);

			$targetDir = $extractPath . '/' . $fileInfo['dirname'];
			if (!is_dir($targetDir)) {
				mkdir($targetDir, 0755, true);
			}

			$targetFile = $extractPath . '/' . $filename;
			if (copy('zip://' . $zipFile . '#' . $filename, $targetFile)) {
				$extractedFiles[] = $filename;
			}
		}

		$zip->close();

		return [
			'success' => true,
			'files_extracted' => count($extractedFiles),
			'files' => $extractedFiles,
		];
	}

	public function deleteArchivedZip($studentId)
	{
		$zipFile = $this->getArchivedStudentZip($studentId);

		if ($zipFile && file_exists($zipFile)) {
			if (@unlink($zipFile)) {
				error_log("FileManagement: Deleted archive ZIP for student: $studentId");
				return true;
			}
			error_log("FileManagement: Failed to delete archive ZIP for student: $studentId");
			return false;
		}

		error_log("FileManagement: No archive ZIP found for student: $studentId");
		return false;
	}
}