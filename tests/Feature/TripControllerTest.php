<?php

namespace Tests\Feature;

use App\Models\RoadEdge;
use App\Models\RoadNode;
use App\Models\RouteChargingStop;
use App\Models\TripRoute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTestData;
use Tests\TestCase;

class TripControllerTest extends TestCase
{
    use CreatesTestData;
    use RefreshDatabase;

    /**
     * A station-type road node with a linked, verified ChargingStation
     * and exactly one slot — mirrors the real Stage 1 shape (one
     * station node per real station) closely enough for ACO tests.
     */
    private function makeStationNode(string $name = 'Test Station', bool $slotAvailable = true): RoadNode
    {
        $node = $this->makeRoadNode(['name' => $name, 'type' => 'station']);
        $station = $this->makeStation(null, ['name' => $name, 'road_node_id' => $node->id]);
        $this->makeSlot($station, ['status' => $slotAvailable ? 'available' : 'occupied']);

        return $node;
    }

    // --- Vehicle ownership (the critical case) ---

    public function test_cannot_create_trip_with_another_users_vehicle(): void
    {
        $owner = $this->makeUser();
        $intruder = $this->makeUser();
        $vehicle = $this->makeVehicle($owner);
        $origin = $this->makeRoadNode(['name' => 'Yangon']);
        $destination = $this->makeRoadNode(['name' => 'Mandalay']);

        $response = $this->actingAs($intruder)->postJson('/api/trips', [
            'vehicle_id' => $vehicle->id,
            'origin_node_id' => $origin->id,
            'destination_node_id' => $destination->id,
            'battery_percent' => 80,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('vehicle_id');
        $this->assertDatabaseCount('trip_requests', 0);
    }

    public function test_owner_can_create_trip_with_own_vehicle(): void
    {
        $user = $this->makeUser();
        $vehicle = $this->makeVehicle($user);
        $origin = $this->makeRoadNode(['name' => 'Yangon']);
        $destination = $this->makeRoadNode(['name' => 'Mandalay']);
        RoadEdge::create(['from_node_id' => $origin->id, 'to_node_id' => $destination->id, 'distance_km' => 50, 'avg_speed_kmh' => 60]);

        $response = $this->actingAs($user)->postJson('/api/trips', [
            'vehicle_id' => $vehicle->id,
            'origin_node_id' => $origin->id,
            'destination_node_id' => $destination->id,
            'battery_percent' => 80,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('trip_requests', [
            'user_id' => $user->id,
            'vehicle_id' => $vehicle->id,
        ]);
    }

    // --- Creating a trip: real ACO planning ---

    public function test_store_produces_a_real_route_with_no_stops_when_within_range(): void
    {
        $user = $this->makeUser();
        $vehicle = $this->makeVehicle($user); // default EvModel: 60kWh / 400km range
        $origin = $this->makeRoadNode(['name' => 'Yangon']);
        $destination = $this->makeRoadNode(['name' => 'Mandalay']);
        // Only one edge exists at all, well within the vehicle's range at
        // 80% battery (remaining 320km) - deterministic: no station in
        // this graph, so there's nothing to stop at even if the algorithm
        // wanted to.
        RoadEdge::create(['from_node_id' => $origin->id, 'to_node_id' => $destination->id, 'distance_km' => 50, 'avg_speed_kmh' => 60]);

        $response = $this->actingAs($user)->postJson('/api/trips', [
            'vehicle_id' => $vehicle->id,
            'origin_node_id' => $origin->id,
            'destination_node_id' => $destination->id,
            'battery_percent' => 80,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('vehicle_id', $vehicle->id)
            ->assertJsonCount(1, 'routes')
            ->assertJsonPath('routes.0.status', 'planned')
            ->assertJsonPath('routes.0.total_distance_km', '50.00')
            ->assertJsonPath('routes.0.charging_stops', []);

        $this->assertGreaterThan(0, $response->json('routes.0.total_duration_min'));
    }

    public function test_store_produces_a_real_route_with_a_stop_when_it_exceeds_range(): void
    {
        $user = $this->makeUser();
        $evModel = $this->makeEvModel(['battery_capacity_kwh' => 20, 'max_range_km' => 20]);
        $vehicle = $this->makeVehicle($user, $evModel);
        $origin = $this->makeRoadNode(['name' => 'Origin City']);
        $station = $this->makeStationNode('Midway Station');
        $destination = $this->makeRoadNode(['name' => 'Destination City']);
        // No direct origin->destination edge at all - the only path is via
        // the station, and at 80% battery (16km remaining) the vehicle
        // cannot cover both 15km legs without recharging, so a stop is
        // physically mandatory, not just probable.
        RoadEdge::create(['from_node_id' => $origin->id, 'to_node_id' => $station->id, 'distance_km' => 15, 'avg_speed_kmh' => 40]);
        RoadEdge::create(['from_node_id' => $station->id, 'to_node_id' => $destination->id, 'distance_km' => 15, 'avg_speed_kmh' => 40]);

        $response = $this->actingAs($user)->postJson('/api/trips', [
            'vehicle_id' => $vehicle->id,
            'origin_node_id' => $origin->id,
            'destination_node_id' => $destination->id,
            'battery_percent' => 80,
        ]);

        $response->assertStatus(201)
            ->assertJsonCount(1, 'routes')
            ->assertJsonPath('routes.0.total_distance_km', '30.00')
            ->assertJsonCount(1, 'routes.0.charging_stops')
            ->assertJsonPath('routes.0.charging_stops.0.sequence_no', 0)
            ->assertJsonPath('routes.0.charging_stops.0.station.name', 'Midway Station');

        $this->assertGreaterThan(0, $response->json('routes.0.estimated_cost'));
        $this->assertGreaterThan(0, $response->json('routes.0.est_battery_consumption'));
    }

    public function test_stops_are_persisted_in_strictly_increasing_sequence_order(): void
    {
        $user = $this->makeUser();
        $evModel = $this->makeEvModel(['battery_capacity_kwh' => 20, 'max_range_km' => 20]);
        $vehicle = $this->makeVehicle($user, $evModel);
        $origin = $this->makeRoadNode(['name' => 'Chain Origin']);
        $stationA = $this->makeStationNode('Chain Station A');
        $stationB = $this->makeStationNode('Chain Station B');
        $destination = $this->makeRoadNode(['name' => 'Chain Destination']);
        // Forces two mandatory stops in a row: each leg is right at the
        // edge of what's feasible on a full (80%-charged) battery, so the
        // vehicle must recharge at both A and B in turn - only one path
        // exists through this graph at all.
        RoadEdge::create(['from_node_id' => $origin->id, 'to_node_id' => $stationA->id, 'distance_km' => 15, 'avg_speed_kmh' => 40]);
        RoadEdge::create(['from_node_id' => $stationA->id, 'to_node_id' => $stationB->id, 'distance_km' => 15, 'avg_speed_kmh' => 40]);
        RoadEdge::create(['from_node_id' => $stationB->id, 'to_node_id' => $destination->id, 'distance_km' => 15, 'avg_speed_kmh' => 40]);

        $response = $this->actingAs($user)->postJson('/api/trips', [
            'vehicle_id' => $vehicle->id,
            'origin_node_id' => $origin->id,
            'destination_node_id' => $destination->id,
            'battery_percent' => 80,
        ]);

        $response->assertStatus(201)->assertJsonCount(2, 'routes.0.charging_stops');
        $sequenceNumbers = collect($response->json('routes.0.charging_stops'))->pluck('sequence_no')->all();
        $this->assertSame([0, 1], $sequenceNumbers);
    }

    public function test_infeasible_battery_and_range_combination_returns_422_without_persisting_a_route(): void
    {
        $user = $this->makeUser();
        $evModel = $this->makeEvModel(['battery_capacity_kwh' => 20, 'max_range_km' => 50]);
        $vehicle = $this->makeVehicle($user, $evModel);
        $origin = $this->makeRoadNode(['name' => 'Stranded Origin']);
        $destination = $this->makeRoadNode(['name' => 'Unreachable Destination']);
        // 1% battery on a 50km-range vehicle leaves 0.5km - nowhere near
        // enough for this 100km edge, and there is no station anywhere in
        // this graph to rescue the ant.
        RoadEdge::create(['from_node_id' => $origin->id, 'to_node_id' => $destination->id, 'distance_km' => 100, 'avg_speed_kmh' => 60]);

        $response = $this->actingAs($user)->postJson('/api/trips', [
            'vehicle_id' => $vehicle->id,
            'origin_node_id' => $origin->id,
            'destination_node_id' => $destination->id,
            'battery_percent' => 1,
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('No feasible route', $response->json('message'));
        $this->assertDatabaseCount('trip_routes', 0);
    }

    public function test_repeated_planning_calls_always_produce_a_feasible_result(): void
    {
        $user = $this->makeUser();
        $evModel = $this->makeEvModel(['battery_capacity_kwh' => 30, 'max_range_km' => 60]);
        $vehicle = $this->makeVehicle($user, $evModel);
        $origin = $this->makeRoadNode(['name' => 'Branch Origin']);
        $stationA = $this->makeStationNode('Branch Station A');
        $stationB = $this->makeStationNode('Branch Station B');
        $destination = $this->makeRoadNode(['name' => 'Branch Destination']);
        // Two genuinely different, symmetric routes exist (via A or via
        // B) - which one any given ant/run picks is real, probabilistic
        // variation. Both routes mandate exactly one recharge by
        // construction (70% battery = 42km remaining; each first leg
        // leaves under the 30%-of-60km=18km trigger), so every feasible
        // run must land on the same totals (30+35=65km, 1 stop) even
        // though the actual station visited varies run to run.
        RoadEdge::create(['from_node_id' => $origin->id, 'to_node_id' => $stationA->id, 'distance_km' => 30, 'avg_speed_kmh' => 40]);
        RoadEdge::create(['from_node_id' => $stationA->id, 'to_node_id' => $destination->id, 'distance_km' => 35, 'avg_speed_kmh' => 40]);
        RoadEdge::create(['from_node_id' => $origin->id, 'to_node_id' => $stationB->id, 'distance_km' => 35, 'avg_speed_kmh' => 40]);
        RoadEdge::create(['from_node_id' => $stationB->id, 'to_node_id' => $destination->id, 'distance_km' => 30, 'avg_speed_kmh' => 40]);

        for ($i = 0; $i < 5; $i++) {
            $trip = $this->makeTripRequest($user, $origin, $destination, $vehicle, ['battery_percent' => 70]);

            $response = $this->actingAs($user)->postJson("/api/trips/{$trip->id}/recalculate", [
                'reason' => "repeat run {$i}",
                'current_battery_percent' => 70,
            ]);

            // recalculate() serializes the just-created TripRoute instance
            // directly (only its chargingStops relation is re-loaded from
            // the DB) - total_distance_km is still the real PHP float this
            // request computed, not a re-fetched DECIMAL-as-string the way
            // store()'s response is (which reloads the whole route fresh
            // via the parent trip's ->load() call).
            // json_encode drops the trailing .0 for a whole-number float,
            // so this decodes back as a plain int, not 65.0.
            $response->assertStatus(200)
                ->assertJsonPath('total_distance_km', 65)
                ->assertJsonCount(1, 'charging_stops');
        }
    }

    public function test_planning_completes_within_a_reasonable_time(): void
    {
        $user = $this->makeUser();
        $vehicle = $this->makeVehicle($user);
        $origin = $this->makeRoadNode(['name' => 'Perf Origin']);
        $destination = $this->makeRoadNode(['name' => 'Perf Destination']);
        RoadEdge::create(['from_node_id' => $origin->id, 'to_node_id' => $destination->id, 'distance_km' => 50, 'avg_speed_kmh' => 60]);

        $start = microtime(true);

        $this->actingAs($user)->postJson('/api/trips', [
            'vehicle_id' => $vehicle->id,
            'origin_node_id' => $origin->id,
            'destination_node_id' => $destination->id,
            'battery_percent' => 80,
        ])->assertStatus(201);

        $elapsed = microtime(true) - $start;

        $this->assertLessThan(5.0, $elapsed, 'ACO planning must stay fast — it runs synchronously in an HTTP request.');
    }

    public function test_origin_and_destination_must_differ(): void
    {
        $user = $this->makeUser();
        $vehicle = $this->makeVehicle($user);
        $node = $this->makeRoadNode();

        $this->actingAs($user)->postJson('/api/trips', [
            'vehicle_id' => $vehicle->id,
            'origin_node_id' => $node->id,
            'destination_node_id' => $node->id,
            'battery_percent' => 80,
        ])->assertStatus(422)->assertJsonValidationErrors('destination_node_id');
    }

    public function test_battery_percent_must_be_within_zero_to_a_hundred(): void
    {
        $user = $this->makeUser();
        $vehicle = $this->makeVehicle($user);
        $origin = $this->makeRoadNode(['name' => 'Yangon']);
        $destination = $this->makeRoadNode(['name' => 'Mandalay']);

        $base = [
            'vehicle_id' => $vehicle->id,
            'origin_node_id' => $origin->id,
            'destination_node_id' => $destination->id,
        ];

        $this->actingAs($user)->postJson('/api/trips', $base + ['battery_percent' => -5])
            ->assertStatus(422)->assertJsonValidationErrors('battery_percent');

        $this->actingAs($user)->postJson('/api/trips', $base + ['battery_percent' => 101])
            ->assertStatus(422)->assertJsonValidationErrors('battery_percent');
    }

    public function test_each_required_field_is_individually_enforced(): void
    {
        $user = $this->makeUser();
        $vehicle = $this->makeVehicle($user);
        $origin = $this->makeRoadNode(['name' => 'Yangon']);
        $destination = $this->makeRoadNode(['name' => 'Mandalay']);

        $full = [
            'vehicle_id' => $vehicle->id,
            'origin_node_id' => $origin->id,
            'destination_node_id' => $destination->id,
            'battery_percent' => 80,
        ];

        foreach (['vehicle_id', 'origin_node_id', 'destination_node_id', 'battery_percent'] as $field) {
            $payload = $full;
            unset($payload[$field]);

            $this->actingAs($user)->postJson('/api/trips', $payload)
                ->assertStatus(422)
                ->assertJsonValidationErrors($field);
        }
    }

    public function test_nonexistent_vehicle_and_nodes_are_rejected(): void
    {
        $user = $this->makeUser();
        $vehicle = $this->makeVehicle($user);
        $origin = $this->makeRoadNode(['name' => 'Yangon']);
        $destination = $this->makeRoadNode(['name' => 'Mandalay']);

        $this->actingAs($user)->postJson('/api/trips', [
            'vehicle_id' => 999999,
            'origin_node_id' => $origin->id,
            'destination_node_id' => $destination->id,
            'battery_percent' => 80,
        ])->assertStatus(422)->assertJsonValidationErrors('vehicle_id');

        $this->actingAs($user)->postJson('/api/trips', [
            'vehicle_id' => $vehicle->id,
            'origin_node_id' => 999999,
            'destination_node_id' => $destination->id,
            'battery_percent' => 80,
        ])->assertStatus(422)->assertJsonValidationErrors('origin_node_id');

        $this->actingAs($user)->postJson('/api/trips', [
            'vehicle_id' => $vehicle->id,
            'origin_node_id' => $origin->id,
            'destination_node_id' => 999999,
            'battery_percent' => 80,
        ])->assertStatus(422)->assertJsonValidationErrors('destination_node_id');
    }

    // --- Listing trips ---

    public function test_index_only_returns_the_authenticated_users_trips(): void
    {
        $userA = $this->makeUser();
        $userB = $this->makeUser();
        $origin = $this->makeRoadNode(['name' => 'Yangon']);
        $destination = $this->makeRoadNode(['name' => 'Mandalay']);

        $this->makeTripRequest($userA, $origin, $destination);
        $this->makeTripRequest($userB, $origin, $destination);

        $this->actingAs($userA)->getJson('/api/trips')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    // --- Viewing a trip ---

    public function test_owner_can_view_own_trip_with_full_nested_data(): void
    {
        $user = $this->makeUser();
        $vehicle = $this->makeVehicle($user);
        $origin = $this->makeRoadNode(['name' => 'Yangon']);
        $destination = $this->makeRoadNode(['name' => 'Mandalay']);
        $trip = $this->makeTripRequest($user, $origin, $destination, $vehicle);
        $route = $this->makeTripRoute($trip);

        $response = $this->actingAs($user)->getJson("/api/trips/{$trip->id}");

        $response->assertStatus(200)
            ->assertJsonPath('vehicle.id', $vehicle->id)
            ->assertJsonPath('vehicle.ev_model.id', $vehicle->ev_model_id)
            ->assertJsonPath('origin_node.id', $origin->id)
            ->assertJsonPath('destination_node.id', $destination->id)
            ->assertJsonPath('routes.0.id', $route->id);
    }

    public function test_non_owner_cannot_view_a_trip(): void
    {
        $owner = $this->makeUser();
        $intruder = $this->makeUser();
        $origin = $this->makeRoadNode(['name' => 'Yangon']);
        $destination = $this->makeRoadNode(['name' => 'Mandalay']);
        $trip = $this->makeTripRequest($owner, $origin, $destination);

        $this->actingAs($intruder)->getJson("/api/trips/{$trip->id}")->assertStatus(403);
    }

    /**
     * Trip Result/Live Trip's stop lists need real-time slot availability
     * (e.g. "2/3 available"), not just the station name - previously this
     * wasn't even eager-loaded for this context, let alone rendered. Reuses
     * the exact same available_slots_count/total_slots_count withCount
     * pattern ChargingStationController::index() already uses (see
     * TripController::withStationAvailability()).
     */
    public function test_show_includes_real_time_slot_availability_on_charging_stops(): void
    {
        $user = $this->makeUser();
        $vehicle = $this->makeVehicle($user);
        $origin = $this->makeRoadNode(['name' => 'Yangon']);
        $destination = $this->makeRoadNode(['name' => 'Mandalay']);
        $trip = $this->makeTripRequest($user, $origin, $destination, $vehicle);
        $route = $this->makeTripRoute($trip);
        $station = $this->makeStation();
        $this->makeSlot($station, ['status' => 'available']);
        $this->makeSlot($station, ['status' => 'available']);
        $this->makeSlot($station, ['status' => 'occupied']);
        RouteChargingStop::create(['trip_route_id' => $route->id, 'station_id' => $station->id, 'sequence_no' => 0]);

        $response = $this->actingAs($user)->getJson("/api/trips/{$trip->id}");

        $response->assertStatus(200)
            ->assertJsonPath('routes.0.charging_stops.0.station.available_slots_count', 2)
            ->assertJsonPath('routes.0.charging_stops.0.station.total_slots_count', 3);
    }

    public function test_viewing_a_nonexistent_trip_returns_404(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->getJson('/api/trips/999999')->assertStatus(404);
    }

    // --- route_geometry (RouteGeometryBuilder, wired into show()) ---

    public function test_show_includes_real_route_geometry_from_a_fresh_plan(): void
    {
        $user = $this->makeUser();
        $vehicle = $this->makeVehicle($user);
        $origin = $this->makeRoadNode(['name' => 'Yangon']);
        $destination = $this->makeRoadNode(['name' => 'Mandalay']);
        // A single edge, but with a real multi-point geometry - proves
        // route_geometry reflects the edge's actual shape, not just the
        // two waypoint endpoints.
        \App\Models\RoadEdge::create([
            'from_node_id' => $origin->id,
            'to_node_id' => $destination->id,
            'distance_km' => 50,
            'avg_speed_kmh' => 60,
            'geometry' => [[16.80, 96.15], [16.81, 96.16], [16.82, 96.17], [16.83, 96.18], [16.84, 96.19]],
        ]);

        $planResponse = $this->actingAs($user)->postJson('/api/trips', [
            'vehicle_id' => $vehicle->id,
            'origin_node_id' => $origin->id,
            'destination_node_id' => $destination->id,
            'battery_percent' => 80,
        ]);
        $planResponse->assertStatus(201);
        $tripId = $planResponse->json('id');

        $this->assertDatabaseHas('trip_routes', [
            'trip_request_id' => $tripId,
        ]);
        $route = \App\Models\TripRoute::where('trip_request_id', $tripId)->first();
        $this->assertNotNull($route->edge_path_ids, 'AcoRouteEngine should have persisted the traversed edge path.');

        $response = $this->actingAs($user)->getJson("/api/trips/{$tripId}");

        $response->assertStatus(200);
        $geometry = $response->json('route_geometry');

        $this->assertIsArray($geometry);
        $this->assertCount(5, $geometry);
        $this->assertGreaterThan(2, count($geometry)); // more than just the 2 waypoint endpoints
        $this->assertSame([16.80, 96.15], $geometry[0]);
        $this->assertSame([16.84, 96.19], $geometry[4]);
    }

    public function test_show_falls_back_to_dijkstra_when_edge_path_ids_is_null(): void
    {
        $user = $this->makeUser();
        $vehicle = $this->makeVehicle($user);
        $origin = $this->makeRoadNode(['name' => 'Yangon']);
        $destination = $this->makeRoadNode(['name' => 'Mandalay']);
        \App\Models\RoadEdge::create([
            'from_node_id' => $origin->id,
            'to_node_id' => $destination->id,
            'distance_km' => 50,
            'avg_speed_kmh' => 60,
            'geometry' => [[16.80, 96.15], [16.81, 96.16], [16.82, 96.17]],
        ]);

        $planResponse = $this->actingAs($user)->postJson('/api/trips', [
            'vehicle_id' => $vehicle->id,
            'origin_node_id' => $origin->id,
            'destination_node_id' => $destination->id,
            'battery_percent' => 80,
        ]);
        $tripId = $planResponse->json('id');

        // Simulate a trip planned before edge_path_ids existed.
        \App\Models\TripRoute::where('trip_request_id', $tripId)->update(['edge_path_ids' => null]);

        $response = $this->actingAs($user)->getJson("/api/trips/{$tripId}");

        $response->assertStatus(200);
        $geometry = $response->json('route_geometry');

        $this->assertIsArray($geometry);
        $this->assertCount(3, $geometry);
        $this->assertGreaterThan(2, count($geometry));
        $this->assertSame([16.80, 96.15], $geometry[0]);
        $this->assertSame([16.82, 96.17], $geometry[2]);
    }

    // --- Recalculation ---

    public function test_owner_can_recalculate_and_original_route_is_cancelled(): void
    {
        $user = $this->makeUser();
        $vehicle = $this->makeVehicle($user);
        $origin = $this->makeRoadNode(['name' => 'Yangon']);
        $destination = $this->makeRoadNode(['name' => 'Mandalay']);
        RoadEdge::create(['from_node_id' => $origin->id, 'to_node_id' => $destination->id, 'distance_km' => 50, 'avg_speed_kmh' => 60]);

        $storeResponse = $this->actingAs($user)->postJson('/api/trips', [
            'vehicle_id' => $vehicle->id,
            'origin_node_id' => $origin->id,
            'destination_node_id' => $destination->id,
            'battery_percent' => 90,
        ]);
        $tripId = $storeResponse->json('id');
        $originalRouteId = $storeResponse->json('routes.0.id');

        $response = $this->actingAs($user)->postJson("/api/trips/{$tripId}/recalculate", [
            'reason' => 'Station became full',
            'current_battery_percent' => 45,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('previous_route_id', $originalRouteId)
            ->assertJsonPath('recalculation_reason', 'Station became full')
            ->assertJsonPath('status', 'planned');

        $this->assertDatabaseHas('trip_routes', [
            'id' => $originalRouteId,
            'status' => 'cancelled',
        ]);
    }

    /**
     * Recalculating a trip that is currently ACTIVE (started, mid-journey -
     * the real-world case the recalculate() docblock itself describes: "a
     * recommended station became fully occupied mid-journey") must produce
     * another active route, not strand the trip. planRoute() always
     * persists a fresh route as 'planned' by default (correct for a
     * first-time plan), so without recalculate() explicitly carrying
     * active-ness forward, the trip would vanish from GET /trips/active the
     * moment it's recalculated mid-drive, and the one-active-trip rule
     * would stop blocking a second trip despite this one still being in
     * progress.
     */
    public function test_recalculating_an_active_trip_keeps_it_active(): void
    {
        $user = $this->makeUser();
        $vehicle = $this->makeVehicle($user);
        $origin = $this->makeRoadNode(['name' => 'Yangon']);
        $destination = $this->makeRoadNode(['name' => 'Mandalay']);
        RoadEdge::create(['from_node_id' => $origin->id, 'to_node_id' => $destination->id, 'distance_km' => 50, 'avg_speed_kmh' => 60]);

        $storeResponse = $this->actingAs($user)->postJson('/api/trips', [
            'vehicle_id' => $vehicle->id,
            'origin_node_id' => $origin->id,
            'destination_node_id' => $destination->id,
            'battery_percent' => 90,
        ]);
        $tripId = $storeResponse->json('id');
        $originalRouteId = $storeResponse->json('routes.0.id');

        $this->actingAs($user)->postJson("/api/trips/{$tripId}/start")->assertStatus(200);
        $originalStartedAt = TripRoute::find($originalRouteId)->started_at;

        $response = $this->actingAs($user)->postJson("/api/trips/{$tripId}/recalculate", [
            'reason' => 'Station became full mid-journey',
            'current_battery_percent' => 45,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('previous_route_id', $originalRouteId)
            ->assertJsonPath('status', 'active');

        $newRouteId = $response->json('id');

        $this->assertDatabaseHas('trip_routes', ['id' => $originalRouteId, 'status' => 'cancelled']);
        $this->assertDatabaseHas('trip_routes', ['id' => $newRouteId, 'status' => 'active']);
        $this->assertNotNull(TripRoute::find($newRouteId)->started_at);
        $this->assertEquals(
            $originalStartedAt->toIso8601String(),
            TripRoute::find($newRouteId)->started_at->toIso8601String(),
            'The drive itself did not restart, only the plan did - started_at should carry over.'
        );

        // The trip must still be reachable via GET /trips/active - this is
        // exactly what Live Trip's page polls, and what the one-active-trip
        // rule checks before allowing a second trip to start.
        $activeResponse = $this->actingAs($user)->getJson('/api/trips/active');
        $activeResponse->assertOk();
        $this->assertEquals($newRouteId, $activeResponse->json('routes.0.id'));
    }

    /**
     * Proves the Part 1 fix: recalculate() must plan against the
     * request's own current_battery_percent, not the trip's original
     * battery_percent. The graph is built so the direct edge is only
     * feasible at the ORIGINAL high battery — at the low recalculation
     * battery it is physically impossible, forcing the only remaining
     * path (via a station, with a mandatory recharge). If the bug were
     * still present (silently reusing the original 90% battery), the
     * short direct edge would remain feasible and be the overwhelmingly
     * likely pick, never producing this forced detour.
     */
    public function test_recalculate_uses_the_new_battery_percent_not_the_original(): void
    {
        $user = $this->makeUser();
        $evModel = $this->makeEvModel(['battery_capacity_kwh' => 40, 'max_range_km' => 80]);
        $vehicle = $this->makeVehicle($user, $evModel);
        $origin = $this->makeRoadNode(['name' => 'Detour Origin']);
        $station = $this->makeStationNode('Detour Station');
        $destination = $this->makeRoadNode(['name' => 'Detour Destination']);
        RoadEdge::create(['from_node_id' => $origin->id, 'to_node_id' => $destination->id, 'distance_km' => 20, 'avg_speed_kmh' => 50]);
        RoadEdge::create(['from_node_id' => $origin->id, 'to_node_id' => $station->id, 'distance_km' => 10, 'avg_speed_kmh' => 40]);
        RoadEdge::create(['from_node_id' => $station->id, 'to_node_id' => $destination->id, 'distance_km' => 50, 'avg_speed_kmh' => 40]);

        $storeResponse = $this->actingAs($user)->postJson('/api/trips', [
            'vehicle_id' => $vehicle->id,
            'origin_node_id' => $origin->id,
            'destination_node_id' => $destination->id,
            'battery_percent' => 90, // remaining 72km - the direct 20km edge is feasible
        ]);
        $tripId = $storeResponse->json('id');

        // 15% of 80km = 12km remaining - the direct 20km edge is now
        // physically infeasible, so the only possible route is via the
        // station (10km there, mandatory recharge, 50km onward = 60km).
        $response = $this->actingAs($user)->postJson("/api/trips/{$tripId}/recalculate", [
            'reason' => 'Battery much lower than the original plan assumed',
            'current_battery_percent' => 15,
        ]);

        // See the note in test_repeated_planning_calls_always_produce_a_feasible_result()
        // for why recalculate()'s total_distance_km is a real number here,
        // unlike store()'s re-fetched decimal-as-string - and json_encode
        // drops the trailing .0 for a whole-number float, so this decodes
        // back as a plain int, not 60.0.
        $response->assertStatus(200)
            ->assertJsonPath('total_distance_km', 60)
            ->assertJsonCount(1, 'charging_stops')
            ->assertJsonPath('charging_stops.0.station.name', 'Detour Station');
    }

    public function test_recalculate_requires_reason_and_current_battery_percent(): void
    {
        $user = $this->makeUser();
        $origin = $this->makeRoadNode(['name' => 'Yangon']);
        $destination = $this->makeRoadNode(['name' => 'Mandalay']);
        $trip = $this->makeTripRequest($user, $origin, $destination);

        $this->actingAs($user)->postJson("/api/trips/{$trip->id}/recalculate", [
            'current_battery_percent' => 50,
        ])->assertStatus(422)->assertJsonValidationErrors('reason');

        $this->actingAs($user)->postJson("/api/trips/{$trip->id}/recalculate", [
            'reason' => 'Station full',
        ])->assertStatus(422)->assertJsonValidationErrors('current_battery_percent');
    }

    public function test_non_owner_cannot_recalculate_someone_elses_trip(): void
    {
        $owner = $this->makeUser();
        $intruder = $this->makeUser();
        $origin = $this->makeRoadNode(['name' => 'Yangon']);
        $destination = $this->makeRoadNode(['name' => 'Mandalay']);
        $trip = $this->makeTripRequest($owner, $origin, $destination);

        $this->actingAs($intruder)->postJson("/api/trips/{$trip->id}/recalculate", [
            'reason' => 'Trying to hijack this trip',
            'current_battery_percent' => 50,
        ])->assertStatus(403);
    }

    public function test_recalculating_a_trip_with_no_existing_route_does_not_crash(): void
    {
        $user = $this->makeUser();
        $origin = $this->makeRoadNode(['name' => 'Yangon']);
        $destination = $this->makeRoadNode(['name' => 'Mandalay']);
        RoadEdge::create(['from_node_id' => $origin->id, 'to_node_id' => $destination->id, 'distance_km' => 50, 'avg_speed_kmh' => 60]);
        $trip = $this->makeTripRequest($user, $origin, $destination);
        // No route created for this trip - latestRoute will be null.

        $response = $this->actingAs($user)->postJson("/api/trips/{$trip->id}/recalculate", [
            'reason' => 'First plan for this trip',
            'current_battery_percent' => 60,
        ]);

        $response->assertStatus(200)->assertJsonPath('previous_route_id', null);
    }

    // --- Summary ---

    public function test_summary_of_a_trip_with_no_sessions_returns_zeros_not_errors(): void
    {
        $user = $this->makeUser();
        $origin = $this->makeRoadNode(['name' => 'Yangon']);
        $destination = $this->makeRoadNode(['name' => 'Mandalay']);
        $trip = $this->makeTripRequest($user, $origin, $destination);
        $this->makeTripRoute($trip, ['status' => 'planned']);

        $response = $this->actingAs($user)->getJson("/api/trips/{$trip->id}/summary");

        $response->assertStatus(200)
            ->assertJsonPath('actual_cost', 0)
            ->assertJsonPath('charging_time_min', 0)
            ->assertJsonPath('charging_stops', 0)
            ->assertJsonPath('total_distance_km', null)
            ->assertJsonPath('estimated_cost', null)
            ->assertJsonPath('status', 'planned');
    }

    public function test_summary_sums_completed_charging_sessions_correctly(): void
    {
        $user = $this->makeUser();
        $origin = $this->makeRoadNode(['name' => 'Yangon']);
        $destination = $this->makeRoadNode(['name' => 'Mandalay']);
        $trip = $this->makeTripRequest($user, $origin, $destination);
        $route = $this->makeTripRoute($trip);
        $station = $this->makeStation();

        $this->makeSession($station, $user, [
            'trip_route_id' => $route->id,
            'status' => 'completed',
            'payment_amount' => 5000,
            'started_at' => now()->subMinutes(30),
            'ended_at' => now(),
        ]);
        $this->makeSession($station, $user, [
            'trip_route_id' => $route->id,
            'status' => 'completed',
            'payment_amount' => 3000,
            'started_at' => now()->subMinutes(20),
            'ended_at' => now(),
        ]);
        // Still in progress - must not be counted.
        $this->makeSession($station, $user, [
            'trip_route_id' => $route->id,
            'status' => 'charging',
            'payment_amount' => 9999,
        ]);

        $response = $this->actingAs($user)->getJson("/api/trips/{$trip->id}/summary");

        $response->assertStatus(200)
            ->assertJsonPath('actual_cost', 8000)
            ->assertJsonPath('charging_time_min', 50);
    }

    public function test_summary_of_a_trip_with_no_route_returns_404(): void
    {
        $user = $this->makeUser();
        $origin = $this->makeRoadNode(['name' => 'Yangon']);
        $destination = $this->makeRoadNode(['name' => 'Mandalay']);
        $trip = $this->makeTripRequest($user, $origin, $destination);
        // No route created for this trip.

        $this->actingAs($user)->getJson("/api/trips/{$trip->id}/summary")->assertStatus(404);
    }

    public function test_non_owner_cannot_view_summary(): void
    {
        $owner = $this->makeUser();
        $intruder = $this->makeUser();
        $origin = $this->makeRoadNode(['name' => 'Yangon']);
        $destination = $this->makeRoadNode(['name' => 'Mandalay']);
        $trip = $this->makeTripRequest($owner, $origin, $destination);
        $this->makeTripRoute($trip);

        $this->actingAs($intruder)->getJson("/api/trips/{$trip->id}/summary")->assertStatus(403);
    }

    // --- Unauthenticated access ---

    public function test_all_trip_routes_require_authentication(): void
    {
        $user = $this->makeUser();
        $origin = $this->makeRoadNode(['name' => 'Yangon']);
        $destination = $this->makeRoadNode(['name' => 'Mandalay']);
        $trip = $this->makeTripRequest($user, $origin, $destination);

        $this->getJson('/api/trips')->assertStatus(401);
        $this->postJson('/api/trips', [])->assertStatus(401);
        $this->getJson("/api/trips/{$trip->id}")->assertStatus(401);
        $this->postJson("/api/trips/{$trip->id}/recalculate", [])->assertStatus(401);
        $this->getJson("/api/trips/{$trip->id}/summary")->assertStatus(401);
    }
}
