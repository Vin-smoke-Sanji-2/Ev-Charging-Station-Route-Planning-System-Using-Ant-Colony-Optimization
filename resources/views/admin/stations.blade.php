@extends('layouts.admin')

@section('title', 'Stations - EV Route Planner')

@push('head')
    @vite(['resources/js/pages/admin-stations.js'])
@endpush

@section('content')
    <h2 class="mb-1">Stations</h2>
    <p class="text-muted">Review new station submissions and manage the status of already-verified stations. Verifying, rejecting, or suspending only changes the station itself - it never touches the owner's account status.</p>

    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <label for="stations-search" class="form-label">Search</label>
            <input type="text" class="form-control" id="stations-search" placeholder="Search by station name...">
        </div>
        <div class="col-md-6">
            <label for="stations-status-filter" class="form-label">Status</label>
            <select class="form-select" id="stations-status-filter">
                <option value="">All</option>
                <option value="pending">Pending</option>
                <option value="verified">Verified</option>
                <option value="suspended">Suspended</option>
                <option value="rejected">Rejected</option>
            </select>
        </div>
    </div>

    <ul class="nav nav-tabs mb-3" id="stationTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="tab-pending-btn" data-bs-toggle="tab" data-bs-target="#tab-pending"
                    data-tab-key="pending" type="button" role="tab">Pending</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-verified-btn" data-bs-toggle="tab" data-bs-target="#tab-verified"
                    data-tab-key="verified" type="button" role="tab">Verified &amp; Suspended</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-rejected-btn" data-bs-toggle="tab" data-bs-target="#tab-rejected"
                    data-tab-key="rejected" type="button" role="tab">Rejected</button>
        </li>
    </ul>

    <div id="stations-error" class="alert alert-danger d-none"></div>

    <div class="tab-content" id="stationTabsContent">
        {{-- Pending: Verify/Reject only. --}}
        <div class="tab-pane fade show active" id="tab-pending" role="tabpanel">
            <div id="pending-loading" class="text-muted">Loading pending stations...</div>
            <div id="pending-empty" class="text-center py-5 d-none">
                <i class="bi bi-check-circle coming-soon-icon"></i>
                <h5 class="mt-3">Nothing waiting on review</h5>
            </div>
            <div id="pending-list" class="d-flex flex-column gap-3"></div>
            <nav aria-label="Pending stations pagination" id="pending-pagination" class="mt-3 d-none">
                <ul class="pagination justify-content-center mb-0">
                    <li class="page-item" id="pending-prev-item"><a class="page-link" href="#" id="pending-prev">Previous</a></li>
                    <li class="page-item" id="pending-next-item"><a class="page-link" href="#" id="pending-next">Next</a></li>
                </ul>
            </nav>
        </div>

        {{-- Verified & Suspended: shown on the same view - Suspend/Reactivate per card. --}}
        <div class="tab-pane fade" id="tab-verified" role="tabpanel">
            <div id="verified-loading" class="text-muted">Loading verified stations...</div>
            <div id="verified-empty" class="text-center py-5 d-none">
                <i class="bi bi-ev-station coming-soon-icon"></i>
                <h5 class="mt-3">No verified stations yet</h5>
            </div>
            <div id="verified-list" class="d-flex flex-column gap-3"></div>
            <nav aria-label="Verified stations pagination" id="verified-pagination" class="mt-3 d-none">
                <ul class="pagination justify-content-center mb-0">
                    <li class="page-item" id="verified-prev-item"><a class="page-link" href="#" id="verified-prev">Previous</a></li>
                    <li class="page-item" id="verified-next-item"><a class="page-link" href="#" id="verified-next">Next</a></li>
                </ul>
            </nav>
        </div>

        {{-- Rejected: terminal, display-only, no actions. --}}
        <div class="tab-pane fade" id="tab-rejected" role="tabpanel">
            <div id="rejected-loading" class="text-muted">Loading rejected stations...</div>
            <div id="rejected-empty" class="text-center py-5 d-none">
                <i class="bi bi-ev-station coming-soon-icon"></i>
                <h5 class="mt-3">No rejected stations</h5>
            </div>
            <div id="rejected-list" class="d-flex flex-column gap-3"></div>
            <nav aria-label="Rejected stations pagination" id="rejected-pagination" class="mt-3 d-none">
                <ul class="pagination justify-content-center mb-0">
                    <li class="page-item" id="rejected-prev-item"><a class="page-link" href="#" id="rejected-prev">Previous</a></li>
                    <li class="page-item" id="rejected-next-item"><a class="page-link" href="#" id="rejected-next">Next</a></li>
                </ul>
            </nav>
        </div>
    </div>
@endsection
