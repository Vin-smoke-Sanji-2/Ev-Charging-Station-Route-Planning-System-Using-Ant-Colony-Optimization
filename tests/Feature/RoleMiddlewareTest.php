<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTestData;
use Tests\TestCase;

class RoleMiddlewareTest extends TestCase
{
    use CreatesTestData;
    use RefreshDatabase;

    public function test_ev_owner_is_forbidden_from_a_station_owner_only_route(): void
    {
        $evOwner = $this->makeUser(['role' => 'ev_owner']);

        $response = $this->actingAs($evOwner)->postJson('/api/stations', [
            'name' => 'Should Be Blocked',
            'latitude' => 16.8,
            'longitude' => 96.15,
        ]);

        $response->assertStatus(403)->assertJson(['message' => 'Forbidden: insufficient role']);
    }

    public function test_station_owner_is_forbidden_from_an_admin_only_route(): void
    {
        $stationOwner = $this->makeStationOwner();

        $response = $this->actingAs($stationOwner)->getJson('/api/admin/dashboard');

        $response->assertStatus(403)->assertJson(['message' => 'Forbidden: insufficient role']);
    }

    public function test_station_owner_and_admin_are_both_allowed_on_the_shared_role_route(): void
    {
        $stationOwner = $this->makeStationOwner();
        $admin = $this->makeAdmin();

        $this->actingAs($stationOwner)->postJson('/api/stations', [
            'name' => 'Owner Station',
            'latitude' => 16.8,
            'longitude' => 96.15,
        ])->assertStatus(201);

        $this->actingAs($admin)->postJson('/api/stations', [
            'name' => 'Admin Station',
            'latitude' => 16.8,
            'longitude' => 96.15,
        ])->assertStatus(201);
    }
}
