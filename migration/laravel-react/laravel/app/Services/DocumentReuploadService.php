<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class DocumentReuploadService
{
    const DOCUMENT_TYPES = [
        '04' => ['name' => 'id_picture', 'folder' => 'id_pictures'],
        '00' => ['name' => 'eaf', 'folder' => 'enrollment_forms'],
        '01' => ['name' => 'academic_grades', 'folder' => 'grades'],
        '02' => ['name' => 'letter_to_mayor', 'folder' => 'letter_to_mayor'],
        '03' => ['name' => 'certificate_of_indigency', 'folder' => 'indigency']
    ];

    private OcrProcessingService $ocrService;

    public function __construct(OcrProcessingService $ocrService)
    {
        $this->ocrService = $ocrService;
    }

    /**
     * Handle document re-upload for rejected applicants
     * Uploads directly to permanent storage (skips temp folder)
     *
     * @param string $studentId
     * @param string $documentTypeCode
     * @param string $filePath
     * @param int|null $adminId
     * @return array ['success' => bool, 'document_id' => int|null, 'error' => string|null]
     */
    public function reuploadDocument(string $studentId, string $documentTypeCode, string $filePath, ?int $adminId = null): array
    {
        try {
            Log::info("DocumentReuploadService::reuploadDocument - START for student: $studentId, type: $documentTypeCode");

            if (!file_exists($filePath)) {
                return [
                    'success' => false,
                    'document_id' => null,
                    'error' => 'Source file not found'
                ];
            }

            // Get document type info
            if (!isset(self::DOCUMENT_TYPES[$documentTypeCode])) {
                return [
                    'success' => false,
                    'document_id' => null,
                    'error' => 'Invalid document type code: ' . $documentTypeCode
                ];
            }

            $docTypeInfo = self::DOCUMENT_TYPES[$documentTypeCode];

            // Create permanent storage path
            $destFolder = storage_path("app/students/$studentId/{$docTypeInfo['folder']}");
            if (!file_exists($destFolder)) {
                mkdir($destFolder, 0755, true);
            }

            $fileName = date('YmdHis') . '_' . basename($filePath);
            $destPath = "$destFolder/$fileName";

            // Copy file to permanent storage
            if (!copy($filePath, $destPath)) {
                return [
                    'success' => false,
                    'document_id' => null,
                    'error' => 'Failed to copy file to permanent storage'
                ];
            }

            // Process OCR for grades
            $ocrData = null;
            if ($documentTypeCode === '01') {  // academic_grades
                $ocrResult = $this->ocrService->processGradeDocument($destPath);
                if ($ocrResult['success']) {
                    $ocrData = json_encode($ocrResult['subjects'] ?? []);
                }
            }

            // Store in database
            $documentId = DB::table('documents')->insertGetId([
                'student_id' => $studentId,
                'document_type_code' => $documentTypeCode,
                'file_path' => $destPath,
                'status' => 'permanent',
                'approved_by' => $adminId,
                'approved_at' => now(),
                'ocr_data' => $ocrData,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            Log::info("DocumentReuploadService::reuploadDocument - SUCCESS for student: $studentId, document_id: $documentId");

            return [
                'success' => true,
                'document_id' => $documentId,
                'error' => null
            ];
        } catch (Exception $e) {
            Log::error("DocumentReuploadService::reuploadDocument - Error: {$e->getMessage()}");

            return [
                'success' => false,
                'document_id' => null,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get re-upload status for an applicant
     *
     * @param string $studentId
     * @return array
     */
    public function getReuploadStatus(string $studentId): array
    {
        $student = DB::table('students')->where('student_id', $studentId)->first();

        if (!$student) {
            return ['status' => 'not_found'];
        }

        // Check if student is in rejected/reupload status
        if ($student->status !== 'applicant') {
            return [
                'status' => 'not_applicable',
                'current_status' => $student->status
            ];
        }

        // Get missing documents
        $allDocTypes = array_keys(self::DOCUMENT_TYPES);
        $uploadedDocTypes = DB::table('documents')
            ->where('student_id', $studentId)
            ->whereIn('document_type_code', $allDocTypes)
            ->pluck('document_type_code')
            ->toArray();

        $missingDocTypes = array_diff($allDocTypes, $uploadedDocTypes);

        return [
            'status' => 'eligible_for_reupload',
            'student_status' => $student->status,
            'uploaded_documents' => array_map(fn($code) => self::DOCUMENT_TYPES[$code], $uploadedDocTypes),
            'missing_documents' => array_map(fn($code) => self::DOCUMENT_TYPES[$code], $missingDocTypes),
            'last_rejection_reason' => $student->rejection_reason ?? null,
            'rejected_at' => $student->rejected_at ?? null
        ];
    }

    /**
     * Complete re-upload workflow (mark as complete for re-processing)
     *
     * @param string $studentId
     * @return array ['success' => bool, 'message' => string]
     */
    public function completeReupload(string $studentId): array
    {
        try {
            DB::beginTransaction();

            // Verify all required documents are present
            $requiredTypes = array_keys(self::DOCUMENT_TYPES);
            $uploadedCount = DB::table('documents')
                ->where('student_id', $studentId)
                ->whereIn('document_type_code', $requiredTypes)
                ->count();

            if ($uploadedCount < count($requiredTypes)) {
                DB::rollBack();
                return [
                    'success' => false,
                    'message' => 'Not all required documents have been uploaded'
                ];
            }

            // Mark re-upload as complete
            DB::table('students')
                ->where('student_id', $studentId)
                ->update([
                    'reupload_completed_at' => now(),
                    'ready_for_reprocessing' => true
                ]);

            DB::commit();

            Log::info("DocumentReuploadService::completeReupload - Completed for student: $studentId");

            return [
                'success' => true,
                'message' => 'Re-upload completed. Your application will be processed shortly.'
            ];
        } catch (Exception $e) {
            DB::rollBack();
            Log::error("DocumentReuploadService::completeReupload - Error: {$e->getMessage()}");

            return [
                'success' => false,
                'message' => 'Failed to complete re-upload: ' . $e->getMessage()
            ];
        }
    }
}
