@extends('layouts.app')

@section('title', 'Dashboard - EV Route Planner')

@section('content')
    <h2>Welcome back, {{ auth()->user()->name }}</h2>
    <p class="text-muted">Ready to plan your next EV road trip?</p>

    <div class="row g-3 mt-2">
        <div class="col-md-4">
            <a href="{{ route('trips.plan') }}" class="text-decoration-none">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <i class="bi bi-signpost-split fs-2 text-brand"></i>
                        <h5 class="mt-2">Plan a Trip</h5>
                        <p class="text-muted small mb-0">Get a route with recommended charging stops.</p>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a href="{{ route('vehicles.index') }}" class="text-decoration-none">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <i class="bi bi-car-front fs-2 text-brand"></i>
                        <h5 class="mt-2">My EVs</h5>
                        <p class="text-muted small mb-0">Manage the vehicles on your account.</p>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a href="{{ route('stations.index') }}" class="text-decoration-none">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <i class="bi bi-ev-station fs-2 text-brand"></i>
                        <h5 class="mt-2">Browse Stations</h5>
                        <p class="text-muted small mb-0">Search verified charging stations.</p>
                    </div>
                </div>
            </a>
        </div>
    </div>
@endsection
