/**
 * Unified API Client for EducAid React Frontend
 * Handles all communication with Laravel backend API
 */

const API_BASE = '/api';

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

// ============ WORKFLOW ENDPOINTS ============
export const workflowApi = {
  getStatus() {
    return jsonRequest(`${API_BASE}/workflow/status`);
  },
  getStudentCounts() {
    return jsonRequest(`${API_BASE}/workflow/student-counts`);
  },
};

// ============ STUDENT ENDPOINTS ============
export const studentApi = {
  getNotificationCount() {
    return jsonRequest(`${API_BASE}/student/get_notification_count.php`);
  },
  getNotificationPreferences() {
    return jsonRequest(`${API_BASE}/student/get_notification_preferences.php`);
  },
  saveNotificationPreferences(payload) {
    return jsonRequest(`${API_BASE}/student/save_notification_preferences.php`, {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  },
  markNotificationRead(notificationId) {
    return jsonRequest(`${API_BASE}/student/mark_notification_read.php`, {
      method: 'POST',
      body: JSON.stringify({ notification_id: notificationId }),
    });
  },
  markAllNotificationsRead() {
    return jsonRequest(`${API_BASE}/student/mark_all_notifications_read.php`, {
      method: 'POST',
      body: JSON.stringify({}),
    });
  },
  deleteNotification(notificationId) {
    return jsonRequest(`${API_BASE}/student/delete_notification.php`, {
      method: 'POST',
      body: JSON.stringify({ notification_id: notificationId }),
    });
  },
  getPrivacySettings() {
    return jsonRequest(`${API_BASE}/student/get_privacy_settings.php`);
  },
  savePrivacySettings(payload) {
    return jsonRequest(`${API_BASE}/student/save_privacy_settings.php`, {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  },
  requestDataExport() {
    return jsonRequest(`${API_BASE}/student/request_data_export.php`, {
      method: 'POST',
      body: JSON.stringify({}),
    });
  },
  getExportStatus() {
    return jsonRequest(`${API_BASE}/student/export_status.php`);
  },
  downloadExport() {
    // Returns a download link
    return jsonRequest(`${API_BASE}/student/download_export.php`);
  },
};

// ============ DOCUMENT ENDPOINTS ============
export const documentApi = {
  getStudentDocuments(studentId) {
    return jsonRequest(`${API_BASE}/documents/student-documents?student_id=${encodeURIComponent(studentId)}`);
  },
  moveToPermStorage(studentId) {
    return jsonRequest(`${API_BASE}/documents/move-to-perm-storage`, {
      method: 'POST',
      body: JSON.stringify({ student_id: studentId }),
    });
  },
  archiveDocuments(payload) {
    return jsonRequest(`${API_BASE}/documents/archive`, {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  },
  deleteDocuments(payload) {
    return jsonRequest(`${API_BASE}/documents/delete`, {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  },
  processGradeOcr(payload) {
    return jsonRequest(`${API_BASE}/documents/process-grade-ocr`, {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  },
  exportZip(studentId) {
    return jsonRequest(`${API_BASE}/documents/export-zip?student_id=${encodeURIComponent(studentId)}`);
  },
  getReuploadStatus(studentId) {
    return jsonRequest(`${API_BASE}/documents/reupload-status?student_id=${encodeURIComponent(studentId)}`);
  },
  reuploadDocument(payload) {
    return jsonRequest(`${API_BASE}/documents/reupload`, {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  },
  completeReupload(payload) {
    return jsonRequest(`${API_BASE}/documents/complete-reupload`, {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  },
};

// ============ ADMIN ENDPOINTS ============
export const adminApi = {
  getApplicantBadgeCount() {
    return jsonRequest(`${API_BASE}/admin/applicants/badge-count`);
  },
  getApplicantDetails(filters = {}) {
    const query = new URLSearchParams(filters).toString();
    return jsonRequest(`${API_BASE}/admin/applicants/details?${query}`);
  },
  performApplicantAction(payload) {
    return jsonRequest(`${API_BASE}/admin/applicants/actions`, {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  },
  saveLoginContent(payload) {
    return jsonRequest(`${API_BASE}/admin/login-content/save`, {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  },
  toggleLoginContentSection(payload) {
    return jsonRequest(`${API_BASE}/admin/login-content/toggle-section`, {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  },
};

// ============ FILE COMPRESSION ENDPOINTS ============
export const compressionApi = {
  compressDistribution(payload) {
    return jsonRequest(`${API_BASE}/compression/compress-distribution`, {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  },
  decompressDistribution(payload) {
    return jsonRequest(`${API_BASE}/compression/decompress-distribution`, {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  },
  cleanupArchives() {
    return jsonRequest(`${API_BASE}/compression/cleanup-archives`, {
      method: 'POST',
      body: JSON.stringify({}),
    });
  },
};

// ============ DISTRIBUTION ENDPOINTS ============
export const distributionApi = {
  endDistribution(payload) {
    return jsonRequest(`${API_BASE}/distributions/end-distribution`, {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  },
  getDistributionStats() {
    return jsonRequest(`${API_BASE}/distributions/stats`);
  },
};

// ============ NOTIFICATION ENDPOINTS ============
export const notificationApi = {
  createNotification(payload) {
    return jsonRequest(`${API_BASE}/notifications/create`, {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  },
  getUnreadNotifications() {
    return jsonRequest(`${API_BASE}/notifications/unread`);
  },
  markAsRead(notificationId) {
    return jsonRequest(`${API_BASE}/notifications/mark-read`, {
      method: 'POST',
      body: JSON.stringify({ notification_id: notificationId }),
    });
  },
};

// ============ ENROLLMENT/OCR ENDPOINTS ============
export const enrollmentApi = {
  processOcr(payload) {
    return jsonRequest(`${API_BASE}/enrollment-ocr/process`, {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  },
};

// ============ REPORT ENDPOINTS ============
export const reportApi = {
  generateReport(payload) {
    return jsonRequest(`${API_BASE}/reports/generate_report.php`, {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  },
};

// ============ ELIGIBILITY ENDPOINTS ============
export const eligibilityApi = {
  checkSubject(payload) {
    return jsonRequest(`${API_BASE}/eligibility/subject-check.php`, {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  },
};

export default {
  workflowApi,
  studentApi,
  documentApi,
  adminApi,
  compressionApi,
  distributionApi,
  notificationApi,
  enrollmentApi,
  reportApi,
  eligibilityApi,
};
