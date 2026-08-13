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

function verificationBadge(status) {
    if (status === 'verified') return '<span class="badge badge-verified badge-status">Verified</span>';
    if (status === 'rejected') return '<span class="badge bg-danger badge-status">Rejected</span>';
    return '<span class="badge bg-accent badge-status">Pending</span>';
}

function statusBadgeClass(status) {
    if (status === 'available') return 'bg-success';
    if (status === 'occupied') return 'bg-warning text-dark';
    return 'bg-danger';
}

function slotRow(slot) {
    const powerKw = slot.power_kw === null || slot.power_kw === undefined ? 'Unknown kW' : `${slot.power_kw} kW`;

    return `
        <div class="border rounded p-2 d-flex align-items-center justify-content-between" data-slot-row="${slot.id}">
            <div>
                <div class="fw-semibold">${slot.slot_code} <span class="badge ${statusBadgeClass(slot.status)} ms-1">${slot.status}</span></div>
                <div class="text-muted small">${slot.connector_type} - ${slot.power_type} - ${powerKw}</div>
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-icon-circle btn-icon-circle--secondary edit-slot-btn"
                        data-id="${slot.id}"
                        data-slot-code="${slot.slot_code}"
                        data-connector-type="${slot.connector_type}"
                        data-power-type="${slot.power_type}"
                        data-power-kw="${slot.power_kw ?? ''}"
                        data-status="${slot.status}"
                        aria-label="Edit slot">
                    <i class="bi bi-pencil"></i>
                </button>
                <button type="button" class="btn btn-icon-circle btn-icon-circle--danger delete-slot-btn"
                        data-id="${slot.id}" aria-label="Delete slot">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        </div>
    `;
}

function renderSlots(slots) {
    const empty = document.getElementById('slots-empty');
    const list = document.getElementById('slots-list');

    if (slots.length === 0) {
        empty.classList.remove('d-none');
        list.innerHTML = '';
        return;
    }

    empty.classList.add('d-none');
    list.innerHTML = slots.map(slotRow).join('');
}

