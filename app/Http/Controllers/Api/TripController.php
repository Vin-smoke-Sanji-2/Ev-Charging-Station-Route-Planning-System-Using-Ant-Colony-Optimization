<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TripRequest;
use App\Models\TripRoute;
use Illuminate\Http\Request;

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
            'routes.chargingStops.station',
        ]);

        return response()->json($trip);
    }

    /**
     * Create a trip request and hand it to the ACO route engine.
     *
     * NOTE: route planning itself (ant colony optimization over the
     * road_nodes / road_edges graph, weighing distance, live station
     * occupancy and remaining battery) is built in the next phase. This
     * endpoint currently persists the request and a placeholder
     * trip_route so the API contract for the frontend is stable already.
     * Swap the body of planRoute() below for the real ACO call.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'vehicle_id' => 'required|exists:user_vehicles,id',
            'origin_node_id' => 'required|exists:road_nodes,id',
            'destination_node_id' => 'required|exists:road_nodes,id|different:origin_node_id',
            'battery_percent' => 'required|numeric|min:0|max:100',
        ]);

        $data['user_id'] = $request->user()->id;
        $data['requested_at'] = now();

        $trip = TripRequest::create($data);
        $route = $this->planRoute($trip);

        return response()->json($trip->load('routes.chargingStops.station'), 201);
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
        $previousRoute?->update(['status' => 'cancelled']);

        $route = $this->planRoute($trip, previousRouteId: $previousRoute?->id, reason: $data['reason']);

        return response()->json($route->load('chargingStops.station'));
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
     * TODO(ACO phase): replace this placeholder with a call into the
     * ant colony optimization engine over road_nodes / road_edges,
     * weighing distance, real-time station occupancy and battery range.
     * It should return a persisted TripRoute with its RouteChargingStop
     * rows already attached.
     */
    private function planRoute(TripRequest $trip, ?int $previousRouteId = null, ?string $reason = null): TripRoute
    {
        return TripRoute::create([
            'trip_request_id' => $trip->id,
            'previous_route_id' => $previousRouteId,
            'recalculation_reason' => $reason,
            'status' => 'planned',
        ]);
    }

    private function authorizeOwner(Request $request, TripRequest $trip): void
    {
        abort_unless($trip->user_id === $request->user()->id, 403);
    }
}
