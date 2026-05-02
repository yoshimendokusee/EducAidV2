<?php

namespace App\Http\Controllers;

use App\Services\StudentEmailNotificationService;
use App\Services\DistributionEmailService;
use App\Services\AnnouncementEmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * EmailController
 * Handles all email notification endpoints
 * Routes handled:
 * - POST /api/email/send-student-approval
 * - POST /api/email/send-student-rejection
 * - POST /api/email/send-distribution-notification
 * - POST /api/email/send-document-update
 * - POST /api/email/notify-distribution-opened
 * - POST /api/email/notify-distribution-closed
 * - POST /api/email/send-announcement (admin)
 */
class EmailController extends Controller
{
    private StudentEmailNotificationService $studentEmailService;
    private DistributionEmailService $distributionEmailService;
    private AnnouncementEmailService $announcementEmailService;

    public function __construct(
        StudentEmailNotificationService $studentEmailService,
        DistributionEmailService $distributionEmailService,
        AnnouncementEmailService $announcementEmailService
    ) {
        $this->studentEmailService = $studentEmailService;
        $this->distributionEmailService = $distributionEmailService;
        $this->announcementEmailService = $announcementEmailService;
    }

    /**
     * Send approval email to student
     * POST /api/email/send-student-approval
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function sendStudentApprovalEmail(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'student_id' => 'required|string'
            ]);

            $success = $this->studentEmailService->sendApprovalEmail($validated['student_id']);

            Log::info('EmailController::sendStudentApprovalEmail', [
                'student_id' => $validated['student_id'],
                'status' => $success ? 'sent' : 'failed'
            ]);

            return response()->json([
                'ok' => $success,
                'message' => $success ? 'Approval email sent successfully' : 'Failed to send approval email'
            ]);
        } catch (Exception $e) {
            Log::error('EmailController::sendStudentApprovalEmail - Error', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'Error sending approval email: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Send rejection email to student
     * POST /api/email/send-student-rejection
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function sendStudentRejectionEmail(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'student_id' => 'required|string',
                'reason' => 'nullable|string'
            ]);

            $success = $this->studentEmailService->sendRejectionEmail(
                $validated['student_id'],
                $validated['reason'] ?? null
            );

            Log::info('EmailController::sendStudentRejectionEmail', [
                'student_id' => $validated['student_id'],
                'status' => $success ? 'sent' : 'failed'
            ]);

            return response()->json([
                'ok' => $success,
                'message' => $success ? 'Rejection email sent successfully' : 'Failed to send rejection email'
            ]);
        } catch (Exception $e) {
            Log::error('EmailController::sendStudentRejectionEmail - Error', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'Error sending rejection email: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Send distribution notification email to student
     * POST /api/email/send-distribution-notification
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function sendDistributionNotificationEmail(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'student_id' => 'required|string',
                'distribution_name' => 'required|string',
                'amount' => 'nullable|numeric'
            ]);

            $success = $this->studentEmailService->sendDistributionNotificationEmail(
                $validated['student_id'],
                $validated['distribution_name'],
                $validated['amount'] ?? null
            );

            Log::info('EmailController::sendDistributionNotificationEmail', [
                'student_id' => $validated['student_id'],
                'distribution' => $validated['distribution_name'],
                'status' => $success ? 'sent' : 'failed'
            ]);

            return response()->json([
                'ok' => $success,
                'message' => $success ? 'Distribution notification sent successfully' : 'Failed to send distribution notification'
            ]);
        } catch (Exception $e) {
            Log::error('EmailController::sendDistributionNotificationEmail - Error', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'Error sending distribution notification: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Send document processing update email to student
     * POST /api/email/send-document-update
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function sendDocumentUpdateEmail(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'student_id' => 'required|string',
                'document_type' => 'required|string',
                'status' => 'required|in:submitted,verified,rejected,processed'
            ]);

            $success = $this->studentEmailService->sendDocumentProcessingUpdate(
                $validated['student_id'],
                $validated['document_type'],
                $validated['status']
            );

            Log::info('EmailController::sendDocumentUpdateEmail', [
                'student_id' => $validated['student_id'],
                'document_type' => $validated['document_type'],
                'status' => $validated['status']
            ]);

            return response()->json([
                'ok' => $success,
                'message' => $success ? 'Document update email sent successfully' : 'Failed to send document update email'
            ]);
        } catch (Exception $e) {
            Log::error('EmailController::sendDocumentUpdateEmail - Error', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'Error sending document update email: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Notify all students when distribution opens
     * POST /api/email/notify-distribution-opened
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function notifyDistributionOpened(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'distribution_id' => 'required|integer',
                'deadline' => 'required|string'
            ]);

            $result = $this->distributionEmailService->notifyDistributionOpened(
                $validated['distribution_id'],
                $validated['deadline']
            );

            Log::info('EmailController::notifyDistributionOpened', [
                'distribution_id' => $validated['distribution_id'],
                'sent_count' => $result['sent_count'],
                'failed_count' => $result['failed_count']
            ]);

            return response()->json([
                'ok' => $result['success'],
                'sent_count' => $result['sent_count'],
                'failed_count' => $result['failed_count'],
                'message' => $result['success'] ? "Distribution opened notification sent to {$result['sent_count']} students" : 'Failed to send distribution notifications'
            ]);
        } catch (Exception $e) {
            Log::error('EmailController::notifyDistributionOpened - Error', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'Error sending distribution notifications: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Notify students when distribution closes
     * POST /api/email/notify-distribution-closed
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function notifyDistributionClosed(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'distribution_id' => 'required|integer'
            ]);

            $result = $this->distributionEmailService->notifyDistributionClosed($validated['distribution_id']);

            Log::info('EmailController::notifyDistributionClosed', [
                'distribution_id' => $validated['distribution_id'],
                'sent_count' => $result['sent_count'],
                'failed_count' => $result['failed_count']
            ]);

            return response()->json([
                'ok' => $result['success'],
                'sent_count' => $result['sent_count'],
                'failed_count' => $result['failed_count'],
                'message' => $result['success'] ? "Distribution closed notification sent to {$result['sent_count']} students" : 'Failed to send distribution closure notifications'
            ]);
        } catch (Exception $e) {
            Log::error('EmailController::notifyDistributionClosed - Error', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'Error sending distribution closure notifications: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Send announcement email (admin only)
     * POST /api/email/send-announcement
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function sendAnnouncement(Request $request): JsonResponse
    {
        try {
            // Check admin authentication
            if (empty($_SESSION['admin_username'])) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Unauthorized: Admin access required'
                ], 403);
            }

            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'message' => 'required|string',
                'recipient_type' => 'required|in:all_students,eligible_only,by_status,by_municipality',
                'recipient_status' => 'nullable|string',
                'municipality_id' => 'nullable|integer'
            ]);

            $result = $this->announcementEmailService->sendAnnouncement(
                $validated['title'],
                $validated['message'],
                $validated['recipient_type'],
                $validated['recipient_status'] ?? null,
                $validated['municipality_id'] ?? null,
                $_SESSION['admin_username']
            );

            Log::info('EmailController::sendAnnouncement', [
                'admin' => $_SESSION['admin_username'],
                'title' => $validated['title'],
                'recipient_type' => $validated['recipient_type'],
                'sent_count' => $result['sent_count'],
                'failed_count' => $result['failed_count']
            ]);

            return response()->json([
                'ok' => $result['success'],
                'sent_count' => $result['sent_count'],
                'failed_count' => $result['failed_count'],
                'message' => $result['success'] ? "Announcement sent to {$result['sent_count']} students" : 'Failed to send announcements'
            ]);
        } catch (Exception $e) {
            Log::error('EmailController::sendAnnouncement - Error', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'Error sending announcements: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get email configuration status
     * GET /api/email/config-status
     *
     * @return JsonResponse
     */
    public function getConfigStatus(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'config' => [
                'mail_driver' => config('mail.mailer'),
                'from_address' => config('mail.from.address'),
                'from_name' => config('mail.from.name'),
                'queue_enabled' => config('queue.default') !== 'sync',
                'queue_driver' => config('queue.default')
            ]
        ]);
    }
}
