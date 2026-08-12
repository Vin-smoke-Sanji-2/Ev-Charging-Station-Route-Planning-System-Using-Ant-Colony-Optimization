<?php

namespace Tests\Feature;

use App\Models\RouteChargingStop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTestData;
use Tests\TestCase;

class LiveTripTest extends TestCase
{
    use CreatesTestData;
    use RefreshDatabase;

    // --- start() ---

    public function test_owner_can_start_a_planned_trip(): void
    {
        $user = $this->makeUser();
        $origin = $this->makeRoadNode(['name' => 'Yangon']);
        $destination = $this->makeRoadNode(['name' => 'Mandalay']);
        $trip = $this->makeTripRequest($user, $origin, $destination);
        $route = $this->makeTripRoute($trip, ['status' => 'planned']);

        $response = $this->actingAs($user)->postJson("/api/trips/{$trip->id}/start");

        $response->assertStatus(200);
        $this->assertDatabaseHas('trip_routes', [
            'id' => $route->id,
            'status' => 'active',
        ]);
        $this->assertNotNull($route->fresh()->started_at);
    }

    public function test_cannot_start_a_trip_that_is_not_planned(): void
    {
        $user = $this->makeUser();
        $origin = $this->makeRoadNode(['name' => 'Yangon']);
        $destination = $this->makeRoadNode(['name' => 'Mandalay']);
        $trip = $this->makeTripRequest($user, $origin, $destination);
        $route = $this->makeTripRoute($trip, ['status' => 'completed', 'completed_at' => now()]);

        $response = $this->actingAs($user)->postJson("/api/trips/{$trip->id}/start");

        $response->assertStatus(422);
        $this->assertSame('completed', $route->fresh()->status);
    }

    public function test_non_owner_cannot_start_another_users_trip(): void
    {
        $owner = $this->makeUser();
        $intruder = $this->makeUser();
        $origin = $this->makeRoadNode(['name' => 'Yangon']);
        $destination = $this->makeRoadNode(['name' => 'Mandalay']);
        $trip = $this->makeTripRequest($owner, $origin, $destination);
        $this->makeTripRoute($trip, ['status' => 'planned']);

        $this->actingAs($intruder)->postJson("/api/trips/{$trip->id}/start")->assertStatus(403);
    }

    public function test_cannot_start_a_second_trip_while_one_is_already_active(): void
    {
        $user = $this->makeUser();
        $origin = $this->makeRoadNode(['name' => 'Yangon']);
        $destination = $this->makeRoadNode(['name' => 'Mandalay']);

        $activeTrip = $this->makeTripRequest($user, $origin, $destination);
        $this->makeTripRoute($activeTrip, ['status' => 'active', 'started_at' => now()]);

        $secondTrip = $this->makeTripRequest($user, $origin, $destination);
        $this->makeTripRoute($secondTrip, ['status' => 'planned']);

        $response = $this->actingAs($user)->postJson("/api/trips/{$secondTrip->id}/start");

        $response->assertStatus(422);
        $this->assertDatabaseHas('trip_routes', ['trip_request_id' => $secondTrip->id, 'status' => 'planned']);
    }

    // --- active() ---

    public function test_active_returns_the_users_current_active_trip(): void
    {
        $user = $this->makeUser();
        $origin = $this->makeRoadNode(['name' => 'Yangon']);
        $destination = $this->makeRoadNode(['name' => 'Mandalay']);
        $trip = $this->makeTripRequest($user, $origin, $destination);
        $route = $this->makeTripRoute($trip, ['status' => 'active', 'started_at' => now()]);
        $station = $this->makeStation();
        RouteChargingStop::create(['trip_route_id' => $route->id, 'station_id' => $station->id, 'sequence_no' => 0]);

        $response = $this->actingAs($user)->getJson('/api/trips/active');

        $response->assertStatus(200)
            ->assertJsonPath('id', $trip->id)
            ->assertJsonPath('routes.0.id', $route->id)
            ->assertJsonPath('routes.0.charging_stops.0.station_id', $station->id)
            ->assertJsonPath('routes.0.charging_stops.0.reached_at', null);
        $this->assertArrayHasKey('route_geometry', $response->json());
    }

