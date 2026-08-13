<?php

namespace App\Services;

use App\Exceptions\RouteNotFeasibleException;
use App\Models\ChargingSession;
use App\Models\RoadEdge;
use App\Models\RoadNode;
use App\Models\RouteChargingStop;
use App\Models\TripRequest;
use App\Models\TripRoute;
use Illuminate\Support\Facades\DB;

/**
 * Ant Colony Optimization over the real road_nodes/road_edges graph.
 *
 * One instance is built fresh per planning call (store() or
 * recalculate()) — the whole graph (98 nodes / 698 edges at the time
 * this was written) is trivial to hold fully in memory for the
 * duration of a single request, so there's no lazy/partial loading
 * or caching across requests.
 */
class AcoRouteEngine
{
    // --- Colony parameters, kept as constants so they're easy to tune
    // without hunting through the algorithm itself. ---
    private const NUM_ANTS = 30;

    private const NUM_ITERATIONS = 40;

    private const ALPHA = 1.0; // pheromone influence

    private const BETA = 2.0; // heuristic (1/distance) influence

    private const EVAPORATION_RATE = 0.3;

    private const PHEROMONE_DEPOSIT_Q = 100.0;

    private const PHEROMONE_FLOOR = 0.01;

    private const PHEROMONE_INITIAL = 1.0;

    private const MAX_STEPS = 40; // 98 nodes total; a sane route never needs more hops

    // ACO is probabilistic - removing/graying out a well-connected station
    // (e.g. it went Occupied) can turn an easy problem into a harder one
    // that a single colony run doesn't always solve within its fixed
    // ant/iteration budget, even though a feasible route genuinely exists.
    // Measured directly against a real harder-than-usual case (a popular
    // station occupied, forcing a longer alternate path): roughly a 40-60%
    // per-attempt success rate, not near-100% like the easy case. Retrying
    // a few times before giving up brings the odds of a false "no feasible
    // route" down to a low single-digit percentage instead of surfacing a
    // failure the very first unlucky attempt hits.
    private const MAX_PLANNING_ATTEMPTS = 5;

    // --- Battery / charging behaviour ---
    private const CHARGE_TRIGGER_RATIO = 0.30; // charge once remaining range drops below 30% of max range

    private const CHARGE_TARGET_RATIO = 0.80; // charging always tops up to 80% of max range

    private const NEAR_EMPTY_BONUS_RATIO = 0.40; // "under 40% after this move" station-bonus trigger

    private const DESTINATION_BONUS = 3.0;

    private const STATION_BONUS = 1.5;

    // --- Cost/time model defaults ---
    private const DEFAULT_SLOT_POWER_KW = 22.0; // common AC fallback when a slot's power_kw is unknown

    private const COST_PER_KWH_MMK = 750.0;

    private const QUEUE_WAIT_MIN_PER_WAITING_SESSION = 30; // rough per-session queue estimate

    /** @var array<int, array{type: string, station_id: ?int, slot: ?\App\Models\ChargingSlot}> */
    private array $nodes = [];

    /** @var array<int, list<array{id: int, to_node_id: int, distance_km: float, avg_speed_kmh: float}>> */
    private array $adjacency = [];

    /** @var array<int, float> edge_id => pheromone level */
    private array $pheromone = [];

    private float $maxRangeKm;

    private float $batteryCapacityKwh;

    private int $destinationNodeId;

    private array $usedFallbackPowerStops = [];

