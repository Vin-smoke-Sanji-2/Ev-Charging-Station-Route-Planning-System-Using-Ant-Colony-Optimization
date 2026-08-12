// Myanmar's real geographic bounding box (SW/NE corners, [lat, lng]) - a
// stable fact, not derived. Shared by every page with a Leaflet map so all
// of them agree on where "the country" ends, rather than each page picking
// its own numbers.
export const MYANMAR_BOUNDS = [[9.5, 92.0], [28.6, 101.2]];

// Prevents zooming out far enough to see unrelated parts of the world once
// panning is stopped at MYANMAR_BOUNDS - purely a zoom floor, doesn't limit
// panning/zooming in any other way.
export const MYANMAR_MIN_ZOOM = 6;

// Roughly the center of Myanmar - used only for a map's initial view before
// real data (stations, a route) arrives and a page's own fitBounds() takes
// over, so there's never a flash of a wrong-continent default view.
export const MYANMAR_CENTER = [19.7, 96.1];
export const MYANMAR_DEFAULT_ZOOM = 6;
