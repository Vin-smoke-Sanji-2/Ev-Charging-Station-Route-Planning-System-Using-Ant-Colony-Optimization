import { apiFetch } from '../api.js';

let masterStations = [];
let displayedStations = [];
let favoriteIds = new Set();
let searchToken = 0;

function stationRow(station) {
    const favorited = favoriteIds.has(String(station.id));

    return `
        <tr data-station-id="${station.id}">
            <td>
                <div class="fw-semibold">${station.name}</div>
                <div class="text-muted small">${station.township ?? ''}</div>
            </td>
            <td>${station.address ?? '—'}</td>
            <td>
                <span class="badge bg-brand">
                    ${station.available_slots_count} / ${station.total_slots_count}
                </span>
            </td>
            <td>${station.charging_speed ?? '—'}</td>
            <td class="text-center">
                <button type="button" class="btn btn-icon-circle ${favorited ? 'btn-icon-circle--favorite' : 'btn-icon-circle--muted'} favorite-toggle-btn"
                        data-station-id="${station.id}" aria-label="${favorited ? 'Remove from favorites' : 'Add to favorites'}">
                    <i class="bi ${favorited ? 'bi-heart-fill' : 'bi-heart'}"></i>
                </button>
            </td>
            <td>
                <a href="/stations/${station.id}" class="btn btn-sm btn-secondary">View Details</a>
            </td>
        </tr>
    `;
}

function sortStations(stations) {
    return [...stations].sort((a, b) => {
        const aFav = favoriteIds.has(String(a.id)) ? 0 : 1;
        const bFav = favoriteIds.has(String(b.id)) ? 0 : 1;
        return aFav - bFav;
    });
}

function renderStations(stations) {
    const loading = document.getElementById('stations-loading');
    const empty = document.getElementById('stations-empty');
    const tableWrap = document.getElementById('stations-table-wrap');
    const tbody = document.getElementById('stations-tbody');

    loading.classList.add('d-none');

    if (stations.length === 0) {
        empty.classList.remove('d-none');
        tableWrap.classList.add('d-none');
        tbody.innerHTML = '';
        return;
    }

    empty.classList.add('d-none');
    tableWrap.classList.remove('d-none');
    tbody.innerHTML = sortStations(stations).map(stationRow).join('');
}

function renderCurrent() {
    renderStations(displayedStations);
}

function buildQueryString(form) {
    const params = new URLSearchParams();

    const connector = form.connector.value.trim();

    if (connector) params.set('connector', connector);
    if (form.fast_only.checked) params.set('fast_only', '1');
    if (form.available_now.checked) params.set('available_now', '1');

    const qs = params.toString();
    return qs ? `?${qs}` : '';
}

async function fetchFavorites() {
    const response = await apiFetch('/api/favorites');
    const favorites = response.ok ? await response.json() : [];
    favoriteIds = new Set(favorites.map((favorite) => String(favorite.station_id)));
}

async function fetchStations(query = '') {
    const response = await apiFetch(`/api/stations${query}`);
    const stations = response.ok ? await response.json() : [];

    masterStations = stations;
    displayedStations = stations;
}

async function loadStations(query = '') {
    const loading = document.getElementById('stations-loading');
    const empty = document.getElementById('stations-empty');
    const tableWrap = document.getElementById('stations-table-wrap');
    const tbody = document.getElementById('stations-tbody');

    loading.classList.remove('d-none');
    empty.classList.add('d-none');
    tableWrap.classList.add('d-none');
    tbody.innerHTML = '';

    await fetchStations(query);
    renderCurrent();
}

function hideSuggestions() {
    searchToken += 1;
    const box = document.getElementById('station-suggestions');
    box.classList.add('d-none');
    box.innerHTML = '';
}

