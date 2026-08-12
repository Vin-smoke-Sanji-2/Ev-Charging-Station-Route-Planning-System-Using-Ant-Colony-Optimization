import L from 'leaflet';
import markerIcon2x from 'leaflet/dist/images/marker-icon-2x.png';
import markerIcon from 'leaflet/dist/images/marker-icon.png';
import markerShadow from 'leaflet/dist/images/marker-shadow.png';
import { apiFetch } from './api.js';
import { triggerPulse } from './animate.js';

// Vite bundles Leaflet's default marker images under hashed filenames,
// which breaks Leaflet's built-in relative-path lookup. Point it at the
// bundled URLs explicitly. Any page importing this module gets the fix,
// so trip-show.js and stations-show.js don't each need their own copy.
delete L.Icon.Default.prototype._getIconUrl;
L.Icon.Default.mergeOptions({
    iconRetinaUrl: markerIcon2x,
    iconUrl: markerIcon,
    shadowUrl: markerShadow,
});

const EARTH_RADIUS_KM = 6371;

// How often GET /api/navigate/route can be called while tracking is active -
// the live position dot still updates every single watchPosition tick
// (unchanged), but re-fetching the real road route on every tick would
// hammer the backend for no visible benefit between fixes a few meters
// apart. 12s sits in the middle of the suggested 10-15s range.
const ROUTE_FETCH_THROTTLE_MS = 12000;

export function haversineDistanceKm(lat1, lon1, lat2, lon2) {
    const toRad = (deg) => (deg * Math.PI) / 180;
    const dLat = toRad(lat2 - lat1);
    const dLon = toRad(lon2 - lon1);
    const a = Math.sin(dLat / 2) ** 2
        + Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLon / 2) ** 2;
    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));

    return EARTH_RADIUS_KM * c;
}

// Shared with live-trip.js (imported from there, not redefined) - both
// features render the same concept, "your live position," and must look
// identical wherever they appear: a pulsing dot (.live-position-marker/
// live-position-pulse in app.css), not a plain static circle. This used
// to be Navigate's own separately-defined, non-pulsing userLocationIcon()
// back when Navigate was still a one-shot getCurrentPosition() snapshot -
// now that it's continuous watchPosition tracking too, there's no reason
// for it to look different from Live Trip's marker.
export function livePositionIcon() {
    return L.divIcon({
        className: 'live-position-marker-wrap',
        html: '<span class="live-position-marker"></span>',
        iconSize: [20, 20],
        iconAnchor: [10, 10],
    });
}

export function geolocationErrorMessage(error) {
    switch (error.code) {
        case error.PERMISSION_DENIED:
            return 'Location access needed to show distance - please allow location access in your browser and try again.';
        case error.POSITION_UNAVAILABLE:
            return "Your location couldn't be determined right now. Please try again.";
        case error.TIMEOUT:
            return 'Location request timed out. Please try again.';
        default:
            return "Location access needed to show distance - we couldn't determine your location.";
    }
}

/**
 * Wires a "Navigate" toggle button to continuously track the user's real
 * position (via watchPosition, the same continuous-tracking API Live Trip
 * uses - not a single getCurrentPosition() snapshot) on an already-created
 * Leaflet map: a live marker that moves as new positions arrive, a real
 * road-following route to `target` (upgraded from an instant dashed
 * straight-line fallback once GET /api/navigate/route responds - see
 * renderFallbackLine()/renderRealRoute() below), and a live-updating
 * distance - and never any other marker (charging stops, etc.) even when
 * the underlying map already has a full multi-stop route drawn on it -
 * this overlay only ever knows about the user's position and the one
 * `target`/`targetNodeId`.
 *
 * Purely client-side and ephemeral: nothing here writes anything back to
 * the backend - GET /api/navigate/route is a read-only lookup, unlike Live
 * Trip's real start/active/complete lifecycle. Closing or navigating away
 * from the page is itself enough to stop tracking, since watchPosition's
 * native watch dies with the page (this project is multi-page/full-reload,
 * not an SPA, so there's no separate "unmount" step that could leak a
 * watch across page loads). A `pagehide` listener is still added
 * defensively, so a click that toggles tracking OFF and a navigation away
 * both go through the exact same cleanup path.
 *
 * Clicking again while tracking is active toggles it off: clears the
 * watch, removes the marker/lines, and resets the button/status.
 *
 * @param {object} options
 * @param {L.Map} options.map - an already-initialized Leaflet map instance
 * @param {string} options.buttonId
 * @param {string} options.statusId
 * @param {[number, number]} options.target - [lat, lng] of the destination
 * @param {string} options.targetLabel - human-readable name shown in the status text
 * @param {number|null} [options.targetNodeId] - the destination's real
 *   road_nodes.id (a station's own `road_node_id`, or a trip's
 *   `destination_node.id`) - enables the real road-route upgrade. When
 *   omitted/null, Navigate stays on the straight-line-only behavior
 *   forever (no endpoint calls), rather than erroring.
 */