    public function plan(
        TripRequest $trip,
        float $startingBatteryPercent,
        ?int $previousRouteId = null,
        ?string $reason = null
    ): TripRoute {
        $trip->loadMissing('vehicle.evModel');

        $evModel = $trip->vehicle->evModel;
        $this->maxRangeKm = (float) $evModel->max_range_km;
        $this->batteryCapacityKwh = (float) $evModel->battery_capacity_kwh;
        $this->destinationNodeId = $trip->destination_node_id;

        $this->loadGraph();

        $best = null;

        for ($attempt = 0; $attempt < self::MAX_PLANNING_ATTEMPTS; $attempt++) {
            // A fresh colony run each attempt, not a continuation - reset
            // pheromone back to its initial level so a failed attempt's
            // reinforcement doesn't bias the next one. The graph itself
            // (nodes/adjacency) doesn't need reloading between attempts,
            // since nothing about it changes mid-request.
            if ($attempt > 0) {
                $this->resetPheromone();
            }

            $best = $this->runColony($trip->origin_node_id, $trip->destination_node_id, $startingBatteryPercent);

            if ($best !== null) {
                break;
            }
        }

        if ($best === null) {
            throw new RouteNotFeasibleException('No feasible route found for this battery level and vehicle.');
        }

        return $this->persist($trip, $best, $previousRouteId, $reason);
    }

    private function resetPheromone(): void
    {
        foreach ($this->pheromone as $edgeId => $level) {
            $this->pheromone[$edgeId] = self::PHEROMONE_INITIAL;
        }
    }

    private function loadGraph(): void
    {
        $stationNodes = RoadNode::where('type', 'station')
            ->with(['chargingStations.slots'])
            ->get()
            ->keyBy('id');

        foreach (RoadNode::all() as $node) {
            $stationId = null;
            $slot = null;

            if ($node->type === 'station' && $stationNodes->has($node->id)) {
                $station = $stationNodes[$node->id]->chargingStations->first();
                if ($station) {
                    $stationId = $station->id;
                    $slot = $station->slots
                        ->where('status', 'available')
                        ->sortBy('id')
                        ->first();
                }
            }

            $this->nodes[$node->id] = [
                'type' => $node->type,
                'station_id' => $stationId,
                'slot' => $slot,
            ];
        }

        foreach (RoadEdge::all() as $edge) {
            $this->adjacency[$edge->from_node_id][] = [
                'id' => $edge->id,
                'to_node_id' => $edge->to_node_id,
                'distance_km' => (float) $edge->distance_km,
                'avg_speed_kmh' => (float) $edge->avg_speed_kmh,
            ];
            $this->pheromone[$edge->id] = self::PHEROMONE_INITIAL;
        }
    }

    private function stationHasAvailableSlot(int $nodeId): bool
    {
        return isset($this->nodes[$nodeId]) && $this->nodes[$nodeId]['slot'] !== null;
    }

    /**
     * @return array{path: list<int>, edge_ids: list<int>, stops: list<array{node_id: int, battery_percent_on_arrival: float, slot: ?\App\Models\ChargingSlot}>, total_distance_km: float}|null
     */
    private function runColony(int $originNodeId, int $destinationNodeId, float $startingBatteryPercent): ?array
    {
        $best = null;

        for ($iteration = 0; $iteration < self::NUM_ITERATIONS; $iteration++) {
            $iterationResults = [];

            for ($ant = 0; $ant < self::NUM_ANTS; $ant++) {
                $result = $this->constructAntSolution($originNodeId, $destinationNodeId, $startingBatteryPercent);

                if ($result !== null) {
                    $iterationResults[] = $result;

                    if (
                        $best === null
                        || $result['total_distance_km'] < $best['total_distance_km']
                        || ($result['total_distance_km'] === $best['total_distance_km'] && count($result['stops']) < count($best['stops']))
                    ) {
                        $best = $result;
                    }
                }
            }

            $this->evaporatePheromones();
            $this->depositPheromones($iterationResults);
        }

        return $best;
    }

    private function evaporatePheromones(): void
    {
        foreach ($this->pheromone as $edgeId => $level) {
            $this->pheromone[$edgeId] = max($level * (1 - self::EVAPORATION_RATE), self::PHEROMONE_FLOOR);
        }
    }

