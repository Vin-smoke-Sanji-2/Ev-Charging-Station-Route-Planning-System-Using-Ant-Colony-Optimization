import L from 'leaflet';
import { MYANMAR_BOUNDS, MYANMAR_MIN_ZOOM } from './map-bounds.js';
import { categoryFor, markerIconFor } from './station-marker.js';

// Extracted from trip-show.js so Live Trip can render the exact same
// route (real road-following geometry + shared pin markers) instead of
// reimplementing it slightly differently.

// Origin/destination are plain city road_nodes, not real charging stations -
// categoryFor()'s DC/AC categorization doesn't apply to them, so they get
// the same pin glyph/rendering technique with fixed, sensible brand colors
// instead (navy "start", burgundy "destination"). Charging stops ARE real
// stations, so those get the exact same categorized pin Dashboard/Station
// Detail use - genuinely shared styling, not a lookalike.
const ORIGIN_MARKER_COLOR = '#2E3A59';
const DESTINATION_MARKER_COLOR = '#7A1E3C';

/**
 * Renders a trip's route onto a Leaflet map: origin/destination/charging-
 * stop markers, and the real road-following polyline (or a dashed
 * straight-line fallback when routeGeometry is null - see
 * RouteGeometryBuilder). Stops that already have a truthy `reached_at`
 * (Live Trip only - Trip Result's stops never have this set, since a
 * route that was never started has no reached stops) render at reduced
 * opacity so reached vs. not-yet-reached is visually obvious.
 *
 * @param {string} mapElementId - id of the container div for L.map()
 * @param {object} originNode
 * @param {object} destinationNode
 * @param {object[]} stops - each with a `station` object and optionally `reached_at`
 * @param {Array<[number, number]>|null} routeGeometry
 * @returns {L.Map}
 */
export function renderRouteMap(mapElementId, originNode, destinationNode, stops, routeGeometry) {
    const originLatLng = [Number(originNode.latitude), Number(originNode.longitude)];
    const destinationLatLng = [Number(destinationNode.latitude), Number(destinationNode.longitude)];
    const stopLatLngs = stops
        .filter((stop) => stop.station)
        .map((stop) => [Number(stop.station.latitude), Number(stop.station.longitude)]);
    const waypoints = [originLatLng, ...stopLatLngs, destinationLatLng];

    // route_geometry (computed server-side by RouteGeometryBuilder) is the
    // real road-following polyline - a real, curving road can extend
    // outside the straight-line bounding box of just the waypoints, so
    // fitting to the full geometry (when available) shows the whole route,
    // not just its endpoints with the middle potentially clipped.
    const hasRealGeometry = Array.isArray(routeGeometry) && routeGeometry.length > 0;
    const boundsPoints = hasRealGeometry ? routeGeometry : waypoints;

    // The map must settle on its final view BEFORE the tile layer is added.
    // An unset view followed by an animated fitBounds() thrashes through
    // every intermediate tile grid (see the original trip-show.js gotcha).
    const map = L.map(mapElementId, {
        maxBounds: MYANMAR_BOUNDS,
        minZoom: MYANMAR_MIN_ZOOM,
    });

    if (boundsPoints.length > 1) {
        map.fitBounds(boundsPoints, { padding: [30, 30], animate: false });
    } else {
        map.setView(waypoints[0], 7, { animate: false });
    }

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors',
        maxZoom: 19,
    }).addTo(map);

    L.marker(originLatLng, { icon: markerIconFor(ORIGIN_MARKER_COLOR) })
        .addTo(map).bindPopup(`Start: ${originNode.name}`);

    stops.forEach((stop, index) => {
        if (!stop.station) return;
        const latLng = [Number(stop.station.latitude), Number(stop.station.longitude)];
        const color = categoryFor(stop.station).color;
        const reached = Boolean(stop.reached_at);
        const marker = L.marker(latLng, { icon: markerIconFor(color) })
            .addTo(map)
            .bindPopup(`Stop ${index + 1}: ${stop.station.name}${reached ? ' (reached)' : ''}`);

        if (reached) {
            marker.setOpacity(0.45);
        }
    });

    L.marker(destinationLatLng, { icon: markerIconFor(DESTINATION_MARKER_COLOR) })
        .addTo(map).bindPopup(`Destination: ${destinationNode.name}`);

    if (hasRealGeometry) {
        // A real, road-following route - solid, not dashed, since dashed
        // specifically signaled "placeholder," which this no longer is.
        L.polyline(routeGeometry, { color: '#2E3A59', weight: 4 }).addTo(map);
    } else {
        // Defensive fallback only, not the default - route_geometry came
        // back null (should be essentially impossible given the graph's
        // confirmed full connectivity, but handled rather than assumed away).
        L.polyline(waypoints, { color: '#2E3A59', weight: 3, dashArray: '6 8' }).addTo(map);
    }

    return map;
}

