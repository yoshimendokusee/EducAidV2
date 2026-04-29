<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class NotificationService
{
    const TYPE_INFO = 'info';
    const TYPE_WARNING = 'warning';
    const TYPE_ERROR = 'error';
    const TYPE_SUCCESS = 'success';

    /**
     * Create notification for student or admin
     *
     * @param string|null $studentId
     * @param int|null $adminId
     * @param string $title
     * @param string $message
     * @param string $type
     * @param string|null $actionUrl
     * @param array|null $actionData
     * @return bool
     */
    public function createNotification(
        ?string $studentId,
        ?int $adminId,
        string $title,
        string $message,
        string $type = self::TYPE_INFO,
        ?string $actionUrl = null,
        ?array $actionData = null
    ): bool {
        try {
            DB::table('notifications')->insert([
                'student_id' => $studentId,
                'admin_id' => $adminId,
                'title' => $title,
                'message' => $message,
                'type' => $type,
                'action_url' => $actionUrl,
                'action_data' => $actionData ? json_encode($actionData) : null,
                'is_read' => false,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            Log::info("NotificationService::createNotification - Created for student=$studentId, admin=$adminId");

            return true;
        } catch (Exception $e) {
            Log::error("NotificationService::createNotification - Error: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Get unread notifications for user
     *
     * @param string|null $studentId
     * @param int|null $adminId
     * @return array
     */
    public function getUnreadNotifications(?string $studentId, ?int $adminId): array
    {
        try {
            $query = DB::table('notifications')->where('is_read', false);

            if ($studentId) {
                $query->where('student_id', $studentId);
            } else {
                $query->where('admin_id', $adminId);
            }

            return $query->orderBy('created_at', 'desc')->get()->toArray();
        } catch (Exception $e) {
            Log::error("NotificationService::getUnreadNotifications - Error: {$e->getMessage()}");

            return [];
        }
    }

    /**
     * Mark notification as read
     *
     * @param int $notificationId
     * @return bool
     */
    public function markAsRead(int $notificationId): bool
    {
        try {
            DB::table('notifications')
                ->where('notification_id', $notificationId)
                ->update(['is_read' => true, 'read_at' => now()]);

            return true;
        } catch (Exception $e) {
            Log::error("NotificationService::markAsRead - Error: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Mark all notifications as read for user
     *
     * @param string|null $studentId
     * @param int|null $adminId
     * @return int (count of updated records)
     */
    public function markAllAsRead(?string $studentId, ?int $adminId): int
    {
        try {
            $query = DB::table('notifications')->where('is_read', false);

            if ($studentId) {
                $query->where('student_id', $studentId);
            } else {
                $query->where('admin_id', $adminId);
            }

            return $query->update(['is_read' => true, 'read_at' => now()]);
        } catch (Exception $e) {
            Log::error("NotificationService::markAllAsRead - Error: {$e->getMessage()}");

            return 0;
        }
    }

    /**
     * Delete notification
     *
     * @param int $notificationId
     * @return bool
     */
    public function deleteNotification(int $notificationId): bool
    {
        try {
            DB::table('notifications')
                ->where('notification_id', $notificationId)
                ->delete();

            return true;
        } catch (Exception $e) {
            Log::error("NotificationService::deleteNotification - Error: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Get notification statistics
     *
     * @param string|null $studentId
     * @param int|null $adminId
     * @return array
     */
    public function getStatistics(?string $studentId, ?int $adminId): array
    {
        try {
            $query = DB::table('notifications');

            if ($studentId) {
                $query->where('student_id', $studentId);
            } else {
                $query->where('admin_id', $adminId);
            }

            $total = $query->count();
            $unread = (clone $query)->where('is_read', false)->count();

            $byType = (clone $query)
                ->select('type', DB::raw('COUNT(*) as count'))
                ->groupBy('type')
                ->pluck('count', 'type')
                ->toArray();

            return [
                'total' => $total,
                'unread' => $unread,
                'by_type' => $byType
            ];
        } catch (Exception $e) {
            Log::error("NotificationService::getStatistics - Error: {$e->getMessage()}");

            return [
                'total' => 0,
                'unread' => 0,
                'by_type' => []
            ];
        }
    }
}
