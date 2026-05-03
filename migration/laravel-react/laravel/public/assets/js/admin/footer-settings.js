/**
 * Footer Settings JavaScript
 * Handles form submission, preview updates, and AJAX
 */
class FooterSettings {
    constructor() {
        this.form = document.getElementById('settingsForm');
        this.submitButton = this.form ? document.getElementById('footerSettingsSubmit') : null;
        this.originalButtonHtml = this.submitButton ? this.submitButton.innerHTML : '';
        this.previewBindings = [
            { field: 'footer_title', preview: 'preview-title', fallback: 'EducAid' },
            { field: 'footer_description', preview: 'preview-description', fallback: 'Making education accessible.' },
            { field: 'contact_address', preview: 'preview-address', fallback: 'General Trias City Hall, Cavite' },
            { field: 'contact_phone', preview: 'preview-phone', fallback: '+63 (046) 123-4567' },
            { field: 'contact_email', preview: 'preview-email', fallback: 'info@educaid.gov.ph' }
        ];
        this.init();
    }

    init() {
        this.bindTextInputs();
        this.bindColorPickers();
        this.bindFormSubmission();
        this.updatePreview();
    }

    bindTextInputs() {
        this.previewBindings.forEach(cfg => {
            const el = document.getElementById(cfg.field);
            const pv = document.getElementById(cfg.preview);
            if (el && pv) {
                el.addEventListener('input', () => {
                    const value = el.value || cfg.fallback;
                    pv.textContent = value;
                });
            }
        });
    }

    bindColorPickers() {
        const colorInputs = [
            'footer_bg_color',
            'footer_text_color',
            'footer_heading_color',
            'footer_link_color',
            'footer_link_hover_color',
            'footer_divider_color'
        ];
        
        colorInputs.forEach(id => {
            const input = document.getElementById(id);
            if (!input) return;
            
            input.addEventListener('input', () => {
                const next = input.nextElementSibling;
                if (next && next.tagName === 'INPUT') {
                    next.value = input.value;
                }
                this.updatePreview();
            });
        });
    }

    bindFormSubmission() {
        if (!this.form) return;

        this.form.addEventListener('submit', event => {
            event.preventDefault();
            this.clearAlerts();

            if (!this.validate()) {
                this.showAlert('danger', 'Please correct the errors below before saving.');
                return;
            }

            const formData = new FormData(this.form);
            formData.append('ajax', '1');
            this.submitForm(formData);
        });
    }

