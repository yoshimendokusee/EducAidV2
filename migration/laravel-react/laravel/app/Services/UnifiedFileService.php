<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Exception;

class UnifiedFileService
{
    /**
     * Document type mapping with metadata
     */
    const DOCUMENT_TYPES = [
        'eaf' => ['code' => '00', 'name' => 'eaf', 'folder' => 'enrollment_forms'],
        'academic_grades' => ['code' => '01', 'name' => 'academic_grades', 'folder' => 'grades'],
        'letter_to_mayor' => ['code' => '02', 'name' => 'letter_to_mayor', 'folder' => 'letter_to_mayor'],
        'certificate_of_indigency' => ['code' => '03', 'name' => 'certificate_of_indigency', 'folder' => 'indigency'],
        'id_picture' => ['code' => '04', 'name' => 'id_picture', 'folder' => 'id_pictures']
    ];

    private OcrProcessingService $ocrService;

    public function __construct(OcrProcessingService $ocrService)
    {
        $this->ocrService = $ocrService;
    }

    /**
     * Move documents from temp to permanent storage after approval
     * Supports both registration and applicant approval workflows
     *
     * @param string $studentId
     * @param int|null $adminId
     * @param array $options
     * @return array ['success' => bool, 'moved_count' => int, 'errors' => array]
     */
    public function moveToPermStorage(string $studentId, ?int $adminId = null, array $options = []): array
    {
        try {
            Log::info("UnifiedFileService::moveToPermStorage - START for student: $studentId");

            // Get all temp documents for this student
            $documents = DB::table('documents')
                ->where('student_id', $studentId)
                ->where('status', 'temp')
                ->get();

            Log::info("UnifiedFileService::moveToPermStorage - Found {$documents->count()} temp documents");

            $movedCount = 0;
            $errors = [];

            DB::beginTransaction();

            foreach ($documents as $doc) {
                try {
                    $oldPath = $doc->file_path;
                    $oldFolder = dirname($oldPath);
                    $fileName = basename($oldPath);
                    $documentTypeCode = $doc->document_type_code;

                    // Get document type info
                    $docTypeInfo = null;
                    foreach (self::DOCUMENT_TYPES as $type => $info) {
                        if ($info['code'] === $documentTypeCode) {
                            $docTypeInfo = $info;
                            break;
                        }
                    }

                    if (!$docTypeInfo) {
                        $errors[] = "Unknown document type code: $documentTypeCode";
                        continue;
                    }

                    // Construct new path
                    $newFolder = storage_path("app/students/$studentId/{$docTypeInfo['folder']}");
                    $newPath = "$newFolder/$fileName";

                    // Create directory if it doesn't exist
                    if (!file_exists($newFolder)) {
                        mkdir($newFolder, 0755, true);
                    }

                    // Copy file to permanent storage
                    if (!file_exists($oldPath)) {
                        $errors[] = "Source file not found: $oldPath";
                        continue;
                    }

                    if (!copy($oldPath, $newPath)) {
                        $errors[] = "Failed to copy file from temp storage: $oldPath";
                        continue;
                    }

                    // Update database
                    DB::table('documents')
                        ->where('document_id', $doc->document_id)
                        ->update([
                            'file_path' => $newPath,
                            'status' => 'permanent',
                            'approved_by' => $adminId,
                            'approved_at' => now()
                        ]);

                    // Delete temp file
                    if (file_exists($oldPath)) {
                        unlink($oldPath);
                    }

                    $movedCount++;
                    Log::info("UnifiedFileService::moveToPermStorage - Moved doc {$doc->document_id}");
                } catch (Exception $e) {
                    $errors[] = $e->getMessage();
                    Log::error("UnifiedFileService::moveToPermStorage - Error moving doc: {$e->getMessage()}");
                }
            }

            DB::commit();

            Log::info("UnifiedFileService::moveToPermStorage - Completed: moved=$movedCount, errors=" . count($errors));

            return [
                'success' => true,
                'moved_count' => $movedCount,
                'errors' => $errors
            ];
        } catch (Exception $e) {
            DB::rollBack();
            Log::error("UnifiedFileService::moveToPermStorage - Exception: {$e->getMessage()}");

            return [
                'success' => false,
                'moved_count' => 0,
                'errors' => [$e->getMessage()]
            ];
        }
    }

