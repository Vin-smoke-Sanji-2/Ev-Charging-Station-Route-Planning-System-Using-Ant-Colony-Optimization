@extends('layouts.app')

@section('title', 'Stations - EV Route Planner')

@push('head')
    @vite(['resources/js/pages/stations-index.js'])
@endpush

@section('content')
    <h2 class="mb-1">Charging Stations</h2>
    <p class="text-muted">Search verified charging stations across Myanmar.</p>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form id="station-filters-form" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label for="filter-name" class="form-label">Name</label>
                    <input type="text" class="form-control" id="filter-name" name="name" placeholder="Search by name">
                </div>
                <div class="col-md-3">
                    <label for="filter-township" class="form-label">Township <span class="text-muted small">(exact match)</span></label>
                    <input type="text" class="form-control" id="filter-township" name="township" placeholder="e.g. Bago">
                </div>
                <div class="col-md-3">
                    <label for="filter-connector" class="form-label">Connector type <span class="text-muted small">(exact match)</span></label>
                    <input type="text" class="form-control" id="filter-connector" name="connector" placeholder="e.g. CCS2">
                </div>
                <div class="col-md-3 d-flex gap-3">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="filter-fast-only" name="fast_only">
                        <label class="form-check-label" for="filter-fast-only">Fast chargers only</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="filter-available-now" name="available_now">
                        <label class="form-check-label" for="filter-available-now">Available now</label>
                    </div>
                </div>
                <div class="col-12 d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-search"></i> Search
                    </button>
                    <button type="button" class="btn btn-outline-secondary" id="clear-filters-btn">Clear filters</button>
                </div>
            </form>
        </div>
    </div>

    <div id="stations-loading" class="text-muted">Loading stations...</div>

    <div id="stations-empty" class="text-center py-5 d-none">
        <i class="bi bi-ev-station coming-soon-icon"></i>
        <h5 class="mt-3">No stations found</h5>
        <p class="text-muted">Try adjusting or clearing your filters.</p>
    </div>

    <div id="stations-grid" class="row g-3"></div>
@endsection
