<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'EV Route Planner - Admin')</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/js/app.js'])
    @stack('head')
</head>
<body>
    <nav class="navbar navbar-expand app-navbar px-3 py-2">
        <div class="container-fluid">
            <a class="navbar-brand d-flex align-items-center gap-2" href="{{ route('admin.overview') }}">
                <i class="bi bi-ev-station-fill fs-4"></i> EV Route Planner
            </a>

            <button class="btn btn-accent d-lg-none" type="button"
                    data-bs-toggle="collapse" data-bs-target="#adminSidebar" aria-controls="adminSidebar">
                <i class="bi bi-list"></i>
            </button>

            <div class="dropdown ms-auto">
                <button class="btn navbar-profile-btn dropdown-toggle" type="button" data-bs-toggle="dropdown">
                    <span class="navbar-profile-avatar{{ auth()->user()->avatar_url ? ' d-none' : '' }}" id="navbar-avatar-initial">{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</span>
                    <img class="navbar-profile-avatar navbar-profile-avatar-img{{ auth()->user()->avatar_url ? '' : ' d-none' }}" id="navbar-avatar-img"
                         alt="{{ auth()->user()->name }}" @if(auth()->user()->avatar_url) src="{{ auth()->user()->avatar_url }}" @endif>
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
        <aside class="app-sidebar collapse d-lg-block" id="adminSidebar">
            <nav class="nav flex-column py-3">
                {{--
                    EV Models/Road Graph nav items still get added here as
                    each of those screens actually gets built (own
                    follow-up prompts), same as station-owner.blade.php
                    grew its own sidebar one screen at a time. Adding an
                    entry for a route name that isn't registered yet would
                    make route() throw a fatal error building this array.

                    EV Owners and Station Owners are deliberately separate
                    sidebar entries, not one combined "Users" page - EV
                    owners have no approval workflow at all (read-only
                    headcount tracking), while station owners go through a
                    real Pending/Accepted/Suspended/Rejected review process,
                    so they don't belong on the same screen.

                    Total Users is a THIRD, separate entry on top of those
                    two - a combined read+filter view (role dropdown toggles
                    between EV owners and station owners) that Overview's
                    own "Total Users" stat card links into. It doesn't
                    replace EV Owners/Station Owners, which stay as their
                    own focused screens - some data overlap between all
                    three is expected and accepted.

                    admin.overview's route name/path are intentionally
                    unchanged even though its sidebar label now reads
                    "Dashboard" - internal identifiers, not user-facing
                    text, so renaming them would only churn tests/redirects
                    for no visible benefit.
                --}}
                @foreach ([
                    ['route' => 'admin.overview', 'icon' => 'speedometer2', 'label' => 'Dashboard'],
                    ['route' => 'admin.ev-owners', 'icon' => 'person-badge', 'label' => 'EV Owners'],
                    ['route' => 'admin.station-owners', 'icon' => 'people', 'label' => 'Station Owners'],
                    ['route' => 'admin.stations', 'icon' => 'ev-station', 'label' => 'Stations'],
                    ['route' => 'admin.total-users', 'icon' => 'person-lines-fill', 'label' => 'Total Users'],
                    ['route' => 'admin.trips', 'icon' => 'signpost-split', 'label' => 'Total Trips'],
                    ['route' => 'admin.active-today', 'icon' => 'activity', 'label' => 'Active Today'],
                    ['route' => 'admin.notifications', 'icon' => 'bell', 'label' => 'Notifications'],
                    ['route' => 'profile', 'icon' => 'person', 'label' => 'Profile'],
                ] as $item)
                    <a class="nav-link d-flex align-items-center justify-content-between {{ request()->routeIs($item['route']) ? 'active' : '' }}"
                       href="{{ route($item['route']) }}">
                        <span><i class="bi bi-{{ $item['icon'] }}"></i> {{ $item['label'] }}</span>
                        @if (str_contains($item['route'], 'notifications'))
                            <span class="badge rounded-pill sidebar-notif-badge d-none" id="sidebar-notif-badge"></span>
                        @endif
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