    /**
     * Archive documents for a student
     *
     * @param string $studentId
     * @return array ['success' => bool, 'archived_count' => int, 'error' => string|null]
     */
    public function archiveStudentDocuments(string $studentId): array
    {
        try {
            DB::beginTransaction();

            $updated = DB::table('documents')
                ->where('student_id', $studentId)
                ->whereIn('status', ['permanent', 'approved'])
                ->update([
                    'status' => 'archived',
                    'archived_at' => now()
                ]);

            DB::commit();

            Log::info("UnifiedFileService::archiveStudentDocuments - Archived $updated documents for student $studentId");

            return [
                'success' => true,
                'archived_count' => $updated,
                'error' => null
            ];
        } catch (Exception $e) {
            DB::rollBack();
            Log::error("UnifiedFileService::archiveStudentDocuments - Error: {$e->getMessage()}");

            return [
                'success' => false,
                'archived_count' => 0,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get student documents by type and status
     *
     * @param string $studentId
     * @param string|null $type
     * @param string|null $status
     * @return array
     */
    public function getStudentDocuments(string $studentId, ?string $type = null, ?string $status = null): array
    {
        $query = DB::table('documents')
            ->where('student_id', $studentId);

        if ($type) {
            $query->where('document_type_code', $type);
        }

        if ($status) {
            $query->where('status', $status);
        }

        return $query->orderBy('created_at', 'desc')->get()->toArray();
    }

    /**
     * Process OCR for grade documents
     *
     * @param string $studentId
     * @param string $filePath
     * @return array ['success' => bool, 'subjects' => array, 'error' => string|null]
     */
    public function processGradeDocumentOcr(string $studentId, string $filePath): array
    {
        try {
            Log::info("UnifiedFileService::processGradeDocumentOcr - Processing for student: $studentId");

            $result = $this->ocrService->processGradeDocument($filePath);

            if (!$result['success']) {
                return [
                    'success' => false,
                    'subjects' => [],
                    'error' => $result['error'] ?? 'OCR processing failed'
                ];
            }

            // Store OCR results in database
            DB::table('documents')
                ->where('file_path', $filePath)
                ->update([
                    'ocr_data' => json_encode($result['subjects']),
                    'ocr_processed_at' => now()
                ]);

            return [
                'success' => true,
                'subjects' => $result['subjects'] ?? [],
                'error' => null
            ];
        } catch (Exception $e) {
            Log::error("UnifiedFileService::processGradeDocumentOcr - Error: {$e->getMessage()}");

            return [
                'success' => false,
                'subjects' => [],
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Delete student documents
     *
     * @param string $studentId
     * @param array|null $documentIds (null = delete all)
     * @return array ['success' => bool, 'deleted_count' => int]
     */
    public function deleteStudentDocuments(string $studentId, ?array $documentIds = null): array
    {
        try {
            DB::beginTransaction();

            $query = DB::table('documents')->where('student_id', $studentId);

            if ($documentIds) {
                $query->whereIn('document_id', $documentIds);
            }

            $documents = $query->get();

            // Delete physical files
            foreach ($documents as $doc) {
                if (file_exists($doc->file_path)) {
                    unlink($doc->file_path);
                }
            }

            // Delete database records
            $deleted = $query->delete();

            DB::commit();

            Log::info("UnifiedFileService::deleteStudentDocuments - Deleted $deleted documents for student $studentId");

            return [
                'success' => true,
                'deleted_count' => $deleted
            ];
        } catch (Exception $e) {
            DB::rollBack();
            Log::error("UnifiedFileService::deleteStudentDocuments - Error: {$e->getMessage()}");

            return [
                'success' => false,
                'deleted_count' => 0
            ];
        }
    }

    /**
     * Export student documents to ZIP for distribution
     *
     * @param string $studentId
     * @param string|null $outputPath
     * @return array ['success' => bool, 'zip_path' => string|null, 'error' => string|null]
     */
    public function exportStudentDocumentsZip(string $studentId, ?string $outputPath = null): array
    {
        try {
            $documents = DB::table('documents')
                ->where('student_id', $studentId)
                ->where('status', '!=', 'archived')
                ->get();

            if ($documents->isEmpty()) {
                return [
                    'success' => false,
                    'zip_path' => null,
                    'error' => 'No documents found to export'
                ];
            }

            if (!$outputPath) {
                $outputPath = storage_path("app/exports/student_{$studentId}_" . time() . '.zip');
            }

            $zipDir = dirname($outputPath);
            if (!file_exists($zipDir)) {
                mkdir($zipDir, 0755, true);
            }

            $zip = new \ZipArchive();
            if ($zip->open($outputPath, \ZipArchive::CREATE) !== true) {
                return [
                    'success' => false,
                    'zip_path' => null,
                    'error' => 'Failed to create ZIP file'
                ];
            }

            foreach ($documents as $doc) {
                if (file_exists($doc->file_path)) {
                    $arcName = "student_{$studentId}/" . basename($doc->file_path);
                    $zip->addFile($doc->file_path, $arcName);
                }
            }

            $zip->close();

            Log::info("UnifiedFileService::exportStudentDocumentsZip - Created ZIP for student $studentId at $outputPath");

            return [
                'success' => true,
                'zip_path' => $outputPath,
                'error' => null
            ];
        } catch (Exception $e) {
            Log::error("UnifiedFileService::exportStudentDocumentsZip - Error: {$e->getMessage()}");

            return [
                'success' => false,
                'zip_path' => null,
                'error' => $e->getMessage()
            ];
        }
    }
}
