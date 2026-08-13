import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { apiFetch } from '../api.js';
import { attachNavigate } from '../navigate.js';
import { MYANMAR_BOUNDS, MYANMAR_MIN_ZOOM } from '../map-bounds.js';
import { categoryFor, markerIconFor } from '../station-marker.js';
import { triggerPulse } from '../animate.js';
import { refreshChargingControl, attachStartChargingControl } from '../charging-session.js';

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

            // hasSlots needs the real station.slots array, so this waits
            // for the first loadStation() resolution rather than running
            // in parallel with it like the rest of this block does -
            // "Shared with Live Trip's own per-stop controls (see
            // charging-session.js) - always refetch after mutation here
            // also picks up the Slots list and Queue length stat via the
            // loadStation() re-fetch, both of which just became stale."
            const chargingControlRoot = document.getElementById('charging-control');
            const hasSlots = (station.slots?.length ?? 0) > 0;
            refreshChargingControl(chargingControlRoot, stationId, hasSlots);
            attachStartChargingControl(chargingControlRoot, stationId, () => loadStation(stationId), hasSlots);
        })
        .catch(() => {
            document.getElementById('station-loading').classList.add('d-none');
            const errorBox = document.getElementById('station-error');
            errorBox.textContent = 'Something went wrong loading this station.';
            errorBox.classList.remove('d-none');
        });

    refreshFavoriteButton(stationId);

    loadReviewsPage(stationId);

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
