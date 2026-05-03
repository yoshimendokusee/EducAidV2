/**
 * EducAid Password Strength Validator
 * 
 * Reusable password validation system with real-time strength calculation
 * and visual feedback. Can be used across the application for any password
 * input requirements.
 * 
 * USAGE:
 * 1. Include this script in your HTML
 * 2. Add the required HTML elements (see HTML structure below)
 * 3. Call setupPasswordValidation() with configuration
 * 
 * @author EducAid Development Team
 * @version 1.0.0
 */

/**
 * Setup password validation with real-time strength feedback
 * 
 * @param {Object} config - Configuration object
 * @param {string} config.passwordInputId - ID of the password input field (default: 'password')
 * @param {string} config.confirmPasswordInputId - ID of the confirm password input (optional)
 * @param {string} config.strengthBarId - ID of the strength progress bar (default: 'strengthBar')
 * @param {string} config.strengthTextId - ID of the strength text element (default: 'strengthText')
 * @param {string} config.passwordMatchTextId - ID of the password match text (optional)
 * @param {string} config.submitButtonSelector - CSS selector for submit button (optional)
 * @param {string} config.currentPasswordInputId - ID of current password field to check for reuse (optional)
 * @param {number} config.minStrength - Minimum strength required (0-100, default: 70)
 * @param {boolean} config.requireMatch - Whether confirm password is required (default: true)
 * @param {function} config.onValidationChange - Callback when validation state changes
 */
