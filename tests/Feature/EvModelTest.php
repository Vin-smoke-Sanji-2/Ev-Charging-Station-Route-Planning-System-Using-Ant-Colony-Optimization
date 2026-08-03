<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTestData;
use Tests\TestCase;

class EvModelTest extends TestCase
{
    use CreatesTestData;
    use RefreshDatabase;

    public function test_anyone_can_list_ev_models(): void
    {
        $this->makeEvModel(['brand' => 'Nissan', 'model' => 'Leaf']);
        $this->makeEvModel(['brand' => 'BYD', 'model' => 'Atto 3']);

        $response = $this->getJson('/api/ev-models');

        $response->assertStatus(200)->assertJsonCount(2);
    }

    public function test_admin_can_create_ev_model(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->postJson('/api/admin/ev-models', [
            'brand' => 'Tesla',
            'model' => 'Model Y',
            'battery_capacity_kwh' => 75,
            'max_range_km' => 500,
            'connector_type' => 'CCS2',
        ]);

        $response->assertStatus(201)->assertJsonPath('brand', 'Tesla');
        $this->assertDatabaseHas('ev_models', ['brand' => 'Tesla', 'model' => 'Model Y']);
    }

    public function test_non_admin_cannot_create_ev_model(): void
    {
        $owner = $this->makeStationOwner();
        $evOwner = $this->makeUser();

        $this->actingAs($owner)->postJson('/api/admin/ev-models', [
            'brand' => 'Tesla',
            'model' => 'Model Y',
            'battery_capacity_kwh' => 75,
            'max_range_km' => 500,
            'connector_type' => 'CCS2',
        ])->assertStatus(403);

        $this->actingAs($evOwner)->postJson('/api/admin/ev-models', [
            'brand' => 'Tesla',
            'model' => 'Model Y',
            'battery_capacity_kwh' => 75,
            'max_range_km' => 500,
            'connector_type' => 'CCS2',
        ])->assertStatus(403);
    }

    public function test_admin_can_update_ev_model(): void
    {
        $admin = $this->makeAdmin();
        $evModel = $this->makeEvModel(['brand' => 'Old Brand']);

        $response = $this->actingAs($admin)->putJson("/api/admin/ev-models/{$evModel->id}", [
            'brand' => 'New Brand',
        ]);

        $response->assertStatus(200)->assertJsonPath('brand', 'New Brand');
    }

    public function test_non_admin_cannot_update_ev_model(): void
    {
        $evOwner = $this->makeUser();
        $evModel = $this->makeEvModel();

        $this->actingAs($evOwner)->putJson("/api/admin/ev-models/{$evModel->id}", [
            'brand' => 'Hacked',
        ])->assertStatus(403);

        $this->assertDatabaseMissing('ev_models', ['brand' => 'Hacked']);
    }

    public function test_admin_can_delete_ev_model(): void
    {
        $admin = $this->makeAdmin();
        $evModel = $this->makeEvModel();

        $this->actingAs($admin)->deleteJson("/api/admin/ev-models/{$evModel->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('ev_models', ['id' => $evModel->id]);
    }

    public function test_non_admin_cannot_delete_ev_model(): void
    {
        $evOwner = $this->makeUser();
        $evModel = $this->makeEvModel();

        $this->actingAs($evOwner)->deleteJson("/api/admin/ev-models/{$evModel->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('ev_models', ['id' => $evModel->id]);
    }
}
