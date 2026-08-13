import { apiFetch } from '../api.js';
import { debounce } from '../debounce.js';
import { statusBadge, userStatusActionHtml, confirmStatusChange, applyUserStatusChange } from './admin-user-status-actions.js';

const ROLE_LABELS = { ev_owner: 'EV Owner', station_owner: 'Station Owner' };

let roleFilter = '';
let statusFilter = '';
let searchQuery = '';
let loadSeq = 0;

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

function buildUrl() {
    const params = new URLSearchParams();
    if (roleFilter) params.set('role', roleFilter);
    // The status filter only ever makes sense for station owners - EV
    // owners have no status field at all, so it's dropped (and hidden in
    // the UI) whenever the role filter isn't station_owner.
    if (roleFilter === 'station_owner' && statusFilter) params.set('status', statusFilter);
    if (searchQuery) params.set('name', searchQuery);
    const qs = params.toString();
    return qs ? `/api/admin/users?${qs}` : '/api/admin/users';
}

function userRow(user) {
    const isStationOwner = user.role === 'station_owner';

    return `
        <tr>
            <td>${user.name}</td>
            <td class="text-muted">${user.email}</td>
            <td><span class="badge bg-brand">${ROLE_LABELS[user.role] ?? user.role}</span></td>
            <td class="text-muted small">${formatDate(user.created_at)}</td>
            <td>${isStationOwner ? statusBadge(user.status) : '—'}</td>
            <td>${isStationOwner ? userStatusActionHtml(user) : ''}</td>
        </tr>
    `;
}

function setPageLinkState(itemEl, linkEl, url) {
    if (url) {
        itemEl.classList.remove('disabled');
        linkEl.dataset.url = url;
    } else {
        itemEl.classList.add('disabled');
        delete linkEl.dataset.url;
    }
}

async function loadTotalUsers(url) {
    const loading = document.getElementById('total-users-loading');
    const empty = document.getElementById('total-users-empty');
    const errorBox = document.getElementById('total-users-error');
    const tableWrap = document.getElementById('total-users-table-wrap');
    const tbody = document.getElementById('total-users-tbody');
    const pagination = document.getElementById('total-users-pagination');
    const prevItem = document.getElementById('total-users-prev-item');
    const nextItem = document.getElementById('total-users-next-item');
    const prevLink = document.getElementById('total-users-prev');
    const nextLink = document.getElementById('total-users-next');

    const targetUrl = url ?? buildUrl();
    const seq = ++loadSeq;

    loading.classList.remove('d-none');
    empty.classList.add('d-none');
    errorBox.classList.add('d-none');
    tableWrap.classList.add('d-none');
    pagination.classList.add('d-none');

    const response = await apiFetch(targetUrl);
    if (seq !== loadSeq) return; // superseded by a newer filter/search change

    loading.classList.add('d-none');

    if (!response.ok) {
        errorBox.textContent = 'Unable to load users. Please try again.';
        errorBox.classList.remove('d-none');
        return;
    }

    const data = await response.json();

    tbody.dataset.currentUrl = targetUrl;

    if (data.total === 0) {
        empty.classList.remove('d-none');
        return;
    }

    tbody.innerHTML = data.data.map(userRow).join('');
    tableWrap.classList.remove('d-none');

    if (data.last_page > 1) {
        pagination.classList.remove('d-none');
        setPageLinkState(prevItem, prevLink, data.prev_page_url);
        setPageLinkState(nextItem, nextLink, data.next_page_url);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const tbody = document.getElementById('total-users-tbody');
    if (!tbody) return;

    const errorBox = document.getElementById('total-users-error');
    const searchInput = document.getElementById('total-users-search');
    const roleSelect = document.getElementById('total-users-role-filter');
    const statusSelect = document.getElementById('total-users-status-filter');
    const statusWrap = document.getElementById('total-users-status-filter-wrap');
    const prevItem = document.getElementById('total-users-prev-item');
    const nextItem = document.getElementById('total-users-next-item');
    const prevLink = document.getElementById('total-users-prev');
    const nextLink = document.getElementById('total-users-next');

    const debouncedSearch = debounce(() => {
        searchQuery = searchInput.value.trim();
        loadTotalUsers();
    }, 250);
    searchInput.addEventListener('input', debouncedSearch);

    roleSelect.addEventListener('change', () => {
        roleFilter = roleSelect.value;

        if (roleFilter === 'station_owner') {
            statusWrap.classList.remove('d-none');
        } else {
            statusWrap.classList.add('d-none');
            statusFilter = '';
            statusSelect.value = '';
        }

        loadTotalUsers();
    });

    statusSelect.addEventListener('change', () => {
        statusFilter = statusSelect.value;
        loadTotalUsers();
    });

    prevLink.addEventListener('click', (event) => {
        event.preventDefault();
        if (prevItem.classList.contains('disabled') || !prevLink.dataset.url) return;
        loadTotalUsers(prevLink.dataset.url);
    });

    nextLink.addEventListener('click', (event) => {
        event.preventDefault();
        if (nextItem.classList.contains('disabled') || !nextLink.dataset.url) return;
        loadTotalUsers(nextLink.dataset.url);
    });

    tbody.addEventListener('click', async (event) => {
        const btn = event.target.closest('[data-action]');
        if (!btn) return;

        const { action, userId } = btn.dataset;

        if (!confirmStatusChange(action)) return;

        errorBox.classList.add('d-none');
        btn.disabled = true;

        const response = await applyUserStatusChange(userId, action);

        if (!response.ok) {
            const data = await response.json().catch(() => null);
            errorBox.textContent = data?.message || 'Unable to update this account. Please try again.';
            errorBox.classList.remove('d-none');
            btn.disabled = false;
            return;
        }

        await loadTotalUsers(tbody.dataset.currentUrl);
    });

    loadTotalUsers();
});
