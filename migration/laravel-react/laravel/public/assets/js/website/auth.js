/**
 * Authentication Modal Handler
 * Handles login, signup, and forgot password functionality
 */
class AuthModal {
  constructor() {
    this.init();
  }

  init() {
    this.setupEventListeners();
    this.setupFormValidation();
    this.setupPasswordToggle();
  }

  setupEventListeners() {
    // Login Form
    const loginForm = document.getElementById('loginForm');
    if (loginForm) {
      loginForm.addEventListener('submit', (e) => this.handleLogin(e));
    }

    // OTP Form
    const otpForm = document.getElementById('otpForm');
    if (otpForm) {
      otpForm.addEventListener('submit', (e) => this.handleOTPVerification(e));
    }

    // Signup Form
    const signupForm = document.getElementById('signupForm');
    if (signupForm) {
      const nextBtn = document.getElementById('signupNextBtn');
      if (nextBtn) {
        nextBtn.addEventListener('click', (e) => this.handleSignupNext(e));
      }
      
      signupForm.addEventListener('submit', (e) => this.handleSignupSubmit(e));
    }

    // Forgot Password Form
    const forgotForm = document.getElementById('forgotPasswordForm');
    if (forgotForm) {
      forgotForm.addEventListener('submit', (e) => this.handleForgotPassword(e));
    }

    // Navigation Buttons
    const signupBackBtn = document.getElementById('signupBackBtn');
    if (signupBackBtn) {
      signupBackBtn.addEventListener('click', () => this.showSignupStep(1));
    }

    // Resend OTP Buttons
    const resendOtpBtn = document.getElementById('resendOtpBtn');
    if (resendOtpBtn) {
      resendOtpBtn.addEventListener('click', () => this.resendOTP('login'));
    }

    const resendSignupOtpBtn = document.getElementById('resendSignupOtpBtn');
    if (resendSignupOtpBtn) {
      resendSignupOtpBtn.addEventListener('click', () => this.resendOTP('signup'));
    }

    // OTP Input Formatting
    const otpInputs = document.querySelectorAll('input[maxlength="6"]');
    otpInputs.forEach(input => {
      input.addEventListener('input', (e) => this.formatOTPInput(e));
    });
  }

  setupFormValidation() {
    // Password confirmation validation
    const signupPassword = document.getElementById('signupPassword');
    const confirmPassword = document.getElementById('signupConfirmPassword');
    
    if (signupPassword && confirmPassword) {
      confirmPassword.addEventListener('input', () => {
        if (confirmPassword.value !== signupPassword.value) {
          confirmPassword.setCustomValidity('Passwords do not match');
        } else {
          confirmPassword.setCustomValidity('');
        }
      });
    }

    // Phone number validation
    const phoneInput = document.getElementById('signupPhone');
    if (phoneInput) {
      phoneInput.addEventListener('input', (e) => {
        let value = e.target.value.replace(/\D/g, '');
        if (value.length > 11) value = value.slice(0, 11);
        e.target.value = value;
      });
    }
  }

  setupPasswordToggle() {
    const toggleButtons = [
      { buttonId: 'toggleLoginPassword', inputId: 'loginPassword' },
      { buttonId: 'toggleSignupPassword', inputId: 'signupPassword' }
    ];

    toggleButtons.forEach(({ buttonId, inputId }) => {
      const button = document.getElementById(buttonId);
      const input = document.getElementById(inputId);
      
      if (button && input) {
        button.addEventListener('click', () => {
          const icon = button.querySelector('i');
          if (input.type === 'password') {
            input.type = 'text';
            icon.className = 'bi bi-eye-slash';
          } else {
            input.type = 'password';
            icon.className = 'bi bi-eye';
          }
        });
      }
    });
  }

  async handleLogin(e) {
    e.preventDefault();
    const submitBtn = document.getElementById('loginSubmitBtn');
    const spinner = submitBtn.querySelector('.spinner-border');
    
    this.setLoading(submitBtn, spinner, true);
    this.hideAlert('loginAlert');

    try {
      const formData = new FormData(e.target);
      const response = await fetch('unified_login.php', {
        method: 'POST',
        body: formData,
        headers: {
          'X-Requested-With': 'XMLHttpRequest'
        }
      });

      const result = await response.json();
      
      if (result.status === 'otp_sent') {
        // Switch to OTP form
        document.getElementById('loginForm').classList.add('d-none');
        document.getElementById('otpForm').classList.remove('d-none');
        this.showAlert('loginAlert', 'Verification code sent to your email!', 'success');
      } else if (result.status === 'error') {
        this.showAlert('loginAlert', result.message, 'danger');
      }
    } catch (error) {
      this.showAlert('loginAlert', 'Connection error. Please try again.', 'danger');
    } finally {
      this.setLoading(submitBtn, spinner, false);
    }
  }

  async handleOTPVerification(e) {
    e.preventDefault();
    const submitBtn = document.getElementById('otpSubmitBtn');
    const spinner = submitBtn.querySelector('.spinner-border');
    
    this.setLoading(submitBtn, spinner, true);

    try {
      const formData = new FormData();
      formData.append('login_action', 'verify_otp');
      formData.append('otp', document.getElementById('otpCode').value);

      const response = await fetch('unified_login.php', {
        method: 'POST',
        body: formData,
        headers: {
          'X-Requested-With': 'XMLHttpRequest'
        }
      });

      const result = await response.json();
      
      if (result.status === 'success') {
        // Redirect based on user role
        if (result.role === 'student') {
          window.location.href = 'modules/student/';
        } else {
          window.location.href = 'modules/admin/';
        }
      } else {
        this.showAlert('loginAlert', result.message, 'danger');
      }
    } catch (error) {
      this.showAlert('loginAlert', 'Verification failed. Please try again.', 'danger');
    } finally {
      this.setLoading(submitBtn, spinner, false);
    }
  }

