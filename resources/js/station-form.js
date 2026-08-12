import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
// Importing navigate.js (for its export below) also runs its module-level
// Leaflet default-marker-icon fix as a side effect - the same fix every
// other Leaflet-using page in this app relies on, not re-implemented here.
import { geolocationErrorMessage } from './navigate.js';
import { MYANMAR_BOUNDS, MYANMAR_CENTER, MYANMAR_DEFAULT_ZOOM, MYANMAR_MIN_ZOOM } from './map-bounds.js';

/**
 * The shared "station location + basic info" form building block - built
 * originally for station-owner registration, now also used by "Add
 * Another Station" on My Stations, so both flows share one real
 * implementation instead of two copies of the same map-picker/validation
 * logic. Always paired with the `partials.station-form-fields` Blade
 * partial, whose field ids/names this module's functions look up
 * directly (station_name, station_latitude, station-picker-map, etc.) -
 * the two are not meant to be used independently.
 */

/**
 * Click-anywhere-to-place-pin map picker for the station's own location -
 * required before submission, with no default/fallback coordinate, the
 * same "block submission until a real selection exists" pattern already
 * established for Plan Trip's city autocomplete (see plan-trip.js).
 *
 * @param {string} [mapElementId] - defaults to 'station-picker-map', the
 *   partial's own id - only ever overridden if a page embeds more than
 *   one picker at once (not currently the case anywhere).
 */
export function setupLocationPicker(mapElementId = 'station-picker-map') {
    const latInput = document.getElementById('station_latitude');
    const lngInput = document.getElementById('station_longitude');
    const hint = document.getElementById('location-hint');
    const coordsLabel = document.getElementById('selected-coordinates');

    const map = L.map(mapElementId, {
        maxBounds: MYANMAR_BOUNDS,
        minZoom: MYANMAR_MIN_ZOOM,
    }).setView(MYANMAR_CENTER, MYANMAR_DEFAULT_ZOOM);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors',
        maxZoom: 19,
    }).addTo(map);

    let marker = null;

    function placePin(latLng) {
        latInput.value = latLng.lat;
        lngInput.value = latLng.lng;
        coordsLabel.textContent = `${latLng.lat.toFixed(5)}, ${latLng.lng.toFixed(5)}`;
        hint.classList.add('d-none');

        if (marker) {
            marker.setLatLng(latLng);
        } else {
            marker = L.marker(latLng, { draggable: true }).addTo(map);
            marker.on('dragend', () => placePin(marker.getLatLng()));
        }
    }

    map.on('click', (event) => placePin(event.latlng));

    return {
        map,
        hasSelection: () => Boolean(latInput.value && lngInput.value),
        showInvalidHint: () => hint.classList.remove('d-none'),
        setPin: (lat, lng) => {
            const latLng = L.latLng(lat, lng);
            map.setView(latLng, 15);
            placePin(latLng);
        },
        /** Used when reopening an already-placed pin (e.g. a fresh "Add
         *  Another Station" modal open) needs to reset to no-selection. */
        reset: () => {
            latInput.value = '';
            lngInput.value = '';
            coordsLabel.textContent = 'none yet';
            hint.classList.add('d-none');
            if (marker) {
                map.removeLayer(marker);
                marker = null;
            }
            map.setView(MYANMAR_CENTER, MYANMAR_DEFAULT_ZOOM);
        },
        /** Leaflet needs a real, visible container to measure - call this
         *  right after a modal's 'shown.bs.modal' event, since a map
         *  created while its container was display:none renders blank. */
        invalidateSize: () => map.invalidateSize(),
    };
}

/** Convenience only - re-centers the map and suggests a starting pin; the user can still drag it elsewhere afterward. */
export function setupUseMyLocation(picker) {
    const button = document.getElementById('use-my-location-btn');
    const statusEl = document.getElementById('location-status');

    button.addEventListener('click', () => {
        if (!('geolocation' in navigator)) {
            statusEl.classList.remove('d-none');
            statusEl.classList.add('text-danger');
            statusEl.textContent = "Location access needed - your browser doesn't support it.";
            return;
        }

        button.disabled = true;
        statusEl.classList.remove('d-none', 'text-danger');
        statusEl.textContent = 'Locating you...';

        navigator.geolocation.getCurrentPosition(
            (position) => {
                picker.setPin(position.coords.latitude, position.coords.longitude);
                statusEl.textContent = 'Using your location as a starting point - drag the pin to adjust it.';
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

/**
 * Reads the partial's station fields into the exact flat shape
 * ChargingStationController::store()/update() both validate
 * (name/latitude/longitude/address/township/charging_speed/
 * operating_hours) - callers that need it nested under a "station" key
 * (station-owner registration's combined payload) wrap this result
 * themselves rather than this function knowing about that shape.
 */
export function collectStationFields(form) {
    return {
        name: form.station_name.value,
        latitude: form.station_latitude.value,
        longitude: form.station_longitude.value,
        address: form.station_address.value || null,
        township: form.station_township.value || null,
        charging_speed: form.station_charging_speed.value || null,
        operating_hours: form.station_operating_hours.value || null,
    };
}

/**
 * Applies one field-level validation error to the partial's markup. The
 * partial's own data-error-for attributes are flat (name/latitude/
 * address/...) regardless of caller, since POST /api/stations itself
 * returns flat error keys - a caller whose own endpoint nests these
 * under "station.xxx" (station-owner registration) strips that prefix
 * before calling this, rather than the partial needing two id schemes.
 */
export function applyStationFieldError(form, field, message) {
    const input = document.getElementById(`station_${field}`);
    const feedback = form.querySelector(`[data-error-for="${field}"]`);
    if (input) input.classList.add('is-invalid');
    if (feedback) feedback.textContent = message;
}

export function clearStationFieldErrors(form) {
    form.querySelectorAll('[id^="station_"]').forEach((el) => el.classList.remove('is-invalid'));
    form.querySelectorAll('[data-error-for]').forEach((el) => (el.textContent = ''));
}
