{{-- Shared station name/address/township/charging_speed/operating_hours
     fields + Leaflet map picker - used by both station-owner registration
     (register.blade.php) and "Add Another Station" (station-owner/
     stations-index.blade.php's modal), paired with resources/js/
     station-form.js. Field ids are fixed (station_name, station-picker-map,
     etc.) so that shared JS module works unchanged regardless of which
     page embeds this partial - never include this partial twice on the
     same page. --}}
<div class="row g-4">
    <div class="col-md-6">
        <div class="mb-3">
            <label for="station_name" class="form-label">Station name</label>
            <input type="text" class="form-control" id="station_name" name="station_name" required>
            <div class="invalid-feedback" data-error-for="name"></div>
        </div>
        <div class="mb-3">
            <label for="station_address" class="form-label">Address <span class="text-muted">(optional)</span></label>
            <input type="text" class="form-control" id="station_address" name="station_address">
            <div class="invalid-feedback" data-error-for="address"></div>
        </div>
        <div class="mb-3">
            <label for="station_township" class="form-label">Township <span class="text-muted">(optional)</span></label>
            <input type="text" class="form-control" id="station_township" name="station_township">
            <div class="invalid-feedback" data-error-for="township"></div>
        </div>
        <div class="row g-2">
            <div class="col-6">
                <label for="station_charging_speed" class="form-label">Charging speed <span class="text-muted">(optional)</span></label>
                <select class="form-select" id="station_charging_speed" name="station_charging_speed">
                    <option value="">Not set</option>
                    <option value="standard">Standard</option>
                    <option value="fast">Fast</option>
                </select>
                <div class="invalid-feedback" data-error-for="charging_speed"></div>
            </div>
            <div class="col-6">
                <label for="station_operating_hours" class="form-label">Operating hours <span class="text-muted">(optional)</span></label>
                <input type="text" class="form-control" id="station_operating_hours" name="station_operating_hours" placeholder="e.g. 24/7">
                <div class="invalid-feedback" data-error-for="operating_hours"></div>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="d-flex align-items-center justify-content-between mb-2">
            <h6 class="text-uppercase small fw-semibold text-muted mb-0">Station location</h6>
            <button type="button" class="btn btn-neutral btn-sm" id="use-my-location-btn">
                <i class="bi bi-geo-alt"></i> Use My Location
            </button>
        </div>
        <p class="text-muted small">Click anywhere on the map to place a pin at your station - drag it afterward if you need to adjust.</p>

        <div id="station-picker-map" class="station-picker-map mb-2"></div>

        <div id="location-status" class="small mb-2 d-none"></div>
        <div id="location-hint" class="small text-danger mb-2 d-none">
            Please click the map to place your station's location before submitting.
        </div>

        <input type="hidden" id="station_latitude" name="station_latitude">
        <input type="hidden" id="station_longitude" name="station_longitude">

        <div class="small text-muted">
            Selected coordinates: <span id="selected-coordinates">none yet</span>
        </div>
    </div>
</div>
