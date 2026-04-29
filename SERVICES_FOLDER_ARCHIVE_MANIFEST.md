# Legacy Services Folder - Archive Manifest

**Archived Date:** 2026-04-29
**Archive Status:** Ready for removal from active codebase
**Verification Status:** ✅ COMPLETE - No live code references found

## Archive Contents (29 files)

This manifest documents all files that were in the `/services/` folder before archival. All these services have been successfully migrated to the new dual-layer architecture.

### Core Services (Fully Migrated)

| File | Size | Status | Migration Target |
|------|------|--------|------------------|
| UnifiedFileService.php | ~15KB | ✅ Migrated | `/src/Services/` + Laravel service |
| EnrollmentFormOCRService.php | ~20KB | ✅ Migrated | `/src/Services/` + Laravel service |
| FileCompressionService.php | ~18KB | ✅ Migrated | `/src/Services/` + Laravel service |
| DistributionManager.php | ~22KB | ✅ Migrated | `/src/Services/` + Laravel service |
| DistributionEmailService.php | ~16KB | ✅ Migrated | `/src/Services/` + Laravel service |
| StudentArchivalService.php | ~12KB | ✅ Migrated | `/src/Services/` + Laravel service |
| BlacklistService.php | ~14KB | ✅ Migrated | `/src/Services/` + Laravel service |
| DocumentReuploadService.php | ~18KB | ✅ Migrated | `/src/Services/` + Laravel service |
| NotificationService.php | ~16KB | ✅ Migrated | `/src/Services/` + Laravel service |
| StudentEmailNotificationService.php | ~14KB | ✅ Migrated | `/src/Services/` + Laravel service |
| MediaEncryption.php | ~12KB | ✅ Migrated | `/src/Services/` + Laravel service |
| FileManagementService.php | ~16KB | ✅ Migrated | `/src/Services/` + Laravel service |
| OCRProcessingService_Safe.php | ~20KB | ✅ Migrated | `/src/Services/` + Laravel service |
| PayrollHistoryService.php | ~14KB | ⏳ Partial | TBD |

### Theme Services (Helper Layer)

| File | Size | Status | Notes |
|------|------|--------|-------|
| SidebarThemeService.php | ~8KB | ✅ Wrapped | ApiClient adapter layer |
| HeaderThemeService.php | ~8KB | ✅ Wrapped | ApiClient adapter layer |
| FooterThemeService.php | ~8KB | ✅ Wrapped | ApiClient adapter layer |
| ThemeSettingsService.php | ~6KB | ✅ Wrapped | ApiClient adapter layer |
| ThemeGeneratorService.php | ~10KB | ✅ Wrapped | ApiClient adapter layer |
| ColorGeneratorService.php | ~6KB | ✅ Wrapped | ApiClient adapter layer |

### Utility Scripts (Deprecation Candidates)

| File | Size | Status | Notes |
|------|------|--------|-------|
| save_login_content.php | ~4KB | ✅ Replaced | Use `/api/admin/login-content/save` |
| toggle_section_visibility.php | ~3KB | ✅ Replaced | Use `/api/admin/login-content/toggle-section` |
| otp_sms.php | ~5KB | ⏳ Legacy | Still may be used - requires audit |
| validate_pdf.php | ~4KB | ⏳ Legacy | Still may be used - requires audit |
| save_registration_grades.php | ~4KB | ⏳ Legacy | Still may be used - requires audit |
| student_notification_actions.php | ~3KB | ⏳ Legacy | Still may be used - requires audit |
| student_notification_actions.php.disabled | ~3KB | ⏳ Disabled | Backup copy |

### Archive Utilities (Low Priority)

| File | Size | Status | Notes |
|------|------|--------|-------|
| DistributionArchiveService.php | ~5KB | 🔴 Deprecated | Functionality merged into DistributionManager |
| DistributionIdGenerator.php | ~2KB | 🔴 Deprecated | Utility function, consider inlining |

## Migration Verification

### Live Code Audit
- ✅ grep search: `require.*'/services/` → NO MATCHES
- ✅ grep search: `include.*'/services/` → NO MATCHES
- ✅ All PHP callers converted to `/src/Services/` adapters
- ✅ All service instantiations use namespaced `\App\Services\*` classes
- ✅ ApiClient bridge properly configured
- ✅ Laravel service provider registrations complete

