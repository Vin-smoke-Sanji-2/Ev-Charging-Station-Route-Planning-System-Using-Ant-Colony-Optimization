import { apiFetch } from '../api.js';

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

function vehicleCard(vehicle) {
    const model = vehicle.ev_model ? `${vehicle.ev_model.brand} ${vehicle.ev_model.model}` : 'Unknown model';
    const defaultBadge = vehicle.is_default
        ? '<span class="badge bg-brand ms-2">Default</span>'
        : '';

    return `
        <div class="col-md-6 col-lg-4" data-vehicle-card="${vehicle.id}">
            <div class="card h-100 border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-start justify-content-between">
                        <div>
                            <h5 class="card-title mb-1">
                                <i class="bi bi-car-front-fill text-brand"></i> ${model}${defaultBadge}
                            </h5>
                            <p class="text-muted mb-0">${vehicle.plate_no || 'No plate number set'}</p>
                        </div>
                    </div>
                    <div class="d-flex gap-3 mt-3">
                        <button type="button" class="btn btn-icon-circle btn-icon-circle--secondary edit-vehicle-btn"
                                data-id="${vehicle.id}"
                                data-ev-model-id="${vehicle.ev_model_id}"
                                data-plate-no="${vehicle.plate_no ?? ''}"
                                data-is-default="${vehicle.is_default ? '1' : '0'}"
                                aria-label="Edit vehicle">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button type="button" class="btn btn-icon-circle btn-icon-circle--danger delete-vehicle-btn"
                                data-id="${vehicle.id}" aria-label="Delete vehicle">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `;
}

function renderVehicles(vehicles) {
    const loading = document.getElementById('vehicles-loading');
    const empty = document.getElementById('vehicles-empty');
    const grid = document.getElementById('vehicles-grid');

    loading.classList.add('d-none');

    if (vehicles.length === 0) {
        empty.classList.remove('d-none');
        grid.innerHTML = '';
        return;
    }

    empty.classList.add('d-none');
    grid.innerHTML = vehicles.map(vehicleCard).join('');
}

async function loadVehicles() {
    const response = await apiFetch('/api/vehicles');
    const vehicles = response.ok ? await response.json() : [];
    renderVehicles(vehicles);
}

async function loadEvModelOptions(select) {
    const response = await apiFetch('/api/ev-models');
    const evModels = response.ok ? await response.json() : [];

    select.innerHTML = '<option value="">Select an EV model</option>' + evModels.map((model) =>
        `<option value="${model.id}">${model.brand} ${model.model}</option>`
    ).join('');
}

// Both Add and Edit always start in dropdown mode - even when editing a
// vehicle, its current model is a real dropdown option (that's what it was
// created from originally), so there's no reason to default to manual
// entry. The user can still toggle to manual if they want to switch it to
// something not in the list.
function showDropdownMode(form) {
    document.getElementById('ev-model-select-group').classList.remove('d-none');
    document.getElementById('ev-model-manual-group').classList.add('d-none');
    form.ev_model_id.required = true;
    form.brand.required = false;
    form.model.required = false;
    form.battery_capacity_kwh.required = false;
    form.max_range_km.required = false;
    form.connector_type.required = false;
}

function showManualEntryMode(form) {
    document.getElementById('ev-model-select-group').classList.add('d-none');
    document.getElementById('ev-model-manual-group').classList.remove('d-none');
    form.ev_model_id.required = false;
    form.brand.required = true;
    form.model.required = true;
    form.battery_capacity_kwh.required = true;
    form.max_range_km.required = true;
    form.connector_type.required = true;
}

function resetFormToAddMode(form, modalTitle) {
    form.reset();
    form.vehicle_id.value = '';
    showDropdownMode(form);
    modalTitle.textContent = 'Add Vehicle';
}

function fillFormForEdit(form, modalTitle, button) {
    form.vehicle_id.value = button.dataset.id;
    form.ev_model_id.value = button.dataset.evModelId;
    form.plate_no.value = button.dataset.plateNo;
    form.is_default.checked = button.dataset.isDefault === '1';
    showDropdownMode(form);
    modalTitle.textContent = 'Edit Vehicle';
}

