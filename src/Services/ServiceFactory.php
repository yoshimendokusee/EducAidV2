<?php

namespace App\Services;

/**
 * ServiceFactory (Laravel Compatible)
 * 
 * Central factory for instantiating and managing application services
 * Provides convenient access to all migrated services with dependency injection
 * 
 * Usage:
 *   $factory = new ServiceFactory($dbConnection);
 *   $ocrService = $factory->makeOCRService();
 *   $documentService = $factory->makeDocumentService();
 * 
 * @package App\Services
 */
class ServiceFactory
{
    private $connection;
    private $services = [];

    /**
     * Initialize Service Factory
     * 
     * @param resource|null $dbConnection Database connection (optional, will use global if null)
     */
    public function __construct($dbConnection = null)
    {
        if ($dbConnection === null) {
            global $connection;
            $dbConnection = $connection;
        }

        $this->connection = $dbConnection;
    }

    /**
     * Get or create OCRProcessingService
     * 
     * @param array $config OCR configuration options
     * @return OCRProcessingService
     */
    public function makeOCRService($config = [])
    {
        $key = 'ocr_service';

        if (!isset($this->services[$key])) {
            $defaultConfig = [
                'tesseract_path' => getenv('TESSERACT_PATH') ?: 'tesseract',
                'temp_dir' => sys_get_temp_dir(),
                'max_file_size' => 10 * 1024 * 1024, // 10MB
                'allowed_extensions' => ['pdf', 'png', 'jpg', 'jpeg', 'tiff', 'bmp']
            ];

            $finalConfig = array_merge($defaultConfig, $config);
            $this->services[$key] = new OCRProcessingService($finalConfig);
        }

        return $this->services[$key];
    }

    /**
     * Get or create DocumentService
     * 
     * @return DocumentService
     */
    public function makeDocumentService()
    {
        $key = 'document_service';

        if (!isset($this->services[$key])) {
            $this->services[$key] = new DocumentService($this->connection);
        }

        return $this->services[$key];
    }

    /**
     * Get or create AuditLogger
     * 
     * @return AuditLogger
     */
    public function makeAuditLogger()
    {
        $key = 'audit_logger';

        if (!isset($this->services[$key])) {
            $this->services[$key] = new AuditLogger($this->connection);
        }

        return $this->services[$key];
    }

    /**
     * Get or create OTPService
     * 
     * @return OTPService
     */
    public function makeOTPService()
    {
        $key = 'otp_service';

        if (!isset($this->services[$key])) {
            $this->services[$key] = new OTPService($this->connection);
        }

        return $this->services[$key];
    }

    /**
     * Get or create GradeValidationService
     * 
     * @return GradeValidationService
     */
    public function makeGradeValidationService()
    {
        $key = 'grade_validation_service';

        if (!isset($this->services[$key])) {
            $this->services[$key] = new GradeValidationService($this->connection);
        }

        return $this->services[$key];
    }

    /**
     * Get or create DataExportService
     * 
     * @return DataExportService
     */
    public function makeDataExportService()
    {
        $key = 'data_export_service';

        if (!isset($this->services[$key])) {
            $this->services[$key] = new DataExportService($this->connection);
        }

        return $this->services[$key];
    }

    /**
     * Get database connection
     * 
     * @return resource PostgreSQL connection resource
     */
    public function getConnection()
    {
        return $this->connection;
    }

    /**
     * Set database connection
     * 
     * @param resource $connection PostgreSQL connection resource
     */
    public function setConnection($connection)
    {
        $this->connection = $connection;
        return $this;
    }

    /**
     * Clear all cached services
     */
    public function clearCache()
    {
        $this->services = [];
    }
}
