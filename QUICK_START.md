# EducAid Services - Quick Start Guide

## 🚀 Get Started in 5 Minutes

### Step 1: Install Dependencies
```bash
cd /path/to/EducAidV2
composer install
composer dump-autoload -o
```

### Step 2: Include Bootstrap
Add this to your application entry point (index.php, bootstrap file, etc):

```php
<?php
// At the top of your script, right after session_start()
require_once __DIR__ . '/bootstrap_services.php';

// Now all services are available via helpers
```

### Step 3: Use Services with Helpers
```php
<?php
// Process OCR
$ocr_result = process_ocr('/path/to/document.pdf');

// Save document
$save_result = save_document('STUDENT-123', 'academic_grades', '/path/to/file.pdf', $ocr_result);

// Log action
audit_log('document_processed', 'documents', 'Grade document processed', 'system', 'ocr_processor');

// Validate grades
$validation = validate_grades('UP', [
    ['subject' => 'Math', 'grade' => '2.5', 'confidence' => 90]
]);

// Send OTP
send_otp('user@example.com', 'verification', 123);
```

---

## 📦 What You Got

### 🎯 6 Migrated Services
1. **OCRProcessingService** - Tesseract OCR processing
2. **DocumentService** - Document management
3. **AuditLogger** - Audit trail logging
4. **OTPService** - One-time passwords
5. **GradeValidationService** - Grade validation
6. **DataExportService** - Data export to ZIP

### 🏗️ Foundation Components
- **ServiceFactory** - Dependency injection container
- **UsesDatabaseConnection Trait** - Database utilities
- **bootstrap_services.php** - Service initialization & helpers

### 📚 Documentation
- **SERVICES_MIGRATION_GUIDE.md** - Comprehensive guide (50+ pages)
- **MIGRATION_SUMMARY.md** - Status & overview
- **REMAINING_SERVICES_CHECKLIST.md** - How to migrate other services

