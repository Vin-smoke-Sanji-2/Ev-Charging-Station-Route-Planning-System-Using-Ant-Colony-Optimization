<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChargingStation;
use App\Services\ChargingStationCreator;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

class ChargingStationController extends Controller
{
    /**
     * Search / list stations. Supports:
     *   ?name=      partial match on station name
     *   ?township=  filter by city/township
     *   ?connector= filter by connector type available at the station's slots
     *   ?fast_only=1 only stations with a slot rated 40kW or above
     *   ?available_now=1 only stations with at least one available slot
     *
     * Each station also carries two computed booleans for the Dashboard
     * map's marker categorization: has_dc_connector (true if any slot has
     * power_type DC) and has_ac_connector (true if any slot has power_type
     * AC). Driven by the explicit power_type column, not a connector_type
     * name match - power_type is the intentionally extensible field for
     * this (see the power_type migration/CLAUDE.md), so a future 5th
     * connector name needs zero changes here. Slot status/availability
     * doesn't matter here - this reflects what connector types the
     * station has, not what's free right now.
     */
    public function index(Request $request)
    {
        $query = ChargingStation::query()
            ->where('verification_status', 'verified')
            ->withCount([
                'slots as available_slots_count' => fn ($q) => $q->where('status', 'available'),
            ])
            ->withCount('slots as total_slots_count')
            ->withExists([
                'slots as has_dc_connector' => fn ($q) => $q->where('power_type', 'DC'),
            ])
            ->withExists([
                'slots as has_ac_connector' => fn ($q) => $q->where('power_type', 'AC'),
            ]);

        if ($request->filled('name')) {
            $query->where('name', 'like', '%'.$request->string('name').'%');
        }

        if ($request->filled('township')) {
            $query->where('township', $request->string('township'));
        }

        if ($request->filled('connector')) {
            $connector = $request->string('connector');
            $query->whereHas('slots', fn ($q) => $q->where('connector_type', $connector));
        }

        if ($request->boolean('fast_only')) {
            $query->whereHas('slots', fn ($q) => $q->where('power_kw', '>=', 40));
        }

        if ($request->boolean('available_now')) {
            $query->whereHas('slots', fn ($q) => $q->where('status', 'available'));
        }

        return response()->json($query->get());
    }

    /**
     * Shared autocomplete endpoint for the Dashboard / Stations search boxes.
     * Unlike index()'s exact township match, both groups here use partial
     * (LIKE %q%) matching, since that's what autocomplete requires.
     */
    public function searchSuggestions(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        if ($q === '') {
            return response()->json(['stations' => [], 'locations' => []]);
        }

        $stations = ChargingStation::query()
            ->where('verification_status', 'verified')
            ->where('name', 'like', '%'.$q.'%')
            ->orderBy('name')
            ->limit(5)
            ->get(['id', 'name', 'township']);

        $locations = ChargingStation::query()
            ->where('verification_status', 'verified')
            ->where('township', 'like', '%'.$q.'%')
            ->whereNotNull('township')
            ->distinct()
            ->orderBy('township')
            ->limit(5)
            ->pluck('township');

        return response()->json([
            'stations' => $stations,
            'locations' => $locations,
        ]);
    }

