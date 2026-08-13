@extends('layouts.app')

@section('title', 'Station Details - EV Route Planner')

@push('head')
    @vite(['resources/js/pages/stations-show.js'])
@endpush

@section('content')
    <div id="station-app" data-station-id="{{ $stationId }}">
        <div id="station-loading" class="text-muted">Loading station...</div>
        <div id="station-error" class="alert alert-danger d-none" role="alert"></div>

        <div id="station-content" class="detail-layout d-none">
            <div class="detail-info-column">
                <div class="floating-panel card-tint-primary">
                    <div class="d-flex align-items-start justify-content-between flex-wrap gap-2">
                        <div>
                            <h2 class="mb-1 h4" id="station-name"></h2>
                            <p class="text-muted mb-0 small" id="station-township"></p>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-secondary btn-sm" id="navigate-btn">
                                <i class="bi bi-signpost-split"></i> Navigate
                            </button>
                            <button type="button" class="btn btn-icon-circle btn-icon-circle--muted" id="favorite-btn">
                                <i class="bi bi-heart" id="favorite-icon"></i>
                                <span id="favorite-label" class="visually-hidden">Favorite</span>
                            </button>
                        </div>
                    </div>
                    <div id="navigate-status" class="small mt-2 d-none"></div>
                </div>

                <div class="floating-panel card-tint-secondary">
                    <h6 class="text-uppercase small fw-semibold text-muted mb-3">Details</h6>
                    <dl class="row mb-0 small">
                        <dt class="col-5">Address</dt>
                        <dd class="col-7" id="station-address">&mdash;</dd>

                        <dt class="col-5">Charging speed</dt>
                        <dd class="col-7" id="station-speed">&mdash;</dd>

                        <dt class="col-5">Operating hours</dt>
                        <dd class="col-7" id="station-hours">&mdash;</dd>

                        <dt class="col-5">Average rating</dt>
                        <dd class="col-7" id="station-rating">&mdash;</dd>

                        <dt class="col-5">Queue length</dt>
                        <dd class="col-7" id="station-queue">&mdash;</dd>
                    </dl>
                </div>

                <div class="floating-panel card-tint-terracotta">
                    <h6 class="text-uppercase small fw-semibold text-muted mb-3">Slots</h6>
                    <ul class="list-group list-group-flush" id="slots-list"></ul>
                    <p class="text-muted mb-0 small d-none" id="slots-empty">No slots configured yet.</p>

                    <div class="mt-3 pt-3 border-top" id="charging-control">
                        <div class="text-muted small" data-role="loading">Checking your charging status...</div>
                        <p class="mb-2 small d-none" data-role="status"></p>
                        <button type="button" class="btn btn-primary btn-sm d-none" data-role="start-btn">
                            <i class="bi bi-lightning-charge-fill"></i> Start Charging Here
                        </button>
                        <div class="alert alert-danger py-2 small mt-2 d-none" data-role="error" role="alert"></div>
                    </div>
                </div>

                <div class="floating-panel card-tint-plum">
                    <h6 class="text-uppercase small fw-semibold text-muted mb-3">Reviews</h6>

                    <div id="reviews-loading" class="text-muted small">Loading reviews...</div>
                    <p class="text-muted small d-none" id="reviews-empty">No reviews yet - be the first to leave one.</p>

                    <ul class="list-group list-group-flush mb-3" id="reviews-list"></ul>

                    <nav aria-label="Reviews pagination" id="reviews-pagination" class="mb-3 d-none">
                        <ul class="pagination pagination-sm justify-content-center mb-0">
                            <li class="page-item" id="reviews-prev-item">
                                <a class="page-link" href="#" id="reviews-prev">Previous</a>
                            </li>
                            <li class="page-item" id="reviews-next-item">
                                <a class="page-link" href="#" id="reviews-next">Next</a>
                            </li>
                        </ul>
                    </nav>

                    <hr>

                    <h6 class="small fw-semibold">Leave a review</h6>
                    <div id="review-form-error" class="alert alert-danger d-none py-2 small" role="alert"></div>
                    <form id="review-form" novalidate>
                        <div class="mb-2">
                            <label for="rating" class="form-label small mb-1">Rating</label>
                            <select class="form-select form-select-sm" id="rating" name="rating" required>
                                <option value="">Select a rating</option>
                                <option value="5">5 - Excellent</option>
                                <option value="4">4 - Good</option>
                                <option value="3">3 - Average</option>
                                <option value="2">2 - Poor</option>
                                <option value="1">1 - Very poor</option>
                            </select>
                            <div class="invalid-feedback" data-error-for="rating"></div>
                        </div>
                        <div class="mb-2">
                            <label for="comment" class="form-label small mb-1">Comment <span class="text-muted">(optional)</span></label>
                            <textarea class="form-control form-control-sm" id="comment" name="comment" rows="2"></textarea>
                            <div class="invalid-feedback" data-error-for="comment"></div>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm" id="review-submit">Submit Review</button>
                    </form>
                </div>
            </div>

            <div class="detail-map-column">
                <div class="floating-panel detail-map-panel">
                    <div id="station-map"></div>
                </div>
            </div>
        </div>
    </div>
@endsection
