@extends('layouts.admin')

@section('title', 'Station Owners - EV Route Planner')

@push('head')
    @vite(['resources/js/pages/admin-station-owners.js'])
@endpush

@section('content')
    <h2 class="mb-1">Station Owners</h2>
    <p class="text-muted">Review new registrations and manage the status of already-accepted station owner accounts.</p>

    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <label for="station-owners-search" class="form-label">Search</label>
            <input type="text" class="form-control" id="station-owners-search" placeholder="Search by name or email...">
        </div>
        <div class="col-md-6">
            <label for="station-owners-status-filter" class="form-label">Status</label>
            <select class="form-select" id="station-owners-status-filter">
                <option value="">All</option>
                <option value="pending">Pending</option>
                <option value="suspended">Suspended</option>
                <option value="active">Accepted</option>
                <option value="rejected">Rejected</option>
            </select>
        </div>
    </div>

    <ul class="nav nav-tabs mb-3" id="stationOwnerTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="tab-pending-btn" data-bs-toggle="tab" data-bs-target="#tab-pending"
                    data-tab-key="pending" type="button" role="tab">Pending</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-accepted-btn" data-bs-toggle="tab" data-bs-target="#tab-accepted"
                    data-tab-key="accepted" type="button" role="tab">Accepted &amp; Suspended</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-rejected-btn" data-bs-toggle="tab" data-bs-target="#tab-rejected"
                    data-tab-key="rejected" type="button" role="tab">Rejected</button>
        </li>
    </ul>

    <div id="station-owners-error" class="alert alert-danger d-none"></div>

    <div class="tab-content" id="stationOwnerTabsContent">
        {{-- Pending: Accept/Reject only. --}}
        <div class="tab-pane fade show active" id="tab-pending" role="tabpanel">
            <div id="pending-loading" class="text-muted">Loading pending station owners...</div>
            <div id="pending-empty" class="text-center py-5 d-none">
                <i class="bi bi-people coming-soon-icon"></i>
                <h5 class="mt-3">No pending registrations</h5>
            </div>
            <div id="pending-table-wrap" class="d-none">
                <div class="card border-0 shadow-sm">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Registered</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody id="pending-tbody"></tbody>
                        </table>
                    </div>
                </div>
                <nav aria-label="Pending pagination" id="pending-pagination" class="mt-3 d-none">
                    <ul class="pagination justify-content-center mb-0">
                        <li class="page-item" id="pending-prev-item"><a class="page-link" href="#" id="pending-prev">Previous</a></li>
                        <li class="page-item" id="pending-next-item"><a class="page-link" href="#" id="pending-next">Next</a></li>
                    </ul>
                </nav>
            </div>
        </div>

        {{-- Accepted & Suspended: shown on the same view, per requirements - Suspend/Reactivate per row. --}}
        <div class="tab-pane fade" id="tab-accepted" role="tabpanel">
            <div id="accepted-loading" class="text-muted">Loading accepted station owners...</div>
            <div id="accepted-empty" class="text-center py-5 d-none">
                <i class="bi bi-people coming-soon-icon"></i>
                <h5 class="mt-3">No accepted station owners yet</h5>
            </div>
            <div id="accepted-table-wrap" class="d-none">
                <div class="card border-0 shadow-sm">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Status</th>
                                    <th>Registered</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody id="accepted-tbody"></tbody>
                        </table>
                    </div>
                </div>
                <nav aria-label="Accepted pagination" id="accepted-pagination" class="mt-3 d-none">
                    <ul class="pagination justify-content-center mb-0">
                        <li class="page-item" id="accepted-prev-item"><a class="page-link" href="#" id="accepted-prev">Previous</a></li>
                        <li class="page-item" id="accepted-next-item"><a class="page-link" href="#" id="accepted-next">Next</a></li>
                    </ul>
                </nav>
            </div>
        </div>

        {{-- Rejected: terminal, display-only, no actions. --}}
        <div class="tab-pane fade" id="tab-rejected" role="tabpanel">
            <div id="rejected-loading" class="text-muted">Loading rejected station owners...</div>
            <div id="rejected-empty" class="text-center py-5 d-none">
                <i class="bi bi-people coming-soon-icon"></i>
                <h5 class="mt-3">No rejected registrations</h5>
            </div>
            <div id="rejected-table-wrap" class="d-none">
                <div class="card border-0 shadow-sm">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Registered</th>
                                </tr>
                            </thead>
                            <tbody id="rejected-tbody"></tbody>
                        </table>
                    </div>
                </div>
                <nav aria-label="Rejected pagination" id="rejected-pagination" class="mt-3 d-none">
                    <ul class="pagination justify-content-center mb-0">
                        <li class="page-item" id="rejected-prev-item"><a class="page-link" href="#" id="rejected-prev">Previous</a></li>
                        <li class="page-item" id="rejected-next-item"><a class="page-link" href="#" id="rejected-next">Next</a></li>
                    </ul>
                </nav>
            </div>
        </div>
    </div>
@endsection
