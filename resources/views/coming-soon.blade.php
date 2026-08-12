{{-- Role-aware the same way profile/index.blade.php is - the "back" link
     and surrounding layout need to match whoever's actually looking at
     this, not assume every "not built yet" page belongs to an EV owner. --}}
@extends(auth()->user()->isStationOwner() ? 'layouts.station-owner' : 'layouts.app')

@section('title', $title . ' - EV Route Planner')

@section('content')
    <div class="text-center py-5">
        <i class="bi bi-{{ $icon ?? 'cone-striped' }} coming-soon-icon"></i>
        <h2 class="mt-3">{{ $title }}</h2>
        <p class="text-muted">This screen isn't built yet - check back soon.</p>
        <a href="{{ route(auth()->user()->isStationOwner() ? 'station-owner.overview' : 'dashboard') }}" class="btn btn-primary mt-2">
            Back to Overview
        </a>
    </div>
@endsection
