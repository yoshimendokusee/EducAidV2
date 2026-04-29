<?php
namespace App\Services;
require_once __DIR__ . '/ApiClient.php';

/**
 * ThemeSettingsService Wrapper
 * Forwards topbar/header theme operations to Laravel API
 */
class ThemeSettingsService {
    private ApiClient $client;
    private int $municipalityId;
    
    public function __construct($connection = null, int $municipalityId = 1, string $apiBase = null) {
        $this->client = new ApiClient($apiBase);
        $this->municipalityId = $municipalityId;
    }
    
    /**
     * Get default theme settings
     */
    public function getDefaultSettings() {
        return [
            'topbar_email' => 'educaid@generaltrias.gov.ph',
            'topbar_phone' => '(046) 886-4454',
            'topbar_office_hours' => 'Mon–Fri 8:00AM - 5:00PM',
            'topbar_bg_color' => '#2e7d32',
            'topbar_bg_gradient' => '#1b5e20',
            'topbar_text_color' => '#ffffff',
            'topbar_link_color' => '#e8f5e9'
        ];
    }
    
    /**
     * Get current theme settings from database
     */
    public function getCurrentSettings() {
        return $this->client->get('themes/topbar/current', ['municipality_id' => $this->municipalityId]);
    }
    
    /**
     * Get contact info from municipality
     */
    public function getContactInfoFromMunicipality() {
        return $this->client->get('themes/municipality/contact', ['municipality_id' => $this->municipalityId]);
    }
    
    /**
     * Save theme settings
     */
    public function saveSettings($settings) {
        return $this->client->post('themes/topbar/save', [
            'municipality_id' => $this->municipalityId,
            'settings' => $settings
        ]);
    }
    
    /**
     * Get theme by municipality ID
     */
    public function getThemeByMunicipality($municipalityId) {
        return $this->client->get('themes/municipality/' . $municipalityId);
    }
}
