<?php
namespace App\Services;
require_once __DIR__ . '/ApiClient.php';

/**
 * ColorGeneratorService Wrapper
 * Forwards color generation operations to Laravel API
 */
class ColorGeneratorService {
    private ApiClient $client;
    
    public function __construct($connection = null, string $apiBase = null) {
        $this->client = new ApiClient($apiBase);
    }
    
    /**
     * Generate complementary color
     */
    public function generateComplementary($color) {
        return $this->client->post('themes/colors/complementary', [
            'color' => $color
        ]);
    }
    
    /**
     * Generate analogous colors
     */
    public function generateAnalogous($color) {
        return $this->client->post('themes/colors/analogous', [
            'color' => $color
        ]);
    }
    
    /**
     * Generate triadic colors
     */
    public function generateTriadic($color) {
        return $this->client->post('themes/colors/triadic', [
            'color' => $color
        ]);
    }
    
    /**
     * Generate gradient shades
     */
    public function generateGradient($startColor, $endColor, $steps = 5) {
        return $this->client->post('themes/colors/gradient', [
            'start_color' => $startColor,
            'end_color' => $endColor,
            'steps' => $steps
        ]);
    }
    
    /**
     * Validate color format
     */
    public function validateColor($color) {
        return $this->client->post('themes/colors/validate', [
            'color' => $color
        ]);
    }
    
    /**
     * Generate accessible contrast ratios
     */
    public function getContrastRatios($foreground, $background) {
        return $this->client->post('themes/colors/contrast', [
            'foreground' => $foreground,
            'background' => $background
        ]);
    }
}
