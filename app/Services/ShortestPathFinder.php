<?php

namespace App\Services;

use App\Models\RoadEdge;
use SplPriorityQueue;

/**
 * Plain Dijkstra shortest-path over the road_edges graph, weighted by
 * distance_km. Nothing else in this codebase implements shortest-path
 * routing - AcoRouteEngine's own edge selection is a probabilistic
 * pheromone/heuristic choice, not a real shortest-path algorithm - so
 * this exists purely as a fallback for reconstructing a route's road
 * geometry when no recorded edge path exists (trips planned before
 * TripRoute.edge_path_ids was added). It is NOT used by trip planning
 * itself; AcoRouteEngine is unaffected by this class.
 */
class ShortestPathFinder
{
    /**
     * @return list<int>|null ordered road_edges.id values from $fromNodeId
     *                         to $toNodeId, or null if no path exists.
     */
    public function findPath(int $fromNodeId, int $toNodeId): ?array
    {
        return $this->dijkstra($fromNodeId, $toNodeId)['edge_ids'] ?? null;
    }

    /**
     * Same shortest path as findPath(), plus its real total distance_km -
     * for callers (e.g. Navigate's route endpoint) that need an accurate
     * distance figure alongside the path itself, not just the path.
     *
     * @return array{edge_ids: list<int>, distance_km: float}|null
     */
    public function findPathWithDistance(int $fromNodeId, int $toNodeId): ?array
    {
        return $this->dijkstra($fromNodeId, $toNodeId);
    }

    /**
     * @return array{edge_ids: list<int>, distance_km: float}|null
     */
    private function dijkstra(int $fromNodeId, int $toNodeId): ?array
    {
        if ($fromNodeId === $toNodeId) {
            return ['edge_ids' => [], 'distance_km' => 0.0];
        }

        [$adjacency] = $this->loadGraph();

        $distances = [$fromNodeId => 0.0];
        $previousEdge = []; // node_id => [edge_id, from_node_id]
        $visited = [];

        $queue = new SplPriorityQueue;
        $queue->insert($fromNodeId, 0.0);

        while (! $queue->isEmpty()) {
            $current = $queue->extract();

            if (isset($visited[$current])) {
                continue;
            }
            $visited[$current] = true;

            if ($current === $toNodeId) {
                break;
            }

            foreach ($adjacency[$current] ?? [] as $edge) {
                $candidateDistance = $distances[$current] + $edge['distance_km'];

                if (! isset($distances[$edge['to_node_id']]) || $candidateDistance < $distances[$edge['to_node_id']]) {
                    $distances[$edge['to_node_id']] = $candidateDistance;
                    $previousEdge[$edge['to_node_id']] = ['edge_id' => $edge['id'], 'from_node_id' => $current];
                    // SplPriorityQueue is a max-heap - negate the distance
                    // so the smallest distance is always extracted first.
                    $queue->insert($edge['to_node_id'], -$candidateDistance);
                }
            }
        }

        if (! isset($distances[$toNodeId])) {
            return null; // unreachable
        }

        $edgeIds = [];
        $node = $toNodeId;

        while ($node !== $fromNodeId) {
            $step = $previousEdge[$node] ?? null;

            if ($step === null) {
                return null; // shouldn't happen if $distances[$toNodeId] is set, but defend anyway
            }

            $edgeIds[] = $step['edge_id'];
            $node = $step['from_node_id'];
        }

        return ['edge_ids' => array_reverse($edgeIds), 'distance_km' => $distances[$toNodeId]];
    }

    /**
     * @return array{0: array<int, list<array{id: int, to_node_id: int, distance_km: float}>>}
     */
    private function loadGraph(): array
    {
        $adjacency = [];

        foreach (RoadEdge::all(['id', 'from_node_id', 'to_node_id', 'distance_km']) as $edge) {
            $adjacency[$edge->from_node_id][] = [
                'id' => $edge->id,
                'to_node_id' => $edge->to_node_id,
                'distance_km' => (float) $edge->distance_km,
            ];
        }

        return [$adjacency];
    }
}
