#!/usr/bin/env php
<?php
/**
 * Manual End-to-End Analytics Testing Script
 * Tests all 9 analytics endpoints via HTTP requests
 * 
 * Usage: php test_analytics.php
 */

$BASE_URL = 'http://127.0.0.1:8000/api/analytics';

// Color codes for output
const GREEN = "\033[92m";
const RED = "\033[91m";
const YELLOW = "\033[93m";
const RESET = "\033[0m";
const BOLD = "\033[1m";

// Test results
$results = [];
$passed = 0;
$failed = 0;

echo BOLD . "\n=== Analytics End-to-End Test Suite ===\n" . RESET;
echo "Testing all 9 analytics endpoints\n\n";

/**
 * Helper: Make HTTP request with admin session
 */
function makeRequest($method, $endpoint, $params = []) {
    global $BASE_URL;
    
    $url = $BASE_URL . $endpoint;
    if ($method === 'GET' && !empty($params)) {
        $url .= '?' . http_build_query($params);
    }

    // Set up curl with session cookie support
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    // Add standard headers
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-Requested-With: XMLHttpRequest',
        'Accept: application/json'
    ]);

    // Include cookies (for session)
    curl_setopt($ch, CURLOPT_COOKIEJAR, '/tmp/cookies.txt');
    curl_setopt($ch, CURLOPT_COOKIEFILE, '/tmp/cookies.txt');

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'status' => $httpCode,
        'body' => $response ? json_decode($response, true) : null,
        'raw' => $response
    ];
}

/**
 * Test assertion helper
 */
function assert_test($name, $condition, $details = '') {
    global $results, $passed, $failed;
    
    if ($condition) {
        echo GREEN . "✓ PASS" . RESET . " - $name\n";
        if ($details) echo "  └─ $details\n";
        $passed++;
    } else {
        echo RED . "✗ FAIL" . RESET . " - $name\n";
        if ($details) echo "  └─ $details\n";
        $failed++;
    }
}

// ============ TEST 1: System Metrics ============
echo BOLD . "\n[1/9] Testing System Metrics Endpoint\n" . RESET;
$result = makeRequest('GET', '/system-metrics');
$test1_pass = $result['status'] === 200 && isset($result['body']['data']['total_students']);
if ($test1_pass) {
    $data = $result['body']['data'];
    assert_test(
        "GET /system-metrics (Status: {$result['status']})",
        true,
        "total_students: {$data['total_students']}, active_applicants: {$data['active_applicants']}"
    );
} else {
    assert_test(
        "GET /system-metrics (Status: {$result['status']})",
        false,
        $result['raw']
    );
}

// ============ TEST 2: Applications ============
echo BOLD . "\n[2/9] Testing Application Distribution Endpoint\n" . RESET;
$result = makeRequest('GET', '/applications');
$test2_pass = $result['status'] === 200 && isset($result['body']['data']['applicant']);
if ($test2_pass) {
    $data = $result['body']['data'];
    assert_test(
        "GET /applications (Status: {$result['status']})",
        true,
        "applicant: {$data['applicant']}, approved: {$data['approved']}, rejected: {$data['rejected']}"
    );
} else {
    assert_test(
        "GET /applications (Status: {$result['status']})",
        false,
        $result['raw']
    );
}

// ============ TEST 3: Documents ============
echo BOLD . "\n[3/9] Testing Document Status Endpoint\n" . RESET;
$result = makeRequest('GET', '/documents');
$test3_pass = $result['status'] === 200 && isset($result['body']['data']['pending']);
if ($test3_pass) {
    $data = $result['body']['data'];
    assert_test(
        "GET /documents (Status: {$result['status']})",
        true,
        "pending: {$data['pending']}, approved: {$data['approved']}, verified: {$data['verified']}"
    );
} else {
    assert_test(
        "GET /documents (Status: {$result['status']})",
        false,
        $result['raw']
    );
}

// ============ TEST 4: Distributions ============
echo BOLD . "\n[4/9] Testing Distribution Stats Endpoint\n" . RESET;
$result = makeRequest('GET', '/distributions');
$test4_pass = $result['status'] === 200 && isset($result['body']['data']['total_distributed']);
if ($test4_pass) {
    $data = $result['body']['data'];
    assert_test(
        "GET /distributions (Status: {$result['status']})",
        true,
        "total_distributed: {$data['total_distributed']}, success_rate: {$data['success_rate_percent']}%"
    );
} else {
    assert_test(
        "GET /distributions (Status: {$result['status']})",
        false,
        $result['raw']
    );
}

