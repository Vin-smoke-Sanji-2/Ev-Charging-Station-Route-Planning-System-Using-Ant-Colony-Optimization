@extends('layouts.admin')

@section('title', 'Total Users - EV Route Planner')

@push('head')
    @vite(['resources/js/pages/admin-total-users.js'])
@endpush

@section('content')
    <h2 class="mb-1">Total Users</h2>
    <p class="text-muted">Every EV owner and station owner account on the platform.</p>

    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <label for="total-users-search" class="form-label">Search</label>
            <input type="text" class="form-control" id="total-users-search" placeholder="Search by name or email...">
        </div>
        <div class="col-md-4">
            <label for="total-users-role-filter" class="form-label">Role</label>
            <select class="form-select" id="total-users-role-filter">
                <option value="">All</option>
                <option value="ev_owner">EV Owner</option>
                <option value="station_owner">Station Owner</option>
            </select>
        </div>
        {{-- Only meaningful for station owners - EV owners have no approval
             workflow or status at all, so this stays hidden unless the role
             filter above is set to Station Owner. --}}
        <div class="col-md-4 d-none" id="total-users-status-filter-wrap">
            <label for="total-users-status-filter" class="form-label">Status</label>
            <select class="form-select" id="total-users-status-filter">
                <option value="">All</option>
                <option value="pending">Pending</option>
                <option value="suspended">Suspended</option>
                <option value="active">Accepted</option>
                <option value="rejected">Rejected</option>
            </select>
        </div>
    </div>

    <div id="total-users-loading" class="text-muted">Loading users...</div>

    <div id="total-users-empty" class="text-center py-5 d-none">
        <i class="bi bi-people coming-soon-icon"></i>
        <h5 class="mt-3">No users match these filters</h5>
    </div>

    <div id="total-users-error" class="alert alert-danger d-none"></div>

    <div id="total-users-table-wrap" class="d-none">
        <div class="card border-0 shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Registered</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="total-users-tbody"></tbody>
                </table>
            </div>
        </div>

        <nav aria-label="Total users pagination" id="total-users-pagination" class="mt-3 d-none">
            <ul class="pagination justify-content-center mb-0">
                <li class="page-item" id="total-users-prev-item"><a class="page-link" href="#" id="total-users-prev">Previous</a></li>
                <li class="page-item" id="total-users-next-item"><a class="page-link" href="#" id="total-users-next">Next</a></li>
            </ul>
        </nav>
    </div>
@endsection
