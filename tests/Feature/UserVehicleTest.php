<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTestData;
use Tests\TestCase;

class UserVehicleTest extends TestCase
{
    use CreatesTestData;
    use RefreshDatabase;

    public function test_unauthenticated_user_cannot_access_vehicles(): void
    {
        $this->getJson('/api/vehicles')->assertStatus(401);
    }

    public function test_user_can_create_a_vehicle(): void
    {
        $user = $this->makeUser();
        $evModel = $this->makeEvModel();

        $response = $this->actingAs($user)->postJson('/api/vehicles', [
            'ev_model_id' => $evModel->id,
            'plate_no' => 'YGN-1',
            'is_default' => true,
        ]);

        $response->assertStatus(201)->assertJsonPath('plate_no', 'YGN-1');
        $this->assertDatabaseHas('user_vehicles', ['user_id' => $user->id, 'plate_no' => 'YGN-1']);
    }

    public function test_index_only_returns_the_authenticated_users_vehicles(): void
    {
        $userA = $this->makeUser();
        $userB = $this->makeUser();
        $this->makeVehicle($userA, null, ['plate_no' => 'A-1']);
        $this->makeVehicle($userB, null, ['plate_no' => 'B-1']);

        $response = $this->actingAs($userA)->getJson('/api/vehicles');

        $response->assertStatus(200)->assertJsonCount(1)->assertJsonPath('0.plate_no', 'A-1');
    }

    public function test_user_can_view_own_vehicle(): void
    {
        $user = $this->makeUser();
        $vehicle = $this->makeVehicle($user);

        $this->actingAs($user)->getJson("/api/vehicles/{$vehicle->id}")
            ->assertStatus(200)
            ->assertJsonPath('id', $vehicle->id);
    }

    public function test_user_cannot_view_another_users_vehicle(): void
    {
        $owner = $this->makeUser();
        $intruder = $this->makeUser();
        $vehicle = $this->makeVehicle($owner);

        $this->actingAs($intruder)->getJson("/api/vehicles/{$vehicle->id}")
            ->assertStatus(403);
    }

    public function test_user_can_update_own_vehicle(): void
    {
        $user = $this->makeUser();
        $vehicle = $this->makeVehicle($user, null, ['plate_no' => 'OLD']);

        $this->actingAs($user)->putJson("/api/vehicles/{$vehicle->id}", ['plate_no' => 'NEW'])
            ->assertStatus(200)
            ->assertJsonPath('plate_no', 'NEW');
    }

    public function test_user_cannot_update_another_users_vehicle(): void
    {
        $owner = $this->makeUser();
        $intruder = $this->makeUser();
        $vehicle = $this->makeVehicle($owner, null, ['plate_no' => 'OLD']);

        $this->actingAs($intruder)->putJson("/api/vehicles/{$vehicle->id}", ['plate_no' => 'HACKED'])
            ->assertStatus(403);

        $this->assertDatabaseHas('user_vehicles', ['id' => $vehicle->id, 'plate_no' => 'OLD']);
    }

    public function test_user_can_delete_own_vehicle(): void
    {
        $user = $this->makeUser();
        $vehicle = $this->makeVehicle($user);

        $this->actingAs($user)->deleteJson("/api/vehicles/{$vehicle->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('user_vehicles', ['id' => $vehicle->id]);
    }

    public function test_user_cannot_delete_another_users_vehicle(): void
    {
        $owner = $this->makeUser();
        $intruder = $this->makeUser();
        $vehicle = $this->makeVehicle($owner);

        $this->actingAs($intruder)->deleteJson("/api/vehicles/{$vehicle->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('user_vehicles', ['id' => $vehicle->id]);
    }
}
