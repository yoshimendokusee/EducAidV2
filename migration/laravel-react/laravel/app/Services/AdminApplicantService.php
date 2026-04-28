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
}