// availableSlotsCount/totalSlotsCount are computed server-side via
// TripController's withStationAvailability() (the same withCount pattern
// ChargingStationController::index() already uses for Stations/Dashboard),
// eager-loaded onto each stop's station - not always present (e.g. a stop
// whose station data predates this, or any endpoint that doesn't load it),
// so this renders nothing rather than a misleading "undefined/undefined".
function availabilityText(station) {
    if (!station || station.available_slots_count === undefined || station.total_slots_count === undefined) {
        return '';
    }
    return `${station.available_slots_count}/${station.total_slots_count} available`;
}

/**
 * Renders one charging stop as a <li> for a trip's stop list - shared by
 * Trip Result and Live Trip so both pages show identical station name/
 * township/availability info instead of two separately-maintained
 * templates, with only the parts genuinely specific to each page
 * (Trip Result's wait-time badge; Live Trip's reached checkmark/muted
 * styling) toggled via options.
 *
 * @param {object} stop - a RouteChargingStop with a `station` and optionally `reached_at`
 * @param {object} [options]
 * @param {boolean} [options.showWaitTime] - Trip Result only: shows the
 *   estimated_wait_min badge (always 0 today - see AcoRouteEngine - but the
 *   field exists for a future queue-aware refinement).
 * @param {boolean} [options.showReachedState] - Live Trip only: shows a
 *   checkmark and mutes the row once `stop.reached_at` is truthy.
 * @param {boolean} [options.showStartChargingButton] - Live Trip only:
 *   renders a per-stop "Start Charging Here" control (see
 *   charging-session.js), so an EV owner can manually start a session at
 *   ANY stop on the route, in any order - deliberately independent of
 *   `reached_at`/sequence order. Skipped for a stop with no linked
 *   `station` (should be essentially impossible, but defensive).
 * @returns {string}
 */
export function renderStopListItem(stop, { showWaitTime = false, showReachedState = false, showStartChargingButton = false } = {}) {
    const station = stop.station;
    const reached = showReachedState && Boolean(stop.reached_at);

    const availability = availabilityText(station);
    const meta = [station?.township ?? '', availability].filter(Boolean).join(' · ');

    const waitBadge = showWaitTime
        ? `<span class="badge bg-brand rounded-pill">${
            stop.estimated_wait_min !== null && stop.estimated_wait_min !== undefined
                ? `~${stop.estimated_wait_min} min wait`
                : 'wait time unknown'
        }</span>`
        : '';

    const chargingControl = showStartChargingButton && station
        ? `
            <div class="charging-control mt-2 pt-2 border-top" data-role="stop-charging-control" data-station-id="${station.id}">
                <div class="text-muted small" data-role="loading">Checking your charging status...</div>
                <p class="mb-1 small d-none" data-role="status"></p>
                <button type="button" class="btn btn-primary btn-sm d-none" data-role="start-btn">
                    <i class="bi bi-lightning-charge-fill"></i> Start Charging Here
                </button>
                <div class="alert alert-danger py-1 small mt-1 mb-0 d-none" data-role="error" role="alert"></div>
            </div>
        `
        : '';

    return `
        <li class="list-group-item d-flex justify-content-between align-items-start flex-wrap${reached ? ' text-muted' : ''}">
            <div class="flex-grow-1">
                <div class="fw-semibold">
                    ${station ? station.name : 'Unknown station'}
                    ${reached ? '<i class="bi bi-check-circle-fill text-success ms-1"></i>' : ''}
                </div>
                <div class="text-muted small">${meta}</div>
                ${chargingControl}
            </div>
            ${waitBadge}
        </li>
    `;
}
