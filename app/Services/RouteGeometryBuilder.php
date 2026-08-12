<?php

namespace App\Services;

use App\Models\RoadEdge;
use App\Models\TripRoute;

/**
 * Builds a route's full road-following polyline as one continuous list
 * of [lat, lng] points, for rendering - never used during planning
 * itself. Prefers the route's own recorded edge_path_ids (the ACO
 * engine's actual traversed edges, persisted since the edge_path_ids
 * column was added); falls back to a fresh Dijkstra reconstruction (see
 * ShortestPathFinder) over the waypoint sequence for older trips planned
 * before that column existed. Returns null only if neither source can
 * produce a real path - the frontend's dashed straight-line placeholder
 * is reserved for exactly that case, not the default.
 */
class RouteGeometryBuilder
{
    public function __construct(
        private readonly ShortestPathFinder $pathFinder = new ShortestPathFinder
    ) {}

    /**
     * @return list<array{0: float, 1: float}>|null
     */
    public function build(TripRoute $route): ?array
    {
        $route->loadMissing(['tripRequest', 'chargingStops.station.roadNode']);

        if (! empty($route->edge_path_ids)) {
            return $this->appendEdgeChain([], $route->edge_path_ids);
        }

        return $this->buildFromWaypoints($route);
    }

    private function buildFromWaypoints(TripRoute $route): ?array
    {
        $trip = $route->tripRequest;

        if ($trip === null) {
            return null;
        }

        $waypointNodeIds = [$trip->origin_node_id];

        foreach ($route->chargingStops as $stop) {
            $roadNodeId = $stop->station?->roadNode?->id;

            if ($roadNodeId === null) {
                return null; // no graph node to route through for this stop
            }

            $waypointNodeIds[] = $roadNodeId;
        }

        $waypointNodeIds[] = $trip->destination_node_id;

        $points = [];

        for ($i = 0; $i < count($waypointNodeIds) - 1; $i++) {
            $segment = $this->buildBetweenNodes($waypointNodeIds[$i], $waypointNodeIds[$i + 1]);

            if ($segment === null) {
                return null; // genuinely unreachable - should be essentially impossible given the graph's confirmed connectivity
            }

            // Consecutive segments are chained end-to-start (this waypoint's
            // node is both the previous segment's last point and this
            // segment's first point) - drop the duplicate once $points
            // already has content, the same rule appendEdgeChain() applies
            // at the individual-edge level, just one level up.
            $segmentPoints = $segment['points'];
            $points = [...$points, ...($points === [] ? $segmentPoints : array_slice($segmentPoints, 1))];
        }

        return $points === [] ? null : $points;
    }

    /**
     * Real road-following polyline AND distance between any two road_node
     * ids - the generic single-pair building block underneath build()'s
     * waypoint-chaining loop above, and also the direct capability behind
     * Navigate's route endpoint (NavigateController), which has no
     * TripRoute at all - just a starting road_node and a target one.
     *
     * @return array{points: list<array{0: float, 1: float}>, distance_km: float}|null
     */
    public function buildBetweenNodes(int $fromNodeId, int $toNodeId): ?array
    {
        $result = $this->pathFinder->findPathWithDistance($fromNodeId, $toNodeId);

        if ($result === null) {
            return null;
        }

        $points = $this->appendEdgeChain([], $result['edge_ids']);

        if ($points === null) {
            return null;
        }

        return ['points' => $points, 'distance_km' => $result['distance_km']];
    }

    /**
     * @param  list<array{0: float, 1: float}>  $points
     * @param  list<int>  $edgeIds
     * @return list<array{0: float, 1: float}>|null
     */
    private function appendEdgeChain(array $points, array $edgeIds): ?array
    {
        if ($edgeIds === []) {
            return $points;
        }

        $edges = RoadEdge::whereIn('id', $edgeIds)->get()->keyBy('id');

        foreach ($edgeIds as $edgeId) {
            $edge = $edges->get($edgeId);

            if ($edge === null || empty($edge->geometry)) {
                return null;
            }

            $geometry = $edge->geometry;

            // Skip the first point once $points already has content - it's
            // the same real vertex the previous edge's geometry already
            // ended on (edges are directed and chained end-to-start), so
            // keeping it would insert a redundant duplicate point.
            if ($points !== []) {
                $geometry = array_slice($geometry, 1);
            }

            foreach ($geometry as $point) {
                $points[] = $point;
            }
        }

        return $points;
    }
}
