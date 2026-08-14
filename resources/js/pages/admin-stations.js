import { apiFetch } from '../api.js';
import { debounce } from '../debounce.js';

const TAB_KEYS = ['pending', 'verified', 'rejected'];

// Each tab's own default status filter (used when the dropdown is at
// "All"). 'verified' spans two real statuses, since Verified and
// Suspended share one tab per the requirements.
const TAB_BASE_STATUS = {
    pending: 'pending',
    verified: 'verified,suspended',
    rejected: 'rejected',
};

// The dropdown offers 'verified'/'suspended' as two separate options even
// though they share one tab - selecting either narrows that tab down to
// just the one status, more granular than the tab alone can do.
const VERIFIED_SUB_STATUS = { verified: 'verified', suspended: 'suspended' };

function tabForStatusValue(value) {
    if (value === 'pending') return 'pending';
    if (value === 'verified' || value === 'suspended') return 'verified';
    if (value === 'rejected') return 'rejected';
    return null; // '' (All) never forces a tab switch
}

let statusFilterValue = '';
let searchQuery = '';
let activeTabKey = 'pending';
let switchingFromDropdown = false;

function buildTabUrl(tabKey) {
    const params = new URLSearchParams();
    const subStatus = tabKey === 'verified' ? VERIFIED_SUB_STATUS[statusFilterValue] : null;
    params.set('status', subStatus ?? TAB_BASE_STATUS[tabKey]);
    if (searchQuery) params.set('name', searchQuery);
    return `/api/admin/stations?${params}`;
}

// Local badge function, not shared with station-owner-stations-index.js's
// verificationBadge() - this one needs the 4th 'suspended' case that the
// station-owner-facing list doesn't render an action around.
function verificationBadge(status) {
    if (status === 'verified') return '<span class="badge badge-verified badge-status">Verified</span>';
    if (status === 'suspended') return '<span class="badge bg-warning text-dark badge-status">Suspended</span>';
    if (status === 'rejected') return '<span class="badge bg-danger badge-status">Rejected</span>';
    return '<span class="badge bg-accent badge-status">Pending</span>';
}

function stationDetails(station) {
    const owner = station.owner;
    const ownerLine = owner ? `${owner.name} (${owner.email})` : 'No owner on file';

    return `
        <div class="row g-2 mt-2">
            <div class="col-sm-6 col-md-3">
                <div class="text-muted small text-uppercase">Charging speed</div>
                <div>${station.charging_speed || 'Unknown'}</div>
            </div>
            <div class="col-sm-6 col-md-3">
                <div class="text-muted small text-uppercase">Operating hours</div>
                <div>${station.operating_hours || 'Not specified'}</div>
            </div>
            <div class="col-md-6">
                <div class="text-muted small text-uppercase">Owner</div>
                <div>${ownerLine}</div>
            </div>
        </div>
    `;
}

// Pending only ever offers Verify/Reject - no Suspend option here, since
// suspend can only ever apply to an already-verified station.
function pendingCard(station) {
    return `
        <div class="card border-0 shadow-sm" data-station-card="${station.id}">
            <div class="card-body">
                <div class="d-flex align-items-start justify-content-between flex-wrap gap-2">
                    <div>
                        <h5 class="card-title mb-1"><i class="bi bi-ev-station-fill text-brand"></i> ${station.name}</h5>
                        <p class="text-muted mb-1">${station.address || 'No address on file'}</p>
                        <p class="text-muted small mb-0">${station.township || 'No township on file'}</p>
                    </div>
                    ${verificationBadge(station.verification_status)}
                </div>
                ${stationDetails(station)}
                <div class="d-flex gap-3 mt-3">
                    <button type="button" class="btn btn-primary btn-admin-action" data-action="verify" data-id="${station.id}" data-tab="pending">
                        <i class="bi bi-check-lg"></i> Verify
                    </button>
                    <button type="button" class="btn btn-danger btn-admin-action" data-action="reject" data-id="${station.id}" data-tab="pending">
                        <i class="bi bi-x-lg"></i> Reject
                    </button>
                    ${deleteButton(station.id, 'pending')}
                </div>
            </div>
        </div>
    `;
}

// Delete is offered from every tab regardless of verification status -
// deliberately separate from the verify/reject/suspend/reactivate status
// actions above (which only ever move a station between statuses), never
// removed from the row set the way those are once a status makes them
// inapplicable.
function deleteButton(stationId, tab) {
    return `
        <button type="button" class="btn btn-danger btn-admin-action" data-action="delete" data-id="${stationId}" data-tab="${tab}">
            <i class="bi bi-trash"></i> Delete
        </button>
    `;
}

