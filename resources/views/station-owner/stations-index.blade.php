@extends('layouts.station-owner')

@section('title', 'My Stations - EV Route Planner')

@push('head')
    @vite(['resources/js/pages/station-owner-stations-index.js'])
@endpush

@section('content')
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h2 class="mb-1">My Stations</h2>
            <p class="text-muted mb-0">Manage the charging stations linked to your account.</p>
        </div>
        <button type="button" class="btn btn-primary d-flex align-items-center gap-2" id="add-station-btn"
                data-bs-toggle="modal" data-bs-target="#addStationModal">
            <span class="btn-icon-badge"><i class="bi bi-plus-lg"></i></span> Add Another Station
        </button>
    </div>

    <div id="stations-loading" class="text-muted">Loading your stations...</div>

    <div id="stations-empty" class="text-center py-5 d-none">
        <i class="bi bi-ev-station coming-soon-icon"></i>
        <h5 class="mt-3">No stations yet</h5>
        <p class="text-muted">Add your first charging station to get started.</p>
    </div>

    <div id="stations-grid" class="row g-3"></div>

    <!-- Add Another Station modal - reuses the shared station-form-fields
         partial and station-form.js module registration itself extracted
         to, so both flows create a station through the identical
         map-picker/validation logic. -->
    <div class="modal fade" id="addStationModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form id="add-station-form" novalidate>
                    <div class="modal-header">
                        <h5 class="modal-title">Add Another Station</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div id="add-station-form-error" class="alert alert-danger d-none" role="alert"></div>

                        @include('partials.station-form-fields')
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-neutral" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="add-station-form-submit">Create Station</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
