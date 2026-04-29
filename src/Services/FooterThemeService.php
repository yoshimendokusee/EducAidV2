<?php
namespace App\Services;
require_once __DIR__ . '/ApiClient.php';

/**
 * FooterThemeService Wrapper
 * Forwards footer theme operations to Laravel API
 */
class FooterThemeService {
    private ApiClient $client;
    
    public function __construct($connection = null, string $apiBase = null) {
        $this->client = new ApiClient($apiBase);
    }
    
    /**
     * Get current footer theme settings
     */
    public function getCurrentSettings($municipalityId = 1) {
        return $this->client->get('themes/footer/current', ['municipality_id' => $municipalityId]);
    }
    
    /**
     * Get default footer theme settings
     */
    public function getDefaultSettings() {
        return [
            'footer_bg_color' => '#212529',
            'footer_text_color' => '#ffffff',
            'footer_link_color' => '#0d6efd',
            'footer_copyright' => '&copy; 2024 EducAid System. All rights reserved.',
            'footer_links' => []
        ];
    }
    
    /**
     * Save footer theme settings
     */
    public function saveSettings($settings, $municipalityId = 1) {
        return $this->client->post('themes/footer/save', [
            'municipality_id' => $municipalityId,
            'settings' => $settings
        ]);
    }
}
