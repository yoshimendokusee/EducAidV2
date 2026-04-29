<?php
namespace App\Services;
require_once __DIR__ . '/ApiClient.php';

/**
 * HeaderThemeService Wrapper
 * Forwards header theme operations to Laravel API
 */
class HeaderThemeService {
    private ApiClient $client;
    
    public function __construct($connection = null, string $apiBase = null) {
        $this->client = new ApiClient($apiBase);
    }
    
    /**
     * Get current header theme settings
     */
    public function getCurrentSettings($municipalityId = 1) {
        return $this->client->get('themes/header/current', ['municipality_id' => $municipalityId]);
    }
    
    /**
     * Get default header theme settings
     */
    public function getDefaultSettings() {
        return [
            'header_bg_color' => '#2e7d32',
            'header_bg_gradient' => '#1b5e20',
            'header_text_color' => '#ffffff',
            'header_link_color' => '#e8f5e9',
            'logo_url' => null,
            'logo_height' => 50
        ];
    }
    
    /**
     * Save header theme settings
     */
    public function saveSettings($settings, $municipalityId = 1) {
        return $this->client->post('themes/header/save', [
            'municipality_id' => $municipalityId,
            'settings' => $settings
        ]);
    }
    
    /**
     * Upload and set logo
     */
    public function setLogo($logoPath, $municipalityId = 1) {
        return $this->client->post('themes/header/set-logo', [
            'municipality_id' => $municipalityId,
            'logo_path' => $logoPath
        ]);
    }
}
