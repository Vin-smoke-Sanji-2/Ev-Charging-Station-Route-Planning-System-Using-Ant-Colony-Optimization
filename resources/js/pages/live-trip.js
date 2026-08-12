import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { apiFetch } from '../api.js';
import { haversineDistanceKm, livePositionIcon } from '../navigate.js';
import { attachRecalculate } from '../recalculate.js';
import { renderRouteMap, renderStopListItem } from '../route-map.js';

// A recalculation replaces the active route mid-trip (new stops, possibly a
// new geometry) - loadActiveTrip() must be safely re-runnable, so the watch
// and map it creates are tracked here and torn down at the start of each run
// rather than left to accumulate across reloads.
let watchId = null;
let currentMap = null;

// A stop/destination counts as "reached" once the live position comes
// within this radius - must stay well under the tightest real station
// cluster in the seed data (the two "357 Miles" companies, 131.2m apart)
// while remaining above typical GPS noise, so 40m was chosen over the
// earlier 150m, which would have false-triggered on that cluster.
const GEOFENCE_RADIUS_KM = 0.04; // 40m

function geolocationErrorMessage(error) {
    switch (error.code) {
        case error.PERMISSION_DENIED:
            return 'Location access is required for Live Trip to track your progress - please allow location access and retry.';
        case error.POSITION_UNAVAILABLE:
            return "Your location couldn't be determined right now. Still trying...";
        case error.TIMEOUT:
            return 'Location request timed out. Still trying...';
        default:
            return 'Location access is required for Live Trip - please allow location access and retry.';
    }
}

// Always resolves to exactly ONE target - the first stop that hasn't been
// reached yet, or the destination once every stop has. Deliberately never
// checks against all stops at once: several real stations in this
// project's seed data sit within a few hundred meters of each other (the
// "115 Miles" rest camp, 3 separate companies), so checking only the one
// genuinely-next target avoids ever mistaking a nearby, unrelated station
// for the real next stop.
function nextTarget(trip, stops) {
    const nextStop = stops.find((stop) => !stop.reached_at);

    if (nextStop && nextStop.station) {
        return {
            kind: 'stop',
            stopId: nextStop.id,
            label: nextStop.station.name,
            latLng: [Number(nextStop.station.latitude), Number(nextStop.station.longitude)],
        };
    }

    return {
        kind: 'destination',
        label: trip.destination_node.name,
        latLng: [Number(trip.destination_node.latitude), Number(trip.destination_node.longitude)],
    };
}

function renderProgress(trip, stops) {
    const reachedCount = stops.filter((stop) => stop.reached_at).length;

    document.getElementById('live-trip-progress-text').textContent = stops.length > 0
        ? `${reachedCount} of ${stops.length} charging stop${stops.length === 1 ? '' : 's'} reached`
        : 'No charging stops on this route';

    const target = nextTarget(trip, stops);
    document.getElementById('live-trip-next-target').textContent = target.kind === 'stop'
        ? `Next stop: ${target.label}`
        : `Final destination: ${target.label}`;

    // Manual "Complete Trip" fallback (see setupCompleteTrip()) only makes
    // sense once every real charging stop has actually been reached -
    // nextTarget() only ever returns 'destination' once that's true, so
    // this reuses that same check rather than re-deriving it separately.
    // Showing it earlier would let a user skip stops they were actually
    // supposed to charge at.
    const completeBtn = document.getElementById('complete-trip-btn');
    if (completeBtn) {
        completeBtn.classList.toggle('d-none', target.kind !== 'destination');
    }
}

function renderStopsList(stops) {
    const list = document.getElementById('live-stops-list');
    const empty = document.getElementById('live-stops-empty');

    if (stops.length === 0) {
        list.innerHTML = '';
        empty.classList.remove('d-none');
        return;
    }

    empty.classList.add('d-none');
    list.innerHTML = stops.map((stop) => renderStopListItem(stop, { showReachedState: true })).join('');
}

function showCompletedState() {
    document.getElementById('live-trip-content').classList.add('d-none');
    document.getElementById('live-trip-completed').classList.remove('d-none');
}

function showCancelledState() {
    document.getElementById('live-trip-content').classList.add('d-none');
    document.getElementById('live-trip-cancelled').classList.remove('d-none');
}

