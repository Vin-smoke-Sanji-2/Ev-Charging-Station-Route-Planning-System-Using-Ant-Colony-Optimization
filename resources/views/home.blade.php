<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EV Route Planner - Plan smarter EV road trips in Myanmar</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/js/app.js'])
</head>
<body class="home-page">
    <nav class="navbar navbar-expand app-navbar px-3 py-2">
        <div class="container-fluid">
            <a class="navbar-brand d-flex align-items-center gap-2" href="{{ route('home') }}">
                <i class="bi bi-ev-station-fill fs-4"></i> EV Route Planner
            </a>
            <div class="d-flex gap-2">
                @auth
                    <a href="{{ route('dashboard') }}" class="btn btn-primary">Go to Dashboard</a>
                @else
                    <a href="{{ route('login') }}" class="btn btn-secondary">Log in</a>
                    <a href="{{ route('register') }}" class="btn btn-primary">Sign up</a>
                @endauth
            </div>
        </div>
    </nav>

    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <h1>Plan every EV road trip with confidence.</h1>
                <p class="lead text-muted mt-3">
                    EV Route Planner maps out the fastest route across Myanmar and recommends
                    exactly where to charge along the way, based on live station availability
                    and your vehicle's remaining range.
                </p>
                <div class="d-flex gap-2 mt-4">
                    @auth
                        <a href="{{ route('trips.plan') }}" class="btn btn-primary btn-lg">
                            <i class="bi bi-signpost-split"></i> Plan a Trip
                        </a>
                    @else
                        <a href="{{ route('register') }}" class="btn btn-primary btn-lg">Get started</a>
                        <a href="{{ route('login') }}" class="btn btn-secondary btn-lg">Log in</a>
                    @endauth
                </div>
            </div>
            <div class="col-lg-6">
                <div class="row g-3">
                    <div class="col-6">
                        <div class="card h-100 border-0 shadow-sm floating-card floating-card--1 card-tint-primary">
                            <div class="card-body">
                                <div class="feature-icon-chip feature-icon-chip--primary"><i class="bi bi-signpost-split"></i></div>
                                <h5 class="mt-2">Smart Routing</h5>
                                <p class="text-muted small mb-0">Optimized stops based on distance and battery range.</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="card h-100 border-0 shadow-sm floating-card floating-card--2 card-tint-secondary">
                            <div class="card-body">
                                <div class="feature-icon-chip feature-icon-chip--secondary"><i class="bi bi-ev-station"></i></div>
                                <h5 class="mt-2">Live Stations</h5>
                                <p class="text-muted small mb-0">See real-time slot availability before you arrive.</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="card h-100 border-0 shadow-sm floating-card floating-card--3 card-tint-accent">
                            <div class="card-body">
                                <div class="feature-icon-chip feature-icon-chip--accent"><i class="bi bi-car-front"></i></div>
                                <h5 class="mt-2">Your EVs</h5>
                                <p class="text-muted small mb-0">Save your vehicles for faster trip planning.</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="card h-100 border-0 shadow-sm floating-card floating-card--4 card-tint-favorite">
                            <div class="card-body">
                                <div class="feature-icon-chip feature-icon-chip--favorite"><i class="bi bi-star"></i></div>
                                <h5 class="mt-2">Reviews & Favorites</h5>
                                <p class="text-muted small mb-0">Save the stations you trust most.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="home-charge-panel">
            <svg width="100%" viewBox="0 0 680 170" role="img">
                <title>EV charging animation, sporty coupe reference style</title>
                <desc>A sporty electric coupe with a defined character line and spoiler drives in from the left and parks at a charging station.</desc>
                <style>
                    .ev-car{animation:drive-in 2.4s cubic-bezier(.22,1,.36,1) forwards}
                    @keyframes drive-in{0%{transform:translateX(-640px)}80%{transform:translateX(8px)}100%{transform:translateX(0)}}
                    .ev-cable{opacity:0;animation:fade-in .35s ease-out 2.3s forwards}
                    @keyframes fade-in{to{opacity:1}}
                    .ev-ping{opacity:0;transform-box:fill-box;transform-origin:center;animation:ping 1.6s ease-out 2.5s infinite}
                    .ev-ping.d2{animation-delay:3.3s}
                    @keyframes ping{0%{transform:scale(.4);opacity:.7}100%{transform:scale(2.2);opacity:0}}

                    @media (prefers-reduced-motion: reduce) {
                        .ev-car { animation: none; transform: translateX(0); }
                        .ev-cable { animation: none; opacity: 1; }
                        .ev-ping { animation: none; opacity: 0; }
                    }
                </style>

                <line x1="30" y1="140" x2="650" y2="140" stroke="#cbd5e1" stroke-width="3" stroke-linecap="round" stroke-dasharray="14 10"/>

                <rect x="568" y="90" width="6" height="50" rx="2" fill="#2E3A59"/>
                <rect x="550" y="58" width="42" height="34" rx="8" fill="#2E3A59"/>
                <rect x="558" y="66" width="26" height="10" rx="2" fill="#F8F4EC"/>
                <path d="M577 76 L570 87 L575 87 L571 96 L582 82 L576 82 Z" fill="#C9A464"/>

                <path class="ev-cable" d="M571 120 C 569 114, 569 111, 569 108" fill="none" stroke="#475569" stroke-width="2" stroke-linecap="round"/>
                <circle class="ev-ping" cx="568" cy="108" r="5" fill="none" stroke="#C9A464" stroke-width="2"/>
                <circle class="ev-ping d2" cx="568" cy="108" r="5" fill="none" stroke="#C9A464" stroke-width="2"/>
                <circle cx="568" cy="108" r="4" fill="#C9A464"/>

                <g class="ev-car">
                    <ellipse cx="468" cy="130" rx="17" ry="4" fill="#000000" opacity="0.12"/>
                    <ellipse cx="538" cy="130" rx="17" ry="4" fill="#000000" opacity="0.12"/>

                    <path d="M432 124
                             C427 124 425 119 427 113
                             C432 106 440 100 448 97
                             C450 105 455 110 463 111
                             L463 100
                             C466 95 470 91 476 88
                             C486 90 494 89 502 87
                             C508 90 512 92 516 96
                             C524 94 532 96 538 100
                             C548 99 558 100 566 104
                             C572 107 576 112 577 118
                             C578 122 576 126 571 126
                             Z"
                          fill="#7A1E3C"/>

                    <path d="M463 100
                             C466 95 470 91 476 88
                             C486 90 494 89 502 87
                             C508 89.5 512 91.5 516 95.5
                             L516 95.5
                             C505 97 486 97.5 470 97
                             C467.5 96.9 465.2 98.3 463 100
                             Z"
                          fill="#2E3A59"/>

                    <path d="M478 90 C489 88 498 87.5 507 89 C511 91 513.5 93 515 96
                             L468 96.5
                             C471 93.5 474.5 91.5 478 90 Z"
                          fill="#DCE3EC" opacity="0.9"/>

                    <path d="M448 97 C450 105 455 110 463 111 L461 113 C452 112 446 106 444 98 Z"
                          fill="#611830"/>

                    <rect x="426" y="118" width="145" height="2.6" fill="#C9A464"/>

                    <path d="M428 111 C433 108 439 106 445 105 L445 109 C439 110 434 112 429 115 Z"
                          fill="#611830" opacity="0.85"/>

                    <path d="M509 89 L513 83.5 L516 90 Z" fill="#2E3A59"/>

                    <circle cx="571" cy="120" r="2.6" fill="#C9A464"/>

                    <g>
                        <circle cx="468" cy="130" r="13" fill="#1f2937"/>
                        <circle cx="468" cy="130" r="7.5" fill="#9aa4b2"/>
                        <circle cx="468" cy="130" r="2" fill="#1f2937"/>
                        <g stroke="#1f2937" stroke-width="1.3">
                            <line x1="468" y1="123" x2="468" y2="137"/>
                            <line x1="461.4" y1="126" x2="474.6" y2="134"/>
                            <line x1="474.6" y1="126" x2="461.4" y2="134"/>
                            <line x1="459.5" y1="130" x2="468" y2="123.5"/>
                            <line x1="476.5" y1="130" x2="468" y2="136.5"/>
                        </g>
                    </g>
                    <g>
                        <circle cx="538" cy="130" r="13" fill="#1f2937"/>
                        <circle cx="538" cy="130" r="7.5" fill="#9aa4b2"/>
                        <circle cx="538" cy="130" r="2" fill="#1f2937"/>
                        <g stroke="#1f2937" stroke-width="1.3">
                            <line x1="538" y1="123" x2="538" y2="137"/>
                            <line x1="531.4" y1="126" x2="544.6" y2="134"/>
                            <line x1="544.6" y1="126" x2="531.4" y2="134"/>
                            <line x1="529.5" y1="130" x2="538" y2="123.5"/>
                            <line x1="546.5" y1="130" x2="538" y2="136.5"/>
                        </g>
                    </g>
                </g>
            </svg>
        </div>
    </div>

    <footer class="home-footer text-center small py-4">
        &copy; {{ date('Y') }} EV Route Planner &mdash; plan smarter EV road trips across Myanmar.
    </footer>
</body>
</html>
