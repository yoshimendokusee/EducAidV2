# Safe Cleanup Checklist - Non-Breaking Deletions & Archives

**Purpose:** Guide for safely cleaning up old files without breaking the system  
**Risk Level:** LOW (follow this checklist exactly)

---

## Overview

The following files/folders are **NOT** part of the active runtime system and are safe to archive or delete. This cleanup is optional but recommended to reduce repository clutter.

---

## SAFE TO DELETE/ARCHIVE

### 1. Root-Level Maintenance Scripts (~150 files)
**Status:** ✅ SAFE - Not used at runtime

These are one-off utility and migration scripts that have already been executed. Keeping them is optional - they're not imported or executed by the active system.

#### Examples:
```
❌ DELETE THESE:
- 000 DEBUG delete_student_database stuff.php
- 00001 install_year_advancement_functions.php
- 00002 fix_graduation_eligibility_scope.php
- ... (100+ similar files)
- ajax_create_logo_directory.php
- apply_grading_fix.php
- bootstrap_services.php
- check_and_create_export_table.php
- check_fix_student_slots.php
- cleanup_all_students.php
- cli_seed_demo_students.php
- cli_upload_municipality_logos.php
- create_current_session.php
- debug_municipality_logos.php
- delete_student_GENERALTRIAS-2025-3-1QTP19.php
- disable_distribution_validation_debug.sql
- find_dist_table.php
- fix_ajax_json_errors.ps1
- fix_*.php (most of these)
- install_footer_table.php
- migrate_*.php (most of these)
- manual_register_students_*.php
- populate_municipalities_railway.php
- setup_*.php
- test_*.php (most of these)
- update_*.php (most of these)
- verify_*.php
```

#### How to Archive:
```bash
# Option 1: Create archive folder
mkdir archive/maintenance-scripts

# Move all maintenance scripts
mv 000*.php archive/maintenance-scripts/
mv 00001*.php archive/maintenance-scripts/
mv check_*.php archive/maintenance-scripts/
# ... etc

# Then remove empty root directory entries
```

#### Risk if Deleted: **NONE** ✓

---

### 2. Old Application Structure Folders
**Status:** ✅ SAFE - Replaced by migration/laravel-react/

These are the original application folders that have been completely replaced by the new Laravel + React architecture.

#### Safe to Archive:
```
❌ DELETE THESE FOLDERS:
/config               (old Laravel config → migrate/laravel-react/laravel/config)
/controllers          (old PHP controllers → migrate/laravel-react/laravel/app/Http/Controllers)
/core                 (old core files → migrate/laravel-react/laravel/app)
/utils                (old utilities → migrate/laravel-react/react/src/utils)
/views                (old views → migrate/laravel-react/react/src/pages)
/examples             (old examples → not needed)
/demo                 (old demo → not needed)
/tests                (old tests → migrate tests to Laravel tests/)
```

#### How to Archive:
```bash
mkdir archive/legacy-app-structure
mv config/ archive/legacy-app-structure/
mv controllers/ archive/legacy-app-structure/
mv core/ archive/legacy-app-structure/
mv utils/ archive/legacy-app-structure/
mv views/ archive/legacy-app-structure/
mv examples/ archive/legacy-app-structure/
mv demo/ archive/legacy-app-structure/
# Keep tests/ for now if there are valuable unit tests
```

#### Risk if Deleted: **NONE** ✓

---

### 3. Old Standalone PHP Entry Points
**Status:** ✅ SAFE - Replaced by React LoginPage

These old entry point files have been completely replaced by the new React-based application.

#### Safe to Delete:
```
❌ DELETE THESE FILES:
- login.php              (replaced by React LoginPage)
- register.php           (replaced by React registration)
- unified_login.php      (replaced by React LoginPage)
- router.php             (replaced by Laravel routes/web.php)
- phpinfo.php            (debug file only)
- signup.html            (replaced by React form)
- reb.html               (unknown old file)
- tes.html               (test file)
- upload_status_preview.html (preview file)
- test_history_endpoint.php (test file)
```

#### Risk if Deleted: **NONE** ✓

---

### 4. Old Database & Migration Files (if applicable)
**Status:** ⚠️ CAUTIOUS - Keep if you might need to refer to old schema

These can be archived if you have current backups:

```
OPTIONAL DELETE:
- database_schema_dump_*.sql (keep 1 most recent for reference)
- delete_student.sql (old migration)
- disable_distribution_validation_debug.sql
- enable_distribution_validation_production.sql
```

