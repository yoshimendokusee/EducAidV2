document.addEventListener('DOMContentLoaded', function () {
  const unreadCountSpan = document.getElementById('unread-count');
  const markAllReadBtn = document.getElementById('mark-all-read');
  const notificationList = document.querySelector('.notifications-list');
  let lastDeleted = null;
  let undoTimeout = null;

  function post(action, extra='') {
    return fetch('notifications_api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'action=' + encodeURIComponent(action) + (extra ? '&' + extra : '')
    }).then(r=>r.json());
  }

  function updateUnreadCount() {
    post('get_count').then(data => {
      if (data.success) {
        unreadCountSpan.textContent = data.count;
        unreadCountSpan.style.display = data.count > 0 ? 'inline-block' : 'none';
      }
    });
  }

  function markAllAsRead() {
    post('mark_all_read').then(data => {
      if (data.success) {
        // quick UI update then refresh to sync pagination/filter state
        document.querySelectorAll('.notification-card.unread').forEach(card => {
          card.classList.remove('unread');
          card.classList.add('read');
          const readBtn = card.querySelector('.mark-read-btn');
          if (readBtn) {
            readBtn.classList.remove('bi-envelope','mark-read-btn');
            readBtn.classList.add('bi-envelope-open','text-success');
            readBtn.title = 'Already Read';
          }
        });
        updateUnreadCount();
      }
    });
  }

  function markAsRead(notificationId) {
    if (!notificationId) return;
    post('mark_read', 'notification_id=' + encodeURIComponent(notificationId)).then(data => {
      if (data.success) {
        const card = document.querySelector('[data-notification-id="' + notificationId + '"]');
        if (card) {
          card.classList.remove('unread');
          card.classList.add('read');
          const readBtn = card.querySelector('.mark-read-btn');
          if (readBtn) {
            readBtn.classList.remove('bi-envelope','mark-read-btn');
            readBtn.classList.add('bi-envelope-open','text-success');
            readBtn.title = 'Already Read';
          }
        }
        updateUnreadCount();
      }
    });
  }

  function showUndoSnackbar() {
    const snackbar = document.getElementById('undo-snackbar');
    if (!snackbar) return;
    snackbar.innerHTML = 'Notification removed. <button id="undo-btn" class="btn btn-sm btn-light ms-2">Undo</button>';
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

  // Attach handlers
  document.querySelectorAll('.mark-read-btn').forEach(btn => {
    btn.addEventListener('click', () => markAsRead(btn.getAttribute('data-notification-id')));
  });
  document.querySelectorAll('.delete-btn').forEach(btn => {
    btn.addEventListener('click', () => deleteNotification(btn));
  });
  markAllReadBtn?.addEventListener('click', markAllAsRead);
  updateUnreadCount();
});
