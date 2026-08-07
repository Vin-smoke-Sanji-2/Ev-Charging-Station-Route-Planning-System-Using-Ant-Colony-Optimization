import L from 'leaflet';
import markerIcon2x from 'leaflet/dist/images/marker-icon-2x.png';
import markerIcon from 'leaflet/dist/images/marker-icon.png';
import markerShadow from 'leaflet/dist/images/marker-shadow.png';

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

export function haversineDistanceKm(lat1, lon1, lat2, lon2) {
    const toRad = (deg) => (deg * Math.PI) / 180;
    const dLat = toRad(lat2 - lat1);
    const dLon = toRad(lon2 - lon1);
    const a = Math.sin(dLat / 2) ** 2
        + Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLon / 2) ** 2;
    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));

    return EARTH_RADIUS_KM * c;
}

function userLocationIcon() {
    return L.divIcon({
        className: 'user-location-marker',
        html: '<span style="display:block;width:14px;height:14px;border-radius:50%;'
            + 'background:#2563eb;border:2px solid #fff;box-shadow:0 0 3px rgba(0,0,0,0.6);"></span>',
        iconSize: [14, 14],
        iconAnchor: [7, 7],
        popupAnchor: [0, -8],
    });
}

function geolocationErrorMessage(error) {
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
 * Wires a "Navigate" button to request the user's location, drop a marker
 * for it on an already-created Leaflet map, draw a straight line to
 * `target`, fit the map to both points, and show the haversine
 * straight-line distance - never a real driving distance/route, since
 * this project has no road routing yet.
 *
 * @param {object} options
 * @param {L.Map} options.map - an already-initialized Leaflet map instance
 * @param {string} options.buttonId
 * @param {string} options.statusId
 * @param {[number, number]} options.target - [lat, lng] of the destination
 * @param {string} options.targetLabel - human-readable name shown in the status text
 */
export function attachNavigate({ map, buttonId, statusId, target, targetLabel }) {
    const button = document.getElementById(buttonId);
    const statusEl = document.getElementById(statusId);
    if (!button || !statusEl) return;

    let userMarker = null;
    let distanceLine = null;

    button.addEventListener('click', () => {
        if (!('geolocation' in navigator)) {
            statusEl.classList.remove('d-none');
            statusEl.classList.add('text-danger');
            statusEl.textContent = "Location access needed to show distance - your browser doesn't support it.";
            return;
        }

        button.disabled = true;
        statusEl.classList.remove('d-none', 'text-danger');
        statusEl.textContent = 'Locating you...';

        navigator.geolocation.getCurrentPosition(
            (position) => {
                const userLatLng = [position.coords.latitude, position.coords.longitude];
                const distanceKm = haversineDistanceKm(userLatLng[0], userLatLng[1], target[0], target[1]);

                if (userMarker) map.removeLayer(userMarker);
                if (distanceLine) map.removeLayer(distanceLine);

                userMarker = L.marker(userLatLng, { icon: userLocationIcon() })
                    .addTo(map)
                    .bindPopup('Your location')
                    .openPopup();

                distanceLine = L.polyline([userLatLng, target], {
                    color: '#2563eb',
                    weight: 3,
                    dashArray: '4 6',
                }).addTo(map);

                map.fitBounds([userLatLng, target], { padding: [40, 40] });

                statusEl.classList.remove('text-danger');
                statusEl.textContent = `~${distanceKm.toFixed(1)} km away from ${targetLabel} (straight-line distance)`;
                button.disabled = false;
            },
            (error) => {
                statusEl.classList.add('text-danger');
                statusEl.textContent = geolocationErrorMessage(error);
                button.disabled = false;
            },
            { enableHighAccuracy: true, timeout: 10000 },
        );
    });
}
