<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * PayrollHistoryService
 * Lightweight helper to record and fetch student payroll assignment history.
 * Tracks payroll numbers across academic years and semesters.
 */
class PayrollHistoryService
{
    /**
     * Record a payroll assignment for a student per academic year + semester.
     *
     * @param string $studentId The student ID
     * @param string $payrollNo The payroll number
     * @param string $academicYear The academic year
     * @param string $semester The semester (e.g., 'Q1', 'Q2', etc.)
     * @param string|null $snapshotId Optional distribution snapshot ID
     * @return bool True if record was saved successfully
     */
    public function record(
        string $studentId,
        string $payrollNo,
        string $academicYear,
        string $semester,
        ?string $snapshotId = null
    ): bool {
        if (!$studentId || !$payrollNo || !$academicYear || !$semester) {
            return false;
        }

        try {
            DB::table('distribution_payrolls')->updateOrInsert(
                [
                    'student_id' => $studentId,
                    'academic_year' => $academicYear,
                    'semester' => $semester,
                ],
                [
                    'payroll_no' => $payrollNo,
                    'assigned_at' => now(),
                    'snapshot_id' => $snapshotId,
                ]
            );
            return true;
        } catch (\Exception $e) {
            \Log::error('PayrollHistoryService::record failed', [
                'student_id' => $studentId,
                'payroll_no' => $payrollNo,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Fetch full payroll history ordered by assignment time
     *
     * @param string $studentId The student ID
     * @return array Array of payroll history records
     */
    public function getHistory(string $studentId): array
    {
        try {
            return DB::table('distribution_payrolls')
                ->where('student_id', $studentId)
                ->orderBy('assigned_at', 'asc')
                ->get()
                ->toArray();
        } catch (\Exception $e) {
            \Log::error('PayrollHistoryService::getHistory failed', [
                'student_id' => $studentId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Get the most recent payroll number for a student
     *
     * @param string $studentId The student ID
     * @return string|null The payroll number or null if not found
     */
    public function getLatest(string $studentId): ?string
    {
        try {
            $record = DB::table('distribution_payrolls')
                ->where('student_id', $studentId)
                ->orderBy('assigned_at', 'desc')
                ->first();

            return $record?->payroll_no;
        } catch (\Exception $e) {
            \Log::error('PayrollHistoryService::getLatest failed', [
                'student_id' => $studentId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get payroll history for a specific academic year
     *
     * @param string $studentId The student ID
     * @param string $academicYear The academic year
     * @return array Array of payroll records for the year
     */
    public function getForYear(string $studentId, string $academicYear): array
    {
        try {
            return DB::table('distribution_payrolls')
                ->where('student_id', $studentId)
                ->where('academic_year', $academicYear)
                ->orderBy('semester', 'asc')
                ->get()
                ->toArray();
        } catch (\Exception $e) {
            \Log::error('PayrollHistoryService::getForYear failed', [
                'student_id' => $studentId,
                'academic_year' => $academicYear,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }
}
