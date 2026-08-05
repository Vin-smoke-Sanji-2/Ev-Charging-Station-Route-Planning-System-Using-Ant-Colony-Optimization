@extends('layouts.app')

@section('title', 'Trip History - EV Route Planner')

@push('head')
    @vite(['resources/js/pages/trip-history.js'])
@endpush

@section('content')
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h2 class="mb-1">Trip History</h2>
            <p class="text-muted mb-0">Every trip you've planned, most recent first.</p>
        </div>
        <a href="{{ route('trips.plan') }}" class="btn btn-primary">
            <i class="bi bi-signpost-split"></i> Plan a Trip
        </a>
    </div>

    <div id="trips-loading" class="text-muted">Loading your trips...</div>

    <div id="trips-empty" class="text-center py-5 d-none">
        <i class="bi bi-clock-history coming-soon-icon"></i>
        <h5 class="mt-3">No trips yet</h5>
        <p class="text-muted">
            <a href="{{ route('trips.plan') }}">Plan your first one</a> to see it show up here.
        </p>
    </div>

    <div id="trips-table-wrap" class="d-none">
        <div class="card border-0 shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Route</th>
                            <th>Vehicle</th>
                            <th>Status</th>
                            <th>Distance</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="trips-tbody"></tbody>
                </table>
            </div>
        </div>

        <nav aria-label="Trip history pagination" id="trips-pagination" class="mt-3 d-none">
            <ul class="pagination justify-content-center">
                <li class="page-item" id="trips-prev-item">
                    <a class="page-link" href="#" id="trips-prev">Previous</a>
                </li>
                <li class="page-item" id="trips-next-item">
                    <a class="page-link" href="#" id="trips-next">Next</a>
                </li>
            </ul>
        </nav>
    </div>
@endsection
