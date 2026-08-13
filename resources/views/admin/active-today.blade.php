@extends('layouts.admin')

@section('title', 'Active Today - EV Route Planner')

@push('head')
    @vite(['resources/js/pages/admin-active-today.js'])
@endpush

@section('content')
    <h2 class="mb-1">Active Today</h2>
    <p class="text-muted">Accounts with activity recorded today.</p>

    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <label for="active-today-search" class="form-label">Search</label>
            <input type="text" class="form-control" id="active-today-search" placeholder="Search by name or email...">
        </div>
        <div class="col-md-6">
            <label for="active-today-role-filter" class="form-label">Role</label>
            <select class="form-select" id="active-today-role-filter">
                <option value="">All</option>
                <option value="ev_owner">EV Owner</option>
                <option value="station_owner">Station Owner</option>
            </select>
        </div>
    </div>

    <div id="active-today-loading" class="text-muted">Loading...</div>

    <div id="active-today-empty" class="text-center py-5 d-none">
        <i class="bi bi-activity coming-soon-icon"></i>
        <h5 class="mt-3">No accounts active today match these filters</h5>
    </div>

    <div id="active-today-error" class="alert alert-danger d-none"></div>

    <div id="active-today-table-wrap" class="d-none">
        <div class="card border-0 shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Last Activity</th>
                        </tr>
                    </thead>
                    <tbody id="active-today-tbody"></tbody>
                </table>
            </div>
        </div>

        <nav aria-label="Active today pagination" id="active-today-pagination" class="mt-3 d-none">
            <ul class="pagination justify-content-center mb-0">
                <li class="page-item" id="active-today-prev-item"><a class="page-link" href="#" id="active-today-prev">Previous</a></li>
                <li class="page-item" id="active-today-next-item"><a class="page-link" href="#" id="active-today-next">Next</a></li>
            </ul>
        </nav>
    </div>
@endsection