function formatDate(iso) {
    if (!iso) return '—';
    return new Date(iso).toLocaleString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function vehicleDisplay(session) {
    if (!session.vehicle) return 'No vehicle on file';
    const model = session.vehicle.ev_model
        ? `${session.vehicle.ev_model.brand} ${session.vehicle.ev_model.model}`
        : 'Unknown model';
    return session.vehicle.plate_no ? `${model} (${session.vehicle.plate_no})` : model;
}

function chargingSessionRow(session) {
    const userName = session.user ? session.user.name : 'Unknown user';
    const slotCode = session.slot ? session.slot.slot_code : 'Unknown slot';

    return `
        <div class="border rounded p-2" data-session-row="${session.id}">
            <div class="d-flex align-items-start justify-content-between">
                <div>
                    <div class="fw-semibold">${userName}</div>
                    <div class="text-muted small">${vehicleDisplay(session)} - Slot ${slotCode} - Started ${formatDate(session.started_at)}</div>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-primary btn-sm complete-session-btn" data-id="${session.id}">
                        Complete
                    </button>
                    <button type="button" class="btn btn-icon-circle btn-icon-circle--danger cancel-session-btn"
                            data-id="${session.id}" aria-label="Cancel session">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>
            </div>
        </div>
    `;
}

// Deliberately no Complete action anywhere in this template - a waiting
// session was never actually charging, so "Complete" would make no sense
// here (see CLAUDE.md's queue-investigation notes). Cancel is the only
// action.
function waitingSessionRow(session) {
    const userName = session.user ? session.user.name : 'Unknown user';

    return `
        <div class="border rounded p-2 d-flex align-items-center justify-content-between" data-session-row="${session.id}">
            <div>
                <div class="fw-semibold">${userName}</div>
                <div class="text-muted small">${vehicleDisplay(session)} - Waiting since ${formatDate(session.queued_at)}</div>
            </div>
            <button type="button" class="btn btn-icon-circle btn-icon-circle--danger cancel-session-btn"
                    data-id="${session.id}" aria-label="Cancel session">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
    `;
}

function renderQueue(sessions) {
    // index() orders by created_at (latest() ), not queue order - the
    // real FIFO promotion order is queued_at/started_at ascending (see
    // CLAUDE.md's queue-investigation notes on ChargingSessionController::
    // update()'s own `orderBy('queued_at')`), so both lists are re-sorted
    // here to actually match that order rather than the raw response order.
    const charging = sessions
        .filter((s) => s.status === 'charging')
        .sort((a, b) => new Date(a.started_at) - new Date(b.started_at));
    const waiting = sessions
        .filter((s) => s.status === 'waiting')
        .sort((a, b) => new Date(a.queued_at) - new Date(b.queued_at));

    const chargingEmpty = document.getElementById('queue-charging-empty');
    const chargingList = document.getElementById('queue-charging-list');
    const waitingEmpty = document.getElementById('queue-waiting-empty');
    const waitingList = document.getElementById('queue-waiting-list');

    if (charging.length === 0) {
        chargingEmpty.classList.remove('d-none');
        chargingList.innerHTML = '';
    } else {
        chargingEmpty.classList.add('d-none');
        chargingList.innerHTML = charging.map(chargingSessionRow).join('');
    }

    if (waiting.length === 0) {
        waitingEmpty.classList.remove('d-none');
        waitingList.innerHTML = '';
    } else {
        waitingEmpty.classList.add('d-none');
        waitingList.innerHTML = waiting.map(waitingSessionRow).join('');
    }
}

function resetSlotFormToAddMode(form, modalTitle) {
    form.reset();
    form.slot_id.value = '';
    form.slot_code.disabled = false;
    document.getElementById('slot-code-locked-hint').classList.add('d-none');
    form.power_type.value = 'AC';
    form.slot_status.value = 'available';
    modalTitle.textContent = 'Add Slot';
}

function fillSlotFormForEdit(form, modalTitle, button) {
    form.slot_id.value = button.dataset.id;
    form.slot_code.value = button.dataset.slotCode;
    // slot_code can't be changed after creation (ChargingSlotController::
    // update() has no validation rule for it, so the API would silently
    // ignore it anyway) - disabled here so the UI doesn't imply otherwise.
    form.slot_code.disabled = true;
    document.getElementById('slot-code-locked-hint').classList.remove('d-none');
    form.connector_type.value = button.dataset.connectorType;
    form.power_type.value = button.dataset.powerType;
    form.power_kw.value = button.dataset.powerKw;
    form.slot_status.value = button.dataset.status;
    modalTitle.textContent = 'Edit Slot';
}

document.addEventListener('DOMContentLoaded', async () => {
    const app = document.getElementById('station-manage-app');
    if (!app) return;

    const stationId = app.dataset.stationId;

    const loading = document.getElementById('manage-loading');
    const notOwner = document.getElementById('manage-not-owner');
    const content = document.getElementById('manage-content');

    const [meResponse, stationResponse] = await Promise.all([
        apiFetch('/api/auth/me'),
        apiFetch(`/api/stations/${stationId}`),
    ]);

    loading.classList.add('d-none');

    if (!meResponse.ok || !stationResponse.ok) {
        notOwner.classList.remove('d-none');
        return;
    }

    const me = await meResponse.json();
    const { station } = await stationResponse.json();

    // Client-side defensive check only - the real enforcement is server-
    // side (ChargingStationController::update()/ChargingSlotController's
    // own authorizeStation() both 403 a non-owner), matching this
    // project's established discipline of never trusting the client as
    // the actual authorization boundary. This just avoids showing edit
    // controls for a station this user can't actually manage.
    const isOwner = me.role === 'admin' || station.owner_user_id === me.id;
    if (!isOwner) {
        notOwner.classList.remove('d-none');
        return;
    }

    content.classList.remove('d-none');
    document.getElementById('manage-station-name').textContent = station.name;
    document.getElementById('manage-verification-badge').innerHTML = verificationBadge(station.verification_status);

    const detailsForm = document.getElementById('details-form');
    detailsForm.name.value = station.name || '';
    detailsForm.address.value = station.address || '';
    detailsForm.township.value = station.township || '';
    detailsForm.charging_speed.value = station.charging_speed || '';
    detailsForm.operating_hours.value = station.operating_hours || '';

    renderSlots(station.slots || []);

    async function loadQueue() {
        const queueLoading = document.getElementById('queue-loading');
        const queueContent = document.getElementById('queue-content');

        const response = await apiFetch(`/api/sessions?station_id=${stationId}`);
        queueLoading.classList.add('d-none');
        queueContent.classList.remove('d-none');

        if (!response.ok) {
            renderQueue([]);
            return;
        }

        const data = await response.json();
        renderQueue(data.data || []);
    }

    loadQueue();

    document.getElementById('queue-content').addEventListener('click', async (event) => {
        const completeBtn = event.target.closest('.complete-session-btn');
        if (completeBtn) {
            // No Energy/Payment fields - cost tracking was removed from the
            // EV Owner side entirely, so completing a session here no
            // longer collects anything beyond the status change itself.
            // confirm() replaces the old two-step Complete -> Confirm
            // Complete form as the accidental-click safeguard.
            if (!confirm('Mark this session as complete?')) return;

            const sessionId = completeBtn.dataset.id;
            completeBtn.disabled = true;
            await apiFetch(`/api/sessions/${sessionId}`, {
                method: 'PUT',
                body: JSON.stringify({ status: 'completed' }),
            });
            // Always refetch rather than optimistically patch the DOM -
            // promotion happens automatically server-side within the same
            // request, so a stale client-side update could miss a newly-
            // promoted waiting session (same "always refetch after
            // mutation" discipline this app already uses for My EVs/
            // Favorites).
            await loadQueue();
            return;
        }

        const cancelBtn = event.target.closest('.cancel-session-btn');
        if (cancelBtn) {
            if (!confirm('Cancel this session? This cannot be undone.')) return;

            cancelBtn.disabled = true;
            await apiFetch(`/api/sessions/${cancelBtn.dataset.id}`, {
                method: 'PUT',
                body: JSON.stringify({ status: 'cancelled' }),
            });
            await loadQueue();
        }
    });

    const detailsErrorBox = document.getElementById('details-form-error');
    const detailsSuccessBox = document.getElementById('details-form-success');
    const detailsSubmitBtn = document.getElementById('details-form-submit');

    detailsForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearErrors(detailsForm, detailsErrorBox);
        detailsSuccessBox.classList.add('d-none');
        detailsSubmitBtn.disabled = true;
        detailsSubmitBtn.textContent = 'Saving...';

        try {
            const response = await apiFetch(`/api/stations/${stationId}`, {
                method: 'PUT',
                body: JSON.stringify({
                    name: detailsForm.name.value,
                    address: detailsForm.address.value || null,
                    township: detailsForm.township.value || null,
                    charging_speed: detailsForm.charging_speed.value || null,
                    operating_hours: detailsForm.operating_hours.value || null,
                }),
            });

            if (response.ok) {
                const updated = await response.json();
                document.getElementById('manage-station-name').textContent = updated.name;
                detailsSuccessBox.classList.remove('d-none');
                return;
            }

            const data = await response.json().catch(() => ({}));

            if (response.status === 422 && data.errors) {
                showFieldErrors(detailsForm, data.errors);
            } else {
                detailsErrorBox.textContent = data.message || 'Unable to save changes. Please try again.';
                detailsErrorBox.classList.remove('d-none');
            }
        } catch (error) {
            detailsErrorBox.textContent = 'Something went wrong. Please check your connection and try again.';
            detailsErrorBox.classList.remove('d-none');
        } finally {
            detailsSubmitBtn.disabled = false;
            detailsSubmitBtn.textContent = 'Save Changes';
        }
    });

    async function reloadSlots() {
        const response = await apiFetch(`/api/stations/${stationId}`);
        if (!response.ok) return;
        const data = await response.json();
        renderSlots(data.station.slots || []);
    }

    const slotForm = document.getElementById('slot-form');
    const slotErrorBox = document.getElementById('slot-form-error');
    const slotSubmitBtn = document.getElementById('slot-form-submit');
    const slotModalTitle = document.getElementById('slot-modal-title');
    const slotModalEl = document.getElementById('slotModal');
    const slotsList = document.getElementById('slots-list');
    const addSlotBtn = document.getElementById('add-slot-btn');

    addSlotBtn.addEventListener('click', () => {
        clearErrors(slotForm, slotErrorBox);
        resetSlotFormToAddMode(slotForm, slotModalTitle);
    });

    slotsList.addEventListener('click', async (event) => {
        const editBtn = event.target.closest('.edit-slot-btn');
        if (editBtn) {
            clearErrors(slotForm, slotErrorBox);
            fillSlotFormForEdit(slotForm, slotModalTitle, editBtn);
            window.bootstrap.Modal.getOrCreateInstance(slotModalEl).show();
            return;
        }

        const deleteBtn = event.target.closest('.delete-slot-btn');
        if (deleteBtn) {
            if (!confirm('Delete this slot? This cannot be undone.')) return;

            deleteBtn.disabled = true;
            await apiFetch(`/api/stations/${stationId}/slots/${deleteBtn.dataset.id}`, { method: 'DELETE' });
            await reloadSlots();
        }
    });

    slotForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearErrors(slotForm, slotErrorBox);
        slotSubmitBtn.disabled = true;
        slotSubmitBtn.textContent = 'Saving...';

        const slotId = slotForm.slot_id.value;
        const url = slotId ? `/api/stations/${stationId}/slots/${slotId}` : `/api/stations/${stationId}/slots`;
        const method = slotId ? 'PUT' : 'POST';

        const payload = {
            connector_type: slotForm.connector_type.value,
            power_type: slotForm.power_type.value,
            power_kw: slotForm.power_kw.value,
            status: slotForm.slot_status.value,
        };
        if (!slotId) {
            payload.slot_code = slotForm.slot_code.value;
        }

        try {
            const response = await apiFetch(url, {
                method,
                body: JSON.stringify(payload),
            });

            if (response.ok) {
                window.bootstrap.Modal.getOrCreateInstance(slotModalEl).hide();
                await reloadSlots();
                return;
            }

            const data = await response.json().catch(() => ({}));

            if (response.status === 422 && data.errors) {
                showFieldErrors(slotForm, data.errors);
            } else {
                slotErrorBox.textContent = data.message || 'Unable to save this slot. Please try again.';
                slotErrorBox.classList.remove('d-none');
            }
        } catch (error) {
            slotErrorBox.textContent = 'Something went wrong. Please check your connection and try again.';
            slotErrorBox.classList.remove('d-none');
        } finally {
            slotSubmitBtn.disabled = false;
            slotSubmitBtn.textContent = 'Save Slot';
        }
    });
});