export function attachNavigate({ map, buttonId, statusId, target, targetLabel, targetNodeId = null }) {
    const button = document.getElementById(buttonId);
    const statusEl = document.getElementById(statusId);
    if (!button || !statusEl) return;

    const idleButtonHtml = button.innerHTML;
    const trackingButtonHtml = button.innerHTML.replace(/Navigate/i, 'Stop Navigating');

    let userMarker = null;
    let distanceLine = null; // straight-line fallback - removed for good once real road data first arrives
    let bridgeLine = null; // dashed: live position -> nearest road_node
    let roadLine = null; // solid: real road-following path, nearest road_node -> target
    let watchId = null;
    // fitBounds runs once per toggle-on, when the first position of that
    // session arrives - not on every subsequent update, or the map would
    // jarringly re-center/re-zoom on every real GPS wobble.
    let hasFitBounds = false;
    let hasRealRoute = false; // true once the first successful road_geometry has rendered
    let fetchingRoute = false;
    let lastRouteFetchAt = 0;

    function clearOverlay() {
        [userMarker, distanceLine, bridgeLine, roadLine].forEach((layer) => {
            if (layer) map.removeLayer(layer);
        });
        userMarker = null;
        distanceLine = null;
        bridgeLine = null;
        roadLine = null;
    }

    function stopTracking() {
        if (watchId !== null) {
            navigator.geolocation.clearWatch(watchId);
            watchId = null;
        }
        clearOverlay();
        button.innerHTML = idleButtonHtml;
        button.disabled = false;
        statusEl.classList.add('d-none');
    }

    function renderFallbackLine(userLatLng) {
        if (distanceLine) {
            distanceLine.setLatLngs([userLatLng, target]);
        } else {
            distanceLine = L.polyline([userLatLng, target], {
                color: '#2563eb',
                weight: 3,
                dashArray: '4 6',
            }).addTo(map);
        }
    }

    // Called once GET /api/navigate/route resolves with a genuine
    // road_geometry - upgrades the straight-line fallback into two
    // visually distinct real segments: a short dashed "bridge" (same
    // style the fallback line always used) from the live position to the
    // nearest road_node, and a solid real road-following path from there
    // to the target. Deliberately kept in Navigate's own blue rather than
    // the full route line's navy, even on a trip page where that solid
    // navy route is already on the map underneath - Navigate's overlay
    // has always used its own distinct color specifically so the two
    // don't read as the same kind of line (see the original 2026-08-06
    // design), and that still matters now that both are solid.
    function renderRealRoute(data) {
        if (distanceLine) {
            map.removeLayer(distanceLine);
            distanceLine = null;
        }

        if (bridgeLine) {
            bridgeLine.setLatLngs(data.bridge_geometry);
        } else {
            bridgeLine = L.polyline(data.bridge_geometry, {
                color: '#2563eb',
                weight: 3,
                dashArray: '4 6',
            }).addTo(map);
        }

        if (roadLine) {
            roadLine.setLatLngs(data.road_geometry);
        } else {
            roadLine = L.polyline(data.road_geometry, {
                color: '#2563eb',
                weight: 4,
            }).addTo(map);
        }

        hasRealRoute = true;
    }

    // Throttled to ROUTE_FETCH_THROTTLE_MS - called on every tick but only
    // actually fetches when enough time has passed since the last call
    // (always true for the very first tick of a toggle-on, so the upgrade
    // starts as soon as possible without waiting out a full throttle
    // window first). A genuinely unreachable target (road_geometry: null)
    // or a transient network failure both just leave whatever's already
    // rendered alone - never a regression back to a worse state, and
    // never a crash.
    function maybeFetchRoadRoute(userLatLng) {
        if (!targetNodeId || fetchingRoute) return;
        if (Date.now() - lastRouteFetchAt < ROUTE_FETCH_THROTTLE_MS) return;

        fetchingRoute = true;
        lastRouteFetchAt = Date.now();

        apiFetch(`/api/navigate/route?lat=${userLatLng[0]}&lng=${userLatLng[1]}&target_node_id=${targetNodeId}`)
            .then((response) => (response.ok ? response.json() : null))
            .then((data) => {
                if (!data || !Array.isArray(data.road_geometry) || data.road_geometry.length === 0) {
                    return;
                }

                renderRealRoute(data);

                const totalKm = Number(data.total_distance_km);
                statusEl.classList.remove('d-none', 'text-danger');
                // Honest about precision: only the road_distance_km portion
                // is a real routed distance - the bridge is still a raw
                // straight-line gap out to the nearest mapped road, same as
                // the fallback always was, just much shorter now.
                statusEl.textContent = `~${totalKm.toFixed(1)} km away from ${targetLabel} `
                    + '(real road distance, plus a short straight-line gap to reach the road network)';
            })
            .catch(() => {
                // A network hiccup shouldn't disrupt live tracking - just
                // try again on the next throttled tick.
            })
            .finally(() => {
                fetchingRoute = false;
            });
    }

    function handlePosition(position) {
        const userLatLng = [position.coords.latitude, position.coords.longitude];

        // The live position dot updates on every single tick, unconditionally
        // - this is the one thing that must never regress, since it's what
        // a user actually watches move in real time. Everything else below
        // (the route lines, the distance text) is allowed to lag behind by
        // up to a throttle window; the dot never does.
        if (userMarker) {
            userMarker.setLatLng(userLatLng);
        } else {
            userMarker = L.marker(userLatLng, { icon: livePositionIcon() })
                .addTo(map)
                .bindPopup('Your location')
                .openPopup();
        }

        if (!hasRealRoute) {
            // Fast first paint: the dashed straight line renders instantly,
            // never waiting on the network call below - maybeFetchRoadRoute()
            // upgrades this to the real bridge+road rendering once (and if)
            // it resolves. Once upgraded, the fallback line is retired for
            // good (see renderRealRoute()) - this branch simply stops
            // touching it rather than keeping it in sync underneath the
            // real lines.
            renderFallbackLine(userLatLng);

            const distanceKm = haversineDistanceKm(userLatLng[0], userLatLng[1], target[0], target[1]);
            statusEl.classList.remove('d-none', 'text-danger');
            statusEl.textContent = `~${distanceKm.toFixed(1)} km away from ${targetLabel} (straight-line distance)`;
        }

        // Zoom/pan to show the user and the target together exactly once
        // per toggle-on - see Part 1 of the fix this was built for: a trip
        // page's map starts fitted to the WHOLE route (possibly country-
        // scale), so without this the user+target pair could stay lost
        // inside that much wider view instead of getting its own readable
        // close-up, the same readable view Station Detail's single-marker
        // map already gave Navigate for free.
        if (!hasFitBounds) {
            map.fitBounds([userLatLng, target], { padding: [40, 40] });
            hasFitBounds = true;
        }

        button.disabled = false;
        button.innerHTML = trackingButtonHtml;

        maybeFetchRoadRoute(userLatLng);
    }

    function handleError(error) {
        // PERMISSION_DENIED is permanent for this watch - watchPosition()
        // already returned a real (now-useless) watchId synchronously
        // before this fired, so without resetting it the button would
        // think tracking is still "on" and require a stop-then-start
        // click pair to retry. POSITION_UNAVAILABLE/TIMEOUT are transient
        // (watchPosition keeps retrying on its own, same as Live Trip's
        // identical error handling), so those intentionally leave the
        // watch running rather than tearing it down - only the button's
        // disabled state needs resetting so the user isn't stuck waiting.
        if (error.code === error.PERMISSION_DENIED) {
            stopTracking();
        } else {
            button.disabled = false;
        }

        statusEl.classList.remove('d-none');
        statusEl.classList.add('text-danger');
        statusEl.textContent = geolocationErrorMessage(error);
    }

    function startTracking() {
        if (!('geolocation' in navigator)) {
            statusEl.classList.remove('d-none');
            statusEl.classList.add('text-danger');
            statusEl.textContent = "Location access needed to show distance - your browser doesn't support it.";
            return;
        }

        hasFitBounds = false;
        hasRealRoute = false;
        fetchingRoute = false;
        lastRouteFetchAt = 0;
        button.disabled = true;
        statusEl.classList.remove('d-none', 'text-danger');
        statusEl.textContent = 'Locating you...';

        watchId = navigator.geolocation.watchPosition(
            handlePosition,
            handleError,
            { enableHighAccuracy: true, maximumAge: 5000, timeout: 15000 },
        );
    }

    button.addEventListener('click', () => {
        triggerPulse(button);

        if (watchId !== null) {
            stopTracking();
        } else {
            startTracking();
        }
    });

    // Defensive, not load-bearing in this multi-page app (see docblock) -
    // routes any page-teardown path through the same cleanup as a manual
    // toggle-off, rather than relying solely on the browser's own implicit
    // watch-dies-with-the-page behavior.
    window.addEventListener('pagehide', () => {
        if (watchId !== null) navigator.geolocation.clearWatch(watchId);
    });
}