    private function depositPheromones(array $iterationResults): void
    {
        foreach ($iterationResults as $result) {
            $deposit = self::PHEROMONE_DEPOSIT_Q / max($result['total_distance_km'], 0.001);

            foreach ($result['edge_ids'] as $edgeId) {
                $this->pheromone[$edgeId] = max($this->pheromone[$edgeId] + $deposit, self::PHEROMONE_FLOOR);
            }
        }
    }

    private function constructAntSolution(int $originNodeId, int $destinationNodeId, float $startingBatteryPercent): ?array
    {
        $current = $originNodeId;
        $remainingRangeKm = ($startingBatteryPercent / 100) * $this->maxRangeKm;
        $path = [$current];
        $edgeIds = [];
        $stops = [];
        $totalDistanceKm = 0.0;
        // A real route should never pass through the same location twice.
        // Without this, real seed data creates a pathological trap: several
        // real stations sit within a few hundred meters of each other (e.g.
        // Denko/Prime/MM at the same "115 Miles" rest camp, ~0km apart), so
        // their heuristic score (1/distance^beta) dwarfs any genuine
        // long-haul edge. With no revisit guard, ants oscillate inside that
        // tiny, ultra-high-score cluster indefinitely instead of ever
        // choosing the long edge that actually makes progress toward the
        // destination — confirmed live against the real graph (Yangon to
        // Mandalay failed every single time before this guard was added).
        $visited = [$originNodeId => true];

        for ($step = 0; $step < self::MAX_STEPS; $step++) {
            if ($current === $destinationNodeId) {
                return [
                    'path' => $path,
                    'edge_ids' => $edgeIds,
                    'stops' => $stops,
                    'total_distance_km' => $totalDistanceKm,
                ];
            }

            $outgoing = $this->adjacency[$current] ?? [];
            $feasible = array_values(array_filter(
                $outgoing,
                fn (array $edge) => $edge['distance_km'] <= $remainingRangeKm && ! isset($visited[$edge['to_node_id']])
            ));

            $isStationWithSlot = $this->stationHasAvailableSlot($current);
            $lowBattery = $remainingRangeKm < self::CHARGE_TRIGGER_RATIO * $this->maxRangeKm;
            $noFeasibleMove = empty($feasible);

            if ($isStationWithSlot && ($lowBattery || $noFeasibleMove)) {
                $stops[] = [
                    'node_id' => $current,
                    'battery_percent_on_arrival' => ($remainingRangeKm / $this->maxRangeKm) * 100,
                    'slot' => $this->nodes[$current]['slot'],
                    'station_id' => $this->nodes[$current]['station_id'],
                ];

                $remainingRangeKm = self::CHARGE_TARGET_RATIO * $this->maxRangeKm;

                $feasible = array_values(array_filter(
                    $outgoing,
                    fn (array $edge) => $edge['distance_km'] <= $remainingRangeKm && ! isset($visited[$edge['to_node_id']])
                ));
            }

            if (empty($feasible)) {
                return null; // ant fails
            }

            $chosen = $this->chooseNextEdge($feasible, $remainingRangeKm, $destinationNodeId);

            $remainingRangeKm -= $chosen['distance_km'];
            $totalDistanceKm += $chosen['distance_km'];
            $path[] = $chosen['to_node_id'];
            $edgeIds[] = $chosen['id'];
            $current = $chosen['to_node_id'];
            $visited[$current] = true;
        }

        return null; // exceeded MAX_STEPS
    }

