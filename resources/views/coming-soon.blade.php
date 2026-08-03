@extends('layouts.app')

@section('title', $title . ' - EV Route Planner')

@section('content')
    <div class="text-center py-5">
        <i class="bi bi-{{ $icon ?? 'cone-striped' }} coming-soon-icon"></i>
        <h2 class="mt-3">{{ $title }}</h2>
        <p class="text-muted">This screen isn't built yet - check back soon.</p>
        <a href="{{ route('dashboard') }}" class="btn btn-primary mt-2">Back to Dashboard</a>
    </div>
@endsection
