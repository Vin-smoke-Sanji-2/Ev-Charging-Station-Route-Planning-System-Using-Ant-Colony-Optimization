<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChargingSession extends Model
{
    protected $fillable = [
        'station_id',
        'slot_id',
        'user_id',
        'vehicle_id',
        'trip_route_id',
        'status',
        'queued_at',
        'started_at',
        'ended_at',
        'energy_kwh',
        'payment_amount',
    ];

    protected function casts(): array
    {
        return [
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function station()
    {
        return $this->belongsTo(ChargingStation::class, 'station_id');
    }

    public function slot()
    {
        return $this->belongsTo(ChargingSlot::class, 'slot_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function vehicle()
    {
        return $this->belongsTo(UserVehicle::class, 'vehicle_id');
    }

    public function tripRoute()
    {
        return $this->belongsTo(TripRoute::class, 'trip_route_id');
    }
}
