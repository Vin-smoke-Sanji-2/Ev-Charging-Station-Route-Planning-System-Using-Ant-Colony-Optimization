import { apiFetch } from '../api.js';

// "planned" is a deliberate departure from trip-show.js's statusBadgeClass()
// here specifically - gold accent (same token as "active"), not gray,
// per an explicit request to make it stand out more on this page's table.
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

function tripRow(trip) {
    // latest_route may be missing entirely for a trip with no route yet -
    // fall back to a plain "planned" label rather than crashing on ?.status.
    const status = trip.latest_route?.status ?? 'planned';
    const distance = trip.latest_route?.total_distance_km;
    const vehicleLabel = trip.vehicle?.ev_model
        ? `${trip.vehicle.ev_model.brand} ${trip.vehicle.ev_model.model}`
        : '—';
    const origin = trip.origin_node?.name ?? '—';
    const destination = trip.destination_node?.name ?? '—';

    return `
        <tr>
            <td>${formatDate(trip.requested_at)}</td>
            <td>${origin} &rarr; ${destination}</td>
            <td>${vehicleLabel}</td>
            <td><span class="badge badge-status ${statusBadgeClass(status)}">${status}</span></td>
            <td>${distance !== null && distance !== undefined ? `${distance} km` : '—'}</td>
            <td class="text-end">
                <a href="/trips/${trip.id}" class="btn btn-sm btn-secondary">View</a>
            </td>
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

async function loadPage(url) {
    const loading = document.getElementById('trips-loading');
    const empty = document.getElementById('trips-empty');
    const tableWrap = document.getElementById('trips-table-wrap');
    const tbody = document.getElementById('trips-tbody');
    const pagination = document.getElementById('trips-pagination');
    const prevItem = document.getElementById('trips-prev-item');
    const nextItem = document.getElementById('trips-next-item');
    const prevLink = document.getElementById('trips-prev');
    const nextLink = document.getElementById('trips-next');

    loading.classList.remove('d-none');
    empty.classList.add('d-none');
    tableWrap.classList.add('d-none');
    pagination.classList.add('d-none');

    const response = await apiFetch(url);
    const data = response.ok ? await response.json() : null;

    loading.classList.add('d-none');

    // Laravel's raw paginator JSON is flat (current_page, data, total,
    // prev_page_url, next_page_url, ...) - not nested under links/meta.
    if (!data || data.total === 0) {
        empty.classList.remove('d-none');
        return;
    }

    tbody.innerHTML = data.data.map(tripRow).join('');
    tableWrap.classList.remove('d-none');

    pagination.classList.remove('d-none');
    setPageLinkState(prevItem, prevLink, data.prev_page_url);
    setPageLinkState(nextItem, nextLink, data.next_page_url);
}

document.addEventListener('DOMContentLoaded', () => {
    const tbody = document.getElementById('trips-tbody');
    if (!tbody) return;

    const prevItem = document.getElementById('trips-prev-item');
    const nextItem = document.getElementById('trips-next-item');
    const prevLink = document.getElementById('trips-prev');
    const nextLink = document.getElementById('trips-next');

    loadPage('/api/trips');

    prevLink.addEventListener('click', (event) => {
        event.preventDefault();
        if (prevItem.classList.contains('disabled') || !prevLink.dataset.url) return;
        loadPage(prevLink.dataset.url);
    });

    nextLink.addEventListener('click', (event) => {
        event.preventDefault();
        if (nextItem.classList.contains('disabled') || !nextLink.dataset.url) return;
        loadPage(nextLink.dataset.url);
    });
});
