async function jsonRequest(url, options = {}) {
  const response = await fetch(url, {
    credentials: 'include',
    headers: {
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      ...(options.headers || {}),
    },
    ...options,
  });

  const data = await response.json().catch(() => ({}));
  return { ok: response.ok, status: response.status, data };
}

export const studentApi = {
  getNotificationCount() {
    return jsonRequest('/api/student/get_notification_count.php');
  },
  getNotificationPreferences() {
    return jsonRequest('/api/student/get_notification_preferences.php');
  },
  saveNotificationPreferences(payload) {
    return jsonRequest('/api/student/save_notification_preferences.php', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  },
  markNotificationRead(notificationId) {
    return jsonRequest('/api/student/mark_notification_read.php', {
      method: 'POST',
      body: JSON.stringify({ notification_id: notificationId }),
    });
  },
  markAllNotificationsRead() {
    return jsonRequest('/api/student/mark_all_notifications_read.php', {
      method: 'POST',
      body: JSON.stringify({}),
    });
  },
  deleteNotification(notificationId) {
    return jsonRequest('/api/student/delete_notification.php', {
      method: 'POST',
      body: JSON.stringify({ notification_id: notificationId }),
    });
  },
  getPrivacySettings() {
    return jsonRequest('/api/student/get_privacy_settings.php');
  },
  savePrivacySettings(payload) {
    return jsonRequest('/api/student/save_privacy_settings.php', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  },
  requestDataExport() {
    return jsonRequest('/api/student/request_data_export.php', {
      method: 'POST',
      body: JSON.stringify({}),
    });
  },
  getExportStatus() {
    return jsonRequest('/api/student/export_status.php');
  },
};