// cancel-trip-btn is re-attached on every loadActiveTrip() reload (a
// recalculation reloads the whole page state) - clone-replacing it first
// strips any listener from a previous run, same pattern as the retry
// button in startTracking() below.
function setupCancelTrip(tripId) {
    const oldBtn = document.getElementById('cancel-trip-btn');
    const btn = oldBtn.cloneNode(true);
    oldBtn.replaceWith(btn);

    const errorBox = document.getElementById('cancel-trip-error');

    btn.addEventListener('click', async () => {
        if (!confirm('Cancel this trip? This cannot be undone.')) return;

        btn.disabled = true;
        errorBox.classList.add('d-none');

        const response = await apiFetch(`/api/trips/${tripId}/cancel`, { method: 'POST' });

        if (response.ok) {
            if (watchId !== null) {
                navigator.geolocation.clearWatch(watchId);
                watchId = null;
            }
            showCancelledState();
            return;
        }

        const data = await response.json().catch(() => ({}));
        errorBox.textContent = data.message || 'Unable to cancel this trip. Please try again.';
        errorBox.classList.remove('d-none');
        btn.disabled = false;
    });
}

// Manual fallback for the exact same reason Cancel Trip exists: automatic
// destination-arrival detection depends entirely on this page staying open
// with GPS tracking active until the real position crosses the geofence -
// if that never happens (permission lost, browser/OS backgrounds the tab,
// the user just isn't confident the automatic check will fire), there was
// otherwise no way to finish a trip whose stops are all genuinely reached.
// Calls the same POST /api/trips/{trip}/complete the automatic path uses -
// no separate endpoint, no different end state. Visibility is gated by
// renderProgress() (only shown once every stop's reached_at is set), and
// this button is re-attached on every loadActiveTrip() reload just like
// cancel-trip-btn, for the same duplicate-listener reason.
function setupCompleteTrip(tripId) {
    const oldBtn = document.getElementById('complete-trip-btn');
    const btn = oldBtn.cloneNode(true);
    oldBtn.replaceWith(btn);

    const errorBox = document.getElementById('complete-trip-error');

    btn.addEventListener('click', async () => {
        if (!confirm('Mark this trip as complete? Only do this once you have genuinely arrived at your destination.')) return;

        btn.disabled = true;
        errorBox.classList.add('d-none');

        const response = await apiFetch(`/api/trips/${tripId}/complete`, { method: 'POST' });

        if (response.ok) {
            if (watchId !== null) {
                navigator.geolocation.clearWatch(watchId);
                watchId = null;
            }
            showCompletedState();
            return;
        }

        const data = await response.json().catch(() => ({}));
        errorBox.textContent = data.message || 'Unable to complete this trip. Please try again.';
        errorBox.classList.remove('d-none');
        btn.disabled = false;
    });
}

