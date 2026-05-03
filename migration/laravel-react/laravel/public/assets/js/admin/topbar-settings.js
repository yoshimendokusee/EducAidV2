/**
 * Topbar Settings JavaScript
 */
class TopbarSettings {
    constructor() {
        this.form = document.getElementById('settingsForm');
        this.submitButton = this.form ? document.getElementById('topbarSettingsSubmit') : null;
        this.originalButtonHtml = this.submitButton ? this.submitButton.innerHTML : '';
        this.alertContainer = document.querySelector('.container-fluid');
        this.previewBindings = [
            { field: 'topbar_email', preview: 'preview-email', fallback: 'educaid@generaltrias.gov.ph' },
            { field: 'topbar_phone', preview: 'preview-phone', fallback: '(046) 886-4454' },
            { field: 'topbar_office_hours', preview: 'preview-hours', fallback: 'Mon–Fri 8:00AM - 5:00PM' }
        ];
        this.init();
    }

    init() {
        this.bindTextInputs();
        this.bindColorPickers();
        this.bindGradientToggle();
        this.bindFormSubmission();
        this.syncPreviewText();
        this.updatePreview();
    }

    bindTextInputs() {
        this.previewBindings.forEach(cfg => {
            const el = document.getElementById(cfg.field);
            const pv = document.getElementById(cfg.preview);
            if (el && pv) {
                el.addEventListener('input', () => {
                    this.syncPreviewText();
                });
            }
        });
    }

    bindColorPickers() {
        const ids = [
            'topbar_bg_color','topbar_text_color','topbar_link_color',
            'header_bg_color','header_border_color','header_text_color','header_icon_color','header_hover_bg','header_hover_icon_color'
        ];
        ids.forEach(id => {
            const input = document.getElementById(id);
            if (!input) {
                return;
            }
            input.addEventListener('input', () => {
                const next = input.nextElementSibling;
                if (next && next.tagName === 'INPUT') {
                    next.value = input.value;
                }
                this.updatePreview();
            });
        });
    }

    bindGradientToggle() {
        this.gradientToggle = document.getElementById('topbar_gradient_enabled');
        this.gradientGroup = document.querySelector('[data-gradient-group]');
        this.gradientColor = document.getElementById('topbar_bg_gradient');
        this.gradientText = document.getElementById('topbar_bg_gradient_text');

        if (!this.gradientToggle || !this.gradientGroup || !this.gradientColor) {
            return;
        }

        const applyState = () => {
            const enabled = this.gradientToggle.checked;
            this.gradientGroup.classList.toggle('gradient-disabled', !enabled);

            if (enabled) {
                this.gradientColor.removeAttribute('disabled');
                if (!this.gradientColor.value && this.gradientColor.dataset.default) {
                    this.gradientColor.value = this.gradientColor.dataset.default;
                }
                if (this.gradientText) {
                    this.gradientText.value = this.gradientColor.value || this.gradientColor.dataset.default || '';
                }
            } else {
                this.gradientColor.setAttribute('disabled', 'disabled');
                if (this.gradientText) {
                    this.gradientText.value = 'Solid color only';
                }
            }

            this.updatePreview();
        };

        this.gradientToggle.addEventListener('change', applyState);
        this.gradientColor.addEventListener('input', () => {
            if (this.gradientText) {
                this.gradientText.value = this.gradientColor.value || '';
            }
            this.updatePreview();
        });

        applyState();
    }

    bindFormSubmission() {
        if (!this.form) {
            return;
        }

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

            const csrfInput = this.form.querySelector('input[name="csrf_token"]');
            if (payload.csrf_token && csrfInput) {
                csrfInput.value = payload.csrf_token;
            }

            if (!payload.success) {
                const message = payload.error || 'Unable to save settings. Please try again.';
                this.showAlert('danger', message);
                return;
            }

            this.applySanitizedValues(payload.topbar_settings || {}, payload.header_settings || {});
            this.updatePreview();
            this.updateAdminTopbar(payload.topbar_settings || {});

            const successMessage = payload.message && payload.message.trim() !== ''
                ? payload.message
                : 'Settings updated successfully.';
            this.showAlert('success', successMessage);
        } catch (error) {
            const fallback = error && error.message ? error.message : 'An unexpected error occurred while saving.';
            this.showAlert('danger', fallback);
            console.error('[TopbarSettings] Save error:', error);
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
            const message = data && data.error ? data.error : 'Failed to save settings.';
            throw new Error(message);
        }

