# Module-by-Module Migration Plan (Behavior Preserving)

## Analyzed standalone modules (high impact)

1. Authentication and session
- Source files: `unified_login.php`, `modules/admin/index.php`, `modules/student/student_login.php`, `modules/*/logout.php`, `includes/SessionTimeoutMiddleware.php`
- Migration target: Laravel auth/session bridge first, then native guards later.

2. Workflow control and access gating
- Source file: `includes/workflow_control.php`
- Used by: admin sidebar/pages and student sidebar/homepage.
- Migration target: `app/Services/WorkflowControlService.php`, `app/Http/Controllers/WorkflowController.php`.

3. Student documents + OCR + notification mail
- Source files: `modules/student/upload_document.php`, `services/EnrollmentFormOCRService.php`, `services/OCRProcessingService.php`
- Migration target: legacy execution compatibility first, split into API endpoints next.

4. Applicant verification and distribution
- Source files: `modules/admin/manage_applicants.php`, `modules/admin/distribution_control.php`, `modules/admin/verify_students.php`
- Migration target: API-first extraction while preserving SQL decisions.
- Status: in progress
	- Native migrated: applicant badge count API.
	- Bridged via dedicated controller: applicant details + applicant POST actions (approve/reject/archive/bulk/migration) to preserve behavior and email side effects.

5. Eligibility and report APIs
- Source files: `api/eligibility/subject-check.php`, `api/reports/generate_report.php`
- Status: in progress
	- Eligibility endpoint migrated into dedicated controller/service with legacy OCR + grade service loading.
	- Reports endpoint migrated into dedicated controller with legacy bridge to preserve streaming exports and audit side effects.

5. Student notifications/privacy/export API
- Source files: `api/student/*.php`
- Migration target: Laravel API routes mapped one-to-one with same response envelopes.
- Status: in progress
	- Native migrated: notification count/preferences/read/delete + privacy removed-endpoint behavior.
	- Delegated for parity: export request/status/download (legacy script runner bridge).

## Migration order and checkpoints

1. Phase 1 (completed in scaffold)
- Legacy route bridge and PHP script runner.

2. Phase 2 (started in this update)
- Native Laravel workflow module with same SQL and flags.
- React gate helper consuming `/api/workflow/status`.

3. Phase 3
- Native API extraction for student notification/privacy/export endpoints.
- Keep old payload keys and status codes.
- Current checkpoint completed:
	- `get_notification_count.php`
	- `get_notification_preferences.php`
	- `save_notification_preferences.php`
	- `mark_notification_read.php`
	- `mark_all_notifications_read.php`
	- `delete_notification.php`
	- `get_privacy_settings.php` (410 removed)
	- `save_privacy_settings.php` (410 removed)

4. Phase 4
- Upload/OCR/mail flow extraction module-by-module.
- Keep same file naming, validation thresholds, and message text.

5. Phase 5
- Convert dashboard/management pages to React composition while rendering legacy HTML until each page is fully parity-tested.

## Risk-prone files requiring special handling

- `modules/student/upload_document.php` (OCR + file upload + mail + workflow checks)
- `unified_login.php` (dual-role login, OTP, recaptcha)
- `modules/admin/manage_applicants.php` (bulk actions + mail + migration helpers)
- `modules/admin/distribution_control.php` (distribution state machine)

## Testing checklist per migrated module

- SQL parity: compare result sets for old and new endpoints.
- Permission parity: same allow/deny behavior for admin/student roles.
- Error parity: same validation and error messages.
- Side effects: same DB updates and file outputs.
- UI parity: same labels/alerts/disabled states.