function startTracking(trip, initialStops, map) {
    const tripId = trip.id;
    let stops = initialStops;
    let liveMarker = null;
    let processing = false; // a reached/complete call is already in flight
    // watchId is the module-level variable, not a local one, so a
    // recalculation-triggered reload can clear this exact watch before
    // starting a fresh one.

    const statusEl = document.getElementById('geolocation-status');
    const retryBtn = document.getElementById('geolocation-retry-btn');

    function showStatus(message, isError, showRetry) {
        statusEl.classList.remove('d-none');
        statusEl.classList.toggle('text-danger', Boolean(isError));
        statusEl.textContent = message;
        retryBtn.classList.toggle('d-none', !showRetry);
    }

    async function refreshStopsFromServer() {
        const response = await apiFetch('/api/trips/active');
        const freshTrip = response.ok ? await response.json() : null;

        if (!freshTrip) return null; // trip just completed and is no longer "active"

        const freshRoute = freshTrip.routes[freshTrip.routes.length - 1];
        stops = freshRoute.charging_stops ?? [];
        renderProgress(trip, stops);
        renderStopsList(stops);

        return freshTrip;
    }

    async function handlePosition(position) {
        const userLatLng = [position.coords.latitude, position.coords.longitude];

        if (liveMarker) {
            liveMarker.setLatLng(userLatLng);
        } else {
            liveMarker = L.marker(userLatLng, { icon: livePositionIcon() }).addTo(map).bindPopup('You are here');
        }

        showStatus('Tracking your location...', false, false);

        if (processing) return; // let the in-flight call finish before checking again

        const target = nextTarget(trip, stops);
        const distanceKm = haversineDistanceKm(userLatLng[0], userLatLng[1], target.latLng[0], target.latLng[1]);

        if (distanceKm > GEOFENCE_RADIUS_KM) return;

        processing = true;

        try {
            if (target.kind === 'stop') {
                const response = await apiFetch(`/api/trips/${tripId}/stops/${target.stopId}/reached`, { method: 'POST' });
                if (response.ok) {
                    await refreshStopsFromServer();
                }
            } else {
                const response = await apiFetch(`/api/trips/${tripId}/complete`, { method: 'POST' });
                if (response.ok) {
                    if (watchId !== null) navigator.geolocation.clearWatch(watchId);
                    showCompletedState();
                    return;
                }
            }
        } finally {
            processing = false;
        }
    }

    function startWatching() {
        if (!('geolocation' in navigator)) {
            showStatus("Live Trip needs your location, but your browser doesn't support it.", true, false);
            return;
        }

        showStatus('Requesting your location...', false, false);

        watchId = navigator.geolocation.watchPosition(
            handlePosition,
            (error) => showStatus(geolocationErrorMessage(error), true, error.code === error.PERMISSION_DENIED),
            { enableHighAccuracy: true, maximumAge: 5000, timeout: 15000 },
        );
    }

    // retryBtn is a persistent element re-used across loadActiveTrip()
    // reloads (a recalculation reloads the whole page state) - clone-
    // replacing it before attaching strips any listener from a previous run
    // so retries don't fire startWatching() multiple times over each other.
    const oldRetryBtn = retryBtn;
    const freshRetryBtn = oldRetryBtn.cloneNode(true);
    oldRetryBtn.replaceWith(freshRetryBtn);
    freshRetryBtn.addEventListener('click', () => {
        if (watchId !== null) navigator.geolocation.clearWatch(watchId);
        freshRetryBtn.classList.add('d-none');
        startWatching();
    });

    startWatching();
}

async function loadActiveTrip() {
    const loading = document.getElementById('live-trip-loading');
    const empty = document.getElementById('live-trip-empty');
    const content = document.getElementById('live-trip-content');

    // A recalculation calls this again mid-trip - tear down the previous
    // watch/map before rebuilding, rather than letting them accumulate.
    if (watchId !== null) {
        navigator.geolocation.clearWatch(watchId);
        watchId = null;
    }

    const response = await apiFetch('/api/trips/active');
    const trip = response.ok ? await response.json() : null;

    loading.classList.add('d-none');

    if (!trip) {
        empty.classList.remove('d-none');
        content.classList.add('d-none');
        return;
    }

    const route = trip.routes[trip.routes.length - 1];
    const stops = route.charging_stops ?? [];

    document.getElementById('live-trip-origin').textContent = trip.origin_node.name;
    document.getElementById('live-trip-destination').textContent = trip.destination_node.name;

    const vehicleLabel = trip.vehicle?.ev_model
        ? `${trip.vehicle.ev_model.brand} ${trip.vehicle.ev_model.model}`
        : 'your vehicle';
    document.getElementById('live-trip-meta').textContent = `${vehicleLabel} - trip in progress`;

    renderProgress(trip, stops);
    renderStopsList(stops);

    content.classList.remove('d-none');

    if (currentMap) {
        currentMap.remove();
        currentMap = null;
    }

    // The map container must be visible (not display:none) before Leaflet
    // measures it, so this runs after the d-none class is removed above -
    // same gotcha as Trip Result's map.
    currentMap = renderRouteMap('live-trip-map', trip.origin_node, trip.destination_node, stops, trip.route_geometry);

    attachRecalculate({
        tripId: trip.id,
        defaultBatteryPercent: trip.battery_percent,
        onSuccess: () => loadActiveTrip(),
    });

    setupCancelTrip(trip.id);
    setupCompleteTrip(trip.id);

    startTracking(trip, stops, currentMap);
}

document.addEventListener('DOMContentLoaded', () => {
    const container = document.getElementById('live-trip-app');
    if (!container) return;

    loadActiveTrip().catch(() => {
        document.getElementById('live-trip-loading').classList.add('d-none');
        document.getElementById('live-trip-empty').classList.remove('d-none');
    });
});
