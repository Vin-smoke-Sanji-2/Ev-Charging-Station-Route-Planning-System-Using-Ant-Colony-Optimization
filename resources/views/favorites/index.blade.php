@extends('layouts.app')

@section('title', 'Favorites - EV Route Planner')

@push('head')
    @vite(['resources/js/pages/favorites-index.js'])
@endpush

@section('content')
    <h2 class="mb-1">Favorites</h2>
    <p class="text-muted">Stations you've saved for quick access.</p>

    <div id="favorites-loading" class="text-muted">Loading your favorites...</div>

    <div id="favorites-empty" class="text-center py-5 d-none">
        <i class="bi bi-heart coming-soon-icon"></i>
        <h5 class="mt-3">No favorites yet</h5>
        <p class="text-muted">
            <a href="{{ route('stations.index') }}">Browse stations</a> and save the ones you like.
        </p>
    </div>

    <div id="favorites-grid" class="row g-3"></div>
@endsection
