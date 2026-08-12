<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RouteChargingStop;
use App\Models\TripRequest;
use App\Models\TripRoute;
use App\Services\AcoRouteEngine;
use App\Services\RouteGeometryBuilder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TripController extends Controller
{
    public function index(Request $request)
    {
        $trips = $request->user()->tripRequests()
            ->with(['vehicle.evModel', 'originNode', 'destinationNode', 'latestRoute'])
            ->latest()
            ->paginate(15);

        return response()->json($trips);
    }

    public function show(Request $request, TripRequest $trip)
    {
        $this->authorizeOwner($request, $trip);

        $trip->load([
            'vehicle.evModel',
            'originNode',
            'destinationNode',
            'routes.chargingStops.station' => fn ($query) => $this->withStationAvailability($query),
        ]);

        // The most recently created route is the one actually shown/rendered
        // (trip-show.js picks the last element of routes[] the same way) -
        // computed server-side so the frontend never needs to know about
        // edge_path_ids, Dijkstra, or any of this reconstruction logic.
        $latestRoute = $trip->routes->sortBy('id')->last();
        $routeGeometry = $latestRoute ? (new RouteGeometryBuilder)->build($latestRoute) : null;

        $data = $trip->toArray();
        $data['route_geometry'] = $routeGeometry;

        return response()->json($data);
    }

    /**
     * Create a trip request and hand it to the ACO route engine.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'vehicle_id' => [
                'required',
                Rule::exists('user_vehicles', 'id')->where('user_id', $request->user()->id),
            ],
            'origin_node_id' => 'required|exists:road_nodes,id',
            'destination_node_id' => 'required|exists:road_nodes,id|different:origin_node_id',
            'battery_percent' => 'required|numeric|min:0|max:100',
        ]);

        $data['user_id'] = $request->user()->id;
        $data['requested_at'] = now();

        $trip = TripRequest::create($data);
        $this->planRoute($trip);

        return response()->json($trip->load([
            'routes.chargingStops.station' => fn ($query) => $this->withStationAvailability($query),
        ]), 201);
    }

    /**
     * Re-run route planning for a trip, e.g. because a recommended
     * station became fully occupied mid-journey. Links the new route
     * back to the one it replaces via previous_route_id.
     */
    public function recalculate(Request $request, TripRequest $trip)
    {
        $this->authorizeOwner($request, $trip);

        $data = $request->validate([
            'reason' => 'required|string|max:255',
            'current_battery_percent' => 'required|numeric|min:0|max:100',
        ]);

        $previousRoute = $trip->latestRoute;
        $wasActive = $previousRoute?->status === 'active';
        $previousStartedAt = $previousRoute?->started_at;
        $previousRoute?->update(['status' => 'cancelled']);

        $route = $this->planRoute(
            $trip,
            startingBatteryPercent: $data['current_battery_percent'],
            previousRouteId: $previousRoute?->id,
            reason: $data['reason']
        );

        // A recalculation mid-trip must produce another active route, not
        // leave the trip stranded in limbo - planRoute() always persists a
        // fresh route as 'planned' (the correct default for a first-time
        // plan), so a recalculation of an already-active trip has to
        // explicitly carry that active-ness forward itself. Without this,
        // the old route becomes 'cancelled' and the new one stays 'planned',
        // so the trip disappears from GET /trips/active entirely - Live
        // Trip would show "no active trip" and the one-active-trip rule
        // would stop blocking a second trip, even though the driver is
        // still mid-journey. started_at is carried over from the original
        // route rather than reset to now(), since the drive itself didn't
        // restart - only the plan did.
        if ($wasActive) {
            $route->update(['status' => 'active', 'started_at' => $previousStartedAt ?? now()]);
        }

        return response()->json($route->load([
            'chargingStops.station' => fn ($query) => $this->withStationAvailability($query),
        ]));
    }

    public function summary(Request $request, TripRequest $trip)
    {
        $this->authorizeOwner($request, $trip);

        $route = $trip->latestRoute;
        abort_unless($route, 404, 'No route found for this trip');

        $sessions = $route->chargingSessions()->where('status', 'completed')->get();

        return response()->json([
            'total_distance_km' => $route->total_distance_km,
            'total_duration_min' => $route->total_duration_min,
            'estimated_cost' => $route->estimated_cost,
            'actual_cost' => $sessions->sum('payment_amount'),
            'charging_stops' => $route->chargingStops()->count(),
            'charging_time_min' => $sessions->sum(fn ($s) => $s->started_at && $s->ended_at
                ? $s->started_at->diffInMinutes($s->ended_at)
                : 0),
            'status' => $route->status,
        ]);
    }

    /**
     * Manually starts a Live Trip. Everything after this is automatic -
     * arrival at a stop/the destination is detected client-side (geofence)
     * and reported via markStopReached()/complete(); there is no manual
     * "mark as reached" or "complete trip" action anywhere.
     */
    public function start(Request $request, TripRequest $trip)
    {
        $this->authorizeOwner($request, $trip);

        $route = $trip->latestRoute;
        abort_if(
            ! $route || $route->status !== 'planned',
            422,
            'This trip cannot be started - it has no plan, or has already been started, completed, or cancelled.'
        );

        $hasAnotherActiveTrip = TripRoute::where('status', 'active')
            ->whereHas('tripRequest', fn ($q) => $q->where('user_id', $request->user()->id))
            ->exists();
        abort_if($hasAnotherActiveTrip, 422, 'You already have an active trip - finish it before starting another.');

        $route->update(['status' => 'active', 'started_at' => now()]);

        return response()->json($route->load([
            'chargingStops.station' => fn ($query) => $this->withStationAvailability($query),
        ]));
    }

    /**
     * The requesting user's current in-progress trip, shaped like show()'s
     * response (trip + routes[] + route_geometry) so the frontend can reuse
     * the exact same rendering as Trip Result - just scoped to whichever
     * route is 'active' instead of "whichever is latest." Returns a bare
     * `null` body (not 404) when there is no active trip - this is a
     * legitimate empty state, not an error.
     */
    public function active(Request $request)
    {
        $route = TripRoute::where('status', 'active')
            ->whereHas('tripRequest', fn ($q) => $q->where('user_id', $request->user()->id))
            ->with([
                'tripRequest.vehicle.evModel',
                'tripRequest.originNode',
                'tripRequest.destinationNode',
                'chargingStops.station' => fn ($query) => $this->withStationAvailability($query),
            ])
            ->first();

        if (! $route) {
            // response()->json(null) is NOT a literal `null` body - Laravel/
            // Symfony's JsonResponse constructor can't distinguish "null
            // explicitly passed" from "no argument given" and silently
            // serializes it as `{}` instead (confirmed directly - a real
            // gotcha, not assumed). `{}` decodes to a truthy object in JS,
            // which would have broken the frontend's `if (!trip)` empty-
            // state check. Calling setData() after construction bypasses
            // that constructor default and forces the real literal `null`.
            return response()->json()->setData(null);
        }

        $trip = $route->tripRequest;
        $trip->setRelation('routes', collect([$route]));

        $data = $trip->toArray();
        $data['route_geometry'] = (new RouteGeometryBuilder)->build($route);

        return response()->json($data);
    }

    /**
     * Called by the frontend's automatic geofence detection when the live
     * position comes within range of the next unreached stop - never a
     * manual button. Still fully validated server-side, same discipline as
     * every other endpoint here: real ownership check, and $stop must
     * actually belong to this trip's active route AND be the genuinely
     * next unreached one in sequence_no order (rejects out-of-order or
     * duplicate calls rather than trusting the client's geofence alone).
     */
    public function markStopReached(Request $request, TripRequest $trip, RouteChargingStop $stop)
    {
        $this->authorizeOwner($request, $trip);

        $route = $trip->latestRoute;
        abort_if(! $route || $route->status !== 'active', 422, 'This trip is not currently active.');
        abort_if($stop->trip_route_id !== $route->id, 422, 'This stop does not belong to the active trip.');

        $nextStop = $route->chargingStops()->whereNull('reached_at')->orderBy('sequence_no')->first();
        abort_if(
            ! $nextStop || $nextStop->id !== $stop->id,
            422,
            'This is not the next stop to reach - stops must be reached in order.'
        );

        $stop->update(['reached_at' => now()]);

        return response()->json($route->load([
            'chargingStops.station' => fn ($query) => $this->withStationAvailability($query),
        ]));
    }

    /**
     * Called by the frontend's automatic destination-arrival detection -
     * there is no manual "Complete Trip" button anywhere in this app.
     */
    public function complete(Request $request, TripRequest $trip)
    {
        $this->authorizeOwner($request, $trip);

        $route = $trip->latestRoute;
        abort_if(! $route || $route->status !== 'active', 422, 'This trip is not currently active.');

        $route->update(['status' => 'completed', 'completed_at' => now()]);

        return response()->json($route->load([
            'chargingStops.station' => fn ($query) => $this->withStationAvailability($query),
        ]));
    }

    /**
     * Manually abandons an active trip - the only way out of a Live Trip
     * short of driving to the destination. Unlike recalculate(), this does
     * NOT create a replacement route; it ends the trip outright and frees
     * the one-active-trip slot immediately. Closes a real gap: before this
     * existed, a trip that could never legitimately complete (permanently
     * lost location permission, the driver genuinely gave up, etc.) had no
     * recovery path at all short of direct DB access.
     */
    public function cancel(Request $request, TripRequest $trip)
    {
        $this->authorizeOwner($request, $trip);

        $route = $trip->latestRoute;
        abort_if(! $route || $route->status !== 'active', 422, 'This trip is not currently active.');

        $route->update(['status' => 'cancelled']);

        return response()->json($route->load([
            'chargingStops.station' => fn ($query) => $this->withStationAvailability($query),
        ]));
    }

    /**
     * Plans a real route via Ant Colony Optimization over road_nodes /
     * road_edges. Used for both a fresh plan (store(), where
     * $startingBatteryPercent defaults to the trip's original
     * battery_percent) and a recalculation (recalculate(), which
     * passes the driver's real current battery level explicitly —
     * using the trip's original battery_percent here would silently
     * replan against stale data).
     */
    private function planRoute(
        TripRequest $trip,
        ?float $startingBatteryPercent = null,
        ?int $previousRouteId = null,
        ?string $reason = null
    ): TripRoute {
        return (new AcoRouteEngine)->plan(
            $trip,
            $startingBatteryPercent ?? (float) $trip->battery_percent,
            $previousRouteId,
            $reason
        );
    }

    private function authorizeOwner(Request $request, TripRequest $trip): void
    {
        abort_unless($trip->user_id === $request->user()->id, 403);
    }

    /**
     * The exact same available_slots_count/total_slots_count withCount
     * pattern ChargingStationController::index() already uses - reused
     * here (not reinvented) so a charging stop's station carries real
     * slot availability, the same data Stations/Dashboard already show,
     * for Trip Result/Live Trip's stop lists to display.
     */
    private function withStationAvailability($query): void
    {
        $query->withCount([
            'slots as available_slots_count' => fn ($q) => $q->where('status', 'available'),
        ])->withCount('slots as total_slots_count');
    }
}
