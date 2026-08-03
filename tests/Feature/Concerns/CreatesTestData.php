<?php

namespace Tests\Feature\Concerns;

use App\Models\ChargingSession;
use App\Models\ChargingSlot;
use App\Models\ChargingStation;
use App\Models\EvModel;
use App\Models\RoadNode;
use App\Models\User;
use App\Models\UserVehicle;

trait CreatesTestData
{
    protected function makeUser(array $attrs = []): User
    {
        return User::factory()->create($attrs);
    }

    protected function makeAdmin(array $attrs = []): User
    {
        return User::factory()->create(array_merge(['role' => 'admin'], $attrs));
    }

    protected function makeStationOwner(array $attrs = []): User
    {
        return User::factory()->create(array_merge(['role' => 'station_owner'], $attrs));
    }

    protected function makeEvModel(array $attrs = []): EvModel
    {
        return EvModel::create(array_merge([
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'battery_capacity_kwh' => 60,
            'max_range_km' => 400,
            'connector_type' => 'CCS2',
        ], $attrs));
    }

    protected function makeVehicle(User $user, ?EvModel $evModel = null, array $attrs = []): UserVehicle
    {
        return UserVehicle::create(array_merge([
            'user_id' => $user->id,
            'ev_model_id' => ($evModel ?? $this->makeEvModel())->id,
            'plate_no' => 'YGN-1234',
            'is_default' => true,
        ], $attrs));
    }

    protected function makeStation(?User $owner = null, array $attrs = []): ChargingStation
    {
        return ChargingStation::create(array_merge([
            'owner_user_id' => $owner?->id,
            'name' => 'Test Station',
            'latitude' => 16.8,
            'longitude' => 96.15,
            'address' => '123 Test Rd',
            'township' => 'Kyauktada',
            'total_slots' => 0,
            'charging_speed' => 'fast',
            'operating_hours' => '24/7',
            'verification_status' => 'verified',
        ], $attrs));
    }

    protected function makeSlot(ChargingStation $station, array $attrs = []): ChargingSlot
    {
        $slot = ChargingSlot::create(array_merge([
            'station_id' => $station->id,
            'slot_code' => 'A'.random_int(1000, 999999),
            'connector_type' => 'CCS2',
            'power_kw' => 50,
            'status' => 'available',
        ], $attrs));

        $station->update(['total_slots' => $station->slots()->count()]);

        return $slot;
    }

    protected function makeSession(ChargingStation $station, User $user, array $attrs = []): ChargingSession
    {
        return ChargingSession::create(array_merge([
            'station_id' => $station->id,
            'slot_id' => null,
            'user_id' => $user->id,
            'vehicle_id' => null,
            'trip_route_id' => null,
            'status' => 'waiting',
            'queued_at' => now(),
            'started_at' => null,
        ], $attrs));
    }

    protected function makeRoadNode(array $attrs = []): RoadNode
    {
        return RoadNode::create(array_merge([
            'name' => 'Yangon',
            'latitude' => 16.8,
            'longitude' => 96.15,
            'type' => 'city',
        ], $attrs));
    }
}
