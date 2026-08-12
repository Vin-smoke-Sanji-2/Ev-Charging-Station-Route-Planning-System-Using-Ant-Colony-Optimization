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

    /**
     * The Overview page needs a station owner's own station regardless of
     * verification_status - unlike index(), which is public/verified-only
     * and would hide a pending or rejected station from its own owner.
     */
    public function test_mine_returns_only_the_authenticated_owners_stations_regardless_of_verification_status(): void
    {
        $owner = $this->makeStationOwner();
        $otherOwner = $this->makeStationOwner();
        $this->makeStation($owner, ['name' => 'My Pending Station', 'verification_status' => 'pending']);
        $this->makeStation($otherOwner, ['name' => 'Someone Elses Station', 'verification_status' => 'verified']);

        $response = $this->actingAs($owner)->getJson('/api/stations/mine');

        $response->assertStatus(200)->assertJsonCount(1);
        $this->assertSame('My Pending Station', $response->json('0.name'));
    }

    public function test_mine_requires_station_owner_or_admin_role(): void
    {
        $evOwner = $this->makeUser();

        $this->actingAs($evOwner)->getJson('/api/stations/mine')->assertStatus(403);
    }

    /**
     * Confirms /stations/mine doesn't get swallowed by the /stations/
     * {station} wildcard route - the exact ordering hazard this project has
     * hit before (/stations/search-suggestions, /trips/active).
     */
    public function test_mine_route_is_not_shadowed_by_the_station_show_wildcard(): void
    {
        $owner = $this->makeStationOwner();
        $this->makeStation($owner);

        $this->actingAs($owner)->getJson('/api/stations/mine')->assertStatus(200);
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

    /**
     * Real gap found and fixed: store() used to leave every new station's
     * road_node_id null, silently unroutable (no Trip planning, no
     * Navigate) unlike the 75 originally-seeded stations. Now backed by
     * the shared ChargingStationCreator -> RoadNodeLinker path.
     */
    public function test_creating_a_station_links_it_to_a_real_road_node(): void
    {
        $owner = $this->makeStationOwner();

        $response = $this->actingAs($owner)->postJson('/api/stations', [
            'name' => 'Linked Station',
            'latitude' => 17.123,
            'longitude' => 96.456,
        ]);

        $response->assertStatus(201);
        $this->assertNotNull($response->json('road_node_id'));

        $this->assertDatabaseHas('road_nodes', [
            'name' => 'Linked Station',
            'type' => 'station',
        ]);

        $station = \App\Models\ChargingStation::find($response->json('id'));
        $this->assertSame($station->road_node_id, $station->roadNode->id);
        $this->assertEqualsWithDelta(17.123, (float) $station->roadNode->latitude, 0.0001);
        $this->assertEqualsWithDelta(96.456, (float) $station->roadNode->longitude, 0.0001);
    }

    /**
     * The real root cause of Navigate's "stays on the dashed fallback
     * forever" bug (see RoadNodeLinker::connectToGraph()'s own docs and
     * RoadNodeLinkerTest for the service-level coverage): a newly created
     * station's road_node used to have zero road_edges, making it
     * permanently unreachable regardless of the target. This is the
     * end-to-end confirmation through the real POST /api/stations flow a
     * station owner actually uses.
     */
    public function test_creating_a_station_connects_its_road_node_to_the_existing_graph(): void
    {
        $owner = $this->makeStationOwner();
        $cityA = $this->makeRoadNode(['name' => 'City A', 'type' => 'city', 'latitude' => 16.8, 'longitude' => 96.15]);
        $cityB = $this->makeRoadNode(['name' => 'City B', 'type' => 'city', 'latitude' => 17.0, 'longitude' => 96.3]);
        \App\Models\RoadEdge::create(['from_node_id' => $cityA->id, 'to_node_id' => $cityB->id, 'distance_km' => 30]);
        \App\Models\RoadEdge::create(['from_node_id' => $cityB->id, 'to_node_id' => $cityA->id, 'distance_km' => 30]);

        $response = $this->actingAs($owner)->postJson('/api/stations', [
            'name' => 'Newly Connected Station',
            'latitude' => 16.85,
            'longitude' => 96.2,
        ]);

        $response->assertStatus(201);
        $roadNodeId = $response->json('road_node_id');
        $this->assertNotNull($roadNodeId);

        $this->assertTrue(
            \App\Models\RoadEdge::where('from_node_id', $roadNodeId)->exists()
                || \App\Models\RoadEdge::where('to_node_id', $roadNodeId)->exists(),
            'Expected the new station to have at least one road_edges row, not be an orphan.'
        );

        $segment = (new \App\Services\RouteGeometryBuilder)->buildBetweenNodes($cityA->id, $roadNodeId);
        $this->assertNotNull($segment, 'Expected a real path to the newly created station - this is exactly what GET /api/navigate/route needs.');
        $this->assertNotEmpty($segment['points']);
    }

    /** A second station at the exact same real-world spot reuses the existing road_node, not a duplicate one. */
    public function test_creating_a_station_reuses_an_existing_road_node_at_the_same_coordinates(): void
    {
        $owner = $this->makeStationOwner();
        $existingNode = $this->makeRoadNode(['name' => 'Shared Spot', 'type' => 'station', 'latitude' => 18.0, 'longitude' => 97.0]);

        $response = $this->actingAs($owner)->postJson('/api/stations', [
            'name' => 'Shared Spot',
            'latitude' => 18.0,
            'longitude' => 97.0,
        ]);

        $response->assertStatus(201)->assertJsonPath('road_node_id', $existingNode->id);
        $this->assertSame(1, \App\Models\RoadNode::where('name', 'Shared Spot')->count());
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

    public function test_index_flags_dc_only_station_correctly(): void
    {
        $station = $this->makeStation(null, ['name' => 'DC Only']);
        $this->makeSlot($station, ['connector_type' => 'CCS2', 'power_type' => 'DC']);

        $response = $this->getJson('/api/stations');

        $response->assertStatus(200)
            ->assertJsonPath('0.has_dc_connector', true)
            ->assertJsonPath('0.has_ac_connector', false);
    }

    public function test_index_flags_ac_only_station_correctly(): void
    {
        $station = $this->makeStation(null, ['name' => 'AC Only']);
        $this->makeSlot($station, ['connector_type' => 'Type2']);

        $response = $this->getJson('/api/stations');

        $response->assertStatus(200)
            ->assertJsonPath('0.has_ac_connector', true)
            ->assertJsonPath('0.has_dc_connector', false);
    }

    public function test_index_flags_station_with_both_connector_types(): void
    {
        $station = $this->makeStation(null, ['name' => 'Both']);
        $this->makeSlot($station, ['connector_type' => 'CCS2', 'power_type' => 'DC']);
        $this->makeSlot($station, ['connector_type' => 'Type2']);

        $response = $this->getJson('/api/stations');

        $response->assertStatus(200)
            ->assertJsonPath('0.has_dc_connector', true)
            ->assertJsonPath('0.has_ac_connector', true);
    }

    public function test_index_flags_station_with_no_slots_as_neither_connector_type(): void
    {
        $this->makeStation(null, ['name' => 'No Slots']);

        $response = $this->getJson('/api/stations');

        $response->assertStatus(200)
            ->assertJsonPath('0.has_dc_connector', false)
            ->assertJsonPath('0.has_ac_connector', false);
    }

    public function test_search_suggestions_matches_station_name_partially_and_includes_township(): void
    {
        $this->makeStation(null, ['name' => 'Mandalay Hub', 'township' => 'Mandalay']);

        $response = $this->getJson('/api/stations/search-suggestions?q=Hub');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('stations'));
        $this->assertSame('Mandalay Hub', $response->json('stations.0.name'));
        $this->assertSame('Mandalay', $response->json('stations.0.township'));
    }

    public function test_search_suggestions_matches_township_partially_and_deduplicates(): void
    {
        $this->makeStation(null, ['name' => 'Yangon Central', 'township' => 'Yangon']);
        $this->makeStation(null, ['name' => 'Yangon East', 'township' => 'Yangon']);

        $response = $this->getJson('/api/stations/search-suggestions?q=Yang');

        $response->assertStatus(200);
        $this->assertSame(['Yangon'], $response->json('locations'));
    }

    public function test_search_suggestions_single_character_returns_both_groups(): void
    {
        $this->makeStation(null, ['name' => 'Mandalay Hub', 'township' => 'Mandalay']);

        $response = $this->getJson('/api/stations/search-suggestions?q=m');

        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('stations'));
        $this->assertNotEmpty($response->json('locations'));
    }

    public function test_search_suggestions_never_includes_pending_or_rejected_stations(): void
    {
        $this->makeStation(null, ['name' => 'Pending Hub', 'township' => 'Pendingville', 'verification_status' => 'pending']);
        $this->makeStation(null, ['name' => 'Rejected Hub', 'township' => 'Rejectville', 'verification_status' => 'rejected']);

        $response = $this->getJson('/api/stations/search-suggestions?q=Hub');
        $response->assertStatus(200);
        $this->assertCount(0, $response->json('stations'));

        $response = $this->getJson('/api/stations/search-suggestions?q=ville');
        $response->assertStatus(200);
        $this->assertCount(0, $response->json('locations'));
    }

    public function test_search_suggestions_with_empty_or_missing_query_returns_empty_arrays(): void
    {
        $this->makeStation(null, ['name' => 'Mandalay Hub', 'township' => 'Mandalay']);

        $this->getJson('/api/stations/search-suggestions')
            ->assertStatus(200)
            ->assertExactJson(['stations' => [], 'locations' => []]);

        $this->getJson('/api/stations/search-suggestions?q=')
            ->assertStatus(200)
            ->assertExactJson(['stations' => [], 'locations' => []]);

        $this->getJson('/api/stations/search-suggestions?q=%20%20')
            ->assertStatus(200)
            ->assertExactJson(['stations' => [], 'locations' => []]);
    }

    public function test_search_suggestions_enforces_a_limit_of_five_per_group(): void
    {
        foreach (range(1, 7) as $i) {
            $this->makeStation(null, [
                'name' => "Search Station {$i}",
                'township' => "Search Township {$i}",
            ]);
        }

        $response = $this->getJson('/api/stations/search-suggestions?q=Search');

        $response->assertStatus(200);
        $this->assertCount(5, $response->json('stations'));
        $this->assertCount(5, $response->json('locations'));
    }

    public function test_search_suggestions_requires_no_authentication(): void
    {
        $this->makeStation(null, ['name' => 'Mandalay Hub', 'township' => 'Mandalay']);

        $this->getJson('/api/stations/search-suggestions?q=Mandalay')
            ->assertStatus(200);
    }
}