// Verified and Suspended share this one tab, per the same "combined
// current-status view" pattern as Station Owners' Accepted & Suspended tab
// - only the single applicable action shows per card.
function verifiedCard(station) {
    const action = station.verification_status === 'verified'
        ? `<button type="button" class="btn btn-danger btn-admin-action" data-action="suspend" data-id="${station.id}" data-tab="verified">
               <i class="bi bi-pause-circle"></i> Suspend
           </button>`
        : `<button type="button" class="btn btn-primary btn-admin-action" data-action="reactivate" data-id="${station.id}" data-tab="verified">
               <i class="bi bi-play-circle"></i> Reactivate
           </button>`;

    return `
        <div class="card border-0 shadow-sm" data-station-card="${station.id}">
            <div class="card-body">
                <div class="d-flex align-items-start justify-content-between flex-wrap gap-2">
                    <div>
                        <h5 class="card-title mb-1"><i class="bi bi-ev-station-fill text-brand"></i> ${station.name}</h5>
                        <p class="text-muted mb-1">${station.address || 'No address on file'}</p>
                        <p class="text-muted small mb-0">${station.township || 'No township on file'}</p>
                    </div>
                    ${verificationBadge(station.verification_status)}
                </div>
                ${stationDetails(station)}
                <div class="d-flex gap-3 mt-3">${action} ${deleteButton(station.id, 'verified')}</div>
            </div>
        </div>
    `;
}

// Rejected is terminal for the verify/reject/suspend/reactivate status
// workflow (matching the backend's transition map - zero legal outgoing
// status transitions), but Delete is still offered here - a rejected
// station is exactly the kind of row an admin would want to permanently
// clear out.
function rejectedCard(station) {
    return `
        <div class="card border-0 shadow-sm" data-station-card="${station.id}">
            <div class="card-body">
                <div class="d-flex align-items-start justify-content-between flex-wrap gap-2">
                    <div>
                        <h5 class="card-title mb-1"><i class="bi bi-ev-station-fill text-brand"></i> ${station.name}</h5>
                        <p class="text-muted mb-1">${station.address || 'No address on file'}</p>
                        <p class="text-muted small mb-0">${station.township || 'No township on file'}</p>
                    </div>
                    ${verificationBadge(station.verification_status)}
                </div>
                ${stationDetails(station)}
                <div class="d-flex gap-3 mt-3">${deleteButton(station.id, 'rejected')}</div>
            </div>
        </div>
    `;
}

