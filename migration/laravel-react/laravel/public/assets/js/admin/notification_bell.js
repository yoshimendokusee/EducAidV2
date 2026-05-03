// Admin notification bell functionality
document.addEventListener('DOMContentLoaded', function() {
    const notificationBell = document.querySelector('.admin-icon-btn[title="Notifications"]');
    const notificationBadge = notificationBell?.querySelector('.badge');
    
    // Track if we're currently updating to prevent conflicts
    let isUpdating = false;
    
    // Update notification count
    function updateNotificationCount(force = false) {
        if (isUpdating && !force) return;
        isUpdating = true;
        
        fetch('notifications_api.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'action=get_count'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                updateBadgeDisplay(data.count);
            }
        })
        .catch(error => {
            console.error('Error updating notification count:', error);
        })
        .finally(() => {
            isUpdating = false;
        });
    }
    
    // Centralized function to update badge display
    function updateBadgeDisplay(count) {
        if (count > 0) {
            if (notificationBadge) {
                // Only update if the count actually changed
                if (notificationBadge.textContent !== count.toString()) {
                    notificationBadge.textContent = count;
                }
                notificationBadge.style.display = 'inline';
            } else {
                // Create badge if it doesn't exist
                const badge = document.createElement('span');
                badge.className = 'badge rounded-pill bg-danger';
                badge.textContent = count;
                badge.style.fontSize = '.55rem';
                badge.style.position = 'absolute';
                badge.style.top = '-6px';
                badge.style.right = '-6px';
                notificationBell.appendChild(badge);
            }
        } else {
            if (notificationBadge) {
                notificationBadge.style.display = 'none';
            }
        }
    }
    
    // Mark all notifications as read when dropdown is opened
    if (notificationBell) {
        notificationBell.addEventListener('click', function() {
            // Small delay to ensure dropdown opens first
            setTimeout(() => {
                if (notificationBadge && notificationBadge.style.display !== 'none') {
                    fetch('notifications_api.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'action=mark_all_read'
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            updateNotificationCount(true); // Force update after marking as read
                        }
                    })
                    .catch(error => {
                        console.error('Error marking notifications as read:', error);
                    });
                }
            }, 100);
        });
    }
    
    // Don't update immediately on page load to avoid conflicts with server-rendered count
    // Only start auto-refresh after initial delay
    let pollInterval;
    
    function startPolling() {
        // Clear any existing interval
        if (pollInterval) clearInterval(pollInterval);
        
        // Auto-refresh notification count every 60 seconds (increased from 30)
        pollInterval = setInterval(() => {
            // Only poll if page is visible
            if (!document.hidden) {
                updateNotificationCount();
            }
        }, 60000);
    }
    
    setTimeout(() => {
        updateNotificationCount();
        startPolling();
    }, 2000); // Wait 2 seconds before first JavaScript update
    
    // Pause polling when page is hidden, resume when visible
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            updateNotificationCount(); // Update immediately when page becomes visible
            startPolling();
        } else if (pollInterval) {
            clearInterval(pollInterval);
        }
    });
});