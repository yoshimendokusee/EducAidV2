# EducAid Services Migration Guide

## Overview

This guide documents the migration of all services from standalone PHP classes to Laravel-compatible services with proper namespacing, dependency injection, and PSR-4 autoloading.

## Directory Structure

```
project_root/
├── src/
│   ├── Services/          # All migrated services
│   │   ├── OCRProcessingService.php
│   │   ├── DocumentService.php
│   │   ├── AuditLogger.php
│   │   ├── OTPService.php
│   │   ├── GradeValidationService.php
│   │   ├── DataExportService.php
│   │   └── ServiceFactory.php
│   ├── Models/            # Eloquent models (future)
│   ├── Traits/            # Reusable traits
│   │   └── UsesDatabaseConnection.php
│   └── ...
├── services/              # Legacy standalone services (DEPRECATED)
├── config/                # Configuration files
├── vendor/                # Composer dependencies
└── composer.json
```

## Autoloading Configuration

The project uses PSR-4 autoloading configured in `composer.json`:

```json
"autoload": {
  "psr-4": {
    "App\\": "src/"
  },
  "classmap": [
    "config/",
    "includes/"
  ]
}
```

## How to Use Migrated Services

### Method 1: Using the Service Factory (Recommended)

```php
<?php
use App\Services\ServiceFactory;

// Create factory with database connection
$factory = new ServiceFactory($connection);

// Get services
$ocrService = $factory->makeOCRService();
$documentService = $factory->makeDocumentService();
$auditLogger = $factory->makeAuditLogger();
$otpService = $factory->makeOTPService();
```

### Method 2: Direct Instantiation

```php
<?php
use App\Services\OCRProcessingService;
use App\Services\DocumentService;

// Services automatically use the global $connection if not provided
$ocrService = new OCRProcessingService([
    'tesseract_path' => 'tesseract',
    'temp_dir' => sys_get_temp_dir()
]);

$docService = new DocumentService($connection);
```

### Method 3: Global Helper Function (Legacy Compatibility)

Create a helper file to maintain backward compatibility:

```php
<?php
// includes/service_helpers.php

function getOCRService() {
    static $service = null;
    if ($service === null) {
        $service = new \App\Services\OCRProcessingService();
    }
    return $service;
}

function getDocumentService() {
    global $connection;
    static $service = null;
    if ($service === null) {
        $service = new \App\Services\DocumentService($connection);
    }
    return $service;
}
```

## Migrated Services

### 1. OCRProcessingService

**Purpose:** Handles OCR processing using Tesseract

**Location:** `src/Services/OCRProcessingService.php`

**Key Methods:**
- `extractTextAndConfidence($filePath, $options)` - Extract text with confidence scores
- `processGradeDocument($filePath)` - Extract grades from documents

**Example:**
```php
$ocr = new \App\Services\OCRProcessingService();
$result = $ocr->extractTextAndConfidence('/path/to/document.pdf');
if ($result['success']) {
    echo "Text: " . $result['text'];
    echo "Confidence: " . $result['confidence'] . "%";
}
```

### 2. DocumentService

**Purpose:** Manages document uploads, storage, and OCR processing

**Location:** `src/Services/DocumentService.php`

**Key Methods:**
- `saveDocument($studentId, $docTypeName, $filePath, $ocrData)` - Save document
- `moveToPermStorage($studentId)` - Move from temp to permanent storage
- `getStudentDocuments($studentId)` - Get all documents for student
- `deleteDocument($documentId)` - Delete document

**Example:**
```php
$docService = new \App\Services\DocumentService($connection);
$result = $docService->saveDocument(
    'STUDENT-ID-123',
    'academic_grades',
    '/uploads/temp/file.pdf',
    ['ocr_confidence' => 90, 'verification_score' => 85]
);
```

### 3. AuditLogger

**Purpose:** Logs all system events for compliance and debugging

**Location:** `src/Services/AuditLogger.php`

**Key Methods:**
- `log($eventType, $eventCategory, $description, $userType, $username, $userId, $status, $metadata)` - Log event
- `logAuth($username, $userType, $action, $userId, $status)` - Log authentication event
- `logAdminAction($username, $adminId, $action, $category, $metadata, $status)` - Log admin action
- `getUserLogs($username, $limit, $offset)` - Get user logs

**Example:**
```php
$audit = new \App\Services\AuditLogger($connection);
$audit->logAdminAction(
    'admin@example.com',
    123,
    'Student approved: STUDENT-123',
    'applicant_management',
    ['student_id' => 'STUDENT-123']
);
```

### 4. OTPService

**Purpose:** Generates and verifies one-time passwords

**Location:** `src/Services/OTPService.php`

**Key Methods:**
- `sendOTP($email, $purpose, $adminId)` - Generate and send OTP
- `verifyOTP($adminId, $otp, $purpose)` - Verify OTP code
- `isOTPValid($adminId, $otp, $purpose)` - Check if OTP is valid

