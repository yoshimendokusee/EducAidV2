// Enhanced state variables
let countdown;
let currentStep = 1;
let otpVerified = false;
let documentVerified = false;
let filenameValid = false;

// Lightweight helper to persist OTP verification across inline/external scripts
// Provides a single place to update DOM + state without repeated queries
function setOtpVerifiedState(isVerified) {
    otpVerified = !!isVerified;
    window.otpVerified = otpVerified; // expose globally for inline validator
    hasVerifiedOTP = otpVerified;     // keep ancillary tracking consistent

    // Hidden flag (allows PHP inline validation to check quickly if needed)
    const form = document.getElementById('multiStepForm');
    let hidden = document.getElementById('otpVerifiedFlag');
    if (otpVerified) {
        if (!hidden && form) {
            hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.id = 'otpVerifiedFlag';
            hidden.name = 'otp_verified';
            hidden.value = '1';
            form.appendChild(hidden);
        } else if (hidden) {
            hidden.value = '1';
        }
        // Reveal email status element so inline step-9 validator passes
        const emailStatus = document.getElementById('emailStatus');
        if (emailStatus) emailStatus.classList.remove('d-none');
    } else {
        if (hidden) hidden.remove();
        const emailStatus = document.getElementById('emailStatus');
        // Only hide if it exists and we are explicitly invalidating
        if (emailStatus) emailStatus.classList.add('d-none');
    }
}

// Registration progress tracking
let registrationInProgress = false;
let hasUploadedFiles = false;
let hasVerifiedOTP = false;
let formSubmitted = false;

// Track when user starts uploading documents
function trackFileUpload() {
    hasUploadedFiles = true;
    registrationInProgress = true;
    console.log('File upload tracked - registration in progress');
}

// Track when OTP is verified
function trackOTPVerification() {
    hasVerifiedOTP = true;
    registrationInProgress = true;
    console.log('OTP verification tracked - registration in progress');
}

// Check for existing account before allowing registration
async function checkExistingAccount(email, mobile) {
    try {
        const response = await fetch('', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: new URLSearchParams({
                'check_existing': '1',
                'email': email || '',
                'mobile': mobile || ''
            })
        });
        return await response.json();
    } catch (error) {
        console.error('Error checking existing account:', error);
        return { status: 'error', message: 'Could not verify account status' };
    }
}

// Cleanup temporary files
async function cleanupTempFiles() {
    try {
        const response = await fetch('', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: new URLSearchParams({
                'cleanup_temp': '1'
            })
        });
        return await response.json();
    } catch (error) {
        console.error('Error cleaning up temp files:', error);
        return { status: 'error', message: 'Cleanup failed' };
    }
}

// Enhanced beforeunload warning
function setupNavigationWarning() {
    window.addEventListener('beforeunload', function(e) {
        if (registrationInProgress && !formSubmitted) {
            const message = 'You have unsaved registration progress. If you leave this page, your uploaded documents and verification progress will be lost. Are you sure you want to leave?';
            e.preventDefault();
            e.returnValue = message;
            
            // Attempt to cleanup (may not always work due to browser limitations)
            if (navigator.sendBeacon) {
                navigator.sendBeacon('', new URLSearchParams({
                    'cleanup_temp': '1'
                }));
            }
            
            return message;
        }
    });
}

// Enhanced email/mobile validation with duplicate check
async function validateAccountDetails() {
    const email = document.querySelector('input[name="email"]')?.value.trim();
    const mobile = document.querySelector('input[name="phone"]')?.value.trim();
    
    if (!email && !mobile) return true;
    
    try {
        const result = await checkExistingAccount(email, mobile);
        
        if (result.status === 'exists') {
            const confirmMsg = `${result.message}\n\nAccount found using your ${result.type}.\nStatus: ${result.account_status.replace('_', ' ').toUpperCase()}\n\n`;
            
            if (result.can_reapply) {
                const reapply = confirm(confirmMsg + 'Would you like to continue with a new application? This will replace your previous rejected application.');
                if (!reapply) return false;
            } else {
                alert(confirmMsg + 'Please use the login page instead or contact support if you believe this is an error.');
                return false;
            }
        }
        
        return true;
    } catch (error) {
        console.error('Account validation failed:', error);
        return true; // Allow registration if check fails
    }
}

function updateRequiredFields() {
    // Disable all required fields initially
    document.querySelectorAll('.step-panel input[required], .step-panel select[required], .step-panel textarea[required]').forEach(el => {
        el.disabled = true;
    });
    // Enable required fields in the visible panel only
    document.querySelectorAll(`#step-${currentStep} input[required], #step-${currentStep} select[required], #step-${currentStep} textarea[required]`).forEach(el => {
        el.disabled = false;
    });
}

// ❌ REMOVED: showStep() function - now defined in inline script in student_register.php
// The inline version includes full validation logic and updateStepIndicators() call
// It also handles the date restriction setup for step 2
// This external version was causing conflicts by overwriting the inline implementation

function showNotifier(message, type = 'error') {
    const el = document.getElementById('notifier');
    if (!el) return;

    // Remove prior state classes
    el.classList.remove('success','error','warning');
    el.classList.add(type);
    el.textContent = message;

    // Dynamic vertical offset: ensure it's always above any fixed top bars
    let offset = 12;
    const topbar = document.querySelector('.topbar, #topbar');
    const navbar = document.querySelector('.navbar, .site-navbar, #navbar');
    [topbar, navbar].forEach(node => {
        if (node) {
            const styles = window.getComputedStyle(node);
            if (styles.position === 'fixed' || styles.position === 'sticky') {
                offset += node.offsetHeight;
            }
        }
    });
    el.style.top = offset + 'px';

    // Show with animation
    el.style.display = 'block';
    el.style.opacity = '1';

    // Auto-hide after timeout
    clearTimeout(window.__notifierHideTimer);
    window.__notifierHideTimer = setTimeout(() => {
        el.style.opacity = '0';
        setTimeout(() => { el.style.display = 'none'; }, 300);
    }, 3500);
}

// ========================================
// SIMPLIFIED FORM VALIDATION WITH MOBILE VIBRATION
// ========================================

function highlightMissingFields(fields) {
    // Clear any existing highlights first
    clearFieldHighlights();
    
    let firstMissingField = null;
    
    fields.forEach(field => {
        if (field) {
            // Add red border styling to the field
            field.classList.add('missing-field');
            
            // Track first missing field for scrolling
            if (!firstMissingField) {
                firstMissingField = field;
            }
            
            // Add shake animation
            field.classList.add('shake-field');
            setTimeout(() => {
                field.classList.remove('shake-field');
            }, 600);
        }
    });
    
    // Vibrate on mobile devices
    triggerMobileVibration('error');
    
    // Scroll to first missing field
    if (firstMissingField) {
        scrollToField(firstMissingField);
    }
}

function clearFieldHighlights() {
    // Remove all error styling
    document.querySelectorAll('.missing-field').forEach(el => {
        el.classList.remove('missing-field');
    });
}

function scrollToField(field) {
    // Smooth scroll to the field
    field.scrollIntoView({
        behavior: 'smooth',
        block: 'center'
    });
    
    // Focus the field after scroll
    setTimeout(() => {
        field.focus();
        
        // Add pulse effect
        field.classList.add('pulse-field');
        setTimeout(() => {
            field.classList.remove('pulse-field');
        }, 1000);
    }, 500);
}

function validateCurrentStep() {
    const currentPanel = document.getElementById(`step-${currentStep}`);
    const requiredInputs = currentPanel.querySelectorAll('input[required], select[required], textarea[required]');
    const missingFields = [];
    
    requiredInputs.forEach(input => {
        let isValid = true;
        
        if (input.type === 'radio') {
            const radioGroupName = input.name;
            if (!document.querySelector(`input[name="${radioGroupName}"]:checked`)) {
                // Only add one radio button per group to missing fields
                if (!missingFields.find(field => field.name === radioGroupName)) {
                    missingFields.push(input);
                }
                isValid = false;
            }
        } else if (input.type === 'checkbox') {
            if (!input.checked) {
                missingFields.push(input);
                isValid = false;
            }
        } else if (!input.value.trim()) {
            missingFields.push(input);
            isValid = false;
        }
        
        // Clear previous validation state if field is now valid
        if (isValid) {
            input.classList.remove('missing-field');
        }
    });
    
    return missingFields;
}

