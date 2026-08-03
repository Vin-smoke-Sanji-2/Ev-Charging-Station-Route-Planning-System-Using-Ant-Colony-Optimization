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

async function loadVehicles(select, noVehiclesAlert, submitBtn) {
    const response = await apiFetch('/api/vehicles');
    const vehicles = response.ok ? await response.json() : [];

    if (vehicles.length === 0) {
        select.innerHTML = '<option value="">No vehicles available</option>';
        noVehiclesAlert.classList.remove('d-none');
        submitBtn.disabled = true;
        return;
    }

    select.innerHTML = '<option value="">Select a vehicle</option>' + vehicles.map((vehicle) => {
        const model = vehicle.ev_model ? `${vehicle.ev_model.brand} ${vehicle.ev_model.model}` : 'Vehicle';
        const label = vehicle.plate_no ? `${model} - ${vehicle.plate_no}` : model;
        return `<option value="${vehicle.id}">${label}</option>`;
    }).join('');
}

async function loadRoadNodes(originSelect, destinationSelect) {
    const response = await apiFetch('/api/road-nodes');
    const nodes = response.ok ? await response.json() : [];

    const options = '<option value="">Select a location</option>' + nodes.map((node) =>
        `<option value="${node.id}">${node.name}</option>`
    ).join('');

    originSelect.innerHTML = options;
    destinationSelect.innerHTML = options;
}

document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('plan-trip-form');
    if (!form) return;

    const errorBox = document.getElementById('form-error');
    const noVehiclesAlert = document.getElementById('no-vehicles-alert');
    const submitBtn = document.getElementById('plan-trip-submit');
    const vehicleSelect = document.getElementById('vehicle_id');
    const originSelect = document.getElementById('origin_node_id');
    const destinationSelect = document.getElementById('destination_node_id');

    loadVehicles(vehicleSelect, noVehiclesAlert, submitBtn);
    loadRoadNodes(originSelect, destinationSelect);

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearErrors(form, errorBox);
        submitBtn.disabled = true;
        submitBtn.textContent = 'Planning route...';

        try {
            const response = await apiFetch('/api/trips', {
                method: 'POST',
                body: JSON.stringify({
                    vehicle_id: form.vehicle_id.value,
                    origin_node_id: form.origin_node_id.value,
                    destination_node_id: form.destination_node_id.value,
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