    public function test_active_returns_null_when_no_active_trip(): void
    {
        $user = $this->makeUser();

        $response = $this->actingAs($user)->getJson('/api/trips/active');

        $response->assertStatus(200);
        // Laravel's TestResponse::json() itself can't distinguish "the body
        // is the literal JSON value null" from "decoding failed" - it fails
        // the assertion either way, so this checks the raw body directly.
        // A real browser's fetch().json() has no such ambiguity - it
        // correctly returns JS `null` for this exact same response.
        $this->assertSame('null', $response->getContent());
    }

    public function test_active_only_returns_the_requesting_users_own_trip(): void
    {
        $userA = $this->makeUser();
        $userB = $this->makeUser();
        $origin = $this->makeRoadNode(['name' => 'Yangon']);
        $destination = $this->makeRoadNode(['name' => 'Mandalay']);
        $tripA = $this->makeTripRequest($userA, $origin, $destination);
        $this->makeTripRoute($tripA, ['status' => 'active', 'started_at' => now()]);

        $response = $this->actingAs($userB)->getJson('/api/trips/active');

        $response->assertStatus(200);
        $this->assertSame('null', $response->getContent());
    }

    // --- markStopReached() ---

    public function test_owner_can_mark_the_next_stop_reached_in_order(): void
    {
        $user = $this->makeUser();
        $origin = $this->makeRoadNode(['name' => 'Yangon']);
        $destination = $this->makeRoadNode(['name' => 'Mandalay']);
        $trip = $this->makeTripRequest($user, $origin, $destination);
        $route = $this->makeTripRoute($trip, ['status' => 'active', 'started_at' => now()]);
        $stop1 = RouteChargingStop::create(['trip_route_id' => $route->id, 'station_id' => $this->makeStation()->id, 'sequence_no' => 0]);
        $stop2 = RouteChargingStop::create(['trip_route_id' => $route->id, 'station_id' => $this->makeStation()->id, 'sequence_no' => 1]);

        $this->actingAs($user)->postJson("/api/trips/{$trip->id}/stops/{$stop1->id}/reached")->assertStatus(200);
        $this->assertNotNull($stop1->fresh()->reached_at);
        $this->assertNull($stop2->fresh()->reached_at);

        $this->actingAs($user)->postJson("/api/trips/{$trip->id}/stops/{$stop2->id}/reached")->assertStatus(200);
        $this->assertNotNull($stop2->fresh()->reached_at);
    }

    public function test_cannot_mark_a_stop_reached_out_of_order(): void
    {
        $user = $this->makeUser();
        $origin = $this->makeRoadNode(['name' => 'Yangon']);
        $destination = $this->makeRoadNode(['name' => 'Mandalay']);
        $trip = $this->makeTripRequest($user, $origin, $destination);
        $route = $this->makeTripRoute($trip, ['status' => 'active', 'started_at' => now()]);
        RouteChargingStop::create(['trip_route_id' => $route->id, 'station_id' => $this->makeStation()->id, 'sequence_no' => 0]);
        $stop2 = RouteChargingStop::create(['trip_route_id' => $route->id, 'station_id' => $this->makeStation()->id, 'sequence_no' => 1]);

        $response = $this->actingAs($user)->postJson("/api/trips/{$trip->id}/stops/{$stop2->id}/reached");

        $response->assertStatus(422);
        $this->assertNull($stop2->fresh()->reached_at);
    }

    public function test_cannot_mark_a_stop_reached_twice(): void
    {
        $user = $this->makeUser();
        $origin = $this->makeRoadNode(['name' => 'Yangon']);
        $destination = $this->makeRoadNode(['name' => 'Mandalay']);
        $trip = $this->makeTripRequest($user, $origin, $destination);
        $route = $this->makeTripRoute($trip, ['status' => 'active', 'started_at' => now()]);
        $stop1 = RouteChargingStop::create(['trip_route_id' => $route->id, 'station_id' => $this->makeStation()->id, 'sequence_no' => 0]);
        RouteChargingStop::create(['trip_route_id' => $route->id, 'station_id' => $this->makeStation()->id, 'sequence_no' => 1]);

        $this->actingAs($user)->postJson("/api/trips/{$trip->id}/stops/{$stop1->id}/reached")->assertStatus(200);
        $this->actingAs($user)->postJson("/api/trips/{$trip->id}/stops/{$stop1->id}/reached")->assertStatus(422);
    }

