<?php
/**
 * EducAid Services Bootstrap Loader
 * 
 * This file initializes and sets up all migrated services for use throughout the application.
 * Include this file in your bootstrap or entry point.
 * 
 * Usage:
 *   require_once __DIR__ . '/bootstrap_services.php';
 *   $ocr = services('ocr');
 *   $docs = services('documents');
 */

// Ensure Composer autoloader is loaded first
if (!function_exists('app')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

use App\Services\ServiceFactory;

// Backward-compatible aliases for migrated services.
// This lets legacy entry points continue using old class names while the codebase moves to namespaced services.
class_exists('App\\Services\\UnifiedFileService');
class_exists('App\\Services\\FileManagementService');
class_exists('App\\Services\\FileCompressionService');
class_alias('App\\Services\\OCRProcessingService', 'OCRProcessingService');
class_alias('App\\Services\\DocumentService', 'DocumentService');
class_alias('App\\Services\\AuditLogger', 'AuditLogger');
class_alias('App\\Services\\OTPService', 'OTPService');
class_alias('App\\Services\\GradeValidationService', 'GradeValidationService');
class_alias('App\\Services\\DataExportService', 'DataExportService');
class_alias('App\\Services\\UnifiedFileService', 'UnifiedFileService');
class_alias('App\\Services\\FileManagementService', 'FileManagementService');
class_alias('App\\Services\\FileCompressionService', 'FileCompressionService');

/**
 * Global service container instance
 */
$GLOBALS['__service_factory'] = null;

/**
 * Initialize services with database connection
 * 
 * @param resource $dbConnection Database connection
 */
function initializeServices($dbConnection = null)
{
    if ($dbConnection === null) {
        global $connection;
        $dbConnection = $connection;
    }

    $GLOBALS['__service_factory'] = new ServiceFactory($dbConnection);

    error_log("Services bootstrap: Initialized ServiceFactory");
}

/**
 * Get a service by name (convenience function)
 * 
 * @param string $serviceName Service name (ocr, documents, audit, otp, validation, export)
 * @return mixed Service instance or null if not found
 */
function services($serviceName)
{
    if (empty($GLOBALS['__service_factory'])) {
        // Auto-initialize if not already done
        initializeServices();
    }

    $factory = $GLOBALS['__service_factory'];

    switch (strtolower($serviceName)) {
        case 'ocr':
        case 'ocr_processing':
            return $factory->makeOCRService();

        case 'document':
        case 'documents':
        case 'doc_service':
            return $factory->makeDocumentService();

        case 'audit':
        case 'audit_log':
        case 'audit_logger':
            return $factory->makeAuditLogger();

        case 'otp':
        case 'otp_service':
            return $factory->makeOTPService();

        case 'grade':
        case 'grades':
        case 'validation':
        case 'grade_validation':
            return $factory->makeGradeValidationService();

        case 'export':
        case 'data_export':
            return $factory->makeDataExportService();

        case 'factory':
            return $factory;

        default:
            error_log("Unknown service requested: {$serviceName}");
            return null;
    }
}

/**
 * Helper: Log to audit trail
 * 
 * @param string $eventType Event type
 * @param string $category Event category
 * @param string $description Description
 * @param string $userType User type (admin, student, system)
 * @param string $username Username
 * @param int $userId User ID (optional)
 * @param array $metadata Metadata (optional)
 */
function audit_log($eventType, $category, $description, $userType, $username, $userId = null, $metadata = null)
{
    $logger = services('audit');
    if ($logger) {
        return $logger->log(
            $eventType,
            $category,
            $description,
            $userType,
            $username,
            $userId,
            'success',
            $metadata
        );
    }
    return false;
}

/**
 * Helper: Process document with OCR
 * 
 * @param string $filePath File path
 * @param array $options OCR options
 * @return array Result
 */
function process_ocr($filePath, $options = [])
{
    $ocr = services('ocr');
    if ($ocr) {
        return $ocr->extractTextAndConfidence($filePath, $options);
    }
    return ['success' => false, 'error' => 'OCR Service not available'];
}

/**
 * Helper: Save document
 * 
 * @param string $studentId Student ID
 * @param string $docType Document type
 * @param string $filePath File path
 * @param array $ocrData OCR data
 * @return array Result
 */
function save_document($studentId, $docType, $filePath, $ocrData = [])
{
    $docs = services('documents');
    if ($docs) {
        return $docs->saveDocument($studentId, $docType, $filePath, $ocrData);
    }
    return ['success' => false, 'error' => 'Document Service not available'];
}

/**
 * Helper: Validate grades
 * 
 * @param string $universityKey University key
 * @param array $subjects Subjects array
 * @return array Validation result
 */
function validate_grades($universityKey, $subjects)
{
    $validator = services('validation');
    if ($validator) {
        return $validator->validateApplicant($universityKey, $subjects);
    }
    return ['eligible' => false, 'error' => 'Validation Service not available'];
}

/**
 * Helper: Send OTP
 * 
 * @param string $email Email address
 * @param string $purpose Purpose
 * @param int $adminId Admin ID
 * @return bool
 */
function send_otp($email, $purpose, $adminId = null)
{
    $otp = services('otp');
    if ($otp) {
        return $otp->sendOTP($email, $purpose, $adminId);
    }
    return false;
}

/**
 * Helper: Verify OTP
 * 
 * @param int $adminId Admin ID
 * @param string $otp OTP code
 * @param string $purpose Purpose
 * @return bool
 */
function verify_otp($adminId, $otp, $purpose)
{
    $otpService = services('otp');
    if ($otpService) {
        return $otpService->verifyOTP($adminId, $otp, $purpose);
    }
    return false;
}

/**
 * Helper: Export student data
 * 
 * @param string $studentId Student ID
 * @return array Result
 */
function export_student_data($studentId)
{
    $export = services('export');
    if ($export) {
        return $export->buildExport($studentId);
    }
    return ['success' => false, 'error' => 'Export Service not available'];
}

/**
 * Migrate legacy service instantiation to new system
 * 
 * @param string $legacyClassName Legacy class name
 * @return mixed Service instance or null
 */
function get_migrated_service($legacyClassName)
{
    $mapping = [
        'OCRProcessingService' => 'ocr',
        'DocumentService' => 'documents',
        'AuditLogger' => 'audit',
        'OTPService' => 'otp',
        'GradeValidationService' => 'validation',
        'DataExportService' => 'export'
    ];

    $serviceName = $mapping[$legacyClassName] ?? null;
    if ($serviceName) {
        return services($serviceName);
    }

    error_log("Warning: No migration found for legacy service: {$legacyClassName}");
    return null;
}

/**
 * Compatibility wrapper for legacy code
 * 
 * Usage: $service = get_legacy_service('OCRProcessingService');
 * Returns the new migrated service
 */
function get_legacy_service($className)
{
    return get_migrated_service($className);
}

// Auto-initialize services if database connection is available
if (isset($GLOBALS['connection']) || (function_exists('getenv') && getenv('DATABASE_PUBLIC_URL'))) {
    if (!isset($GLOBALS['__service_factory'])) {
        // Attempt auto-initialization
        if (isset($GLOBALS['connection'])) {
            initializeServices($GLOBALS['connection']);
        } else {
            initializeServices(null); // Will use global $connection
        }
    }
}

error_log("Services bootstrap loader initialized");