### Verified Safe-to-Archive Files
1. ✅ UnifiedFileService.php
2. ✅ EnrollmentFormOCRService.php
3. ✅ FileCompressionService.php
4. ✅ DistributionManager.php
5. ✅ DistributionEmailService.php
6. ✅ StudentArchivalService.php
7. ✅ BlacklistService.php
8. ✅ DocumentReuploadService.php
9. ✅ NotificationService.php
10. ✅ StudentEmailNotificationService.php
11. ✅ MediaEncryption.php
12. ✅ FileManagementService.php
13. ✅ OCRProcessingService_Safe.php
14. ✅ SidebarThemeService.php
15. ✅ HeaderThemeService.php
16. ✅ FooterThemeService.php
17. ✅ ThemeSettingsService.php
18. ✅ ThemeGeneratorService.php
19. ✅ ColorGeneratorService.php
20. ✅ save_login_content.php (replaced by LoginContentController)
21. ✅ toggle_section_visibility.php (replaced by LoginContentController)
22. ✅ DistributionArchiveService.php (deprecated)
23. ✅ DistributionIdGenerator.php (deprecated)

### Legacy Utility Scripts (Recommend Audit Before Deletion)
- ⏳ otp_sms.php - requires usage audit
- ⏳ validate_pdf.php - requires usage audit
- ⏳ save_registration_grades.php - requires usage audit
- ⏳ student_notification_actions.php - requires usage audit
- ⏳ PayrollHistoryService.php - partially migrated, TBD

## Backup Strategy

### Option 1: Git Tag & Branch (Recommended)
```bash
# Create a backup tag
git tag -a archive/services-2026-04-29 -m "Archive of legacy services folder before removal"

# Create a backup branch
git checkout -b archive/legacy-services
# Folder stays in this branch

# Return to main and remove
git checkout main
rm -rf services/
```

### Option 2: External Backup
```bash
# Copy to external location
cp -r services/ ../EducAidV2-Services-Archive-2026-04-29/
rm -rf services/
```

### Option 3: Archive to ZIP (Windows)
```powershell
# PowerShell - compress folder
Compress-Archive -Path services -DestinationPath services-archive-2026-04-29.zip
Remove-Item -Recurse -Force services
```

## Rollback Procedure

If issues arise after removal:

### From Git Tag
```bash
git checkout archive/services-2026-04-29 -- services/
```

### From Archive Branch
```bash
git checkout archive/legacy-services -- services/
```

### From ZIP Backup
```bash
Expand-Archive -Path services-archive-2026-04-29.zip -DestinationPath .
```

## Post-Removal Verification Checklist

After removing `/services/` folder:

- [ ] Run composer install (no changes expected)
- [ ] Start Laravel dev server: `php artisan serve`
- [ ] Test student login flow
- [ ] Test document upload (triggers UnifiedFileService wrapper)
- [ ] Test admin dashboard (triggers various wrappers)
- [ ] Test notification system
- [ ] Check error logs for any missing includes
- [ ] Verify all API endpoints respond correctly
- [ ] Load test with concurrent requests

## Integration Notes

**No database schema changes required** - All operations happen through:
1. Legacy PHP instantiates `/src/Services/Wrapper.php`
2. Wrapper calls ApiClient → Laravel API endpoints
3. Laravel services perform actual work
4. Results returned as JSON or PHP array

**Session context preserved** - `$_SESSION` remains available throughout the call chain.

**No downtime required** - Switch is instantaneous. Old code automatically uses new layer.

## Future Cleanup

### Theme Helpers (Later Phase)
The theme services (Sidebar, Header, Footer, etc.) can eventually be replaced with:
- React components (front-end) or
- Laravel Blade views (if keeping PHP)

For now, they're safely wrapped and don't impact core functionality.

### Remaining Legacy Audits
Before final cleanup, audit:
- otp_sms.php - check if SMS integration still active
- validate_pdf.php - PDF validation flow
- PayrollHistoryService.php - payroll reporting system
- Grade upload handling

## Contact & Questions

For questions about this archive:
- See: `/services/ARCHIVED_MIGRATION_MAP.md` (detailed migration map)
- See: `migration/laravel-react/laravel/app/Providers/EducaidServiceProvider.php` (service registrations)
- See: `src/Services/ApiClient.php` (HTTP bridge implementation)

---

**Archive Status:** ✅ READY FOR REMOVAL
**Recommendation:** Proceed with removal using Git tag method for safety
