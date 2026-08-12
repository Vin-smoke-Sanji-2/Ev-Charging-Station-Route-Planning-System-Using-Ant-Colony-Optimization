{{-- Role-aware the same way profile/index.blade.php is - the "back" link
     and surrounding layout need to match whoever's actually looking at
     this, not assume every "not built yet" page belongs to an EV owner.
     User::layoutFor() covers every role (including admin) - see its doc
     comment for why this used to be a two-way isStationOwner() check.
     The "back" link reuses User::landingPage() directly (already each
     role's real portal home, e.g. /admin/overview) rather than a second,
     separately-hand-rolled route-name mapping that could drift out of
     sync with it. --}}
@extends(auth()->user()->layoutFor())

@section('title', $title . ' - EV Route Planner')

@section('content')
    <div class="text-center py-5">
        <i class="bi bi-{{ $icon ?? 'cone-striped' }} coming-soon-icon"></i>
        <h2 class="mt-3">{{ $title }}</h2>
        <p class="text-muted">This screen isn't built yet - check back soon.</p>
        <a href="{{ auth()->user()->landingPage() }}" class="btn btn-primary mt-2">
            Back to Overview
        </a>
    </div>
@endsection