const TAB_CARD_RENDERER = { pending: pendingCard, verified: verifiedCard, rejected: rejectedCard };

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
    const list = document.getElementById(`${tabKey}-list`);
    const pagination = document.getElementById(`${tabKey}-pagination`);
    const prevItem = document.getElementById(`${tabKey}-prev-item`);
    const nextItem = document.getElementById(`${tabKey}-next-item`);
    const prevLink = document.getElementById(`${tabKey}-prev`);
    const nextLink = document.getElementById(`${tabKey}-next`);

    const targetUrl = url ?? buildTabUrl(tabKey);
    const seq = ++loadSeq;

    loading.classList.remove('d-none');
    empty.classList.add('d-none');
    list.innerHTML = '';
    pagination.classList.add('d-none');

    const response = await apiFetch(targetUrl);
    if (seq !== loadSeq) return; // superseded by a newer request

    loading.classList.add('d-none');

    const errorBox = document.getElementById('stations-error');
    if (!response.ok) {
        errorBox.textContent = 'Unable to load stations. Please try again.';
        errorBox.classList.remove('d-none');
        return;
    }

    const data = await response.json();

    list.dataset.currentUrl = targetUrl;

    if (data.total === 0) {
        empty.classList.remove('d-none');
        return;
    }

    list.innerHTML = data.data.map(TAB_CARD_RENDERER[tabKey]).join('');

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
    const tabsRoot = document.getElementById('stationTabs');
    if (!tabsRoot) return;

    TAB_KEYS.forEach(attachPagination);

    const errorBox = document.getElementById('stations-error');
    const searchInput = document.getElementById('stations-search');
    const statusFilter = document.getElementById('stations-status-filter');

    document.getElementById('stationTabsContent').addEventListener('click', async (event) => {
        const btn = event.target.closest('[data-action]');
        if (!btn) return;

        const { action, id, tab } = btn.dataset;

        if (action === 'delete') {
            await handleDelete(id, tab, btn);
            return;
        }

        // Reject/Suspend are consequential (confirm() dialog, matching this
        // project's existing convention); Verify/Reactivate are not.
        if (action === 'reject' && !confirm('Reject this station? The owner will need to resubmit or contact support.')) {
            return;
        }
        if (action === 'suspend' && !confirm('Suspend this station? It will be hidden from the public map/directory until reactivated.')) {
            return;
        }

        const targetStatus = { verify: 'verified', reject: 'rejected', suspend: 'suspended', reactivate: 'verified' }[action];

        errorBox.classList.add('d-none');
        btn.disabled = true;

        const response = await apiFetch(`/api/admin/stations/${id}/verify`, {
            method: 'PUT',
            body: JSON.stringify({ verification_status: targetStatus }),
        });

        if (!response.ok) {
            const data = await response.json().catch(() => null);
            errorBox.textContent = data?.message || 'Unable to update this station. Please try again.';
            errorBox.classList.remove('d-none');
            btn.disabled = false;
            return;
        }

        // Refetch the tab the action was taken from, from the server -
        // this project's established "never patch client-side" discipline.
        await loadTab(tab, document.getElementById(`${tab}-list`).dataset.currentUrl);
    });

    /**
     * Two-phase delete, matching what the backend actually requires:
     * 1. A plain confirm() gates every delete attempt up front, regardless
     *    of what the station has attached to it - the same "destructive
     *    action needs confirm() first" discipline as Reject/Suspend above.
     * 2. The first DELETE call never has a `confirmed` flag. If the
     *    station has real charging_sessions/reviews that would be
     *    cascade-destroyed, the backend deliberately does NOT delete yet -
     *    it responds 422 with `requires_confirmation: true` and the real
     *    counts, which is shown in a second, specific confirm() dialog.
     *    Only accepting that shows the exact numbers being lost triggers
     *    the real, confirmed=true delete. A station with no history (or a
     *    hard route-history block, which can never be forced) resolves on
     *    the first call.
     */
    async function handleDelete(id, tab, btn) {
        if (!confirm('Delete this station? This cannot be undone.')) return;

        errorBox.classList.add('d-none');
        btn.disabled = true;

        let response = await apiFetch(`/api/admin/stations/${id}`, { method: 'DELETE' });

        if (response.status === 422) {
            const data = await response.json().catch(() => null);

            if (data?.requires_confirmation) {
                if (!confirm(data.message)) {
                    btn.disabled = false;
                    return;
                }
                response = await apiFetch(`/api/admin/stations/${id}?confirmed=true`, { method: 'DELETE' });
            } else {
                errorBox.textContent = data?.message || 'Unable to delete this station. Please try again.';
                errorBox.classList.remove('d-none');
                btn.disabled = false;
                return;
            }
        }

        if (!response.ok) {
            const data = await response.json().catch(() => null);
            errorBox.textContent = data?.message || 'Unable to delete this station. Please try again.';
            errorBox.classList.remove('d-none');
            btn.disabled = false;
            return;
        }

        await loadTab(tab, document.getElementById(`${tab}-list`).dataset.currentUrl);
    }

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

    // Deep-link support: Overview's "Pending Station Verifications" card
    // links here with ?tab=pending explicitly (also the default).
    const requestedTab = new URLSearchParams(location.search).get('tab');
    const initialTab = TAB_KEYS.includes(requestedTab) ? requestedTab : 'pending';
    activeTabKey = initialTab;

    // Lazy: a tab the admin never opens is never fetched at all - but
    // switching BACK to an already-visited tab always refetches too, since
    // the shared search box or status dropdown may have changed while that
    // tab was hidden.
    document.querySelectorAll('[data-bs-toggle="tab"]').forEach((tabBtn) => {
        tabBtn.addEventListener('shown.bs.tab', (event) => {
            const tabKey = event.target.dataset.tabKey;
            activeTabKey = tabKey;

            if (!switchingFromDropdown) {
                statusFilterValue = '';
                statusFilter.value = '';
            }
            switchingFromDropdown = false;

            loadTab(tabKey, buildTabUrl(tabKey));
        });
    });

    loadTab(initialTab, buildTabUrl(initialTab));

    if (initialTab !== 'pending') {
        window.bootstrap.Tab.getOrCreateInstance(document.getElementById(`tab-${initialTab}-btn`)).show();
    }
});