#### How to Archive:
```bash
mkdir archive/old-migrations
mv database_schema_dump_*.sql archive/old-migrations/
# Keep the most recent one in root for reference
mv archive/old-migrations/CURRENT_database_schema_dump_*.sql database_schema_dump_latest_backup.sql
```

#### Risk if Deleted: **NONE** (keep latest backup)

---

### 5. Documentation Files (Old)
**Status:** ✅ SAFE - Replaced by new migration docs

These are outdated documentation files. New docs should be in:
- `migration/laravel-react/SYSTEM_ARCHITECTURE_AUDIT.md`
- `migration/laravel-react/FINAL_VERIFICATION_REPORT.md`

```
SAFE TO DELETE:
- ADMIN_VERIFICATION_BYPASS_FIX.md
- AJAX_FILES_FIX_STATUS.md
- AJAX_FIX_SUMMARY.md
- ALL_AJAX_FILES_FIXED.md
- BYPASS_VERIFICATION_COMPLETE.md
- CAMERA_PERMISSION_FIX_FINAL.md
- CAPTCHA_ERROR_FIX.md
- CAPTCHA_GATE_REMOVAL.md
- CMS_EDITOR_CLEANUP.md
- CMS_EDITOR_FIXES.md
- CMS_JSON_ERROR_FIX.md
- COMPLETE_FIX_SUMMARY.md
- CSRF_FIX_COMPLETE.md
- CSRF_TOKEN_FIX_GUIDE.md
- CUSTOM_DOMAIN_ISSUES.md
- DOMAIN_ISSUES_SUMMARY.md
- (and 50+ other old fix/status docs)
```

#### Why: These were created during development to track temporary fixes. They're now outdated.

#### Risk if Deleted: **NONE** ✓

---

### 6. Power Shell & Batch Script Utilities
**Status:** ✅ SAFE - Development utilities only

These are old development/deployment scripts that are no longer used:

```
SAFE TO DELETE:
- apply_secure_session_config.ps1
- apply_session_simple.ps1
- fix_ajax_json_errors.ps1
- deploy_ocr_bypass.ps1
- deploy_ocr_bypass.sh
- download_bootstrap_icons_fonts.ps1
- generate_favicons.ps1
- install_multi_account_prevention.bat
- run-composer.ps1
- run_migration_1.php
- run_migrations.php
- run_slot_threshold_check.bat
- verify_offline_fix.ps1
```

#### Risk if Deleted: **NONE** ✓

---

### 7. Temporary Test Files
**Status:** ✅ SAFE - Development artifacts

```
SAFE TO DELETE:
- bnikbkbkjb                        (random file)
- test commit                       (git artifact)
- test message commit               (git artifact)
- reb.html                          (old test)
- tes.html                          (old test)
- footer_setup_complete.html        (old test)
- signup.html                       (old test)
- upload_status_preview.html        (old test)
- test_*.php                        (most test files)
```

#### Risk if Deleted: **NONE** ✓

---

### 8. Configuration Files (Some)
**Status:** ✅ SAFE - Replaced by Laravel config

Old configuration files that are no longer used:

```
SAFE TO DELETE (if using Laravel config):
- .user.ini                         (old PHP ini config)
Old .env files from testing         (keep current .env only)
```

#### Keep:
- `.env` (current environment)
- `.env.example` (template)
- `.gitignore` (git configuration)
- `.htaccess` (web server configuration)

---

## DO NOT DELETE - CRITICAL SYSTEM FILES

### ABSOLUTELY KEEP:
```
✅ KEEP THESE:
migration/laravel-react/              (Entire active app)
migration/laravel-react/laravel/      (Laravel backend)
migration/laravel-react/react/        (React frontend)
migration/laravel-react/react/dist/   (Built React assets)

modules/                              (125 legacy files still needed)
modules/admin/                        (94 admin PHP files)
modules/student/                      (31 student PHP files)

includes/                             (Workflow logic files)
services/                             (Grade validation, OCR, etc.)
api/                                  (API endpoint files)

vendor/                               (Composer dependencies)
node_modules/                         (NPM dependencies)
composer.json                         (PHP dependency manifest)
package.json                          (NPM dependency manifest)

database/                             (Laravel migrations & seeds)
resources/                            (Laravel resources)
public/                               (Web server public folder)
storage/                              (File uploads, cache)

.git/                                 (Git history)
.env                                  (Environment configuration)
.gitignore                            (Git rules)
.vscode/                              (IDE configuration)

artisan                               (Laravel command runner)
router.php                            ⚠️ CHECK - may be used
```

---

## Cleanup Strategy (Recommended)

