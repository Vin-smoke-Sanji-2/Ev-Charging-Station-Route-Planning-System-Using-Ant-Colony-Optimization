<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChargingStation;
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

        $data['owner_user_id'] = $request->user()->id;
        $data['verification_status'] = 'pending';
        $data['total_slots'] = 0;

        $station = ChargingStation::create($data);

        return response()->json($station, 201);
    }

    public function update(Request $request, ChargingStation $station)
    {
        abort_unless(
            $request->user()->isAdmin() || $station->owner_user_id === $request->user()->id,
            403
        );

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'latitude' => 'sometimes|numeric',
            'longitude' => 'sometimes|numeric',
            'address' => 'sometimes|nullable|string|max:255',
            'township' => 'sometimes|nullable|string|max:100',
            'charging_speed' => 'sometimes|nullable|string|max:100',
            'operating_hours' => 'sometimes|nullable|string|max:100',
        ]);

        $station->update($data);

        return response()->json($station->fresh());
    }

    public function destroy(Request $request, ChargingStation $station)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $station->delete();

        return response()->json(['message' => 'Station removed']);
    }
}
