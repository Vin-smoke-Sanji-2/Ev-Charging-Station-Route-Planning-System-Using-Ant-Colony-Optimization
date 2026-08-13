import { apiFetch } from './api.js';

/**
 * Updates the unread-count badge on the sidebar's Notifications link - the
 * one thing that was missing before (the bell icon looked identical whether
 * or not anything new had actually arrived). Shared by app.js (runs once
 * per page load, on every authenticated page across all 3 portals - EV
 * Owner/Station Owner/Admin all render the same `#sidebar-notif-badge` id
 * in their own layout) and notifications-index.js (re-run immediately after
 * marking something read, so the badge doesn't sit stale until the next
 * full page navigation).
 *
 * A no-op wherever the badge element doesn't exist - guest pages
 * (login/register) load app.js too but have no sidebar at all.
 */
export async function updateNotificationBadge() {
    const badge = document.getElementById('sidebar-notif-badge');
    if (!badge) return;

    const response = await apiFetch('/api/notifications?is_read=0');
    if (!response.ok) return;

    const data = await response.json();
    const count = data.total ?? 0;

    if (count > 0) {
        badge.textContent = count > 99 ? '99+' : String(count);
        badge.classList.remove('d-none');
    } else {
        badge.classList.add('d-none');
    }
}
