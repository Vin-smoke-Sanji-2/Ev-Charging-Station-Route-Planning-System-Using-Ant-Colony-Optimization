<?php

namespace Tests\Feature;

use App\Models\RoadEdge;
use App\Services\RouteGeometryBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTestData;
use Tests\TestCase;

class RouteGeometryBuilderTest extends TestCase
{
    use CreatesTestData;
    use RefreshDatabase;

    public function test_uses_edge_path_ids_directly_when_present(): void
    {
        $user = $this->makeUser();
        $origin = $this->makeRoadNode(['name' => 'Origin']);
        $mid = $this->makeRoadNode(['name' => 'Mid']);
        $destination = $this->makeRoadNode(['name' => 'Destination']);
        $trip = $this->makeTripRequest($user, $origin, $destination);

        $edge1 = RoadEdge::create([
            'from_node_id' => $origin->id,
            'to_node_id' => $mid->id,
            'distance_km' => 5,
            'avg_speed_kmh' => 50,
            'geometry' => [[16.80, 96.15], [16.81, 96.16], [16.82, 96.17]],
        ]);
        $edge2 = RoadEdge::create([
            'from_node_id' => $mid->id,
            'to_node_id' => $destination->id,
            'distance_km' => 5,
            'avg_speed_kmh' => 50,
            'geometry' => [[16.82, 96.17], [16.83, 96.18], [16.84, 96.19]],
        ]);

        $route = $this->makeTripRoute($trip, ['edge_path_ids' => [$edge1->id, $edge2->id]]);

        $geometry = (new RouteGeometryBuilder)->build($route);

        // The shared vertex [16.82, 96.17] at the end of edge1 / start of
        // edge2 must appear once, not twice - 3 + 3 points with 1 shared = 5.
        $this->assertCount(5, $geometry);
        $this->assertSame([16.80, 96.15], $geometry[0]);
        $this->assertSame([16.84, 96.19], $geometry[4]);
    }

    public function test_falls_back_to_dijkstra_when_edge_path_ids_is_null(): void
    {
        $user = $this->makeUser();
        $origin = $this->makeRoadNode(['name' => 'Origin']);
        $mid = $this->makeRoadNode(['name' => 'Mid']);
        $destination = $this->makeRoadNode(['name' => 'Destination']);
        $trip = $this->makeTripRequest($user, $origin, $destination);

        // A longer, worse direct edge plus a shorter two-hop path - proves
        // Dijkstra genuinely picks the shortest real path, not just any path.
        RoadEdge::create([
            'from_node_id' => $origin->id, 'to_node_id' => $destination->id,
            'distance_km' => 100, 'avg_speed_kmh' => 50,
            'geometry' => [[16.80, 96.15], [16.90, 96.25]],
        ]);
        RoadEdge::create([
            'from_node_id' => $origin->id, 'to_node_id' => $mid->id,
            'distance_km' => 5, 'avg_speed_kmh' => 50,
            'geometry' => [[16.80, 96.15], [16.81, 96.16]],
        ]);
        RoadEdge::create([
            'from_node_id' => $mid->id, 'to_node_id' => $destination->id,
            'distance_km' => 5, 'avg_speed_kmh' => 50,
            'geometry' => [[16.81, 96.16], [16.90, 96.25]],
        ]);

        $route = $this->makeTripRoute($trip, ['edge_path_ids' => null]);

        $geometry = (new RouteGeometryBuilder)->build($route);

        // The short 2-hop path (5+5=10km) via $mid, not the direct 100km edge.
        $this->assertCount(3, $geometry);
        $this->assertSame([16.80, 96.15], $geometry[0]);
        $this->assertSame([16.81, 96.16], $geometry[1]);
        $this->assertSame([16.90, 96.25], $geometry[2]);
    }

    public function test_falls_back_through_a_charging_stop_via_its_linked_road_node(): void
    {
        $user = $this->makeUser();
        $origin = $this->makeRoadNode(['name' => 'Origin']);
        $stationNode = $this->makeRoadNode(['name' => 'Station Node', 'type' => 'station']);
        $destination = $this->makeRoadNode(['name' => 'Destination']);
        $station = $this->makeStation(null, ['road_node_id' => $stationNode->id]);
        $trip = $this->makeTripRequest($user, $origin, $destination);

        RoadEdge::create([
            'from_node_id' => $origin->id, 'to_node_id' => $stationNode->id,
            'distance_km' => 5, 'avg_speed_kmh' => 50,
            'geometry' => [[16.80, 96.15], [16.81, 96.16]],
        ]);
        RoadEdge::create([
            'from_node_id' => $stationNode->id, 'to_node_id' => $destination->id,
            'distance_km' => 5, 'avg_speed_kmh' => 50,
            'geometry' => [[16.81, 96.16], [16.82, 96.17]],
        ]);

        $route = $this->makeTripRoute($trip, ['edge_path_ids' => null]);
        $route->chargingStops()->create(['station_id' => $station->id, 'sequence_no' => 0]);

        $geometry = (new RouteGeometryBuilder)->build($route);

        $this->assertCount(3, $geometry);
        $this->assertSame([16.80, 96.15], $geometry[0]);
        $this->assertSame([16.81, 96.16], $geometry[1]);
        $this->assertSame([16.82, 96.17], $geometry[2]);
    }

    public function test_returns_null_when_a_stop_has_no_linked_road_node(): void
    {
        $user = $this->makeUser();
        $origin = $this->makeRoadNode(['name' => 'Origin']);
        $destination = $this->makeRoadNode(['name' => 'Destination']);
        $station = $this->makeStation(null, ['road_node_id' => null]);
        $trip = $this->makeTripRequest($user, $origin, $destination);

        RoadEdge::create([
            'from_node_id' => $origin->id, 'to_node_id' => $destination->id,
            'distance_km' => 5, 'avg_speed_kmh' => 50,
            'geometry' => [[16.80, 96.15], [16.81, 96.16]],
        ]);

        $route = $this->makeTripRoute($trip, ['edge_path_ids' => null]);
        $route->chargingStops()->create(['station_id' => $station->id, 'sequence_no' => 0]);

        $this->assertNull((new RouteGeometryBuilder)->build($route));
    }

    public function test_returns_null_when_the_graph_is_genuinely_disconnected(): void
    {
        $user = $this->makeUser();
        $origin = $this->makeRoadNode(['name' => 'Origin']);
        $destination = $this->makeRoadNode(['name' => 'Destination']);
        $trip = $this->makeTripRequest($user, $origin, $destination);
        // No edges at all - origin and destination are unreachable from
        // each other, the defensive case this should handle without error.

        $route = $this->makeTripRoute($trip, ['edge_path_ids' => null]);

        $this->assertNull((new RouteGeometryBuilder)->build($route));
    }
}
