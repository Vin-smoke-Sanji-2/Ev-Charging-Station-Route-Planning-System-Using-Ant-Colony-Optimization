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

**Fully confirmed working, end to end.** Migrated (17 tables +
`personal_access_tokens`), 53 API routes resolve cleanly via
`php artisan route:list --path=api` with no middleware errors, and the
full cookie-session auth cycle (register, login, `me`, logout,
unauthenticated `me` → 401) is verified against a real
`php artisan serve` instance. The backend is done — covers auth,
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

**Sanctum cookie-based SPA (session) auth — not Bearer tokens. Fully
verified end to end**, including the unauthenticated-request edge
case: login → `me` → logout → `me` after logout correctly returns 401
(not 200, not a 500).

- `AuthController` uses `Auth::login()` / `Auth::attempt()` session
  auth, not `createToken()`. Responses don't include a token.
- **Every frontend `fetch()` call to `/api/*` must go through one
  shared wrapper function**, not be called ad hoc, and that wrapper
  must always set:
  - `Accept: application/json` — without this, an unauthenticated
    request doesn't cleanly 401, it 500s (Laravel's default web guard
    tries to redirect to a `login` route that doesn't exist in this
    API-only app). This bit us during testing and must not be left to
    each call site to remember.
  - `credentials: 'same-origin'` — sends the session cookie.
  - `X-XSRF-TOKEN` header, read from the `XSRF-TOKEN` cookie, on every
    non-GET request.
- Call `GET /sanctum/csrf-cookie` once before the first authenticated
  action (e.g. on app load, or lazily before the first POST) to obtain
  that cookie.
- `auth:sanctum` middleware in `routes/api.php` doesn't need to
  change — it already supports stateful (session) requests via
  `$middleware->statefulApi()` in `bootstrap/app.php`.

## Testing strategy

**Done — 88 feature tests, all passing**, using PHPUnit (this project's
`laravel new` setup never pulled in Pest, despite the leftover
`pestphp/pest-plugin` entry in `composer.json`'s `allow-plugins`; no
`pestphp/pest` package is actually required).

Tests run against a **separate MySQL database, `ev_route_planner_test`**
(created directly via the MySQL client, not a migration) — not SQLite,
to avoid the driver-mismatch class of bug this project already hit
once. `phpunit.xml` overrides `DB_CONNECTION`/`DB_DATABASE` for the
`testing` environment only; the real dev `.env` is untouched. Every
`tests/Feature/*Test.php` class uses `RefreshDatabase`. Shared
fixture builders (`makeUser`, `makeStation`, `makeSlot`, etc. — plain
`Model::create()` calls, since only `User` has a factory) live in
`tests/Feature/Concerns/CreatesTestData.php`.

**Gotcha baked into `tests/TestCase.php`:** `statefulApi()`'s
`EnsureFrontendRequestsAreStateful` middleware only starts the session
for requests whose `Referer`/`Origin` header matches
`SANCTUM_STATEFUL_DOMAINS` — without it `$request->session()` throws.
`TestCase::setUp()` sets a default `Referer: http://127.0.0.1:8000`
header on every test request to satisfy this (mirrors what a real
browser sends automatically, so it's a test-client-only concern, not a
production one — same root cause as the `/sanctum/csrf-cookie` note
above).

**Gotcha when testing logout specifically:** Laravel's `RequestGuard`
(what `auth:sanctum` resolves to) caches the user it resolved on the
*first* authenticated request within a test and won't re-check it on
later simulated requests sharing the same container — a real browser
gets a fresh container per request, so this never happens in
production. `AuthTest::test_logout_invalidates_the_session` calls
`$this->app['auth']->forgetGuards()` after logout, before asserting
the follow-up `/api/auth/me` call 401s, to force re-resolution.

**`slot_id` on completed sessions — this is correct behavior, not a
bug. Do not "fix" it.** `ChargingSessionController::update()` leaves a
completed/cancelled session's `slot_id` pointing at whichever physical
slot it used — that's the historical record of which slot a finished
charging event occupied. When the next queued session gets promoted
into that same physical slot, it's expected and correct for it to
reference the same `slot_id` too, the same way many different hotel
reservations legitimately share the same room number over time.
Current occupancy is determined by `status = 'charging'`, not by
`slot_id` uniqueness, so nothing depends on `slot_id` being distinct
per session — nulling it out on completion would only destroy the
historical "which slot did this session use" record for no benefit.
`ChargingSessionTest::test_completing_a_session_promotes_the_next_waiting_session`
asserts this shared-`slot_id` behavior explicitly.

## Frontend status

**EV owner-facing screens built and verified in a real browser** (Blade +
Bootstrap 5 + vanilla JS + Leaflet, per the stack — no JS framework, no
axios; `resources/js/bootstrap.js` is unused dead scaffolding left over
from `laravel new`). Built so far:

- Shared layouts: `resources/views/layouts/guest.blade.php` (landing/
  login/register) and `layouts/app.blade.php` (navbar + left sidebar
  nav — Dashboard, Plan Trip, Live Trip, Stations, Trip History, My
  EVs, Favorites, Notifications, Profile, Logout). Green/white
  branding lives entirely in `resources/css/app.css` as overrides on
  top of stock Bootstrap (no Sass rebuild — Bootstrap ships one fixed
  `--bs-primary`, so re-theming means overriding `.btn-primary` etc.
  directly, not swapping a variable).
- `home.blade.php` (landing), `auth/login.blade.php`, `auth/register.blade.php`,
  `dashboard.blade.php` (minimal post-login landing spot).
