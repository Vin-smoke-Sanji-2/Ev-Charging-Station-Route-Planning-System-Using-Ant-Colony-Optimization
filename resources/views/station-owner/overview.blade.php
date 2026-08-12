@extends('layouts.station-owner')

@section('title', 'Overview - EV Route Planner')

@push('head')
    @vite(['resources/js/pages/station-owner-overview.js'])
@endpush

@section('content')
    <div id="station-owner-overview-app">
        <h2 class="mb-1">Welcome, {{ auth()->user()->name }}</h2>
        <p class="text-muted">Here's the current status of your station owner account.</p>

        <div id="overview-loading" class="text-muted">Loading...</div>

        {{-- Gate 1: user.status != 'active' - no station content shown at all,
             regardless of what the station's own verification_status is. --}}
        <div id="overview-account-blocked" class="alert alert-warning d-none" role="alert">
            <i class="bi bi-hourglass-split"></i>
            <span id="account-status-message"></span>
        </div>

        {{-- Gate 2: user is active, but their station isn't verified yet -
             a genuinely different state from gate 1, checked independently. --}}
        <div id="overview-station-pending" class="alert alert-info d-none" role="alert">
            <i class="bi bi-clock-history"></i>
            Your account is approved, but <strong id="station-pending-name"></strong> is still awaiting verification.
            It won't appear in trip planning or station search until an admin verifies it.
        </div>

        {{-- Both gates passed. --}}
        <div id="overview-active" class="d-none">
            <div class="alert alert-success" role="alert">
                <i class="bi bi-check-circle-fill"></i>
                Your account and station are both approved - you're fully live.
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h5 class="card-title" id="overview-station-name"></h5>
                    <p class="text-muted mb-1" id="overview-station-address"></p>
                    <p class="text-muted small mb-0" id="overview-station-township"></p>
                </div>
            </div>

            <p class="text-muted small mt-3">
                Your station is now visible everywhere in the app - the Dashboard map, the Stations directory,
                and trip planning - the same as any other verified station.
            </p>

            <a href="{{ route('station-owner.stations') }}" class="btn btn-primary mt-2">
                <i class="bi bi-ev-station"></i> Manage My Stations
            </a>
        </div>
    </div>
@endsection
