<?php

class TopbarSettingsController {
    private $themeService;
    private $admin_id;
    private $connection;
    
    public function __construct(ThemeSettingsService $themeService, $admin_id, $connection = null) {
        $this->themeService = $themeService;
        $this->admin_id = $admin_id;
        $this->connection = $connection;
    }
    
    /**
     * Handle form submission
     */
    public function handleFormSubmission() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return ['success' => false, 'message' => ''];
        }
        
        // CSRF Protection
        include_once __DIR__ . '/../includes/CSRFProtection.php';
        $csrf_token = $_POST['csrf_token'] ?? '';
        if (!CSRFProtection::validateToken('topbar_settings', $csrf_token)) {
            return [
                'success' => false, 
                'message' => 'Security token validation failed. Please try again.'
            ];
        }
        
        // Sanitize input data
        $data = $this->themeService->sanitizeData($_POST);
        
        // Validate input
        $validation_errors = $this->themeService->validateInput($data);
        if (!empty($validation_errors)) {
            return [
                'success' => false, 
                'message' => implode(' ', $validation_errors)
            ];
        }
        
        // Get current settings for comparison
        $current_settings = $this->themeService->getCurrentSettings();
        
        // Update settings
        $result = $this->themeService->updateSettings($data, $this->admin_id);
        
        if ($result) {
            // Log changes for audit trail
            $changes = $this->getChanges($current_settings, $data);
            if (!empty($changes)) {
                $this->themeService->logSettingsChange($this->admin_id, $changes);
                
                // Send notifications for visual changes
                $this->sendNotifications($changes);
            }
            
            return [
                'success' => true, 
                'message' => 'Settings updated successfully!',
                'data' => $data
            ];
        } else {
            return [
                'success' => false, 
                'message' => 'Failed to update settings. Please try again.'
            ];
        }
    }
    
    /**
     * Send email and bell notifications for visual changes
     */
    private function sendNotifications($changes) {
        try {
            // Get admin information
            $admin_info = $this->getAdminInfo();
            if (!$admin_info) {
                return;
            }
            
            // Use new wrapper-based NotificationService adapter
            require_once __DIR__ . '/../src/Services/NotificationService.php';
            $notificationService = new \App\Services\NotificationService();
            
            // Send email notification
            $notificationService->sendVisualChangeNotification($changes, $admin_info);
            
            // Create bell notification
            $notificationService->createBellNotification($changes, $admin_info);
            
        } catch (Exception $e) {
            error_log("Notification sending failed: " . $e->getMessage());
        }
    }
    
    /**
     * Get admin information for notifications
     */
    private function getAdminInfo() {
        if (!$this->connection) {
            return null;
        }
        
        $query = "SELECT admin_id, username, TRIM(BOTH FROM CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) AS full_name, email FROM admins WHERE admin_id = $1 AND is_active = TRUE";
        $result = pg_query_params($this->connection, $query, [$this->admin_id]);
        
        if ($result && pg_num_rows($result) > 0) {
            $row = pg_fetch_assoc($result);
            // Ensure full_name always has a value for downstream notifications
            if (empty(trim($row['full_name'] ?? ''))) {
                $row['full_name'] = $row['username'] ?? '';
            }
            return $row;
        }
        
        return null;
    }
    
    /**
     * Compare old and new settings to track changes
     */
    private function getChanges($old_settings, $new_settings) {
        $changes = [];
        $trackable_fields = [
            'topbar_email', 'topbar_phone', 'topbar_office_hours',
            'topbar_bg_color', 'topbar_bg_gradient', 'topbar_text_color', 'topbar_link_color'
        ];
        
        foreach ($trackable_fields as $field) {
            $old_value = $old_settings[$field] ?? '';
            $new_value = $new_settings[$field] ?? '';
            
            if ($old_value !== $new_value) {
                $changes[$field] = [
                    'from' => $old_value,
                    'to' => $new_value
                ];
            }
        }
        
        return $changes;
    }
}