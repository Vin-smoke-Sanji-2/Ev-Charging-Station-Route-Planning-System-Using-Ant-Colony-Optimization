@extends('layouts.app')

@section('title', 'Dashboard - EV Route Planner')

@push('head')
    @vite(['resources/js/pages/dashboard.js'])
@endpush

@section('content')
    <div class="dashboard-map-shell">
        <div id="dashboard-map"></div>

        <div class="dashboard-overlay dashboard-welcome">
            <span class="small">Welcome back, {{ auth()->user()->name }}</span>
        </div>

        <div class="dashboard-overlay dashboard-search" id="dashboard-search-wrap">
            <div class="input-group dashboard-search-bar shadow-sm">
                <span class="input-group-text border-0"><i class="bi bi-search"></i></span>
                <input type="text" class="form-control border-0" id="dashboard-search"
                       placeholder="Search stations or townships..." autocomplete="off">
            </div>
            <div id="dashboard-suggestions" class="list-group shadow-sm d-none"
                 style="position:absolute; z-index:1000; top:100%; left:0; right:0;"></div>
        </div>

        <div class="dashboard-overlay dashboard-cards">
            <a href="{{ route('trips.plan') }}" class="text-decoration-none">
                <div class="card h-100 border-0 shadow-sm floating-card floating-card--1 card-tint-primary">
                    <div class="card-body">
                        <div class="feature-icon-chip feature-icon-chip--primary"><i class="bi bi-signpost-split"></i></div>
                        <h6 class="mt-2 mb-1">Plan a Trip</h6>
                        <p class="text-muted small mb-0">Get a route with recommended charging stops.</p>
                    </div>
                </div>
            </a>
            <a href="{{ route('vehicles.index') }}" class="text-decoration-none">
                <div class="card h-100 border-0 shadow-sm floating-card floating-card--2 card-tint-terracotta">
                    <div class="card-body">
                        <div class="feature-icon-chip feature-icon-chip--terracotta"><i class="bi bi-car-front"></i></div>
                        <h6 class="mt-2 mb-1">My EVs</h6>
                        <p class="text-muted small mb-0">Manage the vehicles on your account.</p>
                    </div>
                </div>
            </a>
            <a href="{{ route('stations.index') }}" class="text-decoration-none">
                <div class="card h-100 border-0 shadow-sm floating-card floating-card--3 card-tint-plum">
                    <div class="card-body">
                        <div class="feature-icon-chip feature-icon-chip--plum"><i class="bi bi-ev-station"></i></div>
                        <h6 class="mt-2 mb-1">Browse Stations</h6>
                        <p class="text-muted small mb-0">Search verified charging stations.</p>
                    </div>
                </div>
            </a>
        </div>

        <button type="button" class="btn btn-light shadow-sm dashboard-overlay dashboard-guide-btn"
                data-bs-toggle="modal" data-bs-target="#mapGuideModal">
            <i class="bi bi-question-circle"></i> Map Guide
        </button>
    </div>

    <div class="modal fade" id="mapGuideModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Map Guide</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small">Marker colors show what kind of charging a station offers.</p>
                    <ul class="list-unstyled mb-0 dashboard-guide-list">
                        <li class="d-flex align-items-start gap-2 mb-3">
                            <i class="bi bi-geo-alt-fill dashboard-guide-swatch" style="color:#2563eb;"></i>
                            <div>
                                <div class="fw-semibold">DC fast charging &middot; 24/7</div>
                                <div class="text-muted small">Has a CCS2 or CHAdeMO slot and is open around the clock.</div>
                            </div>
                        </li>
                        <li class="d-flex align-items-start gap-2 mb-3">
                            <i class="bi bi-geo-alt-fill dashboard-guide-swatch" style="color:#9333ea;"></i>
                            <div>
                                <div class="fw-semibold">DC fast charging &middot; limited hours</div>
                                <div class="text-muted small">Has a CCS2 or CHAdeMO slot but isn't open 24/7.</div>
                            </div>
                        </li>
                        <li class="d-flex align-items-start gap-2 mb-3">
                            <!-- Deliberately still green (#16a34a) - the one permanent exception to this
                                 project's design-system green cleanup, not an oversight. See CLAUDE.md. -->
                            <i class="bi bi-geo-alt-fill dashboard-guide-swatch" style="color:#16a34a;"></i>
                            <div>
                                <div class="fw-semibold">AC charging only &middot; 24/7</div>
                                <div class="text-muted small">Type2 slots only, no DC connector, open around the clock.</div>
                            </div>
                        </li>
                        <li class="d-flex align-items-start gap-2 mb-3">
                            <i class="bi bi-geo-alt-fill dashboard-guide-swatch" style="color:#f59e0b;"></i>
                            <div>
                                <div class="fw-semibold">AC charging only &middot; limited hours</div>
                                <div class="text-muted small">Type2 slots only, and isn't open 24/7.</div>
                            </div>
                        </li>
                        <li class="d-flex align-items-start gap-2">
                            <i class="bi bi-geo-alt-fill dashboard-guide-swatch" style="color:#6b7280;"></i>
                            <div>
                                <div class="fw-semibold">No slot data yet</div>
                                <div class="text-muted small">Station is verified but has no charging slots recorded.</div>
                            </div>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
@endsection
