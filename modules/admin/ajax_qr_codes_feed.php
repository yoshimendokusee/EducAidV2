<?php
// AJAX feed for live QR code status updates
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['admin_username'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/database.php';

$barangay = $_GET['barangay'] ?? '';
$search = trim($_GET['search'] ?? '');
$limit = 200; // reasonable upper bound

$query = "SELECT s.student_id, s.first_name, s.middle_name, s.last_name, s.payroll_no, b.name AS barangay, q.unique_id AS qr_unique_id, q.status AS qr_status
          FROM students s
          JOIN barangays b ON s.barangay_id = b.barangay_id
          LEFT JOIN qr_codes q ON s.student_id = q.student_id AND s.payroll_no = q.payroll_number
          WHERE s.status = 'active' AND s.payroll_no IS NOT NULL AND q.unique_id IS NOT NULL";
$params = [];
$idx = 1;
if ($barangay !== '') { $query .= " AND b.barangay_id = $" . $idx; $params[] = $barangay; $idx++; }
if ($search !== '') { $query .= " AND (s.last_name ILIKE $" . $idx . " OR s.first_name ILIKE $" . $idx . ")"; $params[] = "%$search%"; $idx++; }
$query .= " ORDER BY CASE WHEN q.status ILIKE 'pending' THEN 0 ELSE 1 END, s.last_name ASC, s.first_name ASC LIMIT $limit";

$result = !empty($params) ? pg_query_params($connection, $query, $params) : pg_query($connection, $query);
if (!$result) {
    echo json_encode(['error' => pg_last_error($connection)]);
    exit;
}
$rows = [];
while ($row = pg_fetch_assoc($result)) {
    $rows[] = [
        'student_id' => $row['student_id'],
        'full_name' => trim($row['first_name'] . ' ' . ($row['middle_name'] ?? '') . ' ' . $row['last_name']),
        'barangay' => $row['barangay'],
        'payroll_no' => $row['payroll_no'],
        'qr_unique_id' => $row['qr_unique_id'],
        'qr_status' => $row['qr_status']
    ];
}
pg_free_result($result);
pg_close($connection);
echo json_encode(['data' => $rows, 'timestamp' => time()]);
