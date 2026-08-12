@extends('layouts.app')

@section('title', 'Plan Trip - EV Route Planner')

@push('head')
    @vite(['resources/js/pages/plan-trip.js'])
@endpush

@section('content')
    <h2>Plan a Trip</h2>
    <p class="text-muted">Choose your vehicle, origin, and destination to get a recommended route with charging stops.</p>

    <div id="form-error" class="alert alert-danger d-none" role="alert"></div>
    <div id="no-vehicles-alert" class="alert alert-warning d-none" role="alert">
        You don't have any vehicles saved yet. <a href="{{ route('vehicles.index') }}">Add one</a> before planning a trip.
    </div>

    <div class="card border-0 shadow-sm" style="max-width: 640px;">
        <div class="card-body p-4">
            <form id="plan-trip-form" novalidate>
                <div class="mb-3">
                    <label for="vehicle_id" class="form-label">Vehicle</label>
                    <select class="form-select" id="vehicle_id" name="vehicle_id" required>
                        <option value="">Loading vehicles...</option>
                    </select>
                    <div class="invalid-feedback" data-error-for="vehicle_id"></div>
                </div>
                <div class="mb-3 position-relative" id="origin-search-wrap">
                    <label for="origin_search" class="form-label">Origin</label>
                    <div class="d-flex gap-2">
                        <input type="text" class="form-control" id="origin_search" autocomplete="off"
                               placeholder="Start typing a city name...">
                        <button type="button" class="btn btn-secondary text-nowrap" id="use-my-location-btn">
                            <i class="bi bi-geo-alt"></i> Use My Location
                        </button>
                    </div>
                    <input type="hidden" id="origin_node_id" name="origin_node_id">
                    <div class="list-group position-absolute w-100 shadow-sm d-none" id="origin-suggestions" style="z-index: 10; top: 100%;"></div>
                    <div class="invalid-feedback" data-error-for="origin_node_id"></div>
                    <div class="small mt-1 d-none" id="origin-location-status"></div>
                    <div class="small text-danger mt-1 d-none" id="origin-hint">Please select a city from the suggestions.</div>
                </div>
                <div class="mb-3 position-relative" id="destination-search-wrap">
                    <label for="destination_search" class="form-label">Destination</label>
                    <input type="text" class="form-control" id="destination_search" autocomplete="off"
                           placeholder="Start typing a city name...">
                    <input type="hidden" id="destination_node_id" name="destination_node_id">
                    <div class="list-group position-absolute w-100 shadow-sm d-none" id="destination-suggestions" style="z-index: 10; top: 100%;"></div>
                    <div class="invalid-feedback" data-error-for="destination_node_id"></div>
                    <div class="small text-danger mt-1 d-none" id="destination-hint">Please select a city from the suggestions.</div>
                </div>
                <div class="mb-3">
                    <label for="battery_percent" class="form-label">Current battery %</label>
                    <input type="number" class="form-control" id="battery_percent" name="battery_percent"
                           min="0" max="100" step="1" placeholder="e.g. 80" required>
                    <div class="invalid-feedback" data-error-for="battery_percent"></div>
                </div>
                <button type="submit" class="btn btn-primary" id="plan-trip-submit">Plan Route</button>
            </form>
        </div>
    </div>
@endsection
