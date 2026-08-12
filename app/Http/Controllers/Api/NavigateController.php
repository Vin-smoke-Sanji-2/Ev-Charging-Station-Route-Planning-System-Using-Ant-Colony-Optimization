<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RoadNode;
use App\Services\RouteGeometryBuilder;
use Illuminate\Http\Request;

/**
 * Backs the frontend "Navigate" feature (navigate.js) with a real road-
 * following route instead of a pure straight line. Deliberately generic -
 * takes a raw lat/lng and a target road_node id, nothing tied to a
 * TripRoute - since Navigate itself has no concept of a "trip": on
 * Station Detail the target is just the station's own node, and on a trip
 * page it's the trip's destination_node, with neither case ever needing a
 * TripRoute record to exist.
 */
class NavigateController extends Controller
{
    private const EARTH_RADIUS_KM = 6371;

    public function __construct(
        private readonly RouteGeometryBuilder $geometryBuilder = new RouteGeometryBuilder
    ) {}

    public function route(Request $request)
    {
        $data = $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
            'target_node_id' => 'required|integer|exists:road_nodes,id',
        ]);

        $nearestNode = $this->findNearestNode((float) $data['lat'], (float) $data['lng']);

        // 98 road_nodes total (see CLAUDE.md) - genuinely can't be null
        // unless the table is empty, which would itself mean this whole
        // feature has nothing to route against.
        abort_if($nearestNode === null, 500, 'No road nodes exist to route from.');

        $bridgeDistanceKm = $this->haversineDistanceKm(
            (float) $data['lat'],
            (float) $data['lng'],
            (float) $nearestNode->latitude,
            (float) $nearestNode->longitude,
        );

        // The bridge is a simple 2-point line from the raw live position to
        // the nearest graph node - the real road geometry (below) only
        // starts from that node, since that's where the graph actually
        // begins. The TARGET side never needs a bridge of its own: a
        // station's road_node_id coordinates already match its displayed
        // position, and a trip's destination_node_id is already a real
        // graph node directly (see the original graph-population work).
        $bridgeGeometry = [
            [(float) $data['lat'], (float) $data['lng']],
            [(float) $nearestNode->latitude, (float) $nearestNode->longitude],
        ];

        $segment = $this->geometryBuilder->buildBetweenNodes($nearestNode->id, (int) $data['target_node_id']);

        return response()->json([
            'nearest_node' => [
                'id' => $nearestNode->id,
                'name' => $nearestNode->name,
                'latitude' => $nearestNode->latitude,
                'longitude' => $nearestNode->longitude,
            ],
            'bridge_geometry' => $bridgeGeometry,
            'bridge_distance_km' => round($bridgeDistanceKm, 3),
            'road_geometry' => $segment['points'] ?? null,
            'road_distance_km' => $segment !== null ? round($segment['distance_km'], 3) : null,
            'total_distance_km' => $segment !== null ? round($bridgeDistanceKm + $segment['distance_km'], 3) : null,
        ]);
    }

    private function findNearestNode(float $lat, float $lng): ?RoadNode
    {
        $nearest = null;
        $nearestDistance = null;

        // 98 rows total - a plain in-memory scan is genuinely simpler and
        // fast enough than any spatial index at this scale (matches the
        // task's own "trivial at this scale" framing).
        foreach (RoadNode::all(['id', 'name', 'latitude', 'longitude']) as $node) {
            $distance = $this->haversineDistanceKm($lat, $lng, (float) $node->latitude, (float) $node->longitude);

            if ($nearestDistance === null || $distance < $nearestDistance) {
                $nearestDistance = $distance;
                $nearest = $node;
            }
        }

        return $nearest;
    }

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
}
