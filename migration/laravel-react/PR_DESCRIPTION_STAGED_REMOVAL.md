Title: staged: remove migrated compat route entries

Summary:
- This PR applies a staged removal of explicit compat route entries from `laravel/routes/web.php` so the framework uses controller-level routing and folder prefix fallbacks for remaining pages.
- It includes a backup of the original routes at `laravel/routes/web.original.backup.php`.

What changed:
- Removed 89 explicit compat route entries that are covered by controller handlers or folder prefix bridges.
- Kept core entry points and fallback behavior intact.
- Added `web.original.backup.php` to preserve the previous `web.php` for quick rollback.

Why:
- Enables safe canary testing via `staged/**` branch CI (`compat-staged` workflow).
- Reduces surface area of the legacy compat bridge while preserving fallback for un-migrated pages.

Testing & validation:
- CI will run `.github/workflows/compat-staged.yml` for this branch.
- Monitor `X-Compat-Deprecated` header hits to validate traffic to removed paths before final merge.

Rollback:
- Restore `laravel/routes/web.original.backup.php` content to `laravel/routes/web.php` and re-deploy.

Next steps:
1. Let CI run and review `compat-staged` results on this PR.
2. (Optional) Provide a runtime environment with Composer access to re-run `tools/run_parity_diffs.php` and refine the migrated list.
3. Merge to main after canary validation.

Notes:
- Link to the parity tooling and artifacts: `migration/laravel-react/tools/`, `migration/laravel-react/migrated_compat_paths.txt`, `migration/laravel-react/patches/remove_migrated_routes.patch`.