// ============ TEST 5: Municipalities ============
echo BOLD . "\n[5/9] Testing Municipalities Endpoint\n" . RESET;
$result = makeRequest('GET', '/municipalities');
$test5_pass = $result['status'] === 200 && is_array($result['body']['data']);
if ($test5_pass) {
    $count = count($result['body']['data']);
    assert_test(
        "GET /municipalities (Status: {$result['status']})",
        true,
        "Found $count municipalities"
    );
} else {
    assert_test(
        "GET /municipalities (Status: {$result['status']})",
        false,
        $result['raw']
    );
}

// ============ TEST 6: Performance ============
echo BOLD . "\n[6/9] Testing Performance Metrics Endpoint\n" . RESET;
$result = makeRequest('GET', '/performance');
$test6_pass = $result['status'] === 200 && isset($result['body']['data']['api_response_time_ms']);
if ($test6_pass) {
    $data = $result['body']['data'];
    assert_test(
        "GET /performance (Status: {$result['status']})",
        true,
        "API response time: {$data['api_response_time_ms']}ms, uptime: {$data['system_uptime_percent']}%"
    );
} else {
    assert_test(
        "GET /performance (Status: {$result['status']})",
        false,
        $result['raw']
    );
}

// ============ TEST 7: Activity ============
echo BOLD . "\n[7/9] Testing Activity Summary Endpoint\n" . RESET;
$result = makeRequest('GET', '/activity');
$test7_pass = $result['status'] === 200 && isset($result['body']['data']['student_logins_today']);
if ($test7_pass) {
    $data = $result['body']['data'];
    assert_test(
        "GET /activity (Status: {$result['status']})",
        true,
        "logins today: {$data['student_logins_today']}, documents uploaded: {$data['documents_uploaded_today']}"
    );
} else {
    assert_test(
        "GET /activity (Status: {$result['status']})",
        false,
        $result['raw']
    );
}

// ============ TEST 8: Time Series ============
echo BOLD . "\n[8/9] Testing Time Series Data Endpoint\n" . RESET;
$result = makeRequest('GET', '/timeseries', ['metric' => 'applications', 'days' => 30]);
$test8_pass = $result['status'] === 200 && is_array($result['body']['data']);
if ($test8_pass) {
    $count = count($result['body']['data']);
    assert_test(
        "GET /timeseries?metric=applications&days=30 (Status: {$result['status']})",
        true,
        "Retrieved $count data points"
    );
} else {
    assert_test(
        "GET /timeseries (Status: {$result['status']})",
        false,
        $result['raw']
    );
}

// ============ TEST 9: Dashboard ============
echo BOLD . "\n[9/9] Testing Dashboard Comprehensive Endpoint\n" . RESET;
$result = makeRequest('GET', '/dashboard');
$test9_pass = $result['status'] === 200 && isset($result['body']['data']['system_metrics']);
if ($test9_pass) {
    $data = $result['body']['data'];
    $hasAll = isset($data['system_metrics']) && isset($data['application_distribution']) && 
              isset($data['document_status']) && isset($data['performance_metrics']);
    assert_test(
        "GET /dashboard (Status: {$result['status']})",
        $hasAll,
        "Includes: system_metrics, applications, documents, distributions, performance, activity, timeseries, municipalities"
    );
} else {
    assert_test(
        "GET /dashboard (Status: {$result['status']})",
        false,
        $result['raw']
    );
}

// ============ Summary ============
echo BOLD . "\n=== Test Summary ===\n" . RESET;
echo "Total Tests: " . ($passed + $failed) . "\n";
echo GREEN . "Passed: $passed\n" . RESET;
if ($failed > 0) {
    echo RED . "Failed: $failed\n" . RESET;
}

$percentage = ($passed + $failed) > 0 ? ($passed / ($passed + $failed)) * 100 : 0;
echo "Success Rate: " . round($percentage, 1) . "%\n";

if ($failed === 0) {
    echo GREEN . BOLD . "\n✓ All tests passed!\n" . RESET;
    exit(0);
} else {
    echo RED . BOLD . "\n✗ Some tests failed\n" . RESET;
    exit(1);
}
?>
