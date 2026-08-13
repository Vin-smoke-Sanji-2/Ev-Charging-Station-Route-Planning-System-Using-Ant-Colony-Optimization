import { apiFetch } from '../api.js';
import { debounce } from '../debounce.js';

const ROLE_LABELS = { ev_owner: 'EV Owner', station_owner: 'Station Owner' };

let roleFilter = '';
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
    if (searchQuery) params.set('name', searchQuery);
    const qs = params.toString();
    return qs ? `/api/admin/active-today?${qs}` : '/api/admin/active-today';
}

// Read-only, same as EV Owners - this page is purely informational (who's
// been active today), not an approval interface.
function userRow(user) {
    return `
        <tr>
            <td>${user.name}</td>
            <td class="text-muted">${user.email}</td>
            <td><span class="badge bg-brand">${ROLE_LABELS[user.role] ?? user.role}</span></td>
            <td class="text-muted small">${formatDate(user.updated_at)}</td>
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

async function loadActiveToday(url) {
    const loading = document.getElementById('active-today-loading');
    const empty = document.getElementById('active-today-empty');
    const errorBox = document.getElementById('active-today-error');
    const tableWrap = document.getElementById('active-today-table-wrap');
    const tbody = document.getElementById('active-today-tbody');
    const pagination = document.getElementById('active-today-pagination');
    const prevItem = document.getElementById('active-today-prev-item');
    const nextItem = document.getElementById('active-today-next-item');
    const prevLink = document.getElementById('active-today-prev');
    const nextLink = document.getElementById('active-today-next');

    const targetUrl = url ?? buildUrl();
    const seq = ++loadSeq;

    loading.classList.remove('d-none');
    empty.classList.add('d-none');
    errorBox.classList.add('d-none');
    tableWrap.classList.add('d-none');
    pagination.classList.add('d-none');

    const response = await apiFetch(targetUrl);
    if (seq !== loadSeq) return;

    loading.classList.add('d-none');

    if (!response.ok) {
        errorBox.textContent = 'Unable to load active users. Please try again.';
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
    const tbody = document.getElementById('active-today-tbody');
    if (!tbody) return;

    const searchInput = document.getElementById('active-today-search');
    const roleSelect = document.getElementById('active-today-role-filter');
    const prevItem = document.getElementById('active-today-prev-item');
    const nextItem = document.getElementById('active-today-next-item');
    const prevLink = document.getElementById('active-today-prev');
    const nextLink = document.getElementById('active-today-next');

    const debouncedSearch = debounce(() => {
        searchQuery = searchInput.value.trim();
        loadActiveToday();
    }, 250);
    searchInput.addEventListener('input', debouncedSearch);

    roleSelect.addEventListener('change', () => {
        roleFilter = roleSelect.value;
        loadActiveToday();
    });

    prevLink.addEventListener('click', (event) => {
        event.preventDefault();
        if (prevItem.classList.contains('disabled') || !prevLink.dataset.url) return;
        loadActiveToday(prevLink.dataset.url);
    });

    nextLink.addEventListener('click', (event) => {
        event.preventDefault();
        if (nextItem.classList.contains('disabled') || !nextLink.dataset.url) return;
        loadActiveToday(nextLink.dataset.url);
    });

    loadActiveToday();
});
