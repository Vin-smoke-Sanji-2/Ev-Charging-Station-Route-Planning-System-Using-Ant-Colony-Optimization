import { apiFetch } from '../api.js';

function favoriteCard(favorite) {
    const station = favorite.station;

    return `
        <div class="col-md-6 col-lg-4" data-favorite-card="${station.id}">
            <div class="card h-100 border-0 shadow-sm">
                <div class="card-body">
                    <h5 class="card-title mb-1">${station.name}</h5>
                    <p class="text-muted mb-1">${station.township ?? ''}</p>
                    <p class="text-muted small mb-2">${station.address ?? ''}</p>
                    <div class="d-flex gap-2">
                        <a href="/stations/${station.id}" class="btn btn-sm btn-outline-primary">View Details</a>
                        <button type="button" class="btn btn-sm btn-outline-danger remove-favorite-btn"
                                data-station-id="${station.id}">
                            <i class="bi bi-heartbreak"></i> Remove
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `;
}

async function loadFavorites() {
    const loading = document.getElementById('favorites-loading');
    const empty = document.getElementById('favorites-empty');
    const grid = document.getElementById('favorites-grid');

    loading.classList.remove('d-none');
    empty.classList.add('d-none');

    const response = await apiFetch('/api/favorites');
    const favorites = response.ok ? await response.json() : [];

    loading.classList.add('d-none');

    if (favorites.length === 0) {
        empty.classList.remove('d-none');
        grid.innerHTML = '';
        return;
    }

    grid.innerHTML = favorites.map(favoriteCard).join('');
}

document.addEventListener('DOMContentLoaded', () => {
    const grid = document.getElementById('favorites-grid');
    if (!grid) return;

    loadFavorites();

    grid.addEventListener('click', async (event) => {
        const removeBtn = event.target.closest('.remove-favorite-btn');
        if (!removeBtn) return;

        removeBtn.disabled = true;
        await apiFetch(`/api/favorites/${removeBtn.dataset.stationId}`, { method: 'DELETE' });
        await loadFavorites();
    });
});
