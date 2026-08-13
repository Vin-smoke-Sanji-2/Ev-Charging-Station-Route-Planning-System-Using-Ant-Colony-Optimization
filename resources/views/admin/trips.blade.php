@extends('layouts.admin')

@section('title', 'Total Trips - EV Route Planner')

@push('head')
    @vite(['resources/js/pages/admin-trips.js'])
@endpush

@section('content')
    <h2 class="mb-1">Total Trips</h2>
    <p class="text-muted">Every trip planned across the platform.</p>

    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <label for="trips-search" class="form-label">Search</label>
            <input type="text" class="form-control" id="trips-search" placeholder="Search by rider name...">
        </div>
        <div class="col-md-6">
            <label for="trips-status-filter" class="form-label">Status</label>
            <select class="form-select" id="trips-status-filter">
                <option value="">All</option>
                <option value="planned">Planned</option>
                <option value="active">Active</option>
                <option value="completed">Completed</option>
                <option value="cancelled">Cancelled</option>
            </select>
        </div>
    </div>

    <div id="trips-loading" class="text-muted">Loading trips...</div>

    <div id="trips-empty" class="text-center py-5 d-none">
        <i class="bi bi-signpost-split coming-soon-icon"></i>
        <h5 class="mt-3">No trips match these filters</h5>
    </div>

    <div id="trips-error" class="alert alert-danger d-none"></div>

    <div id="trips-table-wrap" class="d-none">
        <div class="card border-0 shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Rider</th>
                            <th>Vehicle</th>
                            <th>Route</th>
                            <th>Status</th>
                            <th>Distance</th>
                            <th>Duration</th>
                            <th>Est. Cost</th>
                            <th>Requested</th>
                        </tr>
                    </thead>
                    <tbody id="trips-tbody"></tbody>
                </table>
            </div>
        </div>

        <nav aria-label="Trips pagination" id="trips-pagination" class="mt-3 d-none">
            <ul class="pagination justify-content-center mb-0">
                <li class="page-item" id="trips-prev-item"><a class="page-link" href="#" id="trips-prev">Previous</a></li>
                <li class="page-item" id="trips-next-item"><a class="page-link" href="#" id="trips-next">Next</a></li>
            </ul>
        </nav>
    </div>
@endsection
