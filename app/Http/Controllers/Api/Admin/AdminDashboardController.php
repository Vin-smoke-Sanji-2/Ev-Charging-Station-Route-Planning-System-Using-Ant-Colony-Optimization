<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChargingStation;
use App\Models\TripRequest;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Http\Request;

class AdminDashboardController extends Controller
{
    /**
     * Single source of truth for legal status transitions - the frontend
     * never re-derives these; it just calls a named button that sends one
     * specific target status, and the server 422s if that transition isn't
     * listed here. 'rejected' is deliberately terminal for both maps (no
     * outgoing transitions at all) - once rejected, the only way back in is
     * to register again, not to be reconsidered through this endpoint.
     */
    private const USER_STATUS_TRANSITIONS = [
        'pending' => ['active', 'rejected'],
        'active' => ['suspended'],
        'suspended' => ['active'],
        'rejected' => [],
    ];

    private const STATION_STATUS_TRANSITIONS = [
        'pending' => ['verified', 'rejected'],
        'verified' => ['suspended'],
        'suspended' => ['verified'],
        'rejected' => [],
    ];

    public function stats()
    {
        return response()->json([
            'total_users' => User::count(),
            'total_stations' => ChargingStation::count(),
            'total_trips' => TripRequest::count(),
            // Bundled bug fix (added alongside the Active Today page below):
            // this used to count admin accounts too, unlike users() and
            // recent_registrations just below, which both already exclude
            // role='admin'. An admin updating their own row today would
            // silently inflate this number - excluded now for the same
            // reason recent_registrations already is.
            'active_today' => User::where('role', '!=', 'admin')->whereDate('updated_at', today())->count(),
            'pending_station_verifications' => ChargingStation::where('verification_status', 'pending')->count(),
            // Admin accounts aren't a role this portal manages/displays
            // anywhere else (see users() below) - excluded here too so one
            // never surfaces in the Recent Registrations widget just
            // because it happens to be among the 5 newest rows.
            'recent_registrations' => User::where('role', '!=', 'admin')->latest()->limit(5)->get(['id', 'name', 'role', 'created_at']),
        ]);
    }

    /**
     * 'admin' is excluded unconditionally, even against an explicit
     * `?role=admin` request (the two where()s AND together, so that combo
     * can never match a row) - this admin portal only ever manages EV
     * owners and station owners, never other admin accounts. `?status=`
     * accepts a comma-separated list (`status=active,suspended`) so a
     * single request can power a combined "Accepted & Suspended" tab, not
     * just one exact status at a time - a single value degrades to the
     * same one-element whereIn(), so this is backward compatible.
     */
    public function users(Request $request)
    {
        $query = User::query()->where('role', '!=', 'admin');

        if ($request->filled('role')) {
            $query->where('role', $request->string('role'));
        }

        if ($request->filled('status')) {
            $query->whereIn('status', array_filter(explode(',', (string) $request->string('status'))));
        }

        $this->applyNameSearch($query, $request);

        return response()->json($query->latest()->paginate(20));
    }

    /**
     * Matches both name and email - an admin searching for a specific
     * person often only remembers one of the two. Wrapped in its own
     * where() closure rather than a bare orWhere() so it ANDs with
     * whatever the caller already added to $query (role='!=admin',
     * status filters, whereDate('updated_at', ...) on activeToday()
     * below) instead of OR-ing around them - a bare orWhere() here would
     * silently leak excluded rows (e.g. admin accounts) back into the
     * results the moment a search term was present.
     */
    private function applyNameSearch($query, Request $request): void
    {
        if (! $request->filled('name')) {
            return;
        }

        $search = $request->string('name');

        $query->where(function ($q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%");
        });
    }

    /**
     * Full paginated/filterable/searchable list backing stats()'s
     * active_today count above - same 'role != admin' + whereDate(...)
     * base query, so the count and this list are always consistent with
     * each other.
     */
    public function activeToday(Request $request)
    {
        $query = User::query()
            ->where('role', '!=', 'admin')
            ->whereDate('updated_at', today());

        if ($request->filled('role')) {
            $query->where('role', $request->string('role'));
        }

        $this->applyNameSearch($query, $request);

        return response()->json($query->latest('updated_at')->paginate(20));
    }

