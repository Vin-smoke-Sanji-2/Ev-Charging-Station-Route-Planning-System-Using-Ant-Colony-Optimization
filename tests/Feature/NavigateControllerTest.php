<?php

namespace Tests\Feature;

use App\Models\RoadEdge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTestData;
use Tests\TestCase;

class NavigateControllerTest extends TestCase
{
    use CreatesTestData;
    use RefreshDatabase;

    public function test_returns_nearest_node_bridge_and_real_road_geometry(): void
    {
        $user = $this->makeUser();
        $near = $this->makeRoadNode(['name' => 'Near', 'latitude' => 16.80, 'longitude' => 96.15]);
        $far = $this->makeRoadNode(['name' => 'Far', 'latitude' => 20.00, 'longitude' => 100.00]);
        $target = $this->makeRoadNode(['name' => 'Target', 'latitude' => 16.90, 'longitude' => 96.25]);

        RoadEdge::create([
            'from_node_id' => $near->id, 'to_node_id' => $target->id,
            'distance_km' => 15, 'avg_speed_kmh' => 50,
            'geometry' => [[16.80, 96.15], [16.85, 96.20], [16.90, 96.25]],
        ]);

        // A live position very close to $near, far from $far - proves the
        // nearest-node search genuinely compares distances, not just picks
        // the first/last row.
        $response = $this->actingAs($user)->getJson(
            '/api/navigate/route?lat=16.801&lng=96.151&target_node_id='.$target->id
        );

        $response->assertStatus(200)
            ->assertJsonPath('nearest_node.id', $near->id)
            ->assertJsonPath('nearest_node.name', 'Near');

        $json = $response->json();

        $this->assertCount(2, $json['bridge_geometry']);
        $this->assertSame([16.801, 96.151], $json['bridge_geometry'][0]);
        $this->assertEqualsWithDelta(16.80, $json['bridge_geometry'][1][0], 0.0001);
        $this->assertGreaterThan(0, $json['bridge_distance_km']);
        $this->assertLessThan(1, $json['bridge_distance_km']); // genuinely close, not a distant node

        $this->assertCount(3, $json['road_geometry']);
        $this->assertSame([16.80, 96.15], $json['road_geometry'][0]);
        $this->assertSame([16.90, 96.25], $json['road_geometry'][2]);
        $this->assertEqualsWithDelta(15.0, $json['road_distance_km'], 0.01);

        $this->assertEqualsWithDelta(
            $json['bridge_distance_km'] + $json['road_distance_km'],
            $json['total_distance_km'],
            0.001
        );
    }

    public function test_returns_null_road_fields_when_target_is_unreachable(): void
    {
        $user = $this->makeUser();
        $near = $this->makeRoadNode(['name' => 'Near', 'latitude' => 16.80, 'longitude' => 96.15]);
        $unreachable = $this->makeRoadNode(['name' => 'Unreachable', 'latitude' => 16.90, 'longitude' => 96.25]);
        // No edges at all - $unreachable is genuinely disconnected from $near.

        $response = $this->actingAs($user)->getJson(
            '/api/navigate/route?lat=16.801&lng=96.151&target_node_id='.$unreachable->id
        );

        $response->assertStatus(200)
            ->assertJsonPath('nearest_node.id', $near->id)
            ->assertJsonPath('road_geometry', null)
            ->assertJsonPath('road_distance_km', null)
            ->assertJsonPath('total_distance_km', null);

        // The bridge itself is unaffected - it never depends on graph
        // connectivity, only on which node is nearest.
        $this->assertNotNull($response->json('bridge_geometry'));
    }

    public function test_nearest_node_search_genuinely_compares_all_nodes(): void
    {
        $user = $this->makeUser();
        $this->makeRoadNode(['name' => 'A', 'latitude' => 0, 'longitude' => 0]);
        $closest = $this->makeRoadNode(['name' => 'B', 'latitude' => 10.001, 'longitude' => 10.001]);
        $this->makeRoadNode(['name' => 'C', 'latitude' => 20, 'longitude' => 20]);
        $target = $this->makeRoadNode(['name' => 'Target', 'latitude' => 30, 'longitude' => 30]);

        $response = $this->actingAs($user)->getJson(
            '/api/navigate/route?lat=10&lng=10&target_node_id='.$target->id
        );

        $response->assertStatus(200)->assertJsonPath('nearest_node.id', $closest->id);
    }

    public function test_missing_required_params_returns_422(): void
    {
        $user = $this->makeUser();
        $target = $this->makeRoadNode();

        $this->actingAs($user)->getJson('/api/navigate/route?lat=16.8&lng=96.15')->assertStatus(422);
        $this->actingAs($user)->getJson('/api/navigate/route?lng=96.15&target_node_id='.$target->id)->assertStatus(422);
        $this->actingAs($user)->getJson('/api/navigate/route?lat=16.8&target_node_id='.$target->id)->assertStatus(422);
    }

    public function test_nonexistent_target_node_id_returns_422(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->getJson('/api/navigate/route?lat=16.8&lng=96.15&target_node_id=999999')
            ->assertStatus(422);
    }

    public function test_requires_authentication(): void
    {
        $target = $this->makeRoadNode();

        $this->getJson('/api/navigate/route?lat=16.8&lng=96.15&target_node_id='.$target->id)
            ->assertStatus(401);
    }
}
