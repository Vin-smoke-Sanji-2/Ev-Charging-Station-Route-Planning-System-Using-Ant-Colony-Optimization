import { apiFetch } from '../api.js';
import { haversineDistanceKm, geolocationErrorMessage } from '../navigate.js';

function clearErrors(form, errorBox) {
    errorBox.classList.add('d-none');
    errorBox.textContent = '';
    form.querySelectorAll('.is-invalid').forEach((el) => el.classList.remove('is-invalid'));
    form.querySelectorAll('[data-error-for]').forEach((el) => (el.textContent = ''));
}

function showFieldErrors(form, errors) {
    Object.entries(errors).forEach(([field, messages]) => {
        const input = form.querySelector(`[name="${field}"]`);
        const feedback = form.querySelector(`[data-error-for="${field}"]`);
        if (input) input.classList.add('is-invalid');
        if (feedback) feedback.textContent = messages[0];
    });
}

async function loadVehicles(select, noVehiclesAlert, submitBtn) {
    const response = await apiFetch('/api/vehicles');
    const vehicles = response.ok ? await response.json() : [];

    if (vehicles.length === 0) {
        select.innerHTML = '<option value="">No vehicles available</option>';
        noVehiclesAlert.classList.remove('d-none');
        submitBtn.disabled = true;
        return false;
    }

    select.innerHTML = '<option value="">Select a vehicle</option>' + vehicles.map((vehicle) => {
        const model = vehicle.ev_model ? `${vehicle.ev_model.brand} ${vehicle.ev_model.model}` : 'Vehicle';
        const label = vehicle.plate_no ? `${model} - ${vehicle.plate_no}` : model;
        return `<option value="${vehicle.id}">${label}</option>`;
    }).join('');

    // Pre-select the user's default vehicle, if they have one - just the
    // starting selection, not enforced. The user can still freely change it,
    // and the submit handler reads whatever is selected at submit time.
    const defaultVehicle = vehicles.find((vehicle) => vehicle.is_default);
    if (defaultVehicle) {
        select.value = defaultVehicle.id;
    }

    return true;
}

function debounce(fn, delay) {
    let timer = null;
    return (...args) => {
        clearTimeout(timer);
        timer = setTimeout(() => fn(...args), delay);
    };
}

/**
 * Wires a city-autocomplete text input to the new
 * /api/road-nodes/city-suggestions endpoint - same debounce/dropdown/
 * Escape/click-outside/searchToken race-guard pattern as
 * stations-index.js's station search box, applied to city-only results.
 * The hidden node-id input is only ever set from a real suggestion click
 * (or the "Use My Location" snap below) - never from free-typed text, so a
 * stale/invalid id can't slip into a submission.
 */
function setupCityAutocomplete({ inputId, hiddenId, suggestionsId, wrapId, hintId, onSelect }) {
    const input = document.getElementById(inputId);
    const hidden = document.getElementById(hiddenId);
    const box = document.getElementById(suggestionsId);
    const wrap = document.getElementById(wrapId);
    const hint = hintId ? document.getElementById(hintId) : null;

    let searchToken = 0;

    function hideSuggestions() {
        searchToken += 1;
        box.classList.add('d-none');
        box.innerHTML = '';
    }

    function clearSelection() {
        hidden.value = '';
        if (hint) hint.classList.add('d-none');
        if (onSelect) onSelect(null);
    }

    function renderSuggestions(cities) {
        if (cities.length === 0) {
            hideSuggestions();
            return;
        }

        box.innerHTML = cities.map((city) => `
            <button type="button" class="list-group-item list-group-item-action suggestion-item"
                    data-id="${city.id}" data-name="${city.name}"
                    data-latitude="${city.latitude}" data-longitude="${city.longitude}">
                ${city.name}
            </button>
        `).join('');
        box.classList.remove('d-none');
    }

    async function fetchSuggestions(q) {
        const token = searchToken;
        const response = await apiFetch(`/api/road-nodes/city-suggestions?q=${encodeURIComponent(q)}`);
        const cities = response.ok ? await response.json() : [];

        if (token !== searchToken) return; // dropdown was closed or superseded while this request was in flight

        renderSuggestions(cities);
    }

    const debouncedSearch = debounce((q) => fetchSuggestions(q), 250);

    input.addEventListener('input', () => {
        clearSelection();
        const q = input.value.trim();

        if (q === '') {
            hideSuggestions();
            return;
        }

        searchToken += 1;
        debouncedSearch(q);
    });

    input.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            hideSuggestions();
        }
    });

    box.addEventListener('click', (event) => {
        const item = event.target.closest('.suggestion-item');
        if (!item) return;

        input.value = item.dataset.name;
        hidden.value = item.dataset.id;
        if (hint) hint.classList.add('d-none');
        hideSuggestions();

        if (onSelect) {
            onSelect({
                id: item.dataset.id,
                name: item.dataset.name,
                latitude: item.dataset.latitude,
                longitude: item.dataset.longitude,
            });
        }
    });

    document.addEventListener('click', (event) => {
        if (!wrap.contains(event.target)) {
            hideSuggestions();
        }
    });

    return {
        setValue(city) {
            input.value = city.name;
            hidden.value = city.id;
            if (hint) hint.classList.add('d-none');
            hideSuggestions();
            if (onSelect) onSelect(city);
        },
        showInvalidHint() {
            if (hint) hint.classList.remove('d-none');
        },
    };
}

