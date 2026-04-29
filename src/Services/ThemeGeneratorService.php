<?php
namespace App\Services;
require_once __DIR__ . '/ApiClient.php';

/**
 * ThemeGeneratorService Wrapper
 * Forwards theme generation operations to Laravel API
 */
class ThemeGeneratorService {
    private ApiClient $client;
    
    public function __construct($connection = null, string $apiBase = null) {
        $this->client = new ApiClient($apiBase);
    }
    
    /**
     * Generate complete theme from base color
     */
    public function generateTheme($baseColor, $municipalityId = 1) {
        return $this->client->post('themes/generate', [
            'base_color' => $baseColor,
            'municipality_id' => $municipalityId
        ]);
    }
    
    /**
     * Generate preset themes
     */
    public function generatePresetTheme($presetName, $municipalityId = 1) {
        return $this->client->post('themes/generate-preset', [
            'preset' => $presetName,
            'municipality_id' => $municipalityId
        ]);
    }
    
    /**
     * Get available presets
     */
    public function getPresets() {
        return $this->client->get('themes/presets');
    }
    
    /**
     * Apply theme to municipality
     */
    public function applyTheme($themeName, $municipalityId = 1) {
        return $this->client->post('themes/apply', [
            'theme' => $themeName,
            'municipality_id' => $municipalityId
        ]);
    }
    
    /**
     * Get theme preview
     */
    public function getThemePreview($baseColor) {
        return $this->client->post('themes/preview', [
            'color' => $baseColor
        ]);
    }
}
