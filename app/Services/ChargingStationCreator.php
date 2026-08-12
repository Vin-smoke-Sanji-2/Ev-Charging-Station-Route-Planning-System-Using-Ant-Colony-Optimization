<?php

namespace App\Services;

use App\Models\ChargingStation;

/**
 * The one real "create a new station" implementation - shared by
 * ChargingStationController::store() (an existing station owner adding a
 * station) and station-owner registration (AuthController::register(),
 * creating a brand-new owner's first station) so there's exactly one
 * place that sets owner_user_id/verification_status/total_slots and
 * links the new station into the road graph, not two slightly-different
 * copies of the same five lines.
 */
class ChargingStationCreator
{
    public function __construct(
        private readonly RoadNodeLinker $roadNodeLinker = new RoadNodeLinker
    ) {}

    /**
     * @param  array{name: string, latitude: float, longitude: float, address?: ?string, township?: ?string, charging_speed?: ?string, operating_hours?: ?string}  $data
     */
    public function create(int $ownerUserId, array $data): ChargingStation
    {
        $station = ChargingStation::create([
            ...$data,
            'owner_user_id' => $ownerUserId,
            'verification_status' => 'pending',
            'total_slots' => 0,
        ]);

        // Every station needs a road graph presence to ever be routable
        // (Trip planning, Navigate) - previously only true for the 75
        // originally-seeded stations; a station created through either of
        // this method's two callers was silently left with a null
        // road_node_id until this fix.
        $this->roadNodeLinker->linkStation($station);

        return $station->fresh();
    }
}
