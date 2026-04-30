# Route and Module Mappings

This file summarizes the migration map used by the Laravel + React scaffold.

## Core mappings

- `website/index.php` -> `/` -> React compat host
- `unified_login.php` -> `/login` and `/unified_login.php`
- `modules/admin/index.php` -> compat route fallback
- `modules/student/student_login.php` -> compat route fallback

## API mappings

- `includes/workflow_control.php` -> `/api/workflow/status` and `/api/workflow/student-counts`
- `api/student/*.php` -> `/api/student/*`
- `api/reports/generate_report.php` -> `/api/reports/generate_report.php`
- `api/eligibility/subject-check.php` -> `/api/eligibility/subject-check.php`

## Page mappings

- Student pages migrate to React under `/student/*`
- Admin pages migrate to React under `/admin/*`
- Unmigrated routes continue through compat controllers

## Source of truth

- Detailed CSV mapping: `MAPPING.csv`
- Module progression notes: `MODULE_MIGRATION_PLAN.md`
