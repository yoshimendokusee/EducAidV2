<?php

namespace App\Http\Controllers;

use App\Services\DocumentReuploadService;
use App\Services\UnifiedFileService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentController extends Controller
{
    private UnifiedFileService $unifiedFileService;
    private DocumentReuploadService $reuploadService;

    public function __construct(
        UnifiedFileService $unifiedFileService,
        DocumentReuploadService $reuploadService
    ) {
        $this->unifiedFileService = $unifiedFileService;
        $this->reuploadService = $reuploadService;
    }

    /**
     * Upload a new document for student
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function uploadDocument(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'student_id' => 'required|string',
                'document_type' => 'required|string',
                'file_data' => 'required|string',
                'file_name' => 'required|string',
                'mime_type' => 'required|string'
            ]);

            $result = $this->unifiedFileService->uploadDocument(
                $request->input('student_id'),
                $request->input('document_type'),
                $request->input('file_data'),
                $request->input('file_name'),
                $request->input('mime_type')
            );

            return response()->json($result);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'document_id' => null,
                'message' => "Validation error: {$e->getMessage()}",
                'file_path' => null
            ], 400);
        }
    }

    /**
     * Get student documents
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getStudentDocuments(Request $request): JsonResponse
    {
        $studentId = $request->query('student_id');
        $type = $request->query('type');
        $status = $request->query('status');

        if (!$studentId) {
            return response()->json(['success' => false, 'message' => 'student_id required'], 400);
        }

        $documents = $this->unifiedFileService->getStudentDocuments($studentId, $type, $status);

        return response()->json([
            'success' => true,
            'documents' => $documents
        ]);
    }

    /**
     * Move documents to permanent storage
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function moveToPermStorage(Request $request): JsonResponse
    {
        $studentId = $request->input('student_id');
        $adminId = auth('admin')->user()?->id;

        if (!$studentId) {
            return response()->json(['success' => false, 'message' => 'student_id required'], 400);
        }

        $result = $this->unifiedFileService->moveToPermStorage($studentId, $adminId);

        return response()->json($result);
    }

    /**
     * Archive student documents
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function archiveDocuments(Request $request): JsonResponse
    {
        $studentId = $request->input('student_id');

        if (!$studentId) {
            return response()->json(['success' => false, 'message' => 'student_id required'], 400);
        }

        $result = $this->unifiedFileService->archiveStudentDocuments($studentId);

        return response()->json($result);
    }

    /**
     * Delete student documents
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function deleteDocuments(Request $request): JsonResponse
    {
        $studentId = $request->input('student_id');

        if (!$studentId) {
            return response()->json(['success' => false, 'message' => 'student_id required'], 400);
        }

        $documentIds = $request->input('document_ids');
        if (is_string($documentIds)) {
            $decoded = json_decode($documentIds, true);
            $documentIds = is_array($decoded) ? $decoded : null;
        }

        $result = $this->unifiedFileService->deleteStudentDocuments($studentId, is_array($documentIds) ? $documentIds : null);

        return response()->json($result);
    }

    /**
     * Process OCR for grade documents
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function processGradeOcr(Request $request): JsonResponse
    {
        $studentId = $request->input('student_id');
        $filePath = $request->input('file_path');

        if (!$studentId || !$filePath) {
            return response()->json(['success' => false, 'message' => 'student_id and file_path required'], 400);
        }

        $result = $this->unifiedFileService->processGradeDocumentOcr($studentId, $filePath);

        return response()->json($result);
    }

    /**
     * Export student documents as ZIP
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function exportZip(Request $request): JsonResponse
    {
        $studentId = $request->query('student_id');

        if (!$studentId) {
            return response()->json(['success' => false, 'message' => 'student_id required'], 400);
        }

        $result = $this->unifiedFileService->exportStudentDocumentsZip($studentId);

        return response()->json($result);
    }

    /**
     * Get re-upload status for student
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getReuploadStatus(Request $request): JsonResponse
    {
        $studentId = $request->query('student_id');

        if (!$studentId) {
            return response()->json(['success' => false, 'message' => 'student_id required'], 400);
        }

        $status = $this->reuploadService->getReuploadStatus($studentId);

        return response()->json([
            'success' => true,
            'status' => $status
        ]);
    }

    /**
     * Reupload document for rejected applicant
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function reuploadDocument(Request $request): JsonResponse
    {
        $request->validate([
            'student_id' => 'required|string',
            'document_type_code' => 'required|string',
            'file_path' => 'required|string'
        ]);

        $adminId = auth('admin')->user()?->id;

        $result = $this->reuploadService->reuploadDocument(
            $request->input('student_id'),
            $request->input('document_type_code'),
            $request->input('file_path'),
            $adminId
        );

        return response()->json($result);
    }

    /**
     * Complete re-upload workflow
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function completeReupload(Request $request): JsonResponse
    {
        $studentId = $request->input('student_id');

        if (!$studentId) {
            return response()->json(['success' => false, 'message' => 'student_id required'], 400);
        }

        $result = $this->reuploadService->completeReupload($studentId);

        return response()->json($result);
    }
}
