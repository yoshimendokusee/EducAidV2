/**
 * Session Timeout Warning System
 * 
 * Monitors session activity and displays warnings before automatic logout.
 * Handles both idle timeout and absolute timeout scenarios.
 * 
 * Features:
 * - Countdown timer display
 * - Modal warning before logout
 * - "Keep me logged in" button to extend session
 * - Automatic logout on timeout expiry
 * - Handles server-side session expiration
 * 
 * @package EducAid
 * @version 1.0.0
 */

class SessionTimeoutWarning {
    constructor(config) {
        this.config = {
            idleTimeoutSeconds: config.idle_timeout_minutes * 60,
            absoluteTimeoutSeconds: config.absolute_timeout_hours * 3600,
            warningThreshold: config.warning_before_logout_seconds,
            checkInterval: 5000, // Check every 5 seconds
            enabled: config.enabled || true
        };
        
        this.lastActivity = Date.now();
        this.sessionStartTime = Date.now();
        this.warningShown = false;
        this.checkIntervalId = null;
        this.countdownIntervalId = null;
        
        if (this.config.enabled) {
            this.init();
        }
    }
    
    /**
     * Initialize the session timeout system
     */
    init() {
        console.log('[Session Timeout] Initialized', this.config);
        
        // Track user activity
        this.attachActivityListeners();
        
        // Start periodic session check
        this.startSessionCheck();
        
        // Create warning modal (hidden by default)
        this.createWarningModal();
    }
    
    /**
     * Attach event listeners to track user activity
     */
    attachActivityListeners() {
        const events = ['mousedown', 'mousemove', 'keypress', 'scroll', 'touchstart', 'click'];
        
        events.forEach(event => {
            document.addEventListener(event, () => {
                this.updateActivity();
            }, { passive: true });
        });
    }
    
    /**
     * Update last activity timestamp
     */
    updateActivity() {
        this.lastActivity = Date.now();
        
        // Hide warning if user becomes active again
        if (this.warningShown) {
            this.hideWarning();
        }
    }
    
    /**
     * Start periodic session timeout checks
     */
    startSessionCheck() {
        this.checkIntervalId = setInterval(() => {
            this.checkTimeout();
        }, this.config.checkInterval);
    }
    
    /**
     * Check if session timeout is approaching or expired
     */
    checkTimeout() {
        const now = Date.now();
        const idleTime = Math.floor((now - this.lastActivity) / 1000);
        const sessionTime = Math.floor((now - this.sessionStartTime) / 1000);
        
        const timeUntilIdleTimeout = this.config.idleTimeoutSeconds - idleTime;
        const timeUntilAbsoluteTimeout = this.config.absoluteTimeoutSeconds - sessionTime;
        
        // Use the smaller of the two timeouts
        const timeUntilTimeout = Math.min(timeUntilIdleTimeout, timeUntilAbsoluteTimeout);
        const timeoutType = timeUntilIdleTimeout < timeUntilAbsoluteTimeout ? 'idle' : 'absolute';
        
        // Check if timeout has expired
        if (timeUntilTimeout <= 0) {
            this.handleTimeout(timeoutType);
            return;
        }
        
        // Show warning if approaching timeout
        if (timeUntilTimeout <= this.config.warningThreshold && !this.warningShown) {
            this.showWarning(timeUntilTimeout, timeoutType);
        }
    }
    
    /**
     * Create the warning modal HTML
     */
    createWarningModal() {
        const modal = document.createElement('div');
        modal.id = 'session-timeout-warning-modal';
        modal.className = 'session-timeout-modal toast-mode';
        modal.style.display = 'none';
        
        modal.innerHTML = `
            <div class="session-timeout-overlay"></div>
            <div class="session-timeout-content">
                <div class="session-timeout-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" fill="currentColor" viewBox="0 0 16 16">
                        <path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/>
                        <path d="M8 4a.905.905 0 0 0-.9.995l.35 3.507a.552.552 0 0 0 1.1 0l.35-3.507A.905.905 0 0 0 8 4zm.002 6a1 1 0 1 0 0 2 1 1 0 0 0 0-2z"/>
                    </svg>
                </div>
                <h3 class="session-timeout-title">Session Expiring Soon</h3>
                <p class="session-timeout-message">
                    Your session will expire in <strong id="session-timeout-countdown">--</strong> due to inactivity.
                </p>
                <div class="session-timeout-actions">
                    <button id="session-keep-active-btn" class="btn btn-primary">
                        Stay Logged In
                    </button>
                    <button id="session-logout-btn" class="btn btn-secondary">
                        Log Out Now
                    </button>
                </div>
            </div>
        `;
        
        document.body.appendChild(modal);
        
        // Attach button event listeners
        document.getElementById('session-keep-active-btn').addEventListener('click', () => {
            this.keepSessionActive();
        });
        
        document.getElementById('session-logout-btn').addEventListener('click', () => {
            this.logoutNow();
        });
    }
    
