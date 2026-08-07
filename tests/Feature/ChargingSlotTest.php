<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTestData;
use Tests\TestCase;

class ChargingSlotTest extends TestCase
{
    use CreatesTestData;
    use RefreshDatabase;

    public function test_public_can_list_slots_for_a_station(): void
    {
        $station = $this->makeStation();
        $this->makeSlot($station, ['slot_code' => 'A1']);
        $this->makeSlot($station, ['slot_code' => 'A2']);

        $this->getJson("/api/stations/{$station->id}/slots")
            ->assertStatus(200)
            ->assertJsonCount(2);
    }

    public function test_station_owner_can_create_a_slot_for_own_station(): void
    {
        $owner = $this->makeStationOwner();
        $station = $this->makeStation($owner);

        $response = $this->actingAs($owner)->postJson("/api/stations/{$station->id}/slots", [
            'slot_code' => 'A1',
            'connector_type' => 'CCS2',
            'power_type' => 'DC',
            'power_kw' => 50,
        ]);

        $response->assertStatus(201)->assertJsonPath('slot_code', 'A1');
        $this->assertSame(1, $station->fresh()->total_slots);
    }

    public function test_station_owner_cannot_create_a_slot_for_another_owners_station(): void
    {
        $ownerA = $this->makeStationOwner();
        $ownerB = $this->makeStationOwner();
        $station = $this->makeStation($ownerA);

        $this->actingAs($ownerB)->postJson("/api/stations/{$station->id}/slots", [
            'slot_code' => 'A1',
            'connector_type' => 'CCS2',
            'power_kw' => 50,
        ])->assertStatus(403);
    }

    public function test_admin_can_create_a_slot_for_any_station(): void
    {
        $owner = $this->makeStationOwner();
        $admin = $this->makeAdmin();
        $station = $this->makeStation($owner);

        $this->actingAs($admin)->postJson("/api/stations/{$station->id}/slots", [
            'slot_code' => 'A1',
            'connector_type' => 'CCS2',
            'power_type' => 'DC',
            'power_kw' => 50,
        ])->assertStatus(201);
    }

    public function test_creating_a_slot_without_power_type_is_rejected(): void
    {
        $owner = $this->makeStationOwner();
        $station = $this->makeStation($owner);

        $response = $this->actingAs($owner)->postJson("/api/stations/{$station->id}/slots", [
            'slot_code' => 'A1',
            'connector_type' => 'CCS2',
            'power_kw' => 50,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('power_type');
    }

    public function test_creating_a_slot_with_an_invalid_power_type_is_rejected(): void
    {
        $owner = $this->makeStationOwner();
        $station = $this->makeStation($owner);

        $response = $this->actingAs($owner)->postJson("/api/stations/{$station->id}/slots", [
            'slot_code' => 'A1',
            'connector_type' => 'CCS2',
            'power_type' => 'ac',
            'power_kw' => 50,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('power_type');
    }

    public function test_creating_a_slot_with_a_valid_power_type_is_persisted(): void
    {
        $owner = $this->makeStationOwner();
        $station = $this->makeStation($owner);

        $this->actingAs($owner)->postJson("/api/stations/{$station->id}/slots", [
            'slot_code' => 'A1',
            'connector_type' => 'Type2',
            'power_type' => 'AC',
            'power_kw' => 22,
        ])->assertStatus(201)->assertJsonPath('power_type', 'AC');

        $this->assertDatabaseHas('charging_slots', [
            'station_id' => $station->id,
            'slot_code' => 'A1',
            'power_type' => 'AC',
        ]);

        $owner2 = $this->makeStationOwner();
        $station2 = $this->makeStation($owner2);

        $this->actingAs($owner2)->postJson("/api/stations/{$station2->id}/slots", [
            'slot_code' => 'A1',
            'connector_type' => 'CCS2',
            'power_type' => 'DC',
            'power_kw' => 60,
        ])->assertStatus(201)->assertJsonPath('power_type', 'DC');
    }

    public function test_station_owner_can_update_own_slot(): void
    {
        $owner = $this->makeStationOwner();
        $station = $this->makeStation($owner);
        $slot = $this->makeSlot($station, ['status' => 'available']);

        $this->actingAs($owner)->putJson("/api/stations/{$station->id}/slots/{$slot->id}", [
            'status' => 'maintenance',
        ])->assertStatus(200)->assertJsonPath('status', 'maintenance');
    }

    public function test_station_owner_cannot_update_another_owners_slot(): void
    {
        $ownerA = $this->makeStationOwner();
        $ownerB = $this->makeStationOwner();
        $station = $this->makeStation($ownerA);
        $slot = $this->makeSlot($station);

        $this->actingAs($ownerB)->putJson("/api/stations/{$station->id}/slots/{$slot->id}", [
            'status' => 'maintenance',
        ])->assertStatus(403);
    }

    public function test_updating_a_slot_that_does_not_belong_to_the_url_station_is_not_found(): void
    {
        $owner = $this->makeStationOwner();
        $stationA = $this->makeStation($owner);
        $stationB = $this->makeStation($owner);
        $slot = $this->makeSlot($stationA);

        $this->actingAs($owner)->putJson("/api/stations/{$stationB->id}/slots/{$slot->id}", [
            'status' => 'maintenance',
        ])->assertStatus(404);
    }

    public function test_station_owner_can_delete_own_slot(): void
    {
        $owner = $this->makeStationOwner();
        $station = $this->makeStation($owner);
        $slot = $this->makeSlot($station);

        $this->actingAs($owner)->deleteJson("/api/stations/{$station->id}/slots/{$slot->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('charging_slots', ['id' => $slot->id]);
        $this->assertSame(0, $station->fresh()->total_slots);
    }

    public function test_station_owner_cannot_delete_another_owners_slot(): void
    {
        $ownerA = $this->makeStationOwner();
        $ownerB = $this->makeStationOwner();
        $station = $this->makeStation($ownerA);
        $slot = $this->makeSlot($station);

        $this->actingAs($ownerB)->deleteJson("/api/stations/{$station->id}/slots/{$slot->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('charging_slots', ['id' => $slot->id]);
    }
}
