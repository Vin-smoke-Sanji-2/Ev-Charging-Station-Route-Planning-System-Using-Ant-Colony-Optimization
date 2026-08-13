import { apiFetch } from '../api.js';
import { debounce } from '../debounce.js';

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

// Read-only - EV owners never go through any approval workflow (see
// CLAUDE.md), so this page is deliberately just a headcount: no status
// column, no filters, no action column, no self-protection concept to
// account for. Purely "how many people are using the app."
function userRow(user) {
    return `
        <tr>
            <td>${user.name}</td>
            <td class="text-muted">${user.email}</td>
            <td class="text-muted small">${formatDate(user.created_at)}</td>
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

let searchQuery = '';
let loadSeq = 0;

function buildUrl() {
    const params = new URLSearchParams({ role: 'ev_owner' });
    if (searchQuery) params.set('name', searchQuery);
    return `/api/admin/users?${params}`;
}

async function loadEvOwners(url) {
    const loading = document.getElementById('ev-owners-loading');
    const empty = document.getElementById('ev-owners-empty');
    const errorBox = document.getElementById('ev-owners-error');
    const tableWrap = document.getElementById('ev-owners-table-wrap');
    const tbody = document.getElementById('ev-owners-tbody');
    const pagination = document.getElementById('ev-owners-pagination');
    const prevItem = document.getElementById('ev-owners-prev-item');
    const nextItem = document.getElementById('ev-owners-next-item');
    const prevLink = document.getElementById('ev-owners-prev');
    const nextLink = document.getElementById('ev-owners-next');

    const seq = ++loadSeq;

    loading.classList.remove('d-none');
    empty.classList.add('d-none');
    errorBox.classList.add('d-none');
    tableWrap.classList.add('d-none');
    pagination.classList.add('d-none');

    const response = await apiFetch(url ?? buildUrl());
    if (seq !== loadSeq) return; // superseded by a newer search keystroke

    loading.classList.add('d-none');

    if (!response.ok) {
        errorBox.textContent = 'Unable to load EV owners. Please try again.';
        errorBox.classList.remove('d-none');
        return;
    }

    const data = await response.json();

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
    const tbody = document.getElementById('ev-owners-tbody');
    if (!tbody) return;

    const prevItem = document.getElementById('ev-owners-prev-item');
    const nextItem = document.getElementById('ev-owners-next-item');
    const prevLink = document.getElementById('ev-owners-prev');
    const nextLink = document.getElementById('ev-owners-next');
    const searchInput = document.getElementById('ev-owners-search');

    const debouncedSearch = debounce(() => {
        searchQuery = searchInput.value.trim();
        loadEvOwners();
    }, 250);
    searchInput.addEventListener('input', debouncedSearch);

    prevLink.addEventListener('click', (event) => {
        event.preventDefault();
        if (prevItem.classList.contains('disabled') || !prevLink.dataset.url) return;
        loadEvOwners(prevLink.dataset.url);
    });

    nextLink.addEventListener('click', (event) => {
        event.preventDefault();
        if (nextItem.classList.contains('disabled') || !nextLink.dataset.url) return;
        loadEvOwners(nextLink.dataset.url);
    });

    loadEvOwners();
});
