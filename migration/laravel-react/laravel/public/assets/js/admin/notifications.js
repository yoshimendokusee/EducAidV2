document.addEventListener('DOMContentLoaded', function () {
  const unreadCountSpan = document.getElementById('unread-count');
  const markAllReadBtn = document.getElementById('mark-all-read');
  const notificationList = document.querySelector('.notifications-list');
  let lastDeleted = null;
  let undoTimeout = null;

  function updateUnreadCount() {
    // Get count from API
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
        unreadCountSpan.textContent = data.count;
        unreadCountSpan.style.display = data.count > 0 ? 'inline-block' : 'none';
      }
    })
    .catch(error => console.error('Error updating count:', error));
  }

  function markAllAsRead() {
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
        // Reload the page to reflect changes
        window.location.reload();
      } else {
        console.error('Failed to mark all as read:', data.message);
      }
    })
    .catch(error => console.error('Error marking all as read:', error));
  }

  function markAsRead(notificationId) {
    if (!notificationId) return;
    
    fetch('notifications_api.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
      },
      body: `action=mark_read&notification_id=${notificationId}`
    })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        // Update the UI immediately
        const card = document.querySelector(`[data-notification-id="${notificationId}"]`);
        if (card) {
          card.classList.remove('unread');
          card.classList.add('read');
          
          // Update the button
          const readBtn = card.querySelector('.mark-read-btn');
          if (readBtn) {
            readBtn.classList.remove('bi-envelope', 'mark-read-btn');
            readBtn.classList.add('bi-envelope-open', 'text-success');
            readBtn.title = 'Already Read';
            readBtn.removeEventListener('click', readBtn.clickHandler);
          }
        }
        updateUnreadCount();
      } else {
        console.error('Failed to mark as read:', data.message);
      }
    })
    .catch(error => console.error('Error marking as read:', error));
  }

  function showUndoSnackbar() {
    const snackbar = document.getElementById('undo-snackbar');
    snackbar.innerHTML = `Notification deleted. <button id="undo-btn" class="btn btn-sm btn-light ms-2">Undo</button>`;
    snackbar.classList.add('show');

    document.getElementById('undo-btn').onclick = function () {
      if (lastDeleted) {
        lastDeleted.classList.remove('fade-out');
        notificationList.appendChild(lastDeleted);
        lastDeleted = null;
      }
      clearTimeout(undoTimeout);
      snackbar.classList.remove('show');
      updateUnreadCount();
    };

    undoTimeout = setTimeout(() => {
      if (lastDeleted) lastDeleted.remove();
      lastDeleted = null;
      snackbar.classList.remove('show');
      updateUnreadCount();
    }, 5000);
  }

  function deleteNotification(button) {
    const card = button.closest('.notification-card');
    if (card) {
      lastDeleted = card;
      card.classList.add('fade-out');
      showUndoSnackbar();
    }
  }

  // Attach delete events
  document.querySelectorAll('.delete-btn').forEach(button => {
    button.addEventListener('click', () => {
      deleteNotification(button);
    });
  });

  // Attach mark-as-read events
  document.querySelectorAll('.mark-read-btn').forEach(button => {
    const clickHandler = () => {
      const notificationId = button.getAttribute('data-notification-id');
      markAsRead(notificationId);
    };
    button.clickHandler = clickHandler;
    button.addEventListener('click', clickHandler);
  });

  // Mark all as read
  markAllReadBtn?.addEventListener('click', markAllAsRead);

  // Initialize count
  updateUnreadCount();
});
