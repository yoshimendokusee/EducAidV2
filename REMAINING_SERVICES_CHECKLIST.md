# Remaining Services - Migration Checklist & Template

## Quick Reference for Migrating Remaining Services

This document provides a step-by-step template and checklist for migrating the remaining 30+ services using the established pattern.

---

## Migration Pattern Template

Use this template for **ALL** remaining services:

```php
<?php
namespace App\Services;

use App\Traits\UsesDatabaseConnection;
use Exception;

/**
 * ServiceNameHere
 * 
 * @package App\Services
 * @description Brief description of what this service does
 */
class ServiceNameHere {
    use UsesDatabaseConnection;
    
    private $config = [];
    
    /**
     * Constructor
     * 
     * @param resource|null $dbConnection Database connection (optional, uses global $connection if null)
     * @param array $config Configuration options
     */
    public function __construct($dbConnection = null, $config = [])
    {
        $this->setConnection($dbConnection);
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }
    
    /**
     * Get default configuration
     * 
     * @return array
     */
    private function getDefaultConfig()
    {
        return [
            // Add default config values here
        ];
    }
    
    /**
     * Public method example
     */
    public function publicMethod()
    {
        try {
            // Your business logic here
            return ['success' => true, 'data' => []];
        } catch (Exception $e) {
            error_log("ServiceNameHere::publicMethod Error: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Query example using trait
     */
    private function queryExample()
    {
        $query = "SELECT * FROM some_table WHERE id = $1";
        $result = $this->executeQuery($query, [123]);
        
        if ($result) {
            $rows = $this->fetchAll($result);
            return $rows;
        }
        return [];
    }
}
```

---

## Step-by-Step Migration Guide

### Step 1: Identify the Service
- [ ] Locate service file in `services/` directory
- [ ] Read the entire file to understand functionality
- [ ] Identify all public methods
- [ ] Identify all dependencies (database, files, other services)
- [ ] Note any database tables used
- [ ] Note any configuration constants

### Step 2: Create New File
- [ ] Create file: `src/Services/ServiceNameHere.php`
- [ ] Add namespace: `namespace App\Services;`
- [ ] Copy original class body

### Step 3: Add Trait
- [ ] Add: `use App\Traits\UsesDatabaseConnection;`
- [ ] Replace `global $connection;` with trait getter/setter
- [ ] Replace `pg_query()` with `$this->executeQuery()`
- [ ] Replace `pg_query_params()` with `$this->executeQuery()`
- [ ] Replace `pg_fetch_assoc()` with `$this->fetchOne()`
- [ ] Replace `pg_fetch_all()` with `$this->fetchAll()`
- [ ] Replace `pg_num_rows()` with result count logic

### Step 4: Update Imports
- [ ] Replace `require_once` with `use` statements
- [ ] Remove duplicate `require_once __DIR__ . '/../config/database.php'`
- [ ] Update any internal service calls to use new namespaces

### Step 5: Update Constructor
- [ ] Add `$dbConnection = null` parameter
- [ ] Add `$this->setConnection($dbConnection);` at start
- [ ] Test with and without connection parameter

### Step 6: Add Error Handling
- [ ] Wrap database operations in try-catch
- [ ] Add error_log() calls with class::method context
- [ ] Ensure exceptions have helpful messages
- [ ] Return meaningful error arrays for failed operations

### Step 7: Add Docstrings
- [ ] Add class-level docstring with @package tag
- [ ] Add method docstrings with @param and @return
- [ ] Document any special configuration options
- [ ] Add usage examples in comments

### Step 8: Update ServiceFactory
- [ ] Add `make*Service()` method to ServiceFactory
- [ ] Include configuration merging if needed
- [ ] Test factory instantiation
- [ ] Add to `services()` helper switch statement

### Step 9: Test
- [ ] Test direct instantiation
- [ ] Test factory instantiation
- [ ] Test with connection parameter
- [ ] Test with null connection (uses global)
- [ ] Test all public methods
- [ ] Verify error handling works

### Step 10: Document
- [ ] Add entry to `SERVICES_MIGRATION_GUIDE.md`
- [ ] Add usage example
- [ ] Document configuration options
- [ ] List key methods
- [ ] Note dependencies

---

## Checklist for Each Service

### (Copy and use for each service)

**Service Name:** ________________

**File:** `services/` → `src/Services/`