async function loadAllCities() {
    const response = await apiFetch('/api/road-nodes');
    const nodes = response.ok ? await response.json() : [];
    return nodes.filter((node) => node.type === 'city');
}

function setupUseMyLocation(originAutocomplete) {
    const button = document.getElementById('use-my-location-btn');
    const statusEl = document.getElementById('origin-location-status');
    if (!button || !statusEl) return;

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
            async (position) => {
                const { latitude, longitude } = position.coords;
                const cities = await loadAllCities();

                if (cities.length === 0) {
                    statusEl.classList.add('text-danger');
                    statusEl.textContent = 'Could not determine nearby cities. Please try again.';
                    button.disabled = false;
                    return;
                }

                let nearest = null;
                let nearestDistance = Infinity;

                cities.forEach((city) => {
                    const distance = haversineDistanceKm(
                        latitude, longitude, Number(city.latitude), Number(city.longitude),
                    );
                    if (distance < nearestDistance) {
                        nearestDistance = distance;
                        nearest = city;
                    }
                });

                originAutocomplete.setValue(nearest);

                // setValue's onSelect callback hides this status box (it's
                // meant to clear a stale "using your location" message when
                // the user later types/picks a different city manually) -
                // re-show it now that we're about to write the real message.
                statusEl.classList.remove('d-none', 'text-danger');
                statusEl.textContent = `Using your location - nearest city: ${nearest.name}`;
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

document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('plan-trip-form');
    if (!form) return;

    const errorBox = document.getElementById('form-error');
    const noVehiclesAlert = document.getElementById('no-vehicles-alert');
    const submitBtn = document.getElementById('plan-trip-submit');
    const vehicleSelect = document.getElementById('vehicle_id');
    const originHidden = document.getElementById('origin_node_id');
    const destinationHidden = document.getElementById('destination_node_id');
    const originHint = document.getElementById('origin-hint');
    const destinationHint = document.getElementById('destination-hint');

    let vehiclesReady = false;

    loadVehicles(vehicleSelect, noVehiclesAlert, submitBtn).then((hasVehicles) => {
        vehiclesReady = hasVehicles;
        updateSubmitState();
    });

    function updateSubmitState() {
        // Only re-enable once both cities are genuinely selected - a
        // no-vehicles account stays disabled regardless (loadVehicles
        // already disabled it for that reason).
        submitBtn.disabled = !vehiclesReady || !originHidden.value || !destinationHidden.value;
    }

    const originAutocomplete = setupCityAutocomplete({
        inputId: 'origin_search',
        hiddenId: 'origin_node_id',
        suggestionsId: 'origin-suggestions',
        wrapId: 'origin-search-wrap',
        hintId: 'origin-hint',
        onSelect: () => {
            document.getElementById('origin-location-status').classList.add('d-none');
            updateSubmitState();
        },
    });

    setupCityAutocomplete({
        inputId: 'destination_search',
        hiddenId: 'destination_node_id',
        suggestionsId: 'destination-suggestions',
        wrapId: 'destination-search-wrap',
        hintId: 'destination-hint',
        onSelect: updateSubmitState,
    });

    setupUseMyLocation(originAutocomplete);
    updateSubmitState();

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearErrors(form, errorBox);

        let blocked = false;
        if (!originHidden.value) {
            originHint.classList.remove('d-none');
            blocked = true;
        }
        if (!destinationHidden.value) {
            destinationHint.classList.remove('d-none');
            blocked = true;
        }
        if (blocked) return;

        submitBtn.disabled = true;
        submitBtn.textContent = 'Planning route...';

        try {
            const response = await apiFetch('/api/trips', {
                method: 'POST',
                body: JSON.stringify({
                    vehicle_id: form.vehicle_id.value,
                    origin_node_id: originHidden.value,
                    destination_node_id: destinationHidden.value,
                    battery_percent: form.battery_percent.value,
                }),
            });

            const data = await response.json().catch(() => ({}));

            if (response.ok) {
                window.location.href = `/trips/${data.id}`;
                return;
            }

            if (response.status === 422 && data.errors) {
                showFieldErrors(form, data.errors);
            } else {
                errorBox.textContent = data.message || 'Unable to plan this trip. Please try again.';
                errorBox.classList.remove('d-none');
            }
        } catch (error) {
            errorBox.textContent = 'Something went wrong. Please check your connection and try again.';
            errorBox.classList.remove('d-none');
        } finally {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Plan Route';
        }
    });
});