// Creates a real EvModel row from the manually-typed fields (find-or-create,
// case-insensitive on brand+model - see EvModelController::store()'s own
// doc comment), then returns its id to use as the vehicle's ev_model_id.
// A vehicle always needs a real EvModel - trip planning reads
// max_range_km/battery_capacity_kwh directly off it (AcoRouteEngine) - so
// "type it manually" still needs those two numbers, it just doesn't
// require them to already be a dropdown option.
async function createEvModelFromManualEntry(form) {
    return apiFetch('/api/ev-models', {
        method: 'POST',
        body: JSON.stringify({
            brand: form.brand.value,
            model: form.model.value,
            battery_capacity_kwh: form.battery_capacity_kwh.value,
            max_range_km: form.max_range_km.value,
            connector_type: form.connector_type.value,
        }),
    });
}

document.addEventListener('DOMContentLoaded', () => {
    const grid = document.getElementById('vehicles-grid');
    if (!grid) return;

    const form = document.getElementById('vehicle-form');
    const errorBox = document.getElementById('vehicle-form-error');
    const submitBtn = document.getElementById('vehicle-form-submit');
    const modalTitle = document.getElementById('vehicle-modal-title');
    const modalEl = document.getElementById('vehicleModal');
    const evModelSelect = document.getElementById('ev_model_id');
    const addBtn = document.getElementById('add-vehicle-btn');
    const toggleManualBtn = document.getElementById('toggle-manual-entry-btn');
    const toggleDropdownBtn = document.getElementById('toggle-dropdown-btn');

    loadVehicles();
    loadEvModelOptions(evModelSelect);

    addBtn.addEventListener('click', () => {
        clearErrors(form, errorBox);
        resetFormToAddMode(form, modalTitle);
    });

    toggleManualBtn.addEventListener('click', () => {
        clearErrors(form, errorBox);
        showManualEntryMode(form);
    });

    toggleDropdownBtn.addEventListener('click', () => {
        clearErrors(form, errorBox);
        showDropdownMode(form);
    });

    grid.addEventListener('click', async (event) => {
        const editBtn = event.target.closest('.edit-vehicle-btn');
        if (editBtn) {
            clearErrors(form, errorBox);
            fillFormForEdit(form, modalTitle, editBtn);
            window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
            return;
        }

        const deleteBtn = event.target.closest('.delete-vehicle-btn');
        if (deleteBtn) {
            if (!confirm('Remove this vehicle from your account?')) return;

            deleteBtn.disabled = true;
            await apiFetch(`/api/vehicles/${deleteBtn.dataset.id}`, { method: 'DELETE' });
            await loadVehicles();
        }
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearErrors(form, errorBox);
        submitBtn.disabled = true;
        submitBtn.textContent = 'Saving...';

        const vehicleId = form.vehicle_id.value;
        const url = vehicleId ? `/api/vehicles/${vehicleId}` : '/api/vehicles';
        const method = vehicleId ? 'PUT' : 'POST';
        const manualEntryActive = !document.getElementById('ev-model-manual-group').classList.contains('d-none');

        try {
            let evModelId = form.ev_model_id.value;

            // Manual entry is a two-step chain: create (or find) the real
            // EvModel row first, then use its id exactly like a dropdown
            // pick - the vehicle-create/update call below never knows or
            // cares which path supplied ev_model_id.
            if (manualEntryActive) {
                const evModelResponse = await createEvModelFromManualEntry(form);
                const evModelData = await evModelResponse.json().catch(() => ({}));

                if (!evModelResponse.ok) {
                    if (evModelResponse.status === 422 && evModelData.errors) {
                        showFieldErrors(form, evModelData.errors);
                    } else {
                        errorBox.textContent = evModelData.message || 'Unable to save this EV model. Please try again.';
                        errorBox.classList.remove('d-none');
                    }
                    return;
                }

                evModelId = evModelData.id;
            }

            const response = await apiFetch(url, {
                method,
                body: JSON.stringify({
                    ev_model_id: evModelId,
                    plate_no: form.plate_no.value || null,
                    is_default: form.is_default.checked,
                }),
            });

            if (response.ok) {
                window.bootstrap.Modal.getOrCreateInstance(modalEl).hide();
                await loadVehicles();
                if (manualEntryActive) {
                    // Picks up the just-created (or matched) model so it's
                    // there next time without needing a page refresh.
                    await loadEvModelOptions(evModelSelect);
                }
                return;
            }

            const data = await response.json().catch(() => ({}));

            if (response.status === 422 && data.errors) {
                showFieldErrors(form, data.errors);
            } else {
                errorBox.textContent = data.message || 'Unable to save this vehicle. Please try again.';
                errorBox.classList.remove('d-none');
            }
        } catch (error) {
            errorBox.textContent = 'Something went wrong. Please check your connection and try again.';
            errorBox.classList.remove('d-none');
        } finally {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Save Vehicle';
        }
    });
});