    /**
     * Guarded against an admin locking out their own account or another
     * admin's - this project has no recovery path for that today (no
     * "reactivate via email," no superuser override, only direct DB/tinker
     * access), so a mis-click here has a real, unrecoverable-from-the-UI
     * cost. Both checks run before validation, so even a malformed request
     * against a protected target fails for the real reason, not a generic
     * validation error.
     *
     * Two further guards added alongside the approval-workflow redesign:
     * this endpoint only ever manages station owner approval status (EV
     * owners have no approval workflow at all - see CLAUDE.md), and every
     * transition is checked against USER_STATUS_TRANSITIONS so an accepted
     * owner can never be reverted to pending, suspend can never be applied
     * before acceptance, and rejected is terminal.
     */
    public function updateUserStatus(Request $request, User $user)
    {
        abort_if(
            $user->is($request->user()),
            422,
            'You cannot change your own account status.'
        );

        abort_if(
            $user->isAdmin(),
            422,
            'Admin account status cannot be changed through this endpoint.'
        );

        abort_if(
            $user->role !== 'station_owner',
            422,
            'This endpoint only manages station owner approval status.'
        );

        $data = $request->validate([
            'status' => 'required|in:active,pending,suspended,rejected',
        ]);

        abort_if(
            ! in_array($data['status'], self::USER_STATUS_TRANSITIONS[$user->status] ?? [], true),
            422,
            "Cannot change status from {$user->status} to {$data['status']}."
        );

        $user->update($data);

        $user->appNotifications()->create([
            'type' => 'Account',
            'message' => "Your station owner account status changed to {$data['status']}.",
        ]);

        return response()->json($user->fresh());
    }

    /**
     * Replaces the old pending-only pendingStations() - the admin Stations
     * page now needs Pending / Verified & Suspended / Rejected tabs, not
     * just a pending queue, so this mirrors users()'s filterable+paginated
     * shape (no role param, since stations have no role). No `?status=`
     * filter returns every station, matching users()'s own no-filter
     * behavior - the real UI always sends an explicit status per tab.
     */
    public function stations(Request $request)
    {
        $query = ChargingStation::query()->with('owner:id,name,email');

        if ($request->filled('status')) {
            $query->whereIn('verification_status', array_filter(explode(',', (string) $request->string('status'))));
        }

        // Mirrors ChargingStationController::index()'s own public-list
        // precedent for name search - name only, a station has no second
        // identifier worth searching the way a user has name+email.
        if ($request->filled('name')) {
            $query->where('name', 'like', '%'.$request->string('name').'%');
        }

        return response()->json($query->latest()->paginate(20));
    }

    /**
     * Same transition-guard discipline as updateUserStatus() above, mirrored
     * for stations: a station can only be suspended once verified (never
     * straight from pending), can only be un-suspended back to verified
     * (never to pending), and rejected is terminal. No self/admin-identity
     * guard is needed here - a station has no "own account" concept - so
     * validate-then-transition-check is the natural order.
     */
    public function verifyStation(Request $request, ChargingStation $station)
    {
        $data = $request->validate([
            'verification_status' => 'required|in:verified,rejected,suspended',
        ]);

        abort_if(
            ! in_array($data['verification_status'], self::STATION_STATUS_TRANSITIONS[$station->verification_status] ?? [], true),
            422,
            "Cannot change verification status from {$station->verification_status} to {$data['verification_status']}."
        );

        $station->update($data);

        // A station created outside the normal owner flow (e.g. one of the
        // originally-seeded stations) can have no owner_user_id at all -
        // nothing to notify in that case.
        if ($station->owner_user_id) {
            UserNotification::create([
                'user_id' => $station->owner_user_id,
                'type' => 'Station',
                'message' => "Your station \"{$station->name}\" status changed to {$data['verification_status']}.",
            ]);
        }

        return response()->json($station->fresh());
    }
}
