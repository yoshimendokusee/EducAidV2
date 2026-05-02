<?php

namespace App\Http\Controllers;

use App\Services\CompatScriptRunner;
use App\Services\StudentNotificationService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class StudentApiController extends Controller
{
    public function __construct(
        private readonly StudentNotificationService $notifications,
        private readonly CompatScriptRunner $runner
    ) {
    }

    private function getStudentIdFromSession(): ?int
    {
        $id = $_SESSION['student_id'] ?? null;
        return $id ? (int) $id : null;
    }

    // Old source: api/student/get_notification_count.php
    public function getNotificationCount(): Response
    {
        $studentId = $this->getStudentIdFromSession();
        if (!$studentId) {
            return response()->json(['success' => false, 'error' => 'Unauthorized'], 401);
        }

        return response()->json([
            'success' => true,
            'count' => $this->notifications->getNotificationCount($studentId),
        ]);
    }

    // New: Get list of notifications
    public function getNotificationList(): Response
    {
        $studentId = $this->getStudentIdFromSession();
        if (!$studentId) {
            return response()->json(['success' => false, 'error' => 'Unauthorized'], 401);
        }

        $notifications = $this->notifications->getNotificationsList($studentId);
        return response()->json([
            'success' => true,
            'notifications' => $notifications,
        ]);
    }

    // Old source: api/student/get_notification_preferences.php
    public function getNotificationPreferences(): Response
    {
        $studentId = $this->getStudentIdFromSession();
        if (!$studentId) {
            return response()->json(['success' => false, 'error' => 'Unauthorized'], 401);
        }

        $preferences = $this->notifications->getNotificationPreferences($studentId);
        if ($preferences === null) {
            return response()->json(['success' => false, 'error' => 'Preferences not supported']);
        }

        return response()->json(['success' => true, 'preferences' => $preferences]);
    }

    // Old source: api/student/save_notification_preferences.php
    public function saveNotificationPreferences(Request $request): Response
    {
        $studentId = $this->getStudentIdFromSession();
        if (!$studentId) {
            return response()->json(['success' => false, 'error' => 'Unauthorized'], 401);
        }

        $payload = $request->json()->all();
        if (empty($payload)) {
            return response()->json(['success' => false, 'error' => 'Invalid payload']);
        }

        $ok = $this->notifications->saveNotificationPreferences($studentId, $payload);
        return response()->json(['success' => $ok]);
    }

    // Old source: api/student/mark_notification_read.php
    public function markNotificationRead(Request $request): Response
    {
        $studentId = $this->getStudentIdFromSession();
        if (!$studentId) {
            return response()->json(['success' => false, 'error' => 'Unauthorized'], 401);
        }

        $notificationId = (int) ($request->json('notification_id') ?? 0);
        if ($notificationId <= 0) {
            return response()->json(['success' => false, 'error' => 'Notification ID required'], 400);
        }

        if ($this->notifications->markNotificationRead($studentId, $notificationId)) {
            return response()->json(['success' => true]);
        }

        return response()->json(['success' => false, 'error' => 'Notification not found or already read'], 404);
    }

    // Old source: api/student/mark_all_notifications_read.php
    public function markAllNotificationsRead(): Response
    {
        $studentId = $this->getStudentIdFromSession();
        if (!$studentId) {
            return response()->json(['success' => false, 'error' => 'Unauthorized'], 401);
        }

        $count = $this->notifications->markAllNotificationsRead($studentId);
        return response()->json([
            'success' => true,
            'count' => $count,
            'message' => $count . ' notification(s) marked as read',
        ]);
    }

    // Old source: api/student/delete_notification.php
    public function deleteNotification(Request $request): Response
    {
        $studentId = $this->getStudentIdFromSession();
        if (!$studentId) {
            return response()->json(['success' => false, 'error' => 'Unauthorized'], 401);
        }

        $notificationId = (int) ($request->json('notification_id') ?? 0);
        if ($notificationId <= 0) {
            return response()->json(['success' => false, 'error' => 'Notification ID required'], 400);
        }

        if ($this->notifications->deleteNotification($studentId, $notificationId)) {
            return response()->json(['success' => true]);
        }

        return response()->json(['success' => false, 'error' => 'Notification not found'], 404);
    }

    // Old source behavior: endpoints removed
    public function getPrivacySettings(): Response
    {
        return response()->json(['success' => false, 'error' => 'Endpoint removed'], 410);
    }

    // Old source behavior: endpoints removed
    public function savePrivacySettings(): Response
    {
        return response()->json(['success' => false, 'error' => 'Endpoint removed'], 410);
    }

    // Export endpoints are delegated to legacy scripts to preserve token/file side effects exactly.
    public function requestDataExport(Request $request): Response
    {
        return $this->runner->run($request, 'api/student/request_data_export.php');
    }

    public function exportStatus(Request $request): Response
    {
        return $this->runner->run($request, 'api/student/export_status.php');
    }

    public function downloadExport(Request $request): Response
    {
        return $this->runner->run($request, 'api/student/download_export.php');
    }
}
