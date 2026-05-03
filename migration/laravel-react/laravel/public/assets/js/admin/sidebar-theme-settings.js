/**
 * Sidebar Theme Settings JavaScript
 * Handles live preview functionality for sidebar theme customization
 */
class SidebarThemeSettings {
    constructor() {
        this.init();
    }
    
    init() {
        this.bindColorInputs();
        this.bindResetButton();
        this.bindFormSubmission();
    }
    
    /**
     * Bind color input events for live preview
     */
    bindColorInputs() {
        const colorInputs = [
            'sidebar_bg_start', 'sidebar_bg_end', 'sidebar_border_color',
            'nav_text_color', 'nav_icon_color', 'nav_hover_bg', 'nav_hover_text',
            'nav_active_bg', 'nav_active_text', 'profile_avatar_bg_start',
            'profile_avatar_bg_end', 'profile_name_color', 'profile_role_color',
            'profile_border_color', 'submenu_bg', 'submenu_text_color',
            'submenu_hover_bg', 'submenu_active_bg', 'submenu_active_text'
        ];
        
        colorInputs.forEach(inputId => {
            const colorInput = document.getElementById(inputId);
            if (colorInput) {
                colorInput.addEventListener('input', () => {
                    this.updateColorDisplay(colorInput);
                    this.updatePreview();
                });
            }
        });
    }
    
    /**
     * Update the text display next to color picker
     */
    updateColorDisplay(colorInput) {
        const textInput = colorInput.nextElementSibling;
        if (textInput && textInput.tagName === 'INPUT') {
            textInput.value = colorInput.value.toUpperCase();
        }
    }
    
    /**
     * Update the live preview
     */
    updatePreview() {
        const previewSidebar = document.getElementById('previewSidebar');
        const previewAvatar = document.getElementById('previewAvatar');
        const previewName = document.getElementById('previewName');
        const previewRole = document.getElementById('previewRole');
        
        if (!previewSidebar) return;
        
        // Update sidebar background
        const bgStart = this.getColorValue('sidebar_bg_start');
        const bgEnd = this.getColorValue('sidebar_bg_end');
        const borderColor = this.getColorValue('sidebar_border_color');
        
        previewSidebar.style.background = `linear-gradient(180deg, ${bgStart} 0%, ${bgEnd} 100%)`;
        previewSidebar.style.borderColor = borderColor;
        
        // Update profile avatar
        if (previewAvatar) {
            const avatarStart = this.getColorValue('profile_avatar_bg_start');
            const avatarEnd = this.getColorValue('profile_avatar_bg_end');
            previewAvatar.style.background = `linear-gradient(135deg, ${avatarStart}, ${avatarEnd})`;
        }
        
        // Update profile text colors
        if (previewName) {
            previewName.style.color = this.getColorValue('profile_name_color');
        }
        if (previewRole) {
            previewRole.style.color = this.getColorValue('profile_role_color');
        }
        
        // Update navigation colors
        const navItems = document.querySelectorAll('.preview-nav-item');
        const navTextColor = this.getColorValue('nav_text_color');
        const navIconColor = this.getColorValue('nav_icon_color');
        const activeNavBg = this.getColorValue('nav_active_bg');
        const activeNavText = this.getColorValue('nav_active_text');
        
        navItems.forEach(item => {
            if (!item.classList.contains('active')) {
                item.style.color = navTextColor;
                const icon = item.querySelector('i');
                if (icon) icon.style.color = navIconColor;
            } else {
                item.style.background = activeNavBg;
                item.style.color = activeNavText;
                const icon = item.querySelector('i');
                if (icon) icon.style.color = activeNavText;
            }
        });
        
        // Update submenu colors
        const submenuItems = document.querySelectorAll('.preview-submenu-item');
        const submenuTextColor = this.getColorValue('submenu_text_color');
        const submenuActiveBg = this.getColorValue('submenu_active_bg');
        const submenuActiveText = this.getColorValue('submenu_active_text');
        
        submenuItems.forEach(item => {
            if (!item.classList.contains('active')) {
                item.style.color = submenuTextColor;
            } else {
                item.style.background = submenuActiveBg;
                item.style.color = submenuActiveText;
            }
        });
        
        // Update submenu background
        const submenu = document.querySelector('.preview-submenu');
        if (submenu) {
            submenu.style.background = this.getColorValue('submenu_bg');
        }
    }
    
