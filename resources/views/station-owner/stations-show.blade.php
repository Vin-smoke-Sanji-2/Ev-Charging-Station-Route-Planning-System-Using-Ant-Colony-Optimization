@extends('layouts.station-owner')

@section('title', 'Manage Station - EV Route Planner')

@push('head')
    @vite(['resources/js/pages/station-owner-stations-show.js'])
@endpush

@section('content')
    <div id="station-manage-app" data-station-id="{{ $stationId }}">
        <a href="{{ route('station-owner.stations') }}" class="text-decoration-none small mb-2 d-inline-block">
            <i class="bi bi-arrow-left"></i> Back to My Stations
        </a>

        <div id="manage-loading" class="text-muted">Loading station...</div>

        <div id="manage-not-owner" class="alert alert-danger d-none" role="alert">
            You don't have access to manage this station.
        </div>

        <div id="manage-content" class="d-none">
            <div class="d-flex align-items-center gap-2 mb-3">
                <h2 class="mb-0" id="manage-station-name">Station</h2>
                <span id="manage-verification-badge"></span>
            </div>

            <div class="row g-3">
                <div class="col-lg-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <h5 class="card-title mb-3">Station Details</h5>

                            <div id="details-form-error" class="alert alert-danger d-none" role="alert"></div>
                            <div id="details-form-success" class="alert alert-success d-none" role="alert">Saved.</div>

                            <form id="details-form" novalidate>
                                <div class="mb-3">
                                    <label for="detail_name" class="form-label">Station name</label>
                                    <input type="text" class="form-control" id="detail_name" name="name" required>
                                    <div class="invalid-feedback" data-error-for="name"></div>
                                </div>
                                <div class="mb-3">
                                    <label for="detail_address" class="form-label">Address</label>
                                    <input type="text" class="form-control" id="detail_address" name="address">
                                    <div class="invalid-feedback" data-error-for="address"></div>
                                </div>
                                <div class="mb-3">
                                    <label for="detail_township" class="form-label">Township</label>
                                    <input type="text" class="form-control" id="detail_township" name="township">
                                    <div class="invalid-feedback" data-error-for="township"></div>
                                </div>
                                <div class="row g-2">
                                    <div class="col-6">
                                        <label for="detail_charging_speed" class="form-label">Charging speed</label>
                                        <select class="form-select" id="detail_charging_speed" name="charging_speed">
                                            <option value="">Not set</option>
                                            <option value="standard">Standard</option>
                                            <option value="fast">Fast</option>
                                        </select>
                                        <div class="invalid-feedback" data-error-for="charging_speed"></div>
                                    </div>
                                    <div class="col-6">
                                        <label for="detail_operating_hours" class="form-label">Operating hours</label>
                                        <input type="text" class="form-control" id="detail_operating_hours" name="operating_hours" placeholder="e.g. 24/7">
                                        <div class="invalid-feedback" data-error-for="operating_hours"></div>
                                    </div>
                                </div>

                                <button type="submit" class="btn btn-primary mt-3" id="details-form-submit">Save Changes</button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <h5 class="card-title mb-0">Slots</h5>
                                <button type="button" class="btn btn-primary btn-sm d-flex align-items-center gap-2" id="add-slot-btn"
                                        data-bs-toggle="modal" data-bs-target="#slotModal">
                                    <span class="btn-icon-badge"><i class="bi bi-plus-lg"></i></span> Add Slot
                                </button>
                            </div>

                            <div id="slots-empty" class="text-muted d-none">No slots added yet.</div>

                            <div id="slots-list" class="d-flex flex-column gap-2"></div>
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <div class="card border-0 shadow-sm">
                        <div class="card-body">
                            <h5 class="card-title mb-1">Charging Queue</h5>
                            <p class="text-muted small mb-3">
                                This is one shared queue across all of this station's slots, not a
                                line per slot - whoever has been waiting longest gets the next slot
                                that frees up, which may not be the exact physical slot they were
                                "waiting for."
                            </p>

                            <div id="queue-loading" class="text-muted">Loading queue...</div>

                            <div id="queue-content" class="d-none">
                                <h6 class="text-uppercase small fw-semibold text-muted mb-2">Charging now</h6>
                                <div id="queue-charging-empty" class="text-muted small mb-3 d-none">No one is currently charging.</div>
                                <div id="queue-charging-list" class="d-flex flex-column gap-2 mb-4"></div>

                                <h6 class="text-uppercase small fw-semibold text-muted mb-2">Waiting</h6>
                                <div id="queue-waiting-empty" class="text-muted small d-none">No one is waiting.</div>
                                <div id="queue-waiting-list" class="d-flex flex-column gap-2"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add / Edit Slot modal (single reusable form, matching My EVs' pattern) -->
    <div class="modal fade" id="slotModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form id="slot-form" novalidate>
                    <div class="modal-header">
                        <h5 class="modal-title" id="slot-modal-title">Add Slot</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" id="slot_id" name="slot_id" value="">

                        <div id="slot-form-error" class="alert alert-danger d-none" role="alert"></div>

                        <div class="mb-3">
                            <label for="slot_code" class="form-label">Slot code</label>
                            <input type="text" class="form-control" id="slot_code" name="slot_code" placeholder="e.g. A1" required>
                            <div class="invalid-feedback" data-error-for="slot_code"></div>
                            <div class="form-text" id="slot-code-locked-hint">Slot code can't be changed after creation.</div>
                        </div>

                        <div class="mb-3">
                            <label for="connector_type" class="form-label">Connector type</label>
                            <input type="text" class="form-control" id="connector_type" name="connector_type" placeholder="e.g. CCS2" required>
                            <div class="invalid-feedback" data-error-for="connector_type"></div>
                        </div>

                        <div class="row g-2">
                            <div class="col-6">
                                <label for="power_type" class="form-label">Power type</label>
                                <select class="form-select" id="power_type" name="power_type" required>
                                    <option value="AC">AC</option>
                                    <option value="DC">DC</option>
                                </select>
                                <div class="invalid-feedback" data-error-for="power_type"></div>
                            </div>
                            <div class="col-6">
                                <label for="power_kw" class="form-label">Power (kW)</label>
                                <input type="number" step="0.1" min="0" class="form-control" id="power_kw" name="power_kw" required>
                                <div class="invalid-feedback" data-error-for="power_kw"></div>
                            </div>
                        </div>

                        <div class="mb-3 mt-2">
                            <label for="slot_status" class="form-label">Status</label>
                            <select class="form-select" id="slot_status" name="status">
                                <option value="available">Available</option>
                                <option value="occupied">Occupied</option>
                                <option value="maintenance">Maintenance</option>
                            </select>
                            <div class="invalid-feedback" data-error-for="status"></div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-neutral" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="slot-form-submit">Save Slot</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
