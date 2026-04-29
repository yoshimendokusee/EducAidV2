<?php

namespace App\Http\Controllers;

use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    private NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Create notification
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function createNotification(Request $request): JsonResponse
    {
        $request->validate([
            'student_id' => 'nullable|string',
            'admin_id' => 'nullable|integer',
            'title' => 'required|string',
            'message' => 'required|string',
            'type' => 'sometimes|string|in:info,warning,error,success',
            'action_url' => 'nullable|string'
        ]);

        $success = $this->notificationService->createNotification(
            $request->input('student_id'),
            $request->input('admin_id'),
            $request->input('title'),
            $request->input('message'),
            $request->input('type', 'info'),
            $request->input('action_url')
        );

        return response()->json([
            'success' => $success,
            'message' => $success ? 'Notification created' : 'Failed to create notification'
        ]);
    }

    /**
     * Get unread notifications
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getUnreadNotifications(Request $request): JsonResponse
    {
        $studentId = $request->query('student_id');
        $adminId = $request->query('admin_id');

        if (!$studentId && !$adminId) {
            // Try to get from authenticated user
            $studentId = auth('web')->user()?->student_id;
            $adminId = auth('admin')->user()?->id;
        }

        $notifications = $this->notificationService->getUnreadNotifications($studentId, $adminId);

        return response()->json([
            'success' => true,
            'count' => count($notifications),
            'notifications' => $notifications
        ]);
    }

    /**
     * Mark notification as read
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function markAsRead(Request $request): JsonResponse
    {
        $request->validate([
            'notification_id' => 'required|integer'
        ]);

        $success = $this->notificationService->markAsRead($request->input('notification_id'));

        return response()->json([
            'success' => $success,
            'message' => $success ? 'Notification marked as read' : 'Failed to mark notification'
        ]);
    }

    /**
     * Mark all notifications as read
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $studentId = $request->input('student_id') ?? auth('web')->user()?->student_id;
        $adminId = $request->input('admin_id') ?? auth('admin')->user()?->id;

        $count = $this->notificationService->markAllAsRead($studentId, $adminId);

        return response()->json([
            'success' => true,
            'marked_count' => $count,
            'message' => "$count notifications marked as read"
        ]);
    }

    /**
     * Delete notification
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function deleteNotification(Request $request): JsonResponse
    {
        $request->validate([
            'notification_id' => 'required|integer'
        ]);

        $success = $this->notificationService->deleteNotification($request->input('notification_id'));

        return response()->json([
            'success' => $success,
            'message' => $success ? 'Notification deleted' : 'Failed to delete notification'
        ]);
    }

    /**
     * Get notification statistics
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getStatistics(Request $request): JsonResponse
    {
        $studentId = $request->query('student_id') ?? auth('web')->user()?->student_id;
        $adminId = $request->query('admin_id') ?? auth('admin')->user()?->id;

        $stats = $this->notificationService->getStatistics($studentId, $adminId);

        return response()->json([
            'success' => true,
            'statistics' => $stats
        ]);
    }
}
