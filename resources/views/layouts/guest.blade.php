<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'EV Route Planner')</title>
    @vite(['resources/js/app.js'])
    @stack('head')
</head>
<body>
    <div class="auth-shell">
        <div class="auth-card @yield('card-size')">
            @yield('content')
        </div>
    </div>
    @stack('scripts')
</body>
</html>
