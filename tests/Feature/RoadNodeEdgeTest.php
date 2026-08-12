<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTestData;
use Tests\TestCase;

class RoadNodeEdgeTest extends TestCase
{
    use CreatesTestData;
    use RefreshDatabase;

    public function test_anyone_can_list_road_nodes(): void
    {
        $this->makeRoadNode(['name' => 'Yangon']);
        $this->makeRoadNode(['name' => 'Mandalay']);

        $this->getJson('/api/road-nodes')->assertStatus(200)->assertJsonCount(2);
    }

    public function test_anyone_can_list_road_edges(): void
    {
        $from = $this->makeRoadNode(['name' => 'Yangon']);
        $to = $this->makeRoadNode(['name' => 'Bago']);
        \App\Models\RoadEdge::create([
            'from_node_id' => $from->id,
            'to_node_id' => $to->id,
            'distance_km' => 90,
        ]);

        $this->getJson('/api/road-edges')->assertStatus(200)->assertJsonCount(1);
    }

    public function test_city_suggestions_only_returns_city_type_nodes(): void
    {
        $this->makeRoadNode(['name' => 'Yangon', 'type' => 'city']);
        $this->makeRoadNode(['name' => 'Yangon Central Charge', 'type' => 'station']);

        $response = $this->getJson('/api/road-nodes/city-suggestions?q=Yangon');

        $response->assertStatus(200)->assertJsonCount(1);
        $response->assertJsonPath('0.name', 'Yangon');
    }

    public function test_city_suggestions_partial_match_is_case_insensitive(): void
    {
        $this->makeRoadNode(['name' => 'Mandalay', 'type' => 'city']);
        $this->makeRoadNode(['name' => 'Bago', 'type' => 'city']);

        $response = $this->getJson('/api/road-nodes/city-suggestions?q=man');

        $response->assertStatus(200)->assertJsonCount(1);
        $response->assertJsonPath('0.name', 'Mandalay');
    }

    public function test_city_suggestions_with_empty_query_returns_all_cities_up_to_limit(): void
    {
        $this->makeRoadNode(['name' => 'Yangon', 'type' => 'city']);
        $this->makeRoadNode(['name' => 'Mandalay', 'type' => 'city']);
        $this->makeRoadNode(['name' => 'Some Station', 'type' => 'station']);

        $response = $this->getJson('/api/road-nodes/city-suggestions');

        $response->assertStatus(200)->assertJsonCount(2);
    }

    public function test_admin_can_create_a_road_node(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->postJson('/api/admin/road-nodes', [
            'name' => 'Naypyidaw',
            'latitude' => 19.76,
            'longitude' => 96.13,
        ]);

        $response->assertStatus(201)->assertJsonPath('name', 'Naypyidaw');
    }

    public function test_non_admin_cannot_create_a_road_node(): void
    {
        $stationOwner = $this->makeStationOwner();

        $this->actingAs($stationOwner)->postJson('/api/admin/road-nodes', [
            'name' => 'Naypyidaw',
            'latitude' => 19.76,
            'longitude' => 96.13,
        ])->assertStatus(403);
    }

    public function test_admin_can_update_and_delete_a_road_node(): void
    {
        $admin = $this->makeAdmin();
        $node = $this->makeRoadNode(['name' => 'Old Name']);

        $this->actingAs($admin)->putJson("/api/admin/road-nodes/{$node->id}", ['name' => 'New Name'])
            ->assertStatus(200)->assertJsonPath('name', 'New Name');

        $this->actingAs($admin)->deleteJson("/api/admin/road-nodes/{$node->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('road_nodes', ['id' => $node->id]);
    }

    public function test_non_admin_cannot_update_or_delete_a_road_node(): void
    {
        $evOwner = $this->makeUser();
        $node = $this->makeRoadNode();

        $this->actingAs($evOwner)->putJson("/api/admin/road-nodes/{$node->id}", ['name' => 'Hacked'])
            ->assertStatus(403);

        $this->actingAs($evOwner)->deleteJson("/api/admin/road-nodes/{$node->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('road_nodes', ['id' => $node->id]);
    }

    public function test_admin_can_create_a_road_edge(): void
    {
        $admin = $this->makeAdmin();
        $from = $this->makeRoadNode();
        $to = $this->makeRoadNode(['name' => 'Other City']);

        $response = $this->actingAs($admin)->postJson('/api/admin/road-edges', [
            'from_node_id' => $from->id,
            'to_node_id' => $to->id,
            'distance_km' => 120.5,
        ]);

        $response->assertStatus(201)->assertJsonPath('distance_km', 120.5);
    }

    public function test_road_edge_cannot_connect_a_node_to_itself(): void
    {
        $admin = $this->makeAdmin();
        $node = $this->makeRoadNode();

        $this->actingAs($admin)->postJson('/api/admin/road-edges', [
            'from_node_id' => $node->id,
            'to_node_id' => $node->id,
            'distance_km' => 10,
        ])->assertStatus(422);
    }

    public function test_non_admin_cannot_create_a_road_edge(): void
    {
        $stationOwner = $this->makeStationOwner();
        $from = $this->makeRoadNode();
        $to = $this->makeRoadNode(['name' => 'Other City']);

        $this->actingAs($stationOwner)->postJson('/api/admin/road-edges', [
            'from_node_id' => $from->id,
            'to_node_id' => $to->id,
            'distance_km' => 120.5,
        ])->assertStatus(403);
    }
}
