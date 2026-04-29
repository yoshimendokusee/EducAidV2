<?php
namespace App\Services;
require_once __DIR__ . '/ApiClient.php';

/**
 * SidebarThemeService Wrapper
 * Forwards theme operations to Laravel API
 */
class SidebarThemeService {
    private ApiClient $client;
    
    public function __construct(string $apiBase = null) {
        $this->client = new ApiClient($apiBase);
    }
    
    /**
     * Get current sidebar theme settings
     */
    public function getCurrentSettings($municipalityId = 1) {
        return $this->client->get('themes/sidebar/current', ['municipality_id' => $municipalityId]);
    }
    
    /**
     * Get default sidebar theme settings
     */
    public function getDefaultSettings() {
        return [
            'sidebar_bg_start' => '#f8f9fa',
            'sidebar_bg_end' => '#ffffff',
            'sidebar_border_color' => '#dee2e6',
            'nav_text_color' => '#212529',
            'nav_icon_color' => '#6c757d',
            'nav_hover_bg' => '#e9ecef',
            'nav_hover_text' => '#212529',
            'nav_active_bg' => '#0d6efd',
            'nav_active_text' => '#ffffff',
            'profile_avatar_bg_start' => '#0d6efd',
            'profile_avatar_bg_end' => '#0b5ed7',
            'profile_name_color' => '#212529',
            'profile_role_color' => '#6c757d',
            'profile_border_color' => '#dee2e6',
            'submenu_bg' => '#f8f9fa',
            'submenu_text_color' => '#495057',
            'submenu_hover_bg' => '#e9ecef',
            'submenu_active_bg' => '#e7f3ff',
            'submenu_active_text' => '#0d6efd'
        ];
    }
    
    /**
     * Save sidebar theme settings
     */
    public function saveSettings($settings, $municipalityId = 1) {
        return $this->client->post('themes/sidebar/save', [
            'municipality_id' => $municipalityId,
            'settings' => $settings
        ]);
    }
}
