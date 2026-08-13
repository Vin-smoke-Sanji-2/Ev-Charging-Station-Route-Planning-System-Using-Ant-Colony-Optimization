import { apiFetch } from '../api.js';
import { debounce } from '../debounce.js';
import { statusBadge, userStatusActionHtml, confirmStatusChange, applyUserStatusChange } from './admin-user-status-actions.js';

const TAB_KEYS = ['pending', 'accepted', 'rejected'];

// Each tab's own default status filter (used when the dropdown is at
// "All"). 'accepted' spans two real statuses, since Active and Suspended
// share one tab per the requirements.
const TAB_BASE_STATUS = {
    pending: 'pending',
    accepted: 'active,suspended',
    rejected: 'rejected',
};

// The dropdown offers 'active'/'suspended' as two separate options even
// though they share one tab - selecting either narrows that tab down to
// just the one status, more granular than the tab alone can do.
const ACCEPTED_SUB_STATUS = { active: 'active', suspended: 'suspended' };

function tabForStatusValue(value) {
    if (value === 'pending') return 'pending';
    if (value === 'active' || value === 'suspended') return 'accepted';
    if (value === 'rejected') return 'rejected';
    return null; // '' (All) never forces a tab switch
}

let statusFilterValue = '';
let searchQuery = '';
let activeTabKey = 'pending';
// Set right before the dropdown handler switches tabs programmatically,
// checked (and cleared) inside shown.bs.tab - lets that handler tell a
// dropdown-triggered switch apart from the admin clicking a tab directly.
let switchingFromDropdown = false;

function buildTabUrl(tabKey) {
    const params = new URLSearchParams({ role: 'station_owner' });
    const subStatus = tabKey === 'accepted' ? ACCEPTED_SUB_STATUS[statusFilterValue] : null;
    params.set('status', subStatus ?? TAB_BASE_STATUS[tabKey]);
    if (searchQuery) params.set('name', searchQuery);
    return `/api/admin/users?${params}`;
}

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

// Pending only ever offers Accept/Reject - no dropdown, no Suspend option,
// per the requirement that Suspend can only ever apply to an already-
// accepted owner.
function pendingRow(user) {
    return `
        <tr data-user-row="${user.id}">
            <td>${user.name}</td>
            <td class="text-muted">${user.email}</td>
            <td class="text-muted small">${formatDate(user.created_at)}</td>
            <td>${userStatusActionHtml(user, 'data-tab="pending"')}</td>
        </tr>
    `;
}

// Active and Suspended share this one tab/table, per requirement - only the
// single applicable action shows per row, never both, and never a dropdown.
function acceptedRow(user) {
    return `
        <tr data-user-row="${user.id}">
            <td>${user.name}</td>
            <td class="text-muted">${user.email}</td>
            <td>${statusBadge(user.status)}</td>
            <td class="text-muted small">${formatDate(user.created_at)}</td>
            <td>${userStatusActionHtml(user, 'data-tab="accepted"')}</td>
        </tr>
    `;
}

// Rejected is terminal - no action of any kind is offered here, matching
// the backend's own transition map (rejected has zero legal outgoing
// transitions through this endpoint).
function rejectedRow(user) {
    return `
        <tr data-user-row="${user.id}">
            <td>${user.name}</td>
            <td class="text-muted">${user.email}</td>
            <td class="text-muted small">${formatDate(user.created_at)}</td>
        </tr>
    `;
}

const TAB_ROW_RENDERER = { pending: pendingRow, accepted: acceptedRow, rejected: rejectedRow };

function setPageLinkState(itemEl, linkEl, url) {
    if (url) {
        itemEl.classList.remove('disabled');
        linkEl.dataset.url = url;
    } else {
        itemEl.classList.add('disabled');
        delete linkEl.dataset.url;
    }
}

let loadSeq = 0;

async function loadTab(tabKey, url) {
    const loading = document.getElementById(`${tabKey}-loading`);
    const empty = document.getElementById(`${tabKey}-empty`);
    const tableWrap = document.getElementById(`${tabKey}-table-wrap`);
    const tbody = document.getElementById(`${tabKey}-tbody`);
    const pagination = document.getElementById(`${tabKey}-pagination`);
    const prevItem = document.getElementById(`${tabKey}-prev-item`);
    const nextItem = document.getElementById(`${tabKey}-next-item`);
    const prevLink = document.getElementById(`${tabKey}-prev`);
    const nextLink = document.getElementById(`${tabKey}-next`);

    const targetUrl = url ?? buildTabUrl(tabKey);

    // Guards against rapid search keystrokes firing overlapping requests -
    // only needed once typing could trigger reloads; plain pagination
    // clicks never raced before this.
    const seq = ++loadSeq;

    loading.classList.remove('d-none');
    empty.classList.add('d-none');
    tableWrap.classList.add('d-none');
    pagination.classList.add('d-none');

    const response = await apiFetch(targetUrl);
    if (seq !== loadSeq) return; // superseded by a newer request

    loading.classList.add('d-none');

    const errorBox = document.getElementById('station-owners-error');
    if (!response.ok) {
        errorBox.textContent = 'Unable to load station owners. Please try again.';
        errorBox.classList.remove('d-none');
        return;
    }

    const data = await response.json();

    // Remembered per-tab (each tab has its own tbody), so a mutation on one
    // tab always refetches exactly that tab's current page, never resets
    // another tab's independent pagination cursor.
    tbody.dataset.currentUrl = targetUrl;

    if (data.total === 0) {
        empty.classList.remove('d-none');
        return;
    }

    tbody.innerHTML = data.data.map(TAB_ROW_RENDERER[tabKey]).join('');
    tableWrap.classList.remove('d-none');

    if (data.last_page > 1) {
        pagination.classList.remove('d-none');
        setPageLinkState(prevItem, prevLink, data.prev_page_url);
        setPageLinkState(nextItem, nextLink, data.next_page_url);
    }
}