    public function test_cannot_mark_a_stop_reached_on_a_non_active_trip(): void
    {
        $user = $this->makeUser();
        $origin = $this->makeRoadNode(['name' => 'Yangon']);
        $destination = $this->makeRoadNode(['name' => 'Mandalay']);
        $trip = $this->makeTripRequest($user, $origin, $destination);
        $route = $this->makeTripRoute($trip, ['status' => 'planned']);
        $stop = RouteChargingStop::create(['trip_route_id' => $route->id, 'station_id' => $this->makeStation()->id, 'sequence_no' => 0]);

        $this->actingAs($user)->postJson("/api/trips/{$trip->id}/stops/{$stop->id}/reached")->assertStatus(422);
    }

    public function test_non_owner_cannot_mark_another_users_stop_reached(): void
    {
        $owner = $this->makeUser();
        $intruder = $this->makeUser();
        $origin = $this->makeRoadNode(['name' => 'Yangon']);
        $destination = $this->makeRoadNode(['name' => 'Mandalay']);
        $trip = $this->makeTripRequest($owner, $origin, $destination);
        $route = $this->makeTripRoute($trip, ['status' => 'active', 'started_at' => now()]);
        $stop = RouteChargingStop::create(['trip_route_id' => $route->id, 'station_id' => $this->makeStation()->id, 'sequence_no' => 0]);

        $this->actingAs($intruder)->postJson("/api/trips/{$trip->id}/stops/{$stop->id}/reached")->assertStatus(403);
        $this->assertNull($stop->fresh()->reached_at);
    }

    public function test_a_stop_belonging_to_a_different_trip_is_rejected(): void
    {
        $user = $this->makeUser();
        $origin = $this->makeRoadNode(['name' => 'Yangon']);
        $destination = $this->makeRoadNode(['name' => 'Mandalay']);

        $tripA = $this->makeTripRequest($user, $origin, $destination);
        $routeA = $this->makeTripRoute($tripA, ['status' => 'active', 'started_at' => now()]);
        RouteChargingStop::create(['trip_route_id' => $routeA->id, 'station_id' => $this->makeStation()->id, 'sequence_no' => 0]);

        $tripB = $this->makeTripRequest($user, $origin, $destination);
        $routeB = $this->makeTripRoute($tripB, ['status' => 'planned']);
        $stopB = RouteChargingStop::create(['trip_route_id' => $routeB->id, 'station_id' => $this->makeStation()->id, 'sequence_no' => 0]);

        // Same user owns both trips, but stopB belongs to tripB's route, not tripA's.
        $response = $this->actingAs($user)->postJson("/api/trips/{$tripA->id}/stops/{$stopB->id}/reached");

        $response->assertStatus(422);
        $this->assertNull($stopB->fresh()->reached_at);
    }

    // --- complete() ---

    public function test_owner_can_complete_an_active_trip(): void
    {
        $user = $this->makeUser();
        $origin = $this->makeRoadNode(['name' => 'Yangon']);
        $destination = $this->makeRoadNode(['name' => 'Mandalay']);
        $trip = $this->makeTripRequest($user, $origin, $destination);
        $route = $this->makeTripRoute($trip, ['status' => 'active', 'started_at' => now()]);

        $response = $this->actingAs($user)->postJson("/api/trips/{$trip->id}/complete");

        $response->assertStatus(200);
        $route->refresh();
        $this->assertSame('completed', $route->status);
        $this->assertNotNull($route->completed_at);
    }

    public function test_cannot_complete_a_trip_that_is_not_active(): void
    {
        $user = $this->makeUser();
        $origin = $this->makeRoadNode(['name' => 'Yangon']);
        $destination = $this->makeRoadNode(['name' => 'Mandalay']);
        $trip = $this->makeTripRequest($user, $origin, $destination);
        $route = $this->makeTripRoute($trip, ['status' => 'planned']);

        $this->actingAs($user)->postJson("/api/trips/{$trip->id}/complete")->assertStatus(422);
        $this->assertSame('planned', $route->fresh()->status);
    }

