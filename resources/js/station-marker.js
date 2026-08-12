import L from 'leaflet';

// Mirrors the exact categorization dashboard.js already used for its map
// markers, extracted here so any other page showing a station marker (this
// Station Detail page) matches Dashboard's coloring/shape exactly rather
// than reimplementing it slightly differently. Dashboard's own station
// objects (from GET /api/stations) always carry has_dc_connector/
// has_ac_connector; Station Detail's (from GET /api/stations/{id}) don't,
// so categoryFor() falls back to deriving the same fact from the station's
// own `slots` array - the same power_type-driven logic the backend uses to
// compute those flags in the first place, not a different heuristic.
export const CATEGORIES = {
    dc247: { color: '#2563eb', label: 'DC fast &middot; 24/7' },
    dcLimited: { color: '#9333ea', label: 'DC fast &middot; limited hours' },
    ac247: { color: '#16a34a', label: 'AC only &middot; 24/7' },
    acLimited: { color: '#f59e0b', label: 'AC only &middot; limited hours' },
    none: { color: '#6b7280', label: 'No slot data yet' },
};

export function categoryFor(station) {
    const is247 = (station.operating_hours || '').toLowerCase().includes('24/7');
    const slots = station.slots || [];
    const hasDc = station.has_dc_connector ?? slots.some((slot) => slot.power_type === 'DC');
    const hasAc = station.has_ac_connector ?? slots.some((slot) => slot.power_type === 'AC');

    if (hasDc) {
        return is247 ? CATEGORIES.dc247 : CATEGORIES.dcLimited;
    }

    if (hasAc) {
        return is247 ? CATEGORIES.ac247 : CATEGORIES.acLimited;
    }

    return CATEGORIES.none;
}

// Location-pin glyph instead of a plain circle marker - color is set inline
// per marker to match its station category above.
export function markerIconFor(color) {
    return L.divIcon({
        className: 'dashboard-marker-pin-wrap',
        html: `<i class="bi bi-geo-alt-fill dashboard-marker-pin" style="color:${color};"></i>`,
        iconSize: [28, 28],
        iconAnchor: [14, 26],
        popupAnchor: [0, -26],
    });
}
