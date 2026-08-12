import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { apiFetch } from '../api.js';
import { attachNavigate } from '../navigate.js';
import { MYANMAR_BOUNDS, MYANMAR_MIN_ZOOM } from '../map-bounds.js';
import { categoryFor, markerIconFor } from '../station-marker.js';
import { triggerPulse } from '../animate.js';

function renderStationMap(station) {
    const latLng = [Number(station.latitude), Number(station.longitude)];

    // Settle the map's view before adding the tile layer - same reason as
    // trip-show.js's renderMap(): an unset view followed by an animated
    // move thrashes through every intermediate tile grid.
    const map = L.map('station-map', {
        maxBounds: MYANMAR_BOUNDS,
        minZoom: MYANMAR_MIN_ZOOM,
    });
    map.setView(latLng, 15, { animate: false });

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors',
        maxZoom: 19,
    }).addTo(map);

    // Same pin glyph/color categorization as Dashboard's map markers (see
    // station-marker.js) - this page's own station falls back to deriving
    // its DC/AC category from `station.slots` directly, since this
    // endpoint's response doesn't carry the has_dc_connector/
    // has_ac_connector booleans Dashboard's station list has.
    const category = categoryFor(station);
    L.marker(latLng, { icon: markerIconFor(category.color) }).addTo(map).bindPopup(station.name);

    return map;
}

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

function slotStatusBadgeClass(status) {
    return {
        available: 'bg-success',
        occupied: 'bg-warning text-dark',
        maintenance: 'bg-danger',
    }[status] || 'bg-secondary';
}