function renderSuggestions(data) {
    const box = document.getElementById('station-suggestions');
    const stations = data.stations || [];
    const locations = data.locations || [];

    if (stations.length === 0 && locations.length === 0) {
        hideSuggestions();
        return;
    }

    let html = '';

    if (stations.length) {
        html += '<div class="list-group-item small text-muted fw-semibold">Stations</div>';
        html += stations.map((station) => `
            <button type="button" class="list-group-item list-group-item-action suggestion-item"
                    data-type="station" data-id="${station.id}">
                ${station.name} <span class="text-muted small">- ${station.township ?? ''}</span>
            </button>
        `).join('');
    }

    if (locations.length) {
        html += '<div class="list-group-item small text-muted fw-semibold">Locations</div>';
        html += locations.map((township) => `
            <button type="button" class="list-group-item list-group-item-action suggestion-item"
                    data-type="location" data-value="${township}">
                ${township}
            </button>
        `).join('');
    }

    box.innerHTML = html;
    box.classList.remove('d-none');
}

async function fetchSuggestions(q) {
    const token = searchToken;
    const response = await apiFetch(`/api/stations/search-suggestions?q=${encodeURIComponent(q)}`);
    const data = response.ok ? await response.json() : { stations: [], locations: [] };

    if (token !== searchToken) return; // dropdown was closed or superseded while this request was in flight

    renderSuggestions(data);
}

function debounce(fn, delay) {
    let timer = null;
    return (...args) => {
        clearTimeout(timer);
        timer = setTimeout(() => fn(...args), delay);
    };
}

document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('station-filters-form');
    if (!form) return;

    const clearBtn = document.getElementById('clear-filters-btn');
    const tbody = document.getElementById('stations-tbody');
    const searchInput = document.getElementById('station-search');
    const searchWrap = document.getElementById('station-search-wrap');
    const suggestionsBox = document.getElementById('station-suggestions');

    (async () => {
        document.getElementById('stations-loading').classList.remove('d-none');
        await Promise.all([fetchStations(), fetchFavorites()]);
        renderCurrent();
    })();

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        searchInput.value = '';
        hideSuggestions();
        loadStations(buildQueryString(form));
    });

    clearBtn.addEventListener('click', () => {
        form.reset();
        searchInput.value = '';
        hideSuggestions();
        loadStations();
    });

    tbody.addEventListener('click', async (event) => {
        const btn = event.target.closest('.favorite-toggle-btn');
        if (!btn) return;

        btn.disabled = true;
        const stationId = btn.dataset.stationId;
        const currentlyFavorited = favoriteIds.has(String(stationId));

        if (currentlyFavorited) {
            await apiFetch(`/api/favorites/${stationId}`, { method: 'DELETE' });
        } else {
            await apiFetch('/api/favorites', {
                method: 'POST',
                body: JSON.stringify({ station_id: stationId }),
            });
        }

        await fetchFavorites();
        renderCurrent();
    });

    const debouncedSearch = debounce((q) => fetchSuggestions(q), 250);

    searchInput.addEventListener('input', () => {
        const q = searchInput.value.trim();

        if (q === '') {
            hideSuggestions();
            displayedStations = masterStations;
            renderCurrent();
            return;
        }

        searchToken += 1;
        debouncedSearch(q);
    });

    searchInput.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            hideSuggestions();
            return;
        }

        if (event.key === 'Enter') {
            event.preventDefault();
            hideSuggestions();
            form.reset();
            const q = searchInput.value.trim();
            loadStations(q ? `?name=${encodeURIComponent(q)}` : '');
        }
    });

    suggestionsBox.addEventListener('click', async (event) => {
        const item = event.target.closest('.suggestion-item');
        if (!item) return;

        hideSuggestions();
        form.reset();

        // The bottom filter row may have narrowed masterStations - refetch the
        // full unfiltered list so the top search never combines with it.
        await fetchStations();

        if (item.dataset.type === 'station') {
            const id = item.dataset.id;
            displayedStations = masterStations.filter((station) => String(station.id) === String(id));
        } else {
            const township = item.dataset.value;
            displayedStations = masterStations.filter((station) => station.township === township);
        }

        renderCurrent();
    });

    document.addEventListener('click', (event) => {
        if (!searchWrap.contains(event.target)) {
            hideSuggestions();
        }
    });
});
