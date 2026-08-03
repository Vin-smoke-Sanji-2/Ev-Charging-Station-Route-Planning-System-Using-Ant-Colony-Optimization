<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EV Route Planner - Plan smarter EV road trips in Myanmar</title>
    @vite(['resources/js/app.js'])
</head>
<body>
    <nav class="navbar navbar-expand app-navbar px-3 py-2">
        <div class="container-fluid">
            <a class="navbar-brand d-flex align-items-center gap-2" href="{{ route('home') }}">
                <i class="bi bi-ev-station-fill fs-4"></i> EV Route Planner
            </a>
            <div class="d-flex gap-2">
                @auth
                    <a href="{{ route('dashboard') }}" class="btn btn-primary">Go to Dashboard</a>
                @else
                    <a href="{{ route('login') }}" class="btn btn-outline-primary">Log in</a>
                    <a href="{{ route('register') }}" class="btn btn-primary">Sign up</a>
                @endauth
            </div>
        </div>
    </nav>

    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <h1 class="display-5 fw-bold text-brand">Plan every EV road trip with confidence.</h1>
                <p class="lead text-muted mt-3">
                    EV Route Planner maps out the fastest route across Myanmar and recommends
                    exactly where to charge along the way, based on live station availability
                    and your vehicle's remaining range.
                </p>
                <div class="d-flex gap-2 mt-4">
                    @auth
                        <a href="{{ route('trips.plan') }}" class="btn btn-primary btn-lg">Plan a Trip</a>
                    @else
                        <a href="{{ route('register') }}" class="btn btn-primary btn-lg">Get started</a>
                        <a href="{{ route('login') }}" class="btn btn-outline-primary btn-lg">Log in</a>
                    @endauth
                </div>
            </div>
            <div class="col-lg-6">
                <div class="row g-3">
                    <div class="col-6">
                        <div class="card h-100 border-0 shadow-sm">
                            <div class="card-body">
                                <i class="bi bi-signpost-split fs-2 text-brand"></i>
                                <h5 class="mt-2">Smart Routing</h5>
                                <p class="text-muted small mb-0">Optimized stops based on distance and battery range.</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="card h-100 border-0 shadow-sm">
                            <div class="card-body">
                                <i class="bi bi-ev-station fs-2 text-brand"></i>
                                <h5 class="mt-2">Live Stations</h5>
                                <p class="text-muted small mb-0">See real-time slot availability before you arrive.</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="card h-100 border-0 shadow-sm">
                            <div class="card-body">
                                <i class="bi bi-car-front fs-2 text-brand"></i>
                                <h5 class="mt-2">Your EVs</h5>
                                <p class="text-muted small mb-0">Save your vehicles for faster trip planning.</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="card h-100 border-0 shadow-sm">
                            <div class="card-body">
                                <i class="bi bi-star fs-2 text-brand"></i>
                                <h5 class="mt-2">Reviews & Favorites</h5>
                                <p class="text-muted small mb-0">Save the stations you trust most.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