- `trips/plan.blade.php` — origin/destination/vehicle/battery% form,
  posts to `POST /api/trips`, redirects to `/trips/{id}`.
- `trips/show.blade.php` — route result screen: distance/duration/
  cost/stop-count stat tiles, a Leaflet map (origin + destination +
  any charging stops, joined by a dashed placeholder line since real
  route geometry doesn't exist until the ACO engine lands), and a
  charging-stops list. Handles the current all-null/empty-stops state
  from the `planRoute()` placeholder gracefully (dashes instead of
  blanks, an explanatory empty-state message, map still renders with
  just the two endpoints) — confirmed both the empty state and a
  manually-seeded populated state render correctly.
- Sidebar links with no screen yet (Live Trip, Stations, Trip History,
  My EVs, Favorites, Notifications, Profile) point at a shared
  `coming-soon.blade.php` placeholder so nothing 404s.

**`resources/js/api.js`** is the *only* thing allowed to call
`fetch()` against `/api/*` — one exported `apiFetch(url, options)`
that lazily GETs `/sanctum/csrf-cookie` once per page load, always
sets `Accept: application/json` + `credentials: 'same-origin'`, adds
`X-XSRF-TOKEN` on non-GET requests, and redirects to `/login` on a 401
— **except** when the caller passes `redirectOn401: false`, which
`login.js`/`register.js` do for their own submit calls, since a wrong
password/email is an *expected* 401 they need to show inline, not a
"session expired" signal.

**Page routes are protected server-side**, not just client-side: since
`auth:sanctum`'s `statefulApi()` guard is backed by the `web` session
guard (see Auth strategy above), the *same* session cookie authenticates
both `/api/*` calls and plain Blade page loads. So `routes/web.php`
wraps `/dashboard`, `/trips/*`, etc. in the standard `auth` middleware
(named `login` route required, or it 500s trying to redirect — same
root cause as the `Accept: application/json` gotcha) and `/login` /
`/register` in `guest`, both framework-default aliases, no custom
middleware class needed.

**Leaflet gotcha (fixed 2026-08-04):** in `trip-show.js`'s `renderMap()`,
never add the tile layer before the map has a real view. The original
code did `L.map('trip-map')` (no center/zoom) → `tileLayer(...).addTo(map)`
→ `fitBounds(points)` afterward. That first `.addTo(map)` call let the
tile layer start loading tiles for the map's default/undefined view,
and then `fitBounds()`'s implicit pan/zoom *animation* stepped through
every intermediate zoom level between that default and the real one —
each step fetching a full tile grid and cancelling the previous one.
One page load generated 800+ requests and took 1.5+ minutes. Fix:
compute `points`/bounds first, call `fitBounds(points, { animate: false })`
(or `setView(..., { animate: false })` for the single-point case) to
settle the map's *only* view, and only then add the tile layer and
markers. Confirmed after the fix: `/trips/2` loads in ~25 total
requests (~15 of them tiles, one correctly-sized grid), under 1MB,
~2s to network-idle.

**Build step:** Vite (`laravel-vite-plugin`) bundles `bootstrap`,
`bootstrap-icons`, and `leaflet` from npm (added as deps; `tailwindcss`
was already in `package.json` from `laravel new` but is unused — no
`@tailwind` directives anywhere, harmless dead config). Multi-page,
not a real SPA: every screen with its own JS is a separate Vite entry
in `vite.config.js`'s `input` array (`app.js` plus one file per page
under `resources/js/pages/`) — adding a new screen means adding its
entry there too, or `npm run build` fails with "Could not resolve
entry module." **Run `npm run build` (not `npm run dev`) before
testing with `php artisan serve`** — `@vite()` falls back to reading
`public/build/manifest.json` when no Vite dev server is running
(detected via a `public/hot` file), so a one-time build is enough; no
need to keep a Vite dev server up alongside `artisan serve`.

**Verified how:** no browser available directly in this environment,
so verification used a throwaway Playwright script (chromium, headless)
driven against a real `php artisan serve` instance — screenshots plus
`page.on('response')`/`console` listeners to confirm the exact `/api/*`
calls, status codes, and absence of JS errors, not just that pages
rendered. Confirmed: full register → dashboard → logout →
dashboard-redirects-to-login → wrong-password-shows-inline-error →
correct-login-reaches-dashboard cycle; full plan-trip →
POST /api/trips (201) → redirect to `/trips/{id}` cycle; both the
empty-route and populated-route states of the result screen. The dev
database now has a handful of seeded rows (one `EvModel`, three
`RoadNode`s — Yangon/Mandalay/Naypyidaw, one verified `ChargingStation`,
one test user's vehicle and trip) left over from that verification —
harmless real sample data, not cleaned up, useful for exercising the
frontend by hand going forward.

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
2. ~~Write feature tests covering the backend~~ — done, 88 passing
   tests against a real MySQL test database (see Testing strategy).
3. ~~Build the EV owner-facing screens~~ — done: shared layout/nav,
   landing/login/register, trip planning form, and the route result
   screen with its Leaflet map (see Frontend status). Station owner
   and admin dashboards, and the remaining EV-owner screens (Live
   Trip, Stations, Trip History, My EVs, Favorites, Notifications,
   Profile) are still just `coming-soon` placeholders in the sidebar.
4. Implement the ACO route engine in `TripController::planRoute()` —
   builds a graph from `road_nodes`/`road_edges`, scores candidate
   routes on distance, live station occupancy, and remaining battery
   range, and persists the winning route with its `route_charging_stops`.