- [ ] Read original file completely
- [ ] Identify all public methods ________________
- [ ] Identify all database tables ________________
- [ ] Identify all dependencies ________________
- [ ] Create new file in src/Services/
- [ ] Add namespace App\Services
- [ ] Add UsesDatabaseConnection trait
- [ ] Update constructor with $dbConnection
- [ ] Replace require_once with use statements
- [ ] Replace pg_query with executeQuery
- [ ] Replace pg_fetch_assoc with fetchOne
- [ ] Replace pg_fetch_all with fetchAll
- [ ] Add error_log statements
- [ ] Add docstrings
- [ ] Add to ServiceFactory.php
- [ ] Add to services() helper
- [ ] Test direct instantiation
- [ ] Test factory instantiation
- [ ] Test all public methods
- [ ] Update migration guide
- [ ] Verify no 404/500 errors
- [ ] Ready for deployment ✓

---

## High Priority Services (Migrate Next)

### 1. DistributionManager.php (800+ lines)
**Purpose:** Distribution lifecycle, file compression, storage stats
**Files:** `services/DistributionManager.php`
**Difficulty:** HIGH (complex logic)
**Key Methods:**
- `endDistribution($distributionId, $adminId, $compressNow)`
- `getActiveDistributions()`
- `getEndedDistributions($includeArchived)`
- `getAllDistributions()`
- `getCompressionStatistics()`
- `getStorageStatistics()`
- `getRecentArchiveLog($limit)`

**Dependencies:**
- FileCompressionService
- DistributionIdGenerator
- FilePathConfig
- ZipArchive

**Database Tables:**
- distributions
- distribution_files
- distribution_snapshots
- distribution_student_snapshot
- students
- documents
- file_archive_log

**Estimated Time:** 1 hour

---

### 2. FileManagementService.php (150+ lines)
**Purpose:** Move files, manage directories
**Difficulty:** MEDIUM
**Key Methods:**
- File movement operations
- Directory management
- Permission handling

**Estimated Time:** 30 minutes

---

### 3. FileCompressionService.php
**Purpose:** ZIP file compression
**Difficulty:** MEDIUM
**Key Methods:**
- Compression workflows
- ZIP archive creation

**Estimated Time:** 30 minutes

---

### 4. NotificationService.php (150+ lines)
**Purpose:** System notifications
**Difficulty:** MEDIUM
**Key Methods:**
- Create notifications
- Send notifications
- Mark as read

**Estimated Time:** 30 minutes

---

### 5. StudentArchivalService.php
**Purpose:** Archive student files and records
**Difficulty:** MEDIUM
**Key Methods:**
- Archive student
- Restore student
- Get archived students

**Estimated Time:** 45 minutes

---

## Medium Priority Services

- EnrollmentFormOCRService.php - OCR for specific forms
- DocumentReuploadService.php - Reupload handling
- StudentEmailNotificationService.php - Student notifications
- DistributionEmailService.php - Distribution emails
- PayrollHistoryService.php - Payroll tracking
- BlacklistService.php - Blacklist management

---

## Low Priority Services (Can Migrate Later)

### Theme Services (5 services)
- ThemeGeneratorService.php
- HeaderThemeService.php
- SidebarThemeService.php
- FooterThemeService.php
- ColorGeneratorService.php

**Difficulty:** LOW (can be done in batch)
**Estimated Time:** 1 hour for all 5

---

## Helper/Utility Services

- save_login_content.php
- save_registration_grades.php
- toggle_section_visibility.php
- validate_pdf.php
- And remaining utilities

**Difficulty:** LOW (simple files)
**Estimated Time:** 1 hour total

---

## Testing Plan for Each Service

```php
<?php
// Test script template

require_once 'bootstrap_services.php';

// Test 1: Direct instantiation
try {
    $service = new \App\Services\ServiceNameHere();
    echo "✓ Direct instantiation works\n";
} catch (Exception $e) {
    echo "✗ Direct instantiation failed: " . $e->getMessage() . "\n";
}

// Test 2: Factory instantiation
try {
    $factory = services('factory');
    $service = $factory->makeServiceNameHere();
    echo "✓ Factory instantiation works\n";
} catch (Exception $e) {
    echo "✗ Factory instantiation failed: " . $e->getMessage() . "\n";
}

// Test 3: With database connection
try {
    global $connection;
    $service = new \App\Services\ServiceNameHere($connection);
    echo "✓ Connection parameter works\n";
} catch (Exception $e) {
    echo "✗ Connection parameter failed: " . $e->getMessage() . "\n";
}

// Test 4: Method calls
try {
    $result = $service->publicMethod();
    if ($result['success'] ?? false) {
        echo "✓ Method call successful\n";
    } else {
        echo "✗ Method call returned failure: " . json_encode($result) . "\n";
    }
} catch (Exception $e) {
    echo "✗ Method call threw exception: " . $e->getMessage() . "\n";
}

// Test 5: Error handling
try {
    $result = $service->publicMethod(-999); // Invalid input
    if (!($result['success'] ?? false)) {
        echo "✓ Error handling works\n";
    } else {
        echo "✗ Error handling didn't catch invalid input\n";
    }
} catch (Exception $e) {
    echo "✓ Exception thrown as expected\n";
}
```