    /**
     * Get color value from input field
     */
    getColorValue(inputId) {
        const input = document.getElementById(inputId);
        // Return empty string instead of black so missing fields don't force black
        return input ? input.value : '';
    }
    
    /**
     * Bind reset to defaults button
     */
    bindResetButton() {
        const resetButton = document.getElementById('resetDefaults');
        if (resetButton) {
            resetButton.addEventListener('click', () => {
                if (confirm('Are you sure you want to reset all colors to default values?')) {
                    this.resetToDefaults();
                }
            });
        }
    }
    
    /**
     * Reset all colors to default values
     */
    resetToDefaults() {
        const defaults = {
            'sidebar_bg_start': '#f8f9fa',
            'sidebar_bg_end': '#ffffff',
            'sidebar_border_color': '#dee2e6',
            'nav_text_color': '#212529',
            'nav_icon_color': '#6c757d',
            'nav_hover_bg': '#e9ecef',
            'nav_hover_text': '#212529',
            'nav_active_bg': '#0d6efd',
            'nav_active_text': '#ffffff',
            'profile_avatar_bg_start': '#0d6efd',
            'profile_avatar_bg_end': '#0b5ed7',
            'profile_name_color': '#212529',
            'profile_role_color': '#6c757d',
            'profile_border_color': '#dee2e6',
            'submenu_bg': '#f8f9fa',
            'submenu_text_color': '#495057',
            'submenu_hover_bg': '#e9ecef',
            'submenu_active_bg': '#e7f3ff',
            'submenu_active_text': '#0d6efd'
        };
        
        Object.entries(defaults).forEach(([fieldId, defaultValue]) => {
            const input = document.getElementById(fieldId);
            if (input) {
                input.value = defaultValue;
                this.updateColorDisplay(input);
            }
        });
        
        this.updatePreview();
    }
    
    /**
     * Bind form submission with validation
     */
    bindFormSubmission() {
        const form = document.getElementById('sidebarSettingsForm');
        if (form) {
            form.addEventListener('submit', (e) => {
                if (!this.validateForm()) {
                    e.preventDefault();
                    this.showAlert('error', 'Please correct the errors before submitting.');
                }
            });
        }
    }
    
    /**
     * Validate form inputs
     */
    validateForm() {
        const colorInputs = document.querySelectorAll('input[type="color"]');
        let valid = true;
        
        colorInputs.forEach(input => {
            const value = input.value;
            if (!/^#[0-9A-Fa-f]{6}$/.test(value)) {
                this.showFieldError(input, 'Invalid color format');
                valid = false;
            } else {
                this.clearFieldError(input);
            }
        });
        
        return valid;
    }
    
    /**
     * Show field error
     */
    showFieldError(field, message) {
        field.classList.add('is-invalid');
        
        let errorDiv = field.parentNode.querySelector('.invalid-feedback');
        if (!errorDiv) {
            errorDiv = document.createElement('div');
            errorDiv.className = 'invalid-feedback';
            field.parentNode.appendChild(errorDiv);
        }
        errorDiv.textContent = message;
    }
    
    /**
     * Clear field error
     */
    clearFieldError(field) {
        field.classList.remove('is-invalid');
        const errorDiv = field.parentNode.querySelector('.invalid-feedback');
        if (errorDiv) {
            errorDiv.remove();
        }
    }
    
    /**
     * Show alert message
     */
    showAlert(type, message) {
        const alertDiv = document.createElement('div');
        alertDiv.className = `alert alert-${type === 'success' ? 'success' : 'danger'} alert-dismissible fade show`;
        alertDiv.innerHTML = `
            <i class="bi bi-${type === 'success' ? 'check-circle' : 'exclamation-triangle'} me-2"></i>
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        
        const container = document.querySelector('.container-fluid');
        if (container) {
            container.insertBefore(alertDiv, container.firstChild);
            
            // Auto-remove after 5 seconds
            setTimeout(() => {
                if (alertDiv.parentNode) {
                    alertDiv.remove();
                }
            }, 5000);
        }
    }
}

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    new SidebarThemeSettings();
});