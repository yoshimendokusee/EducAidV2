<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * FileUploadService
 * Comprehensive file upload handling with validation, storage, and document tracking
 */
class FileUploadService
{
    public const DOCUMENT_TYPES = [
        'eaf' => ['code' => '00', 'name' => 'eaf', 'folder' => 'enrollment_forms'],
        'academic_grades' => ['code' => '01', 'name' => 'academic_grades', 'folder' => 'grades'],
        'letter_to_mayor' => ['code' => '02', 'name' => 'letter_to_mayor', 'folder' => 'letter_to_mayor'],
        'certificate_of_indigency' => ['code' => '03', 'name' => 'certificate_of_indigency', 'folder' => 'indigency'],
        'id_picture' => ['code' => '04', 'name' => 'id_picture', 'folder' => 'id_pictures'],
    ];

    private const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10MB
    private const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'tiff', 'bmp'];

    /**
     * Upload document to temporary storage
     *
     * @param string $studentId Student ID
     * @param string $docTypeName Document type name (e.g., 'academic_grades')
     * @param string $filePath Path to uploaded file
     * @param string $originalName Original filename
     * @return array ['success' => bool, 'documentId' => string|null, 'path' => string|null, 'error' => string|null]
     */
    public function uploadToTemp(
        string $studentId,
        string $docTypeName,
        string $filePath,
        string $originalName
    ): array {
        try {
            // Validate document type
            if (!isset(self::DOCUMENT_TYPES[$docTypeName])) {
                return ['success' => false, 'error' => 'Invalid document type'];
            }

            $docInfo = self::DOCUMENT_TYPES[$docTypeName];

            // Validate file
            if (!file_exists($filePath)) {
                return ['success' => false, 'error' => 'File not found'];
            }

            if (filesize($filePath) > self::MAX_FILE_SIZE) {
                return ['success' => false, 'error' => 'File exceeds maximum size (10MB)'];
            }

            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            if (!in_array($extension, self::ALLOWED_EXTENSIONS)) {
                return ['success' => false, 'error' => 'Invalid file type'];
            }

            // Generate unique filename
            $timestamp = now()->format('YmdHis');
            $newFilename = "{$studentId}_{$docInfo['name']}_{$timestamp}.{$extension}";

            // Store in temp directory
            $tempPath = "temp/{$docInfo['folder']}/{$newFilename}";
            Storage::disk('local')->putFileAs(
                "temp/{$docInfo['folder']}",
                $filePath,
                $newFilename
            );

            // Create document record
            $documentId = DB::table('documents')->insertGetId([
                'student_id' => $studentId,
                'document_type_code' => $docInfo['code'],
                'document_type_name' => $docTypeName,
                'file_path' => $tempPath,
                'original_filename' => $originalName,
                'status' => 'temp',
                'ocr_confidence' => 0,
                'uploaded_at' => now(),
            ]);

            Log::info("FileUploadService: Uploaded document to temp", [
                'student_id' => $studentId,
                'document_id' => $documentId,
                'path' => $tempPath,
            ]);

            return [
                'success' => true,
                'documentId' => (string)$documentId,
                'path' => $tempPath,
            ];
        } catch (Exception $e) {
            Log::error('FileUploadService::uploadToTemp failed', [
                'student_id' => $studentId,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Move document from temp to permanent storage
     *
     * @param string $studentId Student ID
     * @param string $documentId Document ID
     * @param int|null $approvedBy Admin ID who approved
     * @return array ['success' => bool, 'path' => string|null, 'error' => string|null]
     */
    public function moveToPermanent(
        string $studentId,
        string $documentId,
        ?int $approvedBy = null
    ): array {
        try {
            $document = DB::table('documents')->where('document_id', $documentId)->first();

            if (!$document) {
                return ['success' => false, 'error' => 'Document not found'];
            }

            // Get document info
            $docInfo = self::DOCUMENT_TYPES[$document->document_type_name] ?? null;
            if (!$docInfo) {
                return ['success' => false, 'error' => 'Invalid document type'];
            }

            // Read from temp storage
            $tempPath = storage_path('app/' . $document->file_path);
            if (!file_exists($tempPath)) {
                return ['success' => false, 'error' => 'Source file not found'];
            }

            // Generate permanent path
            $timestamp = now()->format('YmdHis');
            $extension = pathinfo($document->original_filename, PATHINFO_EXTENSION);
            $newFilename = pathinfo($document->original_filename, PATHINFO_FILENAME) . "__{$timestamp}.{$extension}";
            $permanentPath = "documents/{$docInfo['folder']}/{$studentId}/{$newFilename}";

            // Copy to permanent storage
            Storage::disk('local')->copy($document->file_path, $permanentPath);

            // Update document record
            DB::table('documents')
                ->where('document_id', $documentId)
                ->update([
                    'file_path' => $permanentPath,
                    'status' => 'approved',
                    'approved_by' => $approvedBy,
                    'approved_date' => now(),
                ]);

            // Delete temp file
            Storage::disk('local')->delete($document->file_path);

            Log::info("FileUploadService: Moved document to permanent", [
                'document_id' => $documentId,
                'student_id' => $studentId,
                'from' => $document->file_path,
                'to' => $permanentPath,
            ]);

            return [
                'success' => true,
                'path' => $permanentPath,
            ];
        } catch (Exception $e) {
            Log::error('FileUploadService::moveToPermanent failed', [
                'document_id' => $documentId,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Delete document and remove file
     *
     * @param string $documentId Document ID
     * @return array ['success' => bool, 'error' => string|null]
     */
    public function deleteDocument(string $documentId): array
    {
        try {
            $document = DB::table('documents')->where('document_id', $documentId)->first();

            if (!$document) {
                return ['success' => false, 'error' => 'Document not found'];
            }

            // Delete file
            if (Storage::disk('local')->exists($document->file_path)) {
                Storage::disk('local')->delete($document->file_path);
            }

            // Delete associated OCR files
            Storage::disk('local')->delete($document->file_path . '.ocr.txt');
            Storage::disk('local')->delete($document->file_path . '.ocr.json');

            // Delete document record
            DB::table('documents')->where('document_id', $documentId)->delete();

            Log::info("FileUploadService: Deleted document", [
                'document_id' => $documentId,
                'path' => $document->file_path,
            ]);

            return ['success' => true];
        } catch (Exception $e) {
            Log::error('FileUploadService::deleteDocument failed', [
                'document_id' => $documentId,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get student documents
     *
     * @param string $studentId Student ID
     * @param string|null $status Filter by status (temp, approved, rejected, etc.)
     * @return array Array of document records
     */
    public function getStudentDocuments(string $studentId, ?string $status = null): array
    {
        try {
            $query = DB::table('documents')->where('student_id', $studentId);

            if ($status) {
                $query->where('status', $status);
            }

            return $query->get()->toArray();
        } catch (Exception $e) {
            Log::error('FileUploadService::getStudentDocuments failed', [
                'student_id' => $studentId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Check if file can be downloaded
     *
     * @param string $documentId Document ID
     * @param string|null $studentId Optional student ID for permission check
     * @return array ['success' => bool, 'path' => string|null, 'error' => string|null]
     */
    public function getDocumentForDownload(string $documentId, ?string $studentId = null): array
    {
        try {
            $document = DB::table('documents')->where('document_id', $documentId)->first();

            if (!$document) {
                return ['success' => false, 'error' => 'Document not found'];
            }

            if ($studentId && $document->student_id !== $studentId) {
                return ['success' => false, 'error' => 'Unauthorized'];
            }

            $filePath = storage_path('app/' . $document->file_path);
            if (!file_exists($filePath)) {
                return ['success' => false, 'error' => 'File not found on disk'];
            }

            return [
                'success' => true,
                'path' => $filePath,
                'filename' => $document->original_filename,
            ];
        } catch (Exception $e) {
            Log::error('FileUploadService::getDocumentForDownload failed', [
                'document_id' => $documentId,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Cleanup old temporary files (older than specified days)
     *
     * @param int $olderThanDays Age in days
     * @return array ['success' => bool, 'deleted_count' => int]
     */
    public function cleanupOldTempFiles(int $olderThanDays = 7): array
    {
        try {
            $cutoffDate = now()->subDays($olderThanDays);

            $deleted = DB::table('documents')
                ->where('status', 'temp')
                ->where('uploaded_at', '<', $cutoffDate)
                ->get();

            $deletedCount = 0;
            foreach ($deleted as $document) {
                if (Storage::disk('local')->exists($document->file_path)) {
                    Storage::disk('local')->delete($document->file_path);
                    $deletedCount++;
                }
                DB::table('documents')->where('document_id', $document->document_id)->delete();
            }

            Log::info("FileUploadService: Cleaned up temporary files", [
                'deleted_count' => $deletedCount,
                'cutoff_date' => $cutoffDate,
            ]);

            return ['success' => true, 'deleted_count' => $deletedCount];
        } catch (Exception $e) {
            Log::error('FileUploadService::cleanupOldTempFiles failed', [
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'deleted_count' => 0];
        }
    }
}
