# API Contract (Laravel <-> React) - Compatibility Phase

## Workflow module

### GET `/api/workflow/status`
Response:
```json
{
  "success": true,
  "data": {
    "list_finalized": true,
    "has_payroll_qr": true,
    "has_schedules": false,
    "can_schedule": true,
    "can_scan_qr": true,
    "can_revert_payroll": true,
    "can_manage_applicants": true,
    "can_verify_students": true,
    "can_manage_slots": true,
    "distribution_status": "active",
    "slots_open": true,
    "uploads_enabled": true,
    "can_start_distribution": false,
    "can_finalize_distribution": true
  }
}
```

### GET `/api/workflow/student-counts`
Response:
```json
{
  "success": true,
  "data": {
    "total_students": 0,
    "active_count": 0,
    "with_payroll_count": 0,
    "applicant_count": 0,
    "verified_students": 0,
    "pending_verification": 0
  }
}
```

## Compatibility rules
- Keep key names identical to legacy workflow arrays.
- Keep boolean semantics and distribution-status values unchanged.
- Keep status code `200` for normal reads and return safe defaults on internal errors, matching legacy behavior intent.

---

## Student notifications/privacy/export module

### GET `/api/student/get_notification_count.php`
Response:
```json
{ "success": true, "count": 0 }
```

### GET `/api/student/get_notification_preferences.php`
Response:
```json
{ "success": true, "preferences": { "email_enabled": true, "email_frequency": "immediate" } }
```

### POST `/api/student/save_notification_preferences.php`
Request:
```json
{ "email_frequency": "daily" }
```
Response:
```json
{ "success": true }
```

### POST `/api/student/mark_notification_read.php`
Request:
```json
{ "notification_id": 123 }
```
Response success: `{ "success": true }`
Response not found: `{ "success": false, "error": "Notification not found or already read" }` with `404`

### POST `/api/student/mark_all_notifications_read.php`
Response:
```json
{ "success": true, "count": 3, "message": "3 notification(s) marked as read" }
```

### POST `/api/student/delete_notification.php`
Request:
```json
{ "notification_id": 123 }
```
Response success: `{ "success": true }`
Response not found: `{ "success": false, "error": "Notification not found" }` with `404`

### GET `/api/student/get_privacy_settings.php`
Response: `{ "success": false, "error": "Endpoint removed" }` with `410`

### POST `/api/student/save_privacy_settings.php`
Response: `{ "success": false, "error": "Endpoint removed" }` with `410`

### Export endpoints (parity bridge)
- `POST /api/student/request_data_export.php`
- `GET /api/student/export_status.php`
- `GET /api/student/download_export.php?request_id=..&token=..`

These are currently delegated to legacy scripts to preserve synchronous export generation, token handling, and file download behavior exactly.

---

## Eligibility module

### POST `/api/eligibility/subject-check.php`
Request (JSON mode):
```json
{
  "universityKey": "cvsu",
  "subjects": [{ "name": "Math", "rawGrade": "2.25", "confidence": 95 }]
}
```

Request (file mode):
- multipart form-data with `gradeDocument` and `universityKey`

Response success:
```json
{
  "success": true,
  "eligible": true,
  "failedSubjects": [],
  "totalSubjects": 1,
  "passedSubjects": 1,
  "universityKey": "cvsu"
}
```

## Reports module

### `/api/reports/generate_report.php`
- `POST action=preview` returns JSON preview payload.
- `GET action=get_statistics` returns JSON stats payload.
- `POST action=export_pdf|export_excel` streams files.

This endpoint currently uses dedicated controller routing with legacy execution bridge to preserve CSRF behavior, audit log inserts, and stream output semantics.

---

## Admin applicants module

### GET `/api/admin/applicants/badge-count`
Response:
```json
{ "count": 0 }
```

### GET `/api/admin/applicants/details?student_id=...`
Response:
- JSON payload delegated from legacy `get_applicant_details.php`.

### POST `/api/admin/applicants/actions`
Request:
- `application/x-www-form-urlencoded` payload matching legacy `manage_applicants.php` POST forms.

Response:
- Delegated response from legacy action handler.

Compatibility note:
- Details and action endpoints are bridged intentionally because they include coupled workflow gates, CSRF consumption patterns, file-system checks, and PHPMailer side effects.
