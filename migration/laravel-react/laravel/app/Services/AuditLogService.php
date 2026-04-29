<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AuditLogService
 * Tracks all system actions for accountability and debugging
 */
class AuditLogService
{
    /**
     * Log an action
     *
     * @param int|null $adminId Admin ID (uses Auth if not provided)
     * @param string $action Action name (verb_object)
     * @param string $tableName Table affected
     * @param string|null $recordId Primary key of affected record
     * @param array|null $details Additional details (JSON serialized)
     * @param string|null $ipAddress Client IP address
     * @return bool True if logged successfully
     */
    public function logAction(
        ?int $adminId = null,
        string $action = '',
        string $tableName = '',
        ?string $recordId = null,
        ?array $details = null,
        ?string $ipAddress = null
    ): bool {
        try {
            if ($adminId === null) {
                $adminId = Auth::id();
            }

            if (!$ipAddress) {
                $ipAddress = request()?->ip() ?? '0.0.0.0';
            }

            DB::table('audit_logs')->insert([
                'admin_id' => $adminId,
                'action' => $action,
                'table_name' => $tableName,
                'record_id' => $recordId,
                'details' => $details ? json_encode($details) : null,
                'ip_address' => $ipAddress,
                'created_at' => now(),
            ]);

            return true;
        } catch (Exception $e) {
            Log::error('AuditLogService::logAction failed', [
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get audit logs for a specific record
     *
     * @param string $tableName Table name
     * @param string $recordId Record ID
     * @param int $limit Number of logs to return
     * @return array Array of audit logs
     */
    public function getRecordAuditLog(string $tableName, string $recordId, int $limit = 100): array
    {
        try {
            return DB::table('audit_logs')
                ->where('table_name', $tableName)
                ->where('record_id', $recordId)
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get()
                ->toArray();
        } catch (Exception $e) {
            Log::error('AuditLogService::getRecordAuditLog failed', [
                'table' => $tableName,
                'record_id' => $recordId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Get audit logs for an admin
     *
     * @param int $adminId Admin ID
     * @param int $limit Number of logs to return
     * @return array Array of audit logs
     */
    public function getAdminAuditLog(int $adminId, int $limit = 100): array
    {
        try {
            return DB::table('audit_logs')
                ->where('admin_id', $adminId)
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get()
                ->toArray();
        } catch (Exception $e) {
            Log::error('AuditLogService::getAdminAuditLog failed', [
                'admin_id' => $adminId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Get all audit logs (with optional filtering)
     *
     * @param string|null $action Filter by action
     * @param string|null $tableName Filter by table
     * @param int $limit Number of logs to return
     * @return array Array of audit logs
     */
    public function getAuditLogs(
        ?string $action = null,
        ?string $tableName = null,
        int $limit = 100
    ): array {
        try {
            $query = DB::table('audit_logs');

            if ($action) {
                $query->where('action', $action);
            }

            if ($tableName) {
                $query->where('table_name', $tableName);
            }

            return $query
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get()
                ->toArray();
        } catch (Exception $e) {
            Log::error('AuditLogService::getAuditLogs failed', [
                'action' => $action,
                'table' => $tableName,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }
}
