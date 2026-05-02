<?php

namespace App\Http\Controllers;

use App\Services\SearchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * SearchController - Advanced Search & Filtering API
 * 
 * Provides endpoints for searching and filtering:
 * - Applicants: Full-text search, status filtering, municipality filtering
 * - Distributions: Name search, date range, amount filtering
 * - Documents: Type/status filtering, file search
 * - Filter Options: Available filter values for UI dropdowns
 * 
 * All endpoints require admin authentication
 */
class SearchController extends Controller
{
    protected $searchService;

    public function __construct(SearchService $searchService)
    {
        $this->searchService = $searchService;
    }

    /**
     * Search applicants with advanced filtering
     * 
     * GET /api/search/applicants
     * 
     * Query parameters:
     *   - search: Full-text search string
     *   - status: applicant|approved|rejected|enrolled
     *   - municipality: municipality ID
     *   - year_level: 1-4
     *   - date_from: YYYY-MM-DD
     *   - date_to: YYYY-MM-DD
     *   - verified: true|false
     *   - sort_by: name|status|created_at
     *   - sort_order: ASC|DESC
     *   - page: 1
     *   - per_page: 20
     * 
     * Response: {ok, data: [...], total, page, per_page, timestamp}
     */
    public function searchApplicants(Request $request)
    {
        if (!$this->isAdmin()) {
            return response()->json([
                'ok' => false,
                'message' => 'Unauthorized',
                'timestamp' => Carbon::now()->toIso8601String()
            ], 403);
        }

        try {
            $filters = $request->query->all();
            $result = $this->searchService->searchApplicants($filters);
            
            return response()->json($result, $result['ok'] ? 200 : 400);
        } catch (\Exception $e) {
            Log::error("SearchController::searchApplicants failed: " . $e->getMessage());
            return response()->json([
                'ok' => false,
                'message' => 'Search failed: ' . $e->getMessage(),
                'timestamp' => Carbon::now()->toIso8601String()
            ], 500);
        }
    }

    /**
     * Search distributions with filtering
     * 
     * GET /api/search/distributions
     * 
     * Query parameters:
     *   - search: Distribution name search
     *   - status: open|closed|pending
     *   - date_from: YYYY-MM-DD
     *   - date_to: YYYY-MM-DD
     *   - min_amount: Minimum total amount
     *   - max_amount: Maximum total amount
     *   - sort_by: name|created_at|date
     *   - sort_order: ASC|DESC
     *   - page: 1
     *   - per_page: 20
     * 
     * Response: {ok, data: [...], total, page, per_page, timestamp}
     */
    public function searchDistributions(Request $request)
    {
        if (!$this->isAdmin()) {
            return response()->json([
                'ok' => false,
                'message' => 'Unauthorized',
                'timestamp' => Carbon::now()->toIso8601String()
            ], 403);
        }

        try {
            $filters = $request->query->all();
            $result = $this->searchService->searchDistributions($filters);
            
            return response()->json($result, $result['ok'] ? 200 : 400);
        } catch (\Exception $e) {
            Log::error("SearchController::searchDistributions failed: " . $e->getMessage());
            return response()->json([
                'ok' => false,
                'message' => 'Search failed: ' . $e->getMessage(),
                'timestamp' => Carbon::now()->toIso8601String()
            ], 500);
        }
    }

    /**
     * Search documents with filtering
     * 
     * GET /api/search/documents
     * 
     * Query parameters:
     *   - search: File name / document type search
     *   - document_type: Document type code
     *   - status: pending|approved|rejected|verified
     *   - student_id: Filter by student
     *   - date_from: YYYY-MM-DD
     *   - date_to: YYYY-MM-DD
     *   - page: 1
     *   - per_page: 20
     * 
     * Response: {ok, data: [...], total, page, per_page, timestamp}
     */
    public function searchDocuments(Request $request)
    {
        if (!$this->isAdmin()) {
            return response()->json([
                'ok' => false,
                'message' => 'Unauthorized',
                'timestamp' => Carbon::now()->toIso8601String()
            ], 403);
        }

        try {
            $filters = $request->query->all();
            $result = $this->searchService->searchDocuments($filters);
            
            return response()->json($result, $result['ok'] ? 200 : 400);
        } catch (\Exception $e) {
            Log::error("SearchController::searchDocuments failed: " . $e->getMessage());
            return response()->json([
                'ok' => false,
                'message' => 'Search failed: ' . $e->getMessage(),
                'timestamp' => Carbon::now()->toIso8601String()
            ], 500);
        }
    }

    /**
     * Get available filter options for search UI
     * 
     * GET /api/search/filter-options?type=applicants|distributions|documents|all
     * 
     * Returns lists of:
     *   - Application statuses
     *   - Municipalities
     *   - Year levels
     *   - Document types
     *   - Distribution statuses
     * 
     * Response: {ok, data: {applicants: {...}, distributions: {...}, documents: {...}}, timestamp}
     */
    public function getFilterOptions(Request $request)
    {
        if (!$this->isAdmin()) {
            return response()->json([
                'ok' => false,
                'message' => 'Unauthorized',
                'timestamp' => Carbon::now()->toIso8601String()
            ], 403);
        }

        try {
            $type = $request->query('type', 'all');
            $result = $this->searchService->getFilterOptions($type);
            
            return response()->json($result, $result['ok'] ? 200 : 400);
        } catch (\Exception $e) {
            Log::error("SearchController::getFilterOptions failed: " . $e->getMessage());
            return response()->json([
                'ok' => false,
                'message' => 'Failed to load filter options: ' . $e->getMessage(),
                'timestamp' => Carbon::now()->toIso8601String()
            ], 500);
        }
    }

    /**
     * Check admin authentication
     */
    private function isAdmin(): bool
    {
        return !empty($_SESSION['admin_username']);
    }
}
