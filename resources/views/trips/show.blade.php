@extends('layouts.app')

@section('title', 'Trip Route - EV Route Planner')

@push('head')
    @vite(['resources/js/pages/trip-show.js'])
@endpush

@section('content')
    <div id="trip-app" data-trip-id="{{ $tripId }}">
        <div id="trip-loading" class="text-muted">Loading your route...</div>
        <div id="trip-error" class="alert alert-danger d-none" role="alert"></div>

        <div id="trip-content" class="detail-layout d-none">
            <div class="detail-info-column">
                <div class="floating-panel card-tint-primary">
                    <div class="d-flex align-items-start justify-content-between flex-wrap gap-2">
                        <div>
                            <h2 class="mb-1 h4">
                                <span id="trip-origin"></span>
                                <i class="bi bi-arrow-right mx-1"></i>
                                <span id="trip-destination"></span>
                            </h2>
                            <p class="text-muted mb-0 small" id="trip-meta"></p>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <button type="button" class="btn btn-secondary btn-sm" id="navigate-btn">
                                <i class="bi bi-signpost-split"></i> Navigate
                            </button>
                            <button type="button" class="btn btn-neutral btn-sm d-none" id="recalculate-btn">
                                <i class="bi bi-arrow-repeat"></i> Recalculate
                            </button>
                            <span class="badge-btn-match" id="trip-status"></span>
                        </div>
                    </div>
                    <div id="navigate-status" class="small mt-2 d-none"></div>

                    <div id="start-trip-wrap" class="mt-2 d-none">
                        <button type="button" class="btn btn-primary btn-sm" id="start-trip-btn">
                            <i class="bi bi-play-circle"></i> Start Trip
                        </button>
                        <div id="start-trip-error" class="small text-danger mt-1 d-none"></div>
                    </div>
                    <div id="active-trip-banner" class="small mt-2 d-none p-2 rounded"
                         style="background-color: var(--color-accent-soft);">
                        <i class="bi bi-broadcast"></i> This trip is currently active &mdash;
                        <a href="{{ route('trips.live') }}" class="fw-semibold">Go to Live Trip</a>
                    </div>
                </div>

                <div class="floating-panel card-tint-secondary">
                    <h6 class="text-uppercase small fw-semibold text-muted mb-3">Trip Summary</h6>
                    <div class="row g-3 text-center">
                        <div class="col-4">
                            <div class="text-muted small">Distance</div>
                            <div class="fs-5 fw-semibold" id="stat-distance">&mdash;</div>
                        </div>
                        <div class="col-4">
                            <div class="text-muted small">Duration</div>
                            <div class="fs-5 fw-semibold" id="stat-duration">&mdash;</div>
                        </div>
                        <div class="col-4">
                            <div class="text-muted small">Charging Stops</div>
                            <div class="fs-5 fw-semibold" id="stat-stops">&mdash;</div>
                        </div>
                    </div>
                </div>

                <div class="floating-panel card-tint-terracotta">
                    <h6 class="text-uppercase small fw-semibold text-muted mb-3">Charging Stops</h6>
                    <div id="stops-empty" class="text-muted small d-none">
                        No charging stops needed - your vehicle's range comfortably covers this route
                        on the battery you started with.
                    </div>
                    <ol id="stops-list" class="list-group list-group-numbered list-group-flush"></ol>
                </div>
            </div>

            <div class="detail-map-column">
                <div class="floating-panel detail-map-panel">
                    <div id="trip-map"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="recalculateModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form id="recalculate-form" novalidate>
                    <div class="modal-header">
                        <h5 class="modal-title">Recalculate Route</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div id="recalculate-form-error" class="alert alert-danger d-none" role="alert"></div>

                        <div class="mb-3">
                            <label for="recalculate-reason" class="form-label">Reason</label>
                            <input type="text" class="form-control" id="recalculate-reason" name="reason"
                                   placeholder="e.g. My planned charging station is unavailable" required maxlength="255">
                            <div class="invalid-feedback" data-error-for="reason"></div>
                        </div>

                        <div class="mb-3">
                            <label for="recalculate-battery" class="form-label">Current battery %</label>
                            <input type="number" class="form-control" id="recalculate-battery"
                                   name="current_battery_percent" min="0" max="100" step="1" required>
                            <div class="invalid-feedback" data-error-for="current_battery_percent"></div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-neutral" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="recalculate-form-submit">Recalculate</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