### ⚙️ Configuration
- **composer.json** - Updated with autoloading & PHPMailer
- **src/** - New directory structure (Services, Models, Traits)

---

## 🔧 Common Usage Patterns

### Pattern 1: Using Helpers (Easiest)
```php
<?php
require_once 'bootstrap_services.php';

// Process a document
$result = process_ocr('/uploads/temp/grade_document.pdf');
echo "Confidence: " . $result['confidence'] . "%";
```

### Pattern 2: Using Factory (Recommended for Complex Operations)
```php
<?php
require_once 'bootstrap_services.php';

$ocrService = services('ocr');
$docService = services('documents');
$auditLogger = services('audit');

// Process document
$ocr_result = $ocrService->extractTextAndConfidence($filePath);

// Save document
$save_result = $docService->saveDocument($studentId, 'academic_grades', $filePath, $ocr_result);

// Log the action
$auditLogger->logAdminAction($username, $adminId, 'Document processed', 'documents', [
    'student_id' => $studentId,
    'document_type' => 'academic_grades',
    'confidence' => $ocr_result['confidence']
]);
```

### Pattern 3: Direct Instantiation (For Specific Needs)
```php
<?php
use App\Services\OCRProcessingService;
use App\Services\DocumentService;

global $connection;

// Create services directly with custom connection
$ocr = new OCRProcessingService($connection, [
    'tesseract_path' => '/usr/bin/tesseract',
    'use_imagick' => true
]);

$docs = new DocumentService($connection);

// Use them
$result = $ocr->extractTextAndConfidence($filePath);
```

---

## 📋 Configuration

### Environment Variables

Add these to your `.env` file or Railway config:

```bash
# OCR Configuration
TESSERACT_PATH=/usr/bin/tesseract

# Email Configuration (for OTP service)
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your-email@gmail.com
MAIL_PASSWORD=your-app-password
MAIL_FROM_ADDRESS=noreply@educaid.local
```

### Database Connection

The system automatically uses the global `$connection` variable. Ensure it's properly initialized:

```php
<?php
// In your database initialization file
$connection = pg_connect("host=$dbhost port=$dbport dbname=$dbname user=$dbuser password=$dbpass");

// Or via environment URL (Railway)
$connection = pg_connect(getenv('DATABASE_PUBLIC_URL'));
```

---

## 🧪 Testing Each Service

### Test OCR Service
```php
<?php
require_once 'bootstrap_services.php';

$ocr = services('ocr');
$result = $ocr->extractTextAndConfidence('/path/to/test/document.pdf');

echo "Success: " . ($result['success'] ? 'Yes' : 'No') . "\n";
echo "Text Length: " . strlen($result['text'] ?? '') . "\n";
echo "Confidence: " . ($result['confidence'] ?? 0) . "%\n";
```

### Test Document Service
```php
<?php
require_once 'bootstrap_services.php';

$docs = services('documents');
$result = $docs->saveDocument('STUDENT-001', 'academic_grades', '/path/to/file.pdf', [
    'ocr_confidence' => 95,
    'verification_score' => 85
]);

echo "Document saved: " . ($result['success'] ? 'Yes' : 'No') . "\n";
if ($result['success']) {
    echo "Document ID: " . $result['document_id'] . "\n";
}
```

### Test Audit Logger
```php
<?php
require_once 'bootstrap_services.php';

$audit = services('audit');
$result = $audit->logAdminAction(
    'admin@example.com',
    123,
    'Test action',
    'testing',
    ['test_data' => 'value']
);

echo "Logged: " . ($result['success'] ? 'Yes' : 'No') . "\n";
```

### Test Grade Validation
```php
<?php
require_once 'bootstrap_services.php';

$validator = services('validation');
$result = $validator->validateApplicant('UP', [
    ['subject' => 'Math', 'grade' => '2.5', 'confidence' => 90],
    ['subject' => 'English', 'grade' => '2.0', 'confidence' => 85]
]);

echo "Eligible: " . ($result['eligible'] ? 'Yes' : 'No') . "\n";
echo "Failed subjects: " . count($result['failed_subjects']) . "\n";
```

---

## 🔍 Debugging & Troubleshooting

### Enable Debug Logging
```php
<?php
require_once 'bootstrap_services.php';

// All services log to error_log() automatically
// Check your PHP error log for debug output

// tail -f /var/log/php-errors.log | grep "Services"
```

### Check Autoloading
```bash
composer dump-autoload -o
php -r "require 'vendor/autoload.php'; echo 'Autoload OK';"
```

### Verify Database Connection
```php
<?php
global $connection;
if ($connection) {
    echo "✓ Database connected\n";
    $result = pg_query($connection, "SELECT 1");
    echo "✓ Query works\n";
} else {
    echo "✗ No database connection\n";
}
```

### Check OCR Setup
```bash
tesseract --version
which tesseract
tesseract /path/to/test/image.png - -l eng
```

### View Error Logs
```bash
# Linux
tail -f /var/log/apache2/error.log | grep EducAid

# Windows (IIS)
Get-Content C:\inetpub\logs\LogFiles\W3SVC1\*.log | tail -20
```

---

## 📊 Performance Tips

### 1. Reuse Service Instances
```php
<?php
// DON'T do this (creates new instance each time)
services('ocr')->process($file1);
services('ocr')->process($file2);

// DO this (reuses cached instance)
$ocr = services('ocr');
$ocr->process($file1);
$ocr->process($file2);
```

### 2. Use Optimized Autoloader
```bash
composer install --optimize-autoloader --no-dev
```

### 3. Cache Service Factory
```php
<?php
// Bootstrap once, reuse throughout request
require_once 'bootstrap_services.php';
// Factory is cached in $GLOBALS['__service_factory']
// All services() calls reuse it
```

### 4. Batch Database Operations
```php
<?php
$docs = services('documents');

// DON'T do this (separate saves)
foreach ($files as $file) {
    $docs->saveDocument($studentId, $type, $file, []);
}

// Better - batch if possible
// (Depends on service implementation)
```

---

## 🚨 Common Issues & Solutions

### Issue: "Class not found: App\Services\..."
**Solution:**
```bash
composer dump-autoload -o
```

### Issue: "Database connection not set"
**Solution:** Ensure global $connection is initialized before using services:
```php
<?php
global $connection;
$connection = pg_connect(...);
require_once 'bootstrap_services.php';
```

### Issue: "OTP email not sending"
**Solution:** Check environment variables:
```bash
echo $MAIL_HOST $MAIL_USERNAME $MAIL_PASSWORD
# Should output your SMTP credentials
```

### Issue: "OCR_BYPASS_ENABLED not working"
**Solution:** Define it before bootstrap:
```php
<?php
define('OCR_BYPASS_ENABLED', true);
require_once 'bootstrap_services.php';
```

### Issue: "Permission denied on file operations"
**Solution:** Check directory permissions:
```bash
chmod -R 755 assets/uploads/
chmod -R 755 assets/temp/
```

---

## 📈 Next Steps

### Immediate (Today)
- [x] Review this quick start guide
- [x] Review SERVICES_MIGRATION_GUIDE.md
- [ ] Run `composer install`
- [ ] Test each service

### Short Term (This Week)
- [ ] Integrate services into your application
- [ ] Replace old service instantiations with new ones
- [ ] Test OCR pipeline end-to-end
- [ ] Test all critical workflows

### Medium Term (This Month)
- [ ] Migrate remaining 30+ services
- [ ] Full system testing
- [ ] Performance testing
- [ ] Deploy to Railway

### Long Term (Future)
- [ ] Create Eloquent models
- [ ] Build API layer
- [ ] Add event broadcasting
- [ ] Implement queue jobs

---

## 📞 Getting Help

### Documentation
1. **Quick Start:** This file
2. **Full Guide:** `SERVICES_MIGRATION_GUIDE.md`
3. **Migration Status:** `MIGRATION_SUMMARY.md`
4. **Remaining Services:** `REMAINING_SERVICES_CHECKLIST.md`

### Code Examples
- `src/Services/OCRProcessingService.php` - Reference implementation
- `src/Services/ServiceFactory.php` - DI container example
- `bootstrap_services.php` - Service helpers

### Database Schema
Check your existing database for table structures:
- `students` - Student records
- `documents` - Document metadata
- `audit_logs` - Audit trail
- `distributions` - Distribution cycles
- `admin_otp_verifications` - OTP storage

---

## ✅ Verification Checklist

Before deploying, verify:

- [ ] `composer install` completes without errors
- [ ] `composer dump-autoload -o` runs successfully
- [ ] `bootstrap_services.php` is included in application
- [ ] All 6 services instantiate without errors
- [ ] Database connection is working
- [ ] OCR processing works (if Tesseract installed)
- [ ] Email sending works (if SMTP configured)
- [ ] Audit logging creates database records
- [ ] No "class not found" errors
- [ ] No "database connection" errors
- [ ] Services respond to method calls correctly
- [ ] Error handling works (graceful failures)

---

## 🎓 Learning Path

1. **Start Here:** This Quick Start Guide
2. **Understand:** Read SERVICES_MIGRATION_GUIDE.md sections 1-3
3. **Practice:** Test each service using examples above
4. **Deep Dive:** Read SERVICES_MIGRATION_GUIDE.md sections 4-8
5. **Extend:** Follow REMAINING_SERVICES_CHECKLIST.md to migrate more services
6. **Master:** Review src/Services/ code directly

---

**You're all set! Happy coding! 🚀**

Need help? Check the documentation files or review the working services in `src/Services/`.
