<?php

namespace App\Services;

use App\Models\ChargingStation;
use App\Models\RoadEdge;
use App\Models\RoadNode;

/**
 * Finds or creates the type='station' road_nodes row for a real-world
 * station name+coordinates, and links a ChargingStation to it via
 * road_node_id. The exact tolerant-coordinate-match "find or create"
 * contract SeedRoadNodesFromStations originally established for the 75
 * seeded stations (rounded to 5 decimal places / ~1m; the bound parameter
 * is CAST to DECIMAL before ROUND() to avoid a real binary-DOUBLE-vs-
 * DECIMAL mismatch that command's own history hit and documented) -
 * extracted here so every path that can create a new station
 * (ChargingStationController::store(), station-owner registration, and
 * the original seeding command) shares one implementation instead of
 * three separately-maintained copies of the same SQL.
 */
class RoadNodeLinker
{
    private const EARTH_RADIUS_KM = 6371;

    public function findOrCreateStationNode(string $name, float $lat, float $lng): RoadNode
    {
        $existing = RoadNode::where('name', $name)->where('type', 'station')
            ->whereRaw('ROUND(latitude, 5) = ROUND(CAST(? AS DECIMAL(10,7)), 5)', [$lat])
            ->whereRaw('ROUND(longitude, 5) = ROUND(CAST(? AS DECIMAL(10,7)), 5)', [$lng])
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $node = RoadNode::create([
            'name' => $name,
            'latitude' => $lat,
            'longitude' => $lng,
            'type' => 'station',
        ]);

        $this->connectToGraph($node);

        return $node;
    }

    /**
     * Backfill for nodes that were created before this fix existed (or by
     * any other future path that creates a road_nodes row without going
     * through findOrCreateStationNode(), such as a direct DB import) -
     * finds every node with zero road_edges in either direction and runs
     * the same connectToGraph() logic on each. Safe to run repeatedly:
     * once a node has at least one edge, it's no longer selected.
     *
     * @return int number of nodes connected
     */
    public function connectOrphanNodes(): int
    {
        $connectedNodeIds = RoadEdge::query()->pluck('from_node_id')
            ->merge(RoadEdge::query()->pluck('to_node_id'))
            ->unique();

        $orphans = RoadNode::query()
            ->when($connectedNodeIds->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $connectedNodeIds))
            ->get();

        foreach ($orphans as $orphan) {
            $this->connectToGraph($orphan);
        }