    public function test_non_owner_cannot_complete_another_users_trip(): void
    {
        $owner = $this->makeUser();
        $intruder = $this->makeUser();
        $origin = $this->makeRoadNode(['name' => 'Yangon']);
        $destination = $this->makeRoadNode(['name' => 'Mandalay']);
        $trip = $this->makeTripRequest($owner, $origin, $destination);
        $route = $this->makeTripRoute($trip, ['status' => 'active', 'started_at' => now()]);

        $this->actingAs($intruder)->postJson("/api/trips/{$trip->id}/complete")->assertStatus(403);
        $this->assertSame('active', $route->fresh()->status);
    }

    // --- cancel() ---

    public function test_owner_can_cancel_an_active_trip(): void
    {
        $user = $this->makeUser();
        $origin = $this->makeRoadNode(['name' => 'Yangon']);
        $destination = $this->makeRoadNode(['name' => 'Mandalay']);
        $trip = $this->makeTripRequest($user, $origin, $destination);
        $route = $this->makeTripRoute($trip, ['status' => 'active', 'started_at' => now()]);

        $response = $this->actingAs($user)->postJson("/api/trips/{$trip->id}/cancel");

        $response->assertStatus(200);
        $this->assertSame('cancelled', $route->fresh()->status);
    }

    public function test_cannot_cancel_a_trip_that_is_not_active(): void
    {
        $user = $this->makeUser();
        $origin = $this->makeRoadNode(['name' => 'Yangon']);
        $destination = $this->makeRoadNode(['name' => 'Mandalay']);
        $trip = $this->makeTripRequest($user, $origin, $destination);
        $route = $this->makeTripRoute($trip, ['status' => 'planned']);

        $this->actingAs($user)->postJson("/api/trips/{$trip->id}/cancel")->assertStatus(422);
        $this->assertSame('planned', $route->fresh()->status);
    }

    public function test_cannot_cancel_an_already_completed_trip(): void
    {
        $user = $this->makeUser();
        $origin = $this->makeRoadNode(['name' => 'Yangon']);
        $destination = $this->makeRoadNode(['name' => 'Mandalay']);
        $trip = $this->makeTripRequest($user, $origin, $destination);
        $route = $this->makeTripRoute($trip, ['status' => 'completed', 'completed_at' => now()]);

        $this->actingAs($user)->postJson("/api/trips/{$trip->id}/cancel")->assertStatus(422);
        $this->assertSame('completed', $route->fresh()->status);
    }

    public function test_non_owner_cannot_cancel_another_users_trip(): void
    {
        $owner = $this->makeUser();
        $intruder = $this->makeUser();
        $origin = $this->makeRoadNode(['name' => 'Yangon']);
        $destination = $this->makeRoadNode(['name' => 'Mandalay']);
        $trip = $this->makeTripRequest($owner, $origin, $destination);
        $route = $this->makeTripRoute($trip, ['status' => 'active', 'started_at' => now()]);

        $this->actingAs($intruder)->postJson("/api/trips/{$trip->id}/cancel")->assertStatus(403);
        $this->assertSame('active', $route->fresh()->status);
    }

    /**
     * The actual regression this endpoint exists to fix: before cancel()
     * existed, a trip that could never legitimately auto-complete had no
     * recovery path at all - the one-active-trip rule would permanently
     * block a new trip. This proves cancelling frees that slot for real,
     * through the same start() endpoint the rule itself guards.
     */
    public function test_cancelling_a_trip_frees_the_one_active_trip_slot(): void
    {
        $user = $this->makeUser();
        $origin = $this->makeRoadNode(['name' => 'Yangon']);
        $destination = $this->makeRoadNode(['name' => 'Mandalay']);

        $firstTrip = $this->makeTripRequest($user, $origin, $destination);
        $this->makeTripRoute($firstTrip, ['status' => 'active', 'started_at' => now()]);

        $secondTrip = $this->makeTripRequest($user, $origin, $destination);
        $this->makeTripRoute($secondTrip, ['status' => 'planned']);

        // Blocked while the first trip is still active - same rule item 2's
        // investigation couldn't otherwise resolve.
        $this->actingAs($user)->postJson("/api/trips/{$secondTrip->id}/start")->assertStatus(422);

        $this->actingAs($user)->postJson("/api/trips/{$firstTrip->id}/cancel")->assertStatus(200);

        $this->actingAs($user)->postJson("/api/trips/{$secondTrip->id}/start")->assertStatus(200);
        $this->assertDatabaseHas('trip_routes', ['trip_request_id' => $secondTrip->id, 'status' => 'active']);
    }
}