    private function chooseNextEdge(array $feasibleEdges, float $remainingRangeKm, int $destinationNodeId): array
    {
        $scores = [];
        $total = 0.0;

        foreach ($feasibleEdges as $edge) {
            $heuristic = 1.0 / max($edge['distance_km'], 0.001);
            $score = ($this->pheromone[$edge['id']] ** self::ALPHA) * ($heuristic ** self::BETA);

            if ($edge['to_node_id'] === $destinationNodeId) {
                $score *= self::DESTINATION_BONUS;
            }

            if (
                $this->stationHasAvailableSlot($edge['to_node_id'])
                && ($remainingRangeKm - $edge['distance_km']) < self::NEAR_EMPTY_BONUS_RATIO * $this->maxRangeKm
            ) {
                $score *= self::STATION_BONUS;
            }

            $scores[] = $score;
            $total += $score;
        }

        if ($total <= 0) {
            // degenerate case (shouldn't happen with the pheromone floor in place) — fall back to uniform choice
            return $feasibleEdges[array_rand($feasibleEdges)];
        }

        $roll = (mt_rand() / mt_getrandmax()) * $total;
        $cumulative = 0.0;

        foreach ($feasibleEdges as $i => $edge) {
            $cumulative += $scores[$i];
            if ($roll <= $cumulative) {
                return $edge;
            }
        }

        return $feasibleEdges[count($feasibleEdges) - 1]; // floating-point safety net
    }

    private function persist(TripRequest $trip, array $best, ?int $previousRouteId, ?string $reason): TripRoute
    {
        return DB::transaction(function () use ($trip, $best, $previousRouteId, $reason) {
            $edgesById = RoadEdge::whereIn('id', $best['edge_ids'])->get()->keyBy('id');

            $totalDistanceKm = $best['total_distance_km'];
            $drivingDurationMin = 0.0;
            foreach ($best['edge_ids'] as $edgeId) {
                $edge = $edgesById[$edgeId];
                $drivingDurationMin += ((float) $edge->distance_km / (float) $edge->avg_speed_kmh) * 60;
            }

            $chargingDurationMin = 0.0;
            $waitDurationMin = 0.0;
            $estimatedCost = 0.0;
            $stopRecords = [];

            foreach ($best['stops'] as $stop) {
                $kwhAdded = $this->batteryCapacityKwh * (self::CHARGE_TARGET_RATIO - ($stop['battery_percent_on_arrival'] / 100));
                $kwhAdded = max($kwhAdded, 0.0);

                $slot = $stop['slot'];
                $powerKw = $slot?->power_kw;
                if ($powerKw === null) {
                    $powerKw = self::DEFAULT_SLOT_POWER_KW;
                    $this->usedFallbackPowerStops[] = $stop['station_id'];
                }

                $chargingDurationMin += ($kwhAdded / $powerKw) * 60;
                $estimatedCost += $kwhAdded * self::COST_PER_KWH_MMK;

                $waitingCount = ChargingSession::where('station_id', $stop['station_id'])
                    ->where('status', 'waiting')
                    ->count();
                $estimatedWaitMin = $waitingCount * self::QUEUE_WAIT_MIN_PER_WAITING_SESSION;
                $waitDurationMin += $estimatedWaitMin;

                $stopRecords[] = [
                    'station_id' => $stop['station_id'],
                    'estimated_wait_min' => $estimatedWaitMin,
                ];
            }

            $estBatteryConsumption = $totalDistanceKm * ($this->batteryCapacityKwh / $this->maxRangeKm);

            $route = TripRoute::create([
                'trip_request_id' => $trip->id,
                'previous_route_id' => $previousRouteId,
                'recalculation_reason' => $reason,
                'total_distance_km' => round($totalDistanceKm, 2),
                'total_duration_min' => round($drivingDurationMin + $chargingDurationMin + $waitDurationMin, 2),
                'est_battery_consumption' => round($estBatteryConsumption, 2),
                'estimated_cost' => round($estimatedCost, 2),
                'status' => 'planned',
                // The winning ant's actual traversed edges, in travel order -
                // previously computed here but discarded once persist()
                // returned; now kept so the real road-shaped route can be
                // rendered later without recomputing it.
                'edge_path_ids' => $best['edge_ids'],
            ]);

            foreach ($stopRecords as $sequenceNo => $stop) {
                RouteChargingStop::create([
                    'trip_route_id' => $route->id,
                    'station_id' => $stop['station_id'],
                    'sequence_no' => $sequenceNo,
                    'estimated_wait_min' => $stop['estimated_wait_min'],
                ]);
            }

            return $route;
        });
    }
}
