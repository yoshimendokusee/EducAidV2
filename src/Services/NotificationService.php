<?php

namespace App\Services;

require_once __DIR__ . '/ApiClient.php';

class NotificationService
{
    private ApiClient $client;

    public function __construct(string $apiBase = null)
    {
        $this->client = new ApiClient($apiBase);
    }

    public function createNotification($studentId, $adminId, $title, $message, $type = 'info', $actionUrl = null)
    {
        return $this->client->post('notifications/create', [
            'student_id' => $studentId,
            'admin_id' => $adminId,
            'title' => $title,
            'message' => $message,
            'type' => $type,
            'action_url' => $actionUrl
        ]);
    }

    public function getUnreadNotifications($studentId = null, $adminId = null)
    {
        return $this->client->get('notifications/unread', array_filter(['student_id' => $studentId, 'admin_id' => $adminId]));
    }

    public function markAsRead(int $notificationId)
    {
        return $this->client->post('notifications/mark-read', ['notification_id' => $notificationId]);
    }

    public function markAllAsRead($studentId = null, $adminId = null)
    {
        return $this->client->post('notifications/mark-all-read', ['student_id' => $studentId, 'admin_id' => $adminId]);
    }

    public function deleteNotification(int $notificationId)
    {
        return $this->client->post('notifications/delete', ['notification_id' => $notificationId]);
    }

    public function getStatistics($studentId = null, $adminId = null)
    {
        return $this->client->get('notifications/stats', array_filter(['student_id' => $studentId, 'admin_id' => $adminId]));
    }

    // Legacy-compatible adapters used by old codebase
    public function sendVisualChangeNotification(array $changes, array $adminInfo)
    {
        $title = 'Visual Settings Changed';
        $message = implode("\n", array_map(fn($c) => ($c['label'] ?? $c['field']) . ': ' . ($c['old_value'] ?? '') . ' → ' . ($c['new_value'] ?? ''), $changes));
        // Create a bell notification and an email-like notification via API
        $this->createNotification(null, $adminInfo['admin_id'] ?? null, $title, $message, 'info', null);
        return ['success' => true];
    }

    public function createBellNotification(array $changes, array $adminInfo)
    {
        $title = 'Visual Settings Changed';
        $message = implode("; ", array_map(fn($c) => ($c['label'] ?? $c['field']) . ' changed', $changes));
        return $this->createNotification(null, $adminInfo['admin_id'] ?? null, $title, $message, 'info', null);
    }
}
