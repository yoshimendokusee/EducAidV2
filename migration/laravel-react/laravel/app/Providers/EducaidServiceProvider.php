<?php

namespace App\Providers;

use App\Services\AuditLogService;
use App\Services\BlacklistService;
use App\Services\DistributionService;
use App\Services\DistributionEmailService;
use App\Services\DistributionManager;
use App\Services\DocumentReuploadService;
use App\Services\EmailNotificationService;
use App\Services\EnrollmentFormOCRService;
use App\Services\FileCompressionService;
use App\Services\FileUploadService;
use App\Services\MediaEncryptionService;
use App\Services\NotificationService;
use App\Services\OcrProcessingService;
use App\Services\PayrollHistoryService;
use App\Services\StudentArchivalService;
use App\Services\StudentEmailNotificationService;
use App\Services\StudentNotificationService;
use App\Services\ThemeService;
use App\Services\UnifiedFileService;
use Illuminate\Support\ServiceProvider;

class EducaidServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Core encryption service
        $this->app->singleton(MediaEncryptionService::class, function ($app) {
            return new MediaEncryptionService();
        });

        // OCR service
        $this->app->singleton(OcrProcessingService::class, function ($app) {
            return new OcrProcessingService();
        });

        // File handling services
        $this->app->singleton(FileUploadService::class, function ($app) {
            return new FileUploadService();
        });

        $this->app->singleton(UnifiedFileService::class, function ($app) {
            return new UnifiedFileService($app->make(OcrProcessingService::class));
        });

        $this->app->singleton(FileCompressionService::class, function ($app) {
            return new FileCompressionService();
        });

        $this->app->singleton(DocumentReuploadService::class, function ($app) {
            return new DocumentReuploadService($app->make(OcrProcessingService::class));
        });

        // Student lifecycle services
        $this->app->singleton(StudentArchivalService::class, function ($app) {
            return new StudentArchivalService();
        });

        $this->app->singleton(BlacklistService::class, function ($app) {
            return new BlacklistService();
        });

        // Payroll and distribution services
        $this->app->singleton(PayrollHistoryService::class, function ($app) {
            return new PayrollHistoryService();
        });

        $this->app->singleton(DistributionService::class, function ($app) {
            return new DistributionService();
        });

        $this->app->singleton(DistributionManager::class, function ($app) {
            return new DistributionManager($app->make(FileCompressionService::class));
        });

        $this->app->singleton(DistributionEmailService::class, function ($app) {
            return new DistributionEmailService();
        });

        // Notification and audit services
        $this->app->singleton(StudentEmailNotificationService::class, function ($app) {
            return new StudentEmailNotificationService();
        });

        $this->app->singleton(NotificationService::class, function ($app) {
            return new NotificationService();
        });

        $this->app->singleton(AuditLogService::class, function ($app) {
            return new AuditLogService();
        });

        // OCR and form services
        $this->app->singleton(EnrollmentFormOCRService::class, function ($app) {
            return new EnrollmentFormOCRService($app->make(OcrProcessingService::class));
        });

        $this->app->singleton(EmailNotificationService::class, function ($app) {
            return new EmailNotificationService();
        });

        $this->app->singleton(AuditLogService::class, function ($app) {
            return new AuditLogService();
        });

        // UI/Theme service
        $this->app->singleton(ThemeService::class, function ($app) {
            return new ThemeService();
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
