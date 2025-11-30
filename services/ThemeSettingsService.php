<?php

class ThemeSettingsService {
    private $connection;
    private $municipality_id;
    
    public function __construct($connection, $municipality_id = 1) {
        $this->connection = $connection;
        $this->municipality_id = $municipality_id;
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
     * Contact info (phone, email, office_hours) is fetched from municipalities table for consistency
     */
    public function getCurrentSettings() {
        $query = "SELECT * FROM theme_settings WHERE municipality_id = $1 AND is_active = TRUE LIMIT 1";
        $result = pg_query_params($this->connection, $query, [$this->municipality_id]);
        
        if ($result && pg_num_rows($result) > 0) {
            $settings = pg_fetch_assoc($result);
            
            // Override contact info from municipalities table (centralized source)
            $contactInfo = $this->getContactInfoFromMunicipality();
            if ($contactInfo) {
                $settings['topbar_phone'] = $contactInfo['contact_phone'];
                $settings['topbar_email'] = $contactInfo['contact_email'];
                $settings['topbar_office_hours'] = $contactInfo['office_hours'];
            }
            
            return $settings;
        }
        
        return $this->getDefaultSettings();
    }
    
    /**
     * Get contact info from municipalities table (centralized source)
     */
    private function getContactInfoFromMunicipality() {
        // Check if contact columns exist
        $checkQuery = "SELECT column_name FROM information_schema.columns 
                       WHERE table_name = 'municipalities' AND column_name = 'contact_phone' LIMIT 1";
        $checkResult = pg_query($this->connection, $checkQuery);
        
        if (!$checkResult || pg_num_rows($checkResult) === 0) {
            return null;
        }
        
        $query = "SELECT contact_phone, contact_email, office_hours FROM municipalities WHERE municipality_id = $1 LIMIT 1";
        $result = pg_query_params($this->connection, $query, [$this->municipality_id]);
        
        if ($result && pg_num_rows($result) > 0) {
            return pg_fetch_assoc($result);
        }
        
        return null;
    }
    
    /**
     * Validate form input data
     */
    public function validateInput($data) {
        $errors = [];
        
        // Required field validation
        $required_fields = ['topbar_email', 'topbar_phone', 'topbar_office_hours'];
        foreach ($required_fields as $field) {
            if (empty(trim($data[$field] ?? ''))) {
                $errors[] = "Please fill in the " . str_replace('topbar_', '', $field) . " field.";
            }
        }
        
        // Email validation
        if (!empty($data['topbar_email']) && !filter_var(trim($data['topbar_email']), FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }
        
        // Color validation
        $color_fields = ['topbar_bg_color', 'topbar_bg_gradient', 'topbar_text_color', 'topbar_link_color'];
        $gradientEnabled = !empty($data['topbar_gradient_enabled']);
        foreach ($color_fields as $field) {
            $color = trim($data[$field] ?? '');
            if ($field === 'topbar_bg_gradient' && !$gradientEnabled) {
                continue;
            }
            if (!empty($color) && !$this->isValidHexColor($color)) {
                $field_name = str_replace(['topbar_', '_'], ['', ' '], $field);
                $errors[] = "Please enter a valid hex color for {$field_name}.";
            }
        }
        
        return $errors;
    }
    
    /**
     * Validate hex color format
     */
    private function isValidHexColor($color) {
        return preg_match('/^#[0-9A-Fa-f]{6}$/', $color);
    }
    
    /**
     * Sanitize and prepare data for database
     */
    public function sanitizeData($data) {
        $sanitized = [];
        $fields = [
            'topbar_email', 'topbar_phone', 'topbar_office_hours',
            'topbar_bg_color', 'topbar_bg_gradient', 'topbar_text_color', 'topbar_link_color'
        ];
        
        $gradientEnabled = !empty($data['topbar_gradient_enabled']);

        foreach ($fields as $field) {
            $value = trim($data[$field] ?? '');
            if ($field === 'topbar_bg_gradient') {
                if (!$gradientEnabled) {
                    $sanitized[$field] = null;
                } else {
                    $sanitized[$field] = ($value === '') ? null : $value;
                }
            } else {
                $sanitized[$field] = $value;
            }
        }
        
        // Apply defaults for required color fields if empty (gradient remains optional)
        $color_defaults = $this->getDefaultSettings();
        foreach (['topbar_bg_color', 'topbar_text_color', 'topbar_link_color'] as $color_field) {
            if (empty($sanitized[$color_field])) {
                $sanitized[$color_field] = $color_defaults[$color_field];
            }
        }
        
        return $sanitized;
    }
    
    /**
     * Update theme settings in database
     */
    public function updateSettings($data, $admin_id) {
        $query = "INSERT INTO theme_settings (
                    municipality_id, topbar_email, topbar_phone, topbar_office_hours, 
                    topbar_bg_color, topbar_bg_gradient, 
                    topbar_text_color, topbar_link_color, updated_by
                  ) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9)
                  ON CONFLICT (municipality_id) 
                  DO UPDATE SET 
                    topbar_email = EXCLUDED.topbar_email,
                    topbar_phone = EXCLUDED.topbar_phone,
                    topbar_office_hours = EXCLUDED.topbar_office_hours,
                    topbar_bg_color = EXCLUDED.topbar_bg_color,
                    topbar_bg_gradient = EXCLUDED.topbar_bg_gradient,
                    topbar_text_color = EXCLUDED.topbar_text_color,
                    topbar_link_color = EXCLUDED.topbar_link_color,
                    updated_at = CURRENT_TIMESTAMP,
                    updated_by = EXCLUDED.updated_by";
        
        $params = [
            $this->municipality_id,
            $data['topbar_email'],
            $data['topbar_phone'],
            $data['topbar_office_hours'],
            $data['topbar_bg_color'],
            $data['topbar_bg_gradient'],
            $data['topbar_text_color'],
            $data['topbar_link_color'],
            $admin_id
        ];
        
        return pg_query_params($this->connection, $query, $params);
    }
    
    /**
     * Log settings changes for audit trail
     */
    public function logSettingsChange($admin_id, $changes) {
        $log_query = "INSERT INTO admin_activity_log (admin_id, action, details, timestamp) 
                      VALUES ($1, $2, $3, CURRENT_TIMESTAMP)";
        
        $details = json_encode([
            'action' => 'theme_settings_updated',
            'changes' => $changes,
            'municipality_id' => $this->municipality_id
        ]);
        
        pg_query_params($this->connection, $log_query, [
            $admin_id,
            'Theme Settings Updated',
            $details
        ]);
    }
}