function validateNameFields() {
    const nameValidations = [];
    
    // Get name fields from Step 1
    const firstNameField = document.querySelector('input[name="first_name"]');
    const middleNameField = document.querySelector('input[name="middle_name"]');
    const lastNameField = document.querySelector('input[name="last_name"]');
    
    // Regular expression to allow only letters, spaces, hyphens, and apostrophes
    const namePattern = /^[A-Za-z\s\-']+$/;
    
    // Validate first name
    if (firstNameField && firstNameField.value) {
        if (!namePattern.test(firstNameField.value)) {
            nameValidations.push(firstNameField);
        }
    }
    
    // Validate middle name (if provided)
    if (middleNameField && middleNameField.value) {
        if (!namePattern.test(middleNameField.value)) {
            nameValidations.push(middleNameField);
        }
    }
    
    // Validate last name
    if (lastNameField && lastNameField.value) {
        if (!namePattern.test(lastNameField.value)) {
            nameValidations.push(lastNameField);
        }
    }
    
    return nameValidations;
}

function validateSpecialFields() {
    const specialValidations = [];
    
    // Email validation
    const emailField = document.getElementById('emailInput');
    if (emailField && emailField.value && !/\S+@\S+\.\S+/.test(emailField.value)) {
        specialValidations.push(emailField);
    }
    
    // Password strength validation
    const passwordField = document.getElementById('password');
    if (passwordField && passwordField.value) {
        const strength = calculatePasswordStrength(passwordField.value);
        if (strength < 75) {
            specialValidations.push(passwordField);
        }
    }
    
    // Confirm password validation
    const confirmPasswordField = document.getElementById('confirmPassword');
    if (confirmPasswordField && confirmPasswordField.value && passwordField && passwordField.value !== confirmPasswordField.value) {
        specialValidations.push(confirmPasswordField);
    }
    
    // ADD NAME VALIDATION FOR STEP 1
    if (currentStep === 1) {
        const nameValidationErrors = validateNameFields();
        specialValidations.push(...nameValidationErrors);
    }
    
    return specialValidations;
}

// Mobile vibration functionality
function triggerMobileVibration(type = 'default') {
    // Check if device supports vibration
    if ('vibrate' in navigator) {
        let pattern;
        
        switch (type) {
            case 'error':
                pattern = [100, 50, 100, 50, 100]; // Triple vibration for errors
                break;
            case 'success':
                pattern = [200]; // Single long vibration for success
                break;
            case 'warning':
                pattern = [100, 100, 100]; // Double vibration for warnings
                break;
            default:
                pattern = [50]; // Short single vibration
                break;
        }
        
        navigator.vibrate(pattern);
    }
}

// Real-time validation
function setupRealTimeValidation() {
    const form = document.getElementById('multiStepForm');
    
    // Add event listeners for real-time validation
    form.addEventListener('input', (e) => {
        const field = e.target;
        
        // Clear error state when user starts typing
        if (field.classList.contains('missing-field')) {
            field.classList.remove('missing-field');
        }
        
        // REAL-TIME NAME VALIDATION
        if (field.name === 'first_name' || field.name === 'middle_name' || field.name === 'last_name') {
            const namePattern = /^[A-Za-z\s\-']+$/;
            
            if (field.value && !namePattern.test(field.value)) {
                field.classList.add('missing-field');
                // Show warning but don't prevent typing
                if (field.value.length > 2) { // Only show after typing a few characters
                    showNotifier('Names can only contain letters, spaces, hyphens (-), and apostrophes (\').', 'error');
                }
            } else {
                field.classList.remove('missing-field');
            }
        }
    });
    
    form.addEventListener('change', (e) => {
        const field = e.target;
        
        // Clear error state for dropdowns and checkboxes
        if (field.classList.contains('missing-field')) {
            field.classList.remove('missing-field');
        }
    });
    
    // Add vibration feedback for successful interactions
    form.addEventListener('input', () => {
        // Light vibration for typing (only occasionally to avoid annoyance)
        if (Math.random() < 0.1) { // 10% chance
            triggerMobileVibration('default');
        }
    });
}

// ========================================
// DATE OF BIRTH RESTRICTION FUNCTION
// ========================================

function setupDateOfBirthRestriction() {
    console.log('🔍 Starting date of birth restriction setup...');
    
    // Wait for Step 2 to be visible
    const step2 = document.getElementById('step-2');
    if (!step2 || step2.classList.contains('d-none')) {
        console.log('❌ Step 2 not visible yet, waiting...');
        return;
    }
    
    // Try to find the date input
    let dobInput = null;
    
    // Method 1: Try by name "bdate" (your actual HTML)
    dobInput = document.querySelector('input[name="bdate"]');
    if (dobInput) {
        console.log('✅ Found date input by name="bdate":', dobInput);
    } else {
        console.log('❌ No element found with name="bdate"');
        
        // Method 2: Try by type="date" within Step 2
        const dateInputs = step2.querySelectorAll('input[type="date"]');
        console.log(`🔍 Found ${dateInputs.length} date input(s) in Step 2:`, dateInputs);
        
        if (dateInputs.length > 0) {
            dobInput = dateInputs[0];
            console.log('✅ Using first date input found in Step 2:', dobInput);
        }
    }
    
    // If still not found, list all inputs in Step 2
    if (!dobInput) {
        console.log('❌ Date input still not found. All inputs in Step 2:');
        const allInputs = step2.querySelectorAll('input');
        allInputs.forEach((input, index) => {
            console.log(`Input ${index + 1}:`, {
                type: input.type,
                id: input.id,
                name: input.name,
                placeholder: input.placeholder,
                className: input.className,
                element: input
            });
        });
        return;
    }
    
    // Apply restrictions if input found
    if (dobInput) {
        const currentYear = new Date().getFullYear();
        const minYear = currentYear - 10; // Youngest possible collegian (10 years old)
        const maxYear = 1900; // Reasonable oldest year
        
        // Set the max date (youngest allowed: current year - 10)
        const maxDate = `${minYear}-12-31`;
        
        // Set the min date (oldest allowed)
        const minDate = `${maxYear}-01-01`;
        
        console.log(`📅 Applying restrictions:`, {
            currentYear,
            minYear,
            maxYear,
            maxDate,
            minDate,
            inputElement: dobInput
        });
        
        // Set attributes
        dobInput.setAttribute('max', maxDate);
        dobInput.setAttribute('min', minDate);
        
        // Force browser to recognize the new constraints
        dobInput.dispatchEvent(new Event('change'));
        
        // Verify attributes were set
        console.log('🔍 Verification - Attributes after setting:', {
            max: dobInput.getAttribute('max'),
            min: dobInput.getAttribute('min'),
            type: dobInput.type,
            name: dobInput.name
        });
        
        // Clear any existing value that might be invalid
        if (dobInput.value) {
            const selectedDate = new Date(dobInput.value);
            const selectedYear = selectedDate.getFullYear();
            if (selectedYear > minYear) {
                dobInput.value = ''; // Clear invalid value
                console.log('🧹 Cleared invalid existing value');
            }
        }
        
        // Add validation event listener
        dobInput.addEventListener('input', function() {
            const selectedDate = new Date(this.value);
            const selectedYear = selectedDate.getFullYear();
            
            if (selectedYear > minYear) {
                console.log('❌ User tried to select year too recent:', selectedYear);
                showNotifier(`Students must be at least 10 years old. Please select a date before ${minYear + 1}.`, 'error');
                this.classList.add('missing-field');
            } else {
                this.classList.remove('missing-field');
            }
        });
        
        console.log(`✅ Date restrictions applied successfully!`);
        console.log(`📅 Maximum year: ${minYear} (current year - 10)`);
        console.log(`📅 Date range: ${minDate} to ${maxDate}`);
        
        // Show success message
        showNotifier(`Date restriction applied: Maximum birth year is ${minYear}`, 'success');
    }
}

// ========================================
// NOTE: nextStep(), prevStep(), showStep(), and updateStepIndicators() 
// are defined in the inline JavaScript in student_register.php
// because they depend on page-specific validation functions.
// They are registered to window.* in the inline code.
// ========================================

// Add vibration to button clicks
function addVibrationToButtons() {
    document.querySelectorAll('button, .btn').forEach(button => {
        button.addEventListener('click', () => {
            triggerMobileVibration('default');
        });
    });
}
document.addEventListener('DOMContentLoaded', () => {
    // NOTE: showStep(1) is now called by inline code in student_register.php
    // after all page-specific validation functions are defined
    
    // ❌ REMOVED: nextStep7Btn event listener - nextStep is defined in inline script
    // The inline script registers window.nextStep after defining it, so onclick handlers work
    // Adding event listeners here before the inline script loads causes "nextStep is not defined" errors
    
    // Setup real-time validation
    setupRealTimeValidation();
    
    // Add vibration to all buttons
    addVibrationToButtons();
    
    // REMOVE THIS LINE - Date restriction will be called when Step 2 is shown
    // setupDateOfBirthRestriction();
    
    // Add listeners to name fields to re-validate filename if changed
    document.querySelector('input[name="first_name"]').addEventListener('input', function() {
        if (document.getElementById('enrollmentForm').files.length > 0) {
            const event = new Event('change');
            document.getElementById('enrollmentForm').dispatchEvent(event);
        }
    });

    document.querySelector('input[name="last_name"]').addEventListener('input', function() {
        if (document.getElementById('enrollmentForm').files.length > 0) {
            const event = new Event('change');
            document.getElementById('enrollmentForm').dispatchEvent(event);
        }
    });
});

// ---- OTP BUTTON HANDLING ----

document.getElementById("sendOtpBtn").addEventListener("click", function() {
    const emailInput = document.getElementById('emailInput');
    const email = emailInput.value;

    if (!email || !/\S+@\S+\.\S+/.test(email)) {
        highlightMissingFields([emailInput]);
        showNotifier('Please enter a valid email address before sending OTP.', 'error');
        return;
    }

    const sendOtpBtn = this;
    sendOtpBtn.disabled = true;
    sendOtpBtn.textContent = 'Sending OTP...';
    document.getElementById("resendOtpBtn").disabled = true;

    const formData = new FormData();
    formData.append('sendOtp', 'true');
    formData.append('email', email);

    // If reCAPTCHA available, execute for send_otp action first
    if (typeof grecaptcha !== 'undefined' && window.RECAPTCHA_SITE_KEY) {
        grecaptcha.ready(function(){
            grecaptcha.execute(window.RECAPTCHA_SITE_KEY, {action:'send_otp'}).then(function(token){
                formData.append('g-recaptcha-response', token);
                fetch('student_register.php', { method: 'POST', body: formData, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(r=>r.json())
                    .then(handleSendOtpResponse)
                    .catch(handleSendOtpError);
            });
        });
    } else {
        fetch('student_register.php', { method: 'POST', body: formData, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r=>r.json())
            .then(handleSendOtpResponse)
            .catch(handleSendOtpError);
    }
});

function handleSendOtpResponse(data){
    const sendOtpBtn = document.getElementById('sendOtpBtn');
        if (data.status === 'success') {
            triggerMobileVibration('success');
            showNotifier(data.message, 'success');
            
            // Add null checks for all DOM elements
            const otpSection = document.getElementById("otpSection");
            if (otpSection) otpSection.classList.remove("d-none");
            
            if (sendOtpBtn) sendOtpBtn.classList.add("d-none");
            
            const resendBtn = document.getElementById("resendOtpBtn");
            if (resendBtn) resendBtn.style.display = 'block';
            
            startOtpTimer();
        } else {
            triggerMobileVibration('error');
            showNotifier(data.message, 'error');
            
            if (sendOtpBtn) {
                sendOtpBtn.disabled = false;
                sendOtpBtn.textContent = "Send OTP (Email)";
            }
            
            const resendBtn = document.getElementById("resendOtpBtn");
            if (resendBtn) resendBtn.disabled = true;
        }
}
function handleSendOtpError(error){
    console.error('Error sending OTP:', error);
    const sendOtpBtn = document.getElementById('sendOtpBtn');
    triggerMobileVibration('error');
    showNotifier('Failed to send OTP. Please try again.', 'error');
    
    if (sendOtpBtn) {
        sendOtpBtn.disabled = false;
        sendOtpBtn.textContent = "Send OTP (Email)";
    }
    
    const resendBtn = document.getElementById("resendOtpBtn");
    if (resendBtn) resendBtn.disabled = true;
}

document.getElementById("resendOtpBtn").addEventListener("click", function() {
    const emailInput = document.getElementById('emailInput');
    const email = emailInput.value;

    if (document.getElementById('timer').textContent !== "OTP expired. Please request a new OTP.") {
        return;
    }

    const resendOtpBtn = this;
    resendOtpBtn.disabled = true;
    resendOtpBtn.textContent = 'Resending OTP...';

    const formData = new FormData();
    formData.append('sendOtp', 'true');
    formData.append('email', email);

    if (typeof grecaptcha !== 'undefined' && window.RECAPTCHA_SITE_KEY) {
        grecaptcha.ready(function(){
            grecaptcha.execute(window.RECAPTCHA_SITE_KEY, {action:'send_otp'}).then(function(token){
                formData.append('g-recaptcha-response', token);
                                fetch('student_register.php', { method:'POST', body: formData, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                  .then(r=>r.json())
                  .then(data => {
                      if (data.status === 'success') {
                          triggerMobileVibration('success');
                          showNotifier(data.message, 'success');
                          startOtpTimer();
                      } else {
                          triggerMobileVibration('error');
                          showNotifier(data.message, 'error');
                          resendOtpBtn.disabled = false;
                          resendOtpBtn.textContent = "Resend OTP";
                      }
                  })
                  .catch(error => {
                      console.error('Error sending OTP:', error);
                      triggerMobileVibration('error');
                      showNotifier('Failed to send OTP. Please try again.', 'error');
                      resendOtpBtn.disabled = false;
                      resendOtpBtn.textContent = "Resend OTP";
                  });
            });
        });
    } else {
        fetch('student_register.php', { method: 'POST', body: formData, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r=>r.json())
            .then(data => {
                if (data.status === 'success') {
                    triggerMobileVibration('success');
                    showNotifier(data.message, 'success');
                    startOtpTimer();
                } else {
                    triggerMobileVibration('error');
                    showNotifier(data.message, 'error');
                    resendOtpBtn.disabled = false;
                    resendOtpBtn.textContent = "Resend OTP";
                }
            })
            .catch(error => {
                console.error('Error sending OTP:', error);
                triggerMobileVibration('error');
                showNotifier('Failed to send OTP. Please try again.', 'error');
                resendOtpBtn.disabled = false;
                resendOtpBtn.textContent = "Resend OTP";
            });
    }
});

document.getElementById("verifyOtpBtn").addEventListener("click", function() {
    const enteredOtp = document.getElementById('otp').value;
    const emailForOtpVerification = document.getElementById('emailInput').value;

    console.log('🔐 OTP Verification Debug:');
    console.log('  Entered OTP:', enteredOtp);
    console.log('  Email:', emailForOtpVerification);

    if (!enteredOtp) {
        highlightMissingFields([document.getElementById('otp')]);
        showNotifier('Please enter the OTP.', 'error');
        return;
    }
    
    if (enteredOtp.length !== 6) {
        showNotifier('OTP must be 6 digits.', 'error');
        return;
    }

    const verifyOtpBtn = this;
    verifyOtpBtn.disabled = true;
    verifyOtpBtn.textContent = 'Verifying...';

    const formData = new FormData();
    formData.append('verifyOtp', 'true');
    formData.append('otp', enteredOtp);
    formData.append('email', emailForOtpVerification);
    
    console.log('📤 Sending OTP verification request...');

    if (typeof grecaptcha !== 'undefined' && window.RECAPTCHA_SITE_KEY) {
        grecaptcha.ready(function(){
            grecaptcha.execute(window.RECAPTCHA_SITE_KEY, {action:'verify_otp'}).then(function(token){
                formData.append('g-recaptcha-response', token);
                                fetch('student_register.php', { method:'POST', body: formData, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                  .then(r=>r.json())
                  .then(handleVerifyOtpResponse)
                  .catch(handleVerifyOtpError);
            });
        });
    } else {
        fetch('student_register.php', { method: 'POST', body: formData, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r=>r.json())
            .then(handleVerifyOtpResponse)
            .catch(handleVerifyOtpError);
    }
});

function handleVerifyOtpResponse(data){
    const verifyOtpBtn = document.getElementById('verifyOtpBtn');
        console.log('📥 OTP verification response:', data); // Debug log
        if (data.status === 'success') {
            console.log('✅ OTP Verified Successfully!');
            triggerMobileVibration('success');
            showNotifier(data.message, 'success');
            setOtpVerifiedState(true);
            trackOTPVerification(); // Track OTP verification completion
            
            // Add null checks for all DOM element updates
            const otpInput = document.getElementById('otp');
            if (otpInput) otpInput.disabled = true;
            
            if (verifyOtpBtn) {
                verifyOtpBtn.classList.add('btn-success');
                verifyOtpBtn.textContent = 'Verified!';
                verifyOtpBtn.disabled = true;
            }
            
            clearInterval(countdown);
            
            const timerElement = document.getElementById('timer');
            if (timerElement) timerElement.textContent = '';
            
            const resendBtn = document.getElementById('resendOtpBtn');
            if (resendBtn) resendBtn.style.display = 'none';
            
            // Enable and highlight the next step button
            // Correct step navigation button id (OTP verification is Step 9)
            let nextBtn = document.getElementById('nextStep9Btn');
            // Fallback for legacy id still present
            if (!nextBtn) nextBtn = document.getElementById('nextStep7Btn');
            if (nextBtn) {
                nextBtn.disabled = false;
                nextBtn.classList.add('btn-success');
                nextBtn.textContent = 'Continue - Email Verified';
                
                // ❌ REMOVED: Event listener attachment for nextStep
                // The nextStep function is defined in inline script (student_register.php)
                // The button already has onclick="nextStep()" in HTML, so no listener needed
                // Adding listener here causes conflicts and "nextStep is not defined" errors
                
                console.log('✅ Next step button enabled successfully'); // Debug log
            } else {
                console.error('❌ nextStep7Btn not found'); // Debug log
            }
            
            const emailInput = document.getElementById('emailInput');
            if (emailInput) {
                emailInput.disabled = true;
                emailInput.classList.add('verified-email');
            }
            const otpSection = document.getElementById('otpSection');
            if (otpSection) otpSection.style.display = 'none';
        } else {
            console.error('❌ OTP Verification Failed:', data.message);
            triggerMobileVibration('error');
            showNotifier(data.message || 'OTP verification failed. Please try again.', 'error');
            if (verifyOtpBtn) {
                verifyOtpBtn.disabled = false;
                verifyOtpBtn.textContent = "Verify OTP";
            }
            setOtpVerifiedState(false);
        }
}
function handleVerifyOtpError(error){
    console.error('Error verifying OTP:', error);
    const verifyOtpBtn = document.getElementById('verifyOtpBtn');
    triggerMobileVibration('error');
    showNotifier('Failed to verify OTP. Please try again.', 'error');
    verifyOtpBtn.disabled = false;
    verifyOtpBtn.textContent = "Verify OTP";
    setOtpVerifiedState(false);
}

function startOtpTimer() {
    let timeLeft = 300;
    clearInterval(countdown);
    // Create or reference timer element safely
    let timerEl = document.getElementById('timer');
    if (!timerEl) {
        const otpSection = document.getElementById('otpSection');
        if (otpSection) {
            timerEl = document.createElement('div');
            timerEl.id = 'timer';
            timerEl.className = 'text-muted small mt-2';
            // Place near resend button if available, otherwise append to section
            const resend = document.getElementById('resendOtpBtn');
            if (resend && resend.parentElement) {
                resend.parentElement.appendChild(timerEl);
            } else {
                otpSection.appendChild(timerEl);
            }
        }
    }
    if (timerEl) timerEl.textContent = `Time left: ${timeLeft} seconds`;

    countdown = setInterval(function() {
        timeLeft--;
        const t = document.getElementById('timer');
        if (t) t.textContent = `Time left: ${timeLeft} seconds`;

        if (timeLeft <= 0) {
            clearInterval(countdown);
            triggerMobileVibration('warning');
            const timerDiv = document.getElementById('timer');
            if (timerDiv) timerDiv.textContent = "OTP expired. Please request a new OTP.";
            const otp = document.getElementById('otp');
            if (otp) otp.disabled = false;
            const vbtn = document.getElementById('verifyOtpBtn');
            if (vbtn) {
                vbtn.disabled = false;
                vbtn.textContent = 'Verify OTP';
                vbtn.classList.remove('btn-success');
            }
            const rbtn = document.getElementById('resendOtpBtn');
            if (rbtn) {
                rbtn.disabled = false;
                rbtn.style.display = 'block';
            }
            const sbtn = document.getElementById('sendOtpBtn');
            if (sbtn) sbtn.classList.add('d-none');
            setOtpVerifiedState(false);
            const legacyBtn = document.getElementById('nextStep7Btn');
            const step9Btn = document.getElementById('nextStep9Btn');
            if (step9Btn) step9Btn.disabled = true; else if (legacyBtn) legacyBtn.disabled = true;
        }
    }, 1000);
}

// ============================================
// REAL-TIME PASSWORD VALIDATION WITH STRICT REQUIREMENTS
// ============================================
function setupPasswordStrength() {
    console.log('🔐 Setting up LIVE password validation with strict requirements...');
    
    const passwordInput = document.getElementById('password');
    const confirmPasswordInput = document.getElementById('confirmPassword');
    const strengthBar = document.getElementById('strengthBar');
    const strengthText = document.getElementById('strengthText');
    const passwordMatchText = document.getElementById('passwordMatchText');
    const submitButton = document.querySelector('button[type="submit"][name="register"]');
    
    // Safety check - ensure elements exist
    if (!passwordInput || !strengthBar || !strengthText) {
        console.warn('❌ Password validation elements not found');
        return;
    }
    
    if (!confirmPasswordInput) {
        console.warn('⚠️ Confirm password input not found');
    }
    
    if (!submitButton) {
        console.warn('⚠️ Submit button not found');
    }
    
    console.log('✅ All password elements found, setting up validation...');
    
    // Internal function: Calculate 100-point strength score with STRICT requirements
    function calculatePasswordStrength(password) {
        let strength = 0;
        let feedback = [];
        
        // STRICT REQUIREMENT 1: Minimum 12 characters (25 points)
        if (password.length >= 12) {
            strength += 25;
        } else if (password.length > 0) {
            feedback.push(`${12 - password.length} more character${12 - password.length > 1 ? 's' : ''}`);
        }
        
        // STRICT REQUIREMENT 2: Uppercase letter (25 points)
        if (/[A-Z]/.test(password)) {
            strength += 25;
        } else if (password.length > 0) {
            feedback.push('uppercase letter (A-Z)');
        }
        
        // STRICT REQUIREMENT 3: Lowercase letter (25 points)
        if (/[a-z]/.test(password)) {
            strength += 25;
        } else if (password.length > 0) {
            feedback.push('lowercase letter (a-z)');
        }
        
        // STRICT REQUIREMENT 4: Number (15 points)
        if (/[0-9]/.test(password)) {
            strength += 15;
        } else if (password.length > 0) {
            feedback.push('number (0-9)');
        }
        
        // STRICT REQUIREMENT 5: Special character (10 points)
        if (/[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]/.test(password)) {
            strength += 10;
        } else if (password.length > 0) {
            feedback.push('special character (!@#$%...)');
        }
        
        console.log(`💪 Password strength: ${strength} / 100`);
        
        return { strength, feedback };
    }
    
    // Update strength bar UI with colors and text
    function updatePasswordStrengthUI() {
        const password = passwordInput.value;
        const { strength, feedback } = calculatePasswordStrength(password);
        
        console.log(`🔍 Password input changed, length: ${password.length}`);
        console.log(`📊 Strength: ${strength}%, Missing: ${feedback.join(', ')}`);
        
        strengthBar.style.width = strength + '%';
        strengthBar.setAttribute('aria-valuenow', strength);
        
        if (password.length === 0) {
            // Empty state
            strengthBar.className = 'progress-bar bg-secondary';
            strengthText.innerHTML = '<i class="bi bi-info-circle me-1"></i>Enter a password to see strength';
            strengthText.className = 'text-muted d-block mt-1';
            console.log('⚪ Password empty');
        } else if (strength < 40) {
            // RED: Weak (missing 3+ requirements)
            strengthBar.className = 'progress-bar bg-danger';
            strengthText.innerHTML = '<i class="bi bi-x-circle me-1"></i><strong>Weak</strong> - Need: ' + feedback.join(', ');
            strengthText.className = 'text-danger d-block mt-1 fw-bold';
            console.log('🔴 Password WEAK');
        } else if (strength < 70) {
            // YELLOW: Fair (missing 1-2 requirements)
            strengthBar.className = 'progress-bar bg-warning';
            strengthText.innerHTML = '<i class="bi bi-exclamation-triangle me-1"></i><strong>Fair</strong> - Need: ' + feedback.join(', ');
            strengthText.className = 'text-warning d-block mt-1 fw-bold';
            console.log('🟡 Password FAIR');
        } else if (strength < 100) {
            // BLUE: Good (missing 1 minor requirement)
            strengthBar.className = 'progress-bar bg-info';
            strengthText.innerHTML = '<i class="bi bi-check-circle me-1"></i><strong>Good</strong> - ' + (feedback.length > 0 ? 'Could add: ' + feedback.join(', ') : 'Strong password!');
            strengthText.className = 'text-info d-block mt-1 fw-bold';
            console.log('🔵 Password GOOD');
        } else {
            // GREEN: Strong (all requirements met)
            strengthBar.className = 'progress-bar bg-success';
            strengthText.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i><strong>Excellent!</strong> All requirements met';
            strengthText.className = 'text-success d-block mt-1 fw-bold';
            console.log('🟢 Password STRONG');
            if (typeof triggerMobileVibration === 'function') {
                triggerMobileVibration('success');
            }
        }
        
        // Always check password match after updating strength
        checkPasswordMatch();
    }
    
    // Check if passwords match
    function checkPasswordMatch() {
        if (!confirmPasswordInput || !passwordMatchText) {
            updateSubmitButton();
            return;
        }
        
        const password = passwordInput.value;
        const confirmPassword = confirmPasswordInput.value;
        
        console.log(`🔒 Checking password match... (password: ${password.length}, confirm: ${confirmPassword.length})`);
        
        if (confirmPassword.length === 0) {
            // Empty confirm field - show hint
            passwordMatchText.innerHTML = '<i class="bi bi-info-circle me-1"></i>Re-enter your password to confirm';
            passwordMatchText.className = 'text-muted d-block mt-1';
            confirmPasswordInput.classList.remove('is-valid', 'is-invalid');
            console.log('⚪ Confirm password empty');
        } else if (password === confirmPassword) {
            // GREEN: Passwords match
            passwordMatchText.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i><strong>Passwords match!</strong>';
            passwordMatchText.className = 'text-success d-block mt-1 fw-bold';
            confirmPasswordInput.classList.remove('is-invalid');
            confirmPasswordInput.classList.add('is-valid');
            console.log('✅ Passwords MATCH');
            if (typeof triggerMobileVibration === 'function') {
                triggerMobileVibration('success');
            }
        } else {
            // RED: Passwords don't match
            passwordMatchText.innerHTML = '<i class="bi bi-x-circle-fill me-1"></i><strong>Passwords do not match</strong>';
            passwordMatchText.className = 'text-danger d-block mt-1 fw-bold';
            confirmPasswordInput.classList.remove('is-valid');
            confirmPasswordInput.classList.add('is-invalid');
            console.log('❌ Passwords DO NOT MATCH');
        }
        
        // Update submit button state
        updateSubmitButton();
    }
    
    // Enable/disable submit button based on validation
    function updateSubmitButton() {
        if (!submitButton) {
            return;
        }
        
        const password = passwordInput.value;
        const confirmPassword = confirmPasswordInput ? confirmPasswordInput.value : '';
        const { strength } = calculatePasswordStrength(password);
        
        // Check all requirements
        const isPasswordStrong = strength >= 70; // Must be "Good" or better
        const doPasswordsMatch = password === confirmPassword && password.length > 0;
        const areFieldsFilled = password.length > 0 && confirmPassword.length > 0;
        
        console.log(`🔍 Submit button check - Strong: ${isPasswordStrong}, Match: ${doPasswordsMatch}, Filled: ${areFieldsFilled}`);
        
        if (!isPasswordStrong || !doPasswordsMatch || !areFieldsFilled) {
            submitButton.disabled = true;
            submitButton.classList.add('opacity-50');
            submitButton.style.cursor = 'not-allowed';
            submitButton.title = 'Complete all password requirements to enable';
            console.log('🔒 Submit button DISABLED');
        } else {
            submitButton.disabled = false;
            submitButton.classList.remove('opacity-50');
            submitButton.style.cursor = 'pointer';
            submitButton.title = '';
            console.log('✅ Submit button ENABLED');
        }
    }
    
    // Initial state - ensure submit button is disabled on load
    if (submitButton) {
        submitButton.disabled = true;
        submitButton.classList.add('opacity-50');
        submitButton.style.cursor = 'not-allowed';
        submitButton.title = 'Complete all password requirements to enable';
        console.log('🔒 Initial state: Submit button DISABLED');
    }
    
    // Set initial hint text
    if (passwordMatchText) {
        passwordMatchText.innerHTML = '<i class="bi bi-info-circle me-1"></i>Re-enter your password to confirm';
        passwordMatchText.className = 'text-muted d-block mt-1';
    }
    
    if (strengthText && passwordInput.value.length === 0) {
        strengthText.innerHTML = '<i class="bi bi-info-circle me-1"></i>Enter a password to see strength';
        strengthText.className = 'text-muted d-block mt-1';
    }
    
    // Attach event listeners for LIVE updates
    console.log('📡 Attaching event listeners...');
    passwordInput.addEventListener('input', updatePasswordStrengthUI);
    passwordInput.addEventListener('keyup', updatePasswordStrengthUI); // Backup for some browsers
    
    if (confirmPasswordInput) {
        confirmPasswordInput.addEventListener('input', checkPasswordMatch);
        confirmPasswordInput.addEventListener('keyup', checkPasswordMatch); // Backup for some browsers
    }
    
    // If fields already have values (e.g., browser autofill), validate immediately
    if (passwordInput.value.length > 0) {
        console.log('⚡ Password field has initial value, triggering validation...');
        updatePasswordStrengthUI();
    }
    
    console.log('✅ Password validation setup complete!');
}

// Call setup when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', setupPasswordStrength);
} else {
    // DOM already loaded
    setupPasswordStrength();
}

// ============================================================
// STUDENT NAME VALIDATION (Gibberish/Keyboard Mashing Detection)
// ============================================================
function validateNameField(field, value) {
    const fieldName = field.name;
    const nameType = fieldName.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase());
    
    // Must contain only letters, spaces, hyphens, apostrophes
    if (!/^[A-Za-z\s\-']+$/.test(value)) {
        return {
            isValid: false,
            error: 'Names can only contain letters, spaces, hyphens (-), and apostrophes (\')'
        };
    }
    
    // Must be at least 2 characters (except middle name which is optional)
    if (value.trim().length < 2 && fieldName !== 'middle_name') {
        return {
            isValid: false,
            error: `${nameType} must be at least 2 characters long`
        };
    }
    
    // Must contain at least one vowel (real names have vowels)
    // EXCEPTION: Middle and last names can be 2-character consonant surnames (e.g., "Dy", "Ng", "Wu")
    const isShortSurname = (fieldName === 'middle_name' || fieldName === 'last_name') && value.trim().length === 2;
    if (!/[aeiouAEIOU]/.test(value) && !isShortSurname) {
        return {
            isValid: false,
            error: `Please enter a valid ${nameType.toLowerCase()} (names typically contain vowels)`
        };
    }
    
    // Detect keyboard mashing patterns
    // 1. Check for excessive repeated characters (e.g., "aaaa", "jjjj")
    if (/(.)\1{3,}/.test(value.toLowerCase())) {
        return {
            isValid: false,
            error: `Please enter a real ${nameType.toLowerCase()} (too many repeated characters detected)`
        };
    }
    
    // 2. Check for repeated 2-character patterns (e.g., "adadad", "asdasd")
    if (/(.{2})\1{2,}/.test(value.toLowerCase())) {
        return {
            isValid: false,
            error: `Please enter a real ${nameType.toLowerCase()} (repeated pattern detected)`
        };
    }
    
    // 3. Check for repeated 3-character patterns (e.g., "abcabcabc")
    if (/(.{3})\1{2,}/.test(value.toLowerCase())) {
        return {
            isValid: false,
            error: `Please enter a real ${nameType.toLowerCase()} (repeated pattern detected)`
        };
    }
    
    // 4. Check for sequential keyboard patterns (horizontal rows)
    const keyboardRows = [
        'qwertyuiop',
        'asdfghjkl',
        'zxcvbnm',
        'qazwsxedcrfvtgbyhnujmikolp' // vertical patterns
    ];
    
    const lowerValue = value.toLowerCase().replace(/[^a-z]/g, '');
    for (const row of keyboardRows) {
        // Check for 4+ consecutive characters from same keyboard row
        for (let i = 0; i < row.length - 3; i++) {
            const pattern = row.substring(i, i + 4);
            if (lowerValue.includes(pattern) || lowerValue.includes(pattern.split('').reverse().join(''))) {
                return {
                    isValid: false,
                    error: `Please enter a real ${nameType.toLowerCase()} (keyboard pattern detected)`
                };
            }
        }
    }
    
    // 5. Check consonant-to-vowel ratio (real names have balanced ratios)
    // EXCEPTION: Skip ratio check for 2-character surnames (e.g., "Dy", "Ng")
    const consonants = (value.match(/[bcdfghjklmnpqrstvwxyzBCDFGHJKLMNPQRSTVWXYZ]/g) || []).length;
    const vowels = (value.match(/[aeiouAEIOU]/g) || []).length;
    if (consonants > 0 && vowels > 0 && !isShortSurname) {
        const ratio = consonants / vowels;
        // If ratio is > 5:1 for names, it's likely gibberish
        if (ratio > 5) {
            return {
                isValid: false,
                error: `Please enter a real ${nameType.toLowerCase()} (unusual letter pattern detected)`
            };
        }
    }
    
    // 6. Check for single letters repeated with spaces (e.g., "a b c d")
    const words = value.trim().split(/\s+/);
    const singleLetterWords = words.filter(w => w.length === 1);
    if (singleLetterWords.length >= 2) {
        return {
            isValid: false,
            error: `Please enter a real ${nameType.toLowerCase()} (too many single-letter parts detected)`
        };
    }
    
    // 7. Check for low character diversity (names should use varied letters)
    // Skip for 2-character surnames (e.g., "Dy", "Ng")
    if (!isShortSurname && value.length >= 5) {
        const uniqueChars = new Set(value.toLowerCase().replace(/[^a-z]/g, '').split('')).size;
        const totalChars = value.replace(/[^a-z]/gi, '').length;
        const diversityRatio = uniqueChars / totalChars;
        
        // If less than 40% unique characters, it's likely gibberish (e.g., "asdadawdasdad" has low diversity)
        if (diversityRatio < 0.4) {
            return {
                isValid: false,
                error: `Please enter a real ${nameType.toLowerCase()} (too many repeated letters detected)`
            };
        }
    }
    
    return { isValid: true };
}

function setupStudentNameValidation() {
    console.log('🔤 Setting up student name validation...');
    
    const nameFields = [
        { input: document.querySelector('input[name="first_name"]'), label: 'First Name' },
        { input: document.querySelector('input[name="middle_name"]'), label: 'Middle Name' },
        { input: document.querySelector('input[name="last_name"]'), label: 'Last Name' }
    ];
    
    nameFields.forEach(({ input, label }) => {
        if (!input) {
            console.warn(`⚠️ ${label} input not found`);
            return;
        }
        
        // Create warning div if it doesn't exist
        let warningDiv = input.parentElement.querySelector('.name-validation-warning');
        if (!warningDiv) {
            warningDiv = document.createElement('div');
            warningDiv.className = 'name-validation-warning alert mt-2';
            warningDiv.style.display = 'none';
            input.parentElement.appendChild(warningDiv);
        }
        
        input.addEventListener('blur', function() {
            const value = this.value.trim();
            
            // Clear previous warnings
            warningDiv.style.display = 'none';
            this.classList.remove('is-invalid');
            this.style.borderColor = '';
            
            // Middle name is optional, so skip validation if empty
            if (!value && input.name === 'middle_name') return;
            
            if (!value) return; // Empty is handled by required validation
            
            // Validate name
            const validation = validateNameField(this, value);
            
            if (!validation.isValid) {
                warningDiv.textContent = '⚠️ ' + validation.error;
                warningDiv.className = 'name-validation-warning alert alert-danger mt-2';
                warningDiv.style.display = 'block';
                this.classList.add('is-invalid');
                this.style.borderColor = '#dc3545';
                console.log(`❌ ${label} validation failed: ${validation.error}`);
            }
        });
        
        // Clear validation on input
        input.addEventListener('input', function() {
            warningDiv.style.display = 'none';
            this.classList.remove('is-invalid');
            this.style.borderColor = '';
        });
    });
    
    console.log('✅ Student name validation initialized');
}

// Auto-initialize
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', setupStudentNameValidation);
} else {
    setupStudentNameValidation();
}

// ============================================================
// MOTHER'S FULL NAME VALIDATION (Gibberish/Keyboard Mashing Detection)
// ============================================================
function validateMothersFullName(value) {
    // Must contain only valid name characters
    if (!/^[A-Za-z\s\-']+$/.test(value)) {
        return {
            isValid: false,
            error: 'Mother\'s name can only contain letters, spaces, hyphens (-), and apostrophes (\')'
        };
    }
    
    // Split into words (must have at least 3 words - first, middle, and maiden surname)
    const words = value.trim().split(/\s+/).filter(w => w.length > 0);
    if (words.length < 3) {
        return {
            isValid: false,
            error: 'Please enter mother\'s complete maiden name (at least first, middle, and last name)'
        };
    }
    
    // Each word must be at least 2 characters
    for (const word of words) {
        if (word.length < 2) {
            return {
                isValid: false,
                error: 'Each part of the name must be at least 2 characters long'
            };
        }
    }
    
    // Must contain at least one vowel (real names have vowels)
    if (!/[aeiouAEIOU]/.test(value)) {
        return {
            isValid: false,
            error: 'Please enter a valid name (names typically contain vowels)'
        };
    }
    
    // Detect keyboard mashing patterns
    // 1. Check for excessive repeated characters (e.g., "aaaa", "jjjj")
    if (/(.)\1{3,}/.test(value.toLowerCase())) {
        return {
            isValid: false,
            error: 'Please enter a real name (too many repeated characters detected)'
        };
    }
    
    // 2. Check for repeated 2-character patterns (e.g., "adadad", "asdasd")
    if (/(.{2})\1{2,}/.test(value.toLowerCase())) {
        return {
            isValid: false,
            error: 'Please enter a real name (repeated pattern detected)'
        };
    }
    
    // 3. Check for repeated 3-character patterns (e.g., "abcabcabc")
    if (/(.{3})\1{2,}/.test(value.toLowerCase())) {
        return {
            isValid: false,
            error: 'Please enter a real name (repeated pattern detected)'
        };
    }
    
    // 4. Check for sequential keyboard patterns (horizontal rows)
    const keyboardRows = [
        'qwertyuiop',
        'asdfghjkl',
        'zxcvbnm',
        'qazwsxedcrfvtgbyhnujmikolp' // vertical patterns
    ];
    
    const lowerValue = value.toLowerCase().replace(/[^a-z]/g, '');
    for (const row of keyboardRows) {
        // Check for 4+ consecutive characters from same keyboard row
        for (let i = 0; i < row.length - 3; i++) {
            const pattern = row.substring(i, i + 4);
            if (lowerValue.includes(pattern) || lowerValue.includes(pattern.split('').reverse().join(''))) {
                return {
                    isValid: false,
                    error: 'Please enter a real name (keyboard pattern detected)'
                };
            }
        }
    }
    
    // 5. Check consonant-to-vowel ratio (real names have balanced ratios)
    const consonants = (value.match(/[bcdfghjklmnpqrstvwxyzBCDFGHJKLMNPQRSTVWXYZ]/g) || []).length;
    const vowels = (value.match(/[aeiouAEIOU]/g) || []).length;
    if (consonants > 0 && vowels > 0) {
        const ratio = consonants / vowels;
        // If ratio is > 6:1, it's likely gibberish (e.g., "hsjkfhlnds" has ratio ~9:1)
        if (ratio > 6) {
            return {
                isValid: false,
                error: 'Please enter a real name (unusual letter pattern detected)'
            };
        }
    }
    
    // 6. Check for low character diversity (names should use varied letters)
    if (value.length >= 5) {
        const uniqueChars = new Set(value.toLowerCase().replace(/[^a-z]/g, '').split('')).size;
        const totalChars = value.replace(/[^a-z]/gi, '').length;
        const diversityRatio = uniqueChars / totalChars;
        
        // If less than 40% unique characters, it's likely gibberish
        if (diversityRatio < 0.4) {
            return {
                isValid: false,
                error: 'Please enter a real name (too many repeated letters detected)'
            };
        }
    }
    
    return { isValid: true };
}

function setupMothersFullNameValidation() {
    console.log('👩 Setting up mother\'s full name validation...');
    
    const mothersFullNameInput = document.querySelector('input[name="mothers_fullname"]');
    
    if (!mothersFullNameInput) {
        console.warn('⚠️ Mother\'s full name input not found');
        return;
    }
    
    // Create warning div if it doesn't exist
    let warningDiv = mothersFullNameInput.parentElement.querySelector('.mothers-name-warning');
    if (!warningDiv) {
        warningDiv = document.createElement('div');
        warningDiv.className = 'mothers-name-warning alert mt-2';
        warningDiv.style.display = 'none';
        mothersFullNameInput.parentElement.appendChild(warningDiv);
    }
    
    mothersFullNameInput.addEventListener('blur', function() {
        const value = this.value.trim();
        
        // Clear previous warnings
        warningDiv.style.display = 'none';
        this.classList.remove('is-invalid');
        this.style.borderColor = '';
        
        if (!value) return; // Empty is handled by required validation
        
        // Validate mother's full name
        const validation = validateMothersFullName(value);
        
        if (!validation.isValid) {
            warningDiv.textContent = '⚠️ ' + validation.error;
            warningDiv.className = 'mothers-name-warning alert alert-danger mt-2';
            warningDiv.style.display = 'block';
            this.classList.add('is-invalid');
            this.style.borderColor = '#dc3545';
            console.log(`❌ Mother's name validation failed: ${validation.error}`);
        }
    });
    
    // Clear validation on input
    mothersFullNameInput.addEventListener('input', function() {
        warningDiv.style.display = 'none';
        this.classList.remove('is-invalid');
        this.style.borderColor = '';
    });
    
    console.log('✅ Mother\'s full name validation initialized');
}

// Auto-initialize
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', setupMothersFullNameValidation);
} else {
    setupMothersFullNameValidation();
}

// ----- FIX FOR REQUIRED FIELD ERROR -----
let isSubmitting = false; // Flag to prevent multiple submissions

document.getElementById('multiStepForm').addEventListener('submit', async function(e) {
    console.log('=== FORM SUBMISSION DEBUG ===');
    console.log('Current step:', currentStep);
    console.log('Is submitting:', isSubmitting);
    
    if (currentStep !== 10) {
        console.log('❌ Not on step 10, preventing submission');
        e.preventDefault();
        triggerMobileVibration('error');
        showNotifier('Please complete all steps first.', 'error');
        return;
    }
    
    // If already in the process of submitting, let it proceed
    if (isSubmitting) {
        console.log('✅ Already submitting, allowing natural submission');
        return true;
    }
    
    console.log('🛑 Preventing default to run validation');
    // Prevent default submission for validation
    e.preventDefault();
    
    // Check for duplicates before final submission
    console.log('🔍 Validating account details...');
    const isValid = await validateAccountDetails();
    if (!isValid) {
        console.log('❌ Account validation failed');
        return false;
    }
    console.log('✅ Account validation passed');
    
    // Show final confirmation
    console.log('📋 Showing confirmation dialog...');
    const confirmSubmit = confirm(
        'Are you sure you want to submit your registration?\n\n' +
        'Please review:\n' +
        '✓ All personal information is correct\n' +
        '✓ All required documents are uploaded and verified\n' +
        '✓ Email and phone number are valid\n' +
        '✓ Password meets requirements\n\n' +
        'Once submitted, you cannot edit your application.'
    );
    
    if (!confirmSubmit) {
        console.log('❌ User cancelled submission');
        return false;
    }
    console.log('✅ User confirmed submission');
    
    // Set flag to indicate submission in progress
    isSubmitting = true;
    console.log('🚀 Setting isSubmitting = true');
    
    // Mark form as submitted to prevent cleanup warning
    formSubmitted = true;
    registrationInProgress = false;
    
    // Show all panels and enable all fields for proper form submission
    console.log('🔧 Enabling all form fields...');
    document.querySelectorAll('.step-panel').forEach(panel => {
        panel.classList.remove('d-none');
        panel.style.display = '';
    });
    
    let enabledCount = 0;
    document.querySelectorAll('input, select, textarea').forEach(el => {
        if (el.disabled) {
            el.disabled = false;
            enabledCount++;
        }
    });
    console.log(`✅ Enabled ${enabledCount} form fields`);
    
    // Validate all required fields are filled
    const requiredFields = this.querySelectorAll('input[required], select[required], textarea[required]');
    const emptyRequired = [];
    requiredFields.forEach(field => {
        if (!field.value.trim()) {
            emptyRequired.push(field.name || field.id);
        }
    });
    
    if (emptyRequired.length > 0) {
        console.log('❌ Required fields missing:', emptyRequired);
        showNotifier(`Missing required fields: ${emptyRequired.join(', ')}`, 'error');
        isSubmitting = false;
        return false;
    }
    
    triggerMobileVibration('success'); // Success vibration on form submission
    
    // Ensure we have a *fresh* reCAPTCHA v3 token for the final POST (tokens expire quickly & action must be register)
    let captchaToken = '';
    if (typeof grecaptcha !== 'undefined') {
        try {
            console.log('🔐 Executing grecaptcha for final submission...');
            captchaToken = await grecaptcha.execute(window.RECAPTCHA_SITE_KEY, { action: 'register' });
            if (!captchaToken) {
                console.warn('⚠️ grecaptcha returned empty token');
            }
        } catch (err) {
            console.error('reCAPTCHA execution failed:', err);
        }
    } else {
        console.warn('grecaptcha not loaded; proceeding without refreshed token');
    }

    // Create a new form and submit it to bypass any lingering listeners
    console.log('⚡ Creating new form element for submission...');
    const newForm = document.createElement('form');
    newForm.method = 'POST';
    newForm.action = window.location.href;

    // Copy all existing form data
    const formData = new FormData(this);
    for (const [key, value] of formData.entries()) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = key;
        input.value = value;
        newForm.appendChild(input);
    }
    
    // CRITICAL: Explicitly ensure password fields are included (they might be missed if hidden)
    const passwordField = document.getElementById('password');
    const confirmPasswordField = document.getElementById('confirmPassword');
    
    if (passwordField && passwordField.value) {
        // Check if password was already added by FormData
        const existingPassword = Array.from(newForm.querySelectorAll('input[name="password"]'))[0];
        if (!existingPassword) {
            console.log('⚠️ Password field was missing from FormData, adding explicitly');
            const passwordInput = document.createElement('input');
            passwordInput.type = 'hidden';
            passwordInput.name = 'password';
            passwordInput.value = passwordField.value;
            newForm.appendChild(passwordInput);
        } else {
            console.log('✅ Password field found in FormData:', existingPassword.value.substring(0, 3) + '***');
        }
    } else {
        console.error('❌ Password field is empty or not found!');
    }
    
    if (confirmPasswordField && confirmPasswordField.value) {
        const existingConfirm = Array.from(newForm.querySelectorAll('input[name="confirm_password"]'))[0];
        if (!existingConfirm) {
            console.log('⚠️ Confirm password field was missing from FormData, adding explicitly');
            const confirmInput = document.createElement('input');
            confirmInput.type = 'hidden';
            confirmInput.name = 'confirm_password';
            confirmInput.value = confirmPasswordField.value;
            newForm.appendChild(confirmInput);
        }
    }

    // Overwrite / add the (possibly refreshed) reCAPTCHA token explicitly
    if (captchaToken) {
        const existing = Array.from(newForm.querySelectorAll('input[name="g-recaptcha-response"]'))[0];
        if (existing) {
            existing.value = captchaToken;
        } else {
            const recaptchaHidden = document.createElement('input');
            recaptchaHidden.type = 'hidden';
            recaptchaHidden.name = 'g-recaptcha-response';
            recaptchaHidden.value = captchaToken;
            newForm.appendChild(recaptchaHidden);
        }
    }

    // Add the register field (flag for server)
    const registerInput = document.createElement('input');
    registerInput.type = 'hidden';
    registerInput.name = 'register';
    registerInput.value = '1';
    newForm.appendChild(registerInput);

    document.body.appendChild(newForm);
    console.log('🎯 Submitting new form with reCAPTCHA token length:', captchaToken ? captchaToken.length : 0);
    newForm.submit();
});

// ----- DOCUMENT UPLOAD AND OCR FUNCTIONALITY -----

function validateFilename(filename, firstName, lastName) {
    // Remove file extension for validation
    const nameWithoutExt = filename.replace(/\.[^/.]+$/, '');

    // Expected format: Lastname_Firstname_EAF
    const expectedFormat = `${lastName}_${firstName}_EAF`;

    // Case-insensitive comparison
    return nameWithoutExt.toLowerCase() === expectedFormat.toLowerCase();
}

function updateProcessButtonState() {
    const processBtn = document.getElementById('processOcrBtn');
    const fileInput = document.getElementById('enrollmentForm');

    if (fileInput.files.length > 0 && filenameValid) {
        processBtn.disabled = false;
    } else {
        processBtn.disabled = true;
    }
}

document.getElementById('enrollmentForm').addEventListener('change', function(e) {
    const file = e.target.files[0];
    const filenameError = document.getElementById('filenameError');

    if (file) {
        trackFileUpload(); // Track that user has uploaded files
        triggerMobileVibration('success'); // File selected vibration
        
        // Get form data for filename validation
        const firstName = document.querySelector('input[name="first_name"]').value.trim();
        const lastName = document.querySelector('input[name="last_name"]').value.trim();

        if (!firstName || !lastName) {
            this.classList.add('missing-field');
            showNotifier('Please fill in your first and last name first.', 'error');
            this.value = '';
            return;
        }

        // Validate filename format
        filenameValid = validateFilename(file.name, firstName, lastName);

        if (!filenameValid) {
            this.classList.add('missing-field');
            triggerMobileVibration('error');
            filenameError.style.display = 'block';
            filenameError.innerHTML = `
                <small><i class="bi bi-exclamation-triangle me-1"></i>
                Filename must be: <strong>${lastName}_${firstName}_EAF.${file.name.split('.').pop()}</strong>
                </small>
            `;
            document.getElementById('uploadPreview').classList.add('d-none');
            document.getElementById('ocrSection').classList.add('d-none');
        } else {
            this.classList.remove('missing-field');
            triggerMobileVibration('success');
            filenameError.style.display = 'none';

            const previewContainer = document.getElementById('uploadPreview');
            const previewImage = document.getElementById('previewImage');
            const pdfPreview = document.getElementById('pdfPreview');

            previewContainer.classList.remove('d-none');
            document.getElementById('ocrSection').classList.remove('d-none');

            if (file.type.startsWith('image/')) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    previewImage.src = e.target.result;
                    previewImage.style.display = 'block';
                    pdfPreview.style.display = 'none';
                };
                reader.readAsDataURL(file);
            } else if (file.type === 'application/pdf') {
                previewImage.style.display = 'none';
                pdfPreview.style.display = 'block';
            }
        }

        // Reset verification status
        documentVerified = false;
        document.getElementById('nextStep4Btn').disabled = true;
        document.getElementById('ocrResults').classList.add('d-none');
        updateProcessButtonState();
    } else {
        filenameError.style.display = 'none';
        filenameValid = false;
        updateProcessButtonState();
    }
});

// ❌ REMOVED: Duplicate enrollment form OCR handler - now handled by processEnrollmentDocument() in student_register.php
// This was causing double-firing: one request succeeds (processEnrollmentOcr), one fails (processOcr)
/*
document.getElementById('processOcrBtn').addEventListener('click', function() {
    const fileInput = document.getElementById('enrollmentForm');
    const file = fileInput.files[0];

    if (!file) {
        highlightMissingFields([fileInput]);
        showNotifier('Please select a file first.', 'error');
        return;
    }

    if (!filenameValid) {
        highlightMissingFields([fileInput]);
        showNotifier('Please rename your file to follow the required format: Lastname_Firstname_EAF', 'error');
        return;
    }

    const processBtn = this;
    processBtn.disabled = true;
    processBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Processing...';

    // Get form data for verification
    const formData = new FormData();
    formData.append('processOcr', 'true');
    formData.append('enrollment_form', file);
    formData.append('first_name', document.querySelector('input[name="first_name"]').value);
    formData.append('middle_name', document.querySelector('input[name="middle_name"]').value);
    formData.append('last_name', document.querySelector('input[name="last_name"]').value);
    formData.append('university_id', document.querySelector('select[name="university_id"]').value);
    formData.append('year_level_id', document.querySelector('select[name="year_level_id"]').value);

    if (typeof grecaptcha !== 'undefined' && window.RECAPTCHA_SITE_KEY) {
        grecaptcha.ready(function(){
            grecaptcha.execute(window.RECAPTCHA_SITE_KEY, {action:'process_ocr'}).then(function(token){
                formData.append('g-recaptcha-response', token);
                                fetch('student_register.php', { method:'POST', body: formData, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                  .then(r=>r.json())
                  .then(handleProcessOcrResponse)
                  .catch(handleProcessOcrError);
            });
        });
    } else {
        fetch('student_register.php', { method: 'POST', body: formData, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r=>r.json())
            .then(handleProcessOcrResponse)
            .catch(handleProcessOcrError);
    }
});
*/

function handleProcessOcrResponse(data){
    const processBtn = document.getElementById('processOcrBtn');
        processBtn.disabled = false;
        processBtn.innerHTML = '<i class="bi bi-search me-2"></i>Process Document';

        if (data.status === 'success') {
            triggerMobileVibration('success');
            displayVerificationResults(data.verification);
        } else {
            triggerMobileVibration('error');
            
            // Enhanced error display for PDFs and suggestions
            let errorMessage = data.message;
            if (data.suggestions && data.suggestions.length > 0) {
                errorMessage += '\n\nSuggestions:\n' + data.suggestions.join('\n');
            }
            
            showNotifier(errorMessage, 'error');
            
            // Also show suggestions in a more user-friendly way
            if (data.suggestions) {
                const ocrResults = document.getElementById('ocrResults');
                const feedbackContainer = document.getElementById('ocrFeedback');
                
                ocrResults.classList.remove('d-none');
                feedbackContainer.style.display = 'block';
                feedbackContainer.className = 'alert alert-warning mt-3';
                
                let suggestionHTML = '<strong>' + data.message + '</strong><br><br>';
                suggestionHTML += '<strong>Please try:</strong><ul>';
                data.suggestions.forEach(suggestion => {
                    suggestionHTML += '<li>' + suggestion + '</li>';
                });
                suggestionHTML += '</ul>';
                
                feedbackContainer.innerHTML = suggestionHTML;
            }
        }
}
function handleProcessOcrError(error){
    console.error('Error processing OCR:', error);
    const processBtn = document.getElementById('processOcrBtn');
    triggerMobileVibration('error');
    showNotifier('Failed to process document. Please try again.', 'error');
    processBtn.disabled = false;
    processBtn.innerHTML = '<i class="bi bi-search me-2"></i>Process Document';
}

function displayVerificationResults(verification) {
    const resultsContainer = document.getElementById('ocrResults');
    const feedbackContainer = document.getElementById('ocrFeedback');

    resultsContainer.classList.remove('d-none');

    // Update checklist items with enhanced details
    const checks = ['firstname', 'middlename', 'lastname', 'yearlevel', 'university', 'document'];
    const checkMap = {
        'firstname': 'first_name',
        'middlename': 'middle_name', 
        'lastname': 'last_name',
        'yearlevel': 'year_level',
        'university': 'university',
        'document': 'document_keywords'
    };

    checks.forEach(check => {
        const element = document.getElementById(`check-${check}`);
        const icon = element.querySelector('i');
        const textSpan = element.querySelector('span');
        const isValid = verification[checkMap[check]];
        const confidence = verification.confidence_scores?.[checkMap[check]];
        const foundText = verification.found_text_snippets?.[checkMap[check]];

        // Get original text without any previous details
        let originalText = textSpan.textContent.split(' (')[0];

        if (isValid) {
            icon.className = 'bi bi-check-circle text-success me-2';
            element.classList.add('text-success');
            element.classList.remove('text-danger');
            
            // Add confidence score and found text if available
            let details = '';
            if (confidence !== undefined) {
                details += ` (${Math.round(confidence)}% match`;
                if (foundText && foundText.length < 50) { // Limit display length
                    details += `, found: "${foundText}"`;
                }
                details += ')';
            }
            textSpan.innerHTML = originalText + '<small class="text-muted">' + details + '</small>';
        } else {
            icon.className = 'bi bi-x-circle text-danger me-2';
            element.classList.add('text-danger');
            element.classList.remove('text-success');
            
            // Show confidence for failed checks if available
            let details = '';
            if (confidence !== undefined && confidence > 0) {
                details += ` <small class="text-muted">(${Math.round(confidence)}% match - needs 70%+)</small>`;
            }
            textSpan.innerHTML = originalText + details;
        }
    });

    if (verification.overall_success) {
        triggerMobileVibration('success');
        feedbackContainer.style.display = 'none';
        feedbackContainer.className = 'alert alert-success mt-3';
        
        let successMessage = '<strong>Verification Successful!</strong> Your document has been validated.';
        if (verification.summary) {
            successMessage += `<br><small>Passed ${verification.summary.passed_checks} of ${verification.summary.total_checks} checks`;
            if (verification.summary.average_confidence) {
                successMessage += ` (Average confidence: ${verification.summary.average_confidence}%)`;
            }
            successMessage += '</small>';
        }
        
        feedbackContainer.innerHTML = successMessage;
        feedbackContainer.style.display = 'block';
        documentVerified = true;
        document.getElementById('nextStep4Btn').disabled = false;
        showNotifier('Document verification successful!', 'success');
    } else {
        triggerMobileVibration('error');
        feedbackContainer.style.display = 'none';
        feedbackContainer.className = 'alert alert-warning mt-3';
        
        let errorMessage = '<strong>Verification Result:</strong> ';
        errorMessage += verification.summary?.recommendation || 'Some verification checks failed';
        errorMessage += '<br><small>';
        if (verification.summary) {
            errorMessage += `Passed ${verification.summary.passed_checks} of ${verification.summary.total_checks} checks`;
            if (verification.summary.average_confidence) {
                errorMessage += ` (Average confidence: ${verification.summary.average_confidence}%)`;
            }
        } else {
            errorMessage += 'Please ensure your document is clear and contains all required information';
        }
        errorMessage += '</small>';
        
        feedbackContainer.innerHTML = errorMessage;
        feedbackContainer.style.display = 'block';
        documentVerified = false;
        document.getElementById('nextStep4Btn').disabled = true;
        showNotifier('Document verification needs improvement. Check the details above.', 'error');
    }
}

// Add CSS for simple field highlighting
const style = document.createElement('style');
style.textContent = `
    .verification-checklist .form-check {
        display: flex;
        align-items: center;
        padding: 8px 0;
        border-bottom: 1px solid #eee;
    }
    .verification-checklist .form-check:last-child {
        border-bottom: none;
    }
    .verification-checklist .form-check span {
        font-size: 14px;
    }
    
    /* Simple red border for missing fields */
    .missing-field {
        border-color: #dc3545 !important;
        box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25) !important;
    }
    
    /* Shake animation */
    @keyframes shake {
        0%, 100% { transform: translateX(0); }
        10%, 30%, 50%, 70%, 90% { transform: translateX(-3px); }
        20%, 40%, 60%, 80% { transform: translateX(3px); }
    }
    
    .shake-field {
        animation: shake 0.6s ease-in-out;
    }
    
    /* Pulse animation */
    @keyframes pulse {
        0% { box-shadow: 0 0 0 0 rgba(0, 104, 218, 0.4); }
        70% { box-shadow: 0 0 0 8px rgba(0, 104, 218, 0); }
        100% { box-shadow: 0 0 0 0 rgba(0, 104, 218, 0); }
    }
    
    .pulse-field {
        animation: pulse 1s ease-in-out;
    }
`;
document.head.appendChild(style);

// Enhanced Terms Modal with Scroll Requirement
document.addEventListener('DOMContentLoaded', () => {
    const acceptTermsBtn = document.getElementById('acceptTermsBtn');
    const agreeTermsCheckbox = document.getElementById('agreeTerms');
    const termsModal = document.getElementById('termsModal');
    let hasScrolledToBottom = false;
    
    if (acceptTermsBtn && agreeTermsCheckbox && termsModal) {
        // Initially disable the accept button
        acceptTermsBtn.disabled = true;
        acceptTermsBtn.innerHTML = '<i class="bi bi-arrow-down me-2"></i>Please scroll to read all terms';
        
        // Handle scroll detection in modal body
        const modalBody = termsModal.querySelector('.modal-body');
        if (modalBody) {
            modalBody.addEventListener('scroll', function() {
                // Check if user has scrolled to the bottom (with small tolerance)
                const scrollTop = this.scrollTop;
                const scrollHeight = this.scrollHeight;
                const clientHeight = this.clientHeight;
                const scrolledToBottom = scrollTop + clientHeight >= scrollHeight - 10;
                
                if (scrolledToBottom && !hasScrolledToBottom) {
                    hasScrolledToBottom = true;
                    
                    // Enable accept button
                    acceptTermsBtn.disabled = false;
                    acceptTermsBtn.innerHTML = '<i class="bi bi-check-circle me-2"></i>I Accept';
                    acceptTermsBtn.classList.remove('btn-secondary');
                    acceptTermsBtn.classList.add('btn-primary');
                    
                    // Success vibration
                    triggerMobileVibration('success');
                    
                    // Show success notification
                    showNotifier('Thank you for reading the terms! You can now accept them.', 'success');
                    
                    // Add visual feedback - highlight the button briefly
                    acceptTermsBtn.classList.add('pulse-field');
                    setTimeout(() => {
                        acceptTermsBtn.classList.remove('pulse-field');
                    }, 1000);
                }
            });
        }
        
        // Handle Accept Terms button click
        acceptTermsBtn.addEventListener('click', () => {
            if (!hasScrolledToBottom) {
                showNotifier('Please scroll through and read all terms and conditions first.', 'error');
                triggerMobileVibration('error');
                return;
            }
            
            agreeTermsCheckbox.checked = true;
            agreeTermsCheckbox.classList.remove('missing-field');
            
            // Success vibration
            triggerMobileVibration('success');
            
            // Show success notification
            showNotifier('Terms and conditions accepted successfully!', 'success');
            
            // Close the modal properly using Bootstrap
            if (window.bootstrap && window.bootstrap.Modal) {
                const modalInstance = bootstrap.Modal.getInstance(termsModal);
                if (modalInstance) {
                    modalInstance.hide();
                    console.log('✅ Terms modal closed after acceptance');
                }
            }
        });
    }
    
    // Handle modal show event - Reset state when modal opens
    if (termsModal) {
        termsModal.addEventListener('show.bs.modal', () => {
            // Reset state when modal opens
            hasScrolledToBottom = false;
            if (acceptTermsBtn) {
                acceptTermsBtn.disabled = true;
                acceptTermsBtn.innerHTML = '<i class="bi bi-arrow-down me-2"></i>Please scroll to read all terms';
                acceptTermsBtn.classList.remove('btn-primary');
                acceptTermsBtn.classList.add('btn-secondary');
            }
            
            // Reset scroll position to top
            const modalBody = termsModal.querySelector('.modal-body');
            if (modalBody) {
                modalBody.scrollTop = 0;
            }
        });
        
        termsModal.addEventListener('shown.bs.modal', () => {
            // Light vibration when modal opens
            triggerMobileVibration('default');
            
            // Show instruction notification
            showNotifier('Please scroll down to read all terms and conditions.', 'warning');
        });
        
        termsModal.addEventListener('hidden.bs.modal', () => {
            // Check if terms were accepted
            if (agreeTermsCheckbox && agreeTermsCheckbox.checked) {
                // Focus on submit button if terms are accepted
                const submitBtn = document.querySelector('button[name="register"]');
                if (submitBtn) {
                    submitBtn.focus();
                }
            }
            
            // Clean up any leftover backdrop elements (fixes overlay bug)
            const backdrops = document.querySelectorAll('.modal-backdrop');
            backdrops.forEach(backdrop => {
                backdrop.remove();
                console.log('🧹 Cleaned up modal backdrop');
            });
            
            // Ensure body doesn't have modal-open class stuck
            document.body.classList.remove('modal-open');
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
        });
    }
    
    // Prevent checking terms checkbox directly without reading modal
    if (agreeTermsCheckbox) {
        agreeTermsCheckbox.addEventListener('click', function(e) {
            if (!hasScrolledToBottom) {
                e.preventDefault();
                showNotifier('Please read the terms and conditions by clicking the link first.', 'error');
                triggerMobileVibration('error');
                
                // Open the modal to force reading
                const modal = new bootstrap.Modal(termsModal);
                modal.show();
            }
        });
    }
});

// Enhanced function to show terms modal programmatically
function showTermsModal() {
    const termsModal = document.getElementById('termsModal');
    if (termsModal) {
        const modal = new bootstrap.Modal(termsModal);
        modal.show();
    }
}

// ❌ REMOVED: Backup event listener for nextStep7Btn
// The nextStep function is defined in inline script (student_register.php) which loads AFTER this file
// Trying to attach event listeners here before inline script loads causes "nextStep is not defined" errors
// The button uses onclick="nextStep()" in HTML, which works once the inline script defines window.nextStep

// Debug functions for testing (can be called from browser console)
window.debugRegistration = {
    forceEnableNextStep: function() {
        const btn = document.getElementById('nextStep7Btn');
        if (btn) {
            btn.disabled = false;
            btn.classList.add('btn-success');
            btn.textContent = 'Continue to Next Step';
            otpVerified = true;
            console.log('Forced nextStep7Btn enabled');
        }
    },
    testNextStep: function() {
        console.log('Testing nextStep function...');
        console.log('Current step:', currentStep);
        console.log('OTP verified:', otpVerified);
        // Check if nextStep exists before calling
        if (typeof window.nextStep === 'function') {
            window.nextStep();
        } else {
            console.error('nextStep function not yet defined');
        }
    },
    checkButton: function() {
        const btn = document.getElementById('nextStep7Btn');
        console.log('Button element:', btn);
        console.log('Button disabled:', btn ? btn.disabled : 'N/A');
        console.log('Button onclick:', btn ? btn.onclick : 'N/A');
        console.log('window.nextStep type:', typeof window.nextStep);
    }
};

// ---- NAVIGATION FUNCTIONS FOR REFRESH/RESTART ----

function startAgain() {
    if (confirm('Are you sure you want to start the registration process again? All entered data will be lost.')) {
        // Clear all form data
        document.querySelectorAll('input, select, textarea').forEach(el => {
            if (el.type === 'checkbox' || el.type === 'radio') {
                el.checked = false;
            } else {
                el.value = '';
            }
        });
        
        // Clear all upload previews
        document.querySelectorAll('[id*="Preview"]').forEach(el => {
            el.classList.add('d-none');
        });
        
        // Reset all state variables
        currentStep = 1;
    setOtpVerifiedState(false);
        documentVerified = false;
        filenameValid = false;
        registrationInProgress = false;
        hasUploadedFiles = false;
        hasVerifiedOTP = false;
        formSubmitted = false;
        
        // Reset all button states
        document.querySelectorAll('[id*="nextStep"]').forEach(btn => {
            btn.disabled = false;
            btn.classList.remove('btn-success');
            btn.classList.add('btn-primary');
            btn.innerHTML = 'Next';
        });
        
        // Reload the page to completely reset
        window.location.reload();
    }
}

function returnToPrevious() {
    if (currentStep > 1) {
        showStep(currentStep - 1);
    } else {
        startAgain();
    }
}

// Handle page refresh/navigation with enhanced protection
window.addEventListener('beforeunload', function(e) {
    if (registrationInProgress && !formSubmitted) {
        const message = 'You have unsaved registration progress. Are you sure you want to leave?';
        e.preventDefault();
        e.returnValue = message;
        return message;
    }
});

// Make additional functions globally available for onclick handlers
window.startAgain = startAgain;
window.returnToPrevious = returnToPrevious;
console.log('OTP verified:', otpVerified);
console.log('Current step:', currentStep);