### Step 1: Create Archive Folder
```bash
mkdir -p archive
mkdir -p archive/maintenance-scripts
mkdir -p archive/legacy-app-structure
mkdir -p archive/old-migrations
mkdir -p archive/documentation
```

### Step 2: Archive in Phases
```bash
# Phase 1: Maintenance scripts (safe immediately)
mv 000*.php archive/maintenance-scripts/
mv 00001*.php archive/maintenance-scripts/
mv check_*.php archive/maintenance-scripts/
mv cleanup_*.php archive/maintenance-scripts/
mv debug_*.php archive/maintenance-scripts/
mv fix_*.php archive/maintenance-scripts/
# ... etc (~150 files)

# Phase 2: Old app structure (safe immediately)
mv config/ archive/legacy-app-structure/
mv controllers/ archive/legacy-app-structure/
mv core/ archive/legacy-app-structure/
mv utils/ archive/legacy-app-structure/
# ... etc

# Phase 3: Old docs (safe immediately)
mv ADMIN_*.md archive/documentation/
mv AJAX_*.md archive/documentation/
# ... etc

# Phase 4: Database backups (optional, after you confirm new backups work)
mv database_schema_dump_*.sql archive/old-migrations/
```

### Step 3: Verify System Still Works
```bash
cd migration/laravel-react/laravel
php artisan route:list          # Should show all routes
php artisan about               # Should show app info

cd ../react
npm run build                   # Should build successfully
```

### Step 4: Commit to Git
```bash
git add archive/
git add .gitignore  # Update to ignore archive if desired
git commit -m "chore: archive legacy files and maintenance scripts"
git push
```

---

## What Gets You in Trouble

### ❌ DO NOT DO THESE:

1. **Delete modules/ folder**
   - Still needed by CompatScriptRunner
   - System will throw 404 errors
   - Users can't access legacy pages

2. **Delete includes/ or services/ folders**
   - Still referenced by legacy PHP files
   - Will cause include/require errors
   - Database operations may fail

3. **Delete vendor/ or node_modules/**
   - Will break dependency loading
   - PHP and JavaScript will fail to load

4. **Delete .env file**
   - Loses database credentials
   - App can't connect to database
   - System becomes inoperable

5. **Delete migration/laravel-react/**
   - Deletes the entire active application
   - System completely non-functional

6. **Delete database/ folder**
   - Loses Laravel migrations
   - Hard to rebuild database schema

---

## Checklist for Safe Cleanup

- [ ] Read this document completely
- [ ] Create archive/ folder
- [ ] Backup current database (if not automated)
- [ ] Run tests to confirm system works
- [ ] Archive maintenance scripts to archive/maintenance-scripts/
- [ ] Archive old app structure to archive/legacy-app-structure/
- [ ] Archive old docs to archive/documentation/
- [ ] Verify routes still work: `php artisan route:list`
- [ ] Verify React still builds: `npm run build`
- [ ] Test login functionality
- [ ] Test student dashboard
- [ ] Test admin dashboard
- [ ] Commit changes to git
- [ ] Deploy to staging first (if applicable)
- [ ] Monitor for errors on staging
- [ ] Deploy to production (if applicable)

---

## Recovery Plan

If something goes wrong:

1. **System won't start:**
   ```bash
   git status  # See what was deleted
   git restore <filename>  # Restore deleted file
   git checkout .  # Restore all deleted files
   ```

2. **Routes broken:**
   ```bash
   cd migration/laravel-react/laravel
   php artisan route:list  # Check routes
   php artisan cache:clear  # Clear route cache
   ```

3. **Database connection lost:**
   ```bash
   # Check .env file still exists
   cat .env
   # If accidentally deleted, restore from backup
   ```

---

## Summary

- ✅ **~150 root maintenance scripts** - SAFE TO DELETE
- ✅ **Old app structure folders** - SAFE TO DELETE
- ✅ **Old documentation files** - SAFE TO DELETE
- ✅ **Old test files** - SAFE TO DELETE
- ✅ **Old power shell scripts** - SAFE TO DELETE
- ❌ **modules/ folder** - DO NOT DELETE
- ❌ **includes/ folder** - DO NOT DELETE
- ❌ **services/ folder** - DO NOT DELETE
- ❌ **migration/laravel-react/** - DO NOT DELETE
- ❌ **vendor/, node_modules/, .env** - DO NOT DELETE

**Total Safe Cleanup:** ~200 files, ~50 MB  
**Critical Files to Keep:** ~1,200 files, ~300 MB

---

Generated: December 2025  
Use With: SYSTEM_ARCHITECTURE_AUDIT.md + FINAL_VERIFICATION_REPORT.md
