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

    /**
     * user_vehicles.ev_model_id is restrictOnDelete() - deleting an in-use
     * model would otherwise throw a raw, unhandled QueryException (a 500),
     * not a clean 4xx. Confirms both the clean response AND that the
     * message reports the real, accurate vehicle count.
     */
    public function test_admin_cannot_delete_an_ev_model_currently_in_use(): void
    {
        $admin = $this->makeAdmin();
        $evModel = $this->makeEvModel();
        $owner1 = $this->makeUser();
        $owner2 = $this->makeUser();
        $this->makeVehicle($owner1, $evModel);
        $this->makeVehicle($owner2, $evModel);

        $response = $this->actingAs($admin)->deleteJson("/api/admin/ev-models/{$evModel->id}");

        $response->assertStatus(422);
        $this->assertStringContainsString('2 vehicle(s)', $response->json('message'));
        $this->assertDatabaseHas('ev_models', ['id' => $evModel->id]);
    }

    public function test_non_admin_cannot_delete_ev_model(): void
    {
        $evOwner = $this->makeUser();
        $evModel = $this->makeEvModel();

        $this->actingAs($evOwner)->deleteJson("/api/admin/ev-models/{$evModel->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('ev_models', ['id' => $evModel->id]);
    }

    /**
     * The non-admin creation path (My EVs' "enter it manually" fallback -
     * see vehicles-index.js) - any authenticated role can reach it, not
     * just ev_owner, since nothing about this endpoint is role-specific.
     */
    public function test_any_authenticated_user_can_create_an_ev_model_via_the_non_admin_endpoint(): void
    {
        $evOwner = $this->makeUser();

        $response = $this->actingAs($evOwner)->postJson('/api/ev-models', [
            'brand' => 'Rare Brand',
            'model' => 'Rare Model',
            'battery_capacity_kwh' => 55,
            'max_range_km' => 350,
            'connector_type' => 'Type2',
        ]);

        $response->assertStatus(201)->assertJsonPath('brand', 'Rare Brand');
        $this->assertDatabaseHas('ev_models', ['brand' => 'Rare Brand', 'model' => 'Rare Model']);
    }

    public function test_unauthenticated_user_cannot_create_an_ev_model(): void
    {
        $this->postJson('/api/ev-models', [
            'brand' => 'Rare Brand',
            'model' => 'Rare Model',
            'battery_capacity_kwh' => 55,
            'max_range_km' => 350,
            'connector_type' => 'Type2',
        ])->assertStatus(401);
    }

    /**
     * Duplicate-safe by design (see the controller's own doc comment) -
     * opening creation up to every user means two people independently
     * typing the same real model shouldn't silently produce two rows.
     * Case-insensitive on both brand and model.
     */
    public function test_creating_an_ev_model_matching_an_existing_one_returns_the_existing_row_instead_of_duplicating(): void
    {
        $evOwner = $this->makeUser();
        $existing = $this->makeEvModel(['brand' => 'Tesla', 'model' => 'Model 3']);

        $response = $this->actingAs($evOwner)->postJson('/api/ev-models', [
            'brand' => 'tesla',
            'model' => 'MODEL 3',
            'battery_capacity_kwh' => 99,
            'max_range_km' => 999,
            'connector_type' => 'CCS2',
        ]);

        $response->assertStatus(200)->assertJsonPath('id', $existing->id);
        $this->assertDatabaseCount('ev_models', 1);
    }
}
