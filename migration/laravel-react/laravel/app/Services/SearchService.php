<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * SearchService - Advanced Search & Filtering
 * 
 * Provides full-text search and advanced filtering for:
 * - Applicants (by name, email, status, municipality)
 * - Distributions (by name, status, date range)
 * - Documents (by type, status, student)
 * - Students (by ID, name, contact info)
 * 
 * Features:
 * - Full-text search across multiple fields
 * - Multi-field filtering with AND/OR logic
 * - Pagination support
 * - Sorting by multiple columns
 * - Date range filtering
 * - Export-ready data formatting
 */
class SearchService
{
    /**
     * Search applicants with advanced filtering
     * 
     * @param array $filters {
     *   'search': string - Full-text search across name, email, phone
     *   'status': string|array - Filter by application status
     *   'municipality': string|array - Filter by municipality
     *   'year_level': int - Filter by year level
     *   'date_from': string - Filter by application date (YYYY-MM-DD)
     *   'date_to': string - Filter by application date (YYYY-MM-DD)
     *   'verified': bool - Filter by verification status
     *   'sort_by': string - Sort column (name, created_at, status)
     *   'sort_order': string - ASC or DESC
     *   'page': int - Pagination (1-based)
     *   'per_page': int - Results per page (default 20, max 100)
     * }
     * @return array {ok, data: [...], total, page, per_page, timestamp}
     */
    public function searchApplicants(array $filters = []): array
    {
        try {
            $page = $filters['page'] ?? 1;
            $perPage = min($filters['per_page'] ?? 20, 100);
            $offset = ($page - 1) * $perPage;
            $search = $filters['search'] ?? null;
            $status = $filters['status'] ?? null;
            $municipality = $filters['municipality'] ?? null;
            $yearLevel = $filters['year_level'] ?? null;
            $dateFrom = $filters['date_from'] ?? null;
            $dateTo = $filters['date_to'] ?? null;
            $verified = $filters['verified'] ?? null;
            $sortBy = $filters['sort_by'] ?? 'created_at';
            $sortOrder = strtoupper($filters['sort_order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

            // Build query
            $query = DB::selectOne(
                "
                SELECT COUNT(*) as count FROM (
                    SELECT s.id FROM students s
                    LEFT JOIN municipalities m ON s.municipality_id = m.id
                    WHERE 1=1
                    " . ($search ? "AND (s.first_name ILIKE :search OR s.last_name ILIKE :search OR s.email ILIKE :search OR s.phone ILIKE :search)" : "") . "
                    " . ($status ? "AND s.application_status = :status" : "") . "
                    " . ($municipality ? "AND s.municipality_id = :municipality" : "") . "
                    " . ($yearLevel ? "AND s.year_level = :year_level" : "") . "
                    " . ($dateFrom ? "AND DATE(s.created_at) >= :date_from" : "") . "
                    " . ($dateTo ? "AND DATE(s.created_at) <= :date_to" : "") . "
                    " . ($verified !== null ? "AND s.is_verified = :verified" : "") . "
                ) as counted
                ",
                $this->buildBindings($filters, $search)
            );

            $total = $query ? $query->count : 0;

            // Get paginated results
            $results = DB::select(
                "
                SELECT 
                    s.id,
                    s.student_id,
                    s.first_name,
                    s.last_name,
                    s.email,
                    s.phone,
                    s.application_status as status,
                    m.municipality_name as municipality,
                    s.year_level,
                    s.is_verified as verified,
                    s.created_at,
                    (SELECT COUNT(*) FROM documents WHERE student_id = s.id AND verification_status = 'pending') as pending_documents,
                    (SELECT COUNT(*) FROM student_notifications WHERE student_id = s.id AND is_read = false) as unread_notifications
                FROM students s
                LEFT JOIN municipalities m ON s.municipality_id = m.id
                WHERE 1=1
                " . ($search ? "AND (s.first_name ILIKE :search OR s.last_name ILIKE :search OR s.email ILIKE :search OR s.phone ILIKE :search)" : "") . "
                " . ($status ? "AND s.application_status = :status" : "") . "
                " . ($municipality ? "AND s.municipality_id = :municipality" : "") . "
                " . ($yearLevel ? "AND s.year_level = :year_level" : "") . "
                " . ($dateFrom ? "AND DATE(s.created_at) >= :date_from" : "") . "
                " . ($dateTo ? "AND DATE(s.created_at) <= :date_to" : "") . "
                " . ($verified !== null ? "AND s.is_verified = :verified" : "") . "
                ORDER BY " . $this->sanitizeSortColumn($sortBy) . " $sortOrder
                LIMIT :limit OFFSET :offset
                ",
                array_merge(
                    $this->buildBindings($filters, $search),
                    ['limit' => $perPage, 'offset' => $offset]
                )
            );

            return [
                'ok' => true,
                'data' => $results,
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'timestamp' => Carbon::now()->toIso8601String()
            ];
        } catch (\Exception $e) {
            \Log::error("SearchService::searchApplicants failed: " . $e->getMessage());
            return [
                'ok' => false,
                'message' => 'Search failed',
                'timestamp' => Carbon::now()->toIso8601String()
            ];
        }
    }

    /**
     * Search distributions with filtering
     * 
     * @param array $filters {
     *   'search': string - Search by name
     *   'status': string - Open, Closed, Pending
     *   'municipality': string|array - Filter by municipality
     *   'date_from': string - YYYY-MM-DD
     *   'date_to': string - YYYY-MM-DD
     *   'min_amount': float - Minimum distribution amount
     *   'max_amount': float - Maximum distribution amount
     *   'sort_by': string - name, created_at, distribution_date
     *   'sort_order': string - ASC or DESC
     *   'page': int
     *   'per_page': int
     * }
     */
    public function searchDistributions(array $filters = []): array
    {
        try {
            $page = $filters['page'] ?? 1;
            $perPage = min($filters['per_page'] ?? 20, 100);
            $offset = ($page - 1) * $perPage;
            $search = $filters['search'] ?? null;
            $status = $filters['status'] ?? null;
            $dateFrom = $filters['date_from'] ?? null;
            $dateTo = $filters['date_to'] ?? null;
            $minAmount = $filters['min_amount'] ?? null;
            $maxAmount = $filters['max_amount'] ?? null;

            // Count total
            $countQuery = "
                SELECT COUNT(*) as count FROM distribution_snapshots
                WHERE 1=1
                " . ($search ? "AND snapshot_name ILIKE :search" : "") . "
                " . ($status ? "AND distribution_status = :status" : "") . "
                " . ($dateFrom ? "AND DATE(snapshot_date) >= :date_from" : "") . "
                " . ($dateTo ? "AND DATE(snapshot_date) <= :date_to" : "") . "
                " . ($minAmount ? "AND total_distributed >= :min_amount" : "") . "
                " . ($maxAmount ? "AND total_distributed <= :max_amount" : "") . "
            ";

            $countResult = DB::selectOne($countQuery, $this->buildDistributionBindings($filters, $search));
            $total = $countResult ? $countResult->count : 0;

            // Get paginated results
            $results = DB::select(
                "
                SELECT 
                    id,
                    snapshot_name as name,
                    snapshot_date as date,
                    distribution_status as status,
                    COUNT(DISTINCT student_id) as beneficiaries,
                    COALESCE(SUM(amount_per_student), 0) as total_distributed,
                    COALESCE(SUM(amount_per_student) / COUNT(DISTINCT student_id), 0) as average_per_student,
                    created_at
                FROM distribution_snapshots
                WHERE 1=1
                " . ($search ? "AND snapshot_name ILIKE :search" : "") . "
                " . ($status ? "AND distribution_status = :status" : "") . "
                " . ($dateFrom ? "AND DATE(snapshot_date) >= :date_from" : "") . "
                " . ($dateTo ? "AND DATE(snapshot_date) <= :date_to" : "") . "
                " . ($minAmount ? "AND COALESCE(SUM(amount_per_student), 0) >= :min_amount" : "") . "
                " . ($maxAmount ? "AND COALESCE(SUM(amount_per_student), 0) <= :max_amount" : "") . "
                GROUP BY id, snapshot_name, snapshot_date, distribution_status, created_at
                ORDER BY snapshot_date DESC
                LIMIT :limit OFFSET :offset
                ",
                array_merge(
                    $this->buildDistributionBindings($filters, $search),
                    ['limit' => $perPage, 'offset' => $offset]
                )
            );

            return [
                'ok' => true,
                'data' => $results,
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'timestamp' => Carbon::now()->toIso8601String()
            ];
        } catch (\Exception $e) {
            \Log::error("SearchService::searchDistributions failed: " . $e->getMessage());
            return [
                'ok' => false,
                'message' => 'Search failed',
                'timestamp' => Carbon::now()->toIso8601String()
            ];
        }
    }

    /**
     * Search documents with filtering
     */
    public function searchDocuments(array $filters = []): array
    {
        try {
            $page = $filters['page'] ?? 1;
            $perPage = min($filters['per_page'] ?? 20, 100);
            $offset = ($page - 1) * $perPage;
            $search = $filters['search'] ?? null;
            $docType = $filters['document_type'] ?? null;
            $status = $filters['status'] ?? null;
            $studentId = $filters['student_id'] ?? null;
            $dateFrom = $filters['date_from'] ?? null;
            $dateTo = $filters['date_to'] ?? null;

            $countQuery = "
                SELECT COUNT(*) as count FROM documents
                WHERE 1=1
                " . ($search ? "AND (file_name ILIKE :search OR document_type_name ILIKE :search)" : "") . "
                " . ($docType ? "AND document_type_code = :document_type" : "") . "
                " . ($status ? "AND verification_status = :status" : "") . "
                " . ($studentId ? "AND student_id = :student_id" : "") . "
                " . ($dateFrom ? "AND DATE(upload_date) >= :date_from" : "") . "
                " . ($dateTo ? "AND DATE(upload_date) <= :date_to" : "") . "
            ";

            $countResult = DB::selectOne($countQuery, $this->buildDocumentBindings($filters, $search));
            $total = $countResult ? $countResult->count : 0;

            $results = DB::select(
                "
                SELECT 
                    document_id,
                    student_id,
                    document_type_code,
                    document_type_name,
                    file_name,
                    file_size_bytes,
                    verification_status,
                    upload_date,
                    (SELECT first_name || ' ' || last_name FROM students WHERE id = documents.student_id) as student_name
                FROM documents
                WHERE 1=1
                " . ($search ? "AND (file_name ILIKE :search OR document_type_name ILIKE :search)" : "") . "
                " . ($docType ? "AND document_type_code = :document_type" : "") . "
                " . ($status ? "AND verification_status = :status" : "") . "
                " . ($studentId ? "AND student_id = :student_id" : "") . "
                " . ($dateFrom ? "AND DATE(upload_date) >= :date_from" : "") . "
                " . ($dateTo ? "AND DATE(upload_date) <= :date_to" : "") . "
                ORDER BY upload_date DESC
                LIMIT :limit OFFSET :offset
                ",
                array_merge(
                    $this->buildDocumentBindings($filters, $search),
                    ['limit' => $perPage, 'offset' => $offset]
                )
            );

            return [
                'ok' => true,
                'data' => $results,
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'timestamp' => Carbon::now()->toIso8601String()
            ];
        } catch (\Exception $e) {
            \Log::error("SearchService::searchDocuments failed: " . $e->getMessage());
            return [
                'ok' => false,
                'message' => 'Search failed',
                'timestamp' => Carbon::now()->toIso8601String()
            ];
        }
    }

    /**
     * Get available filter options
     */
    public function getFilterOptions(string $type = 'applicants'): array
    {
        try {
            $options = [];

            if ($type === 'applicants' || $type === 'all') {
                $options['applicants'] = [
                    'statuses' => DB::select("SELECT DISTINCT application_status FROM students ORDER BY application_status"),
                    'municipalities' => DB::select("SELECT id, municipality_name FROM municipalities ORDER BY municipality_name"),
                    'year_levels' => DB::select("SELECT DISTINCT year_level FROM students WHERE year_level IS NOT NULL ORDER BY year_level"),
                ];
            }

            if ($type === 'distributions' || $type === 'all') {
                $options['distributions'] = [
                    'statuses' => [
                        ['status' => 'open'],
                        ['status' => 'closed'],
                        ['status' => 'pending']
                    ]
                ];
            }

            if ($type === 'documents' || $type === 'all') {
                $options['documents'] = [
                    'types' => DB::select("SELECT DISTINCT document_type_code, document_type_name FROM documents ORDER BY document_type_name"),
                    'statuses' => [
                        ['status' => 'pending'],
                        ['status' => 'approved'],
                        ['status' => 'rejected'],
                        ['status' => 'verified']
                    ]
                ];
            }

            return [
                'ok' => true,
                'data' => $options,
                'timestamp' => Carbon::now()->toIso8601String()
            ];
        } catch (\Exception $e) {
            \Log::error("SearchService::getFilterOptions failed: " . $e->getMessage());
            return [
                'ok' => false,
                'message' => 'Failed to load filter options',
                'timestamp' => Carbon::now()->toIso8601String()
            ];
        }
    }

    /**
     * Helper: Build parameter bindings for applicant search
     */
    private function buildBindings(array $filters, ?string $search): array
    {
        $bindings = [];
        if ($search) $bindings['search'] = "%$search%";
        if ($filters['status'] ?? null) $bindings['status'] = $filters['status'];
        if ($filters['municipality'] ?? null) $bindings['municipality'] = $filters['municipality'];
        if ($filters['year_level'] ?? null) $bindings['year_level'] = $filters['year_level'];
        if ($filters['date_from'] ?? null) $bindings['date_from'] = $filters['date_from'];
        if ($filters['date_to'] ?? null) $bindings['date_to'] = $filters['date_to'];
        if ($filters['verified'] !== null) $bindings['verified'] = $filters['verified'];
        return $bindings;
    }

    /**
     * Helper: Build parameter bindings for distribution search
     */
    private function buildDistributionBindings(array $filters, ?string $search): array
    {
        $bindings = [];
        if ($search) $bindings['search'] = "%$search%";
        if ($filters['status'] ?? null) $bindings['status'] = $filters['status'];
        if ($filters['date_from'] ?? null) $bindings['date_from'] = $filters['date_from'];
        if ($filters['date_to'] ?? null) $bindings['date_to'] = $filters['date_to'];
        if ($filters['min_amount'] ?? null) $bindings['min_amount'] = $filters['min_amount'];
        if ($filters['max_amount'] ?? null) $bindings['max_amount'] = $filters['max_amount'];
        return $bindings;
    }

    /**
     * Helper: Build parameter bindings for document search
     */
    private function buildDocumentBindings(array $filters, ?string $search): array
    {
        $bindings = [];
        if ($search) $bindings['search'] = "%$search%";
        if ($filters['document_type'] ?? null) $bindings['document_type'] = $filters['document_type'];
        if ($filters['status'] ?? null) $bindings['status'] = $filters['status'];
        if ($filters['student_id'] ?? null) $bindings['student_id'] = $filters['student_id'];
        if ($filters['date_from'] ?? null) $bindings['date_from'] = $filters['date_from'];
        if ($filters['date_to'] ?? null) $bindings['date_to'] = $filters['date_to'];
        return $bindings;
    }

    /**
     * Sanitize sort column to prevent SQL injection
     */
    private function sanitizeSortColumn(string $column): string
    {
        $allowed = ['id', 'name', 'created_at', 'status', 'email', 'phone', 'municipality'];
        return in_array($column, $allowed) ? "s.$column" : 's.created_at';
    }
}
