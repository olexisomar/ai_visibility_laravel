/**
 * Notifications System
 */

let notificationsOpen = false;

/**
 * Load notifications
 */
async function loadNotifications() {
    try {
        const res = await fetch(`${API_BASE}/notifications`, {
            headers: { 'X-API-Key': API_KEY }
        });
        
        const data = await res.json();
        
        // Update badge
        updateNotificationBadge(data.unread_count || 0);
        
        // Render notifications
        renderNotifications(data.notifications || []);
        
    } catch (e) {
        console.error('Failed to load notifications:', e);
    }
}

/**
 * Update notification badge
 */
function updateNotificationBadge(count) {
    const badge = document.getElementById('notificationBadge');
    if (!badge) return;
    
    if (count > 0) {
        badge.textContent = count > 99 ? '99+' : count;
        badge.classList.remove('hidden');
    } else {
        badge.classList.add('hidden');
    }
}

/**
 * Render notifications list
 */
function renderNotifications(notifications) {
    const list = document.getElementById('notificationList');
    if (!list) return;
    
    if (notifications.length === 0) {
        list.innerHTML = `
            <div style="padding: 40px; text-align: center; color: #9ca3af;">
                <div style="font-size: 48px; margin-bottom: 12px;">🔔</div>
                <div>No notifications yet</div>
            </div>
        `;
        return;
    }
    
    list.innerHTML = notifications.map(n => {
        const isUnread = !n.is_read;
        const bgColor = isUnread ? '#f0f9ff' : 'white';
        const icon = getNotificationIcon(n.type);
        const time = formatNotificationTime(n.created_at);
        
        return `
            <div class="notification-item" data-id="${n.id}" style="
                padding: 16px;
                border-bottom: 1px solid #e5e7eb;
                cursor: pointer;
                background: ${bgColor};
                transition: background 0.2s;
            " onmouseover="this.style.background='#f9fafb'" 
               onmouseout="this.style.background='${bgColor}'"
               onclick="markNotificationRead(${n.id})">
                <div style="display: flex; gap: 12px;">
                    <div style="font-size: 24px;">${icon}</div>
                    <div style="flex: 1;">
                        <div style="font-weight: ${isUnread ? '600' : '400'}; margin-bottom: 4px;">
                            ${escapeHtml(n.title)}
                        </div>
                        <div style="font-size: 13px; color: #6b7280; white-space: pre-wrap;">
                            ${escapeHtml(n.message)}
                        </div>
                        <div style="font-size: 11px; color: #9ca3af; margin-top: 8px;">
                            ${time}
                        </div>
                    </div>
                    ${isUnread ? '<div style="width: 8px; height: 8px; background: #3b82f6; border-radius: 50%;"></div>' : ''}
                </div>
            </div>
        `;
    }).join('');
}

/**
 * Get icon for notification type
 */
function getNotificationIcon(type) {
    const icons = {
        'run_completed': '✅',
        'brand_mention': '💬',
        'negative_sentiment': '⚠️',
        'daily_summary': '📊',
    };
    return icons[type] || '🔔';
}

/**
 * Format notification time
 */
function formatNotificationTime(timestamp) {
    if (!timestamp) return '';
    
    try {
        const date = new Date(timestamp.replace(' ', 'T'));
        const now = new Date();
        const diff = now - date;
        
        // Less than 1 minute
        if (diff < 60000) {
            return 'Just now';
        }
        
        // Less than 1 hour
        if (diff < 3600000) {
            const minutes = Math.floor(diff / 60000);
            return `${minutes} minute${minutes > 1 ? 's' : ''} ago`;
        }
        
        // Less than 24 hours
        if (diff < 86400000) {
            const hours = Math.floor(diff / 3600000);
            return `${hours} hour${hours > 1 ? 's' : ''} ago`;
        }
        
        // Less than 7 days
        if (diff < 604800000) {
            const days = Math.floor(diff / 86400000);
            return `${days} day${days > 1 ? 's' : ''} ago`;
        }
        
        // Format date
        return date.toLocaleDateString();
    } catch (e) {
        return timestamp;
    }
}

/**
 * Mark notification as read
 */
async function markNotificationRead(id) {
    try {
        await fetch(`${API_BASE}/notifications/${id}/read`, {
            method: 'POST',
            headers: { 'X-API-Key': API_KEY }
        });
        
        // Reload notifications
        await loadNotifications();
        
    } catch (e) {
        console.error('Failed to mark as read:', e);
    }
}

/**
 * Mark all notifications as read
 */
async function markAllNotificationsRead() {
    try {
        await fetch(`${API_BASE}/notifications/read-all`, {
            method: 'POST',
            headers: { 'X-API-Key': API_KEY }
        });
        
        // Reload notifications
        await loadNotifications();
        
    } catch (e) {
        console.error('Failed to mark all as read:', e);
    }
}

/**
 * Toggle notification dropdown
 */
function toggleNotifications() {
    const dropdown = document.getElementById('notificationDropdown');
    if (!dropdown) return;
    
    notificationsOpen = !notificationsOpen;
    
    if (notificationsOpen) {
        dropdown.classList.remove('hidden');
        loadNotifications();
    } else {
        dropdown.classList.add('hidden');
    }
}

/**
 * Initialize notifications
 */
function initNotifications() {
    // Bell icon click
    document.getElementById('notificationBell')?.addEventListener('click', (e) => {
        e.stopPropagation();
        toggleNotifications();
    });
    
    // Mark all read button
    document.getElementById('markAllRead')?.addEventListener('click', (e) => {
        e.stopPropagation();
        markAllNotificationsRead();
    });
    
    // Close dropdown when clicking outside
    document.addEventListener('click', (e) => {
        const dropdown = document.getElementById('notificationDropdown');
        const bell = document.getElementById('notificationBell');
        
        if (notificationsOpen && 
            !dropdown.contains(e.target) && 
            !bell.contains(e.target)) {
            notificationsOpen = false;
            dropdown.classList.add('hidden');
        }
    });
    
    // Load initial count
    loadNotifications();
    
    // Poll for new notifications every 30 seconds
    setInterval(loadNotifications, 30000);
}

// Initialize when page loads
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initNotifications);
} else {
    initNotifications();
}