    /**
     * Show the timeout warning modal
     */
    showWarning(secondsRemaining, timeoutType) {
        this.warningShown = true;
        const modal = document.getElementById('session-timeout-warning-modal');
        const message = document.querySelector('.session-timeout-message');
        
        // Update message based on timeout type
        if (timeoutType === 'idle') {
            message.innerHTML = `Your session will expire in <strong id="session-timeout-countdown">--</strong> due to inactivity.`;
        } else {
            message.innerHTML = `Your session will expire in <strong id="session-timeout-countdown">--</strong> due to maximum session duration.`;
        }
        
        modal.style.display = 'block';
        
        // Start countdown
        this.startCountdown(secondsRemaining);
        
        console.log(`[Session Timeout] Warning shown - ${timeoutType} timeout in ${secondsRemaining}s`);
    }
    
    /**
     * Hide the timeout warning modal
     */
    hideWarning() {
        this.warningShown = false;
        const modal = document.getElementById('session-timeout-warning-modal');
        modal.style.display = 'none';
        
        // Stop countdown
        if (this.countdownIntervalId) {
            clearInterval(this.countdownIntervalId);
            this.countdownIntervalId = null;
        }
        
        console.log('[Session Timeout] Warning hidden');
    }
    
    /**
     * Start countdown timer display
     */
    startCountdown(seconds) {
        let remaining = seconds;
        const countdownElement = document.getElementById('session-timeout-countdown');
        
        const updateCountdown = () => {
            const minutes = Math.floor(remaining / 60);
            const secs = remaining % 60;
            countdownElement.textContent = `${minutes}:${secs.toString().padStart(2, '0')}`;
            
            remaining--;
            
            if (remaining < 0) {
                clearInterval(this.countdownIntervalId);
            }
        };
        
        updateCountdown(); // Initial update
        this.countdownIntervalId = setInterval(updateCountdown, 1000);
    }
    
    /**
     * Keep session active (user clicked "Stay Logged In")
     */
    keepSessionActive() {
        console.log('[Session Timeout] User chose to stay logged in');
        
        // Update activity timestamp
        this.updateActivity();
        
        // Make AJAX request to server to refresh session
        fetch(window.location.href, {
            method: 'HEAD',
            credentials: 'same-origin'
        }).then(() => {
            console.log('[Session Timeout] Session refreshed');
            this.hideWarning();
        }).catch(err => {
            console.error('[Session Timeout] Failed to refresh session:', err);
        });
    }
    
    /**
     * Log out immediately (user clicked "Log Out Now")
     */
    logoutNow() {
        console.log('[Session Timeout] User chose to log out');
        window.location.href = '/EducAid/website/logout.php';
    }
    
    /**
     * Handle session timeout expiry
     */
    handleTimeout(timeoutType) {
        console.log(`[Session Timeout] Session expired - ${timeoutType} timeout`);
        
        // Stop all intervals
        if (this.checkIntervalId) clearInterval(this.checkIntervalId);
        if (this.countdownIntervalId) clearInterval(this.countdownIntervalId);
        
        // Redirect to login with timeout message
        window.location.href = `/EducAid/website/unified_login.php?timeout=${timeoutType}`;
    }
    
    /**
     * Destroy the session timeout system
     */
    destroy() {
        if (this.checkIntervalId) {
            clearInterval(this.checkIntervalId);
        }
        if (this.countdownIntervalId) {
            clearInterval(this.countdownIntervalId);
        }
        
        const modal = document.getElementById('session-timeout-warning-modal');
        if (modal) {
            modal.remove();
        }
        
        console.log('[Session Timeout] Destroyed');
    }
}

// Auto-initialize if config is provided
if (typeof window.sessionTimeoutConfig !== 'undefined') {
    window.sessionTimeoutWarning = new SessionTimeoutWarning(window.sessionTimeoutConfig);
}
