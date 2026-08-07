@extends('layouts.app')

@section('title', 'Notifications - EV Route Planner')

@push('head')
    @vite(['resources/js/pages/notifications-index.js'])
@endpush

@section('content')
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h2 class="mb-1">Notifications</h2>
            <p class="text-muted mb-0">Updates about your trips and account.</p>
        </div>
        <button type="button" class="btn btn-primary" id="mark-all-read-btn" disabled>
            <i class="bi bi-check2-all"></i> Mark all as read
        </button>
    </div>

    <div id="notifications-loading" class="text-muted">Loading notifications...</div>

    <div id="notifications-empty" class="text-center py-5 d-none">
        <i class="bi bi-bell coming-soon-icon"></i>
        <h5 class="mt-3">No notifications yet</h5>
        <p class="text-muted">You'll see updates about your trips and account here.</p>
    </div>

    <div id="notifications-wrap" class="d-none">
        <ul class="list-group mb-3" id="notifications-list"></ul>

        <nav aria-label="Notifications pagination" id="notifications-pagination" class="d-none">
            <ul class="pagination justify-content-center">
                <li class="page-item" id="notifications-prev-item">
                    <a class="page-link" href="#" id="notifications-prev">Previous</a>
                </li>
                <li class="page-item" id="notifications-next-item">
                    <a class="page-link" href="#" id="notifications-next">Next</a>
                </li>
            </ul>
        </nav>
    </div>
@endsection
