# Legacy Bridge Inventory & Cleanup Plan

Date: May 3, 2026

## Summary
The compatibility bridge (`CompatWebController`, `CompatApiController`, `CompatScriptRunner`) continues to serve legacy PHP pages under `/modules/*` and root-level PHP files. Routes are registered in `laravel/routes/web.php` and grouped under the `compat.session.bridge` middleware.

## Inventory (high-level)
- `CompatWebController` routes: root, unified_login.php, modules/admin/*, modules/student/*, fallback for any `*.php`.
- `CompatApiController` supports ajax/api-style legacy paths via `CompatScriptRunner`.
- Admin and Student module bridges exist in `AdminModulesController` and `StudentModulesController` mapping ~100 legacy pages.

## Remaining Legacy Files
All files under `modules/admin` and `modules/student` are still present in `modules/` and are routed by the Compat controllers. These are intentionally retained until parity is 1:1 and decommissioning is safe.

## Cleanup Plan (final integration)
1. Create a migration checklist per PHP page: SQL parity, behavior parity, test coverage. (manual)
2. Gradually mark pages as "migrated" and remove their route mapping from `web.php` once tests pass.
3. After all pages removed, remove `CompatScriptRunner` and compat controllers, or keep as emergency fallback behind feature flag.
4. Prepare deploy checklist and rollback plan.

## Immediate Next Steps (recommended)
- Generate per-page parity report (compare old PHP outputs vs new endpoints).  
- Add deprecation headers to compat responses for pages already migrated.  
- Remove trivial pages with no usage (static content) earlier to reduce maintenance.

---

If you'd like, I can:
- generate a detailed per-file parity checklist (automated diff of sample outputs), or
- start deprecating migrated pages by adding `X-Compat-Deprecated: true` response header from `CompatScriptRunner` for flagged paths, or
- begin removing already-migrated page routes from `web.php` in a staged branch.

Which option do you want me to perform now?