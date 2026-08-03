# EV Charging Station Route Planning System

Final year project. Web-based system that plans EV road trips across
Myanmar, recommending charging stops using an Ant Colony Optimization
(ACO) algorithm.

## Stack

- Backend: PHP 8.2, Laravel 11, Sanctum (cookie-based SPA auth)
- Database: MySQL
- Frontend: Blade templates, Bootstrap, vanilla JavaScript (fetch),
  Leaflet.js + OpenStreetMap tiles — no JS framework
- Core algorithm: Ant Colony Optimization over a road-node/edge graph

**Framework: Laravel 11, PHP 8.2 (fresh install).** Use Laravel 11
conventions specifically:
- Model casts via `protected function casts(): array { return [...]; }`,
  not the older `protected $casts = [...]` property syntax.
- Middleware aliases registered in `bootstrap/app.php` via
  `->withMiddleware()`, not in `app/Http/Kernel.php` (that file doesn't
  exist in a fresh Laravel 11 install).
- Sanctum installed via `php artisan install:api`, which also wires up
  `routes/api.php` and the `api` middleware group automatically.

## Roles

- **EV owner** — plans trips, manages vehicles, tracks live trips, favorites, reviews
- **Station owner** — manages their station(s), slots, charging sessions/queue
- **Admin** — verifies stations, manages users, EV models, and the road graph

## Database (14 tables)

`users` (+ `phone`, `role`, `status` columns added via migration),
`ev_models`, `user_vehicles`, `road_nodes`, `road_edges`,
`charging_stations`, `charging_slots`, `charging_sessions`,
`trip_requests`, `trip_routes`, `route_charging_stops`,
`favorite_stations`, `reviews`, `user_notifications`.

Key design decisions:
- `charging_sessions.status` (`waiting → charging → completed/cancelled`)
  is the single source of truth for both the live queue and payment
  history. Queue length for a station = count of `waiting` sessions,
  calculated dynamically, not stored.
- `trip_routes.previous_route_id` self-references the table so a
  mid-trip recalculation (e.g. a station became full) links back to the
  route it replaced, with `recalculation_reason` recording why.
- `trip_routes.estimated_cost` holds the planned cost; actual cost is
  summed from linked `charging_sessions.payment_amount`.

## Backend status

Migrated (17 tables + `personal_access_tokens`), 53 API routes resolve
cleanly via `php artisan route:list --path=api` with no middleware
errors. Register/login were verified once already, but that test used
the old Bearer-token flow — auth has since been switched to
cookie-based SPA auth (see Auth strategy below), so register/login
need re-verifying against the new session-cookie flow before treating
auth as done. The rest of the backend is otherwise confirmed — covers
EV models, vehicles,
station search/CRUD, slots, the full session/queue lifecycle,
favorites, reviews, notifications, road nodes/edges, and the admin
dashboard/verification flow.

**Not yet implemented:** `App\Http\Controllers\Api\TripController::planRoute()`
is a placeholder that just creates an empty `trip_route` row. This is
the hook where the ACO engine plugs in. Everything around it (trip
requests, recalculation lineage, charging stops, trip summaries) is
already built against the real schema, so the API contract shouldn't
need to change once the algorithm is dropped in.

## Auth strategy

**Sanctum cookie-based SPA (session) auth — not Bearer tokens.** Blade
views and the API are served from the same Laravel app (same origin),
so this is the simpler choice. Consequences for how code gets written:
- `AuthController` uses `Auth::login()` / `Auth::attempt()` session
  auth, not `createToken()`. Responses don't include a token.
- Frontend JS must call `GET /sanctum/csrf-cookie` before first use,
  and every non-GET `fetch()` needs `credentials: 'same-origin'` plus
  an `X-XSRF-TOKEN` header read from the `XSRF-TOKEN` cookie (vanilla
  fetch doesn't do this automatically the way axios does).
- `auth:sanctum` middleware in `routes/api.php` doesn't need to
  change — it already supports stateful (session) requests.

## Guardrails

- Never delete a file under `database/migrations/` without checking
  what it creates first. This project has already lost its Laravel
  default migrations and Sanctum's `personal_access_tokens` migration
  once each to overzealous cleanup — both had to be hand-recreated.
  If a migration looks unfamiliar, assume it's framework/package
  plumbing something else depends on, not dead weight.
- `bootstrap/app.php` must have exactly one `->withMiddleware()` call.
  Laravel's fluent `Application::configure()` chain only applies the
  first one — anything chained after `->create()` is silently a no-op.
  If the `role` alias (or anything else) needs adding later, edit the
  existing block in place; don't append a second call.

## Conventions

- snake_case table/column names, PascalCase model names (standard Eloquent)
- API routes live under `/api`, grouped by auth requirement in `routes/api.php`
  (public / any authenticated user / station_owner+admin / admin only)
- Role checks use the `role:` middleware alias (`EnsureUserHasRole`)

## Next steps (in order)

1. ~~Finish the fresh-install setup checklist and confirm the API boots
   cleanly~~ — done, confirmed via real register/login calls.
2. Build the frontend (Leaflet.js map, trip planning form, live trip
   dashboard, station owner and admin dashboards) per the UI mockups —
   starting with the EV owner-facing screens.
3. Implement the ACO route engine in `TripController::planRoute()` —
   builds a graph from `road_nodes`/`road_edges`, scores candidate
   routes on distance, live station occupancy, and remaining battery
   range, and persists the winning route with its `route_charging_stops`.