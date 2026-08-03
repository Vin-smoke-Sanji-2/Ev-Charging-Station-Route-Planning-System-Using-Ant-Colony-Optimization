<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTestData;
use Tests\TestCase;

class ChargingStationTest extends TestCase
{
    use CreatesTestData;
    use RefreshDatabase;

    public function test_public_index_only_returns_verified_stations(): void
    {
        $this->makeStation(null, ['name' => 'Verified Station', 'verification_status' => 'verified']);
        $this->makeStation(null, ['name' => 'Pending Station', 'verification_status' => 'pending']);
        $this->makeStation(null, ['name' => 'Rejected Station', 'verification_status' => 'rejected']);

        $response = $this->getJson('/api/stations');

        $response->assertStatus(200)->assertJsonCount(1);
        $this->assertSame('Verified Station', $response->json('0.name'));
    }

    public function test_public_show_returns_a_station_regardless_of_verification_status(): void
    {
        $station = $this->makeStation(null, ['verification_status' => 'pending']);

        $response = $this->getJson("/api/stations/{$station->id}");

        $response->assertStatus(200)
            ->assertJsonPath('station.id', $station->id)
            ->assertJsonPath('queue_length', 0);
    }

    public function test_station_owner_can_create_a_station(): void
    {
        $owner = $this->makeStationOwner();

        $response = $this->actingAs($owner)->postJson('/api/stations', [
            'name' => 'My New Station',
            'latitude' => 16.8,
            'longitude' => 96.15,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('owner_user_id', $owner->id)
            ->assertJsonPath('verification_status', 'pending');

        $this->assertDatabaseHas('charging_stations', ['name' => 'My New Station', 'owner_user_id' => $owner->id]);
    }

    public function test_ev_owner_cannot_create_a_station(): void
    {
        $evOwner = $this->makeUser();

        $this->actingAs($evOwner)->postJson('/api/stations', [
            'name' => 'Should Not Exist',
            'latitude' => 16.8,
            'longitude' => 96.15,
        ])->assertStatus(403);
    }

    public function test_admin_can_create_a_station(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->postJson('/api/stations', [
            'name' => 'Admin Station',
            'latitude' => 16.8,
            'longitude' => 96.15,
        ])->assertStatus(201);
    }

    public function test_station_owner_can_update_own_station(): void
    {
        $owner = $this->makeStationOwner();
        $station = $this->makeStation($owner, ['name' => 'Old Name']);

        $this->actingAs($owner)->putJson("/api/stations/{$station->id}", ['name' => 'New Name'])
            ->assertStatus(200)
            ->assertJsonPath('name', 'New Name');
    }

    public function test_station_owner_cannot_update_another_owners_station(): void
    {
        $ownerA = $this->makeStationOwner();
        $ownerB = $this->makeStationOwner();
        $station = $this->makeStation($ownerA, ['name' => 'Owner A Station']);

        $this->actingAs($ownerB)->putJson("/api/stations/{$station->id}", ['name' => 'Hijacked'])
            ->assertStatus(403);

        $this->assertDatabaseHas('charging_stations', ['id' => $station->id, 'name' => 'Owner A Station']);
    }

    public function test_admin_can_update_any_station(): void
    {
        $owner = $this->makeStationOwner();
        $admin = $this->makeAdmin();
        $station = $this->makeStation($owner);

        $this->actingAs($admin)->putJson("/api/stations/{$station->id}", ['name' => 'Admin Edited'])
            ->assertStatus(200)
            ->assertJsonPath('name', 'Admin Edited');
    }

    public function test_only_admin_can_delete_a_station(): void
    {
        $owner = $this->makeStationOwner();
        $admin = $this->makeAdmin();
        $station = $this->makeStation($owner);

        $this->actingAs($owner)->deleteJson("/api/admin/stations/{$station->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('charging_stations', ['id' => $station->id]);

        $this->actingAs($admin)->deleteJson("/api/admin/stations/{$station->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('charging_stations', ['id' => $station->id]);
    }
}
