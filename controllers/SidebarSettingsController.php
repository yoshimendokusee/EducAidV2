<?php
// SidebarSettingsController.php - Handle sidebar theme form submissions

require_once __DIR__ . '/../src/Services/SidebarThemeService.php';
require_once __DIR__ . '/../src/Services/NotificationService.php';

class SidebarSettingsController {
    private $connection;
    private $sidebarThemeService;
    
    public function __construct($connection) {
        $this->connection = $connection;
        $this->sidebarThemeService = new \App\Services\SidebarThemeService();
        // NotificationService will be instantiated when needed
    }
    
    public function handleSubmission($postData) {
        try {
            // Get current settings for comparison
            $currentSettings = $this->sidebarThemeService->getCurrentSettings();
            
            // Update settings
            $result = $this->sidebarThemeService->saveSettings($postData);
            
            if ($result['success']) {
                // Get new settings for comparison
                $newSettings = $this->sidebarThemeService->getCurrentSettings();
                
                // Find what changed
                $changes = $this->getChanges($currentSettings, $newSettings);
                
                if (!empty($changes)) {
                    // Send notifications about the changes
                    $this->sendNotifications($changes);
                }
                
                return [
                    'success' => true,
                    'message' => 'Sidebar theme settings updated successfully!',
                    'changes' => $changes
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Failed to update sidebar theme settings.',
                    'errors' => $result['errors'] ?? []
                ];
            }
        } catch (Exception $e) {
            error_log("Sidebar settings error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'An unexpected error occurred.',
                'errors' => ['system' => $e->getMessage()]
            ];
        }
    }
    
    private function getChanges($old, $new) {
        $changes = [];
        $fieldLabels = [
            'sidebar_bg_start' => 'Sidebar Background Start',
            'sidebar_bg_end' => 'Sidebar Background End',
            'sidebar_border_color' => 'Sidebar Border',
            'nav_text_color' => 'Navigation Text',
            'nav_icon_color' => 'Navigation Icons',
            'nav_hover_bg' => 'Navigation Hover Background',
            'nav_hover_text' => 'Navigation Hover Text',
            'nav_active_bg' => 'Active Navigation Background',
            'nav_active_text' => 'Active Navigation Text',
            'profile_avatar_bg_start' => 'Profile Avatar Start',
            'profile_avatar_bg_end' => 'Profile Avatar End',
            'profile_name_color' => 'Profile Name',
            'profile_role_color' => 'Profile Role',
            'profile_border_color' => 'Profile Border',
            'submenu_bg' => 'Submenu Background',
            'submenu_text_color' => 'Submenu Text',
            'submenu_hover_bg' => 'Submenu Hover Background',
            'submenu_active_bg' => 'Submenu Active Background',
            'submenu_active_text' => 'Submenu Active Text'
        ];
        
        foreach ($fieldLabels as $field => $label) {
            if (isset($old[$field]) && isset($new[$field]) && $old[$field] !== $new[$field]) {
                $changes[] = [
                    'field' => $field,
                    'label' => $label,
                    'old_value' => $old[$field],
                    'new_value' => $new[$field]
                ];
            }
        }
        
        return $changes;
    }
    
    private function sendNotifications($changes) {
        if (empty($changes) || !isset($_SESSION['admin_id'])) return;
        
        $changesList = array_map(function($change) {
            return "{$change['label']}: {$change['old_value']} → {$change['new_value']}";
        }, $changes);
        
        // Get admin info for notifications
        $adminInfo = $this->getAdminInfo($_SESSION['admin_id']);
        if (!$adminInfo) return;
        
        // Create notification service instance
        $notificationService = new \App\Services\NotificationService();
        
        // Send notifications
        $notificationService->sendVisualChangeNotification($changes, $adminInfo);
        $notificationService->createBellNotification($changes, $adminInfo);
    }
    
    private function getAdminInfo($adminId) {
        $query = "SELECT admin_id, username, TRIM(BOTH FROM CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) AS full_name, email FROM admins WHERE admin_id = $1 AND is_active = TRUE";
        $result = pg_query_params($this->connection, $query, [$adminId]);
        
        if ($result && ($row = pg_fetch_assoc($result))) {
            return $row;
        }
        
        return null;
    }
}