function attachPagination(tabKey) {
    const prevItem = document.getElementById(`${tabKey}-prev-item`);
    const nextItem = document.getElementById(`${tabKey}-next-item`);
    const prevLink = document.getElementById(`${tabKey}-prev`);
    const nextLink = document.getElementById(`${tabKey}-next`);

    prevLink.addEventListener('click', (event) => {
        event.preventDefault();
        if (prevItem.classList.contains('disabled') || !prevLink.dataset.url) return;
        loadTab(tabKey, prevLink.dataset.url);
    });

    nextLink.addEventListener('click', (event) => {
        event.preventDefault();
        if (nextItem.classList.contains('disabled') || !nextLink.dataset.url) return;
        loadTab(tabKey, nextLink.dataset.url);
    });
}

document.addEventListener('DOMContentLoaded', () => {
    const tabsRoot = document.getElementById('stationOwnerTabs');
    if (!tabsRoot) return;

    TAB_KEYS.forEach(attachPagination);

    const errorBox = document.getElementById('station-owners-error');
    const searchInput = document.getElementById('station-owners-search');
    const statusFilter = document.getElementById('station-owners-status-filter');

    // One delegated listener for every row action across all three tabs -
    // each button carries which action, which user, and which tab it
    // belongs to, so the handler knows exactly which tab to refetch
    // afterward (the row disappearing from its origin tab is the correct,
    // expected effect - the other two tabs refetch naturally next time
    // they're shown, not eagerly here).
    document.getElementById('stationOwnerTabsContent').addEventListener('click', async (event) => {
        const btn = event.target.closest('[data-action]');
        if (!btn) return;

        const { action, userId, tab } = btn.dataset;

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

        // Always refetch from the server rather than patching the row in
        // place - this project's established "never patch client-side"
        // discipline, applied per-tab here.
        await loadTab(tab, document.getElementById(`${tab}-tbody`).dataset.currentUrl);
    });

    const debouncedSearch = debounce(() => {
        searchQuery = searchInput.value.trim();
        loadTab(activeTabKey, buildTabUrl(activeTabKey));
    }, 250);
    searchInput.addEventListener('input', debouncedSearch);

    statusFilter.addEventListener('change', () => {
        statusFilterValue = statusFilter.value;
        const targetTab = tabForStatusValue(statusFilterValue);

        if (targetTab && targetTab !== activeTabKey) {
            switchingFromDropdown = true;
            window.bootstrap.Tab.getOrCreateInstance(document.getElementById(`tab-${targetTab}-btn`)).show();
        } else {
            loadTab(activeTabKey, buildTabUrl(activeTabKey));
        }
    });

    // Deep-link support: Overview's "Pending Station Verifications"-style
    // cards can link here with ?tab=accepted etc. Defaults to "pending" for
    // any missing/invalid value.
    const requestedTab = new URLSearchParams(location.search).get('tab');
    const initialTab = TAB_KEYS.includes(requestedTab) ? requestedTab : 'pending';
    activeTabKey = initialTab;

    // Lazy: a tab the admin never opens is never fetched at all - but
    // unlike before the search/dropdown filters existed, switching BACK to
    // an already-visited tab now always refetches too, since the shared
    // search box or status dropdown may have changed while that tab was
    // hidden and its previously-loaded rows could be stale.
    document.querySelectorAll('[data-bs-toggle="tab"]').forEach((tabBtn) => {
        tabBtn.addEventListener('shown.bs.tab', (event) => {
            const tabKey = event.target.dataset.tabKey;
            activeTabKey = tabKey;

            // A direct tab click (not triggered by the dropdown above)
            // resets the status filter back to "All" for this tab, so the
            // dropdown never keeps showing a stale selection that no
            // longer matches what's now displayed.
            if (!switchingFromDropdown) {
                statusFilterValue = '';
                statusFilter.value = '';
            }
            switchingFromDropdown = false;

            loadTab(tabKey, buildTabUrl(tabKey));
        });
    });

    // The initial tab is loaded directly (Bootstrap's shown.bs.tab never
    // fires for a tab that's already active with no switch having
    // occurred).
    loadTab(initialTab, buildTabUrl(initialTab));

    if (initialTab !== 'pending') {
        window.bootstrap.Tab.getOrCreateInstance(document.getElementById(`tab-${initialTab}-btn`)).show();
    }
});
