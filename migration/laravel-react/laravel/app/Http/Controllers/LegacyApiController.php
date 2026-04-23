<?php

namespace App\Http\Controllers;

use App\Services\LegacyScriptRunner;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LegacyApiController extends Controller
{
    public function __construct(private readonly LegacyScriptRunner $runner)
    {
    }

    // Migrated from old file: api/student/get_notification_count.php
    public function getNotificationCount(Request $request): Response
    {
        return $this->runner->run($request, 'api/student/get_notification_count.php');
    }

    // Migrated from old file: api/student/get_notification_preferences.php
    public function getNotificationPreferences(Request $request): Response
    {
        return $this->runner->run($request, 'api/student/get_notification_preferences.php');
    }

    // Migrated from old file: api/student/save_notification_preferences.php
    public function saveNotificationPreferences(Request $request): Response
    {
        return $this->runner->run($request, 'api/student/save_notification_preferences.php');
    }

    // Migrated from old file: api/reports/generate_report.php
    public function generateReport(Request $request): Response
    {
        return $this->runner->run($request, 'api/reports/generate_report.php');
    }

    // Migrated from old file: api/eligibility/subject-check.php
    public function subjectCheck(Request $request): Response
    {
        return $this->runner->run($request, 'api/eligibility/subject-check.php');
    }

    public function ajax(Request $request, string $path): Response
    {
        return $this->runner->run($request, $path);
    }
}