        return data;
    }

    applySanitizedValues(topbarSettings, headerSettings) {
        const assign = (id, value) => {
            const input = document.getElementById(id);
            if (input !== null && input !== undefined) {
                input.value = value ?? '';
            }
        };

        ['topbar_email','topbar_phone','topbar_office_hours','system_name','municipality_name']
            .forEach(key => assign(key, topbarSettings[key] ?? ''));

        ['topbar_bg_color','topbar_text_color','topbar_link_color'].forEach(key => {
            if (!(key in topbarSettings)) {
                return;
            }
            const input = document.getElementById(key);
            if (input) {
                input.value = topbarSettings[key] || '';
                const companion = input.nextElementSibling;
                if (companion && companion.tagName === 'INPUT') {
                    companion.value = topbarSettings[key] || '';
                }
            }
        });

        const gradientValue = topbarSettings.topbar_bg_gradient || '';
        const gradientEnabled = gradientValue !== '';
        if (this.gradientToggle && this.gradientGroup && this.gradientColor) {
            this.gradientToggle.checked = gradientEnabled;
            this.gradientGroup.classList.toggle('gradient-disabled', !gradientEnabled);

            if (gradientEnabled) {
                this.gradientColor.removeAttribute('disabled');
                this.gradientColor.value = gradientValue;
                if (this.gradientText) {
                    this.gradientText.value = gradientValue;
                }
            } else {
                this.gradientColor.setAttribute('disabled', 'disabled');
                const fallback = this.gradientColor.dataset.default || '';
                this.gradientColor.value = gradientValue || fallback;
                if (this.gradientText) {
                    this.gradientText.value = 'Solid color only';
                }
            }
        }

        if (this.gradientColor) {
            const companion = this.gradientColor.nextElementSibling;
            if (companion && companion.tagName === 'INPUT' && companion !== this.gradientText) {
                companion.value = gradientValue || '';
            }
        }

        const headerFields = [
            'header_bg_color','header_border_color','header_text_color','header_icon_color','header_hover_bg','header_hover_icon_color'
        ];
        headerFields.forEach(key => {
            if (!(key in headerSettings)) {
                return;
            }
            const input = document.getElementById(key);
            if (input) {
                input.value = headerSettings[key] || '';
                const companion = input.nextElementSibling;
                if (companion && companion.tagName === 'INPUT') {
                    companion.value = headerSettings[key] || '';
                }
            }
        });

        this.syncPreviewText(topbarSettings);
    }

    syncPreviewText(topbarSettings) {
        this.previewBindings.forEach(cfg => {
            const previewEl = document.getElementById(cfg.preview);
            if (!previewEl) {
                return;
            }
            const input = document.getElementById(cfg.field);
            const raw = (topbarSettings && Object.prototype.hasOwnProperty.call(topbarSettings, cfg.field))
                ? topbarSettings[cfg.field]
                : (input ? input.value : '');
            const value = raw ? String(raw) : '';
            const display = value || cfg.fallback;
            if (previewEl.tagName === 'A') {
                previewEl.textContent = display;
                previewEl.href = value ? `mailto:${value}` : '#';
            } else {
                previewEl.textContent = display;
            }
        });
    }

    updateAdminTopbar(topbarSettings) {
        const adminTopbar = document.getElementById('adminTopbar');
        if (!adminTopbar || !topbarSettings) {
            return;
        }

        const bgColor = topbarSettings.topbar_bg_color || '#2e7d32';
        const gradientColor = topbarSettings.topbar_bg_gradient || '';
        const background = gradientColor
            ? `linear-gradient(135deg, ${bgColor} 0%, ${gradientColor} 100%)`
            : bgColor;
        adminTopbar.style.background = background;

        const textColor = topbarSettings.topbar_text_color || '#ffffff';
        adminTopbar.style.color = textColor;

        const linkColor = topbarSettings.topbar_link_color || textColor;
        adminTopbar.querySelectorAll('a').forEach(link => {
            link.style.color = linkColor;
        });

        adminTopbar.querySelectorAll('.bi').forEach(icon => {
            icon.style.color = textColor;
        });

        const emailLink = adminTopbar.querySelector('[data-topbar-email]');
        if (emailLink) {
            const emailValue = topbarSettings.topbar_email || '';
            emailLink.textContent = emailValue;
            emailLink.href = emailValue ? `mailto:${emailValue}` : '#';
            emailLink.style.color = linkColor;
        }

        const phoneSpan = adminTopbar.querySelector('[data-topbar-phone]');
        if (phoneSpan) {
            phoneSpan.textContent = topbarSettings.topbar_phone || '';
        }

        const hoursSpan = adminTopbar.querySelector('[data-topbar-hours]');
        if (hoursSpan) {
            hoursSpan.textContent = topbarSettings.topbar_office_hours || '';
        }

        const hoursMobile = adminTopbar.querySelector('[data-topbar-hours-mobile]');
        if (hoursMobile) {
            hoursMobile.textContent = topbarSettings.topbar_office_hours || 'Office Hours';
        }
    }

    updatePreview() {
        const bg = this.val('topbar_bg_color', '#2e7d32');
        const gradientEnabled = this.gradientToggle ? this.gradientToggle.checked : true;
        const gradientColor = (gradientEnabled && this.gradientColor && this.gradientColor.value)
            ? this.gradientColor.value
            : '';
        const textColor = this.val('topbar_text_color', '#ffffff');
        const linkColor = this.val('topbar_link_color', '#e8f5e9');

        const preview = document.querySelector('.preview-topbar');
        if (preview) {
            preview.style.background = gradientColor
                ? `linear-gradient(135deg, ${bg} 0%, ${gradientColor} 100%)`
                : bg;
            preview.style.color = textColor;
        }

        const previewEmail = document.getElementById('preview-email');
        if (previewEmail) {
            previewEmail.style.color = linkColor;
        }

        const headerPreview = document.getElementById('preview-header');
        if (headerPreview) {
            headerPreview.style.background = this.val('header_bg_color', '#ffffff');
            headerPreview.style.borderColor = this.val('header_border_color', '#e1e7e3');
            const title = document.getElementById('preview-header-title');
            if (title) {
                title.style.color = this.val('header_text_color', '#2e7d32');
            }
            const iconColor = this.val('header_icon_color', '#2e7d32');
            headerPreview.querySelectorAll('button, i').forEach(el => {
                el.style.color = iconColor;
            });
            const hoverBg = this.val('header_hover_bg', '#e9f5e9');
            const hoverIcon = this.val('header_hover_icon_color', '#1b5e20');
            if (!headerPreview.dataset.hoverBound) {
                headerPreview.dataset.hoverBound = '1';
                headerPreview.querySelectorAll('button').forEach(btn => {
                    btn.addEventListener('mouseenter', () => {
                        btn.style.background = hoverBg;
                        btn.style.color = hoverIcon;
                    });
                    btn.addEventListener('mouseleave', () => {
                        btn.style.background = '#f8fbf8';
                        btn.style.color = iconColor;
                    });
                });
            }
        }
    }

    val(id, fallback = '') {
        const el = document.getElementById(id);
        return el && el.value ? el.value : fallback;
    }

    validate() {
        const required = ['topbar_email','topbar_phone','topbar_office_hours'];
        let ok = true;
        this.clearErrors();

        required.forEach(id => {
            const el = document.getElementById(id);
            if (el && !el.value.trim()) {
                this.fieldError(el, 'This field is required');
                ok = false;
            }
        });

        const email = document.getElementById('topbar_email');
        if (email && email.value.trim()) {
            const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!re.test(email.value.trim())) {
                this.fieldError(email, 'Please enter a valid email address');
                ok = false;
            }
        }

        return ok;
    }

    fieldError(el, message) {
        el.classList.add('is-invalid');
        let feedback = el.parentNode.querySelector('.invalid-feedback');
        if (!feedback) {
            feedback = document.createElement('div');
            feedback.className = 'invalid-feedback';
            el.parentNode.appendChild(feedback);
        }
        feedback.textContent = message;
    }

    clearErrors() {
        document.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
        document.querySelectorAll('.invalid-feedback').forEach(el => el.remove());
    }

    clearAlerts() {
        document.querySelectorAll('.topbar-form-alert').forEach(alert => alert.remove());
    }

    showAlert(type, message) {
        if (!this.alertContainer) {
            return;
        }

        const alert = document.createElement('div');
        alert.className = `alert alert-${type} alert-dismissible fade show topbar-form-alert`;
        alert.setAttribute('role', 'alert');

        const iconClass = type === 'success' ? 'bi-check-circle' : 'bi-exclamation-triangle';
        alert.innerHTML = `
            <i class="bi ${iconClass} me-2"></i>${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;

        // Insert alert after the page header section, before the preview
        const previewCard = document.querySelector('.settings-card');
        if (previewCard) {
            previewCard.parentNode.insertBefore(alert, previewCard);
        } else {
            // Fallback to inserting at the top
            this.alertContainer.insertBefore(alert, this.alertContainer.firstChild);
        }
        
        alert.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    setButtonLoading(isLoading) {
        if (!this.submitButton) {
            return;
        }
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
    new TopbarSettings();
});