---

## Quick Migration Commands

```bash
# Copy template to new service file
cp src/Services/OCRProcessingService.php src/Services/NewServiceName.php

# Search for old requires
grep -n "require_once.*services" src/Services/NewServiceName.php

# Find all pg_query calls
grep -n "pg_query" src/Services/NewServiceName.php

# Test autoloading
php -r "require 'vendor/autoload.php'; new \App\Services\NewServiceName();"

# Run full test
php tests/test_service.php NewServiceName
```

---

## Known Patterns to Replace

### Pattern 1: Database Connection
```php
// OLD
global $connection;
$result = pg_query($connection, $query);

// NEW
$result = $this->executeQuery($query, []);
```

### Pattern 2: Query with Parameters
```php
// OLD
$result = pg_query_params($conn, "SELECT * FROM table WHERE id = $1", [$id]);

// NEW
$result = $this->executeQuery("SELECT * FROM table WHERE id = $1", [$id]);
```

### Pattern 3: Fetch All
```php
// OLD
$rows = [];
while ($row = pg_fetch_assoc($result)) {
    $rows[] = $row;
}

// NEW
$rows = $this->fetchAll($result);
```

### Pattern 4: Fetch One
```php
// OLD
$row = pg_fetch_assoc($result);

// NEW
$row = $this->fetchOne($result);
```

### Pattern 5: Error Checking
```php
// OLD
if (!$result) {
    throw new Exception(pg_last_error($connection));
}

// NEW
// (Trait handles this automatically)
// If query fails, executeQuery throws Exception
```

### Pattern 6: Row Count
```php
// OLD
if (pg_num_rows($result) === 0) {
    // no rows
}

// NEW
$rows = $this->fetchAll($result);
if (count($rows) === 0) {
    // no rows
}
```

---

## Common Gotchas

1. **Global Connection** - Use trait getter, don't reference global directly
2. **Database Errors** - Let executeQuery throw exceptions, catch at service level
3. **Legacy require_once** - Use namespaces and `use` statements instead
4. **Static Methods** - Convert to instance methods for dependency injection
5. **Hardcoded Paths** - Use FilePathConfig for file paths
6. **Configuration** - Pass via constructor, don't use constants
7. **Service Dependencies** - Pass as constructor parameters or use factory
8. **Error Logging** - Include class::method in log messages for debugging

---

## Validation Checklist

- [ ] No require_once statements except in bootstrap
- [ ] All class methods updated to use trait
- [ ] All pg_query calls replaced with executeQuery
- [ ] Proper error handling with try-catch
- [ ] Docstrings on all public methods
- [ ] @package tag in class docstring
- [ ] Service added to ServiceFactory
- [ ] Service added to services() helper
- [ ] No undefined variable warnings
- [ ] No fatal errors on instantiation
- [ ] Database queries work with parameters
- [ ] Error messages are helpful
- [ ] Backward compatibility maintained
- [ ] Ready for code review

---

## Timeline Estimate

| Phase | Services | Hours | Status |
|-------|----------|-------|--------|
| Infrastructure | Factory, Trait, Bootstrap | 4 | ✓ DONE |
| Phase 1 | 6 Core Services | 6 | ✓ DONE |
| Phase 2 | 5 High Priority | 3 | ⏳ TODO |
| Phase 3 | 10 Medium Priority | 5 | ⏳ TODO |
| Phase 4 | 15 Low Priority | 3 | ⏳ TODO |
| Testing & QA | Full suite | 3 | ⏳ TODO |
| **TOTAL** | **All 40+ services** | **~24 hours** | ⏳ IN PROGRESS |

---

## Next Steps

1. Pick HIGH priority service from list
2. Follow Step-by-Step Migration Guide above
3. Use template provided
4. Run tests for each service
5. Update ServiceFactory
6. Repeat for next service

**All remaining services will follow THE EXACT SAME PATTERN.** No variations needed!

---

## Support & Questions

Refer to:
- `SERVICES_MIGRATION_GUIDE.md` - Complete usage guide
- `MIGRATION_SUMMARY.md` - Overall status
- `src/Services/OCRProcessingService.php` - Reference implementation
- `src/Traits/UsesDatabaseConnection.php` - Database operations
- `src/Services/ServiceFactory.php` - DI container
