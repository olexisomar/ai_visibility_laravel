// small helpers
function escapeHtml(s){
  return (s||'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
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
            headers: { 
                'X-API-Key': API_KEY,
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
            }
        });
        
        if (!res.ok) {
            throw new Error(`HTTP ${res.status}`);
        }
        
        const data = await res.json();
        
        // ALWAYS update badge
        updateNotificationBadge(data.unread_count || 0);
        
        // ALWAYS render notifications when dropdown is open
        if (notificationsOpen) {
            renderNotifications(data.notifications || []);
        }
        
        console.log(`✅ Notifications loaded: ${data.unread_count} unread`);
        
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
    
    if (count >= 0) {
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
            <div class="notification-item" data-notification-id="${n.id}" style="
                padding: 16px;
                border-bottom: 1px solid #e5e7eb;
                background: ${bgColor};
                transition: background 0.2s;
                display: flex;
                gap: 12px;
                align-items: start;
            " onmouseover="this.style.background='#f9fafb'" 
               onmouseout="this.style.background='${bgColor}'">
                <div style="font-size: 24px;">${icon}</div>
                <div style="flex: 1; min-width: 0; cursor: pointer;" onclick="markNotificationRead(${n.id})">
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
                ${isUnread ? '<div style="width: 8px; height: 8px; background: #3b82f6; border-radius: 50%; margin-top: 4px;"></div>' : ''}
                <button onclick="event.stopPropagation(); deleteNotification(${n.id});" style="
                    background: none;
                    border: none;
                    color: #9ca3af;
                    cursor: pointer;
                    padding: 4px 8px;
                    font-size: 18px;
                    line-height: 1;
                    transition: color 0.2s;
                    flex-shrink: 0;
                " onmouseover="this.style.color='#ef4444'" onmouseout="this.style.color='#9ca3af'" title="Delete notification">
                    ×
                </button>
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
            headers: { 
                'X-API-Key': API_KEY,
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
            }
        });
        
        // Reload notifications to update count
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
        const res = await fetch(`${API_BASE}/notifications/read-all`, {
            method: 'POST',
            headers: { 
                'X-API-Key': API_KEY,
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
            }
        });
        
        if (!res.ok) {
            throw new Error(`HTTP ${res.status}`);
        }
        
        // FORCE badge to 0 immediately
        updateNotificationBadge(0);
        
        // Reload notifications to confirm
        await loadNotifications();
        
        console.log('✅ Marked all as read');
        
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
    
    // Poll for new notifications every 5 minutes
    setInterval(loadNotifications, 60000);
}

/**
 * Refresh notifications
 */
async function refreshNotifications() {
    console.log('🔔 Auto-refreshing notifications...');
    
    // Call the global refresh function if it exists
    if (typeof window.refreshNotifications === 'function') {
        window.refreshNotifications();
    } else if (typeof loadNotifications === 'function') {
        await loadNotifications();
    }
}
/**
 * Manually refresh notifications (can be called from other scripts)
 */
window.refreshNotifications = function() {
    loadNotifications();
};

// Delete individual notification
async function deleteNotification(notificationId) {
    try {
        const response = await fetch(`/ai-visibility-company/public/api/admin/notifications/${notificationId}`, {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            }
        });
        
        const data = await response.json();
        
        if (data.success) {
            // Remove from UI
            const notificationElement = document.querySelector(`[data-notification-id="${notificationId}"]`);
            if (notificationElement) {
                notificationElement.remove();
            }
            
            // Reload notifications
            loadNotifications();
        }
    } catch (error) {
        console.error('Failed to delete notification:', error);
    }
}

// Clear all notifications
async function clearAllNotifications() {
    if (!confirm('Clear all notifications? This cannot be undone.')) {
        return;
    }
    
    try {
        const response = await fetch(`${API_BASE}/notifications/clear-all`, {
            method: 'POST',
            headers: {
                'X-API-Key': API_KEY,
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            }
        });
        
        const data = await response.json();
        
        if (data.success) {
            // Update badge immediately
            updateNotificationBadge(0);
            
            // Reload notifications
            await loadNotifications();
        }
    } catch (error) {
        console.error('Failed to clear notifications:', error);
    }
}

// Clear read notifications only
async function clearReadNotifications() {
    if (!confirm('Clear all read notifications?')) {
        return;
    }
    
    try {
        const response = await fetch(`${API_BASE}/notifications/clear-read`, {
            method: 'POST',
            headers: {
                'X-API-Key': API_KEY,
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            }
        });
        
        const data = await response.json();
        
        if (data.success) {
            // Reload to get updated count
            await loadNotifications();
        }
    } catch (error) {
        console.error('Failed to clear read notifications:', error);
    }
}

function toggleClearMenu(event) {
    event.stopPropagation();
    const menu = document.getElementById('clearMenu');
    menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
}

// Close clear menu when clicking outside
document.addEventListener('click', function(e) {
    const clearMenu = document.getElementById('clearMenu');
    const clearMenuBtn = document.getElementById('clearMenuBtn');
    if (clearMenu && !clearMenu.contains(e.target) && e.target !== clearMenuBtn) {
        clearMenu.style.display = 'none';
    }
});

// Also close clear menu when notification dropdown closes
document.getElementById('notificationDropdown')?.addEventListener('transitionend', function() {
    if (this.classList.contains('hidden')) {
        const clearMenu = document.getElementById('clearMenu');
        if (clearMenu) clearMenu.style.display = 'none';
    }
});

// Initialize when page loads
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initNotifications);
} else {
    initNotifications();
}
