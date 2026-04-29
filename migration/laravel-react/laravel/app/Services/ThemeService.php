<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ThemeService
 * Manages system themes, colors, and visual settings
 */
class ThemeService
{
    /**
     * Get current theme settings
     *
     * @return array Theme configuration
     */
    public function getTheme(): array
    {
        try {
            $settings = DB::table('theme_settings')
                ->where('active', true)
                ->first();

            if (!$settings) {
                return $this->getDefaultTheme();
            }

            return [
                'id' => $settings->id,
                'name' => $settings->name,
                'primary_color' => $settings->primary_color,
                'secondary_color' => $settings->secondary_color,
                'accent_color' => $settings->accent_color,
                'background_color' => $settings->background_color,
                'text_color' => $settings->text_color,
                'header_bg' => $settings->header_bg,
                'sidebar_bg' => $settings->sidebar_bg,
                'footer_bg' => $settings->footer_bg,
                'active' => true,
            ];
        } catch (Exception $e) {
            Log::error('ThemeService::getTheme failed', [
                'error' => $e->getMessage(),
            ]);
            return $this->getDefaultTheme();
        }
    }

    /**
     * Get default theme
     *
     * @return array Default theme
     */
    public function getDefaultTheme(): array
    {
        return [
            'name' => 'Default',
            'primary_color' => '#003d82',
            'secondary_color' => '#0066cc',
            'accent_color' => '#ff6600',
            'background_color' => '#ffffff',
            'text_color' => '#333333',
            'header_bg' => '#003d82',
            'sidebar_bg' => '#f5f5f5',
            'footer_bg' => '#003d82',
        ];
    }

    /**
     * Update theme settings
     *
     * @param array $settings New theme settings
     * @param int|null $adminId Admin ID making the change
     * @return array ['success' => bool, 'message' => string]
     */
    public function updateTheme(array $settings, ?int $adminId = null): array
    {
        try {
            DB::beginTransaction();

            // Deactivate current active theme
            DB::table('theme_settings')->update(['active' => false]);

            // Create new theme or update default
            $themeId = DB::table('theme_settings')->insertGetId([
                'name' => $settings['name'] ?? 'Custom Theme',
                'primary_color' => $settings['primary_color'] ?? '#003d82',
                'secondary_color' => $settings['secondary_color'] ?? '#0066cc',
                'accent_color' => $settings['accent_color'] ?? '#ff6600',
                'background_color' => $settings['background_color'] ?? '#ffffff',
                'text_color' => $settings['text_color'] ?? '#333333',
                'header_bg' => $settings['header_bg'] ?? '#003d82',
                'sidebar_bg' => $settings['sidebar_bg'] ?? '#f5f5f5',
                'footer_bg' => $settings['footer_bg'] ?? '#003d82',
                'active' => true,
                'updated_at' => now(),
            ]);

            // Log change if admin provided
            if ($adminId) {
                DB::table('audit_logs')->insert([
                    'admin_id' => $adminId,
                    'action' => 'theme_updated',
                    'table_name' => 'theme_settings',
                    'record_id' => (string)$themeId,
                    'details' => json_encode($settings),
                    'created_at' => now(),
                ]);
            }

            DB::commit();

            Log::info("ThemeService: Theme updated", [
                'theme_id' => $themeId,
                'admin_id' => $adminId,
            ]);

            return ['success' => true, 'message' => 'Theme updated successfully'];
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('ThemeService::updateTheme failed', [
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get all available themes
     *
     * @return array Array of themes
     */
    public function getAllThemes(): array
    {
        try {
            return DB::table('theme_settings')
                ->orderBy('created_at', 'desc')
                ->get()
                ->toArray();
        } catch (Exception $e) {
            Log::error('ThemeService::getAllThemes failed', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Reset to default theme
     *
     * @param int|null $adminId Admin ID making the change
     * @return array ['success' => bool, 'message' => string]
     */
    public function resetToDefault(?int $adminId = null): array
    {
        try {
            return $this->updateTheme($this->getDefaultTheme(), $adminId);
        } catch (Exception $e) {
            Log::error('ThemeService::resetToDefault failed', [
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