    async submitForm(formData) {
        this.setButtonLoading(true);

        try {
            const response = await fetch(this.form.action || window.location.href, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData,
                credentials: 'same-origin'
            });

            const payload = await this.parseJson(response);

            // Update CSRF token
            const csrfInput = this.form.querySelector('input[name="csrf_token"]');
            if (payload.csrf_token && csrfInput) {
                csrfInput.value = payload.csrf_token;
            }

            if (!payload.success) {
                const message = payload.error || 'Unable to save footer settings. Please try again.';
                this.showAlert('danger', message);
                return;
            }

            // Apply sanitized values
            if (payload.footer_settings) {
                this.applySanitizedValues(payload.footer_settings);
            }
            
            this.updatePreview();

            const successMessage = payload.message && payload.message.trim() !== ''
                ? payload.message
                : 'Footer settings updated successfully.';
            this.showAlert('success', successMessage);
        } catch (error) {
            const fallback = error && error.message ? error.message : 'An unexpected error occurred while saving.';
            this.showAlert('danger', fallback);
            console.error('[FooterSettings] Save error:', error);
        } finally {
            this.setButtonLoading(false);
        }
    }

    async parseJson(response) {
        const text = await response.text();
        let data;
        try {
            data = text ? JSON.parse(text) : {};
        } catch (error) {
            throw new Error('The server returned an unexpected response.');
        }

        if (!response.ok) {
            const message = data && data.error ? data.error : 'Failed to save footer settings.';
            throw new Error(message);
        }

        return data;
    }

    applySanitizedValues(settings) {
        const assign = (id, value) => {
            const input = document.getElementById(id);
            if (input !== null && input !== undefined) {
                input.value = value ?? '';
            }
        };

        // Text fields
        ['footer_title', 'footer_description', 'contact_address', 'contact_phone', 'contact_email']
            .forEach(key => assign(key, settings[key] ?? ''));

        // Color fields
        ['footer_bg_color', 'footer_text_color', 'footer_heading_color', 'footer_link_color', 'footer_link_hover_color', 'footer_divider_color']
            .forEach(key => {
                if (!(key in settings)) return;
                const input = document.getElementById(key);
                if (input) {
                    input.value = settings[key] || '';
                    const companion = input.nextElementSibling;
                    if (companion && companion.tagName === 'INPUT') {
                        companion.value = settings[key] || '';
                    }
                }
            });
    }

    updatePreview() {
        const preview = document.getElementById('preview-footer');
        if (!preview) return;

        const bgColor = document.getElementById('footer_bg_color')?.value || '#1e3a8a';
        const textColor = document.getElementById('footer_text_color')?.value || '#cbd5e1';
        const headingColor = document.getElementById('footer_heading_color')?.value || '#ffffff';
        const linkColor = document.getElementById('footer_link_color')?.value || '#e2e8f0';
        const hoverColor = document.getElementById('footer_link_hover_color')?.value || '#fbbf24';
        const dividerColor = document.getElementById('footer_divider_color')?.value || '#fbbf24';

        preview.style.background = bgColor;
        preview.style.color = textColor;

        // Update badge (EA) with hover color and background
        const badge = preview.querySelector('#preview-badge');
        if (badge) {
            badge.style.background = hoverColor;
            badge.style.color = bgColor;
        }

        // Update heading colors
        preview.querySelectorAll('h5, h6, #preview-title').forEach(el => {
            el.style.color = headingColor;
        });

        // Update text colors
        preview.querySelectorAll('p, small, #preview-description, li, span').forEach(el => {
            if (!el.querySelector('a') && !el.closest('a')) {
                el.style.color = textColor;
            }
        });

        // Update link colors
        preview.querySelectorAll('a').forEach(el => {
            el.style.color = linkColor;
        });

        // Update divider
        const divider = preview.querySelector('hr');
        if (divider) {
            divider.style.borderColor = dividerColor;
        }
    }

    validate() {
        const title = document.getElementById('footer_title');
        if (!title || !title.value.trim()) {
            return false;
        }

        const email = document.getElementById('contact_email');
        if (email && email.value && !this.isValidEmail(email.value)) {
            return false;
        }

        return true;
    }

    isValidEmail(email) {
        const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return re.test(email);
    }

    clearAlerts() {
        document.querySelectorAll('.footer-form-alert').forEach(alert => alert.remove());
    }

    showAlert(type, message) {
        const container = document.querySelector('.container-fluid');
        if (!container) return;

        const alert = document.createElement('div');
        alert.className = `alert alert-${type} alert-dismissible fade show footer-form-alert`;
        alert.setAttribute('role', 'alert');

        const iconClass = type === 'success' ? 'bi-check-circle' : 'bi-exclamation-triangle';
        alert.innerHTML = `
            <i class="bi ${iconClass} me-2"></i>${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;

        const previewCard = document.querySelector('.settings-card');
        if (previewCard) {
            previewCard.parentNode.insertBefore(alert, previewCard);
        } else {
            container.insertBefore(alert, container.firstChild);
        }

        alert.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    setButtonLoading(isLoading) {
        if (!this.submitButton) return;
        
        if (isLoading) {
            this.submitButton.disabled = true;
            this.submitButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Saving...';
        } else {
            this.submitButton.disabled = false;
            this.submitButton.innerHTML = this.originalButtonHtml;
        }
    }
}

document.addEventListener('DOMContentLoaded', () => {
    new FooterSettings();
});
