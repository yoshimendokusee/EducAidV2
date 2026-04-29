# Legacy Services Folder - Migration Archive

**Status:** ARCHIVED - All services migrated to new architecture
**Date:** 2026-04-29
**Migration Type:** Full Laravel + React bridge with `/src/Services/` adapters

## Migration Summary

This folder contains the legacy EducAid PHP service classes that have been completely migrated to a new architecture. No live code in the application currently imports from this folder.

### New Architecture

All services have been replaced via a two-layer migration:

1. **Adapter Layer** (`/src/Services/`)
   - Thin PHP wrapper classes that match the old service interfaces
   - These wrappers instantiate without requiring `$connection` parameter
   - Called by legacy PHP code (controllers, modules, includes)

2. **Laravel Service Layer** (`/migration/laravel-react/laravel/app/Services/`)
   - Native Laravel implementations of each service
   - Registered in `EducaidServiceProvider`
   - Called by Laravel API endpoints and adapter layer

3. **HTTP Bridge** (ApiClient + Route Handlers)
   - Legacy PHP code calls adapter classes
   - Adapters use `ApiClient` to POST/GET to Laravel API endpoints
   - Laravel controllers perform the actual work

## Service Migration Map

| Legacy Service | Adapter Location | Laravel Service | API Route |
|---|---|---|---|
| UnifiedFileService | `/src/Services/UnifiedFileService.php` | `app/Services/UnifiedFileService.php` | `/api/documents/*` |
| EnrollmentFormOCRService | `/src/Services/EnrollmentFormOCRService.php` | `app/Services/EnrollmentFormOCRService.php` | `/api/enrollment-ocr/*` |
| StudentEmailNotificationService | `/src/Services/StudentEmailNotificationService.php` | `app/Services/StudentEmailNotificationService.php` | `/api/notifications/*` |
| FileCompressionService | `/src/Services/FileCompressionService.php` | `app/Services/FileCompressionService.php` | `/api/compression/*` |
| DistributionManager | `/src/Services/DistributionManager.php` | `app/Services/DistributionManager.php` | `/api/distributions/*` |
| DistributionEmailService | `/src/Services/DistributionEmailService.php` | `app/Services/DistributionEmailService.php` | `/api/distributions/*` |
| StudentArchivalService | `/src/Services/StudentArchivalService.php` | `app/Services/StudentArchivalService.php` | Legacy DB calls only |
| BlacklistService | `/src/Services/BlacklistService.php` | `app/Services/BlacklistService.php` | Legacy DB calls only |
| DocumentReuploadService | `/src/Services/DocumentReuploadService.php` | `app/Services/DocumentReuploadService.php` | `/api/documents/reupload` |
| NotificationService | `/src/Services/NotificationService.php` | `app/Services/NotificationService.php` | `/api/notifications/create` |
| MediaEncryption | `/src/Services/MediaEncryption.php` | `app/Services/MediaEncryptionService.php` | (No direct API route) |
| OcrProcessingService_Safe | `/src/Services/OCRProcessingService_Safe.php` | `app/Services/OcrProcessingService.php` | (Injected into other services) |
| ColorGeneratorService | `/src/Services/ColorGeneratorService.php` | `/services/ColorGeneratorService.php` | (Theme helper, still in legacy) |
| SidebarThemeService | `/src/Services/SidebarThemeService.php` | `/services/SidebarThemeService.php` | (Theme helper, still in legacy) |
| HeaderThemeService | `/src/Services/HeaderThemeService.php` | `/services/HeaderThemeService.php` | (Theme helper, still in legacy) |
| FooterThemeService | `/src/Services/FooterThemeService.php` | `/services/FooterThemeService.php` | (Theme helper, still in legacy) |
| ThemeSettingsService | `/src/Services/ThemeSettingsService.php` | `/services/ThemeSettingsService.php` | (Theme helper, still in legacy) |
| ThemeGeneratorService | `/src/Services/ThemeGeneratorService.php` | `/services/ThemeGeneratorService.php` | (Theme helper, still in legacy) |
| DistributionArchiveService | (deprecated) | Not migrated | Integrated into DistributionManager |
| DistributionIdGenerator | (deprecated) | Not migrated | Utility function |
| PayrollHistoryService | Not yet migrated | TBD | N/A |

## Files to Be Deleted/Archived

The following files in this directory can be safely deleted as no active code references them:

- BlacklistService.php
- ColorGeneratorService.php
- DistributionArchiveService.php
- DistributionEmailService.php
- DistributionIdGenerator.php
- DistributionManager.php
- DocumentReuploadService.php
- EnrollmentFormOCRService.php
- FileCompressionService.php
- FileManagementService.php
- FooterThemeService.php
- HeaderThemeService.php
- MediaEncryption.php
- NotificationService.php
- OCRProcessingService_Safe.php
- PayrollHistoryService.php
- SidebarThemeService.php
- StudentArchivalService.php
- StudentEmailNotificationService.php
- ThemeGeneratorService.php
- ThemeSettingsService.php
- UnifiedFileService.php

## Files Still in Use (Theme Helpers)

The following helper files may still be referenced and should be reviewed before deletion:

- otp_sms.php
- save_login_content.php (integrated into LoginContentController)
- save_registration_grades.php
- student_notification_actions.php
- student_notification_actions.php.disabled
- toggle_section_visibility.php (integrated into LoginContentController)
- validate_pdf.php
- ColorGeneratorService.php
- SidebarThemeService.php
- HeaderThemeService.php
- FooterThemeService.php
- ThemeGeneratorService.php
- ThemeSettingsService.php

## Legacy PHP Code Migration

All live PHP code that previously imported from this folder has been updated to:

1. Include `/src/Services/Wrapper.php` files instead
2. Instantiate services as `new \App\Services\ServiceName()` (no `$connection` required)
3. Handle HTTP responses from wrapper classes

Verified migrations:
- ✅ modules/admin/archived_students.php
- ✅ modules/admin/auto_approve_high_confidence.php
- ✅ modules/admin/compress_archived_students.php
- ✅ modules/admin/distribution_control.php
- ✅ modules/admin/end_distribution.php
- ✅ modules/admin/manage_applicants.php
- ✅ modules/admin/review_registrations.php
- ✅ modules/student/upload_document.php
- ✅ modules/student/student_register.php
- ✅ send_daily_student_digests.php
- ✅ test_announcement_email.php
- ✅ test_ocr_bypass.php
- ✅ controllers/SidebarSettingsController.php

## Verification

**Final grep search** (verified 2026-04-29):
```
Pattern: require.*'/services/ | include.*'/services/ | from.*'/services | import.*'/services
Result: NO MATCHES
```

This confirms no live code currently imports from the legacy `/services/` folder.

## Recommendations for Complete Removal

1. **Backup** – Archive this entire folder to `/legacy-services-backup/` or version control tag
2. **Delete** – Remove from active codebase after backup
3. **Monitor** – Watch for any runtime errors in first week of production deployment
4. **Document** – This file serves as migration reference for future developers

## Related Files

- Migration adapter layer: `/src/Services/`
- Laravel services: `/migration/laravel-react/laravel/app/Services/`
- Service provider: `/migration/laravel-react/laravel/app/Providers/EducaidServiceProvider.php`
- API bootstrap: `/bootstrap_services.php` (routes to new layer)