function formatDate(iso) {
    if (!iso) return '—';
    return new Date(iso).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

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

function setPageLinkState(itemEl, linkEl, url) {
    if (url) {
        itemEl.classList.remove('disabled');
        linkEl.dataset.url = url;
    } else {
        itemEl.classList.add('disabled');
        delete linkEl.dataset.url;
    }
}

function renderSlots(slots) {
    const list = document.getElementById('slots-list');
    const empty = document.getElementById('slots-empty');

    if (slots.length === 0) {
        list.innerHTML = '';
        empty.classList.remove('d-none');
        return;
    }

    empty.classList.add('d-none');
    list.innerHTML = slots.map((slot) => {
        // power_kw is nullable - most real seeded stations don't have a
        // confirmed figure (see CLAUDE.md). Without this check, a null
        // value interpolates as the literal string "null" in the template
        // literal below ("null kW"), not a friendly fallback.
        const power = slot.power_kw === null || slot.power_kw === undefined
            ? 'Unknown kW'
            : `${slot.power_kw} kW`;
        return `
        <li class="list-group-item d-flex justify-content-between align-items-center">
            <div>
                <div class="fw-semibold">${slot.slot_code}</div>
                <div class="text-muted small">${slot.connector_type} - ${power}</div>
            </div>
            <span class="badge ${slotStatusBadgeClass(slot.status)}">${slot.status}</span>
        </li>
    `;
    }).join('');
}

function reviewItem(review) {
    const stars = '★'.repeat(review.rating) + '☆'.repeat(5 - review.rating);
    return `
        <li class="list-group-item">
            <div class="d-flex justify-content-between">
                <span class="fw-semibold">${review.user?.name ?? 'Anonymous'}</span>
                <span class="text-muted small">${formatDate(review.created_at)}</span>
            </div>
            <div class="text-warning">${stars}</div>
            ${review.comment ? `<p class="mb-0 mt-1">${review.comment}</p>` : ''}
        </li>
    `;
}

async function loadStation(stationId) {
    const loading = document.getElementById('station-loading');
    const errorBox = document.getElementById('station-error');
    const content = document.getElementById('station-content');

    const response = await apiFetch(`/api/stations/${stationId}`);

    if (!response.ok) {
        loading.classList.add('d-none');
        errorBox.textContent = "This station doesn't exist.";
        errorBox.classList.remove('d-none');
        return null;
    }

    const data = await response.json();
    const station = data.station;

    document.getElementById('station-name').textContent = station.name;
    document.getElementById('station-township').textContent = station.township || '';
    document.getElementById('station-address').textContent = station.address || '—';
    document.getElementById('station-speed').textContent = station.charging_speed || '—';
    document.getElementById('station-hours').textContent = station.operating_hours || '—';
    document.getElementById('station-rating').textContent = station.reviews_avg_rating !== null
        ? `${Number(station.reviews_avg_rating).toFixed(1)} / 5 (${station.reviews_count})`
        : 'No reviews yet';
    document.getElementById('station-queue').textContent = data.queue_length;

    renderSlots(station.slots || []);

    loading.classList.add('d-none');
    content.classList.remove('d-none');

    return station;
}

async function isFavorited(stationId) {
    const response = await apiFetch('/api/favorites');
    if (!response.ok) return false;
    const favorites = await response.json();
    return favorites.some((favorite) => String(favorite.station_id) === String(stationId));
}

let favoriteToken = 0;

// The page-load favorite check and a click's own re-check both hit
// GET /api/favorites independently - without this guard, a slow initial
// load-time check can resolve *after* a fast click's re-check and silently
// stomp the click's correct result back to the stale pre-click state.
// Bumping the token synchronously (before the await) means any earlier
// in-flight call that resolves later sees a stale token and no-ops instead.
async function refreshFavoriteButton(stationId) {
    const token = ++favoriteToken;
    const favorited = await isFavorited(stationId);
    if (token !== favoriteToken) return;
    renderFavoriteButton(favorited);
}

function renderFavoriteButton(favorited) {
    const btn = document.getElementById('favorite-btn');
    const icon = document.getElementById('favorite-icon');
    const label = document.getElementById('favorite-label');

    btn.dataset.favorited = favorited ? '1' : '0';
    icon.className = favorited ? 'bi bi-heart-fill' : 'bi bi-heart';
    label.textContent = favorited ? 'Favorited' : 'Favorite';
    btn.classList.toggle('btn-icon-circle--favorite', favorited);
    btn.classList.toggle('btn-icon-circle--muted', !favorited);
}

async function loadReviewsPage(stationId, url) {
    const loading = document.getElementById('reviews-loading');
    const empty = document.getElementById('reviews-empty');
    const list = document.getElementById('reviews-list');
    const pagination = document.getElementById('reviews-pagination');
    const prevItem = document.getElementById('reviews-prev-item');
    const nextItem = document.getElementById('reviews-next-item');
    const prevLink = document.getElementById('reviews-prev');
    const nextLink = document.getElementById('reviews-next');

    loading.classList.remove('d-none');
    empty.classList.add('d-none');
    pagination.classList.add('d-none');

    const response = await apiFetch(url ?? `/api/stations/${stationId}/reviews`);
    const data = response.ok ? await response.json() : null;

    loading.classList.add('d-none');

    if (!data || data.total === 0) {
        list.innerHTML = '';
        empty.classList.remove('d-none');
        return;
    }

    list.innerHTML = data.data.map(reviewItem).join('');

    // Only show Previous/Next at all once there's genuinely more than one
    // page - otherwise they'd always render enabled-looking but disabled
    // (every review fits on page 1), which reads as "broken" rather than
    // "correctly nothing to page through." Real pagination (10 reviews per
    // page) still works exactly as before once a station has 11+ reviews.
    if (data.last_page > 1) {
        pagination.classList.remove('d-none');
        setPageLinkState(prevItem, prevLink, data.prev_page_url);
        setPageLinkState(nextItem, nextLink, data.next_page_url);
    }
}

/**
 * Checks whether the current EV owner already has an active session
 * (waiting or charging) at this station and shows that status instead of
 * the "Start Charging Here" button, so they can't double-join.
 * GET /api/sessions?station_id={id} already scopes to the requesting
 * user's own sessions for an ev_owner (ChargingSessionController::
 * index()), so no extra filtering by user is needed here - only by
 * status, since the endpoint has no combined waiting-or-charging filter.
 *
 * Deliberately doesn't show an exact queue position (e.g. "#2 in the
 * queue") - this endpoint only ever returns the requesting user's own
 * sessions, never other users' waiting sessions at the same station, so
 * there's no accurate way to compute a personal rank from it. Showing a
 * fabricated number would be worse than not showing one.
 */
async function loadMySessionStatus(stationId) {
    const loading = document.getElementById('charging-session-loading');
    const statusEl = document.getElementById('charging-session-status');
    const startBtn = document.getElementById('start-charging-btn');

    loading.classList.remove('d-none');
    statusEl.classList.add('d-none');
    startBtn.classList.add('d-none');

    const response = await apiFetch(`/api/sessions?station_id=${stationId}`);
    loading.classList.add('d-none');

    const sessions = response.ok ? (await response.json()).data ?? [] : [];
    const mySession = sessions.find((s) => s.status === 'waiting' || s.status === 'charging');

    if (!mySession) {
        startBtn.classList.remove('d-none');
        return;
    }

    statusEl.textContent = mySession.status === 'charging'
        ? `You're charging on Slot ${mySession.slot?.slot_code ?? 'unknown'} (started ${formatDateTime(mySession.started_at)}).`
        : `You're waiting for a slot to open up (queued since ${formatDateTime(mySession.queued_at)}).`;
    statusEl.classList.remove('d-none');
}

document.addEventListener('DOMContentLoaded', () => {
    const container = document.getElementById('station-app');
    if (!container) return;

    const stationId = container.dataset.stationId;
    const favoriteBtn = document.getElementById('favorite-btn');
    const reviewForm = document.getElementById('review-form');
    const reviewErrorBox = document.getElementById('review-form-error');
    const reviewSubmitBtn = document.getElementById('review-submit');
    const prevLink = document.getElementById('reviews-prev');
    const nextLink = document.getElementById('reviews-next');
    const prevItem = document.getElementById('reviews-prev-item');
    const nextItem = document.getElementById('reviews-next-item');

    // Map + Navigate are set up only from this first load, not from the
    // loadStation() re-fetch after submitting a review below - calling
    // L.map() a second time on the same container throws ("Map container
    // is already initialized").
    loadStation(stationId)
        .then((station) => {
            if (!station) return;

            const map = renderStationMap(station);

            attachNavigate({
                map,
                buttonId: 'navigate-btn',
                statusId: 'navigate-status',
                target: [Number(station.latitude), Number(station.longitude)],
                targetLabel: station.name,
                targetNodeId: station.road_node_id,
            });
        })
        .catch(() => {
            document.getElementById('station-loading').classList.add('d-none');
            const errorBox = document.getElementById('station-error');
            errorBox.textContent = 'Something went wrong loading this station.';
            errorBox.classList.remove('d-none');
        });

    refreshFavoriteButton(stationId);

    loadReviewsPage(stationId);

    loadMySessionStatus(stationId);

    const startChargingBtn = document.getElementById('start-charging-btn');
    const startChargingBtnLabel = document.getElementById('start-charging-btn-label');
    const chargingErrorBox = document.getElementById('charging-session-error');

    startChargingBtn.addEventListener('click', async () => {
        chargingErrorBox.classList.add('d-none');
        startChargingBtn.disabled = true;
        startChargingBtnLabel.textContent = 'Starting...';

        try {
            // No slot picker - the backend auto-assigns the first available
            // slot, or queues as waiting if none are free. Only the user's
            // default vehicle (if any) is sent; there's no vehicle picker
            // in v1, and vehicle_id is nullable server-side.
            const vehiclesResponse = await apiFetch('/api/vehicles');
            const vehicles = vehiclesResponse.ok ? await vehiclesResponse.json() : [];
            const defaultVehicle = vehicles.find((vehicle) => vehicle.is_default);

            const response = await apiFetch(`/api/stations/${stationId}/sessions`, {
                method: 'POST',
                body: JSON.stringify(defaultVehicle ? { vehicle_id: defaultVehicle.id } : {}),
            });

            if (response.ok) {
                // Refetch rather than optimistically patch the DOM - same
                // "always refetch after mutation" discipline
                // station-owner-stations-show.js's loadQueue() already
                // uses. Also refreshes the Slots list and Queue length
                // stat, both of which just became stale.
                await loadMySessionStatus(stationId);
                await loadStation(stationId);
                return;
            }

            const data = await response.json().catch(() => ({}));
            chargingErrorBox.textContent = data.message || 'Unable to start charging. Please try again.';
            chargingErrorBox.classList.remove('d-none');
        } catch (error) {
            chargingErrorBox.textContent = 'Something went wrong. Please check your connection and try again.';
            chargingErrorBox.classList.remove('d-none');
        } finally {
            startChargingBtn.disabled = false;
            startChargingBtnLabel.textContent = 'Start Charging Here';
        }
    });

    favoriteBtn.addEventListener('click', async () => {
        triggerPulse(favoriteBtn);
        favoriteBtn.disabled = true;
        const currentlyFavorited = favoriteBtn.dataset.favorited === '1';

        if (currentlyFavorited) {
            await apiFetch(`/api/favorites/${stationId}`, { method: 'DELETE' });
        } else {
            await apiFetch('/api/favorites', {
                method: 'POST',
                body: JSON.stringify({ station_id: stationId }),
            });
        }

        await refreshFavoriteButton(stationId);
        favoriteBtn.disabled = false;
    });

    prevLink.addEventListener('click', (event) => {
        event.preventDefault();
        if (prevItem.classList.contains('disabled') || !prevLink.dataset.url) return;
        loadReviewsPage(stationId, prevLink.dataset.url);
    });

    nextLink.addEventListener('click', (event) => {
        event.preventDefault();
        if (nextItem.classList.contains('disabled') || !nextLink.dataset.url) return;
        loadReviewsPage(stationId, nextLink.dataset.url);
    });

    reviewForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearErrors(reviewForm, reviewErrorBox);
        reviewSubmitBtn.disabled = true;
        reviewSubmitBtn.textContent = 'Submitting...';

        try {
            const response = await apiFetch(`/api/stations/${stationId}/reviews`, {
                method: 'POST',
                body: JSON.stringify({
                    rating: reviewForm.rating.value,
                    comment: reviewForm.comment.value || null,
                }),
            });

            const data = await response.json().catch(() => ({}));

            if (response.ok) {
                reviewForm.reset();
                await loadReviewsPage(stationId);
                await loadStation(stationId); // pick up the updated reviews_avg_rating
                return;
            }

            if (response.status === 422 && data.errors) {
                showFieldErrors(reviewForm, data.errors);
            } else {
                reviewErrorBox.textContent = data.message || 'Unable to submit your review. Please try again.';
                reviewErrorBox.classList.remove('d-none');
            }
        } catch (error) {
            reviewErrorBox.textContent = 'Something went wrong. Please check your connection and try again.';
            reviewErrorBox.classList.remove('d-none');
        } finally {
            reviewSubmitBtn.disabled = false;
            reviewSubmitBtn.textContent = 'Submit Review';
        }
    });
});
