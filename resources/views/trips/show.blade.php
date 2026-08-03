@extends('layouts.app')

@section('title', 'Trip Route - EV Route Planner')

@push('head')
    @vite(['resources/js/pages/trip-show.js'])
@endpush

@section('content')
    <div id="trip-app" data-trip-id="{{ $tripId }}">
        <div id="trip-loading" class="text-muted">Loading your route...</div>
        <div id="trip-error" class="alert alert-danger d-none" role="alert"></div>

        <div id="trip-content" class="d-none">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                <div>
                    <h2 class="mb-1">
                        <span id="trip-origin"></span>
                        <i class="bi bi-arrow-right mx-1"></i>
                        <span id="trip-destination"></span>
                    </h2>
                    <p class="text-muted mb-0" id="trip-meta"></p>
                </div>
                <span class="badge bg-secondary fs-6" id="trip-status"></span>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-3 col-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="text-muted small">Distance</div>
                            <div class="fs-5 fw-semibold" id="stat-distance">&mdash;</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="text-muted small">Duration</div>
                            <div class="fs-5 fw-semibold" id="stat-duration">&mdash;</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="text-muted small">Estimated Cost</div>
                            <div class="fs-5 fw-semibold" id="stat-cost">&mdash;</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="text-muted small">Charging Stops</div>
                            <div class="fs-5 fw-semibold" id="stat-stops">&mdash;</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body p-0">
                    <div id="trip-map"></div>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h5 class="card-title">Charging Stops</h5>
                    <div id="stops-empty" class="text-muted d-none">
                        No charging stops recommended yet. Route optimization is still in development -
                        once it's live, this trip will automatically list the best stations to charge at
                        along the way.
                    </div>
                    <ol id="stops-list" class="list-group list-group-numbered"></ol>
                </div>
            </div>
        </div>
    </div>
@endsection
