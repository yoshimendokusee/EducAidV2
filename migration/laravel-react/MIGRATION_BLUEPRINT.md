# EducAid Laravel + React Migration Blueprint

## Goal

Convert the legacy EducAid PHP application into a Laravel backend with a React frontend while preserving behavior, session keys, file paths, and response envelopes during the transition.

## Migration Layers

1. Compatibility layer
- Laravel routes execute legacy PHP scripts through a script runner.
- Session bridge preserves `$_SESSION` for legacy code.
- API and page fallbacks keep unmigrated modules working.

2. Native Laravel backend
- Controllers handle migrated workflow, student, document, eligibility, report, and applicant endpoints.
- Services encapsulate database and file behavior.

3. Native React frontend
- React hosts migrated pages.
- Legacy HTML pages remain accessible through compat wrappers until each page is replaced.

## Required Files

- `.laravel-base/app/Http/Controllers/CompatWebController.php`
- `.laravel-base/app/Http/Controllers/CompatApiController.php`
- `.laravel-base/app/Http/Middleware/CompatSessionBridge.php`
- `.laravel-base/app/Services/CompatScriptRunner.php`
- `.laravel-base/config/compat.php`
- `.laravel-base/routes/web.php`
- `.laravel-base/routes/api.php`
- `.laravel-base/resources/views/app.blade.php`
- `react/src/pages/CompatPageHost.jsx`
- `react/src/components/CompatHtmlFrame.jsx`

## Behavior Rules

- Preserve legacy route names where possible.
- Preserve JSON envelopes and HTTP status codes.
- Keep file uploads, OCR, and notification side effects identical.
- Use React only for pages that have been fully mapped and validated.

## Reference Maps

- Route and module mappings are tracked in `MAPPING.csv`.
- Module migration order is tracked in `MODULE_MIGRATION_PLAN.md`.
