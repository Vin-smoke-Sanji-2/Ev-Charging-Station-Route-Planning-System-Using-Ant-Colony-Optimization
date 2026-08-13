import { apiFetch } from '../api.js';

const ROLE_LABELS = { ev_owner: 'EV Owner', station_owner: 'Station Owner', admin: 'Admin' };

function formatDate(iso) {
    if (!iso) return '—';
    return new Date(iso).toLocaleString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function registrationItem(user) {
    return `
        <li class="list-group-item d-flex justify-content-between align-items-center">
            <div>
                <div class="fw-semibold">${user.name}</div>
                <div class="text-muted small">${formatDate(user.created_at)}</div>
            </div>
            <span class="badge bg-brand badge-status">${ROLE_LABELS[user.role] || user.role}</span>
        </li>
    `;
}

function loginHistoryItem(entry) {
    const name = entry.user ? entry.user.name : 'Deleted admin account';
    return `
        <li class="list-group-item d-flex justify-content-between align-items-center">
            <div>
                <div class="fw-semibold">${name}</div>
                <div class="text-muted small">${formatDate(entry.logged_in_at)}${entry.ip_address ? ` &middot; ${entry.ip_address}` : ''}</div>
            </div>
        </li>
    `;
}

// Read-only stats page - no admin actions here (those belong to the
// Users/Stations/EV Models/Road Graph follow-up prompts). No client-side
// role check needed beyond what portal:admin already enforces server-side
// on the page route itself - unlike Station Detail's per-resource
// ownership check, there's no ownership concept for platform-wide stats,
// just role gating, which the middleware already fully covers.
document.addEventListener('DOMContentLoaded', async () => {
    const app = document.getElementById('admin-overview-app');
    if (!app) return;

    const loading = document.getElementById('overview-loading');
    const content = document.getElementById('overview-content');

    const response = await apiFetch('/api/admin/dashboard');
    loading.classList.add('d-none');

    if (!response.ok) {
        loading.textContent = 'Unable to load dashboard stats. Please try again.';
        loading.classList.remove('d-none');
        return;
    }

    const stats = await response.json();
    content.classList.remove('d-none');

    document.getElementById('stat-total-users').textContent = stats.total_users;
    document.getElementById('stat-total-stations').textContent = stats.total_stations;
    document.getElementById('stat-total-trips').textContent = stats.total_trips;
    document.getElementById('stat-active-today').textContent = stats.active_today;
    document.getElementById('stat-pending-verifications').textContent = stats.pending_station_verifications;

    const list = document.getElementById('recent-registrations-list');
    const empty = document.getElementById('recent-registrations-empty');
    const registrations = stats.recent_registrations || [];

    if (registrations.length === 0) {
        empty.classList.remove('d-none');
        list.innerHTML = '';
    } else {
        empty.classList.add('d-none');
        list.innerHTML = registrations.map(registrationItem).join('');
    }

    const loginList = document.getElementById('admin-login-history-list');
    const loginEmpty = document.getElementById('admin-login-history-empty');
    const logins = stats.recent_admin_logins || [];

    if (logins.length === 0) {
        loginEmpty.classList.remove('d-none');
        loginList.innerHTML = '';
    } else {
        loginEmpty.classList.add('d-none');
        loginList.innerHTML = logins.map(loginHistoryItem).join('');
    }
});
