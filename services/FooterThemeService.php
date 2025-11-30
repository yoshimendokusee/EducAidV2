<?php
/**
 * Footer Theme Service
 * Manages footer theme settings (colors, text, social links)
 */

class FooterThemeService {
    private $connection;
    private $tableName = 'footer_settings';

    public function __construct($connection) {
        $this->connection = $connection;
    }

    /**
     * Get current footer settings for a municipality
     * Contact info (phone/email) is fetched from theme_settings for consistency with topbar
     */
    public function getCurrentSettings($municipalityId = 1) {
        $query = "
            SELECT *
            FROM {$this->tableName}
            WHERE municipality_id = $1 AND is_active = TRUE
            LIMIT 1
        ";
        
        $result = pg_query_params($this->connection, $query, [$municipalityId]);
        
        if ($result && pg_num_rows($result) > 0) {
            $settings = pg_fetch_assoc($result);
            
            // Decode social_links JSON
            if (isset($settings['social_links'])) {
                $settings['social_links'] = json_decode($settings['social_links'], true);
            }
            
            // Fetch unified contact info from municipalities table (centralized source)
            $unifiedContact = $this->getUnifiedContactInfo($municipalityId);
            if ($unifiedContact) {
                $settings['contact_phone'] = $unifiedContact['contact_phone'];
                $settings['contact_email'] = $unifiedContact['contact_email'];
                $settings['contact_address'] = $unifiedContact['contact_address'];
                $settings['office_hours'] = $unifiedContact['office_hours'];
            }
            
            return $settings;
        }
        
        // Return defaults if no settings found
        return $this->getDefaultSettings();
    }
    
    /**
     * Get unified contact info from municipalities table
     * This ensures topbar, footer, and all pages display consistent contact information
     * Contact info is managed in the Municipality Content Hub
     */
    private function getUnifiedContactInfo($municipalityId = 1) {
        // First check if the contact columns exist in municipalities table
        $checkQuery = "SELECT column_name FROM information_schema.columns 
                       WHERE table_name = 'municipalities' AND column_name = 'contact_phone' LIMIT 1";
        $checkResult = pg_query($this->connection, $checkQuery);
        
        if (!$checkResult || pg_num_rows($checkResult) === 0) {
            // Columns don't exist yet, return null to use defaults
            return null;
        }
        
        $query = "
            SELECT contact_phone, contact_email, contact_address, office_hours 
            FROM municipalities 
            WHERE municipality_id = $1 
            LIMIT 1
        ";
        
        $result = pg_query_params($this->connection, $query, [$municipalityId]);
        
        if ($result && pg_num_rows($result) > 0) {
            $row = pg_fetch_assoc($result);
            return [
                'contact_phone' => $row['contact_phone'] ?? '',
                'contact_email' => $row['contact_email'] ?? '',
                'contact_address' => $row['contact_address'] ?? '',
                'office_hours' => $row['office_hours'] ?? ''
            ];
        }
        
        return null;
    }

    /**
     * Get default footer settings
     * Note: contact_phone and contact_email default to topbar values for consistency
     */
    private function getDefaultSettings() {
        return [
            'footer_bg_color' => '#1e3a8a',
            'footer_text_color' => '#cbd5e1',
            'footer_heading_color' => '#ffffff',
            'footer_link_color' => '#e2e8f0',
            'footer_link_hover_color' => '#fbbf24',
            'footer_divider_color' => '#fbbf24',
            'footer_title' => 'EducAid',
            'footer_description' => 'Making education accessible throughout General Trias City through innovative scholarship solutions.',
            'contact_address' => 'General Trias City Hall, Cavite',
            'contact_phone' => '(046) 886-4454',           // Matches topbar default
            'contact_email' => 'educaid@generaltrias.gov.ph' // Matches topbar default
        ];
    }

    /**
     * Save footer settings
     */
    public function save($data, $adminId, $municipalityId = 1) {
        try {
            // Validate colors
            $colors = [
                'footer_bg_color',
                'footer_bg_gradient',
                'footer_text_color',
                'footer_heading_color',
                'footer_link_color',
                'footer_link_hover_color',
                'footer_divider_color'
            ];

            foreach ($colors as $color) {
                if (isset($data[$color]) && !empty($data[$color])) {
                    if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $data[$color])) {
                        return [
                            'success' => false,
                            'message' => "Invalid color format for {$color}"
                        ];
                    }
                }
            }