    public function show(ChargingStation $station)
    {
        $station->load('slots')
            ->loadCount(['reviews'])
            ->loadAvg('reviews', 'rating');

        return response()->json([
            'station' => $station,
            'queue_length' => $station->queueLength(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'address' => 'nullable|string|max:255',
            'township' => 'nullable|string|max:100',
            'charging_speed' => 'nullable|string|max:100',
            'operating_hours' => 'nullable|string|max:100',
        ]);

        $station = (new ChargingStationCreator)->create($request->user()->id, $data);

        return response()->json($station, 201);
    }

    /**
     * The requesting station owner's own station(s), unfiltered by
     * verification_status - unlike index(), which only ever shows
     * verified stations. A station owner needs to see their own station's
     * real current state (including 'pending'/'rejected') on both the
     * Overview page and the My Stations list, which index()'s public/
     * verified-only scoping would hide. Carries the same
     * available_slots_count/total_slots_count withCount pair as index(),
     * added for My Stations' own per-station slot summary - Overview
     * never read these two fields, so this is a strictly additive change.
     */
    public function mine(Request $request)
    {
        return response()->json(
            $request->user()->ownedStations()
                ->withCount([
                    'slots as available_slots_count' => fn ($q) => $q->where('status', 'available'),
                ])
                ->withCount('slots as total_slots_count')
                ->get()
        );
    }

    /**
     * A station owner editing their own already-verified station drops it
     * back to 'pending' for re-review - an approved listing shouldn't be
     * silently mutable without the admin seeing the new data. Deliberately
     * scoped to only this method (not ChargingSlotController) and only to
     * the owner's own edits (never an admin's - an admin's own trusted
     * edit shouldn't trigger a re-review of itself), and only when the
     * station is currently 'verified' - editing an already pending/
     * rejected/suspended station leaves its status untouched, since
     * there's no "verified" state to lose in the first place.
     */
    public function update(Request $request, ChargingStation $station)
    {
        $isAdmin = $request->user()->isAdmin();

        abort_unless($isAdmin || $station->owner_user_id === $request->user()->id, 403);

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'latitude' => 'sometimes|numeric',
            'longitude' => 'sometimes|numeric',
            'address' => 'sometimes|nullable|string|max:255',
            'township' => 'sometimes|nullable|string|max:100',
            'charging_speed' => 'sometimes|nullable|string|max:100',
            'operating_hours' => 'sometimes|nullable|string|max:100',
        ]);

        if (! $isAdmin && count($data) > 0 && $station->verification_status === 'verified') {
            $data['verification_status'] = 'pending';
        }

        $station->update($data);

        return response()->json($station->fresh());
    }

    /**
     * Two real FK risks on this table, handled separately since they need
     * different responses:
     *
     * 1. route_charging_stops.station_id is restrictOnDelete() - a station
     *    that was ever used as a real trip's charging stop can NEVER be
     *    deleted (the DB itself would refuse it). Checked up front so this
     *    is a clean 422 with a real message, not a raw unhandled
     *    QueryException surfacing as a 500 - and re-checked via a
     *    try/catch around the actual delete() below as a defense-in-depth
     *    safety net against a route stop being created in the gap between
     *    this check and that call.
     * 2. charging_slots/charging_sessions/favorite_stations/reviews are
     *    all cascadeOnDelete() on this table - a delete that's otherwise
     *    allowed would silently destroy that data with zero warning.
     *    Slots and favorites are reconstructable/low-stakes and aren't
     *    warned about; charging_sessions and reviews are real historical/
     *    user-generated data, so the admin must see the real counts and
     *    explicitly confirm before it happens. The first call (no
     *    `confirmed` param) always stops here and reports what would be
     *    lost, without deleting anything; only a second, explicit
     *    `confirmed=true` call actually proceeds.
     */
    public function destroy(Request $request, ChargingStation $station)
    {
        abort_unless($request->user()->isAdmin(), 403);

        abort_if(
            $station->routeStops()->exists(),
            422,
            'This station cannot be deleted because it has trip history (it was used as a charging stop on at least one route). Stations with route history can never be deleted.'
        );

        $sessionsCount = $station->sessions()->count();
        $reviewsCount = $station->reviews()->count();

        if (($sessionsCount > 0 || $reviewsCount > 0) && ! $request->boolean('confirmed')) {
            return response()->json([
                'requires_confirmation' => true,
                'sessions_count' => $sessionsCount,
                'reviews_count' => $reviewsCount,
                'message' => "This will permanently delete {$sessionsCount} charging session(s) and {$reviewsCount} review(s). This cannot be undone.",
            ], 422);
        }

        try {
            $station->delete();
        } catch (QueryException $e) {
            abort(422, 'This station cannot be deleted because it has trip history.');
        }

        return response()->json(['message' => 'Station removed']);
    }
}
