<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RouteChargingStop extends Model
{
    protected $fillable = [
        'trip_route_id',
        'station_id',
        'sequence_no',
        'estimated_wait_min',
        'reached_at',
    ];

    protected function casts(): array
    {
        return [
            'reached_at' => 'datetime',
        ];
    }

    public function tripRoute()
    {
        return $this->belongsTo(TripRoute::class);
    }

    public function station()
    {
        return $this->belongsTo(ChargingStation::class, 'station_id');
    }
}
