# EducAid Standalone PHP -> Laravel + React Migration Blueprint (Minimal-Impact)

## Scope and non-negotiables applied
- Database schema is preserved as-is.
- Existing table names, columns, relations, and raw SQL behavior are preserved.
- Existing business logic is preserved by execution-through-legacy strategy (legacy PHP scripts are executed from Laravel controllers).
- UI/UX is preserved by server-rendered legacy HTML delivery inside React route hosts (no redesign).
- Migration is incremental: old scripts keep running while routes are moved behind Laravel.

---

## 1) Step-by-step migration plan

1. Baseline freeze and parity checks
- Freeze production schema and data contract.
- Capture current route/page matrix and golden outputs (HTML snapshots + JSON outputs).
- Capture OCR and PHPMailer behavior traces (request/response + logs).

2. Introduce Laravel as front controller (no business-logic rewrite)
- Create Laravel route adapters for legacy entry points.
- Route Laravel requests to existing PHP scripts with controlled request/session context.
- Keep existing SQL and includes untouched.

3. Introduce React route shell (no UI redesign)
- Create React page hosts that request server-rendered legacy HTML.
- Preserve existing HTML/CSS/JS output exactly.
- Keep existing script order and global JS behavior.

4. Migrate critical flows first
- Unified login/auth flow.
- Student homepage + upload flow.
- Admin homepage + applicant management.
- API/AJAX handlers currently consumed by frontend.

5. Integrations parity
- OCR wrapper calls existing OCR services/config unchanged.
- PHPMailer wrapper reuses existing SMTP/template logic unchanged.
- File upload/storage paths mapped to existing directories and naming.

6. Session/auth parity
- Preserve PHP session keys (`student_id`, `admin_id`, `admin_username`, etc.).
- Bridge Laravel session middleware to legacy session lifecycle.

7. Incremental cutover
- Move routes module-by-module from direct PHP access to Laravel routing.
- Keep fallback catch-all to legacy scripts until module is validated.

8. Verification and release
- Snapshot diff tests (HTML and JSON).
- SQL query parity checks.
- OCR and mail trigger parity tests.

---

## 2) File/module mapping (standalone -> Laravel + React)

### Core entry points
- `router.php` -> `laravel/routes/web.php` + `laravel/app/Http/Controllers/LegacyWebController.php`
- `.htaccess` rules -> Laravel route declarations + web server rewrite to `public/index.php`
- `website/index.php` -> React route `/` hosted by `react/src/pages/LegacyPageHost.jsx` with backend source `website/index.php`
- `unified_login.php` -> Laravel route `/unified_login.php` + React route `/login` host, backend source `unified_login.php`

### Authentication/session
- `unified_login.php` (student/admin login + OTP) -> `LegacyWebController@unifiedLogin`
- `modules/admin/index.php` redirect flow -> `LegacyWebController@adminIndex`
- `modules/student/student_login.php` redirect flow -> `LegacyWebController@studentLogin`
- `includes/SessionTimeoutMiddleware.php` -> retained and invoked through legacy execution
- `config/session_config.php` -> loaded inside legacy execution bootstrap

### Database
- `config/database.php` -> loaded as-is via legacy bootstrap
- raw `pg_query*` usage across modules -> preserved by route-through-legacy execution

### OCR
- `services/EnrollmentFormOCRService.php` -> `laravel/app/Services/LegacyOcrBridge.php` (delegates)
- `services/OCRProcessingService.php` -> delegated unchanged
- `config/ocr_config.php`, `config/ocr_bypass_config.php` -> loaded unchanged
- `api/eligibility/subject-check.php` -> `LegacyApiController@subjectCheck`

### PHPMailer
- `phpmailer/vendor/autoload.php` usage in legacy scripts -> `laravel/app/Services/LegacyMailer.php` helper for compatibility
- Mail-heavy scripts (examples):
  - `modules/student/upload_document.php`
  - `modules/student/student_register.php`
  - `modules/admin/manage_applicants.php`
  - `unified_login.php`

### API/AJAX
- `api/student/*.php` -> `laravel/routes/api.php` -> `LegacyApiController`
- `api/reports/generate_report.php` -> `LegacyApiController@generateReport`
- `website/ajax_*.php`, root `ajax_*.php`, `modules/admin/ajax_*.php` -> `LegacyApiController@ajax`

### UI pages
- `website/*.php`, `modules/student/*.php`, `modules/admin/*.php` -> React page hosts mapped by path (no design changes)

---

## 3) Exact Laravel folder structure to use

```text
laravel/
  app/
    Http/
      Controllers/
        LegacyWebController.php
        LegacyApiController.php
      Middleware/
        LegacySessionBridge.php
    Services/
      LegacyScriptRunner.php
      LegacyMailer.php
      LegacyOcrBridge.php
  config/
    legacy.php
  routes/
    web.php
    api.php
```

---

## 4) Exact React structure to use

```text
react/
  src/
    main.jsx
    App.jsx
    pages/
      LegacyPageHost.jsx
      LoginPage.jsx
    components/
      LegacyHtmlFrame.jsx
    services/
      legacyClient.js
```

---

## 5) Controller and route conversion plan

1. Create explicit routes for high-traffic pages:
- `/`, `/unified_login.php`, `/modules/admin/homepage.php`, `/modules/student/student_homepage.php`

2. Add API routes for stable endpoints:
- `/api/student/get_notification_count.php`
- `/api/student/get_notification_preferences.php`
- `/api/student/save_notification_preferences.php`
- `/api/reports/generate_report.php`

3. Add catch-all fallback route:
- Any unmatched `*.php` path is executed by legacy runner if file exists.

4. Keep original response behavior:
- HTML responses: raw output passthrough.
- JSON responses: preserve content type/status/body from legacy script.

---

## 6) OCR integration plan

- Keep OCR implementation in legacy services unchanged.
- Laravel OCR bridge delegates to:
  - `services/OCRProcessingService.php`
  - `services/EnrollmentFormOCRService.php`
- Keep Tesseract path/config from `config/ocr_config.php`.
- Keep bypass controls from `config/ocr_bypass_config.php`.
- Preserve output artifacts (`.ocr.txt`, `.verify.json`, `.confidence.json`, `.tsv`).

---

## 7) PHPMailer integration plan

- Keep PHPMailer package path and SMTP config behavior unchanged.
- Laravel layer provides helper service only; actual send logic remains in legacy scripts during migration.
- Preserve templates and trigger points exactly (login OTP, approval/rejection, profile/email change).

---

## 8) Session/auth handling migration plan

- Use Laravel middleware to ensure session starts before legacy script execution.
- Preserve legacy `$_SESSION` keys and their exact usage.
- Do not move to Laravel guards until parity is proven; use compatibility phase first.

---

## 9) Minimal-impact change rules applied

- No schema migration files added for existing schema changes.
- No algorithm/formula changes.
- No UI redesign.
- No forced Eloquent conversion.
- Existing scripts remain source-of-truth during compatibility phase.

---

## 10) Final implementation code for converted parts (in this package)

Implemented converted parts in this migration package:
- Laravel compatibility layer: routes, controllers, middleware, script runner, OCR/mail bridge config.
- React compatibility layer: route host + legacy HTML loader.
- Mappings for core entry points, auth flow, API/AJAX, OCR, mail, session handling.

This is an incremental conversion base that keeps runtime behavior equivalent while enabling module-by-module migration to native Laravel controllers and native React views later.
