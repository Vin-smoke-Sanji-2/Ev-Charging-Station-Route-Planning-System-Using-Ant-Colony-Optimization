import { apiFetch } from '../api.js';

/**
 * Checks BOTH approval gates independently - they're genuinely separate
 * (see CLAUDE.md's "two-gate approval design"), not something that moves
 * together, so this never assumes one implies the other:
 *   1. user.status - blocks everything, no station content shown at all.
 *   2. the station's own verification_status - only relevant once gate 1
 *      has passed.
 *
 * Gate 1 is read from GET /api/stations/mine's own success/failure rather
 * than a second /api/auth/me call - that endpoint is guarded by the same
 * role:station_owner,admin + stationOwnerAccessDeniedMessage() middleware
 * chain used everywhere else a non-active station owner is blocked, so a
 * 403 response's `message` field IS the exact real reason, straight from
 * the backend's own single source of truth - never a second, separately
 * hand-typed copy of that wording here.
 */
document.addEventListener('DOMContentLoaded', async () => {
    const container = document.getElementById('station-owner-overview-app');
    if (!container) return;

    const loading = document.getElementById('overview-loading');
    const blockedState = document.getElementById('overview-account-blocked');
    const stationPendingState = document.getElementById('overview-station-pending');
    const activeState = document.getElementById('overview-active');

    const response = await apiFetch('/api/stations/mine');

    loading.classList.add('d-none');

    if (!response.ok) {
        const data = await response.json().catch(() => ({}));
        document.getElementById('account-status-message').textContent =
            data.message || 'Your account cannot access station features right now.';
        blockedState.classList.remove('d-none');
        return;
    }

    const stations = await response.json();
    const station = stations[0] ?? null;

    if (!station || station.verification_status !== 'verified') {
        document.getElementById('station-pending-name').textContent = station ? station.name : 'your station';
        stationPendingState.classList.remove('d-none');
        return;
    }

    document.getElementById('overview-station-name').textContent = station.name;
    document.getElementById('overview-station-address').textContent = station.address || 'No address on file';
    document.getElementById('overview-station-township').textContent = station.township || '';
    activeState.classList.remove('d-none');
});
