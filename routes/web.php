<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    // Already-logged-in users (any role) get bounced straight to their own
    // portal instead of the marketing page - reuses User::landingPage(),
    // the same single source of truth EnsureUserBelongsToPortal redirects
    // with, so this stays in sync automatically (including once an admin
    // portal exists later). Deliberately status-agnostic - a pending/
    // rejected/suspended station_owner still belongs on the page that
    // explains their status, not back on the marketing page.
    if (auth()->check()) {
        return redirect(auth()->user()->landingPage());
    }

    return view('home');
})->name('home');

Route::middleware('guest')->group(function () {
    Route::get('/login', function () {
        return view('auth.login');
    })->name('login');

    Route::get('/register', function () {
        return view('auth.register');
    })->name('register');

    Route::get('/station-owner/register', function () {
        return view('station-owner.register');
    })->name('station-owner.register');

    // A vanity URL, not a real form - login is unified (auth/login.blade.php
    // handles every role, then redirects role-aware after success, see
    // login.js) so a station owner landing here just gets bounced to it.
    Route::get('/station-owner/login', function () {
        return redirect()->route('login');
    })->name('station-owner.login');
});

Route::middleware('auth')->group(function () {
    // /profile is deliberately outside both portal groups below - it's
    // genuinely role-agnostic (AuthController's profile endpoints operate
    // on $request->user() generically, see CLAUDE.md), shared as-is by
    // both an ev_owner and a station_owner.
    Route::get('/profile', function () {
        return view('profile.index');
    })->name('profile');

    // EV-owner-only pages. A station_owner (or any other role) hitting
    // any of these gets redirected to their own real landing page
    // instead of loading a page built for a different portal - see
    // EnsureUserBelongsToPortal's own doc block for the full reasoning.
    Route::middleware('portal:ev_owner')->group(function () {
        Route::get('/dashboard', function () {
            return view('dashboard');
        })->name('dashboard');

        Route::get('/trips/plan', function () {
            return view('trips.plan');
        })->name('trips.plan');

        Route::get('/trips/history', function () {
            return view('trips.history');
        })->name('trips.history');

        Route::get('/trips/live', function () {
            return view('trips.live');
        })->name('trips.live');

        Route::get('/trips/{trip}', function (int $trip) {
            return view('trips.show', ['tripId' => $trip]);
        })->name('trips.show');

        Route::get('/stations', function () {
            return view('stations.index');
        })->name('stations.index');

        Route::get('/stations/{station}', function (int $station) {
            return view('stations.show', ['stationId' => $station]);
        })->name('stations.show');

        Route::get('/vehicles', function () {
            return view('vehicles.index');
        })->name('vehicles.index');

        Route::get('/favorites', function () {
            return view('favorites.index');
        })->name('favorites.index');

        Route::get('/notifications', function () {
            return view('notifications.index');
        })->name('notifications.index');
    });

    // Station-owner-only pages, same portal-redirect protection as above,
    // just the other direction (an ev_owner hitting these gets bounced to
    // /dashboard instead).
    Route::middleware('portal:station_owner')->group(function () {
        Route::get('/station-owner/overview', function () {
            return view('station-owner.overview');
        })->name('station-owner.overview');

        // Both of these are the real management screens, not Overview -
        // Overview is specifically the page built to explain a pending/
        // rejected/suspended account's status, so a station_owner whose
        // status isn't 'active' is bounced back there instead of loading
        // a page that would just silently fail via the underlying API's
        // existing 403 (ChargingStationController::mine() etc. already
        // enforce this server-side - this only improves the page-level
        // experience, it doesn't duplicate that enforcement). Reuses the
        // same stationOwnerAccessDeniedMessage() this project already
        // centralizes that check on, rather than a second copy of the
        // pending/rejected/suspended logic.
        Route::get('/station-owner/stations', function () {
            if (auth()->user()->stationOwnerAccessDeniedMessage()) {
                return redirect()->route('station-owner.overview');
            }

            return view('station-owner.stations-index');
        })->name('station-owner.stations');

        Route::get('/station-owner/stations/{station}', function (int $station) {
            if (auth()->user()->stationOwnerAccessDeniedMessage()) {
                return redirect()->route('station-owner.overview');
            }

            return view('station-owner.stations-show', ['stationId' => $station]);
        })->name('station-owner.stations.show');

        // Reuses the exact same view/JS as the EV Owner's own
        // notifications.index - see that view's own doc comment.
        // Deliberately not gated by stationOwnerAccessDeniedMessage() like
        // the two routes above - even a pending/rejected/suspended owner
        // should be able to see notifications about their own account
        // status (that's literally one of the things this page shows).
        Route::get('/station-owner/notifications', function () {
            return view('notifications.index');
        })->name('station-owner.notifications');
    });

    // Admin-only pages, same portal-redirect protection as the two groups
    // above. EV Models/Road Graph land in their own follow-up prompts, each
    // adding its own route + sidebar entry. The single "Users" page was
    // replaced by a dedicated EV Owners (read-only) page and a dedicated
    // Station Owners (approval workflow) page - see CLAUDE.md's Admin
    // portal approval-workflow-redesign entry. total-users/trips/
    // active-today are the click targets for Overview's own stat cards
    // of the same names - see CLAUDE.md's "New pages, search/filter,
    // button redesign" entry.
    Route::middleware('portal:admin')->group(function () {
        Route::get('/admin/overview', function () {
            return view('admin.overview');
        })->name('admin.overview');

        Route::get('/admin/ev-owners', function () {
            return view('admin.ev-owners');
        })->name('admin.ev-owners');

        Route::get('/admin/station-owners', function () {
            return view('admin.station-owners');
        })->name('admin.station-owners');

        Route::get('/admin/stations', function () {
            return view('admin.stations');
        })->name('admin.stations');

        Route::get('/admin/total-users', function () {
            return view('admin.total-users');
        })->name('admin.total-users');

        Route::get('/admin/trips', function () {
            return view('admin.trips');
        })->name('admin.trips');

        Route::get('/admin/active-today', function () {
            return view('admin.active-today');
        })->name('admin.active-today');

        // Same shared notifications.index view every other portal uses.
        Route::get('/admin/notifications', function () {
            return view('notifications.index');
        })->name('admin.notifications');
    });
});
