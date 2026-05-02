<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class AdminApplicantService
{
    // Old source: modules/admin/manage_applicants.php?api=badge_count
    public function getApplicantBadgeCount(): int
    {
        $row = (array) DB::selectOne(
            "SELECT COUNT(*) as count FROM students WHERE status = 'applicant' AND (is_archived IS NULL OR is_archived = FALSE)"
        );

        return (int) ($row['count'] ?? 0);
    }

    /**
     * Get list of applicants with all details
     * Returns applicants for the React ApplicantsPage
     * 
     * @param array $filters Optional filters: status, search_term
     * @param int $limit Maximum results
     * @return array List of applicants with details
     */
    public function getApplicantsList(array $filters = [], int $limit = 100): array
    {
        $query = DB::table('students')
            ->select(
                'student_id as id',
                'first_name',
                'last_name',
                'email',
                'mobile',
                'status',
                'application_date as submittedDate',
                'documents_submitted',
                'documents_validated'
            )
            ->whereIn('status', ['applicant', 'approved', 'rejected'])
            ->where('is_archived', false)
            ->orWhereNull('is_archived');

        // Apply status filter if provided
        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        // Apply search filter if provided
        if (!empty($filters['search_term'])) {
            $search = '%' . $filters['search_term'] . '%';
            $query->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(first_name || ? || last_name) LIKE LOWER(?)', [' ', $search])
                  ->orWhere('email', 'ilike', $search)
                  ->orWhere('student_id', 'ilike', $search);
            });
        }

        $applicants = $query->limit($limit)->get()->toArray();

        // Transform to React component format
        $result = [];
        foreach ($applicants as $app) {
            $result[] = [
                'id' => $app->id,
                'name' => trim($app->first_name . ' ' . $app->last_name),
                'email' => $app->email,
                'school' => 'School TBD', // TODO: Add school relation
                'grade' => 12, // TODO: Get from year_level
                'status' => $app->status,
                'submittedDate' => substr($app->submittedDate, 0, 10),
                'documentsComplete' => $app->documents_validated ? 10 : 0,
                'documentsTotal' => 10,
            ];
        }

        return $result;
    }

    /**
     * Get badge count and list together (for quick loading)
     * @return array
     */
    public function getApplicantsOverview(): array
    {
        return [
            'count' => $this->getApplicantBadgeCount(),
            'applicants' => $this->getApplicantsList(),
            'summary' => [
                'pending' => (int) DB::selectOne(
                    "SELECT COUNT(*) as count FROM students WHERE status = 'applicant' AND (is_archived IS NULL OR is_archived = FALSE)"
                )->count,
                'approved' => (int) DB::selectOne(
                    "SELECT COUNT(*) as count FROM students WHERE status = 'approved' AND (is_archived IS NULL OR is_archived = FALSE)"
                )->count,
                'rejected' => (int) DB::selectOne(
                    "SELECT COUNT(*) as count FROM students WHERE status = 'rejected' AND (is_archived IS NULL OR is_archived = FALSE)"
                )->count,
            ]
        ];
    }
}