        return $orphans->count();
    }

    /**
     * A brand-new road_nodes row starts with zero road_edges - genuinely
     * unreachable from (and to) everywhere else in the graph until
     * something connects it. This went unnoticed for a while: the original
     * 98-node graph (23 city + 75 station) got its real, OSM-Dijkstra-
     * computed edges from the offline pyosmium/.pbf pipeline (see the
     * road_edges population pipeline entries in CLAUDE.md), but every
     * station created since then via this method - through
     * ChargingStationController::store() or station-owner registration -
     * never got any equivalent. Confirmed directly against the dev DB
     * before writing this fix: every one of road_nodes ids 99+ (10 of
     * them, all real stations created through this app's own "add a
     * station" flows since the original seeding) had 0 rows in
     * road_edges - real, permanent orphans, not a coincidence or a
     * temporary state.
     *
     * This is the real root cause of Navigate's "stays on the dashed
     * fallback forever" bug for any such station: GET /api/navigate/route
     * targeting one of these nodes could never produce a real
     * road_geometry (ShortestPathFinder correctly reports them
     * unreachable, since they genuinely are), and if a user's own raw GPS
     * position happened to be nearest to one of these orphans rather than
     * to any of the 98 original nodes, even navigating to a perfectly
     * reachable target would fail the same way, since the *starting* node
     * itself had no edges to route from.
     *
     * Fix: connect every newly-created node to the graph immediately, via
     * one straight-line (haversine) connector edge pair to whichever
     * *already-connected* node is nearest - not the literal nearest node
     * overall, which could itself be another recently-added orphan and
     * would risk chaining isolated nodes together without ever actually
     * reaching the main graph. This is a deliberately minimal interim
     * connectivity measure, not a substitute for the original pipeline's
     * real OSM-road-following edges - the same straight-line-approximation
     * precedent Navigate's own "bridge" segment (raw GPS position ->
     * nearest road node) already uses for the one stretch that can't be
     * computed from the offline graph either. A future improvement could
     * re-run the real offline edge computation for newly-added stations
     * instead; not attempted here, since that pipeline needs the full
     * Myanmar .osm.pbf extract and is not something this request-time PHP
     * code can invoke.
     */
    private function connectToGraph(RoadNode $newNode): void
    {
        $connectedNodeIds = RoadEdge::query()->pluck('from_node_id')
            ->merge(RoadEdge::query()->pluck('to_node_id'))
            ->unique();

        $candidates = RoadNode::where('id', '!=', $newNode->id)
            ->when($connectedNodeIds->isNotEmpty(), fn ($query) => $query->whereIn('id', $connectedNodeIds))
            ->get(['id', 'latitude', 'longitude']);

        $nearest = null;
        $nearestDistanceKm = null;

        foreach ($candidates as $candidate) {
            $distanceKm = $this->haversineDistanceKm(
                (float) $newNode->latitude,
                (float) $newNode->longitude,
                (float) $candidate->latitude,
                (float) $candidate->longitude,
            );

            if ($nearestDistanceKm === null || $distanceKm < $nearestDistanceKm) {
                $nearestDistanceKm = $distanceKm;
                $nearest = $candidate;
            }
        }

        // Can only happen if road_nodes is otherwise completely empty (no
        // candidates at all, connected or not) - genuinely not possible in
        // practice given the 98 already-seeded nodes, but this is a
        // permanent no-op rather than an error either way, matching this
        // codebase's existing "defend anyway" convention for cases that
        // shouldn't happen given known data (see NavigateController's own
        // near-identical comment on its abort_if for the same reason).
        if ($nearest === null) {
            return;
        }

        $forwardGeometry = [
            [(float) $newNode->latitude, (float) $newNode->longitude],
            [(float) $nearest->latitude, (float) $nearest->longitude],
        ];

        RoadEdge::create([
            'from_node_id' => $newNode->id,
            'to_node_id' => $nearest->id,
            'distance_km' => round($nearestDistanceKm, 2),
            'geometry' => $forwardGeometry,
        ]);

        RoadEdge::create([
            'from_node_id' => $nearest->id,
            'to_node_id' => $newNode->id,
            'distance_km' => round($nearestDistanceKm, 2),
            'geometry' => array_reverse($forwardGeometry),
        ]);
    }

    // Duplicated from NavigateController's own private copy rather than
    // extracted into a shared utility - this project already has an
    // established precedent of small, same-formula duplication like this
    // (see CLAUDE.md's note on haversineDistanceKm() existing separately
    // in navigate.js and NavigateController), and a third copy here keeps
    // this class's own diff minimal and self-contained.
    private function haversineDistanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $toRad = fn (float $deg) => $deg * M_PI / 180;

        $dLat = $toRad($lat2 - $lat1);
        $dLon = $toRad($lon2 - $lon1);

        $a = sin($dLat / 2) ** 2
            + cos($toRad($lat1)) * cos($toRad($lat2)) * sin($dLon / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return self::EARTH_RADIUS_KM * $c;
    }

    /**
     * Finds/creates the station's own road_node and points its
     * road_node_id at it - a no-op if the station is already correctly
     * linked.
     */
    public function linkStation(ChargingStation $station): RoadNode
    {
        $node = $this->findOrCreateStationNode(
            $station->name,
            (float) $station->latitude,
            (float) $station->longitude,
        );

        if ($station->road_node_id !== $node->id) {
            $station->update(['road_node_id' => $node->id]);
        }

        return $node;
    }
}
