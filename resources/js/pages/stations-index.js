import { apiFetch } from '../api.js';

function stationCard(station) {
    const speed = station.charging_speed
        ? `<div class="text-muted small"><i class="bi bi-lightning-charge"></i> ${station.charging_speed}</div>`
        : '';

    return `
        <div class="col-md-6 col-lg-4">
            <div class="card h-100 border-0 shadow-sm">
                <div class="card-body">
                    <h5 class="card-title mb-1">${station.name}</h5>
                    <p class="text-muted mb-1">${station.township ?? ''}</p>
                    <p class="text-muted small mb-2">${station.address ?? ''}</p>
                    <p class="mb-1">
                        <span class="badge bg-brand">
                            ${station.available_slots_count} / ${station.total_slots_count} slots available
                        </span>
                    </p>
                    ${speed}
                    <a href="/stations/${station.id}" class="btn btn-sm btn-outline-primary mt-2">View Details</a>
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

function buildQueryString(form) {
    const params = new URLSearchParams();

    const name = form.name.value.trim();
    const township = form.township.value.trim();
    const connector = form.connector.value.trim();

    if (name) params.set('name', name);
    if (township) params.set('township', township);
    if (connector) params.set('connector', connector);
    if (form.fast_only.checked) params.set('fast_only', '1');
    if (form.available_now.checked) params.set('available_now', '1');

    const qs = params.toString();
    return qs ? `?${qs}` : '';
}

async function loadStations(query = '') {
    const loading = document.getElementById('stations-loading');
    const empty = document.getElementById('stations-empty');
    const grid = document.getElementById('stations-grid');

    loading.classList.remove('d-none');
    empty.classList.add('d-none');
    grid.innerHTML = '';

    const response = await apiFetch(`/api/stations${query}`);
    const stations = response.ok ? await response.json() : [];
    renderStations(stations);
}

document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('station-filters-form');
    if (!form) return;

    const clearBtn = document.getElementById('clear-filters-btn');

    loadStations();

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        loadStations(buildQueryString(form));
    });

    clearBtn.addEventListener('click', () => {
        form.reset();
        loadStations();
    });
});