function setupPasswordValidation(config = {}) {
    // Default configuration
    const settings = {
        passwordInputId: 'password',
        confirmPasswordInputId: 'confirmPassword',
        strengthBarId: 'strengthBar',
        strengthTextId: 'strengthText',
        passwordMatchTextId: 'passwordMatchText',
        submitButtonSelector: null,
        currentPasswordInputId: null,
        minStrength: 70,
        requireMatch: true,
        onValidationChange: null,
        ...config
    };

    console.log('🔐 Setting up password validation with config:', settings);

    // Get DOM elements
    const passwordInput = document.getElementById(settings.passwordInputId);
    const confirmPasswordInput = settings.requireMatch ? document.getElementById(settings.confirmPasswordInputId) : null;
    const currentPasswordInput = settings.currentPasswordInputId ? document.getElementById(settings.currentPasswordInputId) : null;
    const strengthBar = document.getElementById(settings.strengthBarId);
    const strengthText = document.getElementById(settings.strengthTextId);
    const passwordMatchText = settings.requireMatch ? document.getElementById(settings.passwordMatchTextId) : null;
    const submitButton = settings.submitButtonSelector ? document.querySelector(settings.submitButtonSelector) : null;

    // Validation check
    if (!passwordInput) {
        console.error(`❌ Password input not found: #${settings.passwordInputId}`);
        return null;
    }

    if (!strengthBar || !strengthText) {
        console.error('❌ Strength indicator elements not found');
        return null;
    }

    console.log('✅ All required elements found');

    /**
     * Calculate password strength using a 100-point system with pattern detection
     * 
     * Base scoring:
     * - Length (12+ chars): 20 points
     * - Uppercase letter: 20 points
     * - Lowercase letter: 20 points
     * - Number: 15 points
     * - Special character: 10 points
     * 
     * Bonus points:
     * - Extra length (16+ chars): +5 points
     * - Multiple character types mixed well: +10 points
     * 
     * Penalties:
     * - Repeated characters (aaa, 111): -15 points
     * - Sequential characters (abc, 123): -15 points
     * - Common patterns (qwerty, password): -20 points
     * - Keyboard patterns (asdf, zxcv): -15 points
     * 
     * @param {string} password - Password to evaluate
     * @returns {Object} { strength: number, feedback: array }
     */
    function calculatePasswordStrength(password) {
        let strength = 0;
        let feedback = [];
        let penalties = [];

        // CRITICAL CHECK: Password reuse detection
        if (currentPasswordInput && currentPasswordInput.value && password === currentPasswordInput.value) {
            return {
                strength: 0,
                feedback: ['⛔ You cannot reuse your current password'],
                penalties: ['password reuse'],
                isReused: true
            };
        }

        // REQUIREMENT 1: Minimum 12 characters (20 points)
        if (password.length >= 12) {
            strength += 20;
            // Bonus for extra length
            if (password.length >= 16) {
                strength += 5;
            }
        } else if (password.length > 0) {
            feedback.push(`${12 - password.length} more character${12 - password.length > 1 ? 's' : ''}`);
        }

        // REQUIREMENT 2: Uppercase letter (20 points)
        if (/[A-Z]/.test(password)) {
            strength += 20;
        } else if (password.length > 0) {
            feedback.push('uppercase letter (A-Z)');
        }

        // REQUIREMENT 3: Lowercase letter (20 points)
        if (/[a-z]/.test(password)) {
            strength += 20;
        } else if (password.length > 0) {
            feedback.push('lowercase letter (a-z)');
        }

        // REQUIREMENT 4: Number (15 points)
        if (/[0-9]/.test(password)) {
            strength += 15;
        } else if (password.length > 0) {
            feedback.push('number (0-9)');
        }

        // REQUIREMENT 5: Special character (10 points)
        if (/[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]/.test(password)) {
            strength += 10;
        } else if (password.length > 0) {
            feedback.push('special character (!@#$%...)');
        }

        // BONUS: Good character distribution (+10 points)
        if (password.length >= 12) {
            const hasGoodMix = /[A-Z]/.test(password) && /[a-z]/.test(password) && 
                               /[0-9]/.test(password) && /[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]/.test(password);
            if (hasGoodMix) {
                // Check if characters are well distributed (not all grouped together)
                const uppercasePositions = [...password].map((c, i) => /[A-Z]/.test(c) ? i : -1).filter(i => i >= 0);
                const lowercasePositions = [...password].map((c, i) => /[a-z]/.test(c) ? i : -1).filter(i => i >= 0);
                const numberPositions = [...password].map((c, i) => /[0-9]/.test(c) ? i : -1).filter(i => i >= 0);
                
                const isWellMixed = uppercasePositions.length > 1 || lowercasePositions.length > 1 || 
                                   numberPositions.length > 1;
                if (isWellMixed) {
                    strength += 10;
                }
            }
        }

        // PENALTY 1: Repeated characters (aaa, 111, @@@) - reduce quality
        const repeatedChars = password.match(/(.)\1{2,}/g);
        if (repeatedChars && repeatedChars.length > 0) {
            strength -= 15;
            penalties.push('repeated characters');
        }

        // PENALTY 2: Sequential characters (abc, 123, xyz)
        const hasSequential = /abc|bcd|cde|def|efg|fgh|ghi|hij|ijk|jkl|klm|lmn|mno|nop|opq|pqr|qrs|rst|stu|tuv|uvw|vwx|wxy|xyz|012|123|234|345|456|567|678|789/i.test(password);
        if (hasSequential) {
            strength -= 15;
            penalties.push('sequential characters');
        }

        // PENALTY 3: Most common weak passwords and patterns
        // Based on real-world data breaches and common password lists
        const commonPatterns = [
            // Top 20 most common passwords
            '123456', '123456789', '12345678', 'password', '12345', 
            '1234567', '1234567890', 'qwerty', 'abc123', 'password1',
            '111111', '123123', '1234', 'password123', '1q2w3e4r',
            'iloveyou', 'admin', 'welcome', 'monkey', 'login',
            
            // Common variations with substitutions
            'p@ssword', 'passw0rd', 'p@ssw0rd', 'pa$$word', 'p4ssword',
            'passw@rd', 'pass123', 'pass@123', 'p@ss123', 'admin123',
            'admin@123', 'adm1n', '@dmin', 'root123', 'user123',
            
            // Common phrases
            'letmein', 'letme1n', 'welcome1', 'welc0me', 'sunshine',
            'princess', 'dragon', 'master', 'superman', 'batman',
            'starwars', 'shadow', 'michael', 'jennifer', 'computer',
            'trustno1', 'freedom', 'whatever', 'football', 'baseball',
            
            // Sequential and repeated patterns
            '000000', '123321', '654321', '666666', '999999',
            'aaaaaa', 'abcdef', 'qwerty123', 'zxcvbn', 'asdfgh',
            
            // Common with year
            'password2024', 'password2025', 'admin2024', 'admin2025',
            'welcome2024', 'welcome2025', '20242024', '20252025',
            
            // Location and name patterns (common)
            'london', 'newyork', 'password!', 'Password1', 'Password123',
            'Password1!', 'Pass123!', 'Qwerty123', 'Abc123', 'Admin123'
        ];
        
        const lowerPassword = password.toLowerCase();
        const originalPassword = password;
        
        // Check for exact match (case insensitive)
        for (const pattern of commonPatterns) {
            if (lowerPassword === pattern.toLowerCase()) {
                strength -= 30; // Heavier penalty for exact match
                penalties.push('extremely common password');
                break;
            } else if (lowerPassword.includes(pattern.toLowerCase())) {
                strength -= 20;
                penalties.push('contains common password');
                break;
            }
        }
        
        // Check for common password with numbers at end (password123, admin2024, etc.)
        if (/^(password|admin|welcome|qwerty|letmein|monkey|dragon)\d+$/i.test(password)) {
            strength -= 25;
            penalties.push('common word + numbers');
        }

        // PENALTY 4: Keyboard patterns (qwerty, asdf, zxcv)
        const keyboardPatterns = [
            'qwerty', 'asdfgh', 'zxcvbn', 'qazwsx', 'qwertyuiop',
            '!@#$%^', '1qaz2wsx', 'zaq1xsw2'
        ];
        for (const pattern of keyboardPatterns) {
            if (lowerPassword.includes(pattern)) {
                strength -= 15;
                penalties.push('keyboard pattern');
                break;
            }
        }

        // PENALTY 5: All same character type (e.g., all lowercase or all numbers)
        if (password.length >= 12) {
            const onlyLowercase = /^[a-z]+$/.test(password);
            const onlyUppercase = /^[A-Z]+$/.test(password);
            const onlyNumbers = /^[0-9]+$/.test(password);
            const onlySpecial = /^[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]+$/.test(password);
            
            if (onlyLowercase || onlyUppercase || onlyNumbers || onlySpecial) {
                strength -= 25;
                penalties.push('single character type only');
            }
        }

        // Ensure strength stays within 0-100 range
        strength = Math.max(0, Math.min(100, strength));

        // Add penalty warnings to feedback if password is weak
        if (penalties.length > 0 && strength < 70) {
            feedback.push(`⚠️ Avoid: ${penalties.join(', ')}`);
        }

        return { strength, feedback, penalties, isReused: false };
    }

    /**
     * Update the visual strength indicator
     */
    function updatePasswordStrengthUI() {
        const password = passwordInput.value;
        const { strength, feedback, penalties, isReused } = calculatePasswordStrength(password);

        strengthBar.style.width = strength + '%';
        strengthBar.setAttribute('aria-valuenow', strength);

        // CRITICAL: Password reuse detected
        if (isReused) {
            strengthBar.className = 'progress-bar bg-danger';
            strengthBar.style.width = '100%'; // Full red bar for emphasis
            strengthText.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-1"></i><strong>Password Reuse Detected!</strong> You must use a different password';
            strengthText.className = 'text-danger d-block mt-1 fw-bold';
            
            if (settings.requireMatch) {
                checkPasswordMatch();
            } else {
                updateValidationState();
            }
            return;
        }

        if (password.length === 0) {
            // Empty state
            strengthBar.className = 'progress-bar bg-secondary';
            strengthText.innerHTML = '<i class="bi bi-info-circle me-1"></i>Enter a password to see strength';
            strengthText.className = 'text-muted d-block mt-1';
        } else if (strength < 40) {
            // RED: Weak
            strengthBar.className = 'progress-bar bg-danger';
            let message = '<i class="bi bi-x-circle me-1"></i><strong>Weak</strong>';
            if (feedback.length > 0) {
                message += ' - ' + feedback.join(' • ');
            }
            strengthText.innerHTML = message;
            strengthText.className = 'text-danger d-block mt-1 fw-bold';
        } else if (strength < 70) {
            // YELLOW: Fair
            strengthBar.className = 'progress-bar bg-warning';
            let message = '<i class="bi bi-exclamation-triangle me-1"></i><strong>Fair</strong>';
            if (feedback.length > 0) {
                message += ' - ' + feedback.join(' • ');
            }
            strengthText.innerHTML = message;
            strengthText.className = 'text-warning d-block mt-1 fw-bold';
        } else if (strength < 95) {
            // BLUE: Good
            strengthBar.className = 'progress-bar bg-info';
            let message = '<i class="bi bi-check-circle me-1"></i><strong>Good</strong>';
            if (feedback.length > 0) {
                message += ' - Could add: ' + feedback.filter(f => !f.startsWith('⚠️')).join(', ');
            } else {
                message += ' - Strong password!';
            }
            strengthText.innerHTML = message;
            strengthText.className = 'text-info d-block mt-1 fw-bold';
        } else {
            // GREEN: Excellent
            strengthBar.className = 'progress-bar bg-success';
            strengthText.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i><strong>Excellent!</strong> Very strong password';
            strengthText.className = 'text-success d-block mt-1 fw-bold';
        }

        if (settings.requireMatch) {
            checkPasswordMatch();
        } else {
            updateValidationState();
        }
    }

    /**
     * Check if passwords match
     */
    function checkPasswordMatch() {
        if (!confirmPasswordInput || !passwordMatchText) {
            updateValidationState();
            return;
        }

        const password = passwordInput.value;
        const confirmPassword = confirmPasswordInput.value;

        if (confirmPassword.length === 0) {
            passwordMatchText.innerHTML = '<i class="bi bi-info-circle me-1"></i>Re-enter your password to confirm';
            passwordMatchText.className = 'text-muted d-block mt-1';
            confirmPasswordInput.classList.remove('is-valid', 'is-invalid');
        } else if (password === confirmPassword) {
            passwordMatchText.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i><strong>Passwords match!</strong>';
            passwordMatchText.className = 'text-success d-block mt-1 fw-bold';
            confirmPasswordInput.classList.remove('is-invalid');
            confirmPasswordInput.classList.add('is-valid');
        } else {
            passwordMatchText.innerHTML = '<i class="bi bi-x-circle-fill me-1"></i><strong>Passwords do not match</strong>';
            passwordMatchText.className = 'text-danger d-block mt-1 fw-bold';
            confirmPasswordInput.classList.remove('is-valid');
            confirmPasswordInput.classList.add('is-invalid');
        }

        updateValidationState();
    }

    /**
     * Update validation state and submit button
     */
    function updateValidationState() {
        const password = passwordInput.value;
        const confirmPassword = confirmPasswordInput ? confirmPasswordInput.value : password;
        const { strength, isReused } = calculatePasswordStrength(password);

        const isPasswordStrong = strength >= settings.minStrength && !isReused;
        const doPasswordsMatch = !settings.requireMatch || (password === confirmPassword && password.length > 0);
        const isValid = isPasswordStrong && doPasswordsMatch && !isReused;

        // Update submit button if provided
        if (submitButton) {
            if (!isValid) {
                submitButton.disabled = true;
                submitButton.classList.add('opacity-50');
                submitButton.style.cursor = 'not-allowed';
                submitButton.title = 'Complete all password requirements to enable';
            } else {
                submitButton.disabled = false;
                submitButton.classList.remove('opacity-50');
                submitButton.style.cursor = 'pointer';
                submitButton.title = '';
            }
        }

        // Call validation callback if provided
        if (settings.onValidationChange) {
            settings.onValidationChange({
                isValid,
                strength,
                isPasswordStrong,
                doPasswordsMatch,
                password
            });
        }
    }

    // Initialize
    if (submitButton) {
        submitButton.disabled = true;
        submitButton.classList.add('opacity-50');
    }

    if (passwordMatchText) {
        passwordMatchText.innerHTML = '<i class="bi bi-info-circle me-1"></i>Re-enter your password to confirm';
        passwordMatchText.className = 'text-muted d-block mt-1';
    }

    if (strengthText && passwordInput.value.length === 0) {
        strengthText.innerHTML = '<i class="bi bi-info-circle me-1"></i>Enter a password to see strength';
        strengthText.className = 'text-muted d-block mt-1';
    }

    // Attach event listeners
    passwordInput.addEventListener('input', updatePasswordStrengthUI);
    passwordInput.addEventListener('keyup', updatePasswordStrengthUI);

    if (confirmPasswordInput) {
        confirmPasswordInput.addEventListener('input', checkPasswordMatch);
        confirmPasswordInput.addEventListener('keyup', checkPasswordMatch);
    }

    // Initial validation if fields have values
    if (passwordInput.value.length > 0) {
        updatePasswordStrengthUI();
    }

    console.log('✅ Password validation setup complete');

    // Return API for programmatic control
    return {
        validate: () => {
            updatePasswordStrengthUI();
            const password = passwordInput.value;
            const { strength } = calculatePasswordStrength(password);
            const isValid = strength >= settings.minStrength;
            return isValid;
        },
        getStrength: () => {
            const password = passwordInput.value;
            return calculatePasswordStrength(password);
        },
        reset: () => {
            passwordInput.value = '';
            if (confirmPasswordInput) confirmPasswordInput.value = '';
            updatePasswordStrengthUI();
        }
    };
}

// Auto-initialize if default IDs exist
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        if (document.getElementById('password') && document.getElementById('strengthBar')) {
            setupPasswordValidation();
        }
    });
} else {
    if (document.getElementById('password') && document.getElementById('strengthBar')) {
        setupPasswordValidation();
    }
}