**Requirements:**
- PHPMailer (installed via Composer)
- Environment variables: `MAIL_HOST`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`

**Example:**
```php
$otp = new \App\Services\OTPService($connection);
$sent = $otp->sendOTP('user@example.com', 'verification', 123);
$verified = $otp->verifyOTP(123, '123456', 'verification');
```

### 5. GradeValidationService

**Purpose:** Validates grades against university-specific policies

**Location:** `src/Services/GradeValidationService.php`

**Key Methods:**
- `isSubjectPassing($universityKey, $rawGrade)` - Check if grade is passing
- `validateApplicant($universityKey, $subjects)` - Validate all subjects
- `getUniversityGradingPolicy($universityKey)` - Get grading policy
- `normalizeGrade($rawGrade)` - Fix OCR artifacts in grades

**Example:**
```php
$validator = new \App\Services\GradeValidationService($connection);
$result = $validator->validateApplicant('UP', [
    ['subject' => 'Math', 'grade' => '2.5', 'confidence' => 90],
    ['subject' => 'English', 'grade' => '2.0', 'confidence' => 85]
]);
if ($result['eligible']) {
    echo "Student is eligible!";
}
```

### 6. DataExportService

**Purpose:** Exports student data to ZIP file

**Location:** `src/Services/DataExportService.php`

**Key Methods:**
- `buildExport($studentId)` - Build ZIP archive with student data
- `deleteExport($zipPath)` - Delete export file
- `cleanupOldExports($daysOld)` - Clean up old exports

**Example:**
```php
$export = new \App\Services\DataExportService($connection);
$result = $export->buildExport('STUDENT-123');
if ($result['success']) {
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="student_data.zip"');
    readfile($result['zip_path']);
}
```

## Database Connection Management

All services use the `UsesDatabaseConnection` trait for consistent connection handling:

```php
// In any service:
use App\Traits\UsesDatabaseConnection;

class YourService {
    use UsesDatabaseConnection;
    
    public function __construct($dbConnection = null) {
        $this->setConnection($dbConnection);
    }
}
```

## Migration Checklist

- [x] Create `src/Services` directory structure
- [x] Migrate OCRProcessingService
- [x] Migrate DocumentService
- [x] Migrate AuditLogger
- [x] Migrate OTPService
- [x] Migrate GradeValidationService
- [x] Migrate DataExportService
- [x] Create ServiceFactory
- [x] Create UsesDatabaseConnection trait
- [x] Update composer.json with PSR-4 autoloading
- [ ] Migrate remaining services (FileManagementService, DistributionManager, etc.)
- [ ] Create Eloquent models for database tables
- [ ] Update all controllers to use new services
- [ ] Update all AJAX endpoints to use new services
- [ ] Test OCR pipeline end-to-end
- [ ] Performance testing
- [ ] Deployment to Railway

## Installation Steps

### 1. Install Composer Dependencies

```bash
composer install
composer dump-autoload -o
```

### 2. Enable Autoloading

Add this to your bootstrap/entry point file:

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';
```

### 3. Update Existing Code

Replace old service instantiation:

```php
// OLD (deprecated)
require_once __DIR__ . '/services/OCRProcessingService.php';
$ocr = new OCRProcessingService();

// NEW (recommended)
use App\Services\ServiceFactory;
$factory = new ServiceFactory($connection);
$ocr = $factory->makeOCRService();
```

## Configuration

### Environment Variables

```bash
# .env or railway.json
TESSERACT_PATH=tesseract
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your-email@gmail.com
MAIL_PASSWORD=your-app-password
MAIL_FROM_ADDRESS=noreply@educaid.local
MAIL_FROM_NAME="EducAid System"
```

### File Paths

The system automatically detects environment (Railway vs Localhost):

- **Railway:** Uses `/mnt/assets/uploads/` volume
- **Localhost:** Uses `./assets/uploads/`

## OCR Setup

### Required Packages

```bash
# Linux (Ubuntu/Debian)
sudo apt-get install tesseract-ocr imagemagick ghostscript poppler-utils

# MacOS
brew install tesseract imagemagick ghostscript poppler

# Windows
# Download from: https://github.com/UB-Mannheim/tesseract/wiki
```

### Tesseract Installation Verification

```bash
tesseract --version
which tesseract  # Linux/Mac
where tesseract  # Windows
```

### OCR Bypass for Development

Set in your environment or config:

```php
define('OCR_BYPASS_ENABLED', true);  // Skip actual OCR in development
define('OCR_BYPASS_CONFIDENCE', 95.0);
```

## Troubleshooting

### Autoloading Issues

```bash
composer dump-autoload -o
php -r "require 'vendor/autoload.php'; echo 'OK';"
```

### Database Connection Errors

Ensure the global `$connection` variable is set:

```php
global $connection;
// Connection should be established via config/database.php
```

### OCR Processing Failures

Check logs:
```bash
tail -f /path/to/error.log | grep OCR
```

Verify Tesseract:
```bash
tesseract /path/to/image.png - -l eng
```

### File Upload Issues

Ensure proper permissions:
```bash
chmod -R 755 assets/uploads/
chmod -R 755 assets/temp/
```

## Performance Optimization

### Enable Autoload Optimization

```bash
composer install --optimize-autoloader --no-dev
```

### Cache Database Connections

```php
$factory = new ServiceFactory($connection);
// Reuse $factory instance across requests
```

## Future Roadmap

1. **Eloquent Models** - Create ORM models for all database tables
2. **Laravel Integration** - Full integration with Laravel's service container
3. **API Endpoints** - RESTful API for all services
4. **Event Broadcasting** - Real-time updates using WebSockets
5. **Queue Jobs** - Async processing for OCR and file compression
6. **Testing** - Comprehensive unit and integration tests

## Support

For issues or questions:
1. Check logs in `/logs/` directory
2. Review error messages in server logs
3. Consult database schema for table structures
4. Check environment variables configuration

## References

- [Composer PSR-4 Autoloading](https://getcomposer.org/doc/04-schema.md#psr-4)
- [Tesseract Documentation](https://github.com/UB-Mannheim/tesseract/wiki)
- [Laravel Service Providers](https://laravel.com/docs/services)
- [PHP Namespaces](https://www.php.net/manual/en/language.namespaces.php)
