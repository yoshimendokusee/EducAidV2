// EducAid Login System - Complete JavaScript Implementation
(function() {
    'use strict';

    // Utility Functions
    function hideAllSteps() {
        document.querySelectorAll('.step').forEach(step => {
            step.classList.remove('active');
        });
    }

    function showMessage(message, type) {
        const messagesContainer = document.getElementById('messages');
        messagesContainer.innerHTML = `
            <div class="alert alert-${type} alert-dismissible fade show" role="alert">
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        `;
        
        // Auto-dismiss after 5 seconds
        setTimeout(() => {
            const alert = messagesContainer.querySelector('.alert');
            if (alert) {
                alert.remove();
            }
        }, 5000);
    }

    function clearMessages() {
        const messagesContainer = document.getElementById('messages');
        messagesContainer.innerHTML = '';
    }

    function updateStepIndicators(activeStep) {
        const stepIndicatorContainer = document.querySelector('.step-indicators');
        if (!stepIndicatorContainer) return;
        
        // Reset all indicators
        document.querySelectorAll('.step-indicator').forEach(indicator => {
            indicator.classList.remove('active');
        });
        
        // Hide indicators for main login step
        if (activeStep === 'step1') {
            stepIndicatorContainer.style.display = 'none';
            return;
        }
        
        // Show indicators for multi-step processes
        stepIndicatorContainer.style.display = 'flex';
        
        // Handle different flows
        if (activeStep === 'step2') {
            // Login OTP verification - Show 2 steps: Email → Verify
            document.getElementById('label1').textContent = 'Email';
            document.getElementById('label2').textContent = 'Verify';
            document.getElementById('label3').textContent = '';
            
            // Hide third indicator for login flow
            document.querySelector('.step-indicator-item:nth-child(3)').style.display = 'none';
            document.querySelector('.step-indicator-item:nth-child(1)').style.display = 'flex';
            document.querySelector('.step-indicator-item:nth-child(2)').style.display = 'flex';
            
            // Mark first two as active
            document.getElementById('indicator1').classList.add('active');
            document.getElementById('indicator2').classList.add('active');
            
        } else if (activeStep === 'forgotStep1') {
            // Forgot password email - Show 3 steps: Email → Verify → Password
            document.getElementById('label1').textContent = 'Email';
            document.getElementById('label2').textContent = 'Verify';
            document.getElementById('label3').textContent = 'Password';
            
            // Show all three indicators for forgot password flow
            document.querySelector('.step-indicator-item:nth-child(1)').style.display = 'flex';
            document.querySelector('.step-indicator-item:nth-child(2)').style.display = 'flex';
            document.querySelector('.step-indicator-item:nth-child(3)').style.display = 'flex';
            
            // Mark first as active
            document.getElementById('indicator1').classList.add('active');
            
        } else if (activeStep === 'forgotStep2') {
            // Forgot password OTP verification
            document.getElementById('label1').textContent = 'Email';
            document.getElementById('label2').textContent = 'Verify';
            document.getElementById('label3').textContent = 'Password';
            
            // Show all three indicators
            document.querySelector('.step-indicator-item:nth-child(1)').style.display = 'flex';
            document.querySelector('.step-indicator-item:nth-child(2)').style.display = 'flex';
            document.querySelector('.step-indicator-item:nth-child(3)').style.display = 'flex';
            
            // Mark first two as active
            document.getElementById('indicator1').classList.add('active');
            document.getElementById('indicator2').classList.add('active');
            
        } else if (activeStep === 'forgotStep3') {
            // Forgot password new password
            document.getElementById('label1').textContent = 'Email';
            document.getElementById('label2').textContent = 'Verify';
            document.getElementById('label3').textContent = 'Password';
            
            // Show all three indicators
            document.querySelector('.step-indicator-item:nth-child(1)').style.display = 'flex';
            document.querySelector('.step-indicator-item:nth-child(2)').style.display = 'flex';
            document.querySelector('.step-indicator-item:nth-child(3)').style.display = 'flex';
            
            // Mark all as active
            document.getElementById('indicator1').classList.add('active');
            document.getElementById('indicator2').classList.add('active');
            document.getElementById('indicator3').classList.add('active');
        }
    }

    function setButtonLoading(button, loading, originalText = '') {
        if (loading) {
            button.disabled = true;
            button.innerHTML = 'Processing...';
        } else {
            button.disabled = false;
            button.innerHTML = originalText || button.innerHTML;
        }
    }

    function makeRequest(url, formData, successCallback, errorCallback) {
        fetch(url, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin', // Include cookies for session persistence
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => successCallback(data))
        .catch(error => {
            console.error('Error:', error);
            if (errorCallback) errorCallback(error);
            showMessage('Connection error. Please try again.', 'danger');
        });
    }

    function isValidEmail(email) {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return emailRegex.test(email);
    }

    // Step Navigation Functions
    window.showStep1 = function() { 
        hideAllSteps(); 
        document.getElementById('step1')?.classList.add('active'); 
        updateStepIndicators('step1');
        clearMessages();
    };

    window.showStep2 = function() { 
        hideAllSteps(); 
        document.getElementById('step2')?.classList.add('active');
        updateStepIndicators('step2');
        clearMessages();
    };

    window.showForgotPassword = function() { 
        hideAllSteps(); 
        document.getElementById('forgotStep1')?.classList.add('active');
        updateStepIndicators('forgotStep1');
        clearMessages();
    };

    window.showForgotStep2 = function() { 
        hideAllSteps(); 
        document.getElementById('forgotStep2')?.classList.add('active');
        updateStepIndicators('forgotStep2');
    };

    window.showForgotStep3 = function() { 
        hideAllSteps(); 
        document.getElementById('forgotStep3')?.classList.add('active');
        updateStepIndicators('forgotStep3');
    };

    // Password Toggle Function
    window.togglePassword = function(inputId) {
        const passwordInput = document.getElementById(inputId);
        const toggleIcon = document.getElementById(inputId + '-toggle-icon');
        
        if (passwordInput.type === 'password') {
            passwordInput.type = 'text';
            toggleIcon.classList.remove('bi-eye');
            toggleIcon.classList.add('bi-eye-slash');
        } else {
            passwordInput.type = 'password';
            toggleIcon.classList.remove('bi-eye-slash');
            toggleIcon.classList.add('bi-eye');
        }
    };

    // DOM Content Loaded - Initialize Event Listeners
    document.addEventListener('DOMContentLoaded', function() {
        
        // Login Form Handler
        const loginForm = document.getElementById('loginForm');
        if (loginForm) {
            loginForm.addEventListener('submit', function(e) {
                e.preventDefault();
                
                const email = document.getElementById('email').value.trim();
                const password = document.getElementById('password').value;
                
                // Basic validation
                if (!email || !password) {
                    showMessage('Please fill in all fields.', 'danger');
                    return;
                }
                
                if (!isValidEmail(email)) {
                    showMessage('Please enter a valid email address.', 'danger');
                    return;
                }
                
                // Validate reCAPTCHA
                const recaptchaResponse = grecaptcha.getResponse();
                if (!recaptchaResponse) {
                    showMessage('Please complete the CAPTCHA verification.', 'danger');
                    return;
                }
                
                const formData = new FormData();
                formData.append('email', email);
                formData.append('password', password);
                formData.append('g-recaptcha-response', recaptchaResponse);
                
                const submitBtn = this.querySelector('button[type="submit"]');
                const originalText = submitBtn.innerHTML;

                setButtonLoading(submitBtn, true);

                makeRequest('unified_login.php', formData,
                    function(data) {
                        setButtonLoading(submitBtn, false, originalText);
                        
                        if (data.status === 'otp_sent') {
                            showStep2();
                            showMessage('Verification code sent to your email!', 'success');
                            // Reset reCAPTCHA for next attempt
                            grecaptcha.reset();
                        } else {
                            showMessage(data.message, 'danger');
                            // Reset reCAPTCHA on error
                            grecaptcha.reset();
                        }
                    },
                    function(error) {
                        setButtonLoading(submitBtn, false, originalText);
                        // Reset reCAPTCHA on error
                        grecaptcha.reset();
                    }
                );
            });
        }

        // OTP Form Handler
        const otpForm = document.getElementById('otpForm');
        let otpSubmitting = false;
        let lastOtpRequestId = 0;
        let otpSuccessReceived = false;
        if (otpForm) {
            otpForm.addEventListener('submit', function(e) {
                e.preventDefault();
                if (otpSubmitting) { return; }
                
                const otp = document.getElementById('login_otp').value.trim();
                
                if (!otp || otp.length !== 6) {
                    showMessage('Please enter a valid 6-digit code.', 'danger');
                    return;
                }
                
                const formData = new FormData();
                formData.append('login_action', 'verify_otp');
                formData.append('login_otp', otp);
                
                const submitBtn = this.querySelector('button[type="submit"]');
                const originalText = submitBtn.innerHTML;

                setButtonLoading(submitBtn, true);
                otpSubmitting = true;
                otpSuccessReceived = false;
                const requestId = ++lastOtpRequestId;

                makeRequest('unified_login.php', formData,
                    function(data) {
                        // Ignore stale responses
                        if (requestId !== lastOtpRequestId) { return; }
                        setButtonLoading(submitBtn, false, originalText);
                        otpSubmitting = false;
                        
                        if (data.status === 'success') {
                            otpSuccessReceived = true;
                            showMessage('Login successful! Redirecting...', 'success');
                            setTimeout(() => {
                                window.location.href = data.redirect;
                            }, 1500);
                        } else {
                            if (!otpSuccessReceived) {
                                showMessage(data.message, 'danger');
                            }
                        }
                    },
                    function(error) {
                        // Ignore stale or post-success errors
                        if (requestId !== lastOtpRequestId || otpSuccessReceived) { return; }
                        setButtonLoading(submitBtn, false, originalText);
                        otpSubmitting = false;
                    }
                );
            });
        }

        // Forgot Password Form Handler - NO EVENT LISTENER
        // The reCAPTCHA modal handler in unified_login.php will handle this instead

        // Forgot Password OTP Form Handler
        const forgotOtpForm = document.getElementById('forgotOtpForm');
        if (forgotOtpForm) {
            forgotOtpForm.addEventListener('submit', function(e) {
                e.preventDefault();
                
                const otp = document.getElementById('forgot_otp').value.trim();
                
                if (!otp || otp.length !== 6) {
                    showMessage('Please enter a valid 6-digit code.', 'danger');
                    return;
                }
                
                const formData = new FormData();
                formData.append('forgot_action', 'verify_otp');
                formData.append('forgot_otp', otp);
                
                const submitBtn = this.querySelector('button[type="submit"]');
                const originalText = submitBtn.innerHTML;

                setButtonLoading(submitBtn, true);

                makeRequest('unified_login.php', formData,
                    function(data) {
                        setButtonLoading(submitBtn, false, originalText);
                        
                        if (data.status === 'success') {
                            showForgotStep3();
                            showMessage('Code verified! Set your new password.', 'success');
                        } else {
                            showMessage(data.message, 'danger');
                        }
                    },
                    function(error) {
                        setButtonLoading(submitBtn, false, originalText);
                    }
                );
            });
        }

        // New Password Form Handler
        const newPasswordForm = document.getElementById('newPasswordForm');
        if (newPasswordForm) {
            newPasswordForm.addEventListener('submit', function(e) {
                e.preventDefault();
                
                const newPassword = document.getElementById('forgot_new_password').value;
                const confirmPassword = document.getElementById('confirm_password').value;
                
                // Validate password match
                if (newPassword !== confirmPassword) {
                    showMessage('Passwords do not match. Please try again.', 'danger');
                    return;
                }
                
                // Password strength is validated by the password_strength_validator.js
                // which disables the submit button until requirements are met
                
                const formData = new FormData();
                formData.append('forgot_action', 'set_new_password');
                formData.append('forgot_new_password', newPassword);
                
                const submitBtn = this.querySelector('button[type="submit"]');
                const originalText = submitBtn.innerHTML;

                setButtonLoading(submitBtn, true);

                makeRequest('unified_login.php', formData,
                    function(data) {
                        setButtonLoading(submitBtn, false, originalText);
                        
                        if (data.status === 'success') {
                            showStep1();
                            showMessage('Password updated successfully! Please login with your new password.', 'success');
                        } else {
                            showMessage(data.message, 'danger');
                        }
                    },
                    function(error) {
                        setButtonLoading(submitBtn, false, originalText);
                    }
                );
            });
        }

        // OTP Input Auto-formatting
        document.querySelectorAll('.otp-input').forEach(input => {
            input.addEventListener('input', function(e) {
                // Remove non-numeric characters
                this.value = this.value.replace(/\D/g, '');
                
                // Limit to 6 digits
                if (this.value.length > 6) {
                    this.value = this.value.slice(0, 6);
                }
            });
            
            input.addEventListener('paste', function(e) {
                e.preventDefault();
                const paste = (e.clipboardData || window.clipboardData).getData('text');
                const numericPaste = paste.replace(/\D/g, '').slice(0, 6);
                this.value = numericPaste;
            });
        });
    });
})();