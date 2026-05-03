/**
 * Real-Time Distribution Status Monitor
 * Polls for distribution changes and shows notifications
 */

class DistributionMonitor {
    constructor() {
        this.pollInterval = 60000; // Check every 60 seconds (1 minute)
        this.lastStatus = null;
        this.lastTimestamp = null;
        this.intervalId = null;
        this.apiUrl = '../../api/distribution_status.php';
    }

    start() {
        // Initial check
        this.checkStatus();
        
        // Set up polling
        this.intervalId = setInterval(() => {
            this.checkStatus();
        }, this.pollInterval);
        
        console.log('[DistributionMonitor] Started polling');
    }

    stop() {
        if (this.intervalId) {
            clearInterval(this.intervalId);
            this.intervalId = null;
            console.log('[DistributionMonitor] Stopped polling');
        }
    }

    async checkStatus() {
        try {
            const response = await fetch(this.apiUrl, {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (!response.ok) {
                console.error('[DistributionMonitor] API error:', response.status);
                return;
            }

            const data = await response.json();

            if (!data.success) {
                console.error('[DistributionMonitor] API returned error');
                return;
            }

            // Check if status changed
            if (this.lastStatus && this.lastStatus !== data.status) {
                this.handleStatusChange(this.lastStatus, data.status, data);
            }

            // Update sidebar visibility
            this.updateSidebarVisibility(data);

            // Update deadline countdown if exists
            this.updateDeadlineCountdown(data);

            // Store current status
            this.lastStatus = data.status;
            this.lastTimestamp = data.timestamp;

        } catch (error) {
            console.error('[DistributionMonitor] Error:', error);
        }
    }

    handleStatusChange(oldStatus, newStatus, data) {
        console.log(`[DistributionMonitor] Status changed: ${oldStatus} -> ${newStatus}`);

        // Distribution opened
        if (oldStatus === 'inactive' && (newStatus === 'preparing' || newStatus === 'active')) {
            this.showNotification('distribution-opened', {
                title: '📢 New Distribution Started!',
                message: `Distribution for ${data.academic_year} ${data.semester} is now open. Upload your documents by ${this.formatDate(data.deadline)}.`,
                type: 'success',
                action: {
                    text: 'Upload Documents',
                    url: '../../modules/student/upload_document.php'
                }
            });

            // Add pulsing effect to upload documents link
            this.highlightUploadLink();
        }

        // Distribution closed
        if ((oldStatus === 'preparing' || oldStatus === 'active') && newStatus === 'inactive') {
            this.showNotification('distribution-closed', {
                title: '🔒 Distribution Closed',
                message: 'The current distribution cycle has been completed. Thank you for your participation!',
                type: 'info'
            });
        }
    }

    updateSidebarVisibility(data) {
        const uploadLink = document.querySelector('a[href*="upload_document.php"]');
        if (!uploadLink) return;

        const parentLi = uploadLink.closest('li');
        if (!parentLi) return;

        // Show/hide based on distribution status
        if (data.status === 'preparing' || data.status === 'active') {
            parentLi.style.display = '';
            
            // Add badge if documents not submitted
            if (!data.documents_submitted && !uploadLink.querySelector('.notification-badge')) {
                const badge = document.createElement('span');
                badge.className = 'notification-badge pulse';
                badge.textContent = 'NEW';
                uploadLink.appendChild(badge);
            }
        } else {
            // Hide upload link when distribution is inactive
            parentLi.style.display = 'none';
        }
    }

    updateDeadlineCountdown(data) {
        const countdownElement = document.getElementById('deadline-countdown');
        if (!countdownElement || !data.time_remaining) return;

        countdownElement.textContent = data.time_remaining.formatted;
        
        // Add urgency class if less than 2 days
        if (data.time_remaining.days < 2) {
            countdownElement.classList.add('urgent');
        }
    }

    showNotification(id, options) {
        // Prevent duplicate notifications
        if (document.getElementById(`notification-${id}`)) {
            return;
        }

        const notification = document.createElement('div');
        notification.id = `notification-${id}`;
        notification.className = `toast-notification toast-${options.type || 'info'} show`;
        
        notification.innerHTML = `
            <div class="toast-header">
                <strong>${options.title}</strong>
                <button type="button" class="btn-close" onclick="this.closest('.toast-notification').remove()"></button>
            </div>
            <div class="toast-body">
                ${options.message}
                ${options.action ? `
                    <div class="mt-2">
                        <a href="${options.action.url}" class="btn btn-sm btn-primary">
                            ${options.action.text}
                        </a>
                    </div>
                ` : ''}
            </div>
        `;

        // Add to page
        let container = document.getElementById('notification-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'notification-container';
            container.style.cssText = 'position: fixed; top: 80px; right: 20px; z-index: 9999; max-width: 400px;';
            document.body.appendChild(container);
        }

        container.appendChild(notification);

        // Auto-remove after 15 seconds
        setTimeout(() => {
            notification.classList.remove('show');
            setTimeout(() => notification.remove(), 300);
        }, 15000);

        // Play notification sound (optional)
        this.playNotificationSound();
    }

    highlightUploadLink() {
        const uploadLink = document.querySelector('a[href*="upload_document.php"]');
        if (!uploadLink) return;

        uploadLink.classList.add('highlight-pulse');
        
        // Remove after 10 seconds
        setTimeout(() => {
            uploadLink.classList.remove('highlight-pulse');
        }, 10000);
    }

    playNotificationSound() {
        // Optional: Add subtle notification sound
        // const audio = new Audio('../../assets/sounds/notification.mp3');
        // audio.volume = 0.3;
        // audio.play().catch(e => console.log('Sound play prevented'));
    }

    formatDate(dateString) {
        if (!dateString) return 'TBD';
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', { 
            month: 'long', 
            day: 'numeric', 
            year: 'numeric' 
        });
    }
}

// Auto-start monitoring when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        window.distributionMonitor = new DistributionMonitor();
        window.distributionMonitor.start();
    });
} else {
    window.distributionMonitor = new DistributionMonitor();
    window.distributionMonitor.start();
}

// Clean up on page unload
window.addEventListener('beforeunload', () => {
    if (window.distributionMonitor) {
        window.distributionMonitor.stop();
    }
});
