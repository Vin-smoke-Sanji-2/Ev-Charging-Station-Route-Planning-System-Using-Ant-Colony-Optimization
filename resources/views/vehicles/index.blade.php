@extends('layouts.app')

@section('title', 'My EVs - EV Route Planner')

@push('head')
    @vite(['resources/js/pages/vehicles-index.js'])
@endpush

@section('content')
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h2 class="mb-1">My EVs</h2>
            <p class="text-muted mb-0">Manage the vehicles saved to your account.</p>
        </div>
        <button type="button" class="btn btn-primary" id="add-vehicle-btn"
                data-bs-toggle="modal" data-bs-target="#vehicleModal">
            <i class="bi bi-plus-lg"></i> Add Vehicle
        </button>
    </div>

    <div id="vehicles-loading" class="text-muted">Loading your vehicles...</div>

    <div id="vehicles-empty" class="text-center py-5 d-none">
        <i class="bi bi-car-front coming-soon-icon"></i>
        <h5 class="mt-3">No vehicles yet</h5>
        <p class="text-muted">Add your first one to start planning trips.</p>
    </div>

    <div id="vehicles-grid" class="row g-3"></div>

    <!-- Add / Edit Vehicle modal (single reusable form) -->
    <div class="modal fade" id="vehicleModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form id="vehicle-form" novalidate>
                    <div class="modal-header">
                        <h5 class="modal-title" id="vehicle-modal-title">Add Vehicle</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" id="vehicle_id" name="vehicle_id" value="">

                        <div id="vehicle-form-error" class="alert alert-danger d-none" role="alert"></div>

                        <div class="mb-3">
                            <label for="ev_model_id" class="form-label">EV Model</label>
                            <select class="form-select" id="ev_model_id" name="ev_model_id" required>
                                <option value="">Loading EV models...</option>
                            </select>
                            <div class="invalid-feedback" data-error-for="ev_model_id"></div>
                        </div>

                        <div class="mb-3">
                            <label for="plate_no" class="form-label">Plate number <span class="text-muted">(optional)</span></label>
                            <input type="text" class="form-control" id="plate_no" name="plate_no">
                            <div class="invalid-feedback" data-error-for="plate_no"></div>
                        </div>

                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="is_default" name="is_default">
                            <label class="form-check-label" for="is_default">
                                Set as default vehicle
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="vehicle-form-submit">Save Vehicle</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
