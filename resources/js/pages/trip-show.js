import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { apiFetch } from '../api.js';
import { attachNavigate } from '../navigate.js';

function formatNumber(value, unit) {
    return value === null || value === undefined ? '—' : `${Number(value).toLocaleString()} ${unit}`;
}

function formatCurrency(value) {
    return value === null || value === undefined ? '—' : `${Number(value).toLocaleString()} MMK`;
}

function statusBadgeClass(status) {
    // "active" is the one reassignment here - was Bootstrap's plain green
    // .bg-success, now gold accent. planned/completed/cancelled keep their
    // original Bootstrap colors (out of scope for this pass).
    return {
        planned: 'bg-secondary',
        active: 'bg-accent',
        completed: 'bg-primary',
        cancelled: 'bg-danger',
    }[status] || 'bg-secondary';
}

function renderMap(originNode, destinationNode, stops) {
    const originLatLng = [Number(originNode.latitude), Number(originNode.longitude)];
    const destinationLatLng = [Number(destinationNode.latitude), Number(destinationNode.longitude)];
    const stopLatLngs = stops
        .filter((stop) => stop.station)
        .map((stop) => [Number(stop.station.latitude), Number(stop.station.longitude)]);
    const points = [originLatLng, ...stopLatLngs, destinationLatLng];

    // The map must settle on its final view BEFORE the tile layer is added.
    // Previously the tile layer was added right after L.map() with no view
    // set yet, so it started loading tiles for an undefined/default view;
    // the later fitBounds() call then animated through several intermediate
    // zoom levels to reach the real view, firing (and cancelling) a full
    // grid of OSM tile requests at each step - 800+ requests for one page
    // load. Computing bounds first and disabling animation means the map
    // only ever renders tiles for the one final view.
    const map = L.map('trip-map');

    if (points.length > 1) {
        map.fitBounds(points, { padding: [30, 30], animate: false });
    } else {
        map.setView(points[0], 7, { animate: false });
    }

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors',
        maxZoom: 19,
    }).addTo(map);

    L.marker(originLatLng).addTo(map).bindPopup(`Start: ${originNode.name}`);

    stops.forEach((stop, index) => {
        if (!stop.station) return;
        const latLng = [Number(stop.station.latitude), Number(stop.station.longitude)];
        L.marker(latLng).addTo(map).bindPopup(`Stop ${index + 1}: ${stop.station.name}`);
    });

    L.marker(destinationLatLng).addTo(map).bindPopup(`Destination: ${destinationNode.name}`);

    // Real route geometry isn't available until the ACO engine is wired up
    // (see TripController::planRoute) - draw a straight dashed line between
    // the known points as a placeholder for the planned path. Was the old
    // brand green (#16a34a) - now the new secondary navy; this line is a
    // different feature from the Dashboard's map markers, so it's not
    // covered by that one deliberate green exception.
    L.polyline(points, { color: '#2E3A59', weight: 3, dashArray: '6 8' }).addTo(map);

    return map;
}

function renderStops(stops) {
    const list = document.getElementById('stops-list');
    const empty = document.getElementById('stops-empty');

    if (stops.length === 0) {
        empty.classList.remove('d-none');
        return;
    }

    list.innerHTML = stops.map((stop) => {
        const station = stop.station;
        const wait = stop.estimated_wait_min !== null && stop.estimated_wait_min !== undefined
            ? `~${stop.estimated_wait_min} min wait`
            : 'wait time unknown';
        return `
            <li class="list-group-item d-flex justify-content-between align-items-start">
                <div>
                    <div class="fw-semibold">${station ? station.name : 'Unknown station'}</div>
                    <div class="text-muted small">${station?.township ?? ''}</div>
                </div>
                <span class="badge bg-brand rounded-pill">${wait}</span>
            </li>
        `;
    }).join('');
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
    statusBadge.className = `badge fs-6 ${statusBadgeClass(route?.status)}`;

    document.getElementById('stat-distance').textContent = formatNumber(route?.total_distance_km, 'km');
    document.getElementById('stat-duration').textContent = formatNumber(route?.total_duration_min, 'min');
    document.getElementById('stat-cost').textContent = formatCurrency(route?.estimated_cost);
    document.getElementById('stat-stops').textContent = stops.length;

    renderStops(stops);

    loading.classList.add('d-none');
    content.classList.remove('d-none');

    // The map container must be visible (not display:none) before Leaflet
    // measures it, so this runs after the d-none class is removed above.
    const map = renderMap(trip.origin_node, trip.destination_node, stops);

    attachNavigate({
        map,
        buttonId: 'navigate-btn',
        statusId: 'navigate-status',
        target: [Number(trip.destination_node.latitude), Number(trip.destination_node.longitude)],
        targetLabel: trip.destination_node.name,
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
