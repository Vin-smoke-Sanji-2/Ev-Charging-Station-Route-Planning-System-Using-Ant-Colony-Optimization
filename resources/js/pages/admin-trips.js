import { apiFetch } from '../api.js';
import { debounce } from '../debounce.js';

// Same status vocabulary/badge mapping as trip-history.js (the EV-owner-
// facing equivalent) - "planned" deliberately shares the gold accent
// token with "active" there, kept identical here for consistency.
function statusBadgeClass(status) {
    return {
        planned: 'bg-accent',
        active: 'bg-accent',
        completed: 'bg-primary',
        cancelled: 'bg-danger',
    }[status] || 'bg-accent';
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

function formatCurrency(value) {
    return value === null || value === undefined ? '—' : `${Number(value).toLocaleString()} MMK`;
}

// total_duration_min is in minutes - shown as "X hr Y min" here, matching
// trip-show.js's own formatDuration(), so a trip's duration reads the same
// way whether an EV owner or an admin is looking at it.
function formatDuration(minutes) {
    if (minutes === null || minutes === undefined) return '—';
    const total = Math.round(Number(minutes));
    const hrs = Math.floor(total / 60);
    const mins = total % 60;
    if (hrs === 0) return `${mins} min`;
    if (mins === 0) return `${hrs} hr`;
    return `${hrs} hr ${mins} min`;
}

let statusFilter = '';
let searchQuery = '';
let loadSeq = 0;

function buildUrl() {
    const params = new URLSearchParams();
    if (statusFilter) params.set('status', statusFilter);
    if (searchQuery) params.set('name', searchQuery);
    const qs = params.toString();
    return qs ? `/api/admin/trips?${qs}` : '/api/admin/trips';
}

function tripRow(trip) {
    // latest_route may be missing entirely for a trip with no route yet -
    // shown as a plain "No route" badge rather than crashing on ?.status,
    // matching trip-history.js's own defensive handling of this case.
    const status = trip.latest_route?.status;
    const distance = trip.latest_route?.total_distance_km;
    const duration = trip.latest_route?.total_duration_min;
    const cost = trip.latest_route?.estimated_cost;
    const vehicleLabel = trip.vehicle?.ev_model
        ? `${trip.vehicle.ev_model.brand} ${trip.vehicle.ev_model.model}`
        : '—';
    const origin = trip.origin_node?.name ?? '—';
    const destination = trip.destination_node?.name ?? '—';
    const statusBadge = status
        ? `<span class="badge badge-status ${statusBadgeClass(status)}">${status}</span>`
        : '<span class="badge badge-status bg-secondary">No route</span>';

    return `
        <tr>
            <td>
                <div>${trip.user?.name ?? '—'}</div>
                <div class="text-muted small">${trip.user?.email ?? ''}</div>
            </td>
            <td>${vehicleLabel}</td>
            <td>${origin} &rarr; ${destination}</td>
            <td>${statusBadge}</td>
            <td>${distance !== null && distance !== undefined ? `${distance} km` : '—'}</td>
            <td>${formatDuration(duration)}</td>
            <td>${formatCurrency(cost)}</td>
            <td class="text-muted small">${formatDate(trip.requested_at)}</td>
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

async function loadTrips(url) {
    const loading = document.getElementById('trips-loading');
    const empty = document.getElementById('trips-empty');
    const errorBox = document.getElementById('trips-error');
    const tableWrap = document.getElementById('trips-table-wrap');
    const tbody = document.getElementById('trips-tbody');
    const pagination = document.getElementById('trips-pagination');
    const prevItem = document.getElementById('trips-prev-item');
    const nextItem = document.getElementById('trips-next-item');
    const prevLink = document.getElementById('trips-prev');
    const nextLink = document.getElementById('trips-next');

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
        errorBox.textContent = 'Unable to load trips. Please try again.';
        errorBox.classList.remove('d-none');
        return;
    }

    const data = await response.json();

    tbody.dataset.currentUrl = targetUrl;

    if (data.total === 0) {
        empty.classList.remove('d-none');
        return;
    }

    tbody.innerHTML = data.data.map(tripRow).join('');
    tableWrap.classList.remove('d-none');

    if (data.last_page > 1) {
        pagination.classList.remove('d-none');
        setPageLinkState(prevItem, prevLink, data.prev_page_url);
        setPageLinkState(nextItem, nextLink, data.next_page_url);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const tbody = document.getElementById('trips-tbody');
    if (!tbody) return;

    const searchInput = document.getElementById('trips-search');
    const statusSelect = document.getElementById('trips-status-filter');
    const prevItem = document.getElementById('trips-prev-item');
    const nextItem = document.getElementById('trips-next-item');
    const prevLink = document.getElementById('trips-prev');
    const nextLink = document.getElementById('trips-next');

    const debouncedSearch = debounce(() => {
        searchQuery = searchInput.value.trim();
        loadTrips();
    }, 250);
    searchInput.addEventListener('input', debouncedSearch);

    statusSelect.addEventListener('change', () => {
        statusFilter = statusSelect.value;
        loadTrips();
    });

    prevLink.addEventListener('click', (event) => {
        event.preventDefault();
        if (prevItem.classList.contains('disabled') || !prevLink.dataset.url) return;
        loadTrips(prevLink.dataset.url);
    });

    nextLink.addEventListener('click', (event) => {
        event.preventDefault();
        if (nextItem.classList.contains('disabled') || !nextLink.dataset.url) return;
        loadTrips(nextLink.dataset.url);
    });

    loadTrips();
});
