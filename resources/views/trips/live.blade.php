@extends('layouts.app')

@section('title', 'Live Trip - EV Route Planner')

@push('head')
    @vite(['resources/js/pages/live-trip.js'])
@endpush

@section('content')
    <div id="live-trip-app">
        <div id="live-trip-loading" class="text-muted">Checking for an active trip...</div>

        <div id="live-trip-empty" class="text-center py-5 d-none">
            <i class="bi bi-broadcast coming-soon-icon"></i>
            <h5 class="mt-3">No active trip</h5>
            <p class="text-muted">Start a trip from its result page to see it here.</p>
            <div class="d-flex justify-content-center gap-2 mt-3">
                <a href="{{ route('trips.plan') }}" class="btn btn-primary">Plan a Trip</a>
                <a href="{{ route('trips.history') }}" class="btn btn-neutral">Trip History</a>
            </div>
        </div>

        <div id="live-trip-completed" class="text-center py-5 d-none">
            <i class="bi bi-check-circle-fill coming-soon-icon text-success"></i>
            <h5 class="mt-3">Trip completed!</h5>
            <p class="text-muted">You've arrived at your destination.</p>
            <a href="{{ route('trips.history') }}" class="btn btn-primary mt-2">View Trip History</a>
        </div>

        <div id="live-trip-cancelled" class="text-center py-5 d-none">
            <i class="bi bi-x-circle-fill coming-soon-icon text-danger"></i>
            <h5 class="mt-3">Trip cancelled</h5>
            <p class="text-muted">You can plan a new trip whenever you're ready.</p>
            <a href="{{ route('trips.plan') }}" class="btn btn-primary mt-2">Plan a Trip</a>
        </div>

        <div id="live-trip-content" class="detail-layout d-none">
            <div class="detail-info-column">
                <div class="floating-panel card-tint-primary">
                    <div class="d-flex align-items-start justify-content-between flex-wrap gap-2">
                        <h2 class="mb-1 h4">
                            <span id="live-trip-origin"></span>
                            <i class="bi bi-arrow-right mx-1"></i>
                            <span id="live-trip-destination"></span>
                        </h2>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-neutral btn-sm" id="recalculate-btn">
                                <i class="bi bi-arrow-repeat"></i> Recalculate
                            </button>
                            <button type="button" class="btn btn-danger btn-sm" id="cancel-trip-btn">
                                <i class="bi bi-x-circle"></i> Cancel Trip
                            </button>
                        </div>
                    </div>
                    <p class="text-muted mb-0 small" id="live-trip-meta"></p>

                    <div id="geolocation-status" class="small mt-2 d-none"></div>
                    <button type="button" class="btn btn-secondary btn-sm mt-2 d-none" id="geolocation-retry-btn">
                        <i class="bi bi-arrow-clockwise"></i> Retry Location Access
                    </button>
                    <div id="cancel-trip-error" class="small text-danger mt-2 d-none"></div>
                </div>

                <div class="floating-panel card-tint-accent">
                    <h6 class="text-uppercase small fw-semibold text-muted mb-2">Progress</h6>
                    <div class="fw-semibold" id="live-trip-progress-text">&mdash;</div>
                    <div class="text-muted small" id="live-trip-next-target">&mdash;</div>

                    <button type="button" class="btn btn-primary btn-sm mt-2 d-none" id="complete-trip-btn">
                        <i class="bi bi-check-circle"></i> Complete Trip
                    </button>
                    <div id="complete-trip-error" class="small text-danger mt-2 d-none"></div>
                </div>

                <div class="floating-panel card-tint-terracotta">
                    <h6 class="text-uppercase small fw-semibold text-muted mb-3">Charging Stops</h6>
                    <div id="live-stops-empty" class="text-muted small d-none">
                        No charging stops on this route.
                    </div>
                    <ol id="live-stops-list" class="list-group list-group-numbered list-group-flush"></ol>
                </div>
            </div>

            <div class="detail-map-column">
                <div class="floating-panel detail-map-panel">
                    <div id="live-trip-map"></div>
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
