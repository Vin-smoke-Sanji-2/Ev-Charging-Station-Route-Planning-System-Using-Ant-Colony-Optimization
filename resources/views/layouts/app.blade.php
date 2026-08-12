<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'EV Route Planner')</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/js/app.js'])
    @stack('head')
</head>
<body>
    <nav class="navbar navbar-expand app-navbar px-3 py-2">
        <div class="container-fluid">
            <a class="navbar-brand d-flex align-items-center gap-2" href="{{ route('dashboard') }}">
                <i class="bi bi-ev-station-fill fs-4"></i> EV Route Planner
            </a>

            <button class="btn btn-accent d-lg-none" type="button"
                    data-bs-toggle="collapse" data-bs-target="#appSidebar" aria-controls="appSidebar">
                <i class="bi bi-list"></i>
            </button>

            <div class="dropdown ms-auto">
                <button class="btn navbar-profile-btn dropdown-toggle" type="button" data-bs-toggle="dropdown">
                    <span class="navbar-profile-avatar">{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</span>
                    <span class="d-none d-md-inline navbar-profile-name">{{ auth()->user()->name }}</span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="{{ route('profile') }}">Profile</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="#" data-logout>
                        <i class="bi bi-box-arrow-right"></i> Logout
                    </a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="app-shell">
        <aside class="app-sidebar collapse d-lg-block" id="appSidebar">
            <nav class="nav flex-column py-3">
                @foreach ([
                    ['route' => 'dashboard', 'icon' => 'speedometer2', 'label' => 'Overview'],
                    ['route' => 'trips.plan', 'icon' => 'signpost-split', 'label' => 'Plan Trip'],
                    ['route' => 'trips.live', 'icon' => 'broadcast', 'label' => 'Live Trip'],
                    ['route' => 'stations.index', 'icon' => 'ev-station', 'label' => 'Stations'],
                    ['route' => 'trips.history', 'icon' => 'clock-history', 'label' => 'Trip History'],
                    ['route' => 'vehicles.index', 'icon' => 'car-front', 'label' => 'My EVs'],
                    ['route' => 'favorites.index', 'icon' => 'heart', 'label' => 'Favorites'],
                    ['route' => 'notifications.index', 'icon' => 'bell', 'label' => 'Notifications'],
                    ['route' => 'profile', 'icon' => 'person', 'label' => 'Profile'],
                ] as $item)
                    <a class="nav-link {{ request()->routeIs($item['route']) ? 'active' : '' }}"
                       href="{{ route($item['route']) }}">
                        <i class="bi bi-{{ $item['icon'] }}"></i> {{ $item['label'] }}
                    </a>
                @endforeach
                <a class="nav-link text-danger" href="#" data-logout>
                    <i class="bi bi-box-arrow-right"></i> Logout
                </a>
            </nav>
        </aside>

        <main class="app-main">
            @yield('content')
        </main>
    </div>

    @stack('scripts')
</body>
</html>
