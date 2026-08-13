import { apiFetch } from './api.js';

function formatDateTime(iso) {
    if (!iso) return '—';
    return new Date(iso).toLocaleString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

/**
 * Shared "Start Charging Here" control - originally Station Detail-only,
 * now also used per-stop on Live Trip so an EV owner can manually start a
 * session at ANY station on their route, in any order, whenever they
 * actually decide to charge there. This is deliberately independent of
 * a route stop's own `reached_at`/sequence-order tracking (see
 * live-trip.js's nextTarget()) - charging and "reached" are two separate
 * concepts, and neither page enforces the other's ordering.
 *
 * `root` is any element containing four children marked with
 * `data-role="loading"|"status"|"start-btn"|"error"` - Station Detail has
 * exactly one such root on the page; Live Trip renders one per stop.
 */
export async function refreshChargingControl(root, stationId) {
    const loadingEl = root.querySelector('[data-role="loading"]');
    const statusEl = root.querySelector('[data-role="status"]');
    const startBtn = root.querySelector('[data-role="start-btn"]');

    loadingEl?.classList.remove('d-none');
    statusEl?.classList.add('d-none');
    startBtn?.classList.add('d-none');

    const response = await apiFetch(`/api/sessions?station_id=${stationId}`);
    loadingEl?.classList.add('d-none');

    const sessions = response.ok ? (await response.json()).data ?? [] : [];
    const mySession = sessions.find((s) => s.status === 'waiting' || s.status === 'charging');

    if (!mySession) {
        startBtn?.classList.remove('d-none');
        return null;
    }

    if (statusEl) {
        statusEl.textContent = mySession.status === 'charging'
            ? `You're charging on Slot ${mySession.slot?.slot_code ?? 'unknown'} (started ${formatDateTime(mySession.started_at)}).`
            : `You're waiting for a slot to open up (queued since ${formatDateTime(mySession.queued_at)}).`;
        statusEl.classList.remove('d-none');
    }

    return mySession;
}

/**
 * Wires the "Start Charging Here" click handler for one `root` control.
 * No slot picker - the backend auto-assigns the first available slot, or
 * queues as waiting if none are free. Only the user's default vehicle (if
 * any) is sent; there's no vehicle picker, and vehicle_id is nullable
 * server-side.
 *
 * @param {(() => Promise<void>|void)} [onSuccess] - called after a
 *   successful start AND after this control's own status refresh, so the
 *   caller can refresh anything else that just went stale (e.g. Station
 *   Detail's slot list, or Live Trip's stop availability counts).
 */
export function attachStartChargingControl(root, stationId, onSuccess) {
    const startBtn = root.querySelector('[data-role="start-btn"]');
    const errorEl = root.querySelector('[data-role="error"]');
    if (!startBtn) return;

    startBtn.addEventListener('click', async () => {
        errorEl?.classList.add('d-none');
        startBtn.disabled = true;
        const originalHtml = startBtn.innerHTML;
        startBtn.textContent = 'Starting...';

        try {
            const vehiclesResponse = await apiFetch('/api/vehicles');
            const vehicles = vehiclesResponse.ok ? await vehiclesResponse.json() : [];
            const defaultVehicle = vehicles.find((vehicle) => vehicle.is_default);

            const response = await apiFetch(`/api/stations/${stationId}/sessions`, {
                method: 'POST',
                body: JSON.stringify(defaultVehicle ? { vehicle_id: defaultVehicle.id } : {}),
            });

            if (response.ok) {
                await refreshChargingControl(root, stationId);
                if (onSuccess) await onSuccess();
                return;
            }

            const data = await response.json().catch(() => ({}));
            if (errorEl) {
                errorEl.textContent = data.message || 'Unable to start charging. Please try again.';
                errorEl.classList.remove('d-none');
            }
        } catch (error) {
            if (errorEl) {
                errorEl.textContent = 'Something went wrong. Please check your connection and try again.';
                errorEl.classList.remove('d-none');
            }
        } finally {
            startBtn.disabled = false;
            startBtn.innerHTML = originalHtml;
        }
    });
}
