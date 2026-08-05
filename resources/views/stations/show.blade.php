@extends('layouts.app')

@section('title', 'Station Details - EV Route Planner')

@push('head')
    @vite(['resources/js/pages/stations-show.js'])
@endpush

@section('content')
    <div id="station-app" data-station-id="{{ $stationId }}">
        <div id="station-loading" class="text-muted">Loading station...</div>
        <div id="station-error" class="alert alert-danger d-none" role="alert"></div>

        <div id="station-content" class="d-none">
            <div class="d-flex align-items-start justify-content-between flex-wrap gap-2 mb-3">
                <div>
                    <h2 class="mb-1" id="station-name"></h2>
                    <p class="text-muted mb-0" id="station-township"></p>
                </div>
                <button type="button" class="btn btn-outline-danger" id="favorite-btn">
                    <i class="bi bi-heart" id="favorite-icon"></i> <span id="favorite-label">Favorite</span>
                </button>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <h5 class="card-title">Details</h5>
                            <dl class="row mb-0">
                                <dt class="col-sm-4">Address</dt>
                                <dd class="col-sm-8" id="station-address">&mdash;</dd>

                                <dt class="col-sm-4">Charging speed</dt>
                                <dd class="col-sm-8" id="station-speed">&mdash;</dd>

                                <dt class="col-sm-4">Operating hours</dt>
                                <dd class="col-sm-8" id="station-hours">&mdash;</dd>

                                <dt class="col-sm-4">Average rating</dt>
                                <dd class="col-sm-8" id="station-rating">&mdash;</dd>

                                <dt class="col-sm-4">Queue length</dt>
                                <dd class="col-sm-8" id="station-queue">&mdash;</dd>
                            </dl>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <h5 class="card-title">Slots</h5>
                            <ul class="list-group list-group-flush" id="slots-list"></ul>
                            <p class="text-muted mb-0 d-none" id="slots-empty">No slots configured yet.</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h5 class="card-title">Reviews</h5>

                    <div id="reviews-loading" class="text-muted small">Loading reviews...</div>
                    <p class="text-muted d-none" id="reviews-empty">No reviews yet - be the first to leave one.</p>

                    <ul class="list-group list-group-flush mb-3" id="reviews-list"></ul>

                    <nav aria-label="Reviews pagination" id="reviews-pagination" class="mb-4 d-none">
                        <ul class="pagination justify-content-center">
                            <li class="page-item" id="reviews-prev-item">
                                <a class="page-link" href="#" id="reviews-prev">Previous</a>
                            </li>
                            <li class="page-item" id="reviews-next-item">
                                <a class="page-link" href="#" id="reviews-next">Next</a>
                            </li>
                        </ul>
                    </nav>

                    <hr>

                    <h6>Leave a review</h6>
                    <div id="review-form-error" class="alert alert-danger d-none" role="alert"></div>
                    <form id="review-form" novalidate>
                        <div class="mb-3">
                            <label for="rating" class="form-label">Rating</label>
                            <select class="form-select" id="rating" name="rating" style="max-width: 200px;" required>
                                <option value="">Select a rating</option>
                                <option value="5">5 - Excellent</option>
                                <option value="4">4 - Good</option>
                                <option value="3">3 - Average</option>
                                <option value="2">2 - Poor</option>
                                <option value="1">1 - Very poor</option>
                            </select>
                            <div class="invalid-feedback" data-error-for="rating"></div>
                        </div>
                        <div class="mb-3">
                            <label for="comment" class="form-label">Comment <span class="text-muted">(optional)</span></label>
                            <textarea class="form-control" id="comment" name="comment" rows="3"></textarea>
                            <div class="invalid-feedback" data-error-for="comment"></div>
                        </div>
                        <button type="submit" class="btn btn-primary" id="review-submit">Submit Review</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
