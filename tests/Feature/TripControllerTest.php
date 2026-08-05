<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTestData;
use Tests\TestCase;

class TripControllerTest extends TestCase
{
    use CreatesTestData;
    use RefreshDatabase;

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

    // --- Creating a trip ---

    public function test_store_returns_trip_with_a_single_placeholder_route(): void
    {
        $user = $this->makeUser();
        $vehicle = $this->makeVehicle($user);
        $origin = $this->makeRoadNode(['name' => 'Yangon']);
        $destination = $this->makeRoadNode(['name' => 'Mandalay']);

        $response = $this->actingAs($user)->postJson('/api/trips', [
            'vehicle_id' => $vehicle->id,
            'origin_node_id' => $origin->id,
            'destination_node_id' => $destination->id,
            'battery_percent' => 80,
        ]);

        // Note: store()'s response only eager-loads routes.chargingStops.station
        // (unlike show(), which also loads vehicle/originNode/destinationNode) -
        // asserting against the real shape, not the richer one show() returns.
        $response->assertStatus(201)
            ->assertJsonPath('vehicle_id', $vehicle->id)
            ->assertJsonPath('origin_node_id', $origin->id)
            ->assertJsonPath('destination_node_id', $destination->id)
            ->assertJsonCount(1, 'routes')
            ->assertJsonPath('routes.0.status', 'planned')
            ->assertJsonPath('routes.0.previous_route_id', null)
            ->assertJsonPath('routes.0.total_distance_km', null)
            ->assertJsonPath('routes.0.total_duration_min', null)
            ->assertJsonPath('routes.0.estimated_cost', null)
            ->assertJsonPath('routes.0.charging_stops', []);
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

    public function test_viewing_a_nonexistent_trip_returns_404(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->getJson('/api/trips/999999')->assertStatus(404);
    }

    // --- Recalculation ---

    public function test_owner_can_recalculate_and_original_route_is_cancelled(): void
    {
        $user = $this->makeUser();
        $vehicle = $this->makeVehicle($user);
        $origin = $this->makeRoadNode(['name' => 'Yangon']);
        $destination = $this->makeRoadNode(['name' => 'Mandalay']);

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
