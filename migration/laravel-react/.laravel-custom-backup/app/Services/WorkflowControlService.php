<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WorkflowControlService
{
    // Migrated from old file: includes/workflow_control.php::hasPayrollAndQR
    public function hasPayrollAndQR(): bool
    {
        $query = "
            SELECT COUNT(*) as total,
                   COUNT(CASE WHEN COALESCE(s.payroll_no,'') <> '' THEN 1 END) as with_payroll,
                   COUNT(q.qr_id) as with_qr
            FROM students s
            LEFT JOIN qr_codes q ON q.student_id = s.student_id
            WHERE s.status IN ('active', 'given')
        ";

        $data = (array) DB::selectOne($query);

        if (!empty($data) && ((int) ($data['total'] ?? 0)) > 0) {
            return ((int) ($data['with_payroll'] ?? 0)) > 0 && ((int) ($data['with_qr'] ?? 0)) > 0;
        }

        return false;
    }

    // Migrated from old file: includes/workflow_control.php::hasSchedules
    public function hasSchedules(): bool
    {
        $data = (array) DB::selectOne('SELECT COUNT(*) as count FROM schedules');
        return ((int) ($data['count'] ?? 0)) > 0;
    }

    // Migrated from old file: includes/workflow_control.php::isStudentListFinalized
    public function isStudentListFinalized(): bool
    {
        $data = (array) DB::selectOne("SELECT value FROM config WHERE key = 'student_list_finalized'");
        return (($data['value'] ?? null) === '1');
    }

    // Migrated from old file: includes/workflow_control.php::getDistributionStatus
    public function getDistributionStatus(): string
    {
        try {
            $data = (array) DB::selectOne("SELECT value FROM config WHERE key = 'distribution_status'");
            return !empty($data['value']) ? (string) $data['value'] : 'inactive';
        } catch (\Throwable $e) {
            Log::error('Exception in getDistributionStatus', ['error' => $e->getMessage()]);
            return 'inactive';
        }
    }

    // Migrated from old file: includes/workflow_control.php::areSlotsOpen
    public function areSlotsOpen(): bool
    {
        $data = (array) DB::selectOne('SELECT COUNT(*) as count FROM signup_slots WHERE is_active = TRUE');
        return ((int) ($data['count'] ?? 0)) > 0;
    }

    // Migrated from old file: includes/workflow_control.php::areUploadsEnabled
    public function areUploadsEnabled(): bool
    {
        try {
            $data = (array) DB::selectOne("SELECT value FROM config WHERE key = 'uploads_enabled'");
            return (($data['value'] ?? null) === '1');
        } catch (\Throwable $e) {
            Log::error('Exception in areUploadsEnabled', ['error' => $e->getMessage()]);
            return false;
        }
    }

    // Migrated from old file: includes/workflow_control.php::getWorkflowStatus
    public function getWorkflowStatus(): array
    {
        $distributionStatus = $this->getDistributionStatus();
        $distributionActive = ($distributionStatus === 'active');

        $hasPayrollQr = $this->hasPayrollAndQR();
        $hasSchedules = $this->hasSchedules();

        return [
            'list_finalized' => $this->isStudentListFinalized(),
            'has_payroll_qr' => $hasPayrollQr,
            'has_schedules' => $hasSchedules,
            'can_schedule' => $hasPayrollQr,
            'can_scan_qr' => $hasPayrollQr,
            'can_revert_payroll' => $hasPayrollQr && !$hasSchedules,
            'can_manage_applicants' => $distributionActive,
            'can_verify_students' => $distributionActive,
            'can_manage_slots' => $distributionActive,
            'distribution_status' => $distributionStatus,
            'slots_open' => $this->areSlotsOpen(),
            'uploads_enabled' => $this->areUploadsEnabled(),
            'can_start_distribution' => $distributionStatus === 'inactive',
            'can_finalize_distribution' => $distributionActive,
        ];
    }

    // Migrated from old file: includes/workflow_control.php::getStudentCounts
    public function getStudentCounts(): array
    {
        try {
            $query = "
                SELECT
                    COUNT(*) as total_students,
                    COUNT(CASE WHEN status = 'active' THEN 1 END) as active_count,
                    COUNT(CASE WHEN status = 'active' AND COALESCE(payroll_no,'') <> '' THEN 1 END) as with_payroll_count,
                    COUNT(CASE WHEN status = 'applicant' THEN 1 END) as applicant_count,
                    COUNT(CASE WHEN status = 'active' THEN 1 END) as verified_students,
                    COUNT(CASE WHEN status = 'applicant' THEN 1 END) as pending_verification
                FROM students
            ";

            $data = (array) DB::selectOne($query);

            return [
                'total_students' => (int) ($data['total_students'] ?? 0),
                'active_count' => (int) ($data['active_count'] ?? 0),
                'with_payroll_count' => (int) ($data['with_payroll_count'] ?? 0),
                'applicant_count' => (int) ($data['applicant_count'] ?? 0),
                'verified_students' => (int) ($data['verified_students'] ?? 0),
                'pending_verification' => (int) ($data['pending_verification'] ?? 0),
            ];
        } catch (\Throwable $e) {
            Log::error('Exception in getStudentCounts', ['error' => $e->getMessage()]);

            return [
                'total_students' => 0,
                'active_count' => 0,
                'with_payroll_count' => 0,
                'applicant_count' => 0,
                'verified_students' => 0,
                'pending_verification' => 0,
            ];
        }
    }
}
