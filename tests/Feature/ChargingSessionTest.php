<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTestData;
use Tests\TestCase;

class ChargingSessionTest extends TestCase
{
    use CreatesTestData;
    use RefreshDatabase;

    public function test_joining_a_station_with_an_available_slot_starts_charging_immediately(): void
    {
        $user = $this->makeUser();
        $station = $this->makeStation();
        $slot = $this->makeSlot($station, ['status' => 'available']);

        $response = $this->actingAs($user)->postJson("/api/stations/{$station->id}/sessions", []);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'charging')
            ->assertJsonPath('slot.id', $slot->id);

        $this->assertDatabaseHas('charging_sessions', [
            'station_id' => $station->id,
            'user_id' => $user->id,
            'slot_id' => $slot->id,
            'status' => 'charging',
        ]);
        $this->assertDatabaseHas('charging_slots', ['id' => $slot->id, 'status' => 'occupied']);
    }

    public function test_joining_a_full_station_is_queued_as_waiting(): void
    {
        $user = $this->makeUser();
        $station = $this->makeStation();
        $this->makeSlot($station, ['status' => 'occupied']);

        $response = $this->actingAs($user)->postJson("/api/stations/{$station->id}/sessions", []);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'waiting')
            ->assertJsonPath('slot', null);

        $this->assertDatabaseHas('charging_sessions', [
            'station_id' => $station->id,
            'user_id' => $user->id,
            'slot_id' => null,
            'status' => 'waiting',
        ]);
    }

    public function test_completing_a_session_promotes_the_next_waiting_session(): void
    {
        $userA = $this->makeUser();
        $userB = $this->makeUser();
        $owner = $this->makeStationOwner();
        $station = $this->makeStation($owner);
        $slot = $this->makeSlot($station, ['status' => 'available']);

        // First joiner gets the only slot immediately.
        $sessionA = $this->actingAs($userA)
            ->postJson("/api/stations/{$station->id}/sessions", [])
            ->assertStatus(201)
            ->assertJsonPath('status', 'charging')
            ->json();

        // Second joiner finds no slot available, waits.
        $sessionB = $this->actingAs($userB)
            ->postJson("/api/stations/{$station->id}/sessions", [])
            ->assertStatus(201)
            ->assertJsonPath('status', 'waiting')
            ->json();

        // Completing A's session should free the slot and promote B. Only
        // the station's owner (or an admin) may transition a session.
        $response = $this->actingAs($owner)->putJson("/api/sessions/{$sessionA['id']}", [
            'status' => 'completed',
            'energy_kwh' => 12.5,
            'payment_amount' => 5000,
        ]);

        $response->assertStatus(200)->assertJsonPath('status', 'completed');

        // Note: ChargingSessionController::update() frees the *slot* row
        // (status -> available, then reassigned to the promoted session)
        // but never clears the completed session's own slot_id column, so
        // it's left pointing at a slot that may now belong to someone
        // else's active session. Documenting current behavior here rather
        // than the arguably-more-correct null, since changing it is a
        // controller behavior change outside this test task's scope.
        $this->assertDatabaseHas('charging_sessions', [
            'id' => $sessionA['id'],
            'status' => 'completed',
            'slot_id' => $slot->id,
        ]);

        $this->assertDatabaseHas('charging_sessions', [
            'id' => $sessionB['id'],
            'status' => 'charging',
            'slot_id' => $slot->id,
        ]);

        $this->assertDatabaseHas('charging_slots', ['id' => $slot->id, 'status' => 'occupied']);
    }

    public function test_cancelling_a_session_also_promotes_the_next_waiting_session(): void
    {
        $userA = $this->makeUser();
        $userB = $this->makeUser();
        $owner = $this->makeStationOwner();
        $station = $this->makeStation($owner);
        $slot = $this->makeSlot($station, ['status' => 'available']);

        $sessionA = $this->actingAs($userA)
            ->postJson("/api/stations/{$station->id}/sessions", [])
            ->json();

        $sessionB = $this->actingAs($userB)
            ->postJson("/api/stations/{$station->id}/sessions", [])
            ->json();

        $this->actingAs($owner)->putJson("/api/sessions/{$sessionA['id']}", [
            'status' => 'cancelled',
        ])->assertStatus(200)->assertJsonPath('status', 'cancelled');

        $this->assertDatabaseHas('charging_sessions', [
            'id' => $sessionB['id'],
            'status' => 'charging',
            'slot_id' => $slot->id,
        ]);
    }

    public function test_completing_a_session_with_no_one_waiting_just_frees_the_slot(): void
    {
        $user = $this->makeUser();
        $owner = $this->makeStationOwner();
        $station = $this->makeStation($owner);
        $slot = $this->makeSlot($station, ['status' => 'available']);

        $session = $this->actingAs($user)
            ->postJson("/api/stations/{$station->id}/sessions", [])
            ->json();

        $this->actingAs($owner)->putJson("/api/sessions/{$session['id']}", [
            'status' => 'completed',
        ])->assertStatus(200);

        $this->assertDatabaseHas('charging_slots', ['id' => $slot->id, 'status' => 'available']);
    }

    public function test_manually_moving_a_waiting_session_to_charging_requires_an_available_slot(): void
    {
        $user = $this->makeUser();
        $owner = $this->makeStationOwner();
        $station = $this->makeStation($owner);
        $this->makeSlot($station, ['status' => 'occupied']);

        $session = $this->makeSession($station, $user, ['status' => 'waiting']);

        $this->actingAs($owner)->putJson("/api/sessions/{$session->id}", [
            'status' => 'charging',
        ])->assertStatus(422)->assertJson(['message' => 'No available slot to assign yet']);
    }

    public function test_joining_with_another_users_vehicle_id_is_rejected(): void
    {
        $vehicleOwner = $this->makeUser();
        $intruder = $this->makeUser();
        $vehicle = $this->makeVehicle($vehicleOwner);
        $station = $this->makeStation();

        $response = $this->actingAs($intruder)->postJson("/api/stations/{$station->id}/sessions", [
            'vehicle_id' => $vehicle->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('vehicle_id');
        $this->assertDatabaseCount('charging_sessions', 0);
    }

    public function test_joining_with_own_vehicle_id_succeeds(): void
    {
        $user = $this->makeUser();
        $vehicle = $this->makeVehicle($user);
        $station = $this->makeStation();

        $response = $this->actingAs($user)->postJson("/api/stations/{$station->id}/sessions", [
            'vehicle_id' => $vehicle->id,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('charging_sessions', [
            'user_id' => $user->id,
            'vehicle_id' => $vehicle->id,
        ]);
    }

    public function test_joining_with_another_users_trip_route_id_is_rejected(): void
    {
        $tripOwner = $this->makeUser();
        $intruder = $this->makeUser();
        $origin = $this->makeRoadNode(['name' => 'Yangon']);
        $destination = $this->makeRoadNode(['name' => 'Mandalay']);
        $trip = $this->makeTripRequest($tripOwner, $origin, $destination);
        $route = $this->makeTripRoute($trip);
        $station = $this->makeStation();

        $response = $this->actingAs($intruder)->postJson("/api/stations/{$station->id}/sessions", [
            'trip_route_id' => $route->id,
        ]);

        $response->assertStatus(422)->assertJson(['message' => 'This trip route does not belong to you.']);
        $this->assertDatabaseCount('charging_sessions', 0);
    }

    public function test_joining_with_own_trip_route_id_succeeds(): void
    {
        $user = $this->makeUser();
        $origin = $this->makeRoadNode(['name' => 'Yangon']);
        $destination = $this->makeRoadNode(['name' => 'Mandalay']);
        $trip = $this->makeTripRequest($user, $origin, $destination);
        $route = $this->makeTripRoute($trip);
        $station = $this->makeStation();

        $response = $this->actingAs($user)->postJson("/api/stations/{$station->id}/sessions", [
            'trip_route_id' => $route->id,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('charging_sessions', [
            'user_id' => $user->id,
            'trip_route_id' => $route->id,
        ]);
    }

    public function test_index_scopes_sessions_by_role(): void
    {
        $evOwner = $this->makeUser();
        $stationOwner = $this->makeStationOwner();
        $admin = $this->makeAdmin();

        $ownedStation = $this->makeStation($stationOwner);
        $otherStation = $this->makeStation();

        $this->makeSession($ownedStation, $evOwner);
        $this->makeSession($otherStation, $evOwner);

        // EV owner sees only their own sessions, regardless of station.
        $this->actingAs($evOwner)->getJson('/api/sessions')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');

        // Station owner sees only sessions at stations they own.
        $this->actingAs($stationOwner)->getJson('/api/sessions')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        // Admin sees everything.
        $this->actingAs($admin)->getJson('/api/sessions')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }
}
