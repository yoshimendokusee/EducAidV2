<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class StudentNotificationService
{
    public function getNotificationCount(int $studentId): int
    {
        $row = (array) DB::selectOne(
            "SELECT COUNT(*) as unread_count
             FROM student_notifications
             WHERE student_id = ? AND is_read = FALSE
               AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)",
            [$studentId]
        );

        return (int) ($row['unread_count'] ?? 0);
    }

    public function getNotificationPreferences(int $studentId): ?array
    {
        $tbl = DB::selectOne(
            "SELECT 1 FROM information_schema.tables WHERE table_name = ?",
            ['student_notification_preferences']
        );

        if (!$tbl) {
            return null;
        }

        $existing = DB::selectOne(
            'SELECT * FROM student_notification_preferences WHERE student_id = ? LIMIT 1',
            [$studentId]
        );

        if (!$existing) {
            $created = DB::selectOne(
                'INSERT INTO student_notification_preferences (student_id) VALUES (?) RETURNING *',
                [$studentId]
            );
            $row = (array) $created;
        } else {
            $row = (array) $existing;
        }

        $boolFields = [
            'email_enabled', 'email_announcement', 'email_document', 'email_schedule', 'email_warning',
            'email_error', 'email_success', 'email_system', 'email_info',
        ];

        foreach ($boolFields as $field) {
            if (array_key_exists($field, $row)) {
                $row[$field] = ($row[$field] === true || $row[$field] === 't' || $row[$field] === 'true' || $row[$field] === 1 || $row[$field] === '1');
            }
        }

        return $row;
    }

    public function saveNotificationPreferences(int $studentId, array $input): bool
    {
        $emailFrequency = (($input['email_frequency'] ?? 'immediate') === 'daily') ? 'daily' : 'immediate';

        $exists = DB::selectOne('SELECT 1 FROM student_notification_preferences WHERE student_id = ? LIMIT 1', [$studentId]);
        if (!$exists) {
            DB::insert('INSERT INTO student_notification_preferences (student_id) VALUES (?)', [$studentId]);
        }

        return DB::update(
            "UPDATE student_notification_preferences
             SET email_enabled = true,
                 email_frequency = ?,
                 email_announcement = true,
                 email_document = true,
                 email_schedule = true,
                 email_warning = true,
                 email_error = true,
                 email_success = true,
                 email_system = true,
                 email_info = true
             WHERE student_id = ?",
            [$emailFrequency, $studentId]
        ) >= 0;
    }

    public function markNotificationRead(int $studentId, int $notificationId): bool
    {
        $updated = DB::update(
            'UPDATE student_notifications SET is_read = TRUE WHERE notification_id = ? AND student_id = ?',
            [$notificationId, $studentId]
        );

        return $updated > 0;
    }

    public function markAllNotificationsRead(int $studentId): int
    {
        return DB::update(
            "UPDATE student_notifications
             SET is_read = TRUE
             WHERE student_id = ? AND is_read = FALSE
               AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)",
            [$studentId]
        );
    }

    public function deleteNotification(int $studentId, int $notificationId): bool
    {
        $deleted = DB::delete(
            'DELETE FROM student_notifications WHERE notification_id = ? AND student_id = ?',
            [$notificationId, $studentId]
        );

        return $deleted > 0;
    }

    public function getNotificationsList(int $studentId, int $limit = 50): array
    {
        $results = DB::select(
            "SELECT * FROM student_notifications
             WHERE student_id = ?
             ORDER BY created_at DESC
             LIMIT ?",
            [$studentId, $limit]
        );

        return array_map(function ($row) {
            return (array) $row;
        }, $results);
    }
}
