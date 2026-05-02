<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use App\Models\Student;
use App\Http\Controllers\AnalyticsController;
use App\Services\AnalyticsService;

/**
 * End-to-End Analytics System Testing
 * 
 * Tests all 9 analytics endpoints:
 * 1. GET /api/analytics/system-metrics
 * 2. GET /api/analytics/applications
 * 3. GET /api/analytics/documents
 * 4. GET /api/analytics/distributions
 * 5. GET /api/analytics/municipalities
 * 6. GET /api/analytics/performance
 * 7. GET /api/analytics/activity
 * 8. GET /api/analytics/timeseries
 * 9. GET /api/analytics/dashboard
 */
class AnalyticsTest extends TestCase
{
    protected $admin;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create admin session
        session(['admin_username' => 'admin_test']);
    }

    /**
     * Test 1: System Metrics Endpoint
     * Should return 10 core metrics
     */
    public function test_system_metrics_endpoint()
    {
        $response = $this->getJson('/api/analytics/system-metrics');
        
        $response->assertStatus(200)
            ->assertJsonStructure([
                'ok',
                'data' => [
                    'total_students',
                    'active_applicants',
                    'approved_applicants',
                    'rejected_applicants',
                    'total_documents',
                    'pending_documents',
                    'total_distributions',
                    'active_distributions',
                    'total_notifications',
                    'unread_notifications',
                ],
                'timestamp'
            ]);

        $this->assertTrue($response['ok']);
        $this->assertIsNumeric($response['data']['total_students']);
        $this->assertIsNumeric($response['data']['active_applicants']);
    }

    /**
     * Test 2: Application Distribution Endpoint
     * Should return status breakdown (applicant, approved, rejected, enrolled, other)
     */
    public function test_application_distribution_endpoint()
    {
        $response = $this->getJson('/api/analytics/applications');
        
        $response->assertStatus(200)
            ->assertJsonStructure([
                'ok',
                'data' => [
                    'applicant',
                    'approved',
                    'rejected',
                    'enrolled',
                    'other'
                ],
                'timestamp'
            ]);

        $this->assertTrue($response['ok']);
        $data = $response['data'];
        $this->assertIsNumeric($data['applicant']);
        $this->assertIsNumeric($data['approved']);
        $this->assertIsNumeric($data['rejected']);
    }

    /**
     * Test 3: Document Status Endpoint
     * Should return document verification status breakdown
     */
    public function test_document_status_endpoint()
    {
        $response = $this->getJson('/api/analytics/documents');
        
        $response->assertStatus(200)
            ->assertJsonStructure([
                'ok',
                'data' => [
                    'pending',
                    'approved',
                    'rejected',
                    'verified'
                ],
                'timestamp'
            ]);

        $this->assertTrue($response['ok']);
        $data = $response['data'];
        $this->assertIsNumeric($data['pending']);
        $this->assertIsNumeric($data['approved']);
    }

    /**
     * Test 4: Distribution Stats Endpoint
     * Should return distribution metrics and success rate
     */
    public function test_distribution_stats_endpoint()
    {
        $response = $this->getJson('/api/analytics/distributions');
        
        $response->assertStatus(200)
            ->assertJsonStructure([
                'ok',
                'data' => [
                    'status_breakdown',
                    'total_distributed',
                    'average_per_distribution',
                    'success_rate_percent'
                ],
                'timestamp'
            ]);

        $this->assertTrue($response['ok']);
        $data = $response['data'];
        $this->assertIsArray($data['status_breakdown']);
        $this->assertIsNumeric($data['success_rate_percent']);
    }

    /**
     * Test 5: Top Municipalities Endpoint
     * Should return top municipalities by student count
     */
    public function test_municipalities_endpoint()
    {
        $response = $this->getJson('/api/analytics/municipalities');
        
        $response->assertStatus(200)
            ->assertJsonStructure([
                'ok',
                'data' => [
                    '*' => ['name', 'count']
                ],
                'timestamp'
            ]);

        $this->assertTrue($response['ok']);
        $this->assertIsArray($response['data']);
    }

    /**
     * Test 6: Performance Metrics Endpoint
     * Should return API and DB performance data
     */
    public function test_performance_metrics_endpoint()
    {
        $response = $this->getJson('/api/analytics/performance');
        
        $response->assertStatus(200)
            ->assertJsonStructure([
                'ok',
                'data' => [
                    'avg_processing_days',
                    'documents_today',
                    'avg_documents_per_student',
                    'system_uptime_percent',
                    'api_response_time_ms',
                    'database_query_time_ms'
                ],
                'timestamp'
            ]);

        $this->assertTrue($response['ok']);
        $data = $response['data'];
        $this->assertIsNumeric($data['avg_processing_days']);
        $this->assertIsNumeric($data['system_uptime_percent']);
    }

    /**
     * Test 7: Activity Summary Endpoint
     * Should return today's activity metrics
     */
    public function test_activity_summary_endpoint()
    {
        $response = $this->getJson('/api/analytics/activity');
        
        $response->assertStatus(200)
            ->assertJsonStructure([
                'ok',
                'data' => [
                    'student_logins_today',
                    'documents_uploaded_today',
                    'applications_submitted_today',
                    'notifications_sent_today',
                    'support_tickets_open',
                    'average_page_load_ms'
                ],
                'timestamp'
            ]);

        $this->assertTrue($response['ok']);
        $data = $response['data'];
        $this->assertIsNumeric($data['student_logins_today']);
        $this->assertIsNumeric($data['notifications_sent_today']);
    }

    /**
     * Test 8: Time Series Data Endpoint
     * Should return 30-day trend data
     */
    public function test_timeseries_endpoint()
    {
        $response = $this->getJson('/api/analytics/timeseries?metric=applications&days=30');
        
        $response->assertStatus(200)
            ->assertJsonStructure([
                'ok',
                'data' => [
                    '*' => ['date', 'value', 'fullDate']
                ],
                'timestamp'
            ]);

        $this->assertTrue($response['ok']);
        $this->assertIsArray($response['data']);
        $this->assertCount(30, $response['data']);
    }

    /**
     * Test 9: Dashboard Comprehensive Data Endpoint
     * Should return all metrics in one call
     */
    public function test_dashboard_endpoint()
    {
        $response = $this->getJson('/api/analytics/dashboard');
        
        $response->assertStatus(200)
            ->assertJsonStructure([
                'ok',
                'data' => [
                    'system_metrics',
                    'application_distribution',
                    'document_status',
                    'distribution_stats',
                    'performance_metrics',
                    'activity_summary',
                    'timeseries_applications',
                    'timeseries_documents',
                    'top_municipalities'
                ],
                'timestamp'
            ]);

        $this->assertTrue($response['ok']);
        $data = $response['data'];
        $this->assertIsArray($data['system_metrics']);
        $this->assertIsArray($data['performance_metrics']);
        $this->assertIsArray($data['top_municipalities']);
    }

    /**
     * Test 10: Non-Admin User Cannot Access Analytics
     * Should return 403 Forbidden for non-admin users
     */
    public function test_analytics_requires_admin()
    {
        // Clear admin session
        session()->forget('admin_username');
        
        $response = $this->getJson('/api/analytics/system-metrics');
        $response->assertStatus(403);
        $this->assertFalse($response['ok']);
    }

    /**
     * Test 11: Analytics Service Direct Test
     * Test AnalyticsService methods directly
     */
    public function test_analytics_service_methods()
    {
        $service = new AnalyticsService();

        // Test getSystemMetrics
        $metrics = $service->getSystemMetrics();
        $this->assertIsArray($metrics);
        $this->assertArrayHasKey('total_students', $metrics);

        // Test getApplicationDistribution
        $apps = $service->getApplicationDistribution();
        $this->assertIsArray($apps);
        $this->assertArrayHasKey('applicant', $apps);

        // Test getDocumentStatus
        $docs = $service->getDocumentStatus();
        $this->assertIsArray($docs);
        $this->assertArrayHasKey('pending', $docs);

        // Test getPerformanceMetrics
        $perf = $service->getPerformanceMetrics();
        $this->assertIsArray($perf);
        $this->assertArrayHasKey('api_response_time_ms', $perf);

        // Test getTimeSeriesData
        $timeseries = $service->getTimeSeriesData('applications', 30);
        $this->assertIsArray($timeseries);
        $this->assertCount(30, $timeseries);

        // Test getDashboardData
        $dashboard = $service->getDashboardData();
        $this->assertIsArray($dashboard);
        $this->assertArrayHasKey('system_metrics', $dashboard);
        $this->assertArrayHasKey('timestamp', $dashboard);
    }

    /**
     * Test 12: Data Consistency
     * Verify that dashboard data matches individual endpoints
     */
    public function test_data_consistency()
    {
        // Get dashboard data
        $dashboardResponse = $this->getJson('/api/analytics/dashboard');
        $dashboard = $dashboardResponse['data'];

        // Get individual metrics
        $metricsResponse = $this->getJson('/api/analytics/system-metrics');
        $metrics = $metricsResponse['data'];

        // Compare - system metrics should match
        $this->assertEquals(
            $dashboard['system_metrics']['total_students'],
            $metrics['total_students']
        );
        $this->assertEquals(
            $dashboard['system_metrics']['active_applicants'],
            $metrics['active_applicants']
        );
    }

    /**
     * Test 13: Invalid Metric Parameter
     * Should handle invalid metric gracefully
     */
    public function test_timeseries_invalid_metric()
    {
        $response = $this->getJson('/api/analytics/timeseries?metric=invalid_metric&days=30');
        
        // Should either return empty array or error message
        if ($response['ok']) {
            $this->assertIsArray($response['data']);
        } else {
            $this->assertFalse($response['ok']);
        }
    }

    /**
     * Test 14: Date Range Variations
     * Should handle different date ranges correctly
     */
    public function test_timeseries_date_ranges()
    {
        // Test 7 days
        $response7 = $this->getJson('/api/analytics/timeseries?metric=applications&days=7');
        $this->assertTrue($response7['ok']);
        $this->assertCount(7, $response7['data']);

        // Test 60 days
        $response60 = $this->getJson('/api/analytics/timeseries?metric=documents&days=60');
        $this->assertTrue($response60['ok']);
        $this->assertCount(60, $response60['data']);
    }
}
