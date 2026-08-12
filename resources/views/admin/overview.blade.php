@extends('layouts.admin')

@section('title', 'Overview - EV Route Planner')

@push('head')
    @vite(['resources/js/pages/admin-overview.js'])
@endpush

@section('content')
    <div id="admin-overview-app">
        <h2 class="mb-1">Welcome, {{ auth()->user()->name }}</h2>
        <p class="text-muted mb-4">A quick snapshot of the whole platform.</p>

        <div id="overview-loading" class="text-muted">Loading...</div>

        <div id="overview-content" class="d-none">
            <div class="row g-3 mb-3">
                <div class="col-6 col-lg-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body text-center">
                            <div class="text-muted small text-uppercase">Total Users</div>
                            <div class="fs-3 fw-semibold" id="stat-total-users">&mdash;</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body text-center">
                            <div class="text-muted small text-uppercase">Total Stations</div>
                            <div class="fs-3 fw-semibold" id="stat-total-stations">&mdash;</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body text-center">
                            <div class="text-muted small text-uppercase">Total Trips</div>
                            <div class="fs-3 fw-semibold" id="stat-total-trips">&mdash;</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body text-center">
                            <div class="text-muted small text-uppercase">Active Today</div>
                            <div class="fs-3 fw-semibold" id="stat-active-today">&mdash;</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-md-4">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <h6 class="text-uppercase small fw-semibold text-muted mb-2">Pending Station Verifications</h6>
                            <div class="fs-3 fw-semibold" id="stat-pending-verifications">&mdash;</div>
                            <p class="text-muted small mb-0 mt-2">Station verification management is coming in a future update.</p>
                        </div>
                    </div>
                </div>

                <div class="col-md-8">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <h6 class="text-uppercase small fw-semibold text-muted mb-3">Recent Registrations</h6>
                            <ul class="list-group list-group-flush" id="recent-registrations-list"></ul>
                            <p class="text-muted small mb-0 d-none" id="recent-registrations-empty">No registrations yet.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
