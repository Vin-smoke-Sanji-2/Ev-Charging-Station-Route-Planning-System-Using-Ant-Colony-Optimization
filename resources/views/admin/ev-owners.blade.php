@extends('layouts.admin')

@section('title', 'EV Owners - EV Route Planner')

@push('head')
    @vite(['resources/js/pages/admin-ev-owners.js'])
@endpush

@section('content')
    <h2 class="mb-1">EV Owners</h2>
    <p class="text-muted">Read-only headcount of every registered EV owner - no approval workflow applies to this role.</p>

    <div class="mb-3">
        <label for="ev-owners-search" class="form-label">Search</label>
        <input type="text" class="form-control" id="ev-owners-search" placeholder="Search by name or email...">
    </div>

    <div id="ev-owners-loading" class="text-muted">Loading EV owners...</div>

    <div id="ev-owners-empty" class="text-center py-5 d-none">
        <i class="bi bi-person-badge coming-soon-icon"></i>
        <h5 class="mt-3">No EV owners yet</h5>
    </div>

    <div id="ev-owners-error" class="alert alert-danger d-none"></div>

    <div id="ev-owners-table-wrap" class="d-none">
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
                    <tbody id="ev-owners-tbody"></tbody>
                </table>
            </div>
        </div>

        <nav aria-label="EV owners pagination" id="ev-owners-pagination" class="mt-3 d-none">
            <ul class="pagination justify-content-center mb-0">
                <li class="page-item" id="ev-owners-prev-item">
                    <a class="page-link" href="#" id="ev-owners-prev">Previous</a>
                </li>
                <li class="page-item" id="ev-owners-next-item">
                    <a class="page-link" href="#" id="ev-owners-next">Next</a>
                </li>
            </ul>
        </nav>
    </div>
@endsection
