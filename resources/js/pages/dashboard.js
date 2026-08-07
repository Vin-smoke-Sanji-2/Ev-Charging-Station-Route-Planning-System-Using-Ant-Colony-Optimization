import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { apiFetch } from '../api.js';

const CATEGORIES = {
    dc247: { color: '#2563eb', label: 'DC fast &middot; 24/7' },
    dcLimited: { color: '#9333ea', label: 'DC fast &middot; limited hours' },
    ac247: { color: '#16a34a', label: 'AC only &middot; 24/7' },
    acLimited: { color: '#f59e0b', label: 'AC only &middot; limited hours' },
    none: { color: '#6b7280', label: 'No slot data yet' },
};

let map;
let stations = [];
let markersById = new Map();
let searchToken = 0;
let lastSuggestions = { stations: [], locations: [] };

// DC wins when a station has both connector types - a DC-capable station is
// still meaningfully "DC fast charging" even if it also happens to have an
// AC slot. Worth revisiting if that turns out to be the wrong call once
// there's real usage data on which category owners/EV drivers expect.
function categoryFor(station) {
    const is247 = (station.operating_hours || '').toLowerCase().includes('24/7');

    if (station.has_dc_connector) {
        return is247 ? CATEGORIES.dc247 : CATEGORIES.dcLimited;
    }

    if (station.has_ac_connector) {
        return is247 ? CATEGORIES.ac247 : CATEGORIES.acLimited;
    }

    return CATEGORIES.none;
}

// Location-pin glyph instead of a plain circle - color is set inline per
// marker to match its station category (see CATEGORIES above), including
// the deliberately-still-green AC+24/7 entry (a permanent exception to the
// rest of this project's green cleanup, not an oversight).
function markerIconFor(color) {
    return L.divIcon({
        className: 'dashboard-marker-pin-wrap',
        html: `<i class="bi bi-geo-alt-fill dashboard-marker-pin" style="color:${color};"></i>`,
        iconSize: [28, 28],
        iconAnchor: [14, 26],
        popupAnchor: [0, -26],
    });
}

function popupHtml(station, category) {
    return `
        <div class="dashboard-popup">
            <div class="fw-semibold">${station.name}</div>
            <div class="text-muted small mb-1">${station.township ?? ''}</div>
            <span class="badge mb-2" style="background-color:${category.color}; color:#fff;">${category.label}</span>
            <div class="small mb-2">${station.available_slots_count} / ${station.total_slots_count} slots available</div>
            <a href="/stations/${station.id}" class="btn btn-sm btn-secondary">View Details</a>
        </div>
    `;
}

function addMarker(station) {
    if (station.latitude == null || station.longitude == null) return;

    const category = categoryFor(station);
    const latLng = [Number(station.latitude), Number(station.longitude)];
    const marker = L.marker(latLng, { icon: markerIconFor(category.color) })
        .addTo(map)
        .bindPopup(popupHtml(station, category));

    markersById.set(String(station.id), marker);
}

function stationPoints(list) {
    return list
        .filter((station) => station.latitude != null && station.longitude != null)
        .map((station) => [Number(station.latitude), Number(station.longitude)]);
}

function fitToStations(list) {
    const points = stationPoints(list);

    if (points.length > 1) {
        map.fitBounds(points, { padding: [40, 40] });
    } else if (points.length === 1) {
        map.setView(points[0], 14);
    }
}

async function initDashboard() {
    const response = await apiFetch('/api/stations');
    stations = response.ok ? await response.json() : [];

    // The map must settle on its final view before the tile layer is added
    // (see trip-show.js's renderMap for why: an unset view followed by an
    // animated fitBounds() thrashes through every intermediate tile grid).
    // zoomControl is disabled here and re-added at bottom-right below - not
    // an interaction limit, dragging/scroll-zoom/double-click-zoom are all
    // still on by default. The default top-left zoom control would
    // otherwise sit directly behind the "Welcome back" overlay.
    map = L.map('dashboard-map', { zoomControl: false });
    L.control.zoom({ position: 'bottomright' }).addTo(map);

    const points = stationPoints(stations);

    if (points.length > 0) {
        map.fitBounds(points, { padding: [40, 40], animate: false });
    } else {
        map.setView([19.7, 96.1], 6, { animate: false });
    }

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors',
        maxZoom: 19,
    }).addTo(map);

    stations.forEach(addMarker);
}

function hideSuggestions() {
    searchToken += 1;
    lastSuggestions = { stations: [], locations: [] };
    const box = document.getElementById('dashboard-suggestions');
    box.classList.add('d-none');
    box.innerHTML = '';
}

function renderSuggestions(data) {
    const box = document.getElementById('dashboard-suggestions');
    const stationsList = data.stations || [];
    const locationsList = data.locations || [];

    lastSuggestions = { stations: stationsList, locations: locationsList };

    if (stationsList.length === 0 && locationsList.length === 0) {
        hideSuggestions();
        return;
    }

    let html = '';

    if (stationsList.length) {
        html += '<div class="list-group-item small text-muted fw-semibold">Stations</div>';
        html += stationsList.map((station) => `
            <button type="button" class="list-group-item list-group-item-action suggestion-item"
                    data-type="station" data-id="${station.id}">
                ${station.name} <span class="text-muted small">- ${station.township ?? ''}</span>
            </button>
        `).join('');
    }

    if (locationsList.length) {
        html += '<div class="list-group-item small text-muted fw-semibold">Locations</div>';
        html += locationsList.map((township) => `
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

function selectStation(id) {
    const station = stations.find((s) => String(s.id) === String(id));
    if (!station) return;

    map.setView([Number(station.latitude), Number(station.longitude)], 15);

    const marker = markersById.get(String(id));
    if (marker) marker.openPopup();
}

function selectLocation(township) {
    fitToStations(stations.filter((station) => station.township === township));
}

document.addEventListener('DOMContentLoaded', () => {
    const mapEl = document.getElementById('dashboard-map');
    if (!mapEl) return;

    initDashboard();

    const searchInput = document.getElementById('dashboard-search');
    const searchWrap = document.getElementById('dashboard-search-wrap');
    const suggestionsBox = document.getElementById('dashboard-suggestions');

    const debouncedSearch = debounce((q) => fetchSuggestions(q), 250);

    searchInput.addEventListener('input', () => {
        const q = searchInput.value.trim();

        if (q === '') {
            hideSuggestions();
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

            if (lastSuggestions.stations.length) {
                const id = lastSuggestions.stations[0].id;
                hideSuggestions();
                selectStation(id);
            } else if (lastSuggestions.locations.length) {
                const township = lastSuggestions.locations[0];
                hideSuggestions();
                selectLocation(township);
            }
        }
    });

    suggestionsBox.addEventListener('click', (event) => {
        const item = event.target.closest('.suggestion-item');
        if (!item) return;

        if (item.dataset.type === 'station') {
            hideSuggestions();
            selectStation(item.dataset.id);
        } else {
            hideSuggestions();
            selectLocation(item.dataset.value);
        }
    });

    document.addEventListener('click', (event) => {
        if (!searchWrap.contains(event.target)) {
            hideSuggestions();
        }
    });
});
