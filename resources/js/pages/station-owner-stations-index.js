import { apiFetch } from '../api.js';
import {
    setupLocationPicker,
    setupUseMyLocation,
    collectStationFields,
    applyStationFieldError,
    clearStationFieldErrors,
} from '../station-form.js';

function clearErrors(errorBox) {
    errorBox.classList.add('d-none');
    errorBox.textContent = '';
}

function showFieldErrors(form, errors) {
    Object.entries(errors).forEach(([field, messages]) => {
        applyStationFieldError(form, field, messages[0]);
    });
}

function verificationBadge(status) {
    if (status === 'verified') return '<span class="badge badge-verified badge-status">Verified</span>';
    if (status === 'rejected') return '<span class="badge bg-danger badge-status">Rejected</span>';
    return '<span class="badge bg-accent badge-status">Pending</span>';
}

function stationCard(station) {
    const available = station.available_slots_count ?? 0;
    const total = station.total_slots_count ?? 0;

    return `
        <div class="col-md-6 col-lg-4" data-station-card="${station.id}">
            <div class="card h-100 border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-start justify-content-between">
                        <h5 class="card-title mb-1">
                            <i class="bi bi-ev-station-fill text-brand"></i> ${station.name}
                        </h5>
                        ${verificationBadge(station.verification_status)}
                    </div>
                    <p class="text-muted mb-2">${station.township || 'No township on file'}</p>
                    <p class="mb-3">
                        <span class="badge bg-brand">${available} / ${total} available</span>
                    </p>
                    <a href="/station-owner/stations/${station.id}" class="btn btn-secondary btn-sm">
                        <i class="bi bi-gear"></i> Manage
                    </a>
                </div>
            </div>
        </div>
    `;
}

function renderStations(stations) {
    const loading = document.getElementById('stations-loading');
    const empty = document.getElementById('stations-empty');
    const grid = document.getElementById('stations-grid');

    loading.classList.add('d-none');

    if (stations.length === 0) {
        empty.classList.remove('d-none');
        grid.innerHTML = '';
        return;
    }

    empty.classList.add('d-none');
    grid.innerHTML = stations.map(stationCard).join('');
}

async function loadStations() {
    const response = await apiFetch('/api/stations/mine');
    const stations = response.ok ? await response.json() : [];
    renderStations(stations);
}

document.addEventListener('DOMContentLoaded', () => {
    const grid = document.getElementById('stations-grid');
    if (!grid) return;

    const form = document.getElementById('add-station-form');
    const errorBox = document.getElementById('add-station-form-error');
    const submitBtn = document.getElementById('add-station-form-submit');
    const modalEl = document.getElementById('addStationModal');

    loadStations();

    const picker = setupLocationPicker();
    setupUseMyLocation(picker);

    // The map is created while the modal is still display:none (its
    // container has never had a real size yet), so Leaflet needs an
    // explicit remeasure once the modal actually becomes visible - the
    // same gotcha noted in station-form.js's own invalidateSize() doc.
    // Reset to a fresh, unselected pin on every open too, so a second
    // "Add Another Station" click never starts pre-filled with the
    // previous station's pin.
    modalEl.addEventListener('shown.bs.modal', () => {
        picker.invalidateSize();
    });

    modalEl.addEventListener('hidden.bs.modal', () => {
        clearErrors(errorBox);
        clearStationFieldErrors(form);
        form.reset();
        picker.reset();
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearErrors(errorBox);
        clearStationFieldErrors(form);

        if (!picker.hasSelection()) {
            picker.showInvalidHint();
            return;
        }

        submitBtn.disabled = true;
        submitBtn.textContent = 'Creating...';

        try {
            const response = await apiFetch('/api/stations', {
                method: 'POST',
                body: JSON.stringify(collectStationFields(form)),
            });

            if (response.ok) {
                window.bootstrap.Modal.getOrCreateInstance(modalEl).hide();
                await loadStations();
                return;
            }

            const data = await response.json().catch(() => ({}));

            if (response.status === 422 && data.errors) {
                showFieldErrors(form, data.errors);
            } else {
                errorBox.textContent = data.message || 'Unable to create this station. Please try again.';
                errorBox.classList.remove('d-none');
            }
        } catch (error) {
            errorBox.textContent = 'Something went wrong. Please check your connection and try again.';
            errorBox.classList.remove('d-none');
        } finally {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Create Station';
        }
    });
});
