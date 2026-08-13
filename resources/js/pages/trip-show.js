import 'leaflet/dist/leaflet.css';
import { apiFetch } from '../api.js';
import { attachNavigate } from '../navigate.js';
import { attachRecalculate } from '../recalculate.js';
import { renderRouteMap, renderStopListItem } from '../route-map.js';

function formatNumber(value, unit) {
    return value === null || value === undefined ? '—' : `${Number(value).toLocaleString()} ${unit}`;
}

// total_duration_min comes back from the backend in minutes (it's summed
// from real per-edge driving time plus charging/wait time in minutes) -
// shown here as "X hr Y min" rather than decimal hours, so a multi-hour
// trip reads as "16 hr 18 min" instead of "976.1 min" or "16.3 hr".
function formatDuration(minutes) {
    if (minutes === null || minutes === undefined) return '—';
    const total = Math.round(Number(minutes));
    const hrs = Math.floor(total / 60);
    const mins = total % 60;
    if (hrs === 0) return `${mins} min`;
    if (mins === 0) return `${hrs} hr`;
    return `${hrs} hr ${mins} min`;
}

function statusBadgeClass(status) {
    // "active" was Bootstrap's plain green .bg-success, now gold accent.
    // "planned" was Bootstrap's plain gray .bg-secondary, now burgundy
    // (.bg-burgundy) per direct request. completed/cancelled keep their
    // original Bootstrap colors (out of scope for either pass).
    return {
        planned: 'bg-burgundy',
        active: 'bg-accent',
        completed: 'bg-primary',
        cancelled: 'bg-danger',
    }[status] || 'bg-burgundy';
}

function renderStops(stops) {
    const list = document.getElementById('stops-list');
    const empty = document.getElementById('stops-empty');

    if (stops.length === 0) {
        list.innerHTML = '';
        empty.classList.remove('d-none');
        return;
    }

    // A recalculation can change a route from zero stops to some (or vice
    // versa) and re-runs this on the same page - the empty state must be
    // re-hidden here, not just shown once on a page that used to only
    // render stops a single time.
    empty.classList.add('d-none');
    list.innerHTML = stops.map((stop) => renderStopListItem(stop, { showWaitTime: true })).join('');
}

// Recalculating replaces the route in place (new distance/stops/geometry),
// so loadTrip() must be safely re-runnable - it re-creates the Leaflet map
// (guarding against "Map container is already initialized" by removing any
// prior instance first) and clone-replaces buttons that get listeners
// attached each run, so those listeners don't pile up across recalculations.
let currentMap = null;

// Clones a button to strip any listeners from a previous loadTrip() run,
// returning the fresh element so the caller can attach this run's listener.
function freshButton(id) {
    const old = document.getElementById(id);
    const fresh = old.cloneNode(true);
    old.replaceWith(fresh);
    return fresh;
}

async function loadTrip(tripId) {
    const loading = document.getElementById('trip-loading');
    const errorBox = document.getElementById('trip-error');
    const content = document.getElementById('trip-content');

    const response = await apiFetch(`/api/trips/${tripId}`);

    if (!response.ok) {
        loading.classList.add('d-none');
        errorBox.textContent = response.status === 404 || response.status === 403
            ? "This trip doesn't exist or isn't yours to view."
            : 'Unable to load this trip. Please try again.';
        errorBox.classList.remove('d-none');
        return;
    }

    const trip = await response.json();
    const route = trip.routes && trip.routes.length > 0 ? trip.routes[trip.routes.length - 1] : null;
    const stops = route?.charging_stops ?? [];

    document.getElementById('trip-origin').textContent = trip.origin_node.name;
    document.getElementById('trip-destination').textContent = trip.destination_node.name;

    const vehicleLabel = trip.vehicle?.ev_model
        ? `${trip.vehicle.ev_model.brand} ${trip.vehicle.ev_model.model}`
        : 'your vehicle';
    document.getElementById('trip-meta').textContent =
        `${vehicleLabel} - starting at ${trip.battery_percent}% battery`;

    const statusBadge = document.getElementById('trip-status');
    statusBadge.textContent = route ? route.status : 'planned';
    statusBadge.className = `badge-btn-match ${statusBadgeClass(route?.status)}`;

    document.getElementById('stat-distance').textContent = formatNumber(route?.total_distance_km, 'km');
    document.getElementById('stat-duration').textContent = formatDuration(route?.total_duration_min);
    document.getElementById('stat-stops').textContent = stops.length;

    renderStops(stops);

    loading.classList.add('d-none');
    content.classList.remove('d-none');

    if (currentMap) {
        currentMap.remove();
        currentMap = null;
    }

    // The map container must be visible (not display:none) before Leaflet
    // measures it, so this runs after the d-none class is removed above.
    currentMap = renderRouteMap('trip-map', trip.origin_node, trip.destination_node, stops, trip.route_geometry);

    attachNavigate({
        map: currentMap,
        buttonId: freshButton('navigate-btn').id,
        statusId: 'navigate-status',
        target: [Number(trip.destination_node.latitude), Number(trip.destination_node.longitude)],
        targetLabel: trip.destination_node.name,
        targetNodeId: trip.destination_node.id,
    });

    setupStartTrip(tripId, route?.status);

    // Only recalculable while a plan exists and hasn't finished - a
    // completed/cancelled route has nothing left to recalculate. Set this
    // on the current button before attachRecalculate() clone-replaces it -
    // cloneNode(true) copies classes along with the element.
    document.getElementById('recalculate-btn').classList.toggle(
        'd-none',
        !(route && (route.status === 'planned' || route.status === 'active')),
    );
    attachRecalculate({
        tripId,
        defaultBatteryPercent: trip.battery_percent,
        onSuccess: () => loadTrip(tripId),
    });
}

function setupStartTrip(tripId, status) {
    const startWrap = document.getElementById('start-trip-wrap');
    const activeBanner = document.getElementById('active-trip-banner');
    const startBtn = freshButton('start-trip-btn');
    const startError = document.getElementById('start-trip-error');

    startWrap.classList.add('d-none');
    activeBanner.classList.add('d-none');

    if (status === 'planned') {
        startWrap.classList.remove('d-none');
    } else if (status === 'active') {
        activeBanner.classList.remove('d-none');
    }
    // completed/cancelled: neither shown, matching "leave existing display
    // behavior as-is" for a completed trip (already handled by summary()).

    startBtn.addEventListener('click', async () => {
        startBtn.disabled = true;
        startError.classList.add('d-none');

        const response = await apiFetch(`/api/trips/${tripId}/start`, { method: 'POST' });

        if (response.ok) {
            window.location.href = '/trips/live';
            return;
        }

        const data = await response.json().catch(() => ({}));
        startError.textContent = data.message || 'Unable to start this trip. Please try again.';
        startError.classList.remove('d-none');
        startBtn.disabled = false;
    });
}

document.addEventListener('DOMContentLoaded', () => {
    const container = document.getElementById('trip-app');
    if (!container) return;

    const tripId = container.dataset.tripId;
    loadTrip(tripId).catch(() => {
        document.getElementById('trip-loading').classList.add('d-none');
        const errorBox = document.getElementById('trip-error');
        errorBox.textContent = 'Something went wrong loading this trip.';
        errorBox.classList.remove('d-none');
    });
});