  handleSignupNext(e) {
    e.preventDefault();
    const form = document.getElementById('signupForm');
    
    // Validate Step 1 fields
    const requiredFields = ['signupFirstName', 'signupLastName', 'signupEmail', 'signupPhone', 'signupPassword', 'signupConfirmPassword'];
    let isValid = true;
    
    requiredFields.forEach(fieldId => {
      const field = document.getElementById(fieldId);
      if (!field.value.trim() || !field.checkValidity()) {
        field.classList.add('is-invalid');
        isValid = false;
      } else {
        field.classList.remove('is-invalid');
      }
    });

    // Check terms agreement
    const agreeTerms = document.getElementById('agreeTerms');
    if (!agreeTerms.checked) {
      agreeTerms.classList.add('is-invalid');
      isValid = false;
    }

    if (isValid) {
      // Send verification email
      this.sendVerificationEmail();
      this.showSignupStep(2);
    }
  }

  async sendVerificationEmail() {
    try {
      const email = document.getElementById('signupEmail').value;
      document.getElementById('verificationEmail').textContent = email;
      
      // Here you would typically send the verification email
      // For now, we'll simulate it
      console.log('Sending verification email to:', email);
    } catch (error) {
      console.error('Error sending verification email:', error);
    }
  }

  async handleSignupSubmit(e) {
    e.preventDefault();
    const submitBtn = document.getElementById('signupSubmitBtn');
    const spinner = submitBtn.querySelector('.spinner-border');
    
    this.setLoading(submitBtn, spinner, true);

    try {
      const formData = new FormData(e.target);
      
      // Here you would submit to your student registration endpoint
      // For now, we'll simulate success
      setTimeout(() => {
        this.setLoading(submitBtn, spinner, false);
        this.showSignupStep(3);
        this.updateProgress(100);
      }, 2000);
      
    } catch (error) {
      this.showAlert('signupAlert', 'Registration failed. Please try again.', 'danger');
      this.setLoading(submitBtn, spinner, false);
    }
  }

  async handleForgotPassword(e) {
    e.preventDefault();
    const submitBtn = document.getElementById('forgotSubmitBtn');
    const spinner = submitBtn.querySelector('.spinner-border');
    
    this.setLoading(submitBtn, spinner, true);

    try {
      const formData = new FormData(e.target);
      formData.append('forgot_action', 'send_reset');

      const response = await fetch('unified_login.php', {
        method: 'POST',
        body: formData,
        headers: {
          'X-Requested-With': 'XMLHttpRequest'
        }
      });

      const result = await response.json();
      
      if (result.status === 'success') {
        this.showAlert('forgotAlert', 'Reset link sent to your email!', 'success');
      } else {
        this.showAlert('forgotAlert', result.message, 'danger');
      }
    } catch (error) {
      this.showAlert('forgotAlert', 'Failed to send reset link. Please try again.', 'danger');
    } finally {
      this.setLoading(submitBtn, spinner, false);
    }
  }

  showSignupStep(step) {
    // Hide all steps
    document.querySelectorAll('.signup-step').forEach(el => el.classList.add('d-none'));
    
    // Show target step
    document.getElementById(`step${step}`).classList.remove('d-none');
    
    // Update progress
    const progressWidths = { 1: 33, 2: 66, 3: 100 };
    this.updateProgress(progressWidths[step]);
    
    // Update progress labels
    const labels = document.querySelectorAll('.signup-progress small');
    labels.forEach((label, index) => {
      if (index + 1 < step) {
        label.className = 'text-success fw-bold';
      } else if (index + 1 === step) {
        label.className = 'text-primary fw-bold';
      } else {
        label.className = 'text-muted';
      }
    });
  }

  updateProgress(width) {
    const progressBar = document.querySelector('.signup-progress .progress-bar');
    if (progressBar) {
      progressBar.style.width = width + '%';
    }
  }

  formatOTPInput(e) {
    // Only allow numbers
    e.target.value = e.target.value.replace(/\D/g, '');
  }

  async resendOTP(type) {
    try {
      const button = document.getElementById(type === 'login' ? 'resendOtpBtn' : 'resendSignupOtpBtn');
      button.disabled = true;
      button.textContent = 'Sending...';
      
      // Simulate resend
      setTimeout(() => {
        button.disabled = false;
        button.textContent = type === 'login' ? 'Resend Code' : 'Resend Verification Code';
        this.showAlert(type === 'login' ? 'loginAlert' : 'signupAlert', 'Verification code resent!', 'success');
      }, 2000);
    } catch (error) {
      console.error('Error resending OTP:', error);
    }
  }

  setLoading(button, spinner, isLoading) {
    if (isLoading) {
      button.disabled = true;
      spinner.classList.remove('d-none');
    } else {
      button.disabled = false;
      spinner.classList.add('d-none');
    }
  }

  showAlert(alertId, message, type) {
    const alert = document.getElementById(alertId);
    if (alert) {
      alert.className = `alert alert-${type}`;
      alert.textContent = message;
      alert.classList.remove('d-none');
      
      // Auto-hide success alerts
      if (type === 'success') {
        setTimeout(() => this.hideAlert(alertId), 5000);
      }
    }
  }

  hideAlert(alertId) {
    const alert = document.getElementById(alertId);
    if (alert) {
      alert.classList.add('d-none');
    }
  }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
  new AuthModal();
});