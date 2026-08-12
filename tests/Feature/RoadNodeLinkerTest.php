<?php

namespace Tests\Feature;

use App\Models\RoadEdge;
use App\Services\RoadNodeLinker;
use App\Services\RouteGeometryBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTestData;
use Tests\TestCase;

/**
 * Regression coverage for the real root cause of Navigate's "stays on the
 * dashed fallback forever" bug: RoadNodeLinker used to create a brand-new
 * road_nodes row with zero road_edges, leaving it permanently unreachable
 * (ShortestPathFinder correctly, but unhelpfully, reports true orphans as
 * unroutable). Confirmed live against the dev DB before this fix: every
 * road_nodes row created since the original 98-node seeding (10 of them,
 * all real stations added through this app's own registration/"Add
 * Another Station" flows) had 0 rows in road_edges. See
 * RoadNodeLinker::connectToGraph()'s own doc block for the full writeup.
 */
class RoadNodeLinkerTest extends TestCase
{
    use CreatesTestData;
    use RefreshDatabase;

    /** A minimal existing "graph" - two already-connected nodes - to give a newly-created node something real to connect to. */
    private function makeConnectedPair(): array
    {
        $cityA = $this->makeRoadNode(['name' => 'City A', 'type' => 'city', 'latitude' => 16.8, 'longitude' => 96.15]);
        $cityB = $this->makeRoadNode(['name' => 'City B', 'type' => 'city', 'latitude' => 17.0, 'longitude' => 96.3]);

        // Real road_edges rows always carry geometry (computed by the
        // offline OSM pipeline) - appendEdgeChain() deliberately refuses
        // to build a polyline through an edge with none (see
        // RouteGeometryBuilder), so a geometry-less edge here would make
        // any multi-hop path through it fail even though the graph really
        // is connected. Giving these real geometry keeps this stand-in
        // graph honest to what production data actually looks like.
        RoadEdge::create([
            'from_node_id' => $cityA->id,
            'to_node_id' => $cityB->id,
            'distance_km' => 30,
            'geometry' => [[16.8, 96.15], [17.0, 96.3]],
        ]);
        RoadEdge::create([
            'from_node_id' => $cityB->id,
            'to_node_id' => $cityA->id,
            'distance_km' => 30,
            'geometry' => [[17.0, 96.3], [16.8, 96.15]],
        ]);

        return [$cityA, $cityB];
    }

    public function test_a_newly_created_station_node_is_connected_to_the_existing_graph_not_left_orphaned(): void
    {
        [$cityA] = $this->makeConnectedPair();

        $node = (new RoadNodeLinker)->findOrCreateStationNode('New Station', 16.85, 96.2);

        $this->assertTrue(
            RoadEdge::where('from_node_id', $node->id)->exists()
                || RoadEdge::where('to_node_id', $node->id)->exists(),
            'Expected the newly created node to have at least one road_edges row, not be an orphan.'
        );

        // The real regression check, not just "an edge row exists somewhere" -
        // a genuine path can now be found to/from the new node, which is
        // exactly what GET /api/navigate/route needs.
        $segment = (new RouteGeometryBuilder)->buildBetweenNodes($cityA->id, $node->id);
        $this->assertNotNull($segment, 'Expected a real path from an existing graph node to the newly created one.');
        $this->assertNotEmpty($segment['points']);
    }

    public function test_new_station_node_connects_to_the_nearest_already_connected_node_not_an_unrelated_orphan(): void
    {
        [$cityA, $cityB] = $this->makeConnectedPair();

        // A pre-existing orphan, deliberately placed much closer to the new
        // station than either connected city - if connectToGraph() picked
        // the literal nearest node regardless of connectivity, it would
        // wire the new station to this orphan instead, leaving both still
        // unreachable from the real graph.
        $unrelatedOrphan = $this->makeRoadNode(['name' => 'Unrelated Orphan', 'type' => 'station', 'latitude' => 16.851, 'longitude' => 96.201]);

        $node = (new RoadNodeLinker)->findOrCreateStationNode('New Station', 16.85, 96.2);

        $this->assertFalse(
            RoadEdge::where('from_node_id', $node->id)->where('to_node_id', $unrelatedOrphan->id)->exists()
                || RoadEdge::where('to_node_id', $node->id)->where('from_node_id', $unrelatedOrphan->id)->exists(),
            'The new node should not have connected to a closer but still-unconnected orphan.'
        );

        $segment = (new RouteGeometryBuilder)->buildBetweenNodes($cityA->id, $node->id);
        $this->assertNotNull($segment);
    }

    public function test_connect_orphan_nodes_backfills_existing_orphans(): void
    {
        [$cityA] = $this->makeConnectedPair();

        // Simulates a node created before this fix existed - a plain
        // RoadNode row with no road_edges at all, bypassing the linker.
        $orphan = $this->makeRoadNode(['name' => 'Pre-Existing Orphan', 'type' => 'station', 'latitude' => 16.9, 'longitude' => 96.25]);

        $connectedCount = (new RoadNodeLinker)->connectOrphanNodes();

        $this->assertSame(1, $connectedCount);
        $this->assertTrue(
            RoadEdge::where('from_node_id', $orphan->id)->exists()
                || RoadEdge::where('to_node_id', $orphan->id)->exists()
        );

        $segment = (new RouteGeometryBuilder)->buildBetweenNodes($cityA->id, $orphan->id);
        $this->assertNotNull($segment);
    }

    public function test_connect_orphan_nodes_is_a_no_op_when_none_exist(): void
    {
        $this->makeConnectedPair();

        $connectedCount = (new RoadNodeLinker)->connectOrphanNodes();

        $this->assertSame(0, $connectedCount);
    }
}