            // Check if settings exist
            $checkQuery = "SELECT footer_id FROM {$this->tableName} WHERE municipality_id = $1 AND is_active = TRUE";
            $checkResult = pg_query_params($this->connection, $checkQuery, [$municipalityId]);
            
            if ($checkResult && pg_num_rows($checkResult) > 0) {
                // Update existing settings
                $row = pg_fetch_assoc($checkResult);
                $footerId = $row['footer_id'];
                
                $updateQuery = "
                    UPDATE {$this->tableName}
                    SET footer_bg_color = $1,
                        footer_text_color = $2,
                        footer_heading_color = $3,
                        footer_link_color = $4,
                        footer_link_hover_color = $5,
                        footer_divider_color = $6,
                        footer_title = $7,
                        footer_description = $8,
                        contact_address = $9,
                        contact_phone = $10,
                        contact_email = $11,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE footer_id = $12
                ";
                
                $params = [
                    $data['footer_bg_color'] ?? '#1e3a8a',
                    $data['footer_text_color'] ?? '#cbd5e1',
                    $data['footer_heading_color'] ?? '#ffffff',
                    $data['footer_link_color'] ?? '#e2e8f0',
                    $data['footer_link_hover_color'] ?? '#fbbf24',
                    $data['footer_divider_color'] ?? '#fbbf24',
                    $data['footer_title'] ?? 'EducAid',
                    $data['footer_description'] ?? '',
                    $data['contact_address'] ?? '',
                    $data['contact_phone'] ?? '',
                    $data['contact_email'] ?? '',
                    $footerId
                ];
                
                $result = pg_query_params($this->connection, $updateQuery, $params);
            } else {
                // Insert new settings
                $insertQuery = "
                    INSERT INTO {$this->tableName} (
                        municipality_id,
                        footer_bg_color,
                        footer_text_color,
                        footer_heading_color,
                        footer_link_color,
                        footer_link_hover_color,
                        footer_divider_color,
                        footer_title,
                        footer_description,
                        contact_address,
                        contact_phone,
                        contact_email,
                        is_active
                    ) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, TRUE)
                ";
                
                $params = [
                    $municipalityId,
                    $data['footer_bg_color'] ?? '#1e3a8a',
                    $data['footer_text_color'] ?? '#cbd5e1',
                    $data['footer_heading_color'] ?? '#ffffff',
                    $data['footer_link_color'] ?? '#e2e8f0',
                    $data['footer_link_hover_color'] ?? '#fbbf24',
                    $data['footer_divider_color'] ?? '#fbbf24',
                    $data['footer_title'] ?? 'EducAid',
                    $data['footer_description'] ?? '',
                    $data['contact_address'] ?? '',
                    $data['contact_phone'] ?? '',
                    $data['contact_email'] ?? ''
                ];
                
                $result = pg_query_params($this->connection, $insertQuery, $params);
            }

            if ($result) {
                return [
                    'success' => true,
                    'message' => 'Footer settings saved successfully'
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Failed to save footer settings: ' . pg_last_error($this->connection)
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Generate footer colors from primary/secondary colors
     */
    public function generateFromTheme($primaryColor, $secondaryColor) {
        // Use ColorGeneratorService for consistency
        require_once __DIR__ . '/ColorGeneratorService.php';
        $colorService = new ColorGeneratorService();
        
        // Generate footer colors based on primary (darker tones)
        $footerBg = ColorGeneratorService::darken($primaryColor, 0.2);
        
        // Generate text colors with proper contrast
        $footerText = ColorGeneratorService::lighten($footerBg, 0.6); // Light gray text
        $footerHeading = '#ffffff'; // Always white for headings
        $footerLink = ColorGeneratorService::lighten($footerText, 0.1);
        $footerLinkHover = $secondaryColor; // Use secondary color for hover
        
        return [
            'footer_bg_color' => $footerBg,
            'footer_text_color' => $footerText,
            'footer_heading_color' => $footerHeading,
            'footer_link_color' => $footerLink,
            'footer_link_hover_color' => $footerLinkHover,
            'footer_divider_color' => $footerLinkHover
        ];
    }
}
