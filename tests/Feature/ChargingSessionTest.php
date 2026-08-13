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

    /**
     * Coverage gap found during the queue-investigation session (and now
     * exercised for real by Station Details' new "Start Charging Here"
     * status check, which calls exactly this query to see whether the
     * current EV owner already has an active session at this specific
     * station): index()'s ?station_id= filter and its ev_owner-scoping
     * had never been tested together. Confirms both narrow correctly at
     * once - not just the user's own session at a DIFFERENT station, and
     * not another user's session at the SAME station.
     */
    public function test_index_filters_by_station_id_while_still_scoping_to_own_sessions_for_ev_owner(): void
    {
        $user = $this->makeUser();
        $otherUser = $this->makeUser();
        $stationA = $this->makeStation();
        $stationB = $this->makeStation();

        $ownSessionAtStationA = $this->makeSession($stationA, $user, ['status' => 'waiting']);
        $this->makeSession($stationB, $user, ['status' => 'waiting']);
        $this->makeSession($stationA, $otherUser, ['status' => 'waiting']);

        $response = $this->actingAs($user)->getJson("/api/sessions?station_id={$stationA->id}");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownSessionAtStationA->id);
    }

    /**
     * Coverage gap found during the queue-investigation session: index()'s
     * with(['station', 'slot', 'user', 'vehicle']) stopped one level short
     * of vehicle.evModel, unlike every other place in this app that loads
     * a vehicle (UserVehicleController always does ->with('evModel')) -
     * so a queue UI reading vehicle.ev_model.brand/model would have
     * silently gotten null. Now fixed to with(['vehicle.evModel']).
     */
    public function test_index_includes_vehicle_ev_model_for_sessions_with_a_vehicle(): void
    {
        $user = $this->makeUser();
        $evModel = $this->makeEvModel(['brand' => 'BYD', 'model' => 'Atto 3']);
        $vehicle = $this->makeVehicle($user, $evModel);
        $station = $this->makeStation();

        $this->actingAs($user)->postJson("/api/stations/{$station->id}/sessions", [
            'vehicle_id' => $vehicle->id,
        ])->assertStatus(201);

        $response = $this->actingAs($user)->getJson('/api/sessions');

        $response->assertStatus(200)
            ->assertJsonPath('data.0.vehicle.ev_model.brand', 'BYD')
            ->assertJsonPath('data.0.vehicle.ev_model.model', 'Atto 3');
    }

    /**
     * Missing test found during the Station Owner investigation: every
     * other station-owner-scoped resource (station, slot) already has a
     * "cannot touch another owner's X" test; sessions didn't.
     */
    public function test_station_owner_cannot_update_another_owners_session(): void
    {
        $owner = $this->makeStationOwner();
        $intruder = $this->makeStationOwner();
        $evOwner = $this->makeUser();
        $station = $this->makeStation($owner);
        $this->makeSlot($station, ['status' => 'occupied']);
        $session = $this->makeSession($station, $evOwner, ['status' => 'charging', 'started_at' => now()]);

        $response = $this->actingAs($intruder)->putJson("/api/sessions/{$session->id}", [
            'status' => 'completed',
        ]);

        $response->assertStatus(403);
        $this->assertSame('charging', $session->fresh()->status);
    }

    public function test_joining_a_station_notifies_its_owner(): void
    {
        $owner = $this->makeStationOwner();
        $rider = $this->makeUser();
        $station = $this->makeStation($owner);
        $this->makeSlot($station, ['status' => 'available']);

        $this->actingAs($rider)->postJson("/api/stations/{$station->id}/sessions", [])
            ->assertStatus(201);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $owner->id,
            'type' => 'Queue',
        ]);
    }

    public function test_joining_an_ownerless_station_does_not_error_or_notify_anyone(): void
    {
        $rider = $this->makeUser();
        $station = $this->makeStation(null);
        $this->makeSlot($station, ['status' => 'available']);

        $this->actingAs($rider)->postJson("/api/stations/{$station->id}/sessions", [])
            ->assertStatus(201);

        $this->assertDatabaseCount('user_notifications', 0);
    }

    public function test_promoting_a_waiting_session_to_charging_notifies_the_rider(): void
    {
        $owner = $this->makeStationOwner();
        $rider = $this->makeUser();
        $station = $this->makeStation($owner);
        $this->makeSlot($station, ['status' => 'available']);
        $session = $this->makeSession($station, $rider, ['status' => 'waiting']);

        $this->actingAs($owner)->putJson("/api/sessions/{$session->id}", ['status' => 'charging'])
            ->assertStatus(200);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $rider->id,
            'type' => 'Charging',
        ]);
    }

    public function test_completing_a_session_notifies_the_rider(): void
    {
        $owner = $this->makeStationOwner();
        $rider = $this->makeUser();
        $station = $this->makeStation($owner);
        $slot = $this->makeSlot($station, ['status' => 'occupied']);
        $session = $this->makeSession($station, $rider, ['status' => 'charging', 'slot_id' => $slot->id, 'started_at' => now()]);

        $this->actingAs($owner)->putJson("/api/sessions/{$session->id}", ['status' => 'completed'])
            ->assertStatus(200);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $rider->id,
            'type' => 'Charging',
            'message' => "Your charging session at \"{$station->name}\" has been completed.",
        ]);
    }

    public function test_cancelling_a_session_notifies_the_rider(): void
    {
        $owner = $this->makeStationOwner();
        $rider = $this->makeUser();
        $station = $this->makeStation($owner);
        $slot = $this->makeSlot($station, ['status' => 'occupied']);
        $session = $this->makeSession($station, $rider, ['status' => 'charging', 'slot_id' => $slot->id, 'started_at' => now()]);

        $this->actingAs($owner)->putJson("/api/sessions/{$session->id}", ['status' => 'cancelled'])
            ->assertStatus(200);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $rider->id,
            'type' => 'Charging',
            'message' => "Your charging session at \"{$station->name}\" has been cancelled.",
        ]);
    }

    public function test_auto_promoting_the_next_waiting_session_notifies_that_rider(): void
    {
        $owner = $this->makeStationOwner();
        $charging = $this->makeUser();
        $waiting = $this->makeUser();
        $station = $this->makeStation($owner);
        $slot = $this->makeSlot($station, ['status' => 'occupied']);
        $chargingSession = $this->makeSession($station, $charging, ['status' => 'charging', 'slot_id' => $slot->id, 'started_at' => now()]);
        $this->makeSession($station, $waiting, ['status' => 'waiting', 'queued_at' => now()]);

        $this->actingAs($owner)->putJson("/api/sessions/{$chargingSession->id}", ['status' => 'completed'])
            ->assertStatus(200);

        // The waiting rider gets the same "you've been assigned a slot"
        // notification a direct promotion produces - two separate
        // notification rows exist (one Charging "completed" for the
        // just-finished rider, one Charging "assigned" for the newly
        // promoted one).
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $waiting->id,
            'type' => 'Charging',
        ]);
        $this->assertDatabaseCount('user_notifications', 2);
